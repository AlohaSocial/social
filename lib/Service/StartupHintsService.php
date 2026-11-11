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
 * What the web app need not ask for when it opens.
 *
 * Opening the home timeline used to send five requests that nearly always
 * came back empty: the announcements, the stories carousel, the weekly recap,
 * the memories of this day and the team accounts. Each is answered here
 * by one cheap question, and the page carries the answers as
 * `serverData.startup`; the app skips a request whose hint is `false`.
 *
 * A hint is only ever a reason *not* to ask: `false` means the answer is
 * certainly empty, `true` that it may not be. A check that fails says `true`,
 * so the app asks as it always did.
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
