<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Middleware;

use OCA\Social\Middleware\AccessBlockedException;
use OCA\Social\Middleware\AccessBlockMiddleware;
use OCA\Social\Service\AccessBlockService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What an IP block at `no_access` actually stops.
 *
 * The enforcement is in a middleware rather than at the two or three routes
 * somebody would remember, because "no access" is a statement about the whole
 * app: a block that held on the inbox but not on the API, or on last month's
 * routes but not on the ones added since, is not what an admin switched on.
 */
#[AllowMockObjectsWithoutExpectations]
class AccessBlockMiddlewareTest extends TestCase {
	private IRequest|Stub $request;
	private AccessBlockService|MockObject $accessBlockService;
	private AccessBlockMiddleware $middleware;

	/** @var string[] the addresses this instance answers nothing from */
	private array $blocked = [];
	private string $remoteAddress = '1.2.3.4';

	protected function setUp(): void {
		$this->request = $this->createStub(IRequest::class);
		$this->accessBlockService = $this->createMock(AccessBlockService::class);

		$this->request->method('getRemoteAddress')->willReturnCallback(
			fn (): string => $this->remoteAddress
		);
		$this->accessBlockService->method('isBlockedIp')->willReturnCallback(
			fn (string $ip): bool => in_array($ip, $this->blocked, true)
		);

		$this->middleware = new AccessBlockMiddleware(
			$this->request, $this->accessBlockService, new NullLogger()
		);
	}

	private function controller(): Controller {
		return $this->createMock(Controller::class);
	}

	public function testARequestFromAnAddressThatIsNotBlockedGoesThrough(): void {
		$this->middleware->beforeController($this->controller(), 'anything');
		$this->addToAssertionCount(1);
	}

	public function testARequestFromABlockedAddressIsStopped(): void {
		$this->blocked = ['1.2.3.4'];

		$this->expectException(AccessBlockedException::class);

		$this->middleware->beforeController($this->controller(), 'anything');
	}

	/** Nothing to check, and nothing to refuse. */
	public function testARequestWithNoRemoteAddressIsNotRefused(): void {
		$this->remoteAddress = '';
		$this->accessBlockService->expects($this->never())->method('isBlockedIp');

		$this->middleware->beforeController($this->controller(), 'anything');
		$this->addToAssertionCount(1);
	}

	/**
	 * 403, not 404 or 429: the address is being refused deliberately and
	 * permanently, and saying so is what makes a peer stop redelivering rather
	 * than queue for days.
	 */
	public function testTheRefusalIsAForbidden(): void {
		$response = $this->middleware->afterException(
			$this->controller(), 'anything', new AccessBlockedException()
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('address', $response->getData()['error']);
	}

	/** Every other failure is somebody else's to answer. */
	public function testAnyOtherFailureIsPassedOn(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('something else');

		$this->middleware->afterException(
			$this->controller(), 'anything', new \RuntimeException('something else')
		);
	}
}
