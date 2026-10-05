<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\RequestQueueService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * An account here moves to a Mastodon account, and Mastodon's follower of
 * it follows the new account instead.
 *
 * The one half of a move this app sends. The workflow made the Mastodon
 * account `landing` list our `mover` in its `alsoKnownAs`, which is what
 * `MigrationService::move()` checks and what Mastodon's `Move` handler
 * checks again on arrival. Mastodon's `interop` account follows `mover`
 * first — a `Move` tells followers, so without one there is nothing to
 * observe — and what is asserted is Mastodon's own answer about who
 * `interop` follows afterwards.
 *
 * Its own local user, because a moved account refuses to post, and the
 * delivery suite posts as the first user.
 */
class MoveOutboundTest extends TestCase {
	private const USER = 'mover';
	private const LANDING = 'landing';

	private Mastodon $mastodon;
	private Person $actor;

	protected function setUp(): void {
		$mastodon = Mastodon::fromEnvironment();
		if ($mastodon === null) {
			$this->markTestSkipped('no Mastodon to talk to: set MASTODON_BASE_URL and MASTODON_TOKEN');
		}
		$this->mastodon = $mastodon;
		$this->actor = Server::get(AccountService::class)->getActorFromUserId(self::USER, true);
	}

	public function testMastodonsFollowerFollowsTheNewAccountAfterTheMove(): void {
		$cloudHost = Server::get(\OCA\Social\Service\ConfigService::class)->getCloudAuthority();
		$oldId = $this->mastodon->resolveAccount('@' . $this->actor->getPreferredUsername() . '@' . $cloudHost);
		$this->mastodon->follow($oldId);

		$followsRequest = Server::get(FollowsRequest::class);
		$this->assertTrue(
			$this->mastodon->await(fn (): ?bool => ($followsRequest->countFollowers($this->actor->getId()) > 0) ? true : null),
			'Mastodon never managed to follow the account that is about to move'
		);
		$this->drainQueue();

		$landingId = $this->mastodon->accountId(self::LANDING);
		$this->assertFalse($this->mastodon->relationship($landingId)['following'], 'nobody follows the landing account yet');

		$target = Server::get(MigrationService::class)->move(self::USER, 'https://' . (string)getenv('MASTODON_HOST') . '/users/' . self::LANDING);
		$this->drainQueue();

		$this->assertSame(self::LANDING . '@' . (string)getenv('MASTODON_HOST'), $target->getAccount());

		// Mastodon processes the Move on its workers: the follower is
		// unfollowed from the old account and follows the new one
		$this->assertTrue(
			$this->mastodon->await(fn (): ?bool => $this->mastodon->relationship($landingId)['following'] ? true : null, 90),
			'Mastodon did not re-point its follower at the new account'
		);
		$this->assertFalse($this->mastodon->relationship($oldId)['following'], 'the old account is still followed');
	}

	/** Sends whatever is waiting, here and now; cron is not a thing to wait for. */
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
