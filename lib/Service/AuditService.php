<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\Moderation;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use OCP\Log\Audit\CriticalActionPerformedEvent;

/**
 * The moderator decisions that go into the server's audit log, through
 * `CriticalActionPerformedEvent`, which core's `admin_audit` app writes to its
 * file; when it is off the event costs a dispatch. The message carries `%s`
 * for each parameter in order, as `admin_audit` renders it.
 */
class AuditService {
	public function __construct(
		private IEventDispatcher $eventDispatcher,
		private IUserSession $userSession,
	) {
	}

	/** A decision that was applied to an account. */
	public function accountDecided(string $actorId, string $level): void {
		$this->log(match ($level) {
			Moderation::SILENCE => 'Social: the account "%s" was silenced by "%s"',
			Moderation::SUSPEND => 'Social: the account "%s" was suspended by "%s"',
			default => 'Social: the account "%s" had "' . $level . '" applied by "%s"',
		}, $actorId);
	}

	/**
	 * A decision that no longer stands, so a lifted suspension can be told
	 * from one still in force.
	 */
	public function accountLifted(string $actorId): void {
		$this->log('Social: what stood against the account "%s" was lifted by "%s"', $actorId);
	}

	/** One post removed by a moderator rather than by whoever wrote it. */
	public function postTakenDown(string $streamId): void {
		$this->log('Social: the post "%s" was taken down by "%s"', $streamId);
	}

	/**
	 * An instance added to or removed from the federation access list, worded
	 * for the access mode: refused on a block list, allowed on an allow list.
	 *
	 * @param bool $added whether it went on the list or came off it
	 * @param bool $blockList whether the list names who is refused
	 */
	public function accessListChanged(string $domain, bool $added, bool $blockList): void {
		$this->log(match (true) {
			$added && $blockList => 'Social: the instance "%s" was blocked by "%s"',
			!$added && $blockList => 'Social: the block on the instance "%s" was lifted by "%s"',
			$added => 'Social: the instance "%s" was added to the federation allow list by "%s"',
			default => 'Social: the instance "%s" was removed from the federation allow list by "%s"',
		}, $domain);
	}

	private function log(string $message, string $subject): void {
		$this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
			$message, ['subject' => $subject, 'moderator' => $this->who()]
		));
	}

	/**
	 * Who acted. A background job and an occ command have no session, and
	 * saying so is worth more than an empty pair of quotes that reads like a
	 * moderator whose name was lost.
	 */
	private function who(): string {
		$user = $this->userSession->getUser();

		return $user === null ? 'the command line' : $user->getUID();
	}
}
