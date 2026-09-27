<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools\Traits;

use OCA\Social\Tools\Model\SimpleDataStore;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TNCDataResponseTest extends TestCase {
	/** Exposes the protected trait methods. */
	private object $controller;

	protected function setUp(): void {
		$this->controller = new class {
			use TNCDataResponse;

			public function __call(string $name, array $args) {
				return $this->$name(...$args);
			}
		};
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testFailReportsTheExceptionAndLogsAWarning(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->callback(fn (string $message): bool => str_starts_with($message, '500 - ')
				&& str_contains($message, 'RuntimeException')
				&& str_contains($message, 'boom')));
		\OC::$server->register(LoggerInterface::class, $logger);

		$response = $this->controller->fail(new \RuntimeException('boom'), ['context' => 'x']);

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		// the details go to the log only; the response stays generic
		$this->assertSame(
			['context' => 'x', 'status' => -1, 'error' => 'request failed'],
			$response->getData()
		);
	}

	public function testFailCanUseAnotherStatusAndStaySilent(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		\OC::$server->register(LoggerInterface::class, $logger);

		$response = $this->controller->fail(new \InvalidArgumentException('bad'), [], Http::STATUS_BAD_REQUEST, false);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayNotHasKey('exception', $response->getData());
	}

	public function testSuccessWrapsTheResult(): void {
		$response = $this->controller->success(['id' => 1], ['extra' => true]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['extra' => true, 'result' => ['id' => 1], 'status' => 1], $response->getData());
		$this->assertSame(['result' => [], 'status' => 1], $this->controller->success()->getData());
	}

	public function testActivityPubSuccessSetsTheJsonLdContentType(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getId')->willReturn('req-1');
		\OC::$server->register(IRequest::class, $request);
		$store = new SimpleDataStore(['type' => 'Note']);

		$response = $this->controller->activityPubSuccess($store);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($store, $response->getData());
		$this->assertSame(
			'application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
			$response->getHeaders()['Content-Type']
		);
	}
}
