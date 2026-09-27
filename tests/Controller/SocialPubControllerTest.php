<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\NavigationController;
use OCA\Social\Controller\SocialPubController;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IInitialStateService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SocialPubControllerTest extends TestCase {
	private const SOCIAL_URL = 'https://cloud.example/apps/social/';

	/** @var IInitialState&Stub */
	private $initialState;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var AccountService&Stub */
	private $accountService;
	/** @var StreamService&MockObject */
	private $streamService;
	/** @var NavigationController&MockObject */
	private $navigationController;
	/** the page navigate() answers with, so a test can tell it apart */
	private TemplateResponse $app;
	private array $states = [];

	protected function setUp(): void {
		$this->initialState = $this->createStub(IInitialState::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->accountService = $this->createStub(AccountService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->navigationController = $this->createMock(NavigationController::class);
		$this->app = new TemplateResponse('social', 'main');
		$this->navigationController->method('navigate')->willReturn($this->app);

		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $data): void {
				$this->states[$key] = $data;
			});

		// PublicTemplateResponse registers the public page menu with the
		// server's own initial state on construction
		\OC::$server->register(IInitialStateService::class, $this->createStub(IInitialStateService::class));
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function controller(?string $userId): SocialPubController {
		$configService = $this->createStub(ConfigService::class);
		$configService->method('getSocialUrl')->willReturn(self::SOCIAL_URL);
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new SocialPubController(
			$userId,
			$this->initialState,
			$this->createStub(IRequest::class),
			$l10n,
			$this->navigationController,
			$this->cacheActorService,
			$this->accountService,
			$this->streamService,
			$configService
		);
	}

	private function knownActor(string $name = 'Alice'): void {
		$actor = $this->createStub(Person::class);
		$actor->method('getName')->willReturn($name);
		$actor->method('getPreferredUsername')->willReturn('alice');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
	}

	private function unknownActor(): void {
		$this->cacheActorService->method('getFromAccount')
			->willThrowException(new CacheActorDoesNotExistException());
	}

	/**
	 * What a bare local name that nobody holds actually throws.
	 *
	 * `getFromAccount()` tries `getFromLocalAccount()` first, and that reaches
	 * `getFromUsername()`, which throws this one — the cache exception only
	 * comes back for a name that got as far as the remote cache. A mistyped
	 * local handle is the common case, and it is this exception.
	 */
	private function unknownLocalActor(): void {
		$this->cacheActorService->method('getFromAccount')
			->willThrowException(new ActorDoesNotExistException('Actor not found'));
	}

	private function anonymous(): void {
		$this->accountService->method('getCurrentViewer')->willThrowException(new AccountDoesNotExistException());
	}

	/** @return Stream&MockObject */
	private function knownPost(string $token = 'abc'): Stream {
		$stream = $this->createMock(Stream::class);
		$this->streamService->method('getStreamById')->with(self::SOCIAL_URL . '@alice/' . $token, true)->willReturn($stream);

		return $stream;
	}

	private function assertNotFoundGuestPage(Response $response, string $title): void {
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('notfound', $response->getTemplateName());
		$this->assertSame(TemplateResponse::RENDER_AS_GUEST, $response->getRenderAs());
		$this->assertSame($title, $response->getParams()['title']);
		$this->assertNotSame('', $response->getParams()['message']);
	}

	// the profile pages: actor(), followers(), following()

	/** @return iterable<string, array{string}> */
	public static function publicPages(): iterable {
		yield 'actor' => ['actor'];
		yield 'followers' => ['followers'];
		yield 'following' => ['following'];
		yield 'portfolio' => ['portfolio'];
	}

	#[DataProvider('publicPages')]
	public function testAVisitorGetsThePublicPageOfAKnownAccount(string $page): void {
		$this->knownActor();

		$response = $this->controller(null)->$page('alice');

		$this->assertInstanceOf(PublicTemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('main', $response->getTemplateName());
		$this->assertSame('Alice - Social', $response->getParams()['application']);
		$this->assertSame(['public' => true], $this->states['serverData']);
	}

	public function testThePublicPageFallsBackToTheHandleWhenTheAccountHasNoName(): void {
		$this->knownActor('');

		$response = $this->controller(null)->actor('alice');

		$this->assertSame('alice - Social', $response->getParams()['application']);
	}

	/**
	 * The public page was served to everybody: a reader who was logged in
	 * followed a link to a profile and landed on a page with no navigation, a
	 * "Get your own free account" banner and a Follow button that started the
	 * remote-follow flow for an account one click would have followed.
	 */
	#[DataProvider('publicPages')]
	public function testALoggedInReaderGetsTheAppInsteadOfThePublicPage(string $page): void {
		$this->knownActor();
		$this->navigationController->expects($this->once())->method('navigate');

		$response = $this->controller('alice')->$page('bob');

		$this->assertSame($this->app, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayNotHasKey('serverData', $this->states, 'navigate() provides the state of the app');
	}

	/**
	 * An unknown name used to be a 500: the lookup went on to webfinger the
	 * name, a name without a host is not an account to resolve, and the
	 * exception saying so was reported as a server fault.
	 */
	#[DataProvider('publicPages')]
	public function testAnUnknownAccountIsANotFoundPageForAVisitor(string $page): void {
		$this->unknownActor();

		$response = $this->controller(null)->$page('ghost');

		$this->assertNotFoundGuestPage($response, 'Account not found');
		$this->assertStringContainsString('ghost', $response->getParams()['message']);
	}

	public function testAnUnknownAccountIsTheAppWithA404ForALoggedInReader(): void {
		$this->unknownActor();

		$response = $this->controller('alice')->actor('ghost');

		// the app's own profile view says "User not found" once it has asked;
		// the status code tells the browser the same thing
		$this->assertSame($this->app, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testThePageDoesNotGoAndFetchAnAccountItHasNeverSeen(): void {
		$this->cacheActorService->expects($this->once())->method('getFromAccount')
			->with('bob@remote.tld', false)
			->willThrowException(new CacheActorDoesNotExistException());

		$this->controller(null)->actor('bob@remote.tld');
	}

	public function testAnUnknownRemoteProfileLoadsThePublicAppForTheClientLookup(): void {
		$this->unknownActor();

		$response = $this->controller(null)->actor('nextcloud@mastodon.xyz');

		$this->assertInstanceOf(PublicTemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('main', $response->getTemplateName());
		$this->assertSame('nextcloud@mastodon.xyz - Social', $response->getParams()['application']);
		$this->assertSame(['public' => true], $this->states['serverData']);
	}

	/**
	 * And so does a reader with an account.
	 *
	 * A guest opening a handle this instance has never cached got the page and
	 * somebody signed in got a 404 for the same one, which is the wrong way
	 * round twice over: the reader with an account is the one who can follow
	 * what they find there. The client resolves the handle either way.
	 */
	public function testAnUnknownRemoteProfileLoadsTheAppForALoggedInReaderToo(): void {
		$this->unknownActor();

		$response = $this->controller('alice')->actor('nextcloud@mastodon.xyz');

		$this->assertSame($this->app, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * A fediverse handle has a host with a dot in it and no port: WebFinger is
	 * asked of the host. Accepting a port made this route a way for anybody at
	 * all to have the server render a page for `someone@10.0.0.5:8080`, one
	 * host and port at a time.
	 *
	 * @param string $handle what was asked for
	 */
	#[DataProvider('handlesThatAreNotFederated')]
	public function testAHandleThatNamesNoFediverseHostIsANotFoundPage(string $handle): void {
		$this->unknownActor();

		$response = $this->controller(null)->actor($handle);

		$this->assertNotFoundGuestPage($response, 'Account not found');
	}

	public static function handlesThatAreNotFederated(): array {
		return [
			'a port' => ['someone@remote.tld:8080'],
			'an address and a port' => ['someone@10.0.0.5:8080'],
			'no dot' => ['someone@localhost'],
			'an intranet name' => ['someone@intranet'],
		];
	}

	#[DataProvider('publicPages')]
	public function testPublicPagesReportUnexpectedLookupFailures(string $page): void {
		$this->cacheActorService->method('getFromAccount')->with('alice')->willThrowException(new \RuntimeException('db down'));

		$response = $this->controller(null)->$page('alice');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(-1, $response->getData()['status']);
		$this->assertSame('request failed', $response->getData()['error']);
		$this->assertArrayNotHasKey('exception', $response->getData(), 'internals must not leak');
	}

	// displayPost()

	public function testAVisitorGetsThePublicPageWithThePostRenderedIntoIt(): void {
		$this->anonymous();
		$this->streamService->expects($this->never())->method('setViewer');
		$post = $this->knownPost();
		$post->expects($this->once())->method('setCompleteDetails')->with(true);
		// the app reads a status the way the client API serves one; the
		// ActivityPub shape has no `account` and its `id` is an address
		$post->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);

		$response = $this->controller(null)->displayPost('alice', 'abc');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertInstanceOf(PublicTemplateResponse::class, $response);
		$this->assertSame('main', $response->getTemplateName());
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($post, $this->states['item']);
		$this->assertSame(['public' => true, 'firstrun' => false, 'setup' => false], $this->states['serverData']);
	}

	public function testThePostIsReadAsItsViewer(): void {
		$viewer = $this->createStub(Person::class);
		$this->accountService->method('getCurrentViewer')->willReturn($viewer);
		$this->streamService->expects($this->once())->method('setViewer')->with($viewer);
		$this->knownPost();

		$this->controller('alice')->displayPost('alice', 'abc');
	}

	public function testALoggedInReaderGetsTheAppWithThePostRenderedIntoIt(): void {
		$this->accountService->method('getCurrentViewer')->willReturn($this->createStub(Person::class));
		$post = $this->knownPost();
		$this->navigationController->expects($this->once())->method('navigate');

		$response = $this->controller('alice')->displayPost('alice', 'abc');

		$this->assertSame($this->app, $response);
		$this->assertSame($post, $this->states['item']);
		$this->assertArrayNotHasKey('serverData', $this->states, 'navigate() provides the state of the app');
	}

	/**
	 * The app writes its own links to a post with the numeric id its client
	 * API uses, which is not the token in the post's address. Opening one in a
	 * new tab, reloading it, or following one somebody sent found nothing.
	 */
	public function testAPostIsFoundByTheNumericIdTheAppLinksWith(): void {
		$this->anonymous();
		$post = $this->createStub(Stream::class);
		$this->streamService->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streamService->expects($this->once())->method('getStreamByNid')
			->with(1789250751711653456)->willReturn($post);

		$this->controller(null)->displayPost('alice', '1789250751711653456');

		$this->assertSame($post, $this->states['item']);
	}

	public function testATokenThatIsNotANumberIsNotLookedUpAsOne(): void {
		$this->anonymous();
		$this->streamService->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streamService->expects($this->never())->method('getStreamByNid');

		$this->controller(null)->displayPost('alice', 'abc123');
	}

	/**
	 * The public page used to be rendered with nothing in it, and a 200, for a
	 * post that does not exist.
	 */
	public function testAnUnknownPostIsANotFoundPageForAVisitor(): void {
		$this->anonymous();
		$this->streamService->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streamService->method('getStreamByNid')->willThrowException(new StreamNotFoundException());

		$response = $this->controller(null)->displayPost('alice', '404404404');

		$this->assertNotFoundGuestPage($response, 'Post not found');
		$this->assertSame([], $this->states);
	}

	public function testAMistypedLocalHandleIsAlsoANotFoundPage(): void {
		// it was a 500: the page caught only the cache exception, and a name
		// with no local actor throws the other one
		$this->anonymous();
		$this->unknownLocalActor();

		$response = $this->controller(null)->actor('nobodyhere');

		$this->assertSame(404, $response->getStatus());
	}

	public function testAnUnknownPostIsTheAppWithA404ForALoggedInReader(): void {
		$this->accountService->method('getCurrentViewer')->willReturn($this->createStub(Person::class));
		$this->streamService->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$response = $this->controller('alice')->displayPost('alice', 'missing');

		// the app's own post view says the post is not available once it has
		// asked; nothing is rendered into the page for it to show first
		$this->assertSame($this->app, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertArrayNotHasKey('item', $this->states);
	}
}
