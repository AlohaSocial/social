<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Response;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use OCP\Files\SimpleFS\ISimpleFile;

/**
 * A stored file, served a byte range at a time.
 *
 * `FileDisplayResponse` sends the whole thing and says nothing about ranges,
 * which is fine for a picture and wrong for anything with a timeline in it: a
 * browser cannot seek a video it can only receive from the beginning, so the
 * scrub bar does nothing, and asking for the duration alone costs the whole
 * file. On a page of twenty videos that is twenty full downloads before
 * anybody has pressed play.
 *
 * So this answers `Accept-Ranges: bytes`, and when the reader asks for part of
 * a file it sends that part with a `206` and a `Content-Range`. The rules are
 * RFC 9110's: a range past the end is unsatisfiable and answered `416` with the
 * length, a suffix range (`bytes=-500`) is the last N bytes, an open range
 * (`bytes=500-`) runs to the end, and anything malformed is ignored rather than
 * refused — the whole file is a valid answer to a range request nobody could
 * parse.
 *
 * Multipart ranges (`bytes=0-99,200-299`) are deliberately not implemented.
 * No media element asks for one, and the answer to a range request this does
 * not handle is the whole file, which is always correct.
 *
 * @template-extends Response<Http::STATUS_*, array<string, mixed>>
 */
class RangedFileResponse extends Response implements ICallbackResponse {
	/** What one read takes, and therefore the ceiling on memory here. */
	private const CHUNK = 262144;

	private int $size;
	private int $offset = 0;
	private int $length;
	private string $etag = '';

	public function __construct(
		private ISimpleFile $file,
		string $contentType,
		string $range = '',
		string $ifNoneMatch = '',
	) {
		parent::__construct();

		$this->size = (int)$this->file->getSize();
		$this->length = $this->size;

		$this->addHeader('Content-Type', $contentType);
		self::guardStoredFile($this, $contentType);

		// the offer has to be made before a browser will make use of it
		$this->addHeader('Accept-Ranges', 'bytes');

		// The bytes behind one of these are immutable — every route that
		// answers with this one is addressed by a uuid or a (uuid, height)
		// pair that names bytes which never change — so the tag can be
		// anything that identifies them. A timeline is forty to sixty of these
		// per screen, each of which is a full Nextcloud boot and, measured on
		// devel, nineteen queries before the first byte; a conditional request
		// that comes back empty is the difference between that and nothing.
		$this->etag = '"' . $this->file->getETag() . '"';
		$this->addHeader('ETag', $this->etag);

		if ($ifNoneMatch !== '' && $this->matches($ifNoneMatch)) {
			$this->setStatus(Http::STATUS_NOT_MODIFIED);
			$this->length = 0;

			return;
		}

		$asked = $this->parseRange($range);
		if ($asked === null) {
			$this->addHeader('Content-Length', (string)$this->size);

			return;
		}

		if ($asked === false) {
			// RFC 9110 §15.5.17: say how long the file actually is, so the
			// client can ask again for something that exists
			$this->setStatus(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE);
			$this->addHeader('Content-Range', 'bytes */' . $this->size);
			$this->length = 0;

			return;
		}

		[$this->offset, $this->length] = $asked;

		$this->setStatus(Http::STATUS_PARTIAL_CONTENT);
		$this->addHeader('Content-Length', (string)$this->length);
		$this->addHeader(
			'Content-Range',
			'bytes ' . $this->offset . '-' . ($this->offset + $this->length - 1) . '/' . $this->size
		);
	}

	/**
	 * The headers every stored file is served with.
	 *
	 * A file somebody else wrote, served from this instance's own origin. A
	 * browser must not be allowed to sniff past the type this states, and
	 * anything that is not a picture, a video or a sound is handed over as a
	 * download rather than rendered: a peer that declares `text/html` over
	 * bytes beginning `GIF89a` is otherwise a page on this origin, framed and
	 * same-origin, whatever the content security policy says about the
	 * scripts in it. A download is also sandboxed, for the browser that opens
	 * a text file in a tab all the same.
	 */
	public static function guardStoredFile(Response $response, string $contentType): void {
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		if (!self::isRenderable($contentType)) {
			$response->addHeader('Content-Disposition', 'attachment');
			$response->addHeader('Content-Security-Policy', "sandbox; default-src 'none'");
		}
	}

	/**
	 * Whether a media element may be pointed at this and nothing worse can
	 * happen: a picture, a video or a sound.
	 *
	 * An SVG is none of the three. It is a document with scripts and remote
	 * references in it that happens to be spelled `image/`, and drawing one
	 * inline is script on this origin whatever `nosniff` says. The ingest
	 * allow-list already refuses it; this is the second place, for a row
	 * written before it did.
	 */
	private static function isRenderable(string $contentType): bool {
		$type = strtolower(trim(explode(';', $contentType, 2)[0]));
		if (str_contains($type, 'svg') || str_contains($type, 'xml')) {
			return false;
		}

		return preg_match('#^(image|video|audio)/#', $type) === 1;
	}

	/**
	 * What the reader asked for.
	 *
	 * @return array{0: int, 1: int}|false|null offset and length, false when
	 *                                          unsatisfiable, null for "all of it"
	 */
	private function parseRange(string $range): array|false|null {
		if ($range === '' || $this->size <= 0) {
			return null;
		}

		if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) !== 1) {
			return null;
		}

		[, $first, $last] = $m;

		if ($first === '' && $last === '') {
			return null;
		}

		if ($first === '') {
			// a suffix range: the last N bytes, and asking for more of them
			// than exist is the whole file rather than an error
			$length = min((int)$last, $this->size);

			return ($length <= 0) ? false : [$this->size - $length, $length];
		}

		$offset = (int)$first;
		if ($offset >= $this->size) {
			return false;
		}

		$end = ($last === '') ? $this->size - 1 : min((int)$last, $this->size - 1);
		if ($end < $offset) {
			return false;
		}

		return [$offset, $end - $offset + 1];
	}

	/**
	 * Whether the tag the client sent is the one this file has.
	 *
	 * `*` matches anything, which is what a client sends when it means "only
	 * if it exists"; a list is comma-separated; and a weak tag differs only by
	 * its `W/` prefix, which for a byte-for-byte comparison of immutable
	 * content means the same thing.
	 */
	private function matches(string $ifNoneMatch): bool {
		foreach (explode(',', $ifNoneMatch) as $candidate) {
			$candidate = trim($candidate);
			if ($candidate === '*') {
				return true;
			}

			if (str_starts_with($candidate, 'W/')) {
				$candidate = substr($candidate, 2);
			}

			if ($candidate === $this->etag) {
				return true;
			}
		}

		return false;
	}

	#[\Override]
	public function callback(IOutput $output): void {
		if ($this->length <= 0) {
			return;
		}

		$stream = $this->file->read();
		if (!is_resource($stream)) {
			return;
		}

		// object storage hands back a stream that cannot be seeked, so the
		// offset is reached by reading up to it rather than jumping
		if ($this->offset > 0 && fseek($stream, $this->offset) !== 0) {
			$this->skip($stream, $this->offset);
		}

		$remaining = $this->length;
		while ($remaining > 0 && !feof($stream)) {
			$chunk = fread($stream, min(self::CHUNK, $remaining));
			if ($chunk === false || $chunk === '') {
				break;
			}

			$output->setOutput($chunk);
			$remaining -= strlen($chunk);

			if (connection_aborted() !== 0) {
				break;
			}
		}

		fclose($stream);
	}

	/** @param resource $stream */
	private function skip($stream, int $bytes): void {
		while ($bytes > 0 && !feof($stream)) {
			$chunk = fread($stream, min(self::CHUNK, $bytes));
			if ($chunk === false || $chunk === '') {
				return;
			}

			$bytes -= strlen($chunk);
		}
	}
}
