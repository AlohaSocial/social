<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Model;

/**
 * A local actor's AT Protocol identity: the row of social_atproto_identity.
 * The signing key is carried sealed; IdentityService opens it for a
 * signature and nothing else.
 */
final class Identity {
	public const STATE_ACTIVE = 'active';
	public const STATE_DEACTIVATED = 'deactivated';
	public const STATE_MOVED_AWAY = 'moved_away';
	public const STATE_TOMBSTONED = 'tombstoned';

	public function __construct(
		public readonly int $id,
		public readonly string $actorId,
		public readonly string $did,
		public readonly string $handle,
		public readonly string $sealedSigningKey,
		public readonly string $signingPublic,
		public readonly string $recoveryPublic,
		public readonly string $state,
		public readonly string $movedFromPds,
		public readonly int $creation,
	) {
	}

	public function isActive(): bool {
		return $this->state === self::STATE_ACTIVE;
	}

	/** what the record and profile exports show, without the private half */
	public function toArray(): array {
		return [
			'did' => $this->did,
			'handle' => $this->handle,
			'signing_key' => $this->signingPublic,
			'state' => $this->state,
			'active' => $this->isActive(),
			'created_at' => gmdate('Y-m-d\TH:i:s\Z', $this->creation),
		];
	}
}
