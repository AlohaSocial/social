<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\AP;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What happens here when Bluesky stops serving an account, as Jetstream's
 * `account` event or the AppView's refusal says it (§9.5). A deactivated,
 * suspended or taken-down account can come back (takedowns are often
 * reversed), so its mirror is hidden and kept: the actor is marked and its
 * posts lose their recipient rows, which takes them out of every timeline
 * and viewer-limited read until the account is active again and the rows
 * are written back; the follows of it stay. Only a deleted account is gone,
 * so the actor and everything it put here go through the cascade a remote
 * `Delete` of the actor goes through.
 */
class AccountStatus {
	public const DEACTIVATED = 'deactivated';
	public const SUSPENDED = 'suspended';
	public const TAKENDOWN = 'takendown';
	public const DELETED = 'deleted';
	/** the AppView's error names that say the same of an account, by status */
	public const ERRORS = [
		'AccountDeactivated' => self::DEACTIVATED,
		'AccountTakedown' => self::TAKENDOWN,
		'RepoDeactivated' => self::DEACTIVATED,
		'RepoSuspended' => self::SUSPENDED,
		'RepoTakendown' => self::TAKENDOWN,
		'RepoNotFound' => self::DELETED,
	];
	/** posts walked per query while hiding or restoring */
	public const PAGE = 200;

	public function __construct(
		private AtprotoWatchRequest $watches,
		private CacheActorsRequest $cacheActors,
		private StreamRequest $streams,
		private StreamDestRequest $dests,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether the account is gone for good rather than switched off.
	 */
	public static function isGone(string $status): bool {
		return $status === self::DELETED;
	}

	/**
	 * What an account event, or a refusal, says: active again, gone, or
	 * switched off (any other inactive status is taken as switched off, which
	 * keeps the rows).
	 */
	public function apply(string $did, bool $active, string $status): void {
		if ($active) {
			$this->restore($did);
		} elseif (self::isGone($status)) {
			$this->purge($did);
		} else {
			$this->hide($did, $status !== '' ? $status : self::DEACTIVATED);
		}
	}

	/**
	 * The actor marked with the status and limited, its posts and boosts out
	 * of every timeline; nothing is deleted.
	 */
	public function hide(string $did, string $status): void {
		$actor = $this->actor($did);
		if ($actor === null) {
			return;
		}
		$this->mark($actor, $status, true);
		$ids = [];
		foreach ($this->walk($actor->getId()) as $stream) {
			$ids[] = $stream->getId();
			if (count($ids) >= self::PAGE) {
				$this->dests->deleteByStreamIds($ids);
				$ids = [];
			}
		}
		$this->dests->deleteByStreamIds($ids);
		$this->logger->info('Bluesky account hidden', ['did' => $did, 'status' => $status]);
	}

	/**
	 * The account active again: the mark lifted — limited again only when
	 * its labels say so — the recipient rows of its posts written back, and
	 * its feed read on the poller's next pass.
	 */
	public function restore(string $did): void {
		$actor = $this->actor($did);
		if ($actor === null || BlueskyActorService::status($actor) === '') {
			return;
		}
		$this->mark($actor, '', ActorMapper::isLimiting($actor->getDetails(ActorMapper::DETAIL)['labels'] ?? []));
		foreach ($this->walk($actor->getId()) as $stream) {
			try {
				$this->dests->generateStreamDest($stream);
			} catch (Throwable $e) {
				$this->logger->notice('Bluesky post not restored', ['id' => $stream->getId(), 'exception' => $e]);
			}
		}
		$this->watches->wake($did, $this->time->getTime());
		$this->logger->info('Bluesky account restored', ['did' => $did]);
	}

	/**
	 * The actor and everything it put here removed, as a remote `Delete` of
	 * the actor removes them; the watch goes with it.
	 */
	public function purge(string $did): void {
		$this->watches->remove($did);
		$actor = $this->actor($did);
		if ($actor === null) {
			return;
		}
		try {
			AP::instance()->getInterfaceFromType(Person::TYPE)->delete($actor);
		} catch (Throwable $e) {
			$this->logger->warning('Bluesky account not purged', ['did' => $did, 'exception' => $e]);

			return;
		}
		$this->logger->info('Bluesky account purged', ['did' => $did]);
	}

	private function actor(string $did): ?Person {
		try {
			return $this->cacheActors->getFromId(BlueskyIds::actorId($did));
		} catch (Throwable) {
			return null;
		}
	}

	private function mark(Person $actor, string $status, bool $limited): void {
		$details = $actor->getDetails(ActorMapper::DETAIL);
		$details['limited'] = $limited;
		if ($status === '') {
			unset($details['status']);
		} else {
			$details['status'] = $status;
		}
		$actor->setDetailArray(ActorMapper::DETAIL, $details);
		$this->cacheActors->updateDetails($actor);
	}

	/**
	 * @return iterable<Stream> everything the actor put in the stream, newest first
	 */
	private function walk(string $actorId): iterable {
		$before = 0;
		while (true) {
			$page = $this->streams->getByAuthor($actorId, self::PAGE, $before);
			foreach ($page as $stream) {
				yield $stream;
				$before = $stream->getNid();
			}
			if (count($page) < self::PAGE) {
				return;
			}
		}
	}
}
