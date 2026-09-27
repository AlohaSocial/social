<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\Activity\AcceptInterface;
use OCA\Social\Interfaces\Activity\ActivityObjectResolver;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\NullLogger;

require_once __DIR__ . '/DispatchingActivityTestCase.php';
require_once __DIR__ . '/TResolvesActivityObjects.php';

#[AllowMockObjectsWithoutExpectations]
class AcceptInterfaceTest extends DispatchingActivityTestCase {
	use TResolvesActivityObjects;

	private ActivityObjectResolver $objectResolver;

	protected function setUp(): void {
		parent::setUp();

		// by default this instance knows nothing, so a bare object id resolves
		// to nothing and the activity is ignored
		$this->objectResolver = $this->objectResolverOver();
	}

	protected function createHandler(): IActivityPubInterface {
		return new AcceptInterface($this->objectResolver, new NullLogger());
	}

	protected function activityType(): string {
		return Accept::TYPE;
	}

	public function testAcceptOfSomethingElseThanAFollowIsNotAFollowConfirmation(): void {
		$note = new Note();
		$note->setId(self::REMOTE_URL . '/notes/1');
		$accept = $this->incoming(Accept::TYPE, self::REMOTE_URL . '/accepts/1', self::REMOTE_URL . '/users/bob', $note);

		$this->followInterface->expects($this->never())->method('activity');
		$this->noteInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($accept), $this->identicalTo($note));

		$this->createHandler()->processIncomingRequest($accept);
	}

	/**
	 * The case that left a follow pending forever: a peer that sends `object`
	 * as a link rather than an embedded object. Mastodon embeds, so this only
	 * ever bit everyone else.
	 */
	public function testAcceptCarryingTheFollowAsALinkConfirmsTheStoredFollow(): void {
		$follow = new Follow();
		$follow->setId(self::LOCAL_URL . '/follows/1');
		$follow->setActorId(self::LOCAL_URL . '/users/alice');
		$follow->setObjectId(self::REMOTE_URL . '/users/bob');
		$this->objectResolver = $this->objectResolverOver([self::LOCAL_URL . '/follows/1' => $follow]);

		$accept = $this->incoming(Accept::TYPE, self::REMOTE_URL . '/accepts/1', self::REMOTE_URL . '/users/bob');
		$accept->setObjectId(self::LOCAL_URL . '/follows/1');

		$this->followInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($accept), $this->identicalTo($follow));

		$this->createHandler()->processIncomingRequest($accept);
	}

	/**
	 * A follow request is answered by the account it was addressed to. The
	 * follow interface checks the host of that account, which on a shared
	 * server is every account on it — so any of them could confirm a follow
	 * meant for a neighbour and start receiving nothing about it.
	 */
	public function testAcceptOfAFollowAddressedToSomebodyElseIsRefused(): void {
		$follow = new Follow();
		$follow->setId(self::LOCAL_URL . '/follows/1');
		$follow->setActorId(self::LOCAL_URL . '/users/alice');
		$follow->setObjectId(self::REMOTE_URL . '/users/bob');
		$this->objectResolver = $this->objectResolverOver([self::LOCAL_URL . '/follows/1' => $follow]);

		$accept = $this->incoming(
			Accept::TYPE, self::REMOTE_URL . '/accepts/1', self::REMOTE_URL . '/users/mallory'
		);
		$accept->setObjectId(self::LOCAL_URL . '/follows/1');

		$this->followInterface->expects($this->never())->method('activity');

		$this->expectException(InvalidOriginException::class);

		$this->createHandler()->processIncomingRequest($accept);
	}

	/** The resolved object hangs off the activity, so checkOrigin() can work. */
	public function testResolvedObjectIsRootedAtTheActivity(): void {
		$follow = new Follow();
		$follow->setId(self::LOCAL_URL . '/follows/1');
		$follow->setObjectId(self::REMOTE_URL . '/users/bob');
		$this->objectResolver = $this->objectResolverOver([self::LOCAL_URL . '/follows/1' => $follow]);

		$accept = $this->incoming(Accept::TYPE, self::REMOTE_URL . '/accepts/1', self::REMOTE_URL . '/users/bob');
		$accept->setObjectId(self::LOCAL_URL . '/follows/1');

		$this->followInterface->method('activity');
		$this->createHandler()->processIncomingRequest($accept);

		$this->assertSame($accept, $follow->getRoot());
		$this->assertSame(self::REMOTE_HOST, $follow->getRoot()->getOrigin());
	}
}
