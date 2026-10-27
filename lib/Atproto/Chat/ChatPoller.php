<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Chat;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Crypto\PrivateKey;
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
 * account, from where it was read up to; an account's first read backfills
 * all unread conversations and messages before establishing the log cursor. The log
 * also brings what the account wrote in a Bluesky app, and how far it read
 * a conversation there, which is read here too.
 */
class ChatPoller {
	public const INTERVAL = 120;
	/** an account nobody messages on Bluesky is still asked every half hour */
	public const MAX_BACKOFF = 1800;
	public const BATCH = 50;
	/** the upstream maximum page size for both chat conversation and message queries */
	public const PAGE = 100;
	private const TABLE = CoreRequestBuilder::TABLE_ATPROTO_CHAT_CURSOR;
	private const CREATE = 'chat.bsky.convo.defs#logCreateMessage';
	private const DELETE = 'chat.bsky.convo.defs#logDeleteMessage';
	private const READ = 'chat.bsky.convo.defs#logReadConvo';
	/** the older name of `logReadConvo`, which a chat service may still send */
	private const READ_MESSAGE = 'chat.bsky.convo.defs#logReadMessage';

	/** @var array<string, list<string>> the members of the conversations this run asked about, by convo */
	private array $members = [];

	public function __construct(
		private AtprotoConfig $config,
		private IdentityService $identities,
		private AtprotoWatchRequest $cursors,
		private AppViewClient $appView,
		private ChatStore $store,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private ChatDeclaration $declaration,
	) {
	}

	/**
	 * Whether Bluesky's direct messages are read here at all.
	 */
	public function isOn(): bool {
		return $this->config->isEnabled() && $this->config->chat() !== '';
	}

	/**
	 * @param int $deadline the wall clock past which no further account is read, 0 for none
	 * @return array{accounts: int, handled: int}
	 */
	public function poll(int $limit = self::BATCH, int $deadline = 0): array {
		$result = ['accounts' => 0, 'handled' => 0];
		if (!$this->isOn()) {
			return $result;
		}
		$this->enrol();
		$ceiling = $this->config->syncCeiling();
		foreach ($this->cursors->getDue($this->time->getTime(), $limit, self::TABLE) as $cursor) {
			if ($result['accounts'] >= $ceiling || ($deadline > 0 && $this->time->getTime() >= $deadline)) {
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
	 * The first read imports unread messages from every conversation, following
	 * upstream pages. If any page or durable local write fails, the caller leaves
	 * the log cursor empty and retries the complete idempotent backfill.
	 * Who may write to the account is told to Bluesky first, where its
	 * repository does not say it yet.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function first(Identity $identity): array {
		$this->declaration->ensure($identity);
		$key = $this->identities->signingKey($identity);
		$handled = 0;
		$rev = '';
		$convoCursor = '';
		$seenConvoCursors = [];
		do {
			$params = ['limit' => self::PAGE];
			if ($convoCursor !== '') {
				$params['cursor'] = $convoCursor;
			}
			$answer = $this->appView->chatAs($identity->did, $key, 'chat.bsky.convo.listConvos', $params);
			if (!is_array($answer['convos'] ?? null)) {
				throw new \RuntimeException('Bluesky chat conversation response is missing its conversation list');
			}
			foreach ($answer['convos'] as $convo) {
				if (!is_array($convo)) {
					continue;
				}
				$rev = max($rev, (string)($convo['rev'] ?? ''));
				$unread = max(0, (int)($convo['unreadCount'] ?? 0));
				$id = (string)($convo['id'] ?? '');
				if ($unread === 0 || $id === '') {
					continue;
				}
				$members = self::dids($convo['members'] ?? null);
				$this->members[$id] = $members;
				$messageCursor = '';
				$unreadIncoming = 0;
				$messagesToStore = [];
				$seenMessageCursors = [];
				do {
					// unreadCount counts unread messages from other members, not the
					// number of newest messages. Own messages may be interleaved, and
					// deleted-message views still count toward that unread total.
					$messageParams = ['convoId' => $id, 'limit' => self::PAGE];
					if ($messageCursor !== '') {
						$messageParams['cursor'] = $messageCursor;
					}
					$page = $this->appView->chatAs($identity->did, $key, 'chat.bsky.convo.getMessages', $messageParams);
					if (!is_array($page['messages'] ?? null)) {
						throw new \RuntimeException('Bluesky chat message response is missing its message list');
					}
					/** @var list<array<array-key, mixed>> $list */
					$list = array_values(array_filter($page['messages'], 'is_array'));
					if ($list === []) {
						throw new \RuntimeException('Bluesky chat history ended before all unread messages were found');
					}
					foreach ($list as $message) {
						$sender = (string)($message['sender']['did'] ?? '');
						if ($sender !== '' && $sender !== $identity->did) {
							$unreadIncoming++;
						}
						$messagesToStore[] = $message;
					}
					if ($unreadIncoming >= $unread) {
						break;
					}
					$nextMessageCursor = (string)($page['cursor'] ?? '');
					if ($nextMessageCursor === '') {
						throw new \RuntimeException('Bluesky chat history ended before all unread messages were found');
					}
					if ($nextMessageCursor === $messageCursor || isset($seenMessageCursors[$nextMessageCursor])) {
						throw new \RuntimeException('Bluesky chat message pagination did not advance');
					}
					$seenMessageCursors[$nextMessageCursor] = true;
					$messageCursor = $nextMessageCursor;
				} while ($unreadIncoming < $unread);
				// Pages and messages are newest first. Store the complete unread range
				// oldest first so local conversation reply links remain chronological.
				foreach (array_reverse($messagesToStore) as $message) {
					if (($message['$type'] ?? '') === 'chat.bsky.convo.defs#deletedMessageView') {
						// A tombstone proves an unread message existed, but has no
						// content to import. It is deliberately not made into a post.
						continue;
					}
					if ($this->store->received($identity, $id, $message, $members)) {
						$handled++;
					}
				}
			}
			$nextConvoCursor = (string)($answer['cursor'] ?? '');
			if ($nextConvoCursor === '') {
				break;
			}
			if ($nextConvoCursor === $convoCursor || isset($seenConvoCursors[$nextConvoCursor])) {
				throw new \RuntimeException('Bluesky chat conversation pagination did not advance');
			}
			$seenConvoCursors[$nextConvoCursor] = true;
			$convoCursor = $nextConvoCursor;
		} while (true);

		return [$handled, $rev];
	}

	/**
	 * What the chat log says happened since `$rev`.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function since(Identity $identity, string $rev): array {
		$key = $this->identities->signingKey($identity);
		$answer = $this->appView->chatAs($identity->did, $key, 'chat.bsky.convo.getLog', ['cursor' => $rev]);
		$handled = 0;
		$newest = $rev;
		foreach (is_array($answer['logs'] ?? null) ? $answer['logs'] : [] as $log) {
			if (!is_array($log)) {
				continue;
			}
			$convo = (string)($log['convoId'] ?? '');
			$type = (string)($log['$type'] ?? '');
			$message = is_array($log['message'] ?? null) ? $log['message'] : null;
			if ($convo !== '' && $message !== null) {
				$handled += match ($type) {
					self::CREATE => $this->create($identity, $key, $convo, $message) ? 1 : 0,
					self::DELETE => $this->store->deleted($identity, $convo, $message) ? 1 : 0,
					self::READ, self::READ_MESSAGE => $this->store->read($identity, $convo, $message) ? 1 : 0,
					default => 0,
				};
			}
			// Do not let a page cursor jump over an event that was only received
			// in memory: synced() runs only after every event completed without a
			// retryable error. Unhandled event types are intentional no-ops.
			$newest = max($newest, (string)($log['rev'] ?? ''));
		}
		$cursor = (string)($answer['cursor'] ?? '');

		return [$handled, max($newest, $cursor)];
	}

	/**
	 * A message the log says was written: the account's own one, written in
	 * a Bluesky app, is addressed to the conversation's other members, asked
	 * for once a run; one sent from here needs nobody.
	 */
	private function create(Identity $identity, PrivateKey $key, string $convo, array $message): bool {
		$own = ($message['sender']['did'] ?? '') === $identity->did
			&& $this->store->sentHere($identity->did, (string)($message['id'] ?? '')) === '';
		if ($own && !isset($this->members[$convo])) {
			try {
				$answer = $this->appView->chatAs($identity->did, $key, 'chat.bsky.convo.getConvo', ['convoId' => $convo]);
				$members = $answer['convo']['members'] ?? null;
				if (!is_array($members)) {
					throw new \RuntimeException('Bluesky chat conversation response did not include members');
				}
				$this->members[$convo] = self::dids($members);
			} catch (Throwable $e) {
				$this->logger->notice('Members of a Bluesky conversation not read', ['convo' => $convo, 'exception' => $e]);
				// Leave the log cursor before this event. The next poll retries the
				// lookup instead of permanently treating the conversation as empty.
				throw $e;
			}
		}

		return $this->store->received($identity, $convo, $message, $own ? $this->members[$convo] : []);
	}

	/**
	 * The DIDs of a conversation's members (`profileViewBasic`).
	 *
	 * @return list<string>
	 */
	private static function dids(mixed $members): array {
		$dids = [];
		foreach (is_array($members) ? $members : [] as $member) {
			$did = is_array($member) ? (string)($member['did'] ?? '') : '';
			if ($did !== '') {
				$dids[] = $did;
			}
		}

		return $dids;
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
