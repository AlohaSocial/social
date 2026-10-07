<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CountsService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\ExternalMediaQuota;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\InterestService;
use OCA\Social\Service\SectionsService;
use OCA\Social\Service\SensitiveMediaService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Class NavigationController
 *
 * @package OCA\Social\Controller
 */
class NavigationController extends Controller {
	/** How many posts the page is handed before it asks for any. */
	private const FIRST_PAGE = 15;

	use TArrayTools;
	use TNCDataResponse;

	private ?string $userId = null;

	public function __construct(
		IRequest $request,
		?string $userId,
		private IConfig $config,
		private IInitialState $initialState,
		private IURLGenerator $urlGenerator,
		private AccountService $accountService,
		private DocumentService $documentService,
		private ConfigService $configService,
		private CheckService $checkService,
		private SensitiveMediaService $sensitiveMediaService,
		private CountsService $countsService,
		private SectionsService $sectionsService,
		private InterestService $interestService,
		private StreamService $streamService,
		private FilterService $filterService,
		private ExternalMediaQuota $externalMediaQuota,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->userId = $userId;
	}

	/** A public landing page after a self-registered account has been deleted. */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/account-deleted')]
	public function accountDeleted(): PublicTemplateResponse {
		return new PublicTemplateResponse(Application::APP_ID, 'account_deleted');
	}

	/**
	 * Display the navigation page of the Social app.
	 *
	 *
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	// The client-side router owns `/follow_requests`, `/blocked`, `/discover`,
	// `/migration`, `/statistics`, `/settings`, `/search`, `/reels` and
	// `/switch`; the server has to answer them too, or reloading or bookmarking
	// one of those pages is a 404 — and a page nobody can link to is one
	// nobody can be sent.
	// `postfix` keeps the route names apart: a route is keyed by controller,
	// method and postfix, so several routes on one method without it would
	// leave only the last. The profile and post pages, `/@{username}` and
	// `/@{username}/{token}`, are ActivityPub urls first and are answered by
	// `ActivityPubController`, which hands a browser to `SocialPubController`.
	#[FrontpageRoute(verb: 'GET', url: '/')]
	#[FrontpageRoute(verb: 'GET', url: '/follow_requests', postfix: 'followrequests')]
	#[FrontpageRoute(verb: 'GET', url: '/blocked', postfix: 'blocked')]
	#[FrontpageRoute(verb: 'GET', url: '/discover', postfix: 'discover')]
	#[FrontpageRoute(verb: 'GET', url: '/migration', postfix: 'migration')]
	#[FrontpageRoute(verb: 'GET', url: '/switch', postfix: 'switch')]
	#[FrontpageRoute(verb: 'GET', url: '/statistics', postfix: 'statistics')]
	#[FrontpageRoute(verb: 'GET', url: '/settings', postfix: 'settings')]
	#[FrontpageRoute(verb: 'GET', url: '/reels', postfix: 'reels')]
	#[FrontpageRoute(verb: 'GET', url: '/search', postfix: 'search')]
	#[FrontpageRoute(verb: 'GET', url: '/search/{term}', postfix: 'searchterm')]
	#[FrontpageRoute(verb: 'GET', url: '/collections/{id}', postfix: 'collection', requirements: ['id' => '\\d+'])]
	#[FrontpageRoute(verb: 'GET', url: '/places/{id}', postfix: 'place', requirements: ['id' => '\\d+'])]
	public function navigate(string $path = ''): TemplateResponse {
		// A visitor may read public posts from this instance without an account.
		// Do this before any account-specific setup or state is requested: the
		// normal root page is the private home feed, which must never be exposed
		// as a guest page.
		if ($this->userId === null) {
			$this->initialState->provideInitialState('serverData', [
				'public' => true,
				'firstrun' => false,
				'needsAccount' => false,
				'setup' => false,
				'isAdmin' => false,
			]);

			return new PublicTemplateResponse(Application::APP_ID, 'main');
		}

		$this->logger->debug('[NavigationController] navigate() called', [
			'path' => $path,
			'userId' => $this->userId,
		]);

		$serverData = [
			'atprotoEnabled' => in_array($this->configService->getAppValue(ConfigService::ATPROTO_ENABLED), ['1', 'true'], true),
			'public' => false,
			'firstrun' => false,
			'introductionDismissed' => $this->config->getUserValue(
				$this->userId, Application::APP_ID, 'introduction_dismissed', '0'
			) === '1',
			'needsAccount' => false,
			'setup' => false,
			'isAdmin' => Server::get(IGroupManager::class)->isAdmin($this->userId),
			'cliUrl' => $this->getCliUrl(),
			// what to do with a post somebody marked sensitive: this reader's
			// own choice, or what the instance does for somebody who has not
			// chosen. In the page rather than behind a request because it
			// decides what the very first screenful looks like, and a timeline
			// that uncovered itself a moment after it drew would be worse than
			// either policy.
			'nsfwPolicy' => $this->sensitiveMediaService->policyFor($this->userId),
			// and what this reader *chose*, which is a different thing: the
			// settings page has to be able to show "follow the instance" as
			// the state it is rather than as whichever policy that currently
			// resolves to
			'nsfwChoice' => $this->sensitiveMediaService->choiceOf($this->userId),
			'hideCounts' => $this->countsService->hides($this->userId),
			// which sections this instance offers. In the page rather than
			// behind a request for the same reason the policy above is: the
			// sidebar is drawn before anything is fetched, and entries that
			// appeared and then vanished would read as a bug rather than as a
			// setting.
			'sections' => $this->sectionsService->current(),
			// For you: whether the feed, its settings and the tracking
			// that teaches it are on for this reader, decided before the
			// switcher above the timeline is drawn
			'interests' => $this->interestService->pageState($this->userId),
			// a self-registered external user: no Files to attach from, and a
			// media quota the settings page shows. Null for everybody else.
			'externalMedia' => $this->externalMediaQuota->usageOf($this->userId),
			// whether the administrator lets people invite others to register
			'externalInvites' => $this->configService->getAppValue(ConfigService::SOCIAL_EXTERNAL_ENABLED) === '1'
				&& $this->configService->getAppValue(ConfigService::SOCIAL_EXTERNAL_USER_INVITES) === '1',
		];

		$this->logger->debug('[NavigationController] Initial serverData', ['serverData' => $serverData]);

		try {
			$serverData['cloudAddress'] = $this->configService->getCloudUrl();
			$this->logger->debug('[NavigationController] Cloud address configured', [
				'cloudAddress' => $serverData['cloudAddress']
			]);
		} catch (SocialAppConfigException $e) {
			$this->logger->warning('[NavigationController] Cloud address not configured, attempting setup', [
				'exception' => $e->getMessage()
			]);
			$this->checkService->checkInstallationStatus(true);
			$cloudAddress = $this->setupCloudAddress();
			if ($cloudAddress !== '') {
				$serverData['cloudAddress'] = $cloudAddress;
				$this->logger->info('[NavigationController] Cloud address auto-configured', [
					'cloudAddress' => $cloudAddress
				]);
			} else {
				$serverData['setup'] = true;
				$this->logger->warning('[NavigationController] Setup required - cloud address not configured');

				if ($serverData['isAdmin']) {
					$cloudAddress = $this->request->getParam('cloudAddress');
					if ($cloudAddress !== null) {
						$this->configService->setCloudUrl($cloudAddress);
						$this->logger->info('[NavigationController] Cloud address set from request', [
							'cloudAddress' => $cloudAddress
						]);
					} else {
						$this->logger->info('[NavigationController] Returning setup page (admin user)');
						// nothing is probed before there is an address to probe
						// from, but the page reads the same shape either way
						$serverData['checks'] = [
							'success' => true,
							'checks' => [],
							'addresses' => $this->checkService->cloudAddresses(),
							'clientApi' => [],
						];
						$this->initialState->provideInitialState('serverData', $serverData);
						return new TemplateResponse(Application::APP_ID, 'main');
					}
				} else {
					$this->logger->info('[NavigationController] Returning setup page (non-admin user)');
				}
			}
		}

		try {
			$socialUrl = $this->configService->getSocialUrl();
			$this->logger->debug('[NavigationController] Social URL retrieved', ['socialUrl' => $socialUrl]);
		} catch (SocialAppConfigException $e) {
			$this->logger->info('[NavigationController] Setting social URL', ['exception' => $e->getMessage()]);
			$this->configService->setSocialUrl();
		}

		/*
		 * A person without an account is asked, not given one. The actor used
		 * to be created on the first click of the app icon -- an RSA key pair,
		 * a WebFinger-resolvable identity and a handle derived from the user
		 * id, with no consent and no choice -- and somebody who already had an
		 * account on Mastodon could not say so. The page carries what the
		 * setup screen needs (alohasocial/social#1130, #1624); the account is
		 * created by `LocalController::accountCreate()` when they ask.
		 */
		try {
			$this->accountService->getActorFromUserId($this->userId);
		} catch (ActorDoesNotExistException $e) {
			$serverData['needsAccount'] = true;
			try {
				$serverData['suggestedHandle'] = $this->accountService->generateHandleFromUserId($this->userId);
			} catch (Exception $e) {
				$serverData['suggestedHandle'] = '';
			}
			$serverData['linkedHandle'] = $this->accountService->linkedHandle($this->userId);
		} catch (Exception $e) {
			$this->logger->error('[NavigationController] could not look the account up', [
				'userId' => $this->userId,
				'exception' => $e->getMessage()
			]);
		}

		if ($serverData['isAdmin']) {
			$checks = $this->checkService->checkDefault();
			$serverData['checks'] = $checks;
			$this->logger->debug('[NavigationController] Admin checks completed', ['checks' => $checks]);
		}

		$this->logger->debug('[NavigationController] Providing initial state and rendering template', [
			'serverData' => $serverData
		]);
		$this->initialState->provideInitialState('serverData', $serverData);
		$this->provideViewerAccount();
		$this->provideFirstPage($path);

		return new TemplateResponse(Application::APP_ID, 'main');
	}

	/**
	 * The reader's own account, handed to the page instead of waited for.
	 *
	 * The app used to ask for it from `beforeMount()`, so every load spent a
	 * second authenticated round trip — `GET /api/v1/global/account/info` — on
	 * something this request is already holding, and nothing could render until
	 * it came back. The reply is the same cached actor this reads, in the same
	 * export format, so the store is seeded with what it would have received.
	 *
	 * A failure here is not one worth showing anybody: the page falls back to
	 * asking, which is what it did before.
	 */
	private function provideViewerAccount(): void {
		if ($this->userId === null) {
			return;
		}

		try {
			$actor = $this->accountService->getActorFromUserId($this->userId);
			$viewer = $this->accountService->getCachedLocalActor($actor->getPreferredUsername());
			$viewer->setExportFormat(ACore::FORMAT_LOCAL);

			$this->initialState->provideInitialState('currentAccount', $viewer);
		} catch (Exception $e) {
			$this->logger->debug('[NavigationController] no account to hand the page', [
				'userId' => $this->userId,
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * The first page of the home timeline, in the page that asks for it.
	 *
	 * Without this the first screenful is a staircase: the browser fetches
	 * 290 KB of JavaScript, mounts, and only then asks the server for the
	 * posts — a second round trip, and a full Nextcloud boot, before anything
	 * a person came to read is on screen. The page is already doing a database
	 * request and already holds the viewer; the posts cost one more and are
	 * bytes the browser was going to ask for anyway.
	 *
	 * Only the home timeline, and only when the home timeline is what was
	 * asked for. Seeding a profile or a hashtag page would be seeding whatever
	 * happened to be in the URL, and a page reached with a cursor is one the
	 * reader has scrolled to rather than the one they arrived on.
	 *
	 * The reader's keyword filters are applied to it, exactly as the API route
	 * applies them to the page it answers with. Without that the one screenful
	 * a person is handed on every full page load is the only place in the app
	 * where a word they muted comes back — and it is the first thing they see.
	 * It is `FilterService::apply()` that also exports each post for the
	 * client, so what the page is handed is now byte for byte what
	 * `/api/v1/timelines/home` would have answered.
	 *
	 * A failure is not worth showing anybody: the page asks, which is what it
	 * did before.
	 */
	private function provideFirstPage(string $path): void {
		if ($this->userId === null || !$this->isHomeTimeline($path)) {
			return;
		}

		try {
			$viewer = $this->accountService->getActorFromUserId($this->userId);
			$this->streamService->setViewer($viewer);

			$options = new ProbeOptions();
			$options->setFormat(ACore::FORMAT_LOCAL)
				->setProbe(ProbeOptions::HOME)
				->setLimit(self::FIRST_PAGE);

			$this->initialState->provideInitialState(
				'firstPage',
				$this->filterService->apply(
					$this->streamService->getTimeline($options), Filter::CONTEXT_HOME, $viewer
				)
			);
		} catch (Exception $e) {
			$this->logger->debug('[NavigationController] no first page to hand the page', [
				'userId' => $this->userId,
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Whether this address is the home timeline.
	 *
	 * The app's root and `timeline/home` are the two ways of naming it;
	 * everything else — a profile, a hashtag, the notifications — is a
	 * different list and is not what this seeds.
	 */
	private function isHomeTimeline(string $path): bool {
		$path = trim($path, '/');

		return $path === '' || $path === 'timeline' || $path === 'timeline/home';
	}

	private function setupCloudAddress(): string {
		// one definition of what the address should be, shared with the check
		// that later notices the server's URL moving out from under it
		$cloudAddress = $this->checkService->derivedCloudAddress();
		if ($cloudAddress !== '') {
			$this->configService->setCloudUrl($cloudAddress);
		}

		return $cloudAddress;
	}

	private function getCliUrl() {
		$url = rtrim($this->urlGenerator->getBaseUrl(), '/');
		$frontControllerActive
			= ($this->config->getSystemValue('htaccess.IgnoreFrontController', false) === true
			 || getenv('front_controller_active') === 'true');
		if (!$frontControllerActive) {
			$url .= '/index.php';
		}

		return $url;
	}

	/**
	 * Display the navigation page of the Social app.
	 *
	 *
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/timeline/{path}', requirements: ['path' => '.+'], defaults: ['path' => ''])]
	public function timeline(string $path = ''): TemplateResponse {
		return $this->navigate();
	}

	/**
	 * Display the navigation page of the Social app.
	 *
	 *
	 * @param string $path
	 *
	 * @return TemplateResponse
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function account(string $path = ''): TemplateResponse {
		return $this->navigate();
	}

	/**
	 * The Social actor of the session, when there is one.
	 *
	 * A document id says nothing about who may read the document, so the routes
	 * below need to know who is asking; a session without a Social account (or
	 * no session at all) is nobody in particular and sees only public copies.
	 */
	private function viewer(): ?Person {
		if ($this->userId === null) {
			return null;
		}

		try {
			return $this->accountService->getActorFromUserId($this->userId);
		} catch (Exception $e) {
			return null;
		}
	}

	/**
	 *
	 * @param string $id
	 *
	 * @return Response
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/document/get')]
	public function documentGet(string $id): Response {
		$this->logger->debug('[NavigationController] documentGet called', ['id' => $id]);
		try {
			$mime = '';
			$file = $this->documentService->getFromCacheAsViewer($id, $this->viewer(), $mime);
			$this->logger->debug('[NavigationController] Document retrieved from cache', [
				'id' => $id,
				'mime' => $mime
			]);

			return new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mime]);
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	/**
	 *
	 *
	 * @param string $id
	 *
	 * @return Response
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/document/public')]
	public function documentGetPublic(string $id): Response {
		$this->logger->debug('[NavigationController] documentGetPublic called', ['id' => $id]);
		try {
			$mime = '';
			$file = $this->documentService->getFromCache($id, $mime, true);
			$this->logger->debug('[NavigationController] Public document retrieved from cache', [
				'id' => $id,
				'mime' => $mime
			]);

			$response = new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mime]);
			$this->cacheMedia($response);

			return $response;
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	/**
	 *
	 * @param string $id
	 *
	 * @return Response
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/document/get/resized')]
	public function resizedGet(string $id): Response {
		try {
			$mime = '';
			$file = $this->documentService->getResizedFromCacheAsViewer($id, $this->viewer(), $mime);

			return new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mime]);
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	/**
	 *
	 * @param string $id
	 *
	 * @return Response
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/document/public/resized')]
	public function resizedGetPublic(string $id): Response {
		try {
			$mime = '';
			$file = $this->documentService->getResizedFromCache($id, $mime, true);
			$response = new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mime]);
			$this->cacheMedia($response);

			return $response;
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	/**
	 * How long a cached picture may be kept by whoever asked for it.
	 *
	 * These two routes serve every avatar and every attachment on a public
	 * page, and said nothing about caching at all — so a browser asked this
	 * server for the same forty pictures on every page load, and a shared
	 * cache in front of the instance could not help.
	 *
	 * A day, publicly cacheable, and **not immutable**. A local upload never
	 * changes under its id and could be kept for a year; a remote avatar is
	 * the bytes at somebody else's URL, and a peer may replace them — an
	 * immutable year would mean a profile picture that changed today still
	 * being drawn next spring. A day is the same bound the avatar route beside
	 * it already applies, and it is the difference between forty requests a
	 * page and forty requests a day.
	 */
	private function cacheMedia(Response $response): void {
		$response->cacheFor(86400, true, false);
	}
}
