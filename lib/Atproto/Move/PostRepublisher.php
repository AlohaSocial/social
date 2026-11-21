<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Cron\AtprotoMove;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\ImportedPostsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\MoveErrorException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The posts written here that a move here takes off Bluesky, as the identity
 * this server made for the account is retired with its repository. The count
 * is shown before the move; afterwards the person can have them republished
 * into the moved repository, a page at a time through the Publisher (§13.1).
 */
class PostRepublisher {
	/** how many of the account's posts one run of the job looks at */
	public const PAGE = 100;

	public function __construct(
		private AtprotoRepoRequest $repoRequest,
		private StreamRequest $streamRequest,
		private ImportedPostsRequest $imported,
		private Publisher $publisher,
		private ActorsRequest $actors,
		private AtprotoMoveRequest $moves,
		private IJobList $jobList,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/** How many posts of an identity are on Bluesky now. */
	public function onBluesky(?Identity $identity): int {
		return $identity === null ? 0 : $this->repoRequest->countRecords($identity->did, RecordMapper::POST);
	}

	/**
	 * Before an identity is retired by a move: how many of its posts were
	 * on Bluesky, and the time they were written in, kept in the move's
	 * progress as `republish`.
	 */
	public function remember(Move $move, Identity $retired): void {
		if ($retired->did === $move->did) {
			return;
		}
		$count = $this->onBluesky($retired);
		if ($count > 0) {
			$move->progress['republish'] = ['count' => $count, 'from' => $retired->creation, 'until' => $this->time->getTime(), 'state' => ''];
		}
	}

	/**
	 * Queues the posts a finished move took off Bluesky to be published
	 * again.
	 *
	 * @throws MoveErrorException
	 */
	public function queue(?Move $move): Move {
		if ($move === null || $move->state !== Move::DONE || (int)($move->progress['republish']['count'] ?? 0) === 0) {
			throw new MoveErrorException('No posts of a finished move are waiting to be published again', 'nothing-to-republish');
		}
		if (($move->progress['republish']['state'] ?? '') !== '') {
			throw new MoveErrorException('The posts are published again already', 'republish-queued');
		}
		$move->progress['republish']['state'] = 'queued';
		$move->progress['republish']['published'] = 0;
		$this->moves->update($move);
		$this->jobList->add(AtprotoMove::class, ['move' => $move->id, 'republish' => true]);

		return $move;
	}

	/**
	 * Publishes one page of the account's posts written while the retired
	 * identity was its own — not one brought over from elsewhere — and
	 * queues the next page.
	 */
	public function run(Move $move): void {
		$republish = $move->progress['republish'] ?? null;
		if (!is_array($republish) || ($republish['state'] ?? '') !== 'queued') {
			return;
		}
		$actorId = $this->actors->getFromUserId($move->userId)->getId();
		$page = $this->streamRequest->getByAuthor($actorId, self::PAGE, (string)($republish['cursor'] ?? '0'));
		$published = 0;
		foreach ($page as $post) {
			$republish['cursor'] = (string)$post->getNid();
			$written = $post->getPublishedTime();
			if ($written < (int)$republish['from'] || $written > (int)$republish['until'] || $this->imported->isImported($actorId, $post->getId())) {
				continue;
			}
			try {
				$published += $this->publisher->publishPost($post) !== null ? 1 : 0;
			} catch (Throwable $e) {
				$this->logger->warning('Post not published again after a Bluesky move', ['post' => $post->getId(), 'exception' => $e]);
			}
		}
		$republish['published'] = (int)($republish['published'] ?? 0) + $published;
		$more = count($page) === self::PAGE;
		$republish['state'] = $more ? 'queued' : 'done';
		$move->progress['republish'] = $republish;
		$this->moves->update($move);
		if ($more) {
			$this->jobList->add(AtprotoMove::class, ['move' => $move->id, 'republish' => true]);
		}
	}
}
