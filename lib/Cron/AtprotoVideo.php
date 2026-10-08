<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\VideoUploadService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves the videos on their way to Bluesky on, every minute: sends the
 * queued ones to the video service, asks after the ones it is making, and
 * publishes each post whose video has ended — with it, or as a link.
 */
class AtprotoVideo extends TimedJob {
	private const INTERVAL = 60;

	public function __construct(
		ITimeFactory $time,
		private AtprotoConfig $config,
		private VideoUploadService $videos,
		private Publisher $publisher,
		private StreamRequest $streamRequest,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run($argument): void {
		if (!$this->config->isEnabled() || $this->config->videoService() === '') {
			return;
		}
		foreach ($this->videos->advance() as $postId) {
			try {
				$this->publisher->publishPost($this->streamRequest->getStreamById($postId));
			} catch (StreamNotFoundException) {
				$this->videos->forget($postId);
			} catch (Throwable $e) {
				$this->logger->warning('Post with a video not published to Bluesky', ['post' => $postId, 'exception' => $e]);
			}
		}
	}
}
