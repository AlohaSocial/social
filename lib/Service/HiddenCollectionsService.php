<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * "Hide my followers and following", Mastodon's `hide_collections`: a local
 * account's follower and following lists are shown to nobody but the account
 * itself. The counts stay public, as in Mastodon.
 *
 * Stored per Nextcloud user (`hide_collections`, `'1'` hides). Where it is
 * honoured: the client API's follower and following lists and its familiar
 * followers, and the ActivityPub collections, which keep `totalItems` and
 * list nothing.
 */
class HiddenCollectionsService {
	public const USER_KEY = 'hide_collections';

	/** @var array<string, bool> user id => hides, read once per request */
	private array $byUser = [];

	/** @var array<string, bool> actor id => hides */
	private array $byActor = [];

	public function __construct(
		private ConfigService $configService,
		private ActorsRequest $actorsRequest,
		private LoggerInterface $logger,
	) {
	}

	/** Whether this Nextcloud user hides their lists. */
	public function hides(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		$this->byUser[$userId] ??= $this->configService->getUserValue(self::USER_KEY, $userId) === '1';

		return $this->byUser[$userId];
	}

	public function setHides(string $userId, bool $hide): void {
		$this->configService->setValueForUser($userId, self::USER_KEY, $hide ? '1' : '0');
		$this->byUser[$userId] = $hide;
		$this->byActor = [];
	}

	/**
	 * Whether an account hides its lists. A remote account decides on its own
	 * server, and what that server publishes is what is read from it.
	 */
	public function hidesFor(Person $actor): bool {
		if (!$actor->isLocal() || $actor->getId() === '') {
			return false;
		}

		return $this->byActor[$actor->getId()] ??= $this->hides($this->userIdOf($actor));
	}

	/**
	 * Sets the account's `hide_collections` for the client API to report:
	 * whether it hides its lists, for a local account; left unset, so not
	 * reported, for a remote one.
	 */
	public function annotate(Person $actor): Person {
		if ($actor->isLocal()) {
			$actor->setHideCollections($this->hidesFor($actor));
		}

		return $actor;
	}

	/** Whether a reader (null for nobody signed in) may see an account's lists. */
	public function mayList(Person $actor, ?Person $viewer): bool {
		if ($viewer !== null && $viewer->getId() !== '' && $viewer->getId() === $actor->getId()) {
			return true;
		}

		return !$this->hidesFor($actor);
	}

	/**
	 * The Nextcloud user of a local account. A cached copy of the actor
	 * carries none, so the actor row is asked.
	 */
	private function userIdOf(Person $actor): string {
		if ($actor->getUserId() !== '') {
			return $actor->getUserId();
		}

		try {
			return $this->actorsRequest->getFromId($actor->getId())->getUserId();
		} catch (Throwable $e) {
			$this->logger->debug('no local account behind an actor asked about its lists', [
				'actor' => $actor->getId(),
				'exception' => $e,
			]);

			return '';
		}
	}
}
