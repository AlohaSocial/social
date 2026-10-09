<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Identity\CustomHandleService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PresenceService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Move\BridgyTwin;
use OCA\Social\Atproto\Move\InboundMoveService;
use OCA\Social\Atproto\Move\MoveAwayService;
use OCA\Social\Atproto\Move\MoveInService;
use OCA\Social\Atproto\OAuth\AuthorizationServer;
use OCA\Social\Atproto\Publisher\BlueskyBlocks;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Controller\AtprotoAccountController;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A new handle is a new profile on Bluesky: it is published again, and a
 * verification of the account with it, as one naming the old handle no
 * longer counts.
 */
#[AllowMockObjectsWithoutExpectations]
class AtprotoAccountHandleTest extends TestCase {
	/** @var AccountService&MockObject */
	private AccountService $accounts;
	/** @var CustomHandleService&MockObject */
	private CustomHandleService $handles;
	private Person $alice;

	private function controller(): AtprotoAccountController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$this->alice = (new Person())->setId('https://social.test/@alice');
		$this->accounts = $this->createMock(AccountService::class);
		$this->accounts->method('getActorFromUserId')->willReturn($this->alice);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturn(self::identity('alice.social.test', ''));
		$this->handles = $this->createMock(CustomHandleService::class);

		return new AtprotoAccountController(
			$this->createMock(IRequest::class),
			$session,
			$config,
			$this->accounts,
			$identities,
			$this->createMock(Publisher::class),
			$this->createMock(LabelerService::class),
			$this->createMock(AppPasswordService::class),
			$this->createMock(AuthorizationServer::class),
			$this->handles,
			$this->createMock(MoveAwayService::class),
			$this->createMock(MoveInService::class),
			$this->createMock(InboundMoveService::class),
			$this->createMock(BridgyTwin::class),
			$this->createMock(BlueskyBlocks::class),
			$this->createMock(PresenceService::class),
		);
	}

	private static function identity(string $handle, string $custom): Identity {
		return new Identity(1, 'https://social.test/@alice', 'did:plc:alice', $handle, 'sealed', 'did:key:z', '', Identity::STATE_ACTIVE, '', 0, 'alice.social.test', $custom);
	}

	public function testANewHandlePublishesTheProfileAgain(): void {
		$controller = $this->controller();
		$this->handles->method('set')->willReturn(self::identity('alice.example.com', 'alice.example.com'));
		$this->accounts->expects($this->once())->method('queueBlueskyProfile')->with($this->identicalTo($this->alice));

		$response = $controller->setHandle('alice.example.com');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('alice.example.com', $response->getData()['handle']);
	}

	public function testTheAssignedHandleAgainPublishesTheProfileAgain(): void {
		$controller = $this->controller();
		$this->handles->method('clear')->willReturn(self::identity('alice.social.test', ''));
		$this->accounts->expects($this->once())->method('queueBlueskyProfile')->with($this->identicalTo($this->alice));

		$this->assertSame(Http::STATUS_OK, $controller->clearHandle()->getStatus());
	}

	public function testARefusedHandlePublishesNothing(): void {
		$controller = $this->controller();
		$this->handles->method('set')->willThrowException(new \InvalidArgumentException('That handle is another account\'s here'));
		$this->accounts->expects($this->never())->method('queueBlueskyProfile');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->setHandle('bob.example.com')->getStatus());
	}
}
