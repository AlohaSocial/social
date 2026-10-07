<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Protocol;

/** Explicit CBOR byte string; PHP strings otherwise encode as UTF-8 text. */
final class Bytes {
	public function __construct(
		public readonly string $value,
	) {
	}
}
