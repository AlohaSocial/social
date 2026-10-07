<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\FileCommentsController;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FileCommentsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The switch for replies shown as comments in Files: on unless turned off,
 * and a request that is not a yes or a no leaves it as it was.
 */
#[AllowMockObjectsWithoutExpectations]
class FileCommentsControllerTest extends TestCase {
	private IRequest|Stub $request;
	private IUserSession|Stub $userSession;
	private AccountService|Stub $accountService;
	private FileCommentsService|Stub $service;
	private bool $csrf = true;
	/** @var array<string, bool> user id => the switch as stored */
	private array $enabled = [];

	protected function setUp(): void {
		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')->willReturn('');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getRequestUri')->willReturn('/index.php/apps/social/api/v1/social/files_comments');

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

		$this->service = $this->createStub(FileCommentsService::class);
		$this->service->method('export')->willReturnCallback(fn (string $uid): array => ['enabled' => $this->enabled[$uid] ?? true]);
		$this->service->method('setEnabled')->willReturnCallback(function (string $uid, bool $enabled): void {
			$this->enabled[$uid] = $enabled;
		});

		\OC::$server->register(IRequest::class, $this->request);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testTheSwitchIsOnForAnAccountThatNeverTouchedIt(): void {
		$response = $this->controller()->filesComments();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['enabled' => true], $response->getData());
	}

	public function testTurningItOffIsStoredAndAnswered(): void {
		$this->assertSame(['enabled' => false], $this->controller()->filesCommentsUpdate('false')->getData());
		$this->assertSame(['enabled' => false], $this->controller()->filesComments()->getData());
		$this->assertSame(['enabled' => true], $this->controller()->filesCommentsUpdate(true)->getData());
	}

	public function testWhatIsNotABooleanIsRefused(): void {
		foreach ([null, '', 'maybe'] as $raw) {
			$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller()->filesCommentsUpdate($raw)->getStatus(), var_export($raw, true));
		}
		$this->assertSame([], $this->enabled, 'a refused request must not flip the switch');
	}

	public function testTheSwitchNeedsAViewer(): void {
		$this->csrf = false;

		foreach ([$this->controller()->filesComments(), $this->controller()->filesCommentsUpdate('false')] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		}
	}

	private function controller(): FileCommentsController {
		return new FileCommentsController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->createStub(ClientService::class),
			$this->service,
		);
	}
}
