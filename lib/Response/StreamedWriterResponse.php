<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Response;

use Closure;
use OCA\Social\Exceptions\ClientGoneException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * A body sent while it is written by a writer that pushes its bytes out (a
 * zip archive file by file), so only one write is ever held. When the client
 * goes away, the next write throws `ClientGoneException` and the writer
 * stops.
 *
 * @template-extends Response<Http::STATUS_*, array<string, mixed>>
 */
class StreamedWriterResponse extends Response implements ICallbackResponse {
	/**
	 * @param Closure(Closure(string): void): void $writer given what to write to
	 * @param Http::STATUS_* $status
	 * @param array<string, string> $headers
	 */
	public function __construct(
		private Closure $writer,
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
		// output buffering would hold the whole body
		while (ob_get_level() > 0) {
			ob_end_flush();
		}

		try {
			($this->writer)(static function (string $bytes) use ($output): void {
				if (connection_aborted() !== 0) {
					throw new ClientGoneException();
				}
				$output->setOutput($bytes);
				flush();
			});
		} catch (ClientGoneException) {
			// nobody is left to send the rest to
		}
	}
}
