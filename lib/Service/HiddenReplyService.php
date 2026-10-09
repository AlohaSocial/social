<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Replies the author of a thread hid: any reply in the thread of one of
 * their posts, wherever it was written. Kept on the thread's first post; a
 * conversation read here leaves them out, or shows them marked, and a thread
 * that is on Bluesky lists them in its threadgate (`hiddenReplies`), which
 * every AppView honours. A Bluesky author's hidden replies are hidden here
 * the same way (`PostStore`).
 */
class HiddenReplyService {
	public function __construct(
		private StreamRequest $streams,
		private ConversationsRequest $conversations,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * Hides or shows a reply in a thread of the actor's own.
	 *
	 * @throws InvalidResourceException the thread is not theirs, or it is not a reply
	 */
	public function setHidden(Person $actor, Stream $reply, bool $hidden): void {
		$root = $this->rootOf($reply);
		if ($root === null || $root->getId() === $reply->getId() || $root->getAttributedTo() !== $actor->getId()) {
			throw new InvalidResourceException('only the author of a thread hides its replies');
		}
		$ids = array_values(array_diff($root->getHiddenReplies(), [$reply->getId()]));
		if ($hidden) {
			$ids[] = $reply->getId();
		}
		$root->setHiddenReplies($ids);
		$this->streams->updateDetails($root);
		if ($root->isLocal()) {
			try {
				$this->container?->get(Publisher::class)->updateGates($root);
			} catch (Throwable $e) {
				$this->logger->warning('Hidden replies not written to Bluesky', ['post' => $root->getId(), 'exception' => $e]);
			}
		}
	}

	/**
	 * The thread's first post as this server holds it, or null.
	 */
	public function rootOf(Stream $post): ?Stream {
		$rootId = $this->conversations->rootOf($post->getId());
		if ($rootId === $post->getId()) {
			return $post;
		}
		try {
			return $this->streams->getStreamById($rootId);
		} catch (StreamNotFoundException) {
			return null;
		}
	}
}
