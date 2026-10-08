<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Xrpc;

/**
 * An XRPC answer passed on as bytes: a CAR file, a blob, or what the
 * AppView answered a proxied call, with its media type and status. A large
 * blob is a stream instead, read out a chunk at a time.
 */
final class XrpcBytes {
	/**
	 * @param resource|null $stream the bytes as a stream, `$bytes` left empty
	 * @param int $length the stream's length, -1 when not known
	 */
	public function __construct(
		public readonly string $bytes,
		public readonly string $contentType,
		public readonly int $status = 200,
		public readonly mixed $stream = null,
		public readonly int $length = -1,
	) {
	}
}
