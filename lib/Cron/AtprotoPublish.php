<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Atproto\Chat\ChatSender;
use OCA\Social\Atproto\Chat\ChatState;
use OCA\Social\Atproto\Publisher\InteractionPublisher;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Service\BlockedBy\BlockedByService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\VerificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One post's trip to Bluesky, queued by the listener the moment the post
 * is made, deleted or edited, so the Fediverse delivery never waits for it.
 *
 * `argument`: `action` (publish, delete, edit, message for a direct
 * message, profile with an actor's id, or verifications, with any `id`,
 * for every verification moved to another verifying account) and the
 * `id`; for a like or repost (`like`, `unlike`, `repost`, `unrepost`) the
 * id is the Like's or Announce's, with the `post` and the `actor` when one
 * is made; for `chat` the id is the person's, with what `ChatState` does to
 * their Bluesky conversations (`chat`, `convos`, `member`). A failure is
 * logged and, for a post, left to the reconcile pass. Before a like, a
 * repost or a new post's mentions reach accounts on Bluesky, whether they
 * have blocked the local account is asked and recorded
 * (`BlockedByService`); what is written does not change with the answer.
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
			$this->logger->warning('Bluesky publication failed', ['action' => $action, 'post' => $id, 'exception' => $e]);
		}
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
