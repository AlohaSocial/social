<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\NavigationController;
use OCA\Social\Exceptions\AccountAlreadyExistsException;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IInitialStateService;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class NavigationControllerTest extends TestCase {
	/** @var IRequest&MockObject */
	private $request;
	/** @var IConfig&Stub */
	private $config;
	/** @var IInitialState&Stub */
	private $initialState;
	/** @var IURLGenerator&Stub */
	private $urlGenerator;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var DocumentService&MockObject */
	private $documentService;
	/** @var ConfigService&MockObject */
	private $configService;
	/** @var CheckService&MockObject */
	private $checkService;
	private $sensitiveMediaService;
	private \OCA\Social\Service\CountsService $countsService;
	private $sectionsService;
	private $streamService;
	private $filterService;
	/** @var IGroupManager&MockObject */
	private $groupManager;
	private array $states = [];
	private string|false $frontControllerEnv;

	protected function setUp(): void {
		$this->frontControllerEnv = getenv('front_controller_active');
		putenv('front_controller_active');

		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createStub(IConfig::class);
		$this->initialState = $this->createStub(IInitialState::class);
		$this->urlGenerator = $this->createStub(IURLGenerator::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->checkService = $this->createMock(CheckService::class);
		$this->streamService = $this->createMock(\OCA\Social\Service\StreamService::class);
		$this->filterService = $this->createStub(\OCA\Social\Service\FilterService::class);
		// what it does by default: hides nothing, hands each post back exported
		$this->filterService->method('apply')->willReturnArgument(0);
		$this->sensitiveMediaService = $this->createStub(\OCA\Social\Service\SensitiveMediaService::class);
		$this->countsService = $this->createStub(\OCA\Social\Service\CountsService::class);
		$this->countsService->method('hides')->willReturn(true);
		$this->sensitiveMediaService->method('policyFor')->willReturn('default');
		$this->sensitiveMediaService->method('choiceOf')->willReturn('');
		$this->sectionsService = $this->createStub(\OCA\Social\Service\SectionsService::class);
		$this->sectionsService->method('current')->willReturn([
			'stories' => true,
			'section_photos' => true,
			'section_videos' => true,
			'group_lists' => [],
		]);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $data): void {
				$this->states['social'][$key] = $data;
			});
		$this->urlGenerator->method('getBaseUrl')->willReturn('https://cloud.example/');

		\OC::$server->register(IRequest::class, $this->request);
		\OC::$server->register(IGroupManager::class, $this->groupManager);
	}

	protected function tearDown(): void {
		if ($this->frontControllerEnv === false) {
			putenv('front_controller_active');
		} else {
			putenv('front_controller_active=' . $this->frontControllerEnv);
		}
		\OC::$server->reset();
	}

	private function controller(?string $userId = 'alice'): NavigationController {
		return new NavigationController(
			$this->request,
			$userId,
			$this->config,
			$this->initialState,
			$this->urlGenerator,
			$this->accountService,
			$this->documentService,
			$this->configService,
			$this->checkService,
			$this->sensitiveMediaService,
			$this->countsService,
			$this->sectionsService,
			$this->createStub(\OCA\Social\Service\InterestService::class),
			$this->streamService,
			$this->filterService,
			$this->createStub(\OCA\Social\Service\ExternalMediaQuota::class),
			new NullLogger()
		);
	}

	private function systemValues(array $values): void {
		$this->config->method('getSystemValue')
			->willReturnCallback(fn (string $key, $default = '') => $values[$key] ?? $default);
	}

	private function configuredCloud(string $url = 'https://cloud.example/index.php'): void {
		$this->configService->method('getCloudUrl')->willReturn($url);
		$this->configService->method('getSocialUrl')->willReturn($url . '/apps/social/');
	}

	private function existingActor(): void {
		$this->accountService->method('createActor')->willThrowException(new AccountAlreadyExistsException());
	}

	private function serverData(): array {
		return $this->states['social']['serverData'];
	}

	// navigate()

	public function testNavigateRendersTheAppForAReturningUser(): void {
		$this->systemValues([]);
		$this->configuredCloud();
		$this->existingActor();
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(false);
		$this->checkService->expects($this->never())->method('checkDefault');
		$this->checkService->expects($this->never())->method('checkInstallationStatus');

		$response = $this->controller()->navigate();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('main', $response->getTemplateName());
		$this->assertSame([
			'public' => false,
			'firstrun' => false,
			'introductionDismissed' => false,
			'needsAccount' => false,
			'setup' => false,
			'isAdmin' => false,
			'cliUrl' => 'https://cloud.example/index.php',
			// what to do with sensitive media, and what this reader chose —
			// in the page because the timeline needs both before it draws
			'nsfwPolicy' => 'default',
			'nsfwChoice' => '',
			// whether the like, boost and follower numbers are hidden, which
			// the page needs before it draws a post
			'hideCounts' => true,
			// which sections this instance offers, so the sidebar is drawn
			// right the first time rather than losing entries a moment later
			'sections' => [
				'stories' => true,
				'section_photos' => true,
				'section_videos' => true,
				'group_lists' => [],
			],
			// whether For you is on for this reader; what it holds is
			// InterestService::pageState()'s, tested there
			'interests' => [],
			// the media quota of a self-registered external user; alice is not one
			'externalMedia' => null,
			// and whether people may invite others to register; not here
			'externalInvites' => false,
			'cloudAddress' => 'https://cloud.example/index.php',
		], $this->serverData());
	}

	public function testNavigateServesThePublicTimelineToAnAnonymousVisitor(): void {
		\OC::$server->register(IInitialStateService::class, $this->createStub(IInitialStateService::class));
		$this->accountService->expects($this->never())->method('getActorFromUserId');
		$this->accountService->expects($this->never())->method('generateHandleFromUserId');
		$this->configService->expects($this->never())->method('getCloudUrl');
		$this->streamService->expects($this->never())->method('getTimeline');

		$response = $this->controller(null)->navigate();

		$this->assertInstanceOf(PublicTemplateResponse::class, $response);
		$this->assertSame('main', $response->getTemplateName());
		$this->assertTrue($this->serverData()['public']);
		$this->assertFalse($this->serverData()['needsAccount']);
		$this->assertFalse($this->serverData()['isAdmin']);
	}

	public function testAccountDeletionFarewellIsAvailableAsAPublicPage(): void {
		\OC::$server->register(IInitialStateService::class, $this->createStub(IInitialStateService::class));
		$response = $this->controller(null)->accountDeleted();

		$this->assertInstanceOf(PublicTemplateResponse::class, $response);
		$this->assertSame('account_deleted', $response->getTemplateName());
	}

	public function testReloadingThePublicTimelineRouteIsAvailableToAnAnonymousVisitor(): void {
		\OC::$server->register(IInitialStateService::class, $this->createStub(IInitialStateService::class));

		$response = $this->controller(null)->timeline('timeline');

		$this->assertInstanceOf(PublicTemplateResponse::class, $response);
		$this->assertTrue($this->serverData()['public']);
	}

	public function testNavigateAsksBeforeCreatingAnAccount(): void {
		$this->systemValues([]);
		$this->configuredCloud();
		$this->accountService->method('getActorFromUserId')->with('alice')
			->willThrowException(new ActorDoesNotExistException());
		// the handle is derived from the user id rather than being the user id:
		// not every Nextcloud user id is a usable Fediverse handle -- and it is
		// a suggestion now, not a decision
		$this->accountService->method('generateHandleFromUserId')->with('alice')->willReturn('alice');
		$this->accountService->method('linkedHandle')->with('alice')->willReturn('alice@mastodon.social');
		// an RSA key pair and a WebFinger identity used to appear on the first
		// click of the app icon, with nobody asked (alohasocial/social#1130)
		$this->accountService->expects($this->never())->method('createActor');

		$this->controller()->navigate();

		$data = $this->serverData();
		$this->assertTrue($data['needsAccount']);
		$this->assertFalse($data['firstrun'], 'nothing ran for the first time: nothing exists yet');
		$this->assertSame('alice', $data['suggestedHandle']);
		$this->assertSame('alice@mastodon.social', $data['linkedHandle']);
	}

	public function testNavigateAddsChecksForAdmins(): void {
		$this->systemValues([]);
		$this->configuredCloud();
		$this->existingActor();
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(true);
		$this->checkService->method('checkDefault')->willReturn(['wellknown' => true]);

		$this->controller()->navigate();

		$this->assertTrue($this->serverData()['isAdmin']);
		$this->assertSame(['wellknown' => true], $this->serverData()['checks']);
	}

	public function testNavigateOmitsIndexPhpWhenFrontControllerIsActive(): void {
		$this->systemValues(['htaccess.IgnoreFrontController' => true]);
		$this->configuredCloud();
		$this->existingActor();

		$this->controller()->navigate();

		$this->assertSame('https://cloud.example', $this->serverData()['cliUrl']);
	}

	public function testNavigateAutoConfiguresTheCloudAddressFromCliUrl(): void {
		$this->systemValues(['overwrite.cli.url' => 'https://cloud.example/']);
		// the derivation itself lives in CheckService, where it is tested; here
		// it only matters that what it answers is what gets stored and returned
		$this->checkService->method('derivedCloudAddress')->willReturn('https://cloud.example/index.php');
		$this->configService->method('getCloudUrl')->willThrowException(new SocialAppConfigException());
		$this->configService->method('getSocialUrl')->willReturn('x');
		$this->checkService->expects($this->once())->method('checkInstallationStatus')->with(true);
		$this->configService->expects($this->once())->method('setCloudUrl')->with('https://cloud.example/index.php');
		$this->existingActor();

		$this->controller()->navigate();

		$this->assertSame('https://cloud.example/index.php', $this->serverData()['cloudAddress']);
		$this->assertFalse($this->serverData()['setup']);
	}

	public function testNavigateShowsTheSetupPageToAdminsWhenNothingIsConfigured(): void {
		$this->systemValues([]);
		$this->configService->method('getCloudUrl')->willThrowException(new SocialAppConfigException());
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->request->method('getParam')->with('cloudAddress')->willReturn(null);
		$this->accountService->expects($this->never())->method('createActor');
		$this->checkService->expects($this->never())->method('checkDefault');

		$response = $this->controller()->navigate();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertTrue($this->serverData()['setup']);
		$this->assertTrue($this->serverData()['isAdmin']);
		$this->assertArrayNotHasKey('cloudAddress', $this->serverData());
	}

	/**
	 * The setup form and the page after it read `checks.success`; without the
	 * key the form threw while rendering and the administrator got a blank page.
	 */
	public function testTheSetupPageCarriesChecksWithoutProbingAnything(): void {
		$this->systemValues([]);
		$this->configService->method('getCloudUrl')->willThrowException(new SocialAppConfigException());
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->request->method('getParam')->with('cloudAddress')->willReturn(null);
		$this->checkService->expects($this->never())->method('checkDefault');
		$this->checkService->method('cloudAddresses')->willReturn(['configured' => '', 'expected' => '']);

		$this->controller()->navigate();

		$this->assertSame([
			'success' => true,
			'checks' => [],
			'addresses' => ['configured' => '', 'expected' => ''],
			'clientApi' => [],
		], $this->serverData()['checks']);
	}

	public function testNavigateStoresTheCloudAddressSubmittedByAnAdmin(): void {
		$this->systemValues([]);
		$this->configService->method('getCloudUrl')->willThrowException(new SocialAppConfigException());
		$this->configService->method('getSocialUrl')->willReturn('x');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->request->method('getParam')->with('cloudAddress')->willReturn('https://cloud.example/index.php');
		$this->configService->expects($this->once())->method('setCloudUrl')->with('https://cloud.example/index.php');
		$this->existingActor();

		$this->controller()->navigate();

		$this->assertTrue($this->serverData()['setup']);
		$this->assertArrayHasKey('checks', $this->serverData());
	}

	public function testNavigateContinuesForNonAdminsWhenSetupIsMissing(): void {
		$this->systemValues([]);
		$this->configService->method('getCloudUrl')->willThrowException(new SocialAppConfigException());
		$this->configService->method('getSocialUrl')->willReturn('x');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->configService->expects($this->never())->method('setCloudUrl');
		$this->existingActor();

		$response = $this->controller()->navigate();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertTrue($this->serverData()['setup']);
	}

	public function testNavigateInitialisesTheSocialUrlWhenMissing(): void {
		$this->systemValues([]);
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');
		$this->configService->method('getSocialUrl')->willThrowException(new SocialAppConfigException());
		$this->configService->expects($this->once())->method('setSocialUrl');
		$this->existingActor();

		$this->controller()->navigate();
	}

	public function testNavigateSurvivesActorCreationConfigErrors(): void {
		$this->systemValues([]);
		$this->configuredCloud();
		$this->accountService->method('createActor')->willThrowException(new SocialAppConfigException());

		$this->assertInstanceOf(TemplateResponse::class, $this->controller()->navigate());
		$this->assertFalse($this->serverData()['firstrun']);
	}

	public function testTimelineAndAccountRenderTheSamePage(): void {
		$this->systemValues([]);
		$this->configuredCloud();
		$this->existingActor();

		$this->assertSame('main', $this->controller()->timeline('home')->getTemplateName());
		$this->assertSame('main', $this->controller()->account('alice')->getTemplateName());
	}

	/**
	 * The client-side router owns these paths; the server has to answer them
	 * too, or reloading or bookmarking one is a 404. `/search` was missing,
	 * so a reload of a search — or a search somebody sent — was one.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function clientSidePaths(): iterable {
		yield 'follow requests' => ['/follow_requests'];
		yield 'blocked' => ['/blocked'];
		yield 'discover' => ['/discover'];
		yield 'migration' => ['/migration'];
		yield 'statistics' => ['/statistics'];
		yield 'settings' => ['/settings'];
		yield 'shorts' => ['/shorts'];
		yield 'the earlier address of shorts' => ['/reels'];
		yield 'search' => ['/search'];
		yield 'search with a term' => ['/search/{term}'];
	}

	#[DataProvider('clientSidePaths')]
	public function testTheServerAnswersEveryPathTheClientSideRouterOwns(string $path): void {
		$routes = $this->routesOf('navigate');

		$this->assertContains($path, array_keys($routes), $path . ' is not a route of navigate()');
		$this->assertSame('GET', $routes[$path]->getVerb());
	}

	public function testEveryRouteOfNavigateHasANameOfItsOwn(): void {
		// a route is keyed by controller, method and postfix: two without one
		// would leave only the last registered
		$postfixes = array_map(
			static fn (FrontpageRoute $route): string => (string)$route->getPostfix(),
			array_values($this->routesOf('navigate'))
		);

		$this->assertSame(count($postfixes), count(array_unique($postfixes)));
	}

	/** @return array<string, FrontpageRoute> the routes of a method, by url */
	private function routesOf(string $method): array {
		$routes = [];
		foreach ((new \ReflectionMethod(NavigationController::class, $method))->getAttributes(FrontpageRoute::class) as $attribute) {
			$route = $attribute->newInstance();
			$routes[$route->getUrl()] = $route;
		}

		return $routes;
	}

	// documents

	private function cachedFile(string $method, string $mime, ?bool $public): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn('doc');
		$this->documentService->expects($this->once())->method($method)
			->willReturnCallback(function (string $id, string &$mimeType, bool $isPublic = false) use ($file, $mime, $public): ISimpleFile {
				$this->assertSame('doc-1', $id);
				if ($public !== null) {
					$this->assertSame($public, $isPublic);
				}
				$mimeType = $mime;

				return $file;
			});

		return $file;
	}

	/**
	 * The viewer-scoped variant: the id alone says nothing about who may read
	 * the document, so these take the asking actor as well.
	 */
	private function cachedFileAsViewer(string $method, string $mime, ?Person $viewer): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn('doc');
		$this->documentService->expects($this->once())->method($method)
			->willReturnCallback(function (string $id, ?Person $asked, string &$mimeType) use ($file, $mime, $viewer): ISimpleFile {
				$this->assertSame('doc-1', $id);
				$this->assertSame($viewer, $asked);
				$mimeType = $mime;

				return $file;
			});

		return $file;
	}

	/** `Response::cacheFor()` reads the clock out of the container. */
	private function publicClock(): void {
		\OC::$server->register(
			\OCP\AppFramework\Utility\ITimeFactory::class,
			$this->createStub(\OCP\AppFramework\Utility\ITimeFactory::class)
		);
	}

	private function assertServes(FileDisplayResponse|DataResponse $response, string $mime): void {
		$this->assertInstanceOf(FileDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($mime, $response->getHeaders()['Content-Type']);
	}

	public function testDocumentGetServesTheCachedDocumentToItsViewer(): void {
		$viewer = $this->createStub(Person::class);
		$this->accountService->method('getActorFromUserId')->with('alice')->willReturn($viewer);
		$this->cachedFileAsViewer('getFromCacheAsViewer', 'image/jpeg', $viewer);

		$this->assertServes($this->controller()->documentGet('doc-1'), 'image/jpeg');
	}

	public function testDocumentGetAsksAsNobodyWhenThereIsNoSession(): void {
		$this->cachedFileAsViewer('getFromCacheAsViewer', 'image/jpeg', null);

		$this->assertServes($this->controller(null)->documentGet('doc-1'), 'image/jpeg');
	}

	public function testDocumentGetAsksAsNobodyWhenTheSessionHasNoSocialAccount(): void {
		$this->accountService->method('getActorFromUserId')
			->willThrowException(new AccountDoesNotExistException());
		$this->cachedFileAsViewer('getFromCacheAsViewer', 'image/jpeg', null);

		$this->assertServes($this->controller()->documentGet('doc-1'), 'image/jpeg');
	}

	public function testDocumentGetPublicOnlyServesPublicDocuments(): void {
		$this->publicClock();
		$this->cachedFile('getFromCache', 'image/png', true);

		$this->assertServes($this->controller(null)->documentGetPublic('doc-1'), 'image/png');
	}

	/**
	 * These two routes serve every avatar and every attachment on a public
	 * page and said nothing about caching, so a browser asked this server for
	 * the same forty pictures on every page load.
	 */
	public function testAPublicPictureMayBeKeptForADay(): void {
		$this->publicClock();
		$this->cachedFile('getFromCache', 'image/png', true);

		$headers = $this->controller(null)->documentGetPublic('doc-1')->getHeaders();

		$this->assertSame('public, max-age=86400, must-revalidate', $headers['Cache-Control']);
	}

	/**
	 * Not immutable: a remote avatar is the bytes at somebody else's URL and a
	 * peer may replace them, so an immutable year would mean a profile picture
	 * that changed today still being drawn next spring.
	 */
	public function testAPublicPictureIsNotCalledImmutable(): void {
		$this->publicClock();
		$this->cachedFile('getResizedFromCache', 'image/gif', true);

		$headers = $this->controller(null)->resizedGetPublic('doc-1')->getHeaders();

		$this->assertStringNotContainsString('immutable', $headers['Cache-Control']);
	}

	public function testResizedGetServesTheResizedCopyToItsViewer(): void {
		$viewer = $this->createStub(Person::class);
		$this->accountService->method('getActorFromUserId')->with('alice')->willReturn($viewer);
		$this->cachedFileAsViewer('getResizedFromCacheAsViewer', 'image/webp', $viewer);

		$this->assertServes($this->controller()->resizedGet('doc-1'), 'image/webp');
	}

	public function testResizedGetPublicOnlyServesPublicDocuments(): void {
		$this->publicClock();
		$this->cachedFile('getResizedFromCache', 'image/gif', true);

		$this->assertServes($this->controller(null)->resizedGetPublic('doc-1'), 'image/gif');
	}

	/** @return iterable<string, array{string, string}> */
	public static function documentEndpoints(): iterable {
		yield 'documentGet' => ['documentGet', 'getFromCacheAsViewer'];
		yield 'documentGetPublic' => ['documentGetPublic', 'getFromCache'];
		yield 'resizedGet' => ['resizedGet', 'getResizedFromCacheAsViewer'];
		yield 'resizedGetPublic' => ['resizedGetPublic', 'getResizedFromCache'];
	}

	#[DataProvider('documentEndpoints')]
	public function testMissingDocumentsAnswerNotFound(string $action, string $method): void {
		$this->documentService->method($method)->willThrowException(new CacheDocumentDoesNotExistException('missing'));

		$response = $this->controller()->$action('doc-404');

		$this->assertInstanceOf(DataResponse::class, $response);
		// a stale link to a document is the caller's, not this server's
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(-1, $response->getData()['status']);
		$this->assertSame('request failed', $response->getData()['error']);
		$this->assertArrayNotHasKey('exception', $response->getData(), 'internals must not leak');
	}
	// --- the first screenful ------------------------------------------------

	/**
	 * Without this the first screen is a staircase: fetch 290 KB of
	 * JavaScript, mount, and only *then* ask the server for the posts — a
	 * second round trip and a full Nextcloud boot before anything a person
	 * came to read is on screen.
	 */
	public function testTheHomePageIsHandedItsFirstScreenful(): void {
		$this->systemValues([]);
		$this->configuredCloud();
		$this->existingActor();
		$posts = [$this->createMock(\OCA\Social\Model\ActivityPub\Stream::class)];
		$this->streamService->method('getTimeline')->willReturn($posts);

		$this->controller()->navigate();

		$this->assertSame($posts, $this->states['social']['firstPage'] ?? null);
	}

	/**
	 * The one screenful handed to the page used to be the only place in the app
	 * where a word the reader had muted came back — and the first thing they
	 * saw. The API route filters the page it answers with; so does this.
	 */
	public function testTheSeededScreenfulIsFilteredTheWayTheApiFiltersIt(): void {
		$this->systemValues([]);
		$this->configuredCloud();
		$this->existingActor();
		$kept = ['id' => '2'];
		$this->streamService->method('getTimeline')->willReturn([
			$this->createMock(\OCA\Social\Model\ActivityPub\Stream::class),
			$this->createMock(\OCA\Social\Model\ActivityPub\Stream::class),
		]);

		$filterService = $this->createMock(\OCA\Social\Service\FilterService::class);
		$filterService->expects($this->once())
			->method('apply')
			->with($this->anything(), \OCA\Social\Model\Client\Filter::CONTEXT_HOME, $this->anything())
			->willReturn([$kept]);
		$this->filterService = $filterService;

		$this->controller()->navigate();

		$this->assertSame([$kept], $this->states['social']['firstPage'] ?? null);
	}

	/**
	 * Seeding a profile or a hashtag page would be seeding whatever happened
	 * to be in the URL.
	 */
	public function testOnlyTheHomeTimelineIsSeeded(): void {
		$this->systemValues([]);
		$this->configuredCloud();
		$this->existingActor();
		$this->streamService->expects($this->never())->method('getTimeline');

		$this->controller()->navigate('profile/alice');

		$this->assertNull($this->states['social']['firstPage'] ?? null);
	}

	/** A page the server could not build asks, which is what it did before. */
	public function testAFailureToBuildItLeavesThePageToAsk(): void {
		$this->systemValues([]);
		$this->configuredCloud();
		$this->existingActor();
		$this->streamService->method('getTimeline')
			->willThrowException(new \Exception('no'));

		$this->controller()->navigate();

		$this->assertNull($this->states['social']['firstPage'] ?? null);
	}
}
