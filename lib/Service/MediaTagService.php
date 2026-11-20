<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\MediaTagsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Naming the people in a photo, Pixelfed's `MediaTag`.
 *
 *  - Only the post's author may tag, so nobody can push a post onto somebody
 *    else's profile or audience.
 *  - Anybody named may take their own name off, and the author may too.
 *  - A tag is written onto the post as a `Mention`, so the person's own
 *    server tells them, and the post is re-sent as an `Update`.
 *
 * A tag does not widen who may see the post.
 */
class MediaTagService {
	public function __construct(
		private MediaTagsRequest $mediaTagsRequest,
		private StreamRequest $streamRequest,
		private StreamService $streamService,
		private PostDecorationService $postDecorationService,
		private CacheActorService $cacheActorService,
		private ActivityService $activityService,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Names people in one of the author's own posts. The list is the whole set
	 * the post should name, so anybody dropped is untagged and repeating a
	 * call changes nothing.
	 *
	 * @param string[] $accounts handles or ids, as a client has them
	 *
	 * @return Person[] the people the post now names
	 *
	 * @throws ItemNotFoundException the post is unknown or not the author's
	 * @throws InvalidResourceException there is no photo, or too many names
	 */
	public function tag(Person $author, int|string $nid, array $accounts): array {
		$post = $this->ownPost($author, $nid);

		if ($post->getAttachments() === []) {
			// tags name who is in a photo, so a post without one has nothing to tag
			throw new InvalidResourceException('there is no picture on this post');
		}

		if (count($accounts) > MediaTagsRequest::MAX_PER_POST) {
			throw new InvalidResourceException(
				'at most ' . MediaTagsRequest::MAX_PER_POST . ' people in one post'
			);
		}

		$people = [];
		foreach ($accounts as $account) {
			$account = trim((string)$account);
			if ($account === '') {
				continue;
			}

			try {
				$person = $this->cacheActorService->resolve($account);
			} catch (Throwable $e) {
				// an account this server cannot find is left out rather than failing
				// the whole list
				$this->logger->debug('could not name an account in a post', [
					'account' => $account, 'reason' => $e->getMessage(),
				]);
				continue;
			}

			// the shape a client reads. Resolved actors come back in the
			// ActivityPub format, which has no `acct`, no `display_name` and
			// no `avatar` — everything a name under a photograph draws
			$person->setExportFormat(ACore::FORMAT_LOCAL);
			$people[$person->getId()] = $person;
		}

		$was = $this->mediaTagsRequest->forStreams([\OCA\Social\Tools\Nid::normalize($nid)])[\OCA\Social\Tools\Nid::normalize($nid)] ?? [];
		$now = array_keys($people);

		foreach (array_diff($was, $now) as $gone) {
			$this->mediaTagsRequest->untag($nid, $gone);
		}

		foreach ($people as $person) {
			if ($this->mediaTagsRequest->tag($nid, md5($post->getId()), $person->getId(), $author->getId())
				&& $person->getId() !== $author->getId()) {
				$this->notificationService->onPhotoTag($post, $author, $person);
			}
		}

		$this->republish($author, $nid, array_values($people));

		return array_values($people);
	}

	/**
	 * Takes one name off a post.
	 *
	 * The person named, or the author. Anybody else asking is told the same as
	 * somebody asking about a post that does not exist.
	 *
	 * @throws ItemNotFoundException
	 */
	public function untag(Person $viewer, int|string $nid, string $accountId = ''): bool {
		$accountId = ($accountId === '') ? $viewer->getId() : $accountId;

		try {
			$post = $this->streamService->getStreamByNid($nid);
		} catch (Throwable $e) {
			throw new ItemNotFoundException('unknown post');
		}

		$mine = ($accountId === $viewer->getId());
		if (!$mine && $post->getAttributedTo() !== $viewer->getId()) {
			throw new ItemNotFoundException('unknown post');
		}

		$removed = $this->mediaTagsRequest->untag($nid, $accountId);
		if ($removed && $post->getAttributedTo() === $viewer->getId()) {
			// the author taking a name off re-sends the post without it; a
			// person untagging themselves does not, because re-addressing
			// somebody else's post is not theirs to do
			$this->republish($viewer, $nid, $this->peopleIn($nid));
		}

		return $removed;
	}

	/**
	 * The posts somebody is named in, as the reader may see them: read like
	 * any other post for this reader, so hidden ones drop out. A page can
	 * therefore come back short or empty with more rows behind it, so `next`
	 * is the last tag row read, and null once a page read fewer rows than it
	 * asked for.
	 *
	 * @return array{posts: Stream[], next: ?string}
	 */
	public function photosOf(Person $viewer, string $accountId, int $limit = 20, int|string $maxId = '0'): array {
		$limit = max(1, min($limit, MediaTagsRequest::MAX_PAGE));
		$nids = $this->mediaTagsRequest->streamsFor($accountId, $limit, $maxId);

		$posts = [];
		foreach ($nids as $nid) {
			try {
				$post = $this->streamRequest->getStreamByNid($nid);
			} catch (Throwable $e) {
				// gone, or not this reader's to see
				continue;
			}
			$posts[] = $post;
		}

		$this->postDecorationService->decorate($posts, $viewer->getId());

		return [
			'posts' => $posts,
			'next' => (count($nids) >= $limit) ? (string)end($nids) : null,
		];
	}

	/** Every tag on a post, for a deletion. */
	public function forgetStream(int|string $nid): void {
		$this->mediaTagsRequest->deleteByStream($nid);
	}

	/** Every tag naming an account, for a deletion or a suspension. */
	public function forgetActor(string $actorId): void {
		$this->mediaTagsRequest->deleteByActor($actorId);
	}

	/**
	 * The people a post names now, resolved.
	 *
	 * @return Person[]
	 */
	private function peopleIn(int|string $nid): array {
		$ids = $this->mediaTagsRequest->forStreams([\OCA\Social\Tools\Nid::normalize($nid)])[\OCA\Social\Tools\Nid::normalize($nid)] ?? [];

		$people = [];
		foreach ($ids as $id) {
			$person = $this->personFor($id);
			if ($person !== null) {
				$people[] = $person;
			}
		}

		return $people;
	}

	/**
	 * Writes the names onto the post as mentions and sends it again, which is
	 * what reaches the person's own server. A failure here loses the
	 * notification, not the tag, which is stored either way.
	 *
	 * @param Person[] $people
	 */
	private function republish(Person $author, int|string $nid, array $people): void {
		try {
			$post = $this->streamService->getStreamByNid($nid);
			if (!$post->isLocal()) {
				return;
			}

			foreach ($people as $person) {
				// a mention, addressed the way a mention written in the text of
				// the post would be — `cc` on anything but a direct message,
				// which is not a wider audience than the post already had
				$handle = $person->getAccount();
				if ($handle !== '') {
					$this->streamService->addRecipient($post, $post->getVisibility(), $handle);
				}
			}

			$post->setUpdated(gmdate('Y-m-d\TH:i:s\Z'));
			$this->streamService->updateStream($post);
			$this->activityService->updateActivity($author, $post);
		} catch (Throwable $e) {
			$this->logger->warning('could not send a post again after naming somebody in it', [
				'post' => $nid, 'exception' => $e,
			]);
		}
	}

	/**
	 * @throws ItemNotFoundException the post is unknown, or somebody else's
	 */
	private function ownPost(Person $author, int|string $nid): Stream {
		try {
			$post = $this->streamService->getStreamByNid($nid);
		} catch (Throwable $e) {
			throw new ItemNotFoundException('unknown post');
		}

		if ($post->getAttributedTo() !== $author->getId()) {
			// not "forbidden": whose post it is is not a fact to confirm to
			// somebody who is asking about one that is not theirs
			throw new ItemNotFoundException('unknown post');
		}

		return $post;
	}

	private function personFor(string $actorId): ?Person {
		try {
			$person = $this->cacheActorService->getFromId($actorId);
			$person->setExportFormat(ACore::FORMAT_LOCAL);

			return $person;
		} catch (Throwable $e) {
			// somebody this server cannot name any more is left out rather
			// than drawn as a blank
			return null;
		}
	}
}
