<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What becomes of the Bluesky identity when the Fediverse account moves out,
 * since a moved account posts nothing more here.
 *
 * `deactivate` (default): announced inactive, nothing more published; the
 * DID and handle stay the person's and Settings can switch it back on.
 * `move`: the Bluesky account moves on its own (§13.2), done or under way.
 * `keep`: left as it is.
 */
class BlueskyAfterMoveOut {
	public const MOVE = 'move';
	public const DEACTIVATE = 'deactivate';
	public const KEEP = 'keep';
	public const CHOICES = [self::MOVE, self::DEACTIVATE, self::KEEP];

	public function __construct(
		private IdentityService $identities,
		private AtprotoMoveRequest $moves,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Refuses, before anything moves, a choice that cannot be followed.
	 *
	 * @throws MoveErrorException bluesky-choice-invalid, or bluesky-move-first for `move` with no Bluesky move done or under way
	 */
	public function assertPossible(Person $actor, string $choice): void {
		if (!in_array($choice, self::CHOICES, true)) {
			throw new MoveErrorException('bluesky is one of ' . implode(', ', self::CHOICES), 'bluesky-choice-invalid');
		}
		$identity = $this->identityOf($actor);
		if ($choice !== self::MOVE || $identity === null || $identity->state === Identity::STATE_MOVED_AWAY) {
			return;
		}
		$move = $this->moves->latestOfDid($identity->did, Move::AWAY);
		if ($move === null || !($move->state === Move::DONE || $move->isUnderWay())) {
			throw new MoveErrorException('Move the Bluesky account first, or choose to switch it off or keep it', 'bluesky-move-first');
		}
	}

	/**
	 * Follows the choice once the Fediverse account moved.
	 *
	 * @return string what became of the Bluesky identity: `deactivated`, `moving`, `kept`, or `none` without one
	 */
	public function apply(Person $actor, string $choice): string {
		$identity = $this->identityOf($actor);
		if ($identity === null) {
			return 'none';
		}
		if ($choice === self::MOVE) {
			return 'moving';
		}
		if ($choice === self::DEACTIVATE && $identity->state === Identity::STATE_ACTIVE) {
			$this->identities->deactivate($identity);
			$this->logger->info('Bluesky identity switched off as the account moved out', ['did' => $identity->did]);
		}
		if ($choice === self::DEACTIVATE && in_array($identity->state, [Identity::STATE_ACTIVE, Identity::STATE_DEACTIVATED], true)) {
			return 'deactivated';
		}

		return 'kept';
	}

	private function identityOf(Person $actor): ?Identity {
		try {
			return $this->identities->forActor($actor, false);
		} catch (Throwable) {
			return null;
		}
	}
}
