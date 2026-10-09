<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\DirectMessageSettingsController;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\NotificationPolicyService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Who may send the viewer direct messages: read and changed, and anything but
 * the three answers refused without a change.
 */
#[AllowMockObjectsWithoutExpectations]
class DirectMessageSettingsControllerTest extends TestCase {
	private IRequest|Stub $request;
	private IUserSession|Stub $userSession;
	private AccountService|Stub $accountService;
	private NotificationPolicyService|Stub $service;
	private bool $csrf = true;
	private string $from = 'following';

	protected function setUp(): void {
		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')->willReturn('');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getRequestUri')->willReturn('/index.php/apps/social/api/v1/social/direct_messages');

		$this->userSession = $this->createStub(IUserSession::class);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->accountService = $this->createStub(AccountService::class);
		$this->accountService->method('getActorFromUserId')->willReturnCallback(function (): Person {
			$viewer = new Person();
			$viewer->setId('https://cloud.example/users/alice');
			$viewer->setUserId('alice');

			return $viewer;
		});

		$this->service = $this->createStub(NotificationPolicyService::class);
		$this->service->method('directMessagesFrom')->willReturnCallback(fn (): string => $this->from);
		$this->service->method('saveDirectMessagesFrom')->willReturnCallback(function (string $uid, string $from): string {
			if (!in_array($from, NotificationPolicyService::DIRECT_FROM, true)) {
				throw new InvalidResourceException('from must be all, following or none');
			}
			$this->from = $from;

			return $from;
		});

		\OC::$server->register(IRequest::class, $this->request);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testTheSettingIsRead(): void {
		$response = $this->controller()->directMessages();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['from' => 'following'], $response->getData());
	}

	public function testAChangeIsStoredAndAnswered(): void {
		$this->assertSame(['from' => 'none'], $this->controller()->directMessagesUpdate('none')->getData());
		$this->assertSame(['from' => 'none'], $this->controller()->directMessages()->getData());
	}

	public function testAnythingButTheThreeAnswersIsRefused(): void {
		foreach ([null, '', 'friends', ['all']] as $raw) {
			$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller()->directMessagesUpdate($raw)->getStatus(), var_export($raw, true));
		}
		$this->assertSame('following', $this->from);
	}

	public function testTheSettingNeedsAViewer(): void {
		$this->csrf = false;

		foreach ([$this->controller()->directMessages(), $this->controller()->directMessagesUpdate('all')] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		}
		$this->assertSame('following', $this->from);
	}

	private function controller(): DirectMessageSettingsController {
		return new DirectMessageSettingsController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->createStub(ClientService::class),
			$this->service,
		);
	}
}
