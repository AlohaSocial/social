<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Xrpc;

/**
 * A binary XRPC answer: a CAR file or a blob, with its media type.
 */
final class XrpcBytes {
	public function __construct(
		public readonly string $bytes,
		public readonly string $contentType,
	) {
	}
}
