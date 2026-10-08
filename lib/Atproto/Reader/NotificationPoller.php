<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\AP;
use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Watch;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Exceptions\AppViewNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\SignatureService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What Bluesky did to local accounts (§10). A PDS is not told when somebody
 * on Bluesky follows, likes, reposts or answers one of its users — those
 * are records in other repositories, indexed by the AppView — so the
 * AppView's notifications are asked for, as each user, with a token their
 * key signed. Each notification becomes the activity a Fediverse server
 * would have sent: a `Follow` of the account, a `Like` or `Announce` of
 * the post, a reply, mention or quote stored as the post it is in; the
 * import path makes the notification, the count and the follower row.
 */
class NotificationPoller {
	public const INTERVAL = 120;
	/** an account nobody on Bluesky interacts with is asked twice a day */
	public const MAX_BACKOFF = 12 * 3600;
	public const BATCH = 50;
	public const PAGE = 50;
	private const TABLE = CoreRequestBuilder::TABLE_ATPROTO_NOTIFY_CURSOR;
	private const METHOD = 'app.bsky.notification.listNotifications';

	public function __construct(
		private AtprotoConfig $config,
		private IdentityService $identities,
		private AtprotoWatchRequest $cursors,
		private AppViewClient $appView,
		private PostStore $store,
		private LocalRecordResolver $local,
		private ActorMapper $actorMapper,
		private BlueskyActorService $actors,
		private ImportService $import,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{accounts: int, handled: int, requests: int}
	 */
	public function poll(int $limit = self::BATCH): array {
		$result = ['accounts' => 0, 'handled' => 0, 'requests' => 0];
		if (!$this->config->isEnabled()) {
			return $result;
		}
		$this->enrol();
		$ceiling = $this->config->syncCeiling();
		foreach ($this->cursors->getDue($this->time->getTime(), $limit, self::TABLE) as $cursor) {
			if ($result['requests'] >= $ceiling) {
				break;
			}
			$result['accounts']++;
			$result['requests']++;
			$result['handled'] += $this->pollAccount($cursor);
		}

		return $result;
	}

	/**
	 * One local account: the newest notifications since the last read.
	 *
	 * @return int how many were handled
	 */
	public function pollAccount(Watch $cursor): int {
		$now = $this->time->getTime();
		try {
			$identity = $this->identities->getByDid($cursor->did);
			if (!$identity->isActive()) {
				$this->cursors->remove($cursor->did, self::TABLE);

				return 0;
			}
			$answer = $this->appView->queryAs($identity->did, $this->identities->signingKey($identity), self::METHOD, ['limit' => self::PAGE]);
		} catch (AppViewNotFoundException $e) {
			$this->cursors->failed($cursor->did, $e->getMessage(), $now + self::MAX_BACKOFF, self::TABLE);

			return 0;
		} catch (Throwable $e) {
			$this->logger->notice('Bluesky notifications not read', ['did' => $cursor->did, 'exception' => $e]);
			$this->cursors->failed($cursor->did, $e->getMessage(), $now + min(self::MAX_BACKOFF, self::INTERVAL * (1 << min(10, $cursor->failures + 1))), self::TABLE);

			return 0;
		}

		$handled = 0;
		$newest = $cursor->cursor;
		foreach (is_array($answer['notifications'] ?? null) ? $answer['notifications'] : [] as $notification) {
			if (!is_array($notification)) {
				continue;
			}
			$seen = (string)($notification['indexedAt'] ?? '');
			if ($cursor->cursor !== '' && $seen !== '' && $seen <= $cursor->cursor) {
				break;
			}
			if ($seen > $newest) {
				$newest = $seen;
			}
			$handled += $this->handle($identity, $notification) ? 1 : 0;
		}
		$this->cursors->synced($cursor->did, $newest, $now, $now + $this->nextInterval($cursor, $handled > 0), self::TABLE);

		return $handled;
	}

	/**
	 * One notification as the activity it stands for.
	 */
	public function handle(Identity $identity, array $notification): bool {
		$author = is_array($notification['author'] ?? null) ? $notification['author'] : [];
		$did = (string)($author['did'] ?? '');
		$uri = (string)($notification['uri'] ?? '');
		$parsed = Syntax::parseAtUri($uri);
		if ($did === '' || $parsed === null || $parsed['authority'] !== $did) {
			return false;
		}
		$actorId = BlueskyIds::actorId($did);
		$published = PostMapper::datetime((string)($notification['indexedAt'] ?? ''));
		$reason = (string)($notification['reason'] ?? '');
		switch ($reason) {
			case 'follow':
				if (!$this->ensureActor($author)) {
					return false;
				}

				return $this->process([
					'id' => $actorId . '/follow/' . $parsed['rkey'],
					'type' => 'Follow',
					'actor' => $actorId,
					'object' => $identity->actorId,
					'published' => $published,
				]);
			case 'like':
			case 'repost':
				$subject = $this->local->postId((string)($notification['reasonSubject'] ?? ''));
				if ($subject === '' || BlueskyIds::isPostId($subject) || !$this->ensureActor($author)) {
					return false;
				}

				return $this->process([
					'id' => $actorId . '/' . $reason . '/' . $parsed['rkey'],
					'type' => $reason === 'like' ? 'Like' : 'Announce',
					'actor' => $actorId,
					'object' => $subject,
					'published' => $published,
					'to' => [ACore::CONTEXT_PUBLIC],
					'cc' => [BlueskyIds::followersId($did)],
				]);
			case 'reply':
			case 'mention':
			case 'quote':
				return $this->store->storeByUri($uri);
		}

		return false;
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

	private function ensureActor(array $profile): bool {
		$did = (string)($profile['did'] ?? '');
		$actor = $this->actors->cached($did);
		if ($actor !== null) {
			return !BlueskyActorService::isLimited($actor);
		}
		try {
			$this->actors->store($this->actorMapper->person($profile));
		} catch (Throwable $e) {
			$this->logger->notice('Bluesky account not stored', ['did' => $did, 'exception' => $e]);

			return false;
		}

		return true;
	}

	private function process(array $data): bool {
		try {
			$activity = AP::instance()->getItemFromData($data);
			$activity->setOrigin(BlueskyIds::HOST, SignatureService::ORIGIN_REQUEST, $this->time->getTime());
			$this->import->parseIncomingRequest($activity);
		} catch (Throwable $e) {
			$this->logger->warning('Bluesky ' . ($data['type'] ?? '') . ' not handled', ['id' => $data['id'] ?? '', 'exception' => $e]);

			return false;
		}

		return true;
	}

	private function nextInterval(Watch $cursor, bool $hadAny): int {
		if ($hadAny || $cursor->lastSync === 0) {
			return self::INTERVAL;
		}

		return min(self::MAX_BACKOFF, max(self::INTERVAL, $cursor->nextSync - $cursor->lastSync) * 2);
	}
}
