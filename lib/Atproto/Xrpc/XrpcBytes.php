<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Xrpc;

/**
 * An XRPC answer passed on as bytes: a CAR file, a blob, or what the
 * AppView answered a proxied call, with its media type and status.
 */
final class XrpcBytes {
	public function __construct(
		public readonly string $bytes,
		public readonly string $contentType,
		public readonly int $status = 200,
	) {
	}
}
