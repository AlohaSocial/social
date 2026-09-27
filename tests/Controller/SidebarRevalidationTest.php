<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\ApiController;
use OCA\Social\Controller\InstanceApiController;
use OCA\Social\Controller\ListController;
use OCA\Social\Controller\Revalidation;
use OCA\Social\Controller\TagController;
use OCA\Social\Service\EmojiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * The routes the sidebar asks for on every page load answer a conditional GET.
 *
 * Emoji, trends, the instance, lists and followed tags were `DataResponse`s,
 * which the framework sends with `no-store`: the browser kept nothing and
 * fetched every one of them again on every page. They are tagged by what they
 * say now, and a caller that already holds the answer is sent `304`.
 */
class SidebarRevalidationTest extends TestCase {
	private IRequest|MockObject $request;
	private string $ifNoneMatch = '';

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $name === 'If-None-Match' ? $this->ifNoneMatch : '');

		\OC::$server->register(IRequest::class, $this->request);
		\OC::$server->register(IUserSession::class, $this->createMock(IUserSession::class));
	}

	protected function tearDown(): void {
		\OC::$server->reset();
		parent::tearDown();
	}

	public function testAFirstAnswerCarriesATagTheBrowserMayKeep(): void {
		$response = Revalidation::byContent($this->request, new DataResponse(['a' => 1], Http::STATUS_OK));

		$this->assertNotInstanceOf(DataResponse::class, $response, 'a DataResponse is rebuilt with no-store');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['a' => 1], $response->getData());
		$this->assertMatchesRegularExpression('/^"[0-9a-f]{40}"$/', $response->getHeaders()['ETag']);
		$this->assertSame('private, no-cache', $response->getHeaders()['Cache-Control']);
	}

	public function testTheSameAnswerAgainIsNotModified(): void {
		$first = Revalidation::byContent($this->request, new DataResponse(['a' => 1], Http::STATUS_OK));
		$this->ifNoneMatch = $first->getHeaders()['ETag'];

		$second = Revalidation::byContent($this->request, new DataResponse(['a' => 1], Http::STATUS_OK));

		$this->assertSame(Http::STATUS_NOT_MODIFIED, $second->getStatus());
		$this->assertSame($first->getHeaders()['ETag'], $second->getHeaders()['ETag']);
	}

	public function testAChangedAnswerIsSentInFull(): void {
		$this->ifNoneMatch = Revalidation::byContent($this->request, new DataResponse(['a' => 1], Http::STATUS_OK))
			->getHeaders()['ETag'];

		$changed = Revalidation::byContent($this->request, new DataResponse(['a' => 2], Http::STATUS_OK));

		$this->assertSame(Http::STATUS_OK, $changed->getStatus());
		$this->assertNotSame($this->ifNoneMatch, $changed->getHeaders()['ETag']);
	}

	/** Two pages with the same rows and different links are different answers. */
	public function testThePagingLinkIsPartOfTheTagAndSurvives(): void {
		$page = new DataResponse(['a'], Http::STATUS_OK);
		$page->addHeader('Link', '<https://cloud.example/next>; rel="next"');
		$bare = Revalidation::byContent($this->request, new DataResponse(['a'], Http::STATUS_OK));

		$linked = Revalidation::byContent($this->request, $page);

		$this->assertSame('<https://cloud.example/next>; rel="next"', $linked->getHeaders()['Link']);
		$this->assertNotSame($bare->getHeaders()['ETag'], $linked->getHeaders()['ETag']);
	}

	public function testAnErrorIsNotTagged(): void {
		$response = Revalidation::byContent($this->request, new DataResponse(['error' => 'x'], Http::STATUS_BAD_REQUEST));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayNotHasKey('ETag', $response->getHeaders());
	}

	public function testTheEmojiRouteAnswersAConditionalGet(): void {
		$emoji = $this->createMock(EmojiService::class);
		$emoji->method('visible')->willReturn([['shortcode' => 'blobcat']]);
		$controller = $this->getMockBuilder(InstanceApiController::class)
			->disableOriginalConstructor()
			->onlyMethods([])
			->getMock();
		(new ReflectionProperty(Controller::class, 'request'))->setValue($controller, $this->request);
		(new ReflectionProperty(InstanceApiController::class, 'emojiService'))->setValue($controller, $emoji);

		$first = $controller->customEmojis();
		$this->ifNoneMatch = $first->getHeaders()['ETag'];

		$this->assertSame(Http::STATUS_NOT_MODIFIED, $controller->customEmojis()->getStatus());
	}

	/** @return iterable<string, array{class-string, string}> */
	public static function routes(): iterable {
		yield 'custom_emojis' => [InstanceApiController::class, 'customEmojis'];
		yield 'trends/tags' => [ApiController::class, 'trendTags'];
		yield 'instance' => [InstanceApiController::class, 'instance'];
		yield 'v2 instance' => [InstanceApiController::class, 'instanceV2'];
		yield 'lists' => [ListController::class, 'index'];
		yield 'followed_tags' => [TagController::class, 'followedTags'];
	}

	/**
	 * A route declared to return a `DataResponse` cannot carry the tag: the
	 * responder rebuilds it with `no-store`.
	 */
	#[DataProvider('routes')]
	public function testThePolledRouteDoesNotDeclareADataResponse(string $class, string $method): void {
		$type = (new ReflectionMethod($class, $method))->getReturnType();

		$this->assertInstanceOf(ReflectionNamedType::class, $type);
		$this->assertNotSame(DataResponse::class, $type->getName());
		$this->assertContains($type->getName(), [JSONResponse::class, Http\Response::class]);
	}
}
