<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\InvalidActionException;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\Util;
use Throwable;

/**
 * The fields and files of a multipart body that PHP left unparsed.
 *
 * PHP fills `$_POST` and `$_FILES` for a POST only. Mastodon clients send
 * `PATCH /api/v1/accounts/update_credentials` as multipart whenever a picture
 * is included, so that body reached the controller unread: every field was
 * missed, the picture was never seen, and the client got a 200 over an
 * unchanged profile.
 *
 * PHP 8.4 parses such a body on request with `request_parse_body()`. PHP 8.3
 * has nothing for it, so there the body is taken apart here, and the files
 * written for it are remembered so that `isUpload()` can vouch for them the
 * way `is_uploaded_file()` vouches for PHP's own.
 */
class MultipartBodyService {
	/** @var list<string> the temporary files `parse()` wrote in this request */
	private array $written = [];

	public function __construct(
		private ITempManager $tempManager,
	) {
	}

	/**
	 * The request's multipart fields and files, or null when there is nothing
	 * to parse here: the body is not multipart, or it is a POST, which PHP has
	 * already put into `$_POST` and `$_FILES`.
	 *
	 * Has to run before anything reads `php://input`: `request_parse_body()`
	 * finds an empty body after that, and says nothing.
	 *
	 * @return array{fields: array, files: array<string, array>}|null
	 * @throws InvalidActionException the body is multipart and cannot be read
	 */
	public function read(IRequest $request): ?array {
		$contentType = $request->getHeader('Content-Type');
		if ($request->getMethod() === 'POST' || stripos($contentType, 'multipart/form-data') !== 0) {
			return null;
		}

		if (function_exists('request_parse_body')) {
			try {
				[$fields, $files] = request_parse_body();
			} catch (Throwable $e) {
				throw new InvalidActionException('the multipart body could not be read: ' . $e->getMessage());
			}
		} else {
			[$fields, $files] = $this->parse($this->body(), $contentType);
		}

		// a body with content that parses to nothing was not read, and must not
		// become a 200 over an unchanged profile
		if ($fields === [] && $files === [] && (int)$request->getHeader('Content-Length') > 0) {
			throw new InvalidActionException('the multipart body could not be read');
		}

		return ['fields' => $fields, 'files' => $files];
	}

	/**
	 * Whether this request's upload put the file at this path: PHP's own
	 * uploads, and the ones `parse()` wrote.
	 */
	public function isUpload(string $path): bool {
		return is_uploaded_file($path) || in_array($path, $this->written, true);
	}

	/**
	 * A multipart body taken apart the way PHP takes apart a POST: the fields
	 * nested by their bracketed names, each file written to a temporary file
	 * and described in `$_FILES`' shape.
	 *
	 * @return array{0: array, 1: array<string, array>}
	 * @throws InvalidActionException the body is not well-formed multipart
	 */
	public function parse(string $body, string $contentType): array {
		if (preg_match('/boundary=(?:"([^"]+)"|([^\s;]+))/i', $contentType, $match) !== 1) {
			throw new InvalidActionException('the multipart body names no boundary');
		}
		$boundary = ($match[1] !== '') ? $match[1] : $match[2];

		// the preamble before the first delimiter is not a part
		$parts = explode("\r\n--" . $boundary, "\r\n" . $body);
		array_shift($parts);

		$pairs = [];
		$files = [];
		$closed = false;
		foreach ($parts as $part) {
			if (str_starts_with($part, '--')) {
				$closed = true;
				break;
			}

			$split = strpos($part, "\r\n\r\n");
			if ($split === false) {
				throw new InvalidActionException('a part of the multipart body has no headers');
			}
			$headers = substr($part, 0, $split);
			$value = substr($part, $split + 4);

			if (preg_match('/^content-disposition:\s*form-data(.*)$/im', $headers, $disposition) !== 1) {
				continue;
			}
			$name = $this->parameter($disposition[1], 'name');
			if ($name === null || $name === '') {
				continue;
			}

			$filename = $this->parameter($disposition[1], 'filename');
			if ($filename === null) {
				// parse_str() nests `source[privacy]` exactly as $_POST would
				$pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
			} else {
				$files[$name] = $this->file($filename, $headers, $value);
			}
		}

		if (!$closed) {
			throw new InvalidActionException('the multipart body is incomplete');
		}

		parse_str(implode('&', $pairs), $fields);

		return [$fields, $files];
	}

	/**
	 * One file part in `$_FILES`' shape, with the limit PHP would have applied.
	 */
	private function file(string $filename, string $headers, string $bytes): array {
		$type = (preg_match('/^content-type:\s*([^\r\n]+)$/im', $headers, $match) === 1) ? trim($match[1]) : '';
		$upload = [
			// PHP keeps what follows the last slash of either kind, as here
			'name' => (string)preg_replace('#^.*[/\\\\]#', '', $filename),
			'full_path' => $filename,
			'type' => $type,
			'tmp_name' => '',
			'error' => UPLOAD_ERR_OK,
			'size' => strlen($bytes),
		];

		$limit = $this->iniBytes('upload_max_filesize');
		if ($filename === '') {
			return ['error' => UPLOAD_ERR_NO_FILE, 'size' => 0] + $upload;
		}
		if ($limit > 0 && strlen($bytes) > $limit) {
			return ['error' => UPLOAD_ERR_INI_SIZE, 'size' => 0] + $upload;
		}

		$path = $this->tempManager->getTemporaryFile();
		if ($path === false || file_put_contents($path, $bytes) !== strlen($bytes)) {
			return ['error' => UPLOAD_ERR_CANT_WRITE, 'size' => 0] + $upload;
		}
		$this->written[] = $path;

		return ['tmp_name' => $path] + $upload;
	}

	/** A `name="value"` or `name=value` parameter of a header line. */
	private function parameter(string $line, string $key): ?string {
		$pattern = '/;\s*' . preg_quote($key, '/') . '\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;\s]*))/i';
		if (preg_match($pattern, $line, $match) !== 1) {
			return null;
		}

		return isset($match[2]) ? $match[2] : str_replace('\\"', '"', $match[1]);
	}

	/**
	 * The request body, refused when it is over `post_max_size` as PHP refuses
	 * a POST that is.
	 *
	 * @throws InvalidActionException
	 */
	private function body(): string {
		$limit = $this->iniBytes('post_max_size');
		$body = file_get_contents('php://input', false, null, 0, ($limit > 0) ? $limit + 1 : null);
		if ($body === false) {
			throw new InvalidActionException('the multipart body could not be read');
		}
		if ($limit > 0 && strlen($body) > $limit) {
			throw new InvalidActionException('the request body is larger than this server accepts');
		}

		return $body;
	}

	/** A php.ini size in bytes; `0` means no limit. */
	private function iniBytes(string $key): int {
		$size = Util::computerFileSize((string)ini_get($key));

		return ($size === false) ? 0 : (int)$size;
	}
}
