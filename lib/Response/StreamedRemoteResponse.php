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

/**
 * Bytes from another server, copied to the client in fixed-size pieces as
 * they arrive, so a long video costs one chunk of memory. Not
 * `StreamResponse`, which reads a file. Proxying is needed because the CSP
 * says `media-src 'self'`, and it keeps readers from announcing themselves to
 * the origin.
 *
 * @template-extends Response<Http::STATUS_*, array<string, mixed>>
 */
class StreamedRemoteResponse extends Response implements ICallbackResponse {
	/** What one `fread` takes, and therefore the ceiling on memory here. */
	private const CHUNK = 262144;

	/**
	 * @param resource $stream
	 * @param Http::STATUS_* $status
	 * @param array<string, string> $headers
	 */
	public function __construct(
		private $stream,
		int $status = Http::STATUS_OK,
		array $headers = [],
	) {
		parent::__construct();

		$this->setStatus($status);
		foreach ($headers as $name => $value) {
			$this->addHeader($name, $value);
		}
	}

	#[\Override]
	public function callback(IOutput $output): void {
		// Drop output buffers, or the whole video would be assembled in memory.
		while (ob_get_level() > 0) {
			ob_end_flush();
		}

		$stream = $this->stream;
		while (!feof($stream)) {
			$chunk = fread($stream, self::CHUNK);
			if ($chunk === false) {
				break;
			}

			$output->setOutput($chunk);

			// The reader went away: stop reading from the origin.
			if (connection_aborted() !== 0) {
				break;
			}

			flush();
		}

		fclose($stream);
	}
}
