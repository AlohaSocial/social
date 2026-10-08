<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Model;

/**
 * One of the instance's own keys: the rotation key every account's DID
 * lists first, or the service key the instance's did:web names.
 */
final class InstanceKey {
	public const KIND_ROTATION = 'rotation';
	public const KIND_SERVICE = 'service';

	public function __construct(
		public readonly int $id,
		public readonly string $kind,
		public readonly string $sealedPrivateKey,
		public readonly string $publicDidKey,
		public readonly int $creation,
		public readonly int $retired,
	) {
	}
}
