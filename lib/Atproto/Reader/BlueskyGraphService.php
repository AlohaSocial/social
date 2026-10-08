<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The follow graph towards Bluesky: a local follow of a Bluesky account is
 * an `app.bsky.graph.follow` record in the local repository, so the AppView
 * counts it, and a watch row, so the author's feed is read from then on.
 * The watch outlives any one follower and goes when the last one leaves.
 */
class BlueskyGraphService {
	public function __construct(
		private Publisher $publisher,
		private AtprotoWatchRequest $watches,
		private FollowsRequest $follows,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The record and the watch for a follow that was just saved. A record
	 * that cannot be written (no identity, the repository refusing) is
	 * logged; the follow stands here either way and the watch is kept.
	 */
	public function follow(Person $actor, Person $target, Follow $follow): void {
		$did = BlueskyIds::didOf($target->getId());
		if ($did === '') {
			return;
		}
		try {
			$this->publisher->writeRecord($actor, RecordMapper::FOLLOW, [
				'$type' => RecordMapper::FOLLOW,
				'subject' => $did,
				'createdAt' => Syntax::datetime($this->time->getTime()),
			], $follow->getId());
		} catch (Throwable $e) {
			$this->logger->warning('Follow not written to Bluesky', ['actor' => $actor->getId(), 'target' => $target->getId(), 'exception' => $e]);
		}
		$this->watches->add($did, $target->getAccount());
	}

	/**
	 * The record goes; the watch too when no local account follows the
	 * author any more.
	 */
	public function unfollow(Person $actor, Person $target, Follow $follow): void {
		$did = BlueskyIds::didOf($target->getId());
		if ($did === '') {
			return;
		}
		try {
			$this->publisher->removeRecord(RecordMapper::FOLLOW, $follow->getId());
		} catch (Throwable $e) {
			$this->logger->warning('Follow not removed from Bluesky', ['actor' => $actor->getId(), 'target' => $target->getId(), 'exception' => $e]);
		}
		if ($this->follows->countFollowers($target->getId()) === 0) {
			$this->watches->remove($did);
		}
	}
}
