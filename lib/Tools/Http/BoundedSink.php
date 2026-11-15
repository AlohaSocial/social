<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Http;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Where an answer's body is written as it arrives, keeping at most one byte
 * more than `$max`.
 *
 * The server's HTTP client runs on curl, which ignores Guzzle's `stream`
 * option and writes the whole body to the request's sink before the request
 * returns. A write this sink refuses makes curl end the transfer, so a body
 * larger than allowed costs `$max + 1` bytes and no more. Curl hands the sink
 * decoded bytes, so the ceiling holds for a compressed answer too.
 */
final class BoundedSink implements StreamInterface {
	use StreamDecoratorTrait;

	/** @var StreamInterface */
	private $stream;
	private bool $overflowed = false;

	public function __construct(
		StreamInterface $stream,
		private int $max,
	) {
		$this->stream = $stream;
	}

	/** A sink in `php://temp`, which spills to a file past a few megabytes. */
	public static function temporary(int $max): self {
		return new self(Utils::streamFor(Utils::tryFopen('php://temp', 'w+')), $max);
	}

	#[\Override]
	public function write($string): int {
		$room = $this->max + 1 - (int)$this->stream->getSize();
		if ($room <= 0) {
			$this->overflowed = true;

			return 0;
		}

		$written = $this->stream->write(strlen($string) > $room ? substr($string, 0, $room) : $string);
		if ((int)$this->stream->getSize() > $this->max) {
			$this->overflowed = true;
		}

		return $written;
	}

	/** Whether the answer had more than `$max` bytes. */
	public function overflowed(): bool {
		return $this->overflowed;
	}
}
