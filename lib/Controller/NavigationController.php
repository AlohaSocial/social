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
use OCA\Social\Response\RangedFileResponse;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\CloudAddressRules;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CountsService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\ExternalMediaQuota;
use OCA\Social\Service\FeedDisplayService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\InterestService;
use OCA\Social\Service\ModeratorService;
use OCA\Social\Service\SectionsService;
use OCA\Social\Service\SensitiveMediaService;
use OCA\Social\Service\SetupNotificationService;
use OCA\Social\Service\StartupHintsService;
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
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Server;
use Psr\Log\LoggerInterface;

class NavigationController extends Controller {
	/** How many posts the page is handed before it asks for any. */
	private const FIRST_PAGE = 15;

	/**
	 * The timelines under `/timeline/` that are the reader's own: there is
	 * nothing in them for somebody who is not signed in.
	 */
	private const SIGNED_IN_TIMELINES = ['notifications', 'direct', 'favourites', 'bookmarks', 'list', 'interests'];

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
		private FeedDisplayService $feedDisplayService,
		private SectionsService $sectionsService,
		private InterestService $interestService,
		private StreamService $streamService,
		private FilterService $filterService,
		private ExternalMediaQuota $externalMediaQuota,
		private LoggerInterface $logger,
		private FollowService $followService,
		private ?ModeratorService $moderatorService = null,
		private ?SetupNotificationService $setupNotifications = null,
		private ?StartupHintsService $startupHints = null,
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
	 * The app page, for the client-side router's public routes.
	 *
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	// The client-side router's pages have to be answered by the server too, or
	// reloading or bookmarking one is a 404; the reader's own pages are
	// personal()'s. `postfix` keeps the route names apart, since a route is keyed
	// by controller, method and postfix. `/@{username}` and `/@{username}/{token}`
	// are `ActivityPubController`'s, which hands a browser to `SocialPubController`.
	#[FrontpageRoute(verb: 'GET', url: '/')]
	#[FrontpageRoute(verb: 'GET', url: '/discover', postfix: 'discover')]
	#[FrontpageRoute(verb: 'GET', url: '/shorts', postfix: 'shorts')]
	// the Shorts stack's old address, for old links; the client router sends it
	// on to `/shorts`
	#[FrontpageRoute(verb: 'GET', url: '/reels', postfix: 'reels')]
	#[FrontpageRoute(verb: 'GET', url: '/search', postfix: 'search')]
	// the server's rules, contact and legal links, which a visitor needs before signing up
	#[FrontpageRoute(verb: 'GET', url: '/about', postfix: 'about')]
	#[FrontpageRoute(verb: 'GET', url: '/search/{term}', postfix: 'searchterm')]
	#[FrontpageRoute(verb: 'GET', url: '/collections/{id}', postfix: 'collection', requirements: ['id' => '\\d+'])]
	#[FrontpageRoute(verb: 'GET', url: '/places/{id}', postfix: 'place', requirements: ['id' => '\\d+'])]
	// a Bluesky starter pack, at the path bsky.app gives it
	#[FrontpageRoute(verb: 'GET', url: '/starter-pack/{actor}/{rkey}', postfix: 'starterpack')]
	public function navigate(string $path = ''): TemplateResponse {
		// A visitor may read public posts without an account. Handled before any
		// account-specific state is requested: the root page is otherwise the
		// private home feed.
		if ($this->userId === null) {
			$this->initialState->provideInitialState('serverData', [
				'public' => true,
				'cloudAddress' => $this->configService->getAppValue(ConfigService::CLOUD_URL),
				'firstrun' => false,
				'needsAccount' => false,
				'setup' => false,
				'isAdmin' => false,
			]);

			$page = new PublicTemplateResponse(Application::APP_ID, 'main');
			$page->setHeaderTitle(Application::APP_NAME_DISPLAY);

			return $page;
		}

		$this->logger->debug('[NavigationController] navigate() called', [
			'path' => $path,
			'userId' => $this->userId,
		]);

		$externalMedia = $this->externalMediaQuota->usageOf($this->userId);
		$serverData = [
			'public' => false,
			'timeZone' => $this->config->getUserValue($this->userId, 'core', 'timezone', $this->config->getSystemValueString('default_timezone', 'UTC')),
			'firstrun' => false,
			'introductionDismissed' => $this->config->getUserValue(
				$this->userId, Application::APP_ID, 'introduction_dismissed', '0'
			) === '1',
			'needsAccount' => false,
			'setup' => false,
			'isAdmin' => Server::get(IGroupManager::class)->isAdmin($this->userId),
			// whether the reader may verify accounts in the instance's name,
			// which is the moderators' (VerificationController)
			'canVerify' => $this->moderatorService?->isModerator($this->userId) ?? false,
			'cliUrl' => $this->getCliUrl(),
			// what to do with a post marked sensitive: this reader's choice, or the
			// instance default. In the page so the first screenful is drawn right.
			'nsfwPolicy' => $this->sensitiveMediaService->policyFor($this->userId),
			// and what this reader chose, so the settings page can show "follow the
			// instance" as such
			'nsfwChoice' => $this->sensitiveMediaService->choiceOf($this->userId),
			'hideCounts' => $this->countsService->hides($this->userId),
			// whether j, k, l, b and the rest act on the timeline, read before
			// the first key can be pressed
			'keyboardShortcuts' => $this->configService->getUserValue(ConfigService::USER_KEYBOARD_SHORTCUTS, $this->userId) !== '0',
			// which sections this instance offers, in the page since the sidebar is
			// drawn before anything is fetched
			'sections' => $this->sectionsService->current(),
			// For you: whether the feed, its settings and its tracking are on for
			// this reader
			'interests' => $this->interestService->pageState($this->userId),
			// a self-registered external user: no Files to attach from, and a
			// media quota the settings page shows. Null for everybody else.
			'externalMedia' => $externalMedia,
			// whether the administrator lets people invite others to register;
			// never an external user (ExternalSignupService::mayInvite())
			'externalInvites' => $externalMedia === null
				&& $this->configService->getAppValue(ConfigService::SOCIAL_EXTERNAL_ENABLED) === '1'
				&& $this->configService->getAppValue(ConfigService::SOCIAL_EXTERNAL_USER_INVITES) === '1',
			// whether this instance is a Bluesky host, and under which host its
			// handles are issued (`alice.<host>`)
			'bluesky' => [
				'enabled' => $this->configService->getAppValueBool(ConfigService::ATPROTO_ENABLED),
				'host' => strtolower($this->socialAddress()),
			],
			// the host addresses here end in (`alice@<host>`), which the
			// administrator may have set apart from the Nextcloud's own
			'socialAddress' => $this->socialAddress(),
		];

		$this->logger->debug('[NavigationController] Initial serverData', ['serverData' => $serverData]);

		try {
			$serverData['cloudAddress'] = $this->configService->getCloudUrl();
			$this->logger->debug('[NavigationController] Cloud address configured', [
				'cloudAddress' => $serverData['cloudAddress']
			]);
		} catch (SocialAppConfigException $e) {
			// Nothing is stored until an administrator confirms the address: every id
			// is built from it, and it cannot change afterwards.
			$this->checkService->checkInstallationStatus(true);
			$serverData['setup'] = true;

			if ($serverData['isAdmin']) {
				$derived = $this->checkService->derivedCloudAddress();
				$proposed = ($derived !== '') ? $derived : $serverData['cliUrl'];
				$serverData['proposedCloudAddress'] = $proposed;
				$serverData['proposedFrom'] = ($derived !== '') ? 'overwrite.cli.url' : 'request';
				$serverData['proposedProblems'] = CloudAddressRules::problems($proposed);
				// nothing is probed before there is an address to probe
				// from, but the page reads the same shape either way
				$serverData['checks'] = [
					'success' => true,
					'checks' => [],
					'addresses' => $this->checkService->cloudAddresses(),
					'clientApi' => [],
				];
			} else {
				$this->setupNotifications?->notifyAdminsOnce();
			}

			$this->initialState->provideInitialState('serverData', $serverData);

			return new TemplateResponse(Application::APP_ID, 'main');
		}

		try {
			$socialUrl = $this->configService->getSocialUrl();
			$this->logger->debug('[NavigationController] Social URL retrieved', ['socialUrl' => $socialUrl]);
		} catch (SocialAppConfigException $e) {
			$this->logger->info('[NavigationController] Setting social URL', ['exception' => $e->getMessage()]);
			$this->configService->setSocialUrl();
		}

		/*
		 * A person without an account is asked, not given one: the page carries
		 * what the setup screen needs, and `LocalController::accountCreate()`
		 * creates the account when they ask.
		 */
		try {
			$actor = $this->accountService->getActorFromUserId($this->userId);
			// the account's own name, chosen on the setup screen, which need not be
			// the user id
			$serverData['viewer'] = [
				'username' => $actor->getPreferredUsername(),
				'address' => $actor->getPreferredUsername() . '@' . $this->configService->getSocialAddress(),
			];
			// which of the requests the home timeline opens with would come
			// back empty, so the app does not send them (StartupHintsService)
			if ($this->startupHints !== null) {
				$serverData['startup'] = $this->startupHints->forViewer($actor, $this->userId);
			}
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
	 * The reader's own pages: the same app page as navigate(), but not public,
	 * so Nextcloud sends anybody else to its login and back.
	 *
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/settings', postfix: 'settings')]
	#[FrontpageRoute(verb: 'GET', url: '/statistics', postfix: 'statistics')]
	#[FrontpageRoute(verb: 'GET', url: '/migration', postfix: 'migration')]
	#[FrontpageRoute(verb: 'GET', url: '/switch', postfix: 'switch')]
	#[FrontpageRoute(verb: 'GET', url: '/follow_requests', postfix: 'followrequests')]
	#[FrontpageRoute(verb: 'GET', url: '/blocked', postfix: 'blocked')]
	public function personal(): TemplateResponse {
		return $this->navigate();
	}

	/**
	 * The reader's own account, handed to the page so it need not ask
	 * `GET /api/v1/global/account/info` before rendering. Same cached actor and
	 * export format as that reply. On failure the page asks itself.
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
	 * The first page of the home timeline, handed to the page so the first
	 * screenful needs no second round trip after the JavaScript loads.
	 *
	 * Only when the home timeline itself was asked for, without a cursor. The
	 * reader's keyword filters are applied by `FilterService::apply()`, which
	 * also exports the posts, so this is what `/api/v1/timelines/home` answers.
	 * On failure the page asks itself.
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

			$posts = $this->streamService->getTimeline($options);
			$posts = $this->feedDisplayService->filterBlueskyHomePosts($posts, $this->userId, $viewer, $this->followService);
			if ($this->feedDisplayService->hidesReplies($this->userId)) {
				$posts = array_values(array_filter($posts, static fn ($post): bool => $post->getInReplyTo() === ''));
			}
			$this->initialState->provideInitialState(
				'firstPage', $this->filterService->apply($posts, Filter::CONTEXT_HOME, $viewer)
			);
		} catch (Exception $e) {
			$this->logger->debug('[NavigationController] no first page to hand the page', [
				'userId' => $this->userId,
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Whether this address is the home timeline: the app's root or
	 * `timeline/home`.
	 */
	private function isHomeTimeline(string $path): bool {
		$path = trim($path, '/');

		return $path === '' || $path === 'timeline' || $path === 'timeline/home';
	}

	/** The host accounts are addressed under, or '' before the app is set up. */
	private function socialAddress(): string {
		try {
			return $this->configService->getSocialAddress();
		} catch (SocialAppConfigException $e) {
			return '';
		}
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
	 * The app page, for a timeline; signed-in timelines send a visitor to the
	 * login.
	 *
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/timeline/{path}', requirements: ['path' => '.+'], defaults: ['path' => ''])]
	public function timeline(string $path = ''): TemplateResponse|RedirectResponse {
		// one route for every timeline, so the ones that are the reader's own
		// send a visitor to the login here rather than by the route
		if ($this->userId === null && in_array(explode('/', trim($path, '/'))[0], self::SIGNED_IN_TIMELINES, true)) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm', [
				'redirect_url' => $this->request->getRequestUri(),
			]));
		}

		return $this->navigate();
	}

	/**
	 * The app page.
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
	 * The Social actor of the session, when there is one: a session without a
	 * Social account, or none, sees only public copies.
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

			return $this->storedFile($file, $mime);
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

			$response = $this->storedFile($file, $mime);
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

			return $this->storedFile($file, $mime);
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
			$response = $this->storedFile($file, $mime);
			$this->cacheMedia($response);

			return $response;
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	private function storedFile(ISimpleFile $file, string $mime): FileDisplayResponse {
		$response = new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mime]);
		RangedFileResponse::guardStoredFile($response, $mime);

		return $response;
	}

	/**
	 * A day, publicly cacheable, not immutable: a remote avatar is somebody
	 * else's URL and may change.
	 */
	private function cacheMedia(Response $response): void {
		$response->cacheFor(86400, true, false);
	}
}
