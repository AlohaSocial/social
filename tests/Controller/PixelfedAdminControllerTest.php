<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\PixelfedAdminController;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AdminApiService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\PixelfedAdminService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PixelfedAdminControllerTest extends TestCase {
	private const ADMIN = 'root';

	private IRequest|Stub $request;
	private IUserSession|Stub $userSession;
	private ClientService|Stub $clientService;
	private AdminApiService|Stub $adminApiService;
	private PixelfedAdminService|MockObject $pixelfedAdminService;
	private array $headers = [];
	private bool $csrf = true;
	private bool $signedIn = true;
	private bool $isAdmin = true;
	private array $scopes = ['admin:read', 'admin:write'];

	protected function setUp(): void {
		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getParam')->willReturn('');

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn(self::ADMIN);
		$this->userSession = $this->createStub(IUserSession::class);
		$this->userSession->method('getUser')
			->willReturnCallback(fn (): ?IUser => $this->signedIn ? $user : null);

		$this->clientService = $this->createStub(ClientService::class);
		$this->clientService->method('getFromToken')
			->willReturnCallback(function (string $token): SocialClient {
				if ($token !== 'token') {
					throw new ClientNotFoundException('unknown token');
				}
				$client = new SocialClient();
				$client->setAuthUserId(self::ADMIN);
				$client->setAuthScopes($this->scopes);

				return $client;
			});

		\OC::$server->register(IRequest::class, $this->request);

		$this->adminApiService = $this->createStub(AdminApiService::class);
		$this->adminApiService->method('isAdministrator')
			->willReturnCallback(fn (string $userId): bool => $this->isAdmin && $userId === self::ADMIN);
		$this->pixelfedAdminService = $this->createMock(PixelfedAdminService::class);
	}

	private function controller(): PixelfedAdminController {
		return new PixelfedAdminController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->adminApiService,
			$this->clientService,
			$this->pixelfedAdminService,
		);
	}

	public static function routes(): array {
		return [
			'stats' => ['stats', []],
			'config' => ['config', []],
			'configUpdate' => ['configUpdate', ['pixelfed.open_registration']],
			'users' => ['users', ['', 'desc']],
			'user' => ['user', ['7']],
			'userAction' => ['userAction', ['7', 'delete']],
			'modReports' => ['modReports', []],
			'modReportHandle' => ['modReportHandle', [4, 'ignore']],
			'autospam' => ['autospam', []],
			'autospamHandle' => ['autospamHandle', []],
			'instances' => ['instances', []],
			'instance' => ['instance', ['evil.example']],
			'instanceModerate' => ['instanceModerate', ['evil.example', 'banned', '1']],
		];
	}

	private function call(string $method, array $arguments): DataResponse {
		return $this->controller()->$method(...$arguments);
	}

	/** Every route stands behind the same gate as Mastodon's admin API. */
	#[DataProvider('routes')]
	public function testEveryRouteRefusesANonAdministrator(string $method, array $arguments): void {
		$this->isAdmin = false;

		$response = $this->call($method, $arguments);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), $method);
		$this->assertArrayHasKey('error', $response->getData());
	}

	#[DataProvider('routes')]
	public function testEveryRouteRefusesAnAnonymousCaller(string $method, array $arguments): void {
		$this->signedIn = false;

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->call($method, $arguments)->getStatus(), $method);
	}

	/** A bearer token without the write scope reads and does not write. */
	public function testAReadOnlyTokenCannotModerate(): void {
		$this->headers = ['Authorization' => 'Bearer token'];
		$this->scopes = ['admin:read'];
		$this->pixelfedAdminService->method('stats')->willReturn(['users_count' => 1]);
		$this->pixelfedAdminService->expects($this->never())->method('userAction');

		$this->assertSame(Http::STATUS_OK, $this->controller()->stats()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->userAction('7', 'delete')->getStatus());
	}

	public function testTheModeratorHandlingAReportIsTheOneSignedIn(): void {
		$this->pixelfedAdminService->expects($this->once())
			->method('handleModReport')
			->with(4, 'ignore', self::ADMIN)
			->willReturn(['success' => true]);

		$this->assertSame(['success' => true], $this->controller()->modReportHandle(4, 'ignore')->getData());
	}

	/** What this instance does not have is a 422 that says so, never a 200 that did nothing. */
	public function testWhatThisInstanceDoesNotHaveIsRefusedWithAReason(): void {
		$this->pixelfedAdminService->method('updateConfig')
			->willThrowException(new \InvalidArgumentException('configured in Administration settings'));
		$this->pixelfedAdminService->method('handleAutospam')
			->willThrowException(new \InvalidArgumentException('there is no automatic spam detection'));

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller()->configUpdate('x')->getStatus());
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller()->autospamHandle()->getStatus());
	}

	public function testTheInstanceSwitchIsReadAsABoolean(): void {
		$this->pixelfedAdminService->expects($this->once())
			->method('moderateInstance')
			->with('evil.example', 'banned', true)
			->willReturn(['data' => ['banned' => true]]);

		$this->controller()->instanceModerate('evil.example', 'banned', 'true');
	}
}
