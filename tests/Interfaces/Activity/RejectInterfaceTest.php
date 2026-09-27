<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\Activity\ActivityObjectResolver;
use OCA\Social\Interfaces\Activity\RejectInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Object\Follow;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\NullLogger;

require_once __DIR__ . '/DispatchingActivityTestCase.php';
require_once __DIR__ . '/TResolvesActivityObjects.php';

#[AllowMockObjectsWithoutExpectations]
class RejectInterfaceTest extends DispatchingActivityTestCase {
	use TResolvesActivityObjects;

	private ActivityObjectResolver $objectResolver;

	protected function setUp(): void {
		parent::setUp();

		$this->objectResolver = $this->objectResolverOver();
	}

	protected function createHandler(): IActivityPubInterface {
		return new RejectInterface($this->objectResolver, new NullLogger());
	}

	protected function activityType(): string {
		return Reject::TYPE;
	}

	public function testRejectCarryingTheFollowAsALinkReachesTheFollowInterface(): void {
		$follow = new Follow();
		$follow->setId(self::LOCAL_URL . '/follows/1');
		$follow->setObjectId(self::REMOTE_URL . '/users/bob');
		$this->objectResolver = $this->objectResolverOver([self::LOCAL_URL . '/follows/1' => $follow]);

		$reject = $this->incoming(Reject::TYPE, self::REMOTE_URL . '/rejects/1', self::REMOTE_URL . '/users/bob');
		$reject->setObjectId(self::LOCAL_URL . '/follows/1');

		$this->followInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($reject), $this->identicalTo($follow));

		$this->createHandler()->processIncomingRequest($reject);
	}

	/**
	 * A Reject takes a follow away, and the account it was addressed to is the
	 * only one entitled to send it: the follow interface checks the host of
	 * that account, which on a shared server is every account on it.
	 */
	public function testRejectOfAFollowAddressedToSomebodyElseIsRefused(): void {
		$follow = new Follow();
		$follow->setId(self::LOCAL_URL . '/follows/1');
		$follow->setActorId(self::LOCAL_URL . '/users/alice');
		$follow->setObjectId(self::REMOTE_URL . '/users/bob');
		$this->objectResolver = $this->objectResolverOver([self::LOCAL_URL . '/follows/1' => $follow]);

		$reject = $this->incoming(
			Reject::TYPE, self::REMOTE_URL . '/rejects/1', self::REMOTE_URL . '/users/mallory'
		);
		$reject->setObjectId(self::LOCAL_URL . '/follows/1');

		$this->followInterface->expects($this->never())->method('activity');

		$this->expectException(InvalidOriginException::class);

		$this->createHandler()->processIncomingRequest($reject);
	}
}
