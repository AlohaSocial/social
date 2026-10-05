<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Mastodon's `leaver` has moved to our `arrival`: what this side did with
 * the `Move` it was sent.
 *
 * Run after `MoveInboundPrepareTest` and after the workflow had Mastodon
 * deliver the `Move`, signed by `leaver`, to the follower's inbox here.
 * `MoveInterface` is expected to have fetched `arrival` fresh, found
 * `leaver` in its `alsoKnownAs`, re-pointed the follow and recorded where
 * `leaver` went.
 */
class MoveInboundTest extends TestCase {
	private const FOLLOWER = 'admin';
	private const ARRIVAL = 'arrival';
	private const LEAVER = 'leaver';

	protected function setUp(): void {
		if (Mastodon::fromEnvironment() === null) {
			$this->markTestSkipped('no Mastodon to talk to: set MASTODON_BASE_URL and MASTODON_TOKEN');
		}
	}

	public function testTheFollowerHereNowFollowsTheArrivalAndTheLeaverSaysWhereItWent(): void {
		$host = (string)getenv('MASTODON_HOST');
		$leaverId = 'https://' . $host . '/users/' . self::LEAVER;
		$accountService = Server::get(AccountService::class);
		$follower = $accountService->getActorFromUserId(self::FOLLOWER);
		$arrival = $accountService->getActorFromUserId(self::ARRIVAL);
		$followsRequest = Server::get(FollowsRequest::class);

		// the Move arrives on Mastodon's delivery workers, and the inbox here
		// processes it on the request that carried it
		$moved = $this->await(static function () use ($followsRequest, $follower, $arrival): ?bool {
			try {
				$followsRequest->getByPersons($follower->getId(), $arrival->getId());

				return true;
			} catch (\Throwable $e) {
				return null;
			}
		});
		$this->assertTrue($moved, 'the follow of the account that moved was not re-pointed at the arrival');

		$this->expectException(\Throwable::class);
		$followsRequest->getByPersons($follower->getId(), $leaverId);
	}

	public function testTheLeaverIsRecordedAsMoved(): void {
		$host = (string)getenv('MASTODON_HOST');
		$leaver = Server::get(CacheActorService::class)->getFromId('https://' . $host . '/users/' . self::LEAVER);
		$arrival = Server::get(AccountService::class)->getActorFromUserId(self::ARRIVAL);

		$this->assertSame($arrival->getId(), $leaver->getMovedTo());
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
}
