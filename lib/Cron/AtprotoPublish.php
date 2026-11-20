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
use OCA\Social\Atproto\Publisher\BlueskyBookmarks;
use OCA\Social\Atproto\Publisher\InteractionPublisher;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\Backoff;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\AppViewNotFoundException;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Exceptions\AtprotoKeysSealedException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Service\BlockedBy\BlockedByService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\VerificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One post's trip to Bluesky, queued by the listener when the post is made,
 * deleted or edited, so the Fediverse delivery never waits for it.
 *
 * `argument` is `action` and `id`: `publish`, `delete`, `edit`; `bookmark` and
 * `unbookmark` with the post id and `actor`; `like`, `unlike`, `repost` and
 * `unrepost` with the Like's or Announce's id and, when made, `post` and
 * `actor`; `message` for a direct message; `profile` with an actor's id;
 * `verifications`, with any `id`, when verifications move to another verifying
 * account; `chat` with the person's id and what `ChatState` does (`chat`,
 * `convos`, `member`). A failure that may pass is queued again with `tries`,
 * later each time (`Backoff::bluesky()`); one no attempt would change is
 * logged and left to the reconcile pass. Whether Bluesky accounts a like,
 * repost or mention reaches have blocked the local account is recorded first
 * (`BlockedByService`).
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
		private BlueskyBookmarks $bookmarks,
		private ?BlockedByService $blockedBy = null,
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
			if ($action === 'delete') {
				$this->publisher->deletePost($id);
				$this->interactions->removeAllOf($id);

				return;
			}
			if (in_array($action, ['bookmark', 'unbookmark'], true)) {
				$this->bookmarks->syncCurrent($this->cacheActorService->getFromId((string)($argument['actor'] ?? '')), $id);

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
