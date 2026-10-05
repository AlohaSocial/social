<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\MigrationApiController;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\MigrationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class MigrationApiControllerTest extends TestCase {
	private IRequest|Stub $request;
	private ClientService|Stub $clientService;
	private MigrationService|MockObject $migrationService;
	/** @var array<string, string> */
	private array $headers = [];
	private bool $csrf = false;

	protected function setUp(): void {
		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getParam')->willReturn('');
		$this->clientService = $this->createStub(ClientService::class);
		$this->migrationService = $this->createMock(MigrationService::class);
	}

	private function controller(): MigrationApiController {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createStub(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$alice = new Person();
		$alice->setId('https://cloud.example/index.php/apps/social/@alice')->setPreferredUsername('alice')->setUserId('alice');
		$accountService = $this->createStub(AccountService::class);
		$accountService->method('getActorFromUserId')->willReturn($alice);

		return new MigrationApiController(
			$this->request, $userSession, new NullLogger(), $accountService, $this->clientService, $this->migrationService
		);
	}

	/** @param string[] $scopes */
	private function token(array $scopes): void {
		$this->headers = ['Authorization' => 'Bearer tok'];
		$client = new SocialClient();
		$client->setAuthUserId('alice');
		$client->setAuthScopes($scopes);
		$this->clientService->method('getFromToken')->willReturn($client);
	}

	private function moved(): Person {
		$target = new Person();
		$target->setId('https://new.example/index.php/apps/social/@alice')->setAccount('alice@new.example');

		return $target;
	}

	public function testATokenGrantedTheMigrationScopeMovesTheAccount(): void {
		$this->token(['write:migration']);
		$this->migrationService->expects($this->once())->method('move')->with('alice', '@alice@new.example')->willReturn($this->moved());
		$this->migrationService->method('moveStatus')->willReturn(['moved_to' => 'https://new.example/index.php/apps/social/@alice', 'moved_at' => 1, 'can_move_at' => 2]);

		$response = $this->controller()->moveAuthorized('@alice@new.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('https://new.example/index.php/apps/social/@alice', $response->getData()['moved_to']);
		$this->assertSame('alice@new.example', $response->getData()['target']['acct']);
	}

	/** Every phone app holds `write`; none of them may move the account away. */
	public function testTheBroadWriteScopeIsNotEnough(): void {
		$this->token(['read', 'write', 'follow']);
		$this->migrationService->expects($this->never())->method('move');

		$response = $this->controller()->moveAuthorized('@alice@new.example');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('write:migration', $response->getData()['error']);
	}

	/** The button has a password step; this route has a token or nothing. */
	public function testASessionWithoutATokenIsRefused(): void {
		$this->csrf = true;
		$this->migrationService->expects($this->never())->method('move');

		$response = $this->controller()->moveAuthorized('@alice@new.example');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testNoTokenAtAllIsUnauthorized(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->moveAuthorized('@alice@new.example')->getStatus());
	}

	public function testWhatTheServiceRefusesIsAnsweredWithItsReason(): void {
		$this->token(['write:migration']);
		$this->migrationService->method('move')->willThrowException(new InvalidResourceException('this account moved on 2026-10-01 and can move again on 2026-10-31'));

		$response = $this->controller()->moveAuthorized('@alice@new.example');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('can move again', $response->getData()['error']);
	}
}
