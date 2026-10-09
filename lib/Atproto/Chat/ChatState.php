<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Chat;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Cron\AtprotoPublish;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCP\BackgroundJob\IJobList;

/**
 * What a person does with a conversation here, done to its Bluesky
 * conversation as well: read, every one read, a request taken (accepted on
 * Bluesky) or turned down (left on Bluesky while it is still a request, so
 * it is not accepted there). Queued as an `AtprotoPublish` `chat`, so no
 * request here waits for the chat service.
 */
class ChatState {
	public const READ = 'read';
	public const READ_ALL = 'read_all';
	public const ACCEPT = 'accept';
	public const DECLINE = 'decline';
	/** Bluesky's status of a conversation somebody started who the person does not follow */
	public const REQUEST = 'request';

	public function __construct(
		private ChatPoller $poller,
		private ChatStore $store,
		private IdentityService $identities,
		private AppViewClient $appView,
		private CacheActorService $cacheActors,
		private IJobList $jobList,
	) {
	}

	/**
	 * Conversations read here, named by messages in them; `$all` when the
	 * person read every conversation.
	 *
	 * @param string[] $noteIds
	 */
	public function read(Person $viewer, array $noteIds, bool $all = false): void {
		$convos = $this->convosOf($noteIds);
		if ($convos !== []) {
			$this->queue($viewer, $all ? self::READ_ALL : self::READ, $all ? [] : $convos);
		}
	}

	/**
	 * A conversation put away here, named by messages in them: turned down
	 * on Bluesky while it is a request there.
	 *
	 * @param string[] $noteIds
	 */
	public function dismissed(Person $viewer, array $noteIds): void {
		$convos = $this->convosOf($noteIds);
		if ($convos !== []) {
			$this->queue($viewer, self::DECLINE, $convos);
		}
	}

	/**
	 * Somebody taken from the requests here: their conversation is
	 * accepted on Bluesky.
	 */
	public function acceptedSender(Person $viewer, Person $sender): void {
		if (BlueskyIds::isActorId($sender->getId())) {
			$this->queue($viewer, self::ACCEPT, [], BlueskyIds::didOf($sender->getId()));
		}
	}

	/**
	 * Somebody turned down in the requests here: their conversation is
	 * left on Bluesky while it is a request there.
	 */
	public function dismissedSender(Person $viewer, Person $sender): void {
		if (BlueskyIds::isActorId($sender->getId())) {
			$this->queue($viewer, self::DECLINE, [], BlueskyIds::didOf($sender->getId()));
		}
	}

	/**
	 * Does what was queued, as the person, at the chat service.
	 *
	 * @param list<string> $convos
	 * @param string $member the other member's DID, for a conversation named by who it is with
	 */
	public function apply(string $actorId, string $action, array $convos, string $member = ''): void {
		if (!$this->poller->isOn()) {
			return;
		}
		$identity = $this->identities->forActor($this->cacheActors->getFromId($actorId), false);
		if ($identity === null || !$identity->isActive()) {
			return;
		}
		$key = $this->identities->signingKey($identity);
		$call = fn (string $method, array $params = [], ?array $input = null): array => $this->appView->chatAs($identity->did, $key, $method, $params, $input);
		if ($action === self::READ_ALL) {
			$call('chat.bsky.convo.updateAllRead', [], []);

			return;
		}
		if ($action === self::READ) {
			foreach ($convos as $convo) {
				$call('chat.bsky.convo.updateRead', [], ['convoId' => $convo]);
			}

			return;
		}
		if ($action !== self::ACCEPT && $action !== self::DECLINE) {
			return;
		}
		// a conversation named by who it is with, never started for this
		$views = [];
		if ($member !== '') {
			$found = $call('chat.bsky.convo.getConvoAvailability', ['members' => [$member]])['convo'] ?? null;
			$views = is_array($found) ? [$found] : [];
		}
		foreach ($convos as $convo) {
			$found = $call('chat.bsky.convo.getConvo', ['convoId' => $convo])['convo'] ?? null;
			if (is_array($found)) {
				$views[] = $found;
			}
		}
		foreach ($views as $view) {
			$id = (string)($view['id'] ?? '');
			if ($id === '' || ($view['status'] ?? '') !== self::REQUEST) {
				continue;
			}
			$call($action === self::ACCEPT ? 'chat.bsky.convo.acceptConvo' : 'chat.bsky.convo.leaveConvo', [], ['convoId' => $id]);
		}
	}

	/**
	 * @param string[] $noteIds
	 * @return list<string>
	 */
	private function convosOf(array $noteIds): array {
		if (!$this->poller->isOn()) {
			return [];
		}
		$convos = [];
		foreach (array_unique($noteIds) as $id) {
			$convo = $id === '' ? '' : $this->store->convoOf($id);
			if ($convo !== '') {
				$convos[$convo] = $convo;
			}
		}

		return array_values($convos);
	}

	/**
	 * @param list<string> $convos
	 */
	private function queue(Person $viewer, string $action, array $convos, string $member = ''): void {
		if (!$this->poller->isOn() || $this->identities->forActor($viewer, false) === null) {
			return;
		}
		$this->jobList->add(AtprotoPublish::class, [
			'action' => 'chat',
			'id' => $viewer->getId(),
			'chat' => $action,
			'convos' => $convos,
			'member' => $member,
		]);
	}
}
