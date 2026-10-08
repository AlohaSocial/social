<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\PostMapper;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Likes and boosts of posts that exist on Bluesky (§8.6): a local like of
 * a Bluesky post, or of a local post that has a record, writes
 * `app.bsky.feed.like` in the local account's repository; a boost writes
 * `app.bsky.feed.repost`; undoing either removes the record. A like of a
 * post that is on the Fediverse alone writes nothing. One action, two
 * networks — the viewer flags the status carries are the same either way.
 */
class InteractionPublisher {
	public function __construct(
		private Publisher $publisher,
		private RepositoryService $repositories,
		private ITimeFactory $time,
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
	 * The strong reference a like or repost names: the post's `at://` URI
	 * and CID when it is a Bluesky post, or a local post with a record.
	 *
	 * @return array{uri: string, cid: string}|null
	 */
	public function subjectOf(Stream $post): ?array {
		if (BlueskyIds::isPostId($post->getId())) {
			$details = $post->getDetails(PostMapper::DETAIL);
			$uri = (string)($details['uri'] ?? '');
			$cid = (string)($details['cid'] ?? '');

			return $uri !== '' && $cid !== '' && Syntax::isAtUri($uri) ? ['uri' => $uri, 'cid' => $cid] : null;
		}
		foreach ($this->repositories->getRecordsByLocalId($post->getId()) as $record) {
			if ($record->collection === RecordMapper::POST) {
				return ['uri' => $record->uri(), 'cid' => $record->cid->toString()];
			}
		}

		return null;
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
