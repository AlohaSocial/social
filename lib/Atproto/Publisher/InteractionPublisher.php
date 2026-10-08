<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Likes and boosts of posts that exist on Bluesky (§8.6): a local like of
 * a Bluesky post, or of a local post that has a record, writes
 * `app.bsky.feed.like` in the local account's repository; a boost writes
 * `app.bsky.feed.repost`; undoing either removes the record. A like of a
 * post that is on the Fediverse alone writes nothing. One action, two
 * networks — the viewer flags the status carries are the same either way.
 */
class InteractionPublisher {
	/** the most records one deleted post takes with it in one run */
	private const CASCADE_LIMIT = 500;

	public function __construct(
		private Publisher $publisher,
		private PostRefs $refs,
		private ActionsRequest $actions,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $likeId the Like's id here, the key the record is removed by
	 * @throws AtprotoException
	 */
	public function like(Person $actor, Stream $post, string $likeId): bool {
		return $this->write($actor, RecordMapper::LIKE, $post, $likeId);
	}

	/**
	 * @throws AtprotoException
	 */
	public function unlike(string $likeId): bool {
		return $this->publisher->removeRecord(RecordMapper::LIKE, $likeId);
	}

	/**
	 * @param string $announceId the Announce's id here
	 * @throws AtprotoException
	 */
	public function repost(Person $actor, Stream $post, string $announceId): bool {
		return $this->write($actor, RecordMapper::REPOST, $post, $announceId);
	}

	/**
	 * @throws AtprotoException
	 */
	public function unrepost(string $announceId): bool {
		return $this->publisher->removeRecord(RecordMapper::REPOST, $announceId);
	}

	/**
	 * Removes the like and repost records local accounts made of a post that
	 * is gone (§8.5): the likes and boosts here outlive the post, and so
	 * would their records on Bluesky.
	 *
	 * @return int how many records were removed
	 */
	public function removeAllOf(string $postId): int {
		$removed = 0;
		foreach ([Like::TYPE => RecordMapper::LIKE, Announce::TYPE => RecordMapper::REPOST] as $type => $collection) {
			foreach ($this->actions->getActionsOnObject($postId, $type, self::CASCADE_LIMIT) as $action) {
				try {
					$removed += $this->publisher->removeRecord($collection, $action->getId()) ? 1 : 0;
				} catch (Throwable $e) {
					$this->logger->warning('Bluesky record not removed', ['action' => $action->getId(), 'exception' => $e]);
				}
			}
		}

		return $removed;
	}

	/**
	 * The strong reference a like or repost names: the post's `at://` URI
	 * and CID when it is a Bluesky post, or a local post with a record.
	 *
	 * @return array{uri: string, cid: string}|null
	 */
	public function subjectOf(Stream $post): ?array {
		return $this->refs->strongRef($post->getId());
	}

	/**
	 * @throws AtprotoException
	 */
	private function write(Person $actor, string $collection, Stream $post, string $localId): bool {
		$subject = $this->subjectOf($post);
		if ($subject === null) {
			return false;
		}

		return $this->publisher->writeRecord($actor, $collection, [
			'$type' => $collection,
			'subject' => $subject,
			'createdAt' => Syntax::datetime($this->time->getTime()),
		], $localId);
	}
}
