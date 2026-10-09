<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use InvalidArgumentException;
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

/** The switch for a person's own presence on Bluesky, and the identity the settings show. */
#[AllowMockObjectsWithoutExpectations]
class AtprotoAccountControllerTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private PresenceService&MockObject $presence;
	private Publisher&MockObject $publisher;
	private bool $enabled = true;

	protected function setUp(): void {
		$this->presence = $this->createMock(PresenceService::class);
		$this->publisher = $this->createMock(Publisher::class);
	}

	private function controller(): AtprotoAccountController {
		$session = $this->createStub(IUserSession::class);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($user);
		$config = $this->createStub(AtprotoConfig::class);
		$config->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$accounts = $this->createStub(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn((new Person())->setUserId('alice'));

		return new AtprotoAccountController(
			$this->createStub(IRequest::class), $session, $config, $accounts, $this->createStub(IdentityService::class), $this->publisher,
			$this->createStub(LabelerService::class), $this->createStub(AppPasswordService::class), $this->createStub(AuthorizationServer::class),
			$this->createStub(CustomHandleService::class), $this->createStub(MoveAwayService::class), $this->createStub(MoveInService::class),
			$this->createStub(InboundMoveService::class), $this->createStub(BridgyTwin::class), $this->createStub(BlueskyBlocks::class), $this->presence,
		);
	}

	private static function identity(string $state): Identity {
		return new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', '', '', $state, '', 0);
	}

	public function testSwitchingOffAnswersTheIdentityAsOff(): void {
		$this->presence->expects($this->once())->method('switchOff')->willReturn(self::identity(Identity::STATE_DEACTIVATED));
		$this->presence->expects($this->never())->method('switchOn');
		$this->publisher->expects($this->never())->method('publishProfile');

		$data = $this->controller()->state(false)->getData();

		$this->assertSame(['deactivated', false, self::DID, 'alice.social.test'], [$data['state'], $data['active'], $data['did'], $data['handle']]);
	}

	public function testSwitchingOnAnswersTheIdentityAsOnWithTheProfileAsItIsNow(): void {
		$this->presence->expects($this->once())->method('switchOn')->willReturn(self::identity(Identity::STATE_ACTIVE));
		$this->publisher->expects($this->once())->method('publishProfile');

		$data = $this->controller()->state(true)->getData();

		$this->assertSame(['active', true], [$data['state'], $data['active']]);
	}

	public function testNothingToSwitchIsNotFound(): void {
		$this->presence->method('switchOff')->willThrowException(new InvalidArgumentException('This account has no Bluesky identity'));
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->state(false)->getStatus());

		$this->enabled = false;
		$this->presence->expects($this->never())->method('switchOn');
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->state(true)->getStatus());
	}

	/** The settings read `active` to draw the switch. */
	public function testTheExportSaysWhetherTheIdentityIsOn(): void {
		$this->assertTrue(AtprotoAccountController::export(self::identity(Identity::STATE_ACTIVE))['active']);
		$this->assertFalse(AtprotoAccountController::export(self::identity(Identity::STATE_DEACTIVATED))['active']);
	}
}
