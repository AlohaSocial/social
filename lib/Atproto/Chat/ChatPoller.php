<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Chat;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Watch;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Exceptions\AppViewNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The direct messages local accounts receive on Bluesky's chat service,
 * read into their messages here (`ChatStore`): a Bluesky conversation is a
 * conversation here like any other. The chat log (`getLog`) is read as each
 * account, from where it was read up to; an account's first read starts at
 * the newest message of each conversation that has unread ones.
 */
class ChatPoller {
	public const INTERVAL = 120;
	/** an account nobody messages on Bluesky is still asked every half hour */
	public const MAX_BACKOFF = 1800;
	public const BATCH = 50;
	/** how many conversations an account's first read looks at */
	public const FIRST_CONVOS = 20;
	/** the most unread messages of one conversation an account's first read takes */
	public const FIRST_MESSAGES = 20;
	private const TABLE = CoreRequestBuilder::TABLE_ATPROTO_CHAT_CURSOR;
	private const CREATE = 'chat.bsky.convo.defs#logCreateMessage';
	private const DELETE = 'chat.bsky.convo.defs#logDeleteMessage';

	public function __construct(
		private AtprotoConfig $config,
		private IdentityService $identities,
		private AtprotoWatchRequest $cursors,
		private AppViewClient $appView,
		private ChatStore $store,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether Bluesky's direct messages are read here at all.
	 */
	public function isOn(): bool {
		return $this->config->isEnabled() && $this->config->chat() !== '';
	}

	/**
	 * @return array{accounts: int, handled: int}
	 */
	public function poll(int $limit = self::BATCH): array {
		$result = ['accounts' => 0, 'handled' => 0];
		if (!$this->isOn()) {
			return $result;
		}
		$this->enrol();
		$ceiling = $this->config->syncCeiling();
		foreach ($this->cursors->getDue($this->time->getTime(), $limit, self::TABLE) as $cursor) {
			if ($result['accounts'] >= $ceiling) {
				break;
			}
			$result['accounts']++;
			$result['handled'] += $this->pollAccount($cursor);
		}

		return $result;
	}

	/**
	 * Has an account's messages read on the next run: it just wrote one, or
	 * opened its messages.
	 */
	public function wake(string $did): void {
		$this->cursors->wake($did, $this->time->getTime(), self::TABLE);
	}

	/**
	 * Has a person's messages read on the next run, where they are on
	 * Bluesky and Bluesky's direct messages are read here.
	 */
	public function wakeActor(Person $actor): void {
		if (!$this->isOn()) {
			return;
		}
		$identity = $this->identities->forActor($actor, false);
		if ($identity !== null) {
			$this->wake($identity->did);
		}
	}

	/**
	 * One account: what happened in its conversations since the last read.
	 *
	 * @return int how many messages were stored or removed
	 */
	public function pollAccount(Watch $cursor): int {
		$now = $this->time->getTime();
		try {
			$identity = $this->identities->getByDid($cursor->did);
			if (!$identity->isActive()) {
				$this->cursors->remove($cursor->did, self::TABLE);

				return 0;
			}
			[$handled, $rev] = $cursor->cursor === '' ? $this->first($identity) : $this->since($identity, $cursor->cursor);
		} catch (AppViewNotFoundException $e) {
			$this->cursors->failed($cursor->did, $e->getMessage(), $now + self::MAX_BACKOFF, self::TABLE);

			return 0;
		} catch (Throwable $e) {
			$this->logger->notice('Bluesky direct messages not read', ['did' => $cursor->did, 'exception' => $e]);
			$this->cursors->failed($cursor->did, $e->getMessage(), $now + min(self::MAX_BACKOFF, self::INTERVAL * (1 << min(10, $cursor->failures + 1))), self::TABLE);

			return 0;
		}
		$next = ($handled > 0 || $cursor->lastSync === 0)
			? self::INTERVAL
			: min(self::MAX_BACKOFF, max(self::INTERVAL, $cursor->nextSync - $cursor->lastSync) * 2);
		$this->cursors->synced($cursor->did, $rev !== '' ? $rev : $cursor->cursor, $now, $now + $next, self::TABLE);

		return $handled;
	}

	/**
	 * The first read: the unread messages of the newest conversations, and
	 * the newest `rev` among them, which the log is read from next time.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function first(Identity $identity): array {
		$key = $this->identities->signingKey($identity);
		$answer = $this->appView->chatAs($identity->did, $key, 'chat.bsky.convo.listConvos', ['limit' => self::FIRST_CONVOS]);
		$handled = 0;
		$rev = '';
		foreach (is_array($answer['convos'] ?? null) ? $answer['convos'] : [] as $convo) {
			if (!is_array($convo)) {
				continue;
			}
			$rev = max($rev, (string)($convo['rev'] ?? ''));
			$unread = (int)($convo['unreadCount'] ?? 0);
			$id = (string)($convo['id'] ?? '');
			if ($unread <= 0 || $id === '') {
				continue;
			}
			$messages = $this->appView->chatAs($identity->did, $key, 'chat.bsky.convo.getMessages', ['convoId' => $id, 'limit' => min($unread, self::FIRST_MESSAGES)]);
			// newest first on the wire; stored oldest first, so each answers the one before
			$list = is_array($messages['messages'] ?? null) ? array_reverse($messages['messages']) : [];
			foreach ($list as $message) {
				$handled += is_array($message) && $this->store->received($identity, $id, $message) ? 1 : 0;
			}
		}

		return [$handled, $rev];
	}

	/**
	 * What the chat log says happened since `$rev`.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function since(Identity $identity, string $rev): array {
		$answer = $this->appView->chatAs($identity->did, $this->identities->signingKey($identity), 'chat.bsky.convo.getLog', ['cursor' => $rev]);
		$handled = 0;
		$newest = $rev;
		foreach (is_array($answer['logs'] ?? null) ? $answer['logs'] : [] as $log) {
			if (!is_array($log)) {
				continue;
			}
			$newest = max($newest, (string)($log['rev'] ?? ''));
			$convo = (string)($log['convoId'] ?? '');
			$message = is_array($log['message'] ?? null) ? $log['message'] : null;
			if ($convo === '' || $message === null) {
				continue;
			}
			$handled += match ((string)($log['$type'] ?? '')) {
				self::CREATE => $this->store->received($identity, $convo, $message) ? 1 : 0,
				self::DELETE => $this->store->deleted($convo, $message) ? 1 : 0,
				default => 0,
			};
		}
		$cursor = (string)($answer['cursor'] ?? '');

		return [$handled, max($newest, $cursor)];
	}

	/**
	 * Every active identity gets a cursor row; one whose identity is gone
	 * is dropped when next visited.
	 */
	private function enrol(): void {
		foreach ($this->identities->getAll() as $identity) {
			if ($identity->isActive()) {
				$this->cursors->add($identity->did, $identity->handle, self::TABLE);
			}
		}
	}
}
