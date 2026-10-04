<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\RequestQueueService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The half of an inbound move this side does before Mastodon sends it.
 *
 * Mastodon's `leaver` is about to move to our `arrival`. For that to mean
 * anything two things have to be true first, and both are this side's to
 * arrange: `arrival` names `leaver` in its `alsoKnownAs` — the check
 * `MoveInterface` applies, and the one Mastodon applies before it will
 * start a move — and somebody here follows `leaver`, so there is a follow
 * for the `Move` to re-point. The workflow runs this, then has Mastodon
 * deliver the `Move`, then runs `MoveInboundTest`.
 */
class MoveInboundPrepareTest extends TestCase {
	private const FOLLOWER = 'admin';
	private const ARRIVAL = 'arrival';
	private const LEAVER = 'leaver';

	protected function setUp(): void {
		if (Mastodon::fromEnvironment() === null) {
			$this->markTestSkipped('no Mastodon to talk to: set MASTODON_BASE_URL and MASTODON_TOKEN');
		}
	}

	public function testTheArrivalNamesTheLeaverAndSomebodyHereFollowsIt(): void {
		$host = (string)getenv('MASTODON_HOST');
		$leaverId = 'https://' . $host . '/users/' . self::LEAVER;

		$accountService = Server::get(AccountService::class);
		$arrival = $accountService->getActorFromUserId(self::ARRIVAL, true);
		$aliases = Server::get(MigrationService::class)->addAlias(self::ARRIVAL, '@' . self::LEAVER . '@' . $host);
		$this->assertContains($leaverId, $aliases, 'the alias is stored as the actor id the handle resolves to');
		$this->assertContains($leaverId, $accountService->getActorFromUserId(self::ARRIVAL)->getAlsoKnownAs());

		$follower = $accountService->getActorFromUserId(self::FOLLOWER, true);
		Server::get(FollowService::class)->followAccount($follower, self::LEAVER . '@' . $host);
		$this->drainQueue();

		// Mastodon accepts the follow on its workers and the Accept comes back
		// through our inbox; a Move re-points accepted follows only
		$followsRequest = Server::get(FollowsRequest::class);
		$leaver = Server::get(CacheActorService::class)->getFromId($leaverId);
		$accepted = $this->await(static function () use ($followsRequest, $follower, $leaver): ?bool {
			try {
				return $followsRequest->getByPersons($follower->getId(), $leaver->getId())->isAccepted() ? true : null;
			} catch (\Throwable $e) {
				return null;
			}
		});
		$this->assertTrue($accepted, 'Mastodon never accepted the follow of the account that is about to move');
		$this->assertSame($arrival->getId(), $accountService->getActorFromUserId(self::ARRIVAL)->getId());
	}

	private function await(callable $probe, int $seconds = 60): mixed {
		$until = time() + $seconds;
		do {
			$answer = $probe();
			if ($answer !== null) {
				return $answer;
			}
			sleep(1);
		} while (time() < $until);

		return null;
	}

	private function drainQueue(): void {
		$queueService = Server::get(RequestQueueService::class);
		$activityService = Server::get(ActivityService::class);
		$activityService->manageInit();

		$total = 0;
		foreach ($queueService->getRequestStandby($total) as $request) {
			$activityService->manageRequest($request);
		}
	}
}
