<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Atproto\Chat\ChatSender;
use OCA\Social\Atproto\Chat\ChatState;
use OCA\Social\Atproto\Lexicon\LexiconException;
use OCA\Social\Atproto\Publisher\InteractionPublisher;
use OCA\Social\Atproto\Publisher\BlueskyBookmarks;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\Backoff;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\AppViewNotFoundException;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Exceptions\AtprotoKeysSealedException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\BlockedBy\BlockedByService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StreamActionService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\VerificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One post's trip to Bluesky, queued by the listener the moment the post
 * is made, deleted or edited, so the Fediverse delivery never waits for it.
 *
 * `argument`: `action` (publish, delete, edit, bookmark, unbookmark, message for a direct
 * message, profile with an actor's id, or verifications, with any `id`,
 * for every verification moved to another verifying account) and the
 * `id`; for a like or repost (`like`, `unlike`, `repost`, `unrepost`) the
 * id is the Like's or Announce's, with the `post` and the `actor` when one
 * is made; a bookmark has the post id and actor; for `chat` the id is the person's, with what `ChatState` does to
 * their Bluesky conversations (`chat`, `convos`, `member`). A failure that
 * may pass — a service down, a lock that timed out — queues the job again
 * with the attempts so far in `tries`, later each time (`Backoff::bluesky()`),
 * until the last attempt gives up; one no attempt would change — what it is
 * of is gone, a request Bluesky refuses as such — is logged and left, for a
 * post to the reconcile pass. A like or repost undone meanwhile writes
 * nothing. Before a like, a repost or a new post's mentions reach accounts
 * on Bluesky, whether they have blocked the local account is asked and
 * recorded (`BlockedByService`); what is written does not change with the
 * answer.
 */
class AtprotoPublish extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private Publisher $publisher,
		private InteractionPublisher $interactions,
		private StreamService $streamService,
		private CacheActorService $cacheActorService,
		private LoggerInterface $logger,
		private ChatSender $chat,
		private ChatState $chatState,
		private VerificationService $verifications,
		private ActionsRequest $actions,
		private IJobList $jobList,
		private ?BlockedByService $blockedBy = null,
		private ?BlueskyBookmarks $bookmarks = null,
		private ?StreamActionService $streamActions = null,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$action = is_array($argument) ? (string)($argument['action'] ?? '') : '';
		$id = is_array($argument) ? (string)($argument['id'] ?? '') : '';
		if ($id === '') {
			return;
		}
		try {
			if (in_array($action, ['like', 'unlike', 'repost', 'unrepost'], true)) {
				$this->interact($action, $id, (string)($argument['post'] ?? ''), (string)($argument['actor'] ?? ''));

				return;
			}
			if (in_array($action, ['bookmark', 'unbookmark'], true)) {
				$this->bookmark($action, $id, (string)($argument['actor'] ?? ''));

				return;
			}
			if ($action === 'delete') {
				$this->publisher->deletePost($id);
				$this->interactions->removeAllOf($id);

				return;
			}
			if ($action === 'message') {
				$this->chat->send($this->streamService->getStreamById($id));

				return;
			}
			if ($action === 'chat') {
				$convos = is_array($argument['convos'] ?? null) ? array_values(array_filter($argument['convos'], 'is_string')) : [];
				$this->chatState->apply($id, (string)($argument['chat'] ?? ''), $convos, (string)($argument['member'] ?? ''));

				return;
			}
			if ($action === 'profile') {
				$this->publisher->publishProfile($this->cacheActorService->getFromId($id));

				return;
			}
			if ($action === 'verifications') {
				$this->verifications->republishAll();

				return;
			}
			$post = $this->streamService->getStreamById($id);
			if ($action === 'edit') {
				$this->publisher->editPost($post);
			} else {
				$this->askBlocks($post->getAttributedTo(), array_column($post->getTags('Mention'), 'href'));
				$this->publisher->publishPost($post);
			}
		} catch (Throwable $e) {
			$this->failed($argument, $e);
		}
	}

	/**
	 * Queues the job again after a failure that may pass, until the last
	 * attempt.
	 */
	private function failed(array $argument, Throwable $e): void {
		$context = ['action' => (string)($argument['action'] ?? ''), 'post' => (string)($argument['id'] ?? ''), 'exception' => $e];
		if (self::isPermanent($e)) {
			$this->logger->warning('Bluesky publication failed', $context);

			return;
		}
		$backoff = Backoff::bluesky();
		$tries = (int)($argument['tries'] ?? 0) + 1;
		if ($tries >= $backoff->maxTries()) {
			$this->logger->error('Bluesky publication given up after the last attempt', $context + ['tries' => $tries]);

			return;
		}
		$this->logger->notice('Bluesky publication failed, tried again later', $context + ['tries' => $tries]);
		$this->jobList->scheduleAfter(self::class, $this->time->getTime() + $backoff->delay($tries), ['tries' => $tries] + $argument);
	}

	/**
	 * Whether no later attempt would go otherwise: what the job is of is
	 * gone, a service refused the request as such (a 4xx other than a
	 * timeout or too many requests), the record does not fit its lexicon,
	 * or the keys wait for an administrator.
	 */
	public static function isPermanent(Throwable $e): bool {
		if ($e instanceof StreamNotFoundException || $e instanceof ActionDoesNotExistException
			|| $e instanceof AtprotoIdentityNotFoundException || $e instanceof AppViewNotFoundException
			|| $e instanceof AtprotoKeysSealedException) {
			return true;
		}
		if (!$e instanceof AtprotoException) {
			return false;
		}
		$status = $e->getCode();

		return $e->getPrevious() instanceof LexiconException
			|| ($status >= 400 && $status < 500 && !in_array($status, [408, 425, 429], true));
	}

	private function interact(string $action, string $id, string $postId, string $actorId): void {
		switch ($action) {
			case 'unlike':
				$this->interactions->unlike($id);

				return;
			case 'unrepost':
				$this->interactions->unrepost($id);

				return;
		}
		if (!$this->stillMade($action, $id)) {
			return;
		}
		$actor = $this->cacheActorService->getFromId($actorId);
		$post = $this->streamService->getStreamById($postId);
		$this->askBlocks($actor->getId(), [$post->getAttributedTo()]);
		if ($action === 'like') {
			$this->interactions->like($actor, $post, $id);
		} else {
			$this->interactions->repost($actor, $post, $id);
		}
	}

	/** Applies only the latest local bookmark state; a superseded job is a no-op. */
	private function bookmark(string $action, string $postId, string $actorId): void {
		if ($this->bookmarks === null || $this->streamActions === null || $actorId === '' || $postId === '') {
			return;
		}
		$on = $action === 'bookmark';
		if ($this->streamActions->getActionBool($actorId, $postId, StreamAction::BOOKMARKED) !== $on) {
			return;
		}
		$this->bookmarks->sync($this->cacheActorService->getFromId($actorId), $postId, $on);
	}

	/**
	 * Whether the Like or the Announce is still here: undone meanwhile, its
	 * record is not written, however late the job runs.
	 */
	private function stillMade(string $action, string $id): bool {
		try {
			if ($action === 'like') {
				$this->actions->getById($id);
			} else {
				$this->streamService->getStreamById($id);
			}
		} catch (ActionDoesNotExistException|StreamNotFoundException) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<mixed> $actorIds the accounts the local one reaches
	 */
	private function askBlocks(string $localId, array $actorIds): void {
		try {
			$this->blockedBy?->ask($localId, array_values(array_filter($actorIds, 'is_string')));
		} catch (Throwable $e) {
			$this->logger->info('Not asked who blocked an account', ['actor' => $localId, 'exception' => $e]);
		}
	}
}
