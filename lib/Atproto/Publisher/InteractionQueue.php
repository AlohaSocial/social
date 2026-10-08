<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Cron\AtprotoPublish;
use OCP\BackgroundJob\IJobList;

/**
 * Hands a like or boost, and its undoing, to the queued publisher, so the
 * request that made it never waits for a repository commit. Nothing is
 * queued while Bluesky is off.
 */
class InteractionQueue {
	public function __construct(
		private AtprotoConfig $config,
		private IJobList $jobList,
	) {
	}

	public function liked(string $actorId, string $postId, string $likeId): void {
		$this->queue('like', $likeId, $postId, $actorId);
	}

	public function unliked(string $likeId): void {
		$this->queue('unlike', $likeId);
	}

	public function boosted(string $actorId, string $postId, string $announceId): void {
		$this->queue('repost', $announceId, $postId, $actorId);
	}

	public function unboosted(string $announceId): void {
		$this->queue('unrepost', $announceId);
	}

	private function queue(string $action, string $id, string $post = '', string $actor = ''): void {
		if (!$this->config->isEnabled() || $id === '') {
			return;
		}
		$argument = ['action' => $action, 'id' => $id];
		if ($post !== '') {
			$argument['post'] = $post;
			$argument['actor'] = $actor;
		}
		$this->jobList->add(AtprotoPublish::class, $argument);
	}
}
