<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StoriesRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hints that let the web app skip requests that would come back empty when
 * the home timeline opens: announcements, the shorts carousel, the weekly
 * recap, memories of this day and team accounts. Passed as
 * `serverData.startup`; `false` means certainly empty, `true` that it may
 * not be, and a failed check says `true`.
 */
class StartupHintsService {
	public function __construct(
		private AnnouncementService $announcementService,
		private StoriesRequest $storiesRequest,
		private MemoriesService $memoriesService,
		private TeamService $teamService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{has_announcements: bool, has_stories: bool, recap_enabled: bool, has_memories: bool, has_teams: bool}
	 */
	public function forViewer(Person $actor, string $userId): array {
		return [
			'has_announcements' => $this->hint('has_announcements', fn (): bool => $this->announcementService->hasActive()),
			'has_stories' => $this->hint('has_stories', fn (): bool => $this->storiesRequest->hasLive()),
			'recap_enabled' => $this->hint('recap_enabled', fn (): bool => $this->memoriesService->recapEnabled($userId)),
			'has_memories' => $this->hint('has_memories', fn (): bool => $this->memoriesService->mayHaveMemories($actor)),
			'has_teams' => $this->hint('has_teams', fn (): bool => $this->teamService->hasTeamsFor($userId)),
		];
	}

	/** @param callable(): bool $check */
	private function hint(string $name, callable $check): bool {
		try {
			return $check();
		} catch (Throwable $e) {
			$this->logger->info('a startup hint could not be worked out; the app will ask', [
				'hint' => $name, 'exception' => $e,
			]);

			return true;
		}
	}
}
