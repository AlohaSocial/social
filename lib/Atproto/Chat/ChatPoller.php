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
use OCA\Social\Service\DurableCache;
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
	public const PAGE = ChatBackfill::PAGE;
	private const TABLE = CoreRequestBuilder::TABLE_ATPROTO_CHAT_CURSOR;
	private const CREATE = 'chat.bsky.convo.defs#logCreateMessage';
	private const DELETE = 'chat.bsky.convo.defs#logDeleteMessage';
	private const READ = 'chat.bsky.convo.defs#logReadConvo';
	/** the older name of `logReadConvo`, which a chat service may still send */
	private const READ_MESSAGE = 'chat.bsky.convo.defs#logReadMessage';
	private const FIRST_IMPORT_CACHE = ChatBackfill::CACHE;
	private const FIRST_IMPORT_TTL = ChatBackfill::TTL;
	private const ADD_REACTION = 'chat.bsky.convo.defs#logAddReaction';
	private const REMOVE_REACTION = 'chat.bsky.convo.defs#logRemoveReaction';
	/** Log records which affect chat state Aloha does not currently model. */
	private const IGNORED_LOGS = [
		'chat.bsky.convo.defs#logBeginConvo',
		'chat.bsky.convo.defs#logAcceptConvo',
		'chat.bsky.convo.defs#logLeaveConvo',
		'chat.bsky.convo.defs#logMuteConvo',
		'chat.bsky.convo.defs#logUnmuteConvo',
		'chat.bsky.convo.defs#logReadJoinRequests',
		'chat.bsky.convo.defs#logAddMember',
		'chat.bsky.convo.defs#logRemoveMember',
		'chat.bsky.convo.defs#logMemberJoin',
		'chat.bsky.convo.defs#logMemberLeave',
		'chat.bsky.convo.defs#logLockConvo',
		'chat.bsky.convo.defs#logUnlockConvo',
		'chat.bsky.convo.defs#logLockConvoPermanently',
		'chat.bsky.convo.defs#logEditGroup',
		'chat.bsky.convo.defs#logCreateJoinLink',
		'chat.bsky.convo.defs#logEditJoinLink',
		'chat.bsky.convo.defs#logEnableJoinLink',
		'chat.bsky.convo.defs#logDisableJoinLink',
		'chat.bsky.convo.defs#logIncomingJoinRequest',
		'chat.bsky.convo.defs#logApproveJoinRequest',
		'chat.bsky.convo.defs#logRejectJoinRequest',
		'chat.bsky.convo.defs#logOutgoingJoinRequest',
		'chat.bsky.convo.defs#logWithdrawIncomingJoinRequest',
		'chat.bsky.convo.defs#logWithdrawOutgoingJoinRequest',
	];

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
		private DurableCache $durableCache,
		private ChatBackfill $backfill,
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
		$snapshotKey = 'revision:' . $identity->did;
		$snapshotRevision = $this->durableCache->getShared(self::FIRST_IMPORT_CACHE, $snapshotKey);
		$rev = is_string($snapshotRevision) ? $snapshotRevision : '';
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
			if (!is_string($snapshotRevision)) {
				foreach ($answer['convos'] as $convo) {
					if (is_array($convo)) {
						$rev = max($rev, (string)($convo['rev'] ?? ''));
					}
				}
				$snapshotRevision = $rev;
				$this->durableCache->setShared(self::FIRST_IMPORT_CACHE, $snapshotKey, $rev, self::FIRST_IMPORT_TTL);
			}
			foreach ($answer['convos'] as $convo) {
				if (!is_array($convo)) {
					continue;
				}
				$unread = max(0, (int)($convo['unreadCount'] ?? 0));
				$id = (string)($convo['id'] ?? '');
				if ($unread === 0 || $id === '') {
					continue;
				}
				$completeKey = 'complete:' . hash('sha256', $identity->did . "\0" . $id);
				if ($this->durableCache->getShared(self::FIRST_IMPORT_CACHE, $completeKey) === $snapshotRevision) {
					continue;
				}
				$members = $this->allMembers($identity, $key, $id, self::dids($convo['members'] ?? null));
				$this->members[$id] = $members;
				$imported = $this->backfill->import($identity, $key, $id, $unread, $members);
				$handled += $imported['handled'];
				if (!$imported['complete']) {
					// Do not advance the account cursor until every unread window has
					// been durably imported. The conversation checkpoint resumes it.
					return [$handled, ''];
				}
				$this->durableCache->setShared(self::FIRST_IMPORT_CACHE, $completeKey, $snapshotRevision, self::FIRST_IMPORT_TTL);

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

		$this->durableCache->removeShared(self::FIRST_IMPORT_CACHE, $snapshotKey);

		return [$handled, $rev];
	}

	/** Fetch the complete participant list, including paginated group members. @param list<string> $summary */
	private function allMembers(Identity $identity, PrivateKey $key, string $convo, array $summary): array {
		$members = $summary;
		$cursor = '';
		$seen = [];
		do {
			$params = ['convoId' => $convo, 'limit' => self::PAGE];
			if ($cursor !== '') {
				$params['cursor'] = $cursor;
			}
			$page = $this->appView->chatAs($identity->did, $key, 'chat.bsky.convo.getConvoMembers', $params);
			if (!is_array($page['members'] ?? null)) {
				throw new \RuntimeException('Bluesky chat member response is missing its member list');
			}
			$members = [...$members, ...self::dids($page['members'])];
			$next = (string)($page['cursor'] ?? '');
			if ($next !== '' && ($next === $cursor || isset($seen[$next]))) {
				throw new \RuntimeException('Bluesky chat member pagination did not advance');
			}
			$seen[$next] = true;
			$cursor = $next;
		} while ($cursor !== '');

		return array_values(array_unique($members));
	}

	/**
	 * What the chat log says happened since `$rev`.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function since(Identity $identity, string $rev): array {
		$key = $this->identities->signingKey($identity);
		$answer = $this->appView->chatAs($identity->did, $key, 'chat.bsky.convo.getLog', ['cursor' => $rev]);
		if (!is_array($answer['logs'] ?? null)) {
			throw new \RuntimeException('Bluesky chat log response is missing its log list');
		}
		if (isset($answer['cursor']) && !is_string($answer['cursor'])) {
			throw new \RuntimeException('Bluesky chat log response has an invalid cursor');
		}
		$handled = 0;
		$newest = $rev;
		foreach ($answer['logs'] as $log) {
			if (!is_array($log) || !is_string($log['$type'] ?? null) || !is_string($log['rev'] ?? null) || $log['rev'] === '') {
				throw new \RuntimeException('Bluesky chat log contains an incomplete event');
			}
			$convo = (string)($log['convoId'] ?? '');
			$type = (string)($log['$type'] ?? '');
			$message = is_array($log['message'] ?? null) ? $log['message'] : null;
			if (in_array($type, [self::CREATE, self::DELETE, self::READ, self::READ_MESSAGE, self::ADD_REACTION, self::REMOVE_REACTION], true)) {
				if ($convo === '' || $message === null) {
					throw new \RuntimeException('Bluesky chat log event is missing its conversation or message');
				}
				$handled += match ($type) {
					self::CREATE => $this->create($identity, $key, $convo, $message) ? 1 : 0,
					self::DELETE => $this->store->deleted($identity, $convo, $message) ? 1 : 0,
					self::READ, self::READ_MESSAGE => $this->store->read($identity, $convo, $message) ? 1 : 0,
					self::ADD_REACTION => $this->store->reaction(
						$identity, $convo, $message, is_array($log['reaction'] ?? null) ? $log['reaction'] : [], true, $log['rev']
					) ? 1 : 0,
					self::REMOVE_REACTION => $this->store->reaction(
						$identity, $convo, $message, is_array($log['reaction'] ?? null) ? $log['reaction'] : [], false, $log['rev']
					) ? 1 : 0,
				};
			} elseif (!in_array($type, self::IGNORED_LOGS, true)) {
				// An unknown future event must not be acknowledged as though this
				// reader understood it. Known non-message events above are deliberate
				// no-ops; newer lexicon variants are retried after an update.
				throw new \RuntimeException('Unsupported Bluesky chat log event: ' . $type);
			}
			// The getLog lexicon is revision based, not limit/cursor paginated.
			// Advance only through events whose processing completed (or whose
			// known type is deliberately ignored), never to a response cursor that
			// could be ahead of a truncated or malformed event list.
			$newest = max($newest, $log['rev']);
		}
		if ($answer['logs'] === []) {
			$newest = max($newest, $answer['cursor'] ?? '');
		}

		return [$handled, $newest];
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
				$this->members[$convo] = $this->allMembers($identity, $key, $convo, self::dids($members));
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
