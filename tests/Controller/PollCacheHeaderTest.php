<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\StatusApiController;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The `Cache-Control` a conditional poll needs, all the way to the wire.
 *
 * The home timeline's head and the unread count carry an `ETag` so that a
 * client polling every thirty seconds is answered `304` rather than the page it
 * already holds. That only happens if the browser is *allowed to keep* the
 * previous answer: without a stored copy there is no `If-None-Match` to send
 * and the `304` is never asked for.
 *
 * Nextcloud's default json responder is what took the header away. It rebuilds
 * a returned `DataResponse` into a `JSONResponse` and merges the **fresh**
 * response's headers over the controller's, and every response's defaults carry
 * `Cache-Control: no-cache, no-store, must-revalidate`. The first test here is
 * that behaviour, asserted against the real framework class rather than
 * described in a comment — it is the reason these routes hand back a
 * `JSONResponse` themselves, which the dispatcher passes through untouched.
 */
#[AllowMockObjectsWithoutExpectations]
class PollCacheHeaderTest extends TestCase {
	private const TAG = '"h123-20"';

	private IRequest|MockObject $request;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);

		// `Response::getHeaders()` asks the container for the request id and
		// for who is signed in
		\OC::$server->register(IRequest::class, $this->request);
		\OC::$server->register(IUserSession::class, $this->createStub(IUserSession::class));
	}

	protected function tearDown(): void {
		\OC::$server->reset();
		parent::tearDown();
	}

	/** the real controller, with only the two things these two methods touch */
	private function controller(string $pollTag = ''): StatusApiController {
		$controller = $this->getMockBuilder(StatusApiController::class)
			->disableOriginalConstructor()
			->onlyMethods([])
			->getMock();

		(new ReflectionProperty(Controller::class, 'request'))
			->setValue($controller, $this->request);
		(new ReflectionProperty(StatusApiController::class, 'pollTag'))
			->setValue($controller, $pollTag);

		return $controller;
	}

	private function tagged(StatusApiController $controller, DataResponse $response): JSONResponse {
		return (new ReflectionMethod(StatusApiController::class, 'tagged'))
			->invoke($controller, $response);
	}

	private function notModified(StatusApiController $controller, string $tag): ?JSONResponse {
		return (new ReflectionMethod(StatusApiController::class, 'notModified'))
			->invoke($controller, $tag);
	}

	/**
	 * What a `DataResponse` costs: the framework's own default replaces the
	 * header the controller set. `no-store` means "do not keep this", so a
	 * browser given it never revalidates and the `ETag` beside it is decoration.
	 */
	public function testTheFrameworkOverwritesACacheHeaderOnADataResponse(): void {
		$response = new DataResponse(['count' => 1], Http::STATUS_OK);
		$response->addHeader('Cache-Control', 'private, no-cache');

		// Controller is abstract; what is under test is its responder, which
		// every controller in the app inherits unchanged
		$framework = new class('social', $this->request) extends Controller {
		};

		$rebuilt = $framework->buildResponse($response);

		$this->assertSame(
			'no-cache, no-store, must-revalidate',
			$rebuilt->getHeaders()['Cache-Control'],
			'the responder merges the fresh response\'s defaults over the controller\'s headers'
		);
	}

	/**
	 * So the answer is built as the thing the responder would have built. The
	 * dispatcher only rebuilds a `DataResponse`, so this one reaches the wire
	 * as it is.
	 */
	public function testATaggedAnswerCarriesTheHeaderItSet(): void {
		$controller = $this->controller(self::TAG);

		$response = $this->tagged($controller, new DataResponse(['a'], Http::STATUS_OK));

		$this->assertNotInstanceOf(
			DataResponse::class, $response, 'a DataResponse would be rebuilt, and lose the header'
		);
		$this->assertSame('private, no-cache', $response->getHeaders()['Cache-Control']);
		$this->assertSame(self::TAG, $response->getHeaders()['ETag']);
		$this->assertSame(['a'], $response->getData());
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/** Paging links are the caller's, and survive the change of class. */
	public function testTheCallersOwnHeadersAreCarriedOver(): void {
		$page = new DataResponse(['a'], Http::STATUS_OK);
		$page->addHeader('Link', '<https://cloud.example/next>; rel="next"');

		$response = $this->tagged($this->controller(self::TAG), $page);

		$this->assertSame(
			'<https://cloud.example/next>; rel="next"', $response->getHeaders()['Link']
		);
	}

	/** An untagged answer is left alone: nothing to revalidate against. */
	public function testAnUntaggedAnswerGetsNoCacheHeader(): void {
		$response = $this->tagged($this->controller(), new DataResponse(['a'], Http::STATUS_OK));

		$this->assertSame(
			'no-cache, no-store, must-revalidate', $response->getHeaders()['Cache-Control']
		);
		$this->assertArrayNotHasKey('ETag', $response->getHeaders());
	}

	/** The `304` itself has to carry them too, or the next poll has no tag. */
	public function testTheNotModifiedAnswerCarriesTheTagAndTheHeader(): void {
		$this->request->method('getHeader')->with('If-None-Match')->willReturn(self::TAG);

		$response = $this->notModified($this->controller(), 'h123-20');

		$this->assertNotNull($response);
		$this->assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
		$this->assertSame(self::TAG, $response->getHeaders()['ETag']);
		$this->assertSame('private, no-cache', $response->getHeaders()['Cache-Control']);
	}

	/**
	 * A client that holds a *different* version gets the page, and the tag it
	 * will send back next time is remembered for `tagged()`.
	 */
	public function testAStaleTagIsAnsweredWithThePage(): void {
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('"h1-20"');
		$controller = $this->controller();

		$this->assertNull($this->notModified($controller, 'h123-20'));
		$this->assertSame(
			self::TAG,
			(new ReflectionProperty(StatusApiController::class, 'pollTag'))->getValue($controller)
		);
	}
}
