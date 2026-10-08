<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Protocol;

/**
 * A byte string inside a DAG-CBOR value. PHP has one string type, so this
 * wrapper is what tells the encoder "major type 2" from "text".
 */
final class Bytes {
	public function __construct(
		public readonly string $value,
	) {
	}
}
