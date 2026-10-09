<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\AP;
use OCA\Social\Controller\ApiController;
use OCA\Social\Controller\MastodonApiController;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\FederationDeliveryException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\TranslationUnavailableException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\ScheduledStatus;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Client\Translation;
use OCA\Social\Model\CustomEmoji;
use OCA\Social\Model\Gif;
use OCA\Social\Model\HeldPost;
use OCA\Social\Model\Instance;
use OCA\Social\Model\Post;
use OCA\Social\Model\Relationship;
use OCA\Social\Model\Report;
use OCA\Social\Response\RangedFileResponse;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActionService;
use OCA\Social\Service\AdminApiService;
use OCA\Social\Service\AnnualReportService;
use OCA\Social\Service\AvatarService;
use OCA\Social\Service\BannerService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DeliveryService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\FileCommentsService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\GifService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\MarkerService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\MultipartBodyService;
use OCA\Social\Service\NotificationPolicyService;
use OCA\Social\Service\NotificationService;
use OCA\Social\Service\PinService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\PostReviewService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\QuoteService;
use OCA\Social\Service\ReactionService;
use OCA\Social\Service\ReactionSummaryService;
use OCA\Social\Service\RelationshipService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\ScheduledStatusService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\TeamService;
use OCA\Social\Service\TimelineRevisionService;
use OCA\Social\Service\TranslationService;
use OCA\Social\Service\ViewCountService;
use OCA\Social\Tests\Helper\ApiControllerRouter;
use OCA\Social\Tests\Mock\TCacheActorServiceMock;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\ISession;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
class ApiControllerTest extends TestCase {
	use TCacheActorServiceMock;

	private const REVOKED = 'the access_token was revoked';

	/** @var IRequest&Stub */
	private $request;
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var IUserSession&Stub */
	private $userSession;
	/** @var InstanceService&MockObject */
	private $instanceService;
	/** @var ClientService&MockObject */
	private $clientService;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var CacheDocumentService&MockObject */
	private $cacheDocumentService;
	/** @var DocumentService&MockObject */
	private $documentService;
	/** @var FollowService&MockObject */
	private $followService;
	/** @var RelationshipService&MockObject */
	private $relationshipService;
	/** @var StreamService&MockObject */
	private $streamService;
	/** @var ActionService&MockObject */
	private $actionService;
	/** @var PostService&MockObject */
	private $postService;
	/** @var PollService&Stub */
	private $pollService;
	/** @var PinService&MockObject */
	private $session;
	private $pinService;
	private MarkerService|MockObject $markerService;
	private StreamRequest|MockObject $streamRequest;
	/** @var HashtagService&MockObject */
	private $hashtagService;
	/** @var ReportService&MockObject */
	private $reportService;
	/** @var SearchService&MockObject */
	private $searchService;
	/** @var ConfigService&Stub */
	private $configService;
	/** @var CurlService&MockObject */
	private $curlService;
	private CacheDocumentsRequest|MockObject $cacheDocumentsRequest;
	private AccountRelationService|Stub $accountRelationService;
	private ScheduledStatusService|MockObject $scheduledStatusService;
	private PostReviewService|MockObject $postReviewService;
	private ModerationService|MockObject $moderationService;
	private \OCA\Social\Service\SensitiveMediaService|Stub $sensitiveMediaService;
	private \OCA\Social\Service\NotificationDeliveryService|Stub $notificationDeliveryService;
	private \OCA\Social\Service\AiContentService|Stub $aiContentService;
	private \OCA\Social\Service\CountsService|Stub $countsService;
	private \OCA\Social\Service\RemoteFetchQueue|MockObject $remoteFetchQueue;
	/** what the AI switch stub answers for the viewer */
	private bool $hidesAi = false;
	private bool $hidesCounts = true;
	/** @var array<string, mixed> what the delivery service answers for the viewer */
	private array $delivery = [];
	private ViewCountService|Stub $viewCountService;
	private TeamService|Stub $teamService;
	private EmojiService|Stub $emojiService;
	private PlaceService|Stub $placeService;
	private DeliveryService|MockObject $deliveryService;
	private ReactionService|Stub $reactionService;
	private ReactionSummaryService|Stub $reactionSummaryService;
	private GifService|Stub $gifService;
	private FileCommentsService|MockObject $fileCommentsService;
	private NotificationService|Stub $notificationService;
	private TranslationService|MockObject $translationService;
	private NotificationPolicyService|Stub $notificationPolicyService;
	private IFactory|Stub $l10nFactory;
	private QuoteService|MockObject $quoteService;
	private AnnualReportService|MockObject $annualReportService;
	private TimelineRevisionService|Stub $timelineRevisionService;
	private IAppManager|Stub $appManager;
	private FediverseService|Stub $fediverseService;

	/** How this instance reads its access list, and what is on it. */
	private string $accessType = 'all_but';
	/** @var array<string, string> the app values the routes under test read */
	private array $appValues = [];
	/** @var string[] */
	private array $blockedInstances = [];

	/** Whether the server has a sign-up app of its own. */
	private bool $registrationApp = false;
	private BannerService|MockObject $bannerService;
	private AvatarService|MockObject $avatarService;
	private MultipartBodyService|MockObject $multipartBodyService;
	private AdminApiService|MockObject $adminApiService;
	private FilterService|Stub $filterService;
	private IRootFolder|MockObject $rootFolder;
	private ITempManager|Stub $tempManager;

	/** the table behind the durable cache, shared by every controller one test makes */
	private ?\OCA\Social\Tests\Helper\InMemoryDurableCacheRequest $durableCacheRequest = null;

	private array $filesBackup;
	/** temporary files a test made, removed in tearDown */
	private array $tempFiles = [];
	/** the value getParam('_route') hands the controller, per test */
	private string $route = '';
	/** the value getParam('act') hands the controller, per test */
	private string $act = '';
	/** what passesCSRFCheck() reports, per test */
	private bool $csrf = true;
	/** the HTTP verb the request reports, per test */
	private string $verb = 'GET';
	/** the value getParam('description') hands the controller, per test */
	private string $description = '';
	/** the value getParam('path') hands the controller, per test */
	private string $pathParam = '';
	/** the request headers the controller under construction will see */
	private array $headers = [];

	protected function setUp(): void {
		$this->filesBackup = $_FILES;
		$_FILES = [];

		$this->request = $this->createStub(IRequest::class);
		$this->headers = [];
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		// the app's own frontend sends the requesttoken header on every call
		$this->csrf = true;
		$this->verb = 'GET';
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getMethod')->willReturnCallback(fn (): string => $this->verb);
		$this->route = '';
		$this->act = '';
		$this->pathParam = '';
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => match ($key) {
				'_route' => $this->route,
				'act' => ($this->act === '') ? $default : $this->act,
				'description' => ($this->description === '') ? $default : $this->description,
				'path' => ($this->pathParam === '') ? $default : $this->pathParam,
				default => $default,
			}
		);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userSession = $this->createStub(IUserSession::class);
		$this->instanceService = $this->createMock(InstanceService::class);
		$this->clientService = $this->createMock(ClientService::class);
		$this->accountService = $this->createMock(AccountService::class);
		// what an account posts with when the client names no visibility
		$this->accountService->method('getDefaultPrivacy')->willReturnCallback(
			fn (): string => $this->defaultPrivacy
		);
		// the profile parts are written inside it, as the real one does
		$this->accountService->method('changingProfile')->willReturnCallback(
			static fn (string $userId, callable $changes): mixed => $changes()
		);
		$this->cacheActorService = $this->cacheActorServiceMock();
		$this->cacheDocumentService = $this->createMock(CacheDocumentService::class);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->followService = $this->createMock(FollowService::class);
		$this->relationshipService = $this->createMock(RelationshipService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->actionService = $this->createMock(ActionService::class);
		$this->postService = $this->createMock(PostService::class);
		$this->pollService = $this->createStub(PollService::class);
		$this->markerService = $this->createMock(MarkerService::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->session = $this->createMock(ISession::class);
		$this->pinService = $this->createMock(PinService::class);
		$this->hashtagService = $this->createMock(HashtagService::class);
		$this->reportService = $this->createMock(ReportService::class);
		$this->searchService = $this->createMock(SearchService::class);
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValue')->willReturnCallback(
			fn (string $key): string => $this->appValues[$key] ?? ''
		);
		$this->curlService = $this->createMock(CurlService::class);
		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);
		$this->instanceService->method('maxUploadSize')->willReturn(10 * 1048576);
		// what a client is told: the app's ceiling under PHP's 2 MB
		$this->instanceService->method('imageSizeLimit')->willReturn(2 * 1048576);
		$this->instanceService->method('videoSizeLimit')->willReturn(2 * 1048576);
		$this->instanceService->method('phpUploadLimit')->willReturn(2 * 1048576);
		$this->instanceService->method('postMaxSize')->willReturn(8 * 1048576);

		// a pass-through: these tests are about the routes, not about filtering,
		// and a filter that removed anything would rewrite what they assert
		$this->accountRelationService = $this->createStub(AccountRelationService::class);
		$this->scheduledStatusService = $this->createMock(ScheduledStatusService::class);
		$this->postReviewService = $this->createMock(PostReviewService::class);
		$this->moderationService = $this->createMock(ModerationService::class);
		$this->notificationDeliveryService = $this->createStub(\OCA\Social\Service\NotificationDeliveryService::class);
		$this->notificationDeliveryService->method('of')->willReturnCallback(
			fn (): \OCA\Social\Model\NotificationDelivery
				=> \OCA\Social\Model\NotificationDelivery::fromArray($this->delivery)
		);
		$this->aiContentService = $this->createStub(\OCA\Social\Service\AiContentService::class);
		$this->aiContentService->method('hides')->willReturnCallback(fn (string $userId): bool => $this->hidesAi);
		$this->remoteFetchQueue = $this->createMock(\OCA\Social\Service\RemoteFetchQueue::class);
		$this->countsService = $this->createStub(\OCA\Social\Service\CountsService::class);
		$this->countsService->method('hides')->willReturnCallback(fn (string $userId): bool => $this->hidesCounts);
		$this->sensitiveMediaService = $this->createStub(\OCA\Social\Service\SensitiveMediaService::class);
		// the three states PeerTube's NSFW policies map onto; `default` is
		// what an instance that has not chosen does
		$this->sensitiveMediaService->method('policyFor')->willReturn('default');
		$this->sensitiveMediaService->method('instancePolicy')->willReturn('default');
		$this->viewCountService = $this->createStub(ViewCountService::class);
		$this->teamService = $this->createStub(TeamService::class);
		$this->emojiService = $this->createStub(EmojiService::class);
		$this->placeService = $this->createStub(PlaceService::class);
		$this->deliveryService = $this->createMock(DeliveryService::class);
		$this->reactionService = $this->createStub(ReactionService::class);
		$this->reactionSummaryService = $this->createStub(ReactionSummaryService::class);
		$this->gifService = $this->createStub(GifService::class);
		$this->fileCommentsService = $this->createMock(FileCommentsService::class);
		$this->notificationService = $this->createStub(NotificationService::class);
		$this->translationService = $this->createMock(TranslationService::class);
		$this->notificationPolicyService = $this->createStub(NotificationPolicyService::class);
		$this->notificationPolicyService->method('partition')
			->willReturnCallback(static fn (Person $viewer, array $page): array
				=> ['shown' => $page, 'held' => []]);
		$this->notificationPolicyService->method('of')->willReturn(new \OCA\Social\Model\Client\NotificationPolicy());
		$this->l10nFactory = $this->createStub(IFactory::class);
		$this->quoteService = $this->createMock(QuoteService::class);
		$this->annualReportService = $this->createMock(AnnualReportService::class);
		$this->timelineRevisionService = $this->createStub(TimelineRevisionService::class);
		$this->appManager = $this->createStub(IAppManager::class);
		$this->fediverseService = $this->createStub(FediverseService::class);
		$this->fediverseService->method('getAccessType')->willReturnCallback(fn (): string => $this->accessType);
		$this->fediverseService->method('getListedAddresses')->willReturnCallback(fn (): array => $this->blockedInstances);
		$this->appManager->method('isEnabledForUser')->willReturnCallback(
			fn (string $app): bool => $app === 'registration' && $this->registrationApp
		);
		$this->accountRelationService->method('withoutExpiredMutes')->willReturnArgument(1);
		$this->bannerService = $this->createMock(BannerService::class);
		$this->avatarService = $this->createMock(AvatarService::class);
		$this->multipartBodyService = $this->createMock(MultipartBodyService::class);
		$this->adminApiService = $this->createMock(AdminApiService::class);
		$this->filterService = $this->createStub(FilterService::class);
		$this->filterService->method('apply')->willReturnArgument(0);
		$this->filterService->method('applyToNotifications')->willReturnArgument(0);
		$this->filterService->method('applyToStatus')->willReturnArgument(0);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->tempManager = $this->createStub(ITempManager::class);

		\OC::$server->register(IRequest::class, $this->request);
		// the Account export builds placeholder pictures through the container
		\OC::$server->register(IURLGenerator::class, $this->urlGenerator);
		// Response::cacheFor() stamps an Expires header from the clock
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn(1700000000);
		\OC::$server->register(ITimeFactory::class, $clock);
	}

	protected function tearDown(): void {
		$_FILES = $this->filesBackup;
		foreach ($this->tempFiles as $tmp) {
			if (is_file($tmp)) {
				unlink($tmp);
			}
		}
		$this->tempFiles = [];
		AP::set(null);
		\OC::$server->reset();
	}

	private function controller(string $authorization = ''): ApiControllerRouter {
		return $this->controllerWithHeaders($authorization);
	}

	/** @param array<string, string> $headers extra request headers */
	private function controllerWithHeaders(
		string $authorization,
		array $headers = [],
		?LoggerInterface $logger = null,
	): ApiControllerRouter {
		// the callback is registered once, in setUp(): a second method() on the
		// same mock never wins over the first, so per-controller headers have to
		// go through a property
		$this->headers = array_merge(['Authorization' => $authorization], $headers);

		return new ApiControllerRouter([
			'request' => $this->request,
			'urlGenerator' => $this->urlGenerator,
			'userSession' => $this->userSession,
			'logger' => $logger ?? new NullLogger(),
			'instanceService' => $this->instanceService,
			'atprotoIdentities' => (function () {
				$identities = $this->createStub(\OCA\Social\Atproto\Identity\IdentityService::class);
				$identities->method('getByActorId')->willThrowException(new \OCA\Social\Exceptions\AtprotoIdentityNotFoundException());

				return $identities;
			})(),
			'clientService' => $this->clientService,
			'accountService' => $this->accountService,
			'cacheActorService' => $this->cacheActorService,
			'cacheDocumentService' => $this->cacheDocumentService,
			'documentService' => $this->documentService,
			'followService' => $this->followService,
			'relationshipService' => $this->relationshipService,
			'streamService' => $this->streamService,
			'actionService' => $this->actionService,
			'postService' => $this->postService,
			'pollService' => $this->pollService,
			'session' => $this->session,
			'pinService' => $this->pinService,
			'hashtagService' => $this->hashtagService,
			'markerService' => $this->markerService,
			'streamRequest' => $this->streamRequest,
			'reportService' => $this->reportService,
			'searchService' => $this->searchService,
			'configService' => $this->configService,
			'curlService' => $this->curlService,
			'cacheDocumentsRequest' => $this->cacheDocumentsRequest,
			'rootFolder' => $this->rootFolder,
			'tempManager' => $this->tempManager,
			'filterService' => $this->filterService,
			'bannerService' => $this->bannerService,
			'avatarService' => $this->avatarService,
			'multipartBodyService' => $this->multipartBodyService,
			'adminApiService' => $this->adminApiService,
			'accountRelationService' => $this->accountRelationService,
			'scheduledStatusService' => $this->scheduledStatusService,
			'postReviewService' => $this->postReviewService,
			'moderationService' => $this->moderationService,
			'sensitiveMediaService' => $this->sensitiveMediaService,
			'notificationDeliveryService' => $this->notificationDeliveryService,
			'aiContentService' => $this->aiContentService,
			'countsService' => $this->countsService,
			'remoteFetchQueue' => $this->remoteFetchQueue,
			'viewCountService' => $this->viewCountService,
			'teamService' => $this->teamService,
			'emojiService' => $this->emojiService,
			'appManager' => $this->appManager,
			'fediverseService' => $this->fediverseService,
			'placeService' => $this->placeService,
			'deliveryService' => $this->deliveryService,
			'reactionService' => $this->reactionService,
			'reactionSummaryService' => $this->reactionSummaryService,
			'gifService' => $this->gifService,
			'fileCommentsService' => $this->fileCommentsService,
			'notificationService' => $this->notificationService,
			'translationService' => $this->translationService,
			'notificationPolicyService' => $this->notificationPolicyService,
			'quoteService' => $this->quoteService,
			'annualReportService' => $this->annualReportService,
			'watchService' => $this->createMock(\OCA\Social\Service\WatchService::class),
			'l10nFactory' => $this->l10nFactory,
			'timelineRevisionService' => $this->timelineRevisionService,
			// the real one over the doubles above, so the badge is counted by
			// the query these tests assert on
			'notificationInboxService' => new \OCA\Social\Service\NotificationInboxService(
				$this->streamService,
				$this->streamRequest,
				$this->notificationPolicyService,
				$this->markerService,
				$this->createStub(\OCA\Social\Db\ConversationsRequest::class),
			),
			'durableCache' => $this->durableCache(),
			'atprotoPublisher' => (function () {
				$publisher = $this->createStub(\OCA\Social\Atproto\Publisher\Publisher::class);
				$publisher->method('statusOf')->willReturn(['state' => 'not_applicable', 'uri' => '', 'url' => '']);

				return $publisher;
			})(),
			'replyRules' => $this->createMock(\OCA\Social\Service\ReplyRuleService::class),
		]);
	}

	/**
	 * A session user "alice" whose actor is cached: what initViewer() needs.
	 * @return Person&MockObject
	 */
	/** the stored `source.privacy` of the logged-in account */
	private string $defaultPrivacy = 'public';
	/** @var array<string, mixed> what the viewer's `source` half answers */
	private array $viewerSource = ['privacy' => 'public', 'follow_requests_count' => 2];

	/** What the last timeline query read, when a test wants it to differ from the page. */
	private ?int $timelineRows = null;

	private function loggedInAs(string $uid = 'alice'): Person {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

		$account = $this->createStub(Person::class);
		$account->method('getPreferredUsername')->willReturn($uid);
		$account->method('getId')->willReturn('https://cloud.example/apps/social/@' . $uid);
		$this->accountService->method('getActorFromUserId')->with($uid)->willReturn($account);

		$viewer = $this->createMock(Person::class);
		$viewer->method('getPreferredUsername')->willReturn($uid);
		$viewer->method('getId')->willReturn('https://cloud.example/apps/social/@' . $uid);
		// the credentials routes answer with the serialised entity plus `source`,
		// which they ask the model for separately — everywhere else must not
		// have it, see testSourceIsNotPartOfAnOrdinaryAccountEntity
		$viewer->method('jsonSerialize')->willReturn(['id' => '7', 'username' => $uid]);
		// a property rather than a fixed value: a second method() on the same
		// mock never wins over the first, so a test that wants a different
		// source sets this instead
		$viewer->method('exportSourceAsLocal')->willReturnCallback(fn (): array => $this->viewerSource);
		$this->cacheActorService->method('getFromLocalAccount')->with($uid)->willReturn($viewer);

		return $viewer;
	}

	private function assertUnauthorized(DataResponse $response, string $error = self::REVOKED): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => $error], $response->getData());
		$this->assertSame(
			'Bearer error="invalid_token"',
			$response->getHeaders()['WWW-Authenticate'] ?? null,
			'a 401 says what kind of credential it wanted'
		);
	}

	/** A token whose grant does not cover the route: 403, not 401. */
	private function assertInsufficientScope(DataResponse $response, string $error): void {
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => $error], $response->getData());
		$this->assertArrayNotHasKey('WWW-Authenticate', $response->getHeaders());
	}

	private function assertNotFound(DataResponse $response, string $error): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => $error], $response->getData());
	}

	private function assertUnprocessable(DataResponse $response, string $error): void {
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => $error], $response->getData());
	}

	/** An unexpected failure: 500, and nothing of the exception on the wire. */
	private function assertServerError(DataResponse $response): void {
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'internal server error'], $response->getData());
	}

	/**
	 * Captures the ProbeOptions handed to StreamService::getTimeline().
	 */
	private function captureTimelineOptions(array $posts = []): \Closure {
		$captured = null;
		$this->streamService->method('getTimeline')
			->willReturnCallback(function (ProbeOptions $options) use (&$captured, $posts): array {
				$captured = $options;

				return $posts;
			});
		// how many rows the query read: the page itself, unless a test says the
		// page lost a boost of a post already in it
		$this->streamService->method('lastTimelineRowCount')
			->willReturnCallback(fn (): int => $this->timelineRows ?? count($posts));

		return function () use (&$captured): ProbeOptions {
			$this->assertInstanceOf(ProbeOptions::class, $captured, 'getTimeline() was not called');

			return $captured;
		};
	}

	/**
	 * Every route here is reached with a bearer token and no session, so each
	 * one has to declare that itself. Declaring it as a docblock annotation
	 * reads the same and is not the same: Nextcloud stopped honouring the
	 * annotation form, and a route that lost its `@PublicPage` that way answers
	 * a client with the server's own `{"message": ""}` 401 — from the security
	 * middleware, before this controller runs at all.
	 */
	public function testEveryRouteDeclaresItsAccessAsAnAttribute(): void {
		foreach (self::apiMethods() as $method) {
			if ($method->isConstructor()) {
				continue;
			}

			$attributes = array_map(
				static fn (\ReflectionAttribute $a): string => $a->getName(),
				$method->getAttributes()
			);
			$this->assertContains(
				\OCP\AppFramework\Http\Attribute\PublicPage::class,
				$attributes,
				$method->getName() . '() does not declare #[PublicPage]'
			);
			$this->assertStringNotContainsString(
				'@PublicPage',
				(string)$method->getDocComment(),
				$method->getName() . '() declares its access as a legacy annotation'
			);
			$this->assertStringNotContainsString(
				'@NoCSRFRequired',
				(string)$method->getDocComment(),
				$method->getName() . '() declares its access as a legacy annotation'
			);
		}
	}

	// credentials

	public function testAppsCredentialsRequiresAViewer(): void {
		$this->assertUnauthorized($this->controller()->appsCredentials());
	}

	public function testAppsCredentialsForSessionUserIsTheBuiltInClient(): void {
		$this->loggedInAs();

		$response = $this->controller()->appsCredentials();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'name' => 'Aloha Social',
				'website' => 'https://github.com/alohasocial/social/',
				'vapid_key' => '',
			],
			$response->getData()
		);
	}

	public function testAppsCredentialsForBearerTokenDescribesTheOAuthClient(): void {
		$this->route = 'social.Api.appsCredentials';
		$client = new SocialClient();
		$client->setAppName('Tusky')->setAppWebsite('https://tusky.app')->setAuthUserId('alice');
		$this->clientService->method('getFromToken')->with('s3cret')->willReturn($client);
		$this->userSession->method('getUser')->willReturn(null);

		$account = $this->createStub(Person::class);
		$account->method('getPreferredUsername')->willReturn('alice');
		$this->accountService->method('getActorFromUserId')->with('alice')->willReturn($account);
		$this->cacheActorService->method('getFromLocalAccount')->with('alice')->willReturn($this->createStub(Person::class));

		$response = $this->controller('Bearer s3cret')->appsCredentials();

		$this->assertSame(
			['name' => 'Tusky', 'website' => 'https://tusky.app', 'vapid_key' => ''],
			$response->getData()
		);
	}

	/** A bearer client for alice, granted the given scopes. */
	private function bearerFor(array $scopes, string $uid = 'alice'): void {
		$client = new SocialClient();
		$client->setAppName('Tusky')->setAuthUserId($uid)->setAuthScopes($scopes);
		$this->clientService->method('getFromToken')->with('s3cret')->willReturn($client);

		$account = $this->createStub(Person::class);
		$account->method('getPreferredUsername')->willReturn($uid);
		$account->method('getId')->willReturn('https://cloud.example/apps/social/@' . $uid);
		$this->accountService->method('getActorFromUserId')->with($uid)->willReturn($account);

		$viewer = $this->createStub(Person::class);
		$viewer->method('getPreferredUsername')->willReturn($uid);
		$viewer->method('getId')->willReturn('https://cloud.example/apps/social/@' . $uid);
		$this->cacheActorService->method('getFromLocalAccount')->with($uid)->willReturn($viewer);
	}

	// markers and the unread badge

	public function testTheUnreadCountIsWhatArrivedSinceTheMarker(): void {
		$this->loggedInAs();
		$this->markerService->method('lastReadId')->with('alice', 'notifications')->willReturn('42');
		$this->streamRequest->expects($this->once())
			->method('countNotificationsSince')
			->with($this->anything(), $this->identicalTo('42'))
			->willReturn(7);

		$response = $this->controller()->notificationsUnreadCount();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['count' => 7], $response->getData());
	}

	public function testMarkersComeBackForTheTimelinesAskedFor(): void {
		$this->loggedInAs();
		$this->markerService->expects($this->once())->method('get')
			->with('alice', ['notifications'])
			->willReturn(['notifications' => ['last_read_id' => '42', 'version' => 1, 'updated_at' => 'now']]);

		$response = $this->controller()->markersGet(['notifications']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertInstanceOf(\stdClass::class, $response->getData(), 'markers are an object');
		$this->assertTrue(isset($response->getData()->notifications));
	}

	public function testMarkersOfAFreshAccountAreAnEmptyObjectNotAnEmptyList(): void {
		$this->loggedInAs();
		$this->markerService->method('get')->willReturn([]);

		$data = $this->controller()->markersGet()->getData();

		$this->assertInstanceOf(\stdClass::class, $data);
		$this->assertSame('{}', json_encode($data), 'an empty list is not a marker map');
	}

	public function testSettingAMarkerMovesOnlyTheTimelinesInTheBody(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn([
			'notifications' => ['last_read_id' => '42'],
		]);

		$moved = [];
		$this->markerService->method('set')->willReturnCallback(
			function (string $user, string $timeline, string $id) use (&$moved): array {
				$moved[$timeline] = $id;

				return ['last_read_id' => $id, 'version' => 1, 'updated_at' => 'now'];
			}
		);

		$response = $this->controller()->markersSet();

		$this->assertSame(['notifications' => '42'], $moved, 'home was not in the body');
		$this->assertSame(['notifications'], array_keys((array)$response->getData()));
	}

	public function testSettingAMarkerNeedsAWriteToken(): void {
		$this->route = 'social.TimelineApi.markersSet';
		$this->bearerFor(['read']);

		$this->assertInsufficientScope(
			$this->controller('Bearer s3cret')->markersSet(),
			'token scope does not allow this request (needs write:statuses)'
		);
	}

	public function testReadingMarkersIsSatisfiedByAReadToken(): void {
		$this->route = 'social.TimelineApi.markersGet';
		$this->bearerFor(['read']);
		$this->markerService->method('get')->willReturn([]);

		$this->assertSame(
			Http::STATUS_OK, $this->controller('Bearer s3cret')->markersGet()->getStatus()
		);
	}

	// token scopes

	public function testAWriteRouteRefusesAReadOnlyToken(): void {
		$this->route = 'social.StatusApi.statusNew';
		$this->bearerFor(['read']);
		$this->postService->expects($this->never())->method('createPost');

		$response = $this->controller('Bearer s3cret')->statusNew();

		$this->assertInsufficientScope(
			$response, 'token scope does not allow this request (needs write:statuses)'
		);
	}

	public function testABlockRouteRefusesAReadOnlyToken(): void {
		$this->route = 'social.AccountApi.accountMute';
		$this->bearerFor(['read']);

		$this->assertInsufficientScope(
			$this->controller('Bearer s3cret')->accountMute('42'),
			'token scope does not allow this request (needs write:mutes or follow)'
		);
	}

	public function testAReadRouteRefusesAScopelessToken(): void {
		$this->route = 'social.AccountApi.verifyCredentials';
		$this->bearerFor([]);

		$this->assertInsufficientScope(
			$this->controller('Bearer s3cret')->verifyCredentials(),
			'token scope does not allow this request (needs read:accounts)'
		);
	}

	/**
	 * The public methods of every client API controller, each where it is
	 * declared.
	 *
	 * @return list<ReflectionMethod>
	 */
	private static function apiMethods(): array {
		$methods = [];
		foreach (ApiControllerRouter::CONTROLLERS as $class) {
			foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() === $class) {
					$methods[] = $method;
				}
			}
		}

		return $methods;
	}

	/**
	 * Every route of the client API, as its own attributes declare it.
	 *
	 * @return array<string, string> method name => HTTP verb
	 */
	private static function declaredRoutes(): array {
		$routes = [];
		foreach (self::apiMethods() as $method) {
			foreach ($method->getAttributes() as $attribute) {
				if (!in_array($attribute->getName(), [FrontpageRoute::class, ApiRoute::class], true)) {
					continue;
				}

				$routes[$method->getName()] = strtoupper((string)$attribute->getArguments()['verb']);
			}
		}

		return $routes;
	}

	/**
	 * The client API's routes are the contract every Mastodon client is written
	 * against; `/api/v1/instance` is the first request one makes, and several
	 * of the media routes are written into the ActivityPub documents this
	 * server publishes.
	 *
	 * The API is split across several controllers, so a method that lost its
	 * attributes in a move, or a controller missing from
	 * `ApiControllerRouter::CONTROLLERS`, would take its route with it and
	 * nothing else here would notice: the other route tests walk whatever
	 * exists rather than checking that anything does.
	 */
	public function testTheInstanceAndMediaRoutesAreStillDeclared(): void {
		$declared = self::declaredRoutes();

		foreach ([
			// the instance describes itself: the first request a client makes
			'instance' => 'GET',
			'instanceV2' => 'GET',
			'instanceRules' => 'GET',
			'instanceDomainBlocks' => 'GET',
			'instanceExtendedDescription' => 'GET',
			'instancePrivacyPolicy' => 'GET',
			'instanceTermsOfService' => 'GET',
			'instanceTranslationLanguages' => 'GET',
			'oembed' => 'GET',
			// and the bytes
			'mediaNew' => 'POST',
			'mediaNewV2' => 'POST',
			'mediaFromFile' => 'POST',
			'mediaGet' => 'GET',
			'mediaUpdate' => 'PUT',
			'mediaOpen' => 'GET',
			'mediaStream' => 'GET',
			'mediaPlaylist' => 'GET',
			'mediaPlaylistFile' => 'GET',
			'mediaLadder' => 'GET',
			'mediaLadderRung' => 'GET',
			'mediaLadderFile' => 'GET',
			'statusWatched' => 'POST',
			'statusUnwatched' => 'DELETE',
			'videosContinue' => 'GET',
		] as $route => $verb) {
			$this->assertArrayHasKey(
				$route,
				$declared,
				$route . '() no longer declares a route; every URL built from it is now a 404'
			);
			$this->assertSame($verb, $declared[$route], $route . '() answers a different verb');
		}
	}

	/**
	 * The scopes the controller resolves for one route, without going through a
	 * request.
	 *
	 * @return string[]
	 */
	private function scopesFor(string $route, string $verb, string $act = ''): array {
		$resolve = new ReflectionMethod(MastodonApiController::class, 'scopesForRoute');

		return $resolve->invoke($this->controller()->controller(ApiController::class), $route, $verb, $act);
	}

	/**
	 * The table used to enumerate the *writes* and default everything else to
	 * `read`, so every state-changing route added afterwards — deleting an
	 * avatar, reacting to a post, rewriting preferences, generating an annual
	 * report — was open to a read-only token until somebody remembered to list
	 * it. This walks the routes the controller declares rather than a list kept
	 * beside them, so a route added tomorrow is covered by it.
	 */
	public function testNoStateChangingRouteIsSatisfiedByAReadScope(): void {
		// the one POST Mastodon documents behind a read scope: it answers with
		// a post the caller may already read, in another language
		$readByDesign = ['statusTranslate'];
		$checked = 0;

		foreach (self::declaredRoutes() as $route => $verb) {
			if ($verb === 'GET') {
				continue;
			}

			$scopes = $this->scopesFor($route, $verb);
			$this->assertNotSame([], $scopes, $route . ' asks a token for nothing at all');
			$this->assertNotSame(['read'], $scopes, $route . ' is satisfied by a read-only token');

			if (!in_array($route, $readByDesign, true)) {
				foreach ($scopes as $scope) {
					$broad = strstr($scope, ':', true);
					$this->assertContains(
						($broad === false) ? $scope : $broad,
						['write', 'follow'],
						$route . ' is a ' . $verb . ' route resolving to ' . $scope
					);
				}
			}

			$checked++;
		}

		$this->assertGreaterThan(30, $checked, 'the route walk found the routes');
	}

	/**
	 * A granular scope is satisfied by itself or by the broad scope that
	 * contains it, and by nothing else. The controller used to accept any
	 * granular variant of the scope a route asked for, so `write:favourites`
	 * was permission to post, delete and rewrite the profile — none of which
	 * the consent screen had named.
	 */
	public function testOneGranularScopeIsNotPermissionForAnother(): void {
		$this->route = 'social.StatusApi.statusNew';
		$this->bearerFor(['write:favourites']);
		$this->postService->expects($this->never())->method('createPost');

		$this->assertInsufficientScope(
			$this->controller('Bearer s3cret')->statusNew(),
			'token scope does not allow this request (needs write:statuses)'
		);
	}

	public function testOneGranularReadScopeIsNotPermissionToReadSomethingElse(): void {
		$this->route = 'social.TimelineApi.notifications';
		$this->bearerFor(['read:lists']);

		$this->assertInsufficientScope(
			$this->controller('Bearer s3cret')->notifications(),
			'token scope does not allow this request (needs read:notifications)'
		);
	}

	/**
	 * The catch-all status route is several permissions behind one name:
	 * favouriting is not pinning to a profile, and neither is muting a
	 * conversation.
	 */
	public function testTheCatchAllStatusRouteAsksForWhatTheActionReallyIs(): void {
		$this->assertSame(['write:favourites'], $this->scopesFor('statusAction', 'POST', 'favourite'));
		$this->assertSame(['write:bookmarks'], $this->scopesFor('statusAction', 'POST', 'bookmark'));
		$this->assertSame(['write:accounts'], $this->scopesFor('statusAction', 'POST', 'pin'));
		$this->assertSame(['write:mutes'], $this->scopesFor('statusAction', 'POST', 'mute'));
		$this->assertSame(['write:statuses'], $this->scopesFor('statusAction', 'POST', 'reblog'));
		$this->assertSame(['write'], $this->scopesFor('statusAction', 'POST', 'nonsense'));
	}

	/** A route nobody listed resolves by its verb, and only a GET reads. */
	public function testAnUnlistedRouteResolvesByItsVerb(): void {
		$this->assertSame(['read'], $this->scopesFor('somethingNobodyListed', 'GET'));
		$this->assertSame(['write'], $this->scopesFor('somethingNobodyListed', 'POST'));
		$this->assertSame(['write'], $this->scopesFor('somethingNobodyListed', 'DELETE'));
	}

	/** A DELETE that a read-only token used to be able to make. */
	public function testDeletingTheProfileAvatarRefusesAReadOnlyToken(): void {
		$this->route = 'social.AccountApi.profileAvatarDelete';
		$this->verb = 'DELETE';
		$this->bearerFor(['read']);

		$this->assertInsufficientScope(
			$this->controller('Bearer s3cret')->profileAvatarDelete(),
			'token scope does not allow this request (needs write:accounts)'
		);
	}

	public function testReactingToAPostRefusesAReadOnlyToken(): void {
		$this->route = 'social.StatusApi.statusReact';
		$this->verb = 'POST';
		$this->bearerFor(['read']);

		$this->assertInsufficientScope(
			$this->controller('Bearer s3cret')->statusReact(1, '👍'),
			'token scope does not allow this request (needs write:favourites)'
		);
	}

	public function testRewritingPreferencesRefusesAReadOnlyToken(): void {
		$this->route = 'social.AccountApi.preferencesUpdate';
		$this->verb = 'PUT';
		$this->bearerFor(['read']);

		$this->assertInsufficientScope(
			$this->controller('Bearer s3cret')->preferencesUpdate(),
			'token scope does not allow this request (needs write:accounts)'
		);
	}

	public function testAGranularWriteScopeSatisfiesAWriteRoute(): void {
		$this->route = 'social.StatusApi.statusNew';
		$this->bearerFor(['read', 'write:statuses']);
		$this->request->method('getParams')->willReturn(['status' => 'hi']);

		$activity = $this->createStub(ACore::class);
		$activity->method('getObjectId')->willReturn('https://cloud.example/apps/social/@alice/n1');
		$this->postService->method('createPost')->willReturn($activity);
		$this->streamService->method('getStreamById')->willReturn($this->createStub(Stream::class));

		$this->assertSame(Http::STATUS_OK, $this->controller('Bearer s3cret')->statusNew()->getStatus());
	}

	// statusNew()

	public function testStatusNewCarriesTheContentWarningToThePost(): void {
		$this->route = 'social.StatusApi.statusNew';
		$this->bearerFor(['read', 'write']);
		$this->request->method('getParams')->willReturn([
			'status' => 'who shot him',
			'spoiler_text' => 'season finale',
		]);

		$created = null;
		$activity = $this->createMock(ACore::class);
		$activity->method('getObjectId')->willReturn('https://cloud.example/apps/social/@alice/n1');
		$this->postService->method('createPost')
			->willReturnCallback(function (Post $post) use (&$created, $activity): ACore {
				$created = $post;

				return $activity;
			});
		$this->streamService->method('getStreamById')->willReturn($this->createStub(Stream::class));

		$this->controller('Bearer s3cret')->statusNew();

		$this->assertSame('season finale', $created->getSpoilerText());
	}

	public function testABearerTokenIsScopedEvenWhenASessionExists(): void {
		// the token's grant must not silently widen to the cookie's full access
		$this->route = 'social.StatusApi.statusNew';
		$this->loggedInAs();
		$this->bearerFor(['read']);
		$this->postService->expects($this->never())->method('createPost');

		$response = $this->controller('Bearer s3cret')->statusNew();

		$this->assertInsufficientScope(
			$response, 'token scope does not allow this request (needs write:statuses)'
		);
	}

	public function testASessionWithoutACsrfTokenIsRefused(): void {
		$this->csrf = false;
		$this->loggedInAs();

		$this->assertUnauthorized($this->controller()->verifyCredentials());
	}

	/**
	 * A request with a missing or invalid token is answered with a 401; it is
	 * not a server-side failure. Logging each one at error with a stack trace
	 * filled the admin's nextcloud.log for every scanner that found the API.
	 */
	public function testAnInvalidTokenIsNotLoggedAsAServerError(): void {
		$this->clientService->method('getFromToken')->willThrowException(new ClientNotFoundException());
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$logger->expects($this->never())->method('warning');

		$response = $this->controllerWithHeaders('Bearer gone', [], $logger)->verifyCredentials();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	/**
	 * Several of these failures are raised with no message at all, and
	 * `{"error": ""}` tells a client nothing about what happened.
	 */
	public function testAFailureWithNoMessageStillSaysSomething(): void {
		$this->loggedInAs();
		$this->streamService->method('getStreamByNid')->willThrowException(new StreamNotFoundException());

		$this->assertNotFound($this->controller()->statusDelete(7), 'not found');
	}

	public function testNonBearerAuthorizationIsIgnored(): void {
		$this->clientService->expects($this->never())->method('getFromToken');

		$this->assertUnauthorized($this->controller('Basic abc')->appsCredentials());
	}

	public function testRevokedBearerTokenIsUnauthorized(): void {
		$this->clientService->method('getFromToken')->willThrowException(new ClientNotFoundException());

		$this->assertUnauthorized($this->controller('Bearer gone')->verifyCredentials());
	}

	public function testVerifyCredentialsReturnsTheViewerInLocalFormat(): void {
		$viewer = $this->loggedInAs();
		$viewer->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);
		$this->streamService->expects($this->once())->method('setViewer')->with($viewer);
		$this->followService->expects($this->once())->method('setViewer')->with($viewer);
		$this->cacheActorService->expects($this->once())->method('setViewer')->with($viewer);

		$response = $this->controller()->verifyCredentials();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('7', $data['id']);
		$this->assertSame('alice', $data['username']);
		// this is the CredentialAccount: the one entity that carries `source`
		$this->assertSame(2, $data['source']['follow_requests_count']);
	}

	/**
	 * `source` is the account's own copy of its settings, and
	 * `follow_requests_count` is how many people are waiting on its approval.
	 * The model used to emit it on every Account, so a search result, a page of
	 * followers or an anonymous profile read carried it too.
	 */
	public function testOnlyTheCredentialsRoutesCarrySource(): void {
		$this->loggedInAs();
		$target = $this->knownTarget();
		// the route hands the model back and lets it serialise itself, so the
		// guarantee has to be that nothing asks the model for its source
		$target->expects($this->never())->method('exportSourceAsLocal');

		$response = $this->controller()->accountGet('42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($target, $response->getData());
	}

	/**
	 * A client decides whether to offer moderation from `role`, before it asks
	 * for an admin scope, so the CredentialAccount carries the viewer's role.
	 */
	public function testVerifyCredentialsCarriesTheViewersRole(): void {
		$this->loggedInAs();
		$role = ['id' => '3', 'name' => 'Admin', 'color' => '', 'permissions' => '8388607', 'highlighted' => true];
		$this->adminApiService->expects($this->once())->method('credentialRole')
			->with('alice')->willReturn($role);

		$response = $this->controller()->verifyCredentials();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($role, $response->getData()['role']);
	}

	/** update_credentials answers a CredentialAccount too, role included. */
	public function testUpdateCredentialsCarriesTheViewersRole(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['locked' => 'true']);
		$role = ['id' => '-99', 'name' => '', 'color' => '', 'permissions' => '65536', 'highlighted' => false];
		$this->adminApiService->method('credentialRole')->with('alice')->willReturn($role);

		$response = $this->controller()->updateCredentials();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($role, $response->getData()['role']);
	}

	public function testVerifyCredentialsIsUnauthorizedForAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertUnauthorized($this->controller()->verifyCredentials());
	}

	/**
	 * Reading the API never mints the account it reads for.
	 *
	 * It used to: the viewer was initialised with the create flag set, so the
	 * first request of the page — before the setup screen had asked anybody
	 * anything — gave the reader a Fediverse identity with a handle derived
	 * from their Nextcloud user id. Answering the screen then failed with
	 * "that handle is taken". A token cannot exist without an account either,
	 * since `/oauth/authorize` looks one up without creating, so nothing is
	 * left that depends on this.
	 */
	public function testTheApiNeverCreatesTheAccountItReadsFor(): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('newcomer');
		$this->userSession->method('getUser')->willReturn($user);

		$asked = [];
		$this->accountService->method('getActorFromUserId')
			->willReturnCallback(function (string $userId, bool $create = false) use (&$asked): Person {
				$asked[] = $create;

				throw new ActorDoesNotExistException('no account for ' . $userId);
			});

		$response = $this->controller()->verifyCredentials();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertNotContains(true, $asked, 'reading asked for the account to be created');
	}

	public function testViewerIsCachedOnDemandWhenMissingFromCache(): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$account = $this->createStub(Person::class);
		$account->method('getPreferredUsername')->willReturn('alice');
		$this->accountService->method('getActorFromUserId')->willReturn($account);

		$viewer = $this->createStub(Person::class);
		$viewer->method('jsonSerialize')->willReturn(['username' => 'alice']);
		$viewer->method('exportSourceAsLocal')->willReturn([]);
		// missing on the first read, there once the cache has been rebuilt
		$reads = 0;
		$this->cacheActorService->method('getFromLocalAccount')->with('alice')
			->willReturnCallback(function () use (&$reads, $viewer): Person {
				if ($reads++ === 0) {
					throw new CacheActorDoesNotExistException();
				}

				return $viewer;
			});
		$this->accountService->expects($this->once())->method('cacheLocalActorByUsername')->with('alice');

		$this->assertSame('alice', $this->controller()->verifyCredentials()->getData()['username']);
	}

	public function testSavedSearchesIsEmptyForAViewerAndUnauthorizedOtherwise(): void {
		$this->assertUnauthorized($this->controller()->savedSearches());

		$this->loggedInAs();
		$response = $this->controller()->savedSearches();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	// instance / emojis

	public function testInstanceReturnsTheLocalInstanceInLocalFormat(): void {
		$instance = new Instance();
		$this->instanceService->expects($this->once())->method('getLocal')->with(Stream::FORMAT_LOCAL)->willReturn($instance);

		$response = $this->controller()->instance();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($instance, $response->getData());
	}

	public function testCustomEmojisIsAnEmptyList(): void {
		$response = $this->controller()->customEmojis();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	// timelines

	/** @return iterable<string, array{string}> */
	public static function supportedTimelines(): iterable {
		yield 'home' => ['home'];
		yield 'account' => ['account'];
		yield 'public' => ['public'];
		yield 'direct' => ['direct'];
		yield 'favourites' => ['favourites'];
		yield 'case-insensitive' => ['HOME'];
	}

	#[DataProvider('supportedTimelines')]
	public function testTimelinesProbesTheRequestedTimelineWithPagination(string $timeline): void {
		$this->loggedInAs();
		$posts = [$this->createMock(Stream::class)];
		$options = $this->captureTimelineOptions($posts);

		$response = $this->controller()->timelines($timeline, true, 15, 300, 100, 200);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($posts, $response->getData());
		$probe = $options();
		$this->assertSame(strtolower($timeline), $probe->getProbe());
		$this->assertTrue($probe->isLocal());
		$this->assertSame(15, $probe->getLimit());
		$this->assertSame(300, $probe->getMaxId());
		$this->assertSame(100, $probe->getMinId());
		$this->assertSame(200, $probe->getSince());
		$this->assertSame(ACore::FORMAT_LOCAL, $probe->getFormat());
	}

	/** @return iterable<string, array{string}> */
	public static function unsupportedTimelines(): iterable {
		yield 'trending' => ['trending'];
		yield 'notifications' => ['notifications'];
		yield 'hashtag' => ['hashtag'];
		yield 'empty' => [''];
	}

	#[DataProvider('unsupportedTimelines')]
	public function testTimelinesRejectsUnknownTimelineNames(string $timeline): void {
		$this->loggedInAs();
		$this->streamService->expects($this->never())->method('getTimeline');

		$this->assertUnprocessable($this->controller()->timelines($timeline), 'unknown timeline');
	}

	public function testTimelinesRequiresAViewer(): void {
		$this->streamService->expects($this->never())->method('getTimeline');

		$this->assertUnauthorized($this->controller()->timelines('home'));
	}

	public function testTimelinesReportsServiceFailuresAsServerErrors(): void {
		$this->loggedInAs();
		$this->streamService->method('getTimeline')->willThrowException(new \RuntimeException('db down'));

		$response = $this->controller()->timelines('home');

		// not a 401: a client reads that as a revoked token and logs the reader
		// out over what is a transient failure on this side
		$this->assertServerError($response);
	}

	// statuses

	public function testStatusGetReturnsTheStreamInLocalFormatEvenAnonymously(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$item = $this->createMock(Stream::class);
		$this->streamService->method('getStreamByNid')->with(42)->willReturn($item);
		// a single status carries its link preview too
		$this->streamService->expects($this->once())->method('attachCard')->with($item)->willReturn($item);
		$item->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);

		$response = $this->controller()->statusGet(42);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($item, $response->getData());
	}

	public function testStatusGetOfUnknownStatusIsAnError(): void {
		$this->streamService->method('getStreamByNid')->willThrowException(new StreamNotFoundException('not found'));

		$this->assertNotFound($this->controller()->statusGet(42), 'not found');
	}

	public function testStatusContextReturnsAncestorsAndDescendants(): void {
		$context = ['ancestors' => [], 'descendants' => [$this->createMock(Stream::class)]];
		$this->streamService->method('getContextByNid')->with(7)->willReturn($context);

		$response = $this->controller()->statusContext(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($context, $response->getData());
	}

	/** @return iterable<string, array{string}> */
	public static function statusActions(): iterable {
		yield 'favourite' => ['favourite'];
		yield 'unfavourite' => ['unfavourite'];
		yield 'reblog' => ['reblog'];
		yield 'unreblog' => ['unreblog'];
	}

	#[DataProvider('statusActions')]
	public function testStatusActionDispatchesToActionServiceAsTheViewersActor(string $action): void {
		$this->loggedInAs();
		$actor = $this->createStub(Person::class);
		$this->accountService->method('getActor')->with('alice')->willReturn($actor);
		$item = $this->createMock(Stream::class);
		$this->actionService->expects($this->once())->method('action')->with($actor, 12, $action)->willReturn($item);
		$item->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);
		$this->streamService->expects($this->never())->method('getStreamByNid');

		$response = $this->controller()->statusAction(12, $action);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($item, $response->getData());
	}

	public function testStatusActionFallsBackToTheStatusWhenActionReturnsNothing(): void {
		$this->loggedInAs();
		$this->accountService->method('getActor')->willReturn($this->createStub(Person::class));
		$this->actionService->method('action')->willReturn(null);
		$item = $this->createStub(Stream::class);
		$this->streamService->expects($this->once())->method('getStreamByNid')->with(12)->willReturn($item);

		$this->assertSame($item, $this->controller()->statusAction(12, 'translate')->getData());
	}

	/** A moved account is told so with a 403, not a 422: the request was fine, the account may not. */
	public function testAPostFromAMovedAccountIsForbidden(): void {
		$this->loggedInAs();
		$this->postService->method('createPost')
			->willThrowException(new \OCA\Social\Exceptions\AccountMovedException('this account has moved to https://new.example/users/alice'));

		$response = $this->controller()->statusNew('hello');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('has moved', $response->getData()['error']);
	}

	/**
	 * Found on devel with the review queue on: the first-post hold ran before
	 * the moved guard, so a moderator was shown a post from an account that
	 * had left, and the account was told "waiting for a moderator".
	 */
	public function testAMovedAccountIsRefusedBeforeTheReviewQueueHoldsItsPost(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['status' => 'hello everybody']);
		$this->moderationService->method('assertNotMoved')
			->willThrowException(new \OCA\Social\Exceptions\AccountMovedException('this account has moved to https://new.example/users/alice'));
		$this->postReviewService->method('assess')->willReturn(HeldPost::REASON_FIRST_POST);
		$this->postReviewService->expects($this->never())->method('hold');
		$this->postService->expects($this->never())->method('createPost');

		$response = $this->controller()->statusNew();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('has moved', $response->getData()['error']);
	}

	public function testStatusActionRejectsUnknownActions(): void {
		$this->loggedInAs();
		$this->accountService->method('getActor')->willReturn($this->createStub(Person::class));
		$this->actionService->method('action')->willThrowException(new InvalidActionException('unknown action'));

		$this->assertUnprocessable($this->controller()->statusAction(12, 'explode'), 'unknown action');
	}

	public function testStatusActionRequiresAViewer(): void {
		$this->actionService->expects($this->never())->method('action');

		$this->assertUnauthorized($this->controller()->statusAction(12, 'favourite'));
	}

	// statusNew / statusUpdate

	/**
	 * Decided in the controller as well as in PostService, because the
	 * attachments are scoped to the post's visibility here, before the
	 * service runs: a reply to a direct message asked for as `public` would
	 * otherwise have made its pictures world-readable on the way.
	 */
	public function testStatusNewMakesAReplyToADirectMessageDirectBeforeScopingItsMedia(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn([
			'status' => 'between us',
			'visibility' => 'public',
			'in_reply_to_id' => 7,
		]);
		$parent = $this->createStub(Stream::class);
		$parent->method('getId')->willReturn('https://remote.example/notes/7');
		$parent->method('getVisibility')->willReturn(Stream::TYPE_DIRECT);
		$this->streamService->method('getStreamByNid')->with(7)->willReturn($parent);

		$activity = $this->createMock(ACore::class);
		$activity->method('getObjectId')->willReturn('https://cloud.example/apps/social/@alice/n1');
		$created = null;
		$this->postService->method('createPost')
			->willReturnCallback(function (Post $post) use (&$created, $activity): ACore {
				$created = $post;

				return $activity;
			});
		$this->streamService->method('getStreamById')->willReturn($this->createStub(Stream::class));

		$this->controller()->statusNew();

		$this->assertSame(Stream::TYPE_DIRECT, $created->getType());
	}

	public function testStatusNewCreatesAPostFromTheFormParameters(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn([
			'status' => "hello\nworld",
			'visibility' => 'unlisted',
			'in_reply_to_id' => 7,
		]);
		$parent = $this->createStub(Stream::class);
		$parent->method('getId')->willReturn('https://remote.example/notes/7');
		$this->streamService->method('getStreamByNid')->with(7)->willReturn($parent);

		$activity = $this->createMock(ACore::class);
		$activity->method('getObjectId')->willReturn('https://cloud.example/apps/social/@alice/n1');
		$created = null;
		$this->postService->expects($this->once())->method('createPost')
			->willReturnCallback(function (Post $post) use (&$created, $activity): ACore {
				$created = $post;

				return $activity;
			});
		$item = $this->createStub(Stream::class);
		$this->streamService->method('getStreamById')
			->with('https://cloud.example/apps/social/@alice/n1', true, ACore::FORMAT_LOCAL)
			->willReturn($item);

		$response = $this->controller()->statusNew();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($item, $response->getData());
		$this->assertSame("hello\nworld", $created->getContent(), 'the raw text; PostService escapes and converts newlines');
		$this->assertSame('unlisted', $created->getType());
		$this->assertSame('https://remote.example/notes/7', $created->getReplyTo());
	}

	/**
	 * The field used to be parsed and thrown away, so a client that scheduled
	 * a post for next week was told it had been scheduled and the post went
	 * out at once.
	 */
	public function testStatusNewWithAScheduledTimeStoresItInsteadOfPosting(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn([
			'status' => 'later', 'scheduled_at' => '2030-01-01T12:00:00Z',
		]);
		$entity = $this->createStub(ScheduledStatus::class);
		$this->scheduledStatusService->method('requestedTime')->willReturn(1893499200);
		$this->scheduledStatusService->expects($this->once())->method('schedule')->willReturn($entity);
		$this->postService->expects($this->never())->method('createPost');

		$response = $this->controller()->statusNew();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($entity, $response->getData());
	}

	/**
	 * A post a rule holds is stored as a request and never reaches
	 * `social_stream`, so there is no row for a timeline to find. The client
	 * is told in the vocabulary Mastodon's API has, with enough beside it to
	 * say something better than "failed".
	 */
	public function testStatusNewHeldForReviewStoresTheRequestAndPublishesNothing(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['status' => 'hello everybody']);
		$this->postReviewService->method('assess')->willReturn(HeldPost::REASON_FIRST_POST);
		$this->postReviewService->method('paramsOf')->willReturn(['text' => 'hello everybody']);
		$held = (new HeldPost())->setId(7)->setReason(HeldPost::REASON_FIRST_POST);
		$this->postReviewService->expects($this->once())->method('hold')->willReturn($held);
		$this->postService->expects($this->never())->method('createPost');

		$response = $this->controller()->statusNew();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertTrue($response->getData()['held_for_review']);
		$this->assertSame(HeldPost::REASON_FIRST_POST, $response->getData()['reason']);
		$this->assertSame($held, $response->getData()['held_post']);
	}

	public function testStatusNewIsPostedWhenNoRuleHoldsIt(): void {
		$this->postReviewService->method('assess')->willReturn('');
		$this->postReviewService->expects($this->never())->method('hold');

		$this->assertSame('hi', $this->postWith(['status' => 'hi'])->getContent());
	}

	public function testStatusNewWithoutAScheduledTimePostsStraightAway(): void {
		$this->scheduledStatusService->expects($this->never())->method('schedule');

		$this->assertSame('hi', $this->postWith(['status' => 'hi'])->getContent());
	}

	/**
	 * The composer sends a language with every post now, so the round trip
	 * from the request to the Post the poster's server builds is worth
	 * holding still: a post with no language federates as `language: null`
	 * and is seen by neither the readers who filter for a language nor the
	 * ones who filter one out.
	 */
	public function testStatusNewCarriesTheLanguageTheClientSent(): void {
		$this->assertSame('fr', $this->postWith(['status' => 'Bonjour', 'language' => 'fr'])->getLanguage());
	}

	/** A client that sends none still gets the poster's default, from PostService. */
	public function testStatusNewWithoutALanguageLeavesTheDefaultToTheService(): void {
		$this->assertSame('', $this->postWith(['status' => 'hi'])->getLanguage());
	}

	public function testStatusNewIgnoresAMissingParent(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['status' => 'hi', 'in_reply_to_id' => 99]);
		$this->streamService->method('getStreamByNid')->willThrowException(new StreamNotFoundException());
		$activity = $this->createMock(ACore::class);
		$activity->method('getObjectId')->willReturn('id');
		$created = null;
		$this->postService->method('createPost')->willReturnCallback(function (Post $post) use (&$created, $activity): ACore {
			$created = $post;

			return $activity;
		});
		$this->streamService->method('getStreamById')->willReturn($this->createStub(Stream::class));

		$this->controller()->statusNew();

		$this->assertSame('', $created->getReplyTo());
	}

	/**
	 * `sensitive` is what makes a client blur the attachments. The create path
	 * dropped it — the edit path has always carried it — so a post marked
	 * sensitive in Tusky came back unblurred, here and on every instance the
	 * Create federates to.
	 */
	public function testStatusNewCarriesTheSensitiveFlagToThePost(): void {
		$created = $this->postWith(['status' => 'look at this', 'sensitive' => 'true']);

		$this->assertTrue($created->isSensitive());
	}

	public function testStatusNewWithoutTheSensitiveFlagIsNotSensitive(): void {
		$this->assertFalse($this->postWith(['status' => 'look at this'])->isSensitive());
	}

	/**
	 * `quote_id` is parsed off the body by `Status::import()`, but it only
	 * becomes a quote if the controller carries it onto the `Post` — and a
	 * client whose quote is dropped here is told the post succeeded, because
	 * it did: it just quotes nothing.
	 */
	public function testStatusNewCarriesTheQuotedPostToThePost(): void {
		$created = $this->postWith([
			'status' => 'look at this',
			'quote_id' => 'https://mastodon.social/users/bob/statuses/111',
		]);

		$this->assertSame('https://mastodon.social/users/bob/statuses/111', $created->getQuotedId());
	}

	public function testAStatusThatQuotesNothingCarriesNoQuote(): void {
		$this->assertSame('', $this->postWith(['status' => 'just a post'])->getQuotedId());
	}

	/**
	 * A status posted without a visibility used to become a direct message
	 * addressed to nobody: the empty value fell through
	 * `Stream::visibilityFromClient()` to `direct`, and a direct post with no
	 * recipient is delivered to no one while the request answers 200.
	 */
	public function testAStatusWithoutAVisibilityIsPublic(): void {
		$this->assertSame(Stream::TYPE_PUBLIC, $this->postWith(['status' => 'hi'])->getType());
	}

	/**
	 * A client that names no visibility means "whatever this account posts
	 * with", which Mastodon resolves against `source.privacy`: a reader who set
	 * their default to followers-only had every post from a client that omits
	 * the field published to the whole fediverse instead.
	 */
	public function testAStatusWithoutAVisibilityTakesTheAccountsDefault(): void {
		$this->defaultPrivacy = 'private';

		$this->assertSame(Stream::TYPE_FOLLOWERS, $this->postWith(['status' => 'hi'])->getType());
	}

	public function testAVisibilityTheClientNamesWinsOverTheDefault(): void {
		$this->defaultPrivacy = 'private';

		$this->assertSame(
			Stream::TYPE_PUBLIC,
			$this->postWith(['status' => 'hi', 'visibility' => 'public'])->getType()
		);
	}

	public function testAVisibilityThisAppDoesNotKnowIsRefused(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['status' => 'hi', 'visibility' => 'friends']);
		$this->postService->expects($this->never())->method('createPost');

		$this->assertUnprocessable(
			$this->controller()->statusNew(), 'unknown visibility: friends'
		);
	}

	/**
	 * An empty, truncated or scalar JSON body decoded to `null`, which under
	 * strict_types raised a TypeError out of `convertInput(): array` — a
	 * Nextcloud HTML error page, stack trace and all, on a #[PublicPage] route.
	 */
	public function testAMalformedJsonBodyIsRefusedRatherThanCrashing(): void {
		$this->loggedInAs();
		$this->postService->expects($this->never())->method('createPost');

		$response = $this->controllerWithHeaders('', ['Content-Type' => 'application/json; charset=utf-8'])
			->statusNew();

		$this->assertUnprocessable($response, 'the request body is not valid JSON');
	}

	/** Not every failure is an Exception; a client is owed JSON either way. */
	public function testAPhpErrorIsStillAnsweredAsAnApiError(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['status' => 'hi']);
		$this->postService->method('createPost')->willThrowException(new \Error('boom'));

		$this->assertServerError($this->controller()->statusNew());
	}

	public function testStatusNewIsUnauthorizedForAnonymous(): void {
		$this->postService->expects($this->never())->method('createPost');

		// used to be a 400, which tells a client nothing about its credentials
		$this->assertUnauthorized($this->controller()->statusNew());
	}

	public function testStatusUpdateEditsTheStatusWithSpoilerAndSensitivity(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn([
			'status' => "edited\ntext",
			'spoiler_text' => 'cw',
			'sensitive' => true,
		]);
		$item = $this->createMock(Stream::class);
		$this->postService->expects($this->once())->method('editPost')
			->with(5, $this->isInstanceOf(Person::class), "edited\ntext", 'cw', true)
			->willReturn($item);
		$item->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);

		$response = $this->controller()->statusUpdate(5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($item, $response->getData());
	}

	/**
	 * A form body sends `media_attributes[][id]=11&media_attributes[][description]=…`,
	 * which PHP splits into one entry per field. The edit has to see one entry
	 * per attachment, as Mastodon does.
	 */
	public function testStatusUpdateGroupsFormEncodedMediaAttributesPerAttachment(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn([
			'status' => 'same words',
			'media_attributes' => [
				['id' => '11'], ['description' => 'New words'], ['focus' => '0.5,-0.2'],
				['id' => '12'], ['description' => 'Second'],
			],
		]);
		$this->postService->expects($this->once())->method('editPost')
			->with(5, $this->anything(), 'same words', null, false, null, [
				['id' => '11', 'description' => 'New words', 'focus' => '0.5,-0.2'],
				['id' => '12', 'description' => 'Second'],
			])
			->willReturn($this->createStub(Stream::class));

		$this->assertSame(Http::STATUS_OK, $this->controller()->statusUpdate(5)->getStatus());
	}

	public function testStatusUpdatePassesJsonMediaAttributesAsTheyCame(): void {
		$this->loggedInAs();
		$attributes = [['id' => '11', 'description' => 'New words'], ['id' => '12', 'focus' => '0,0']];
		$this->request->method('getParams')->willReturn(['status' => 'x', 'media_attributes' => $attributes]);
		$this->postService->expects($this->once())->method('editPost')
			->with(5, $this->anything(), 'x', null, false, null, $attributes)
			->willReturn($this->createStub(Stream::class));

		$this->controller()->statusUpdate(5);
	}

	public function testStatusUpdateWithoutSpoilerPassesNull(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['status' => 'x']);
		$this->postService->expects($this->once())->method('editPost')
			->with(5, $this->anything(), 'x', null, false)
			->willReturn($this->createStub(Stream::class));

		$this->controller()->statusUpdate(5);
	}

	public function testStatusUpdateOfAMissingStatusIsNotFound(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['status' => 'x']);
		$this->postService->method('editPost')->willThrowException(new StreamNotFoundException('gone'));

		$this->assertNotFound($this->controller()->statusUpdate(5), 'gone');
	}

	public function testStatusUpdateReportsAStoredEditWhoseFederationFailed(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['status' => 'edited']);
		$this->postService->method('editPost')->willThrowException(new FederationDeliveryException(
			'The post was saved locally, but its edit could not be sent to other instances.'
		));

		$response = $this->controller()->statusUpdate(5);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame([
			'error' => 'The post was saved locally, but its edit could not be sent to other instances.',
		], $response->getData());
	}

	// relationships / accounts

	public function testRelationshipsAreResolvedByFollowService(): void {
		$this->loggedInAs();
		$this->followService->expects($this->once())->method('getRelationships')->with([1, 2])->willReturn(['r1', 'r2']);

		$response = $this->controller()->relationships([1, 2]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['r1', 'r2'], $response->getData());
	}

	public function testRelationshipsRequireAViewer(): void {
		$this->assertUnauthorized($this->controller()->relationships([1]));
	}

	/**
	 * A request-bound array parameter is filled in by the dispatcher, before
	 * the method's own try block, so a client asking with no `id[]` at all used
	 * to raise a TypeError there — an HTML error page rather than an answer.
	 */
	public function testRelationshipsWithoutAnyIdIsAnEmptyAnswer(): void {
		$this->loggedInAs();
		$this->followService->method('getRelationships')->with([])->willReturn([]);

		$response = $this->controller()->relationships();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	// blocking / muting

	/**
	 * A target account resolvable by its numeric id, as resolveTargetAccount() does.
	 *
	 * @return Person&MockObject
	 */
	private function knownTarget(int $nid = 42): Person {
		$target = $this->createMock(Person::class);
		$target->method('getNid')->willReturn($nid);
		$this->cacheActorService->expects($this->once())
			->method('getFromNids')->with([$nid])->willReturn([$target]);

		return $target;
	}

	public function testAccountBlockBlocksTheResolvedAccountAndReturnsTheRelationship(): void {
		$viewer = $this->loggedInAs();
		$target = $this->knownTarget();
		$this->relationshipService->expects($this->once())
			->method('block')
			->with($this->identicalTo($viewer), $this->identicalTo($target));

		$relationship = new Relationship(42);
		$this->followService->expects($this->once())
			->method('getRelationshipWith')->with($this->identicalTo($target))->willReturn($relationship);

		$response = $this->controller()->accountBlock('42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($relationship, $response->getData());
	}

	public function testAccountUnblockUnblocksTheResolvedAccount(): void {
		$viewer = $this->loggedInAs();
		$target = $this->knownTarget();
		$this->relationshipService->expects($this->once())
			->method('unblock')
			->with($this->identicalTo($viewer), $this->identicalTo($target));
		$this->relationshipService->expects($this->never())->method('block');

		$this->assertSame(Http::STATUS_OK, $this->controller()->accountUnblock('42')->getStatus());
	}

	public function testAccountMuteHidesNotificationsByDefault(): void {
		$viewer = $this->loggedInAs();
		$target = $this->knownTarget();
		$this->relationshipService->expects($this->once())
			->method('mute')
			->with($this->identicalTo($viewer), $this->identicalTo($target), true);

		$this->assertSame(Http::STATUS_OK, $this->controller()->accountMute('42')->getStatus());
	}

	public function testAccountMuteCanKeepNotifications(): void {
		$viewer = $this->loggedInAs();
		$target = $this->knownTarget();
		$this->relationshipService->expects($this->once())
			->method('mute')
			->with($this->identicalTo($viewer), $this->identicalTo($target), false);

		$this->assertSame(Http::STATUS_OK, $this->controller()->accountMute('42', false)->getStatus());
	}

	public function testAccountUnmuteUnmutesTheResolvedAccount(): void {
		$viewer = $this->loggedInAs();
		$target = $this->knownTarget();
		$this->relationshipService->expects($this->once())
			->method('unmute')
			->with($this->identicalTo($viewer), $this->identicalTo($target));

		$this->assertSame(Http::STATUS_OK, $this->controller()->accountUnmute('42')->getStatus());
	}

	public function testAccountBlockOfAnUnknownAccountIsNotFoundAndBlocksNothing(): void {
		$this->loggedInAs();
		$this->cacheActorService->method('getFromNids')->with([42])->willReturn([]);
		// a numeric id is an id, never a URL: it must not become a WebFinger
		// lookup for the string "42"
		$this->cacheActorService->expects($this->never())->method('getFromId');
		$this->relationshipService->expects($this->never())->method('block');

		$this->assertNotFound($this->controller()->accountBlock('42'), 'unknown account');
	}

	public function testAccountBlockRequiresAViewer(): void {
		$this->relationshipService->expects($this->never())->method('block');

		$this->assertUnauthorized($this->controller()->accountBlock('42'));
	}

	public function testBlocksListTheBlockedAccountsForLocalExport(): void {
		$viewer = $this->loggedInAs();
		$blocked = $this->createMock(Person::class);
		$blocked->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);
		$this->relationshipService->expects($this->once())
			->method('getRelated')
			->with($this->identicalTo($viewer), ActorRelation::TYPE_BLOCK, 40)
			->willReturn([$blocked]);

		$response = $this->controller()->blocks();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([$blocked], $response->getData());
	}

	public function testMutesListTheMutedAccounts(): void {
		$viewer = $this->loggedInAs();
		$muted = $this->createStub(Person::class);
		$this->relationshipService->expects($this->once())
			->method('getRelated')
			->with($this->identicalTo($viewer), ActorRelation::TYPE_MUTE, 40)
			->willReturn([$muted]);

		$response = $this->controller()->mutes();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([$muted], $response->getData());
	}

	/**
	 * Neither route takes a cursor, so the next page a `Link` header would
	 * advertise is the page just sent: a client paging on the header scrolled
	 * the same blocked accounts for ever.
	 */
	public function testTheBlockedAccountsPageDoesNotAdvertiseANextPageItCannotServe(): void {
		$viewer = $this->loggedInAs();
		$this->requestUri('/api/v1/blocks?limit=2');
		$blocked = [$this->createMock(Person::class), $this->createMock(Person::class)];
		$blocked[0]->method('getNid')->willReturn(9);
		$blocked[1]->method('getNid')->willReturn(8);
		$this->relationshipService->method('getRelated')
			->with($this->identicalTo($viewer), ActorRelation::TYPE_BLOCK, 2)
			->willReturn($blocked);

		$response = $this->controller()->blocks(2);

		$this->assertSame($blocked, $response->getData());
		$this->assertArrayNotHasKey('Link', $response->getHeaders());
	}

	public function testTheMutedAccountsPageDoesNotEither(): void {
		$this->loggedInAs();
		$this->requestUri('/api/v1/mutes?limit=1');
		$muted = $this->createStub(Person::class);
		$muted->method('getNid')->willReturn(4);
		$this->relationshipService->method('getRelated')->willReturn([$muted]);

		$this->assertArrayNotHasKey('Link', $this->controller()->mutes(1)->getHeaders());
	}

	public function testBlocksRequireAViewer(): void {
		$this->relationshipService->expects($this->never())->method('getRelated');

		$this->assertUnauthorized($this->controller()->blocks());
	}

	// follow requests

	/** @return array{accounts: Person[], rows: int, first: string, last: string} */
	private function pendingPage(array $accounts, int $rows, string $first = '', string $last = ''): array {
		return ['accounts' => $accounts, 'rows' => $rows, 'first' => $first, 'last' => $last];
	}

	public function testFollowRequestsListThePendingAccountsForLocalExport(): void {
		$this->loggedInAs();
		$this->requestUri('/api/v1/follow_requests');
		$pending = $this->createMock(Person::class);
		$pending->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);
		$this->followService->expects($this->once())
			->method('getPendingRequestPage')->with(40, '', '')
			->willReturn($this->pendingPage([$pending], 1, '5-' . str_repeat('a', 32), '5-' . str_repeat('a', 32)));

		$response = $this->controller()->followRequests();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([$pending], $response->getData());
	}

	/** Mastodon's own bounds: 40 unless asked, never more than 80. */
	public function testAFollowRequestsPageIsBounded(): void {
		$this->loggedInAs();
		$this->requestUri('/api/v1/follow_requests');
		$asked = [];
		$this->followService->method('getPendingRequestPage')
			->willReturnCallback(function (int $limit) use (&$asked): array {
				$asked[] = $limit;

				return $this->pendingPage([], 0);
			});

		$this->controller()->followRequests(500);
		$this->controller()->followRequests(0);
		$this->controller()->followRequests(10);

		$this->assertSame([80, 40, 10], $asked);
	}

	/**
	 * The cursors are the follow rows' — a client pages on the header and
	 * sends back what it found there, as it does against Mastodon.
	 */
	public function testAFullFollowRequestsPageLinksToTheNextAndThePrevious(): void {
		$this->loggedInAs();
		$this->requestUri('/api/v1/follow_requests?limit=2');
		$newest = '1767268801-' . str_repeat('b', 32);
		$oldest = '1767268800-' . str_repeat('a', 32);
		$this->followService->expects($this->once())
			->method('getPendingRequestPage')->with(2, 'older', '')
			->willReturn($this->pendingPage([$this->createMock(Person::class)], 2, $newest, $oldest));

		$response = $this->controller()->followRequests(2, 'older');

		$this->assertSame(
			'<https://cloud.example/api/v1/follow_requests?limit=2&max_id=' . $oldest . '>; rel="next", '
			. '<https://cloud.example/api/v1/follow_requests?limit=2&min_id=' . $newest . '>; rel="prev"',
			$response->getHeaders()['Link']
		);
	}

	public function testTheLastFollowRequestsPageOffersNoNextPage(): void {
		$this->loggedInAs();
		$this->requestUri('/api/v1/follow_requests?limit=2');
		$only = '1767268800-' . str_repeat('a', 32);
		$this->followService->method('getPendingRequestPage')
			->willReturn($this->pendingPage([$this->createMock(Person::class)], 1, $only, $only));

		$link = $this->controller()->followRequests(2)->getHeaders()['Link'];

		$this->assertStringNotContainsString('rel="next"', $link);
		$this->assertStringContainsString('min_id=' . $only, $link);
	}

	public function testAnEmptyFollowRequestsPageCarriesNoLinks(): void {
		$this->loggedInAs();
		$this->requestUri('/api/v1/follow_requests');
		$this->followService->method('getPendingRequestPage')->willReturn($this->pendingPage([], 0));

		$this->assertArrayNotHasKey('Link', $this->controller()->followRequests()->getHeaders());
	}

	public function testFollowRequestsRequireAViewer(): void {
		$this->followService->expects($this->never())->method('getPendingRequestPage');

		$this->assertUnauthorized($this->controller()->followRequests());
	}

	public function testFollowRequestAuthorizeConfirmsAndReturnsTheRelationship(): void {
		$this->loggedInAs();
		$target = $this->knownTarget();
		$this->followService->expects($this->once())
			->method('authorizeFollowRequest')->with($this->identicalTo($target));
		$this->followService->expects($this->never())->method('rejectFollowRequest');

		$relationship = new Relationship(42);
		$this->followService->expects($this->once())
			->method('getRelationshipWith')->with($this->identicalTo($target))->willReturn($relationship);

		$response = $this->controller()->followRequestAuthorize('42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($relationship, $response->getData());
	}

	public function testFollowRequestRejectRejectsAndReturnsTheRelationship(): void {
		$this->loggedInAs();
		$target = $this->knownTarget();
		$this->followService->expects($this->once())
			->method('rejectFollowRequest')->with($this->identicalTo($target));
		$this->followService->expects($this->never())->method('authorizeFollowRequest');

		$relationship = new Relationship(42);
		$this->followService->method('getRelationshipWith')->willReturn($relationship);

		$response = $this->controller()->followRequestReject('42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($relationship, $response->getData());
	}

	public function testFollowRequestAuthorizeWithoutAPendingRequestIsNotFound(): void {
		$this->loggedInAs();
		$this->knownTarget();
		$this->followService->method('authorizeFollowRequest')
			->willThrowException(new FollowNotFoundException());

		$response = $this->controller()->followRequestAuthorize('42');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'no pending follow request'], $response->getData());
	}

	public function testFollowRequestsRequireAViewerForActions(): void {
		$this->followService->expects($this->never())->method('authorizeFollowRequest');
		$this->followService->expects($this->never())->method('rejectFollowRequest');

		$this->assertUnauthorized($this->controller()->followRequestAuthorize('42'));
		$this->assertUnauthorized($this->controller()->followRequestReject('42'));
	}

	public function testAFollowRequestRouteRefusesAReadOnlyToken(): void {
		$this->route = 'social.AccountApi.followRequestAuthorize';
		$this->bearerFor(['read']);
		$this->followService->expects($this->never())->method('authorizeFollowRequest');

		$this->assertInsufficientScope(
			$this->controller('Bearer s3cret')->followRequestAuthorize('42'),
			'token scope does not allow this request (needs write:follows or follow)'
		);
	}

	public function testAFollowScopedTokenMayAuthorizeAFollowRequest(): void {
		$this->route = 'social.AccountApi.followRequestAuthorize';
		$this->bearerFor(['follow']);
		$target = $this->knownTarget();
		$this->followService->expects($this->once())
			->method('authorizeFollowRequest')->with($this->identicalTo($target));
		$this->followService->method('getRelationshipWith')->willReturn(new Relationship(42));

		$response = $this->controller('Bearer s3cret')->followRequestAuthorize('42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// update_credentials

	public function testUpdateCredentialsLocksTheAccountAndReturnsTheRefreshedViewer(): void {
		$viewer = $this->loggedInAs();
		$this->request->method('getParams')->willReturn(['locked' => 'true']);
		$this->accountService->expects($this->once())->method('setLocked')->with('alice', true);

		$response = $this->controller()->updateCredentials();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('7', $response->getData()['id']);
		$this->assertSame($viewer->exportSourceAsLocal(), $response->getData()['source']);
	}

	public function testUpdateCredentialsCanUnlockTheAccount(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['locked' => 'false']);
		$this->accountService->expects($this->once())->method('setLocked')->with('alice', false);

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	public function testUpdateCredentialsWithoutTheLockedFieldChangesNothing(): void {
		$viewer = $this->loggedInAs();
		$this->request->method('getParams')->willReturn(['display_name' => 'Alice']);
		$this->accountService->expects($this->never())->method('setLocked');

		$response = $this->controller()->updateCredentials();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('alice', $response->getData()['username']);
	}

	/**
	 * A client's profile editor sends the whole form in one PATCH. These three
	 * were parsed and dropped, so somebody changing their name, picture and bio
	 * together got a 200 and only the bio.
	 */
	public function testUpdateCredentialsWritesTheDisplayName(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['display_name' => 'Alice of Wonderland']);
		$this->accountService->expects($this->once())
			->method('setDisplayName')->with('alice', 'Alice of Wonderland');

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	/** A backend that owns the name says so rather than answering 200. */
	public function testUpdateCredentialsSaysSoWhenTheNameIsNotOursToChange(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['display_name' => 'Alice']);
		$this->accountService->method('setDisplayName')
			->willThrowException(new InvalidActionException('managed outside Nextcloud'));

		$response = $this->controller()->updateCredentials();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testUpdateCredentialsCarriesTheBotFlag(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['bot' => 'true']);
		$this->accountService->expects($this->once())
			->method('setActorFlags')->with('alice', ['bot' => true]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	public function testUpdateCredentialsStoresAnUploadedAvatar(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn([]);
		$tmp = (string)tempnam(sys_get_temp_dir(), 'social-avatar');
		$this->tempFiles[] = $tmp;
		file_put_contents($tmp, 'avatar bytes');
		$_FILES['avatar'] = ['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'name' => 'me.png'];
		$this->avatarService->expects($this->once())->method('setFromTempFile')->with('alice', $_FILES['avatar']);

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	public function testUpdateCredentialsStoresTheDefaultAudience(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['source' => ['privacy' => 'unlisted']]);
		$this->accountService->expects($this->once())
			->method('setDefaultPrivacy')->with('alice', 'unlisted');

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	public function testUpdateCredentialsWithoutASourceLeavesTheDefaultAudienceAlone(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['locked' => 'true']);
		$this->accountService->expects($this->never())->method('setDefaultPrivacy');

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	public function testUpdateCredentialsWritesTheBio(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['note' => 'Nextcloud, mostly.']);
		$this->accountService->expects($this->once())->method('setSummary')
			->with('alice', 'Nextcloud, mostly.');

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	public function testUpdateCredentialsCanEmptyTheBioOnPurpose(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['note' => '']);
		$this->accountService->expects($this->once())->method('setSummary')->with('alice', '');

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	public function testUpdateCredentialsWithoutANoteLeavesTheBioAlone(): void {
		// a client changing the display name says nothing about the bio, and
		// must not wipe it on the way past
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['locked' => 'true']);
		$this->accountService->expects($this->never())->method('setSummary');

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	public function testUpdateCredentialsStoresProfileFields(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn([
			'fields_attributes' => [
				['name' => 'Website', 'value' => 'https://example.org'],
				['name' => 'Pronouns', 'value' => 'they/them'],
			],
		]);
		$this->accountService->expects($this->once())->method('setFields')
			->with('alice', [
				['name' => 'Website', 'value' => 'https://example.org'],
				['name' => 'Pronouns', 'value' => 'they/them'],
			]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	public function testUpdateCredentialsAcceptsFieldsKeyedByIndex(): void {
		// form-encoded clients send fields_attributes as an object keyed by index
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn([
			'fields_attributes' => ['0' => ['name' => 'Website', 'value' => 'https://example.org']],
		]);
		$this->accountService->expects($this->once())->method('setFields')
			->with('alice', [['name' => 'Website', 'value' => 'https://example.org']]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	public function testUpdateCredentialsPersistsTheDiscoverableAndIndexableFlags(): void {
		// Mastodon clients send these as form booleans: true/false/1/0, or the
		// strings thereof; the service takes real bools
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['discoverable' => 'true', 'indexable' => '0']);
		$this->accountService->expects($this->once())->method('setActorFlags')
			->with('alice', ['discoverable' => true, 'indexable' => false]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	public function testUpdateCredentialsOnlyForwardsTheFlagsThatWereSent(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['indexable' => '1', 'locked' => 'false']);
		$this->accountService->expects($this->once())->method('setActorFlags')->with('alice', ['indexable' => true]);
		$this->accountService->expects($this->once())->method('setLocked')->with('alice', false);

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	public function testUpdateCredentialsWithoutFlagsLeavesThemAlone(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['display_name' => 'Alice']);
		$this->accountService->expects($this->never())->method('setActorFlags');

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	public function testVerifyCredentialsOfABrandNewAccountIsDecodableByAStrictClient(): void {
		// Person::exportAsLocal() emits "" for a date that is not there and for
		// images not yet cached; a strict decoder fails the whole Account on a
		// "" date, and Mastodon never sends an empty avatar — it sends a
		// placeholder URL
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$account = $this->createStub(Person::class);
		$account->method('getPreferredUsername')->willReturn('alice');
		$this->accountService->method('getActorFromUserId')->willReturn($account);
		$fresh = new Person();
		$fresh->setPreferredUsername('alice')->setLocal(true)->setNid(7);
		$this->cacheActorService->method('getFromLocalAccount')->with('alice')->willReturn($fresh);
		$this->urlGenerator->method('linkToRouteAbsolute')
			->with('core.avatar.getAvatar', ['userId' => 'alice', 'size' => 128])
			->willReturn('https://cloud.example/avatar/alice/128');
		$this->urlGenerator->method('imagePath')
			->willReturnCallback(static fn (string $app, string $file): string => '/apps/' . $app . '/img/' . $file);
		$this->urlGenerator->method('getAbsoluteURL')
			->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

		$data = json_decode((string)json_encode($this->controller()->verifyCredentials()->getData()), true);

		$this->assertSame('alice', $data['username']);
		$this->assertNull($data['last_status_at']);
		$this->assertSame('https://cloud.example/avatar/alice/128', $data['avatar']);
		$this->assertSame('https://cloud.example/avatar/alice/128', $data['avatar_static']);
		// never the avatar: a client draws the header where a header goes
		$this->assertSame('https://cloud.example/apps/social/img/header-missing.png', $data['header']);
		$this->assertSame('https://cloud.example/apps/social/img/header-missing.png', $data['header_static']);
		// and says that both are placeholders, so a client need not guess from the URL
		$this->assertTrue($data['avatar_default']);
		$this->assertTrue($data['header_default']);
	}

	public function testUpdateCredentialsRequiresAViewer(): void {
		$this->accountService->expects($this->never())->method('setLocked');

		$this->assertUnauthorized($this->controller()->updateCredentials());
	}

	public function testUpdateCredentialsRefusesAReadOnlyToken(): void {
		$this->route = 'social.AccountApi.updateCredentials';
		$this->bearerFor(['read']);
		$this->accountService->expects($this->never())->method('setLocked');

		$this->assertInsufficientScope(
			$this->controller('Bearer s3cret')->updateCredentials(),
			'token scope does not allow this request (needs write:accounts)'
		);
	}

	// follow / unfollow

	public function testAccountFollowFollowsAndReturnsTheRelationship(): void {
		$viewer = $this->loggedInAs();
		$target = $this->knownTarget();
		$target->method('getAccount')->willReturn('bob@remote.example');
		$this->followService->expects($this->once())
			->method('followAccount')->with($this->identicalTo($viewer), 'bob@remote.example')
			->willReturn(true);
		$this->accountService->expects($this->once())
			->method('bumpActorCount')->with($viewer->getId(), 'count_following', 1);

		$relationship = new Relationship(42);
		$this->followService->method('getRelationshipWith')->willReturn($relationship);

		$response = $this->controller()->accountFollow('42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($relationship, $response->getData());
	}

	public function testAccountUnfollowUnfollows(): void {
		$this->loggedInAs();
		$target = $this->knownTarget();
		$target->method('getAccount')->willReturn('bob@remote.example');
		$this->followService->expects($this->once())->method('unfollowAccount');
		$this->followService->method('getRelationshipWith')->willReturn(new Relationship(42));

		$this->assertSame(Http::STATUS_OK, $this->controller()->accountUnfollow('42')->getStatus());
	}

	/** The same call on an existing follow is how a client flips its bell or its boosts. */
	public function testReFollowingToChangeASettingLeavesTheCountAlone(): void {
		$this->loggedInAs();
		$target = $this->knownTarget();
		$target->method('getAccount')->willReturn('bob@remote.example');
		$this->followService->method('followAccount')->willReturn(false);
		$this->followService->method('getRelationshipWith')->willReturn(new Relationship(42));
		$this->accountService->expects($this->never())->method('bumpActorCount');

		$this->assertSame(Http::STATUS_OK, $this->controller()->accountFollow('42', true)->getStatus());
	}

	public function testUnfollowingSomebodyNotFollowedLeavesTheCountAlone(): void {
		$this->loggedInAs();
		$target = $this->knownTarget();
		$target->method('getAccount')->willReturn('bob@remote.example');
		$this->followService->method('unfollowAccount')->willReturn(false);
		$this->followService->method('getRelationshipWith')->willReturn(new Relationship(42));
		$this->accountService->expects($this->never())->method('bumpActorCount');

		$this->assertSame(Http::STATUS_OK, $this->controller()->accountUnfollow('42')->getStatus());
	}

	public function testUnfollowingTakesOneOffTheFollowingCount(): void {
		$viewer = $this->loggedInAs();
		$target = $this->knownTarget();
		$target->method('getAccount')->willReturn('bob@remote.example');
		$this->followService->method('unfollowAccount')->willReturn(true);
		$this->followService->method('getRelationshipWith')->willReturn(new Relationship(42));
		$this->accountService->expects($this->once())
			->method('bumpActorCount')->with($viewer->getId(), 'count_following', -1);

		$this->assertSame(Http::STATUS_OK, $this->controller()->accountUnfollow('42')->getStatus());
	}

	public function testAccountFollowRequiresAViewer(): void {
		$this->followService->expects($this->never())->method('followAccount');

		$this->assertUnauthorized($this->controller()->accountFollow('42'));
	}

	public function testAFollowRouteRefusesAReadOnlyToken(): void {
		$this->route = 'social.AccountApi.accountFollow';
		$this->bearerFor(['read']);
		$this->followService->expects($this->never())->method('followAccount');

		$this->assertInsufficientScope(
			$this->controller('Bearer s3cret')->accountFollow('42'),
			'token scope does not allow this request (needs write:follows or follow)'
		);
	}

	// favourited_by / reblogged_by / accounts search

	public function testFavouritedByListsTheAccountsThatLikedThePost(): void {
		$this->loggedInAs();
		$post = $this->createStub(Stream::class);
		$this->streamService->method('getStreamByNid')->with(9)->willReturn($post);
		$alice = $this->createStub(Person::class);
		$this->actionService->expects($this->once())
			->method('reactedBy')
			->with($this->identicalTo($post), 'Like', 40)
			->willReturn([$alice]);

		$response = $this->controller()->statusFavouritedBy(9);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([$alice], $response->getData());
	}

	public function testRebloggedByAsksForBoostsRatherThanLikes(): void {
		$this->loggedInAs();
		$this->streamService->method('getStreamByNid')->willReturn($this->createStub(Stream::class));
		$this->actionService->expects($this->once())
			->method('reactedBy')
			->with($this->anything(), 'Announce', 40)
			->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->statusRebloggedBy(9)->getStatus());
	}

	/** Who liked a post is as private as the post: a 404 is a 404 all the way down. */
	public function testReactionsOfAPostTheReaderMayNotSeeAreA404(): void {
		$this->loggedInAs();
		$this->streamService->method('getStreamByNid')
			->willThrowException(new StreamNotFoundException());
		$this->actionService->expects($this->never())->method('reactedBy');

		$this->assertSame(
			Http::STATUS_NOT_FOUND, $this->controller()->statusFavouritedBy(9)->getStatus()
		);
	}

	// quotes: who has quoted a post, who may, and taking one back

	public function testTheQuotesOfAPostAreWhatTheServiceHolds(): void {
		$this->loggedInAs();
		$post = $this->createStub(Stream::class);
		$this->streamService->method('getStreamByNid')->with(9)->willReturn($post);
		$quote = $this->createMock(Stream::class);
		$quote->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);
		$this->quoteService->expects($this->once())->method('quotesOf')
			->with($this->identicalTo($post), 20, 0)->willReturn([$quote]);

		$response = $this->controller()->statusQuotes(9);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([$quote], $response->getData());
	}

	/** A post the reader may not see has no list of quotes either. */
	public function testTheQuotesOfAPostTheReaderMayNotSeeAreA404(): void {
		$this->loggedInAs();
		$this->streamService->method('getStreamByNid')
			->willThrowException(new StreamNotFoundException());
		$this->quoteService->expects($this->never())->method('quotesOf');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->statusQuotes(9)->getStatus());
	}

	/**
	 * Somebody else's post is a 404 rather than a 403: the pair of answers
	 * together would say whose post an id belongs to.
	 */
	public function testSettingThePolicyOnSomebodyElsesPostIsNotFound(): void {
		$this->loggedInAs();
		$this->quoteService->method('setPolicy')
			->willThrowException(new InvalidResourceException('no such post'));

		$response = $this->controller()->statusInteractionPolicy(9);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testRevokingAQuoteThatIsNotThereIsNotFound(): void {
		$this->loggedInAs();
		$this->quoteService->method('revoke')->willReturn(false);

		$this->assertSame(
			Http::STATUS_NOT_FOUND, $this->controller()->statusQuoteRevoke(9, 11)->getStatus()
		);
	}

	public function testRevokingAQuoteAnswersPlainlyWhenItWorked(): void {
		$this->loggedInAs();
		$this->quoteService->expects($this->once())->method('revoke')
			->with(9, $this->anything(), 11)->willReturn(true);

		$this->assertSame(
			Http::STATUS_OK, $this->controller()->statusQuoteRevoke(9, 11)->getStatus()
		);
	}

	// the year an account had

	public function testTheAnnualReportsAreWrappedTheWayMastodonWrapsThem(): void {
		$this->loggedInAs();
		$this->annualReportService->method('years')->willReturn([2025]);
		$this->annualReportService->method('forYear')->willReturn([
			'year' => 2025,
			'data' => ['archetype' => 'oracle', 'time_series' => [], 'top_hashtags' => [],
				'top_statuses' => ['by_reblogs' => '9', 'by_replies' => null, 'by_favourites' => null]],
			'schema_version' => 1,
			'share_url' => null,
			'account_id' => '3',
		]);
		$best = $this->createStub(Stream::class);
		$this->streamService->method('getStreamByNid')->with(9)->willReturn($best);

		$data = $this->controller()->annualReports()->getData();

		$this->assertCount(1, $data['annual_reports']);
		$this->assertSame([$best], $data['statuses'], 'the posts a report names travel with it');
		$this->assertCount(1, $data['accounts']);
	}

	/** A post deleted since it was the year's best does not take the report with it. */
	public function testAReportWhosePostIsGoneStillComesBack(): void {
		$this->loggedInAs();
		$this->annualReportService->method('years')->willReturn([2025]);
		$this->annualReportService->method('forYear')->willReturn([
			'year' => 2025,
			'data' => ['top_statuses' => ['by_reblogs' => '9']],
			'schema_version' => 1, 'share_url' => null, 'account_id' => '3',
		]);
		$this->streamService->method('getStreamByNid')
			->willThrowException(new StreamNotFoundException());

		$data = $this->controller()->annualReports()->getData();

		$this->assertCount(1, $data['annual_reports']);
		$this->assertSame([], $data['statuses']);
	}

	/** Twelve empty months is worse than saying there is nothing. */
	public function testAYearWithNoReportAnswersWithAnEmptyWrapper(): void {
		$this->loggedInAs();
		$this->annualReportService->method('state')->willReturn('ineligible');
		$this->annualReportService->expects($this->never())->method('forYear');

		$data = $this->controller()->annualReport(2019)->getData();

		$this->assertSame([], $data['annual_reports']);
		$this->assertSame([], $data['accounts']);
	}

	public function testTheStateIsWhatTheServiceSays(): void {
		$this->loggedInAs();
		$this->annualReportService->method('state')->with($this->anything(), 2025)
			->willReturn('available');

		$this->assertSame(['state' => 'available'], $this->controller()->annualReportState(2025)->getData());
	}

	public function testMarkingAReportReadReachesTheService(): void {
		$this->loggedInAs();
		$this->annualReportService->expects($this->once())->method('markRead')->with('alice', 2025);

		$this->assertSame(Http::STATUS_OK, $this->controller()->annualReportRead(2025)->getStatus());
	}

	/**
	 * There is nothing to generate — the report is ready the moment it is
	 * asked for — but a Mastodon client calls this before it reads, and a 404
	 * there is a client that never asks again.
	 */
	public function testAskingForOneToBeGeneratedAnswersPlainly(): void {
		$this->loggedInAs();

		$this->assertSame(Http::STATUS_OK, $this->controller()->annualReportGenerate(2025)->getStatus());
	}

	public function testAccountsSearchCompletesAHandle(): void {
		$this->loggedInAs();
		$bob = $this->createStub(Person::class);
		$bob->method('getId')->willReturn('https://remote.example/users/bob');
		$bob->method('setExportFormat')->willReturnSelf();
		$this->searchService->expects($this->once())->method('searchAccounts')->with('bob', 40)->willReturn([$bob]);
		$this->searchService->expects($this->never())->method('searchUri');

		$this->assertSame([$bob], $this->controller()->accountsSearch('bob')->getData());
	}

	/** Without this, completing a handle from a server we have never seen finds nothing. */
	public function testAccountsSearchGoesAndLooksWhenAskedTo(): void {
		$this->loggedInAs();
		$bob = $this->createStub(Person::class);
		$bob->method('getId')->willReturn('https://remote.example/users/bob');
		$bob->method('setExportFormat')->willReturnSelf();
		$this->searchService->expects($this->once())
			->method('searchAccounts')->with('@bob@remote.example', 40)->willReturn([]);
		$this->searchService->expects($this->once())
			->method('searchUri')->with('@bob@remote.example')->willReturn([$bob]);

		$data = $this->controller()->accountsSearch('@bob@remote.example', 40, true)->getData();

		$this->assertSame([$bob], $data);
	}

	public function testAccountsSearchWithoutATermIsAnEmptyList(): void {
		$this->loggedInAs();
		$this->searchService->expects($this->never())->method('searchAccounts');

		$this->assertSame([], $this->controller()->accountsSearch('  ')->getData());
	}

	public function testAccountsSearchRequiresAViewer(): void {
		$this->assertUnauthorized($this->controller()->accountsSearch('bob'));
	}

	public function testAccountsSearchDoesNotResolvePlainSearchTerms(): void {
		$this->loggedInAs();
		$bob = $this->createStub(Person::class);
		$bob->method('getId')->willReturn('https://remote.example/users/bob');
		$bob->method('setExportFormat')->willReturnSelf();
		$this->searchService->expects($this->once())->method('searchAccounts')->with('bob', 40)->willReturn([$bob]);
		$this->searchService->expects($this->never())->method('searchUri');

		$this->assertSame([$bob], $this->controller()->accountsSearch('bob', 40, true)->getData());
	}

	public function testAccountsSearchCapsFollowingChecksAtTheRequestedLimit(): void {
		$this->loggedInAs();
		$resolved = $this->createStub(Person::class);
		$resolved->method('getId')->willReturn('https://remote.example/users/resolved');
		$resolved->method('setExportFormat')->willReturnSelf();
		$cached = $this->createStub(Person::class);
		$cached->method('getId')->willReturn('https://remote.example/users/cached');
		$cached->method('setExportFormat')->willReturnSelf();
		$this->searchService->expects($this->once())
			->method('searchAccounts')->with('@bob@remote.example', 1)->willReturn([$cached]);
		$this->searchService->expects($this->once())
			->method('searchUri')->with('@bob@remote.example')->willReturn([$resolved]);
		$relationship = (new Relationship(42))->setFollowing(true);
		$this->followService->expects($this->once())
			->method('getRelationshipWith')->with($resolved)->willReturn($relationship);

		$this->assertSame(
			[$resolved],
			$this->controller()->accountsSearch('@bob@remote.example', 1, true, true)->getData()
		);
	}

	/**
	 * The search is narrowed, not its first page: filtering the first `limit`
	 * matches found nobody whenever the followed account ranked below them.
	 */
	public function testAccountsSearchNarrowsTheSearchItselfToFollowedAccounts(): void {
		$this->loggedInAs();
		$bob = $this->createStub(Person::class);
		$bob->method('getId')->willReturn('https://remote.example/users/bob');
		$bob->method('setExportFormat')->willReturnSelf();
		$this->searchService->expects($this->once())
			->method('searchAccounts')
			->with('bob', 8, 'https://cloud.example/apps/social/@alice')
			->willReturn([$bob]);
		$this->followService->expects($this->never())->method('getRelationshipWith');

		$this->assertSame([$bob], $this->controller()->accountsSearch('bob', 8, false, true)->getData());
	}

	public function testAnOrdinaryAccountsSearchIsNotNarrowed(): void {
		$this->loggedInAs();
		$this->searchService->expects($this->once())
			->method('searchAccounts')->with('bob', 8, '')->willReturn([]);

		$this->assertSame([], $this->controller()->accountsSearch('bob', 8)->getData());
	}

	// instance/peers, instance/activity, preferences, familiar_followers

	public function testPeersAnswersTheInstancesThisOneHasHeardOf(): void {
		$this->instanceService->method('getPeers')->willReturn(['a.example', 'b.example']);

		$response = $this->controller()->instancePeers();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['a.example', 'b.example'], $response->getData());
	}

	public function testActivityAnswersTheWeeklySeries(): void {
		$week = ['week' => '1700000000', 'statuses' => '12', 'logins' => '0', 'registrations' => '0'];
		$this->instanceService->method('getWeeklyActivity')->willReturn([$week]);

		$this->assertSame([$week], $this->controller()->instanceActivity()->getData());
	}

	/**
	 * A client that cannot read these guesses, and the guess it makes is
	 * `public` — which is how somebody whose default is followers-only ends up
	 * posting to the world.
	 */
	public function testPreferencesCarryTheAccountsPostingDefaults(): void {
		$viewer = $this->loggedInAs();
		$this->viewerSource = ['sensitive' => true, 'language' => 'de'];
		// the shared mock reads this property, see setUp()
		$this->defaultPrivacy = 'private';

		$data = $this->controller()->preferences()->getData();

		$this->assertSame('private', $data['posting:default:visibility']);
		$this->assertTrue($data['posting:default:sensitive']);
		$this->assertSame('de', $data['posting:default:language']);
		$this->assertSame('default', $data['reading:expand:media']);
	}

	public function testPreferencesEchoTheNotificationDeliverySetting(): void {
		$this->loggedInAs();
		$this->delivery = ['mode' => 'digest', 'times' => ['07:00'], 'quiet' => ['from' => '22:00', 'to' => '06:00']];

		$data = $this->controller()->preferences()->getData();

		$this->assertSame([
			'mode' => 'digest',
			'times' => ['07:00'],
			'passthrough' => ['direct' => true, 'mentions_from_followed' => true],
			'quiet' => ['from' => '22:00', 'to' => '06:00'],
		], $data['notifications:delivery']);
	}

	public function testPreferencesEchoTheDeliveryDefaultsForAnUntouchedAccount(): void {
		$this->loggedInAs();

		$this->assertSame('instant', $this->controller()->preferences()->getData()['notifications:delivery']['mode']);
	}

	public function testPreferencesEchoTheAiContentSwitch(): void {
		$this->loggedInAs();

		$this->assertFalse($this->controller()->preferences()->getData()['reading:hide:ai']);

		$this->hidesAi = true;
		$this->assertTrue($this->controller()->preferences()->getData()['reading:hide:ai']);
	}

	public function testPreferencesEchoTheCountsSwitch(): void {
		$this->loggedInAs();

		$this->assertTrue($this->controller()->preferences()->getData()['reading:hide:counts'], 'hidden unless turned off');

		$this->hidesCounts = false;
		$this->assertFalse($this->controller()->preferences()->getData()['reading:hide:counts']);
	}

	public function testPreferencesReportNoLanguageAsNullRatherThanEmpty(): void {
		$viewer = $this->loggedInAs();
		$this->viewerSource = ['language' => ''];

		$this->assertNull($this->controller()->preferences()->getData()['posting:default:language']);
	}

	public function testPreferencesRequireAViewer(): void {
		$this->assertUnauthorized($this->controller()->preferences());
	}

	public function testFamiliarFollowersAnswersPerAccount(): void {
		$viewer = $this->loggedInAs();
		$target = $this->knownTarget();
		$target->method('getNid')->willReturn(42);
		$known = $this->createStub(Person::class);
		$known->method('setExportFormat')->willReturnSelf();
		$this->followService->expects($this->once())
			->method('familiarFollowers')
			->with($this->identicalTo($viewer), $this->identicalTo($target))
			->willReturn([$known]);

		$data = $this->controller()->familiarFollowers(['42'])->getData();

		$this->assertCount(1, $data);
		$this->assertSame('42', $data[0]['id']);
		$this->assertSame([$known], $data[0]['accounts']);
	}

	/** A client may send one id or twenty; both are `id`. */
	public function testFamiliarFollowersAcceptsASingleId(): void {
		$this->loggedInAs();
		$target = $this->knownTarget();
		$target->method('getNid')->willReturn(42);
		$this->followService->method('familiarFollowers')->willReturn([]);

		$this->assertCount(1, $this->controller()->familiarFollowers('42')->getData());
	}

	// search v2

	public function testSearchV2BundlesAccountsStatusesAndHashtags(): void {
		$this->loggedInAs();
		$account = $this->createStub(Person::class);
		$account->method('getId')->willReturn('https://remote.example/@bob');
		$account->method('setExportFormat')->willReturnSelf();
		$this->searchService->method('searchUri')->with('bob')->willReturn([]);
		$this->searchService->method('searchAccounts')->with('bob')->willReturn([$account, $account]);
		$status = $this->createStub(Stream::class);
		$this->searchService->method('searchStreamContent')->with('bob')->willReturn([$status]);
		$this->searchService->method('searchHashtags')->with('bob')
			->willReturn([['hashtag' => 'bobcats', 'trend' => []]]);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/tags/bobcats');

		$response = $this->controller()->searchV2('  bob ');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $data['accounts'], 'duplicates collapse on the actor id');
		$this->assertSame([$status], $data['statuses']);
		$this->assertSame('bobcats', $data['hashtags'][0]['name']);
		$this->assertSame('https://cloud.example/tags/bobcats', $data['hashtags'][0]['url']);
	}

	public function testSearchV2CanBeNarrowedByType(): void {
		$this->loggedInAs();
		$this->searchService->expects($this->never())->method('searchStreamContent');
		$this->searchService->expects($this->never())->method('searchHashtags');
		$this->searchService->method('searchUri')->willReturn([]);
		$this->searchService->method('searchAccounts')->willReturn([]);

		$data = $this->controller()->searchV2('bob', 'accounts')->getData();

		$this->assertSame([], $data['accounts']);
		$this->assertSame([], $data['statuses']);
		$this->assertSame([], $data['hashtags']);
	}

	/**
	 * A reader who pasted a link is asking for a fetch; everybody else is not.
	 */
	public function testSearchV2OnlyFetchesARemotePostWhenAskedTo(): void {
		$this->loggedInAs();
		$this->searchService->method('searchUri')->willReturn([]);
		$this->searchService->method('searchAccounts')->willReturn([]);
		$this->searchService->method('searchStreamContent')->willReturn([]);
		$this->searchService->method('searchHashtags')->willReturn([]);
		$this->searchService->expects($this->never())->method('resolveStatus');

		$data = $this->controller()->searchV2('https://remote.example/notes/1')->getData();

		$this->assertSame([], $data['statuses']);
	}

	public function testSearchV2ResolvesARemotePostOnRequest(): void {
		$this->loggedInAs();
		$resolved = $this->createStub(Stream::class);
		$resolved->method('setExportFormat')->willReturnSelf();
		$this->searchService->method('searchUri')->willReturn([]);
		$this->searchService->method('searchAccounts')->willReturn([]);
		$this->searchService->method('searchStreamContent')->willReturn([]);
		$this->searchService->method('searchHashtags')->willReturn([]);
		$this->searchService->expects($this->once())
			->method('resolveStatus')
			->with('https://remote.example/notes/1')
			->willReturn($resolved);

		$data = $this->controller()->searchV2('https://remote.example/notes/1', '', 20, true)->getData();

		$this->assertSame([$resolved], $data['statuses']);
	}

	/** What is already here is the answer; nothing goes out over the network. */
	public function testSearchV2DoesNotFetchWhenTheSearchAlreadyFoundSomething(): void {
		$this->loggedInAs();
		$status = $this->createStub(Stream::class);
		$this->searchService->method('searchUri')->willReturn([]);
		$this->searchService->method('searchAccounts')->willReturn([]);
		$this->searchService->method('searchStreamContent')->willReturn([$status]);
		$this->searchService->method('searchHashtags')->willReturn([]);
		$this->searchService->expects($this->never())->method('resolveStatus');

		$data = $this->controller()->searchV2('https://remote.example/notes/1', '', 20, true)->getData();

		$this->assertSame([$status], $data['statuses']);
	}

	/**
	 * Mastodon's `account_id` narrows the posts and nothing else: a client that
	 * wants the posts alone sends `type=statuses` as well.
	 */
	public function testSearchV2NarrowsThePostsToTheNamedAccount(): void {
		$this->loggedInAs();
		$alice = $this->createStub(Person::class);
		$alice->method('getId')->willReturn('https://cloud.example/users/alice');
		$alice->method('setExportFormat')->willReturnSelf();
		$this->cacheActorService->method('getFromNids')->with([42])->willReturn([$alice]);
		$this->searchService->method('searchUri')->willReturn([]);
		$this->searchService->method('searchAccounts')->with('fox')->willReturn([$alice]);
		$this->searchService->method('searchHashtags')->with('fox')
			->willReturn([['hashtag' => 'foxes', 'trend' => []]]);
		$status = $this->createStub(Stream::class);
		$this->searchService->expects($this->once())->method('searchStreamContent')
			->with('fox', 20, 0, 'https://cloud.example/users/alice', 0, 0)
			->willReturn([$status]);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/tags/foxes');

		$data = $this->controller()->searchV2('fox', '', 20, false, '42')->getData();

		$this->assertSame([$status], $data['statuses']);
		$this->assertCount(1, $data['accounts'], 'accounts are not narrowed');
		$this->assertSame('foxes', $data['hashtags'][0]['name'], 'hashtags are not narrowed');
	}

	public function testSearchV2FindsNoPostsOfAnAccountItDoesNotKnow(): void {
		$this->loggedInAs();
		$this->cacheActorService->method('getFromNids')->willReturn([]);
		$this->searchService->expects($this->never())->method('searchStreamContent');
		$this->searchService->expects($this->never())->method('resolveStatus');

		$response = $this->controller()->searchV2('https://remote.example/notes/1', 'statuses', 20, true, '999');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData()['statuses']);
	}

	public function testSearchV2PagesThePosts(): void {
		$this->loggedInAs();
		$this->searchService->expects($this->once())->method('searchStreamContent')
			->with('fox', 10, 30, '', '1791284215592836068', 0)
			->willReturn([]);

		$this->controller()->searchV2('fox', 'statuses', 10, false, '', 30, '1791284215592836068', 'not-an-id');
	}

	/** A later page that found nothing is the end of the answer, not a link to fetch. */
	public function testSearchV2ResolvesOnlyOnTheFirstPage(): void {
		$this->loggedInAs();
		$this->searchService->method('searchStreamContent')->willReturn([]);
		$this->searchService->expects($this->never())->method('resolveStatus');

		$this->controller()->searchV2('https://remote.example/notes/1', 'statuses', 20, true, '', 20);
	}

	public function testSearchV2RequiresAViewer(): void {
		$this->searchService->expects($this->never())->method('searchAccounts');

		$this->assertUnauthorized($this->controller()->searchV2('bob'));
	}

	// trends

	public function testTrendTagsReturnsTagEntitiesForWhatIsTrending(): void {
		// the entity is built by HashtagService, which the tag lookup and the
		// follow answers use too — the shape is pinned in HashtagServiceTest;
		// what belongs here is that each trending tag is asked for, for the
		// window that was requested, and without a `following` this public
		// route has nobody to answer for
		$this->loggedInAs();
		$this->hashtagService->method('getTrending')->with(5, '1h')->willReturn([
			['hashtag' => 'nextcloud', 'trend' => ['1h' => 12, '1d' => 40]],
			['hashtag' => 'fediverse', 'trend' => ['1h' => 3]],
		]);
		$this->hashtagService->expects($this->exactly(2))->method('tagEntity')
			->willReturnCallback(static function (string $name, ?bool $following, string $period): array {
				self::assertNull($following, 'a public route knows no viewer to answer `following` for');
				self::assertSame('1h', $period);

				return ['name' => $name, 'url' => 'https://cloud.example/tags/' . $name, 'history' => []];
			});

		$tags = $this->controller()->trendTags(5, '1h')->getData();

		$this->assertSame(['nextcloud', 'fediverse'], array_column($tags, 'name'));
		$this->assertSame('https://cloud.example/tags/nextcloud', $tags[0]['url']);
	}

	public function testTrendTagsCapsTheLimit(): void {
		$this->loggedInAs();
		$this->hashtagService->expects($this->once())->method('getTrending')
			->with(20, HashtagService::PERIOD_DEFAULT)->willReturn([]);

		$this->assertSame([], $this->controller()->trendTags(500)->getData());
	}

	public function testTrendTagsIsReadableWithoutAViewer(): void {
		// a public instance shows what is trending to anyone who can read it
		$this->hashtagService->method('getTrending')->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->trendTags()->getStatus());
	}

	// reports

	public function testReportNewFilesAReportAgainstTheResolvedAccount(): void {
		$viewer = $this->loggedInAs();
		$target = $this->knownTarget();
		$this->request->method('getParams')->willReturn([
			'account_id' => '42',
			'status_ids' => ['7', 8],
			'comment' => 'spam bot',
			'category' => 'spam',
		]);

		$report = new Report();
		$this->reportService->expects($this->once())
			->method('reportFromLocal')
			->with($this->identicalTo($viewer), $this->identicalTo($target), ['7', '8'], 'spam bot', 'spam', false)
			->willReturn($report);

		$response = $this->controller()->reportNew();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($report, $response->getData());
	}

	/**
	 * `forward` is what sends the report to the instance that hosts the
	 * account, which is the only one that can act on it; dropped here, the
	 * client is told the report was filed and the reported instance never
	 * hears of it.
	 */
	public function testReportNewCarriesTheForwardFlag(): void {
		$viewer = $this->loggedInAs();
		$target = $this->knownTarget();
		$this->request->method('getParams')->willReturn([
			'account_id' => '42', 'comment' => 'spam bot', 'forward' => 'true',
		]);
		$this->reportService->expects($this->once())
			->method('reportFromLocal')
			->with($this->identicalTo($viewer), $this->identicalTo($target), [], 'spam bot', 'other', true)
			->willReturn(new Report());

		$this->assertSame(Http::STATUS_OK, $this->controller()->reportNew()->getStatus());
	}

	public function testReportNewRequiresAnAccountId(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['comment' => 'no target']);
		$this->reportService->expects($this->never())->method('reportFromLocal');

		$response = $this->controller()->reportNew();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testReportNewRefusesReportingYourself(): void {
		$viewer = $this->loggedInAs();
		$this->request->method('getParams')->willReturn(['account_id' => '42']);
		$self = $this->createStub(Person::class);
		$self->method('getNid')->willReturn(42);
		$self->method('getId')->willReturn($viewer->getId());
		$this->cacheActorService->method('getFromNids')->with([42])->willReturn([$self]);
		$this->reportService->expects($this->never())->method('reportFromLocal');

		$response = $this->controller()->reportNew();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testReportNewRequiresAViewer(): void {
		$this->reportService->expects($this->never())->method('reportFromLocal');

		$this->assertUnauthorized($this->controller()->reportNew());
	}

	public function testReportNewRefusesAReadOnlyToken(): void {
		$this->route = 'social.Api.reportNew';
		$this->bearerFor(['read']);
		$this->reportService->expects($this->never())->method('reportFromLocal');

		$this->assertInsufficientScope(
			$this->controller('Bearer s3cret')->reportNew(),
			'token scope does not allow this request (needs write:reports)'
		);
	}

	/**
	 * The first page asks for the outbox to be synced in the background and
	 * answers with what is stored; the sync used to run first, on every page,
	 * while the caller waited.
	 */
	public function testAccountStatusesFirstPageQueuesASyncAndAnswersWhatIsStored(): void {
		$this->loggedInAs();
		$actor = $this->createStub(Person::class);
		$actor->method('getId')->willReturn('https://remote.example/users/bob');
		$this->cacheActorService->method('getFromAccount')->with('bob@remote.example')->willReturn($actor);
		$this->streamService->expects($this->never())->method('syncRemoteTimeline');
		$this->remoteFetchQueue->expects($this->once())->method('syncTimeline')->with($actor);
		$this->captureTimelineOptions(['p']);

		$this->assertSame(['p'], $this->controller()->accountStatuses('bob@remote.example')->getData());
	}

	/** Scrolling down a profile is reading what is stored, not a reason to sync again. */
	public function testAccountStatusesLaterPagesDoNotSync(): void {
		$this->loggedInAs();
		$actor = $this->createStub(Person::class);
		$actor->method('getId')->willReturn('https://remote.example/users/bob');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		$this->streamService->expects($this->never())->method('syncRemoteTimeline');
		$this->remoteFetchQueue->expects($this->never())->method('syncTimeline');
		$this->captureTimelineOptions([]);

		$this->controller()->accountStatuses('bob@remote.example', 20, 40);
		$this->controller()->accountStatuses('bob@remote.example', 20, 0, 10);
		$this->controller()->accountStatuses('bob@remote.example', 20, 0, 0, '20');
	}

	public function testAccountStatusesProbesTheAccountTimeline(): void {
		$this->loggedInAs();
		$actor = $this->createStub(Person::class);
		$actor->method('getId')->willReturn('https://remote.example/users/bob');
		$this->cacheActorService->method('getFromAccount')->with('bob@remote.example')->willReturn($actor);
		$options = $this->captureTimelineOptions(['p']);

		$response = $this->controller()->accountStatuses('bob@remote.example', 5, 40, 10, 20);

		$this->assertSame(['p'], $response->getData());
		$probe = $options();
		$this->assertSame(ProbeOptions::ACCOUNT, $probe->getProbe());
		$this->assertSame('https://remote.example/users/bob', $probe->getAccountId());
		$this->assertSame(5, $probe->getLimit());
		$this->assertSame(40, $probe->getMaxId());
		$this->assertSame(10, $probe->getMinId());
		$this->assertSame(20, $probe->getSince());
	}

	/**
	 * What the profile's Photos and Videos tabs ask for. `only_media` is
	 * Mastodon's own parameter on this route and had never been passed on;
	 * `media_type` is the extension that tells the two tabs apart.
	 */
	public function testAccountStatusesPassesTheMediaFilterOn(): void {
		$actor = $this->createStub(Person::class);
		$actor->method('getId')->willReturn('https://remote.example/users/bob');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		$options = $this->captureTimelineOptions(['p']);

		$this->controller()->accountStatuses('bob@remote.example', 20, 0, 0, 0, false, true, 'video');

		$probe = $options();
		$this->assertTrue($probe->isOnlyMedia());
		$this->assertSame('video', $probe->getMediaType());
	}

	/** A tab that asks for everything must not quietly filter. */
	public function testAccountStatusesAsksForEverythingByDefault(): void {
		$actor = $this->createStub(Person::class);
		$actor->method('getId')->willReturn('https://remote.example/users/bob');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		$options = $this->captureTimelineOptions(['p']);

		$this->controller()->accountStatuses('bob@remote.example');

		$probe = $options();
		$this->assertFalse($probe->isOnlyMedia());
		$this->assertSame('', $probe->getMediaType());
	}

	public function testAccountStatusesFlagsThePinnedPostsOfThePage(): void {
		$actor = $this->createStub(Person::class);
		$actor->method('getId')->willReturn('https://remote.example/users/bob');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		$this->streamService->method('getTimeline')->willReturn(['p']);
		$this->pinService->expects($this->once())->method('markPinned')
			->with(['p'], 'https://remote.example/users/bob');

		$this->assertSame(['p'], $this->controller()->accountStatuses('bob@remote.example')->getData());
	}

	public function testAccountStatusesWithPinnedReturnsThePinsWithoutASync(): void {
		$actor = $this->createStub(Person::class);
		$actor->method('getId')->willReturn('https://remote.example/users/bob');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		$this->streamService->expects($this->never())->method('syncRemoteTimeline');
		$this->remoteFetchQueue->expects($this->never())->method('syncTimeline');
		$this->streamService->expects($this->never())->method('getTimeline');
		$this->pinService->expects($this->once())->method('getPinnedPosts')
			->with('https://remote.example/users/bob')->willReturn(['pinned']);

		$response = $this->controller()->accountStatuses('bob@remote.example', 20, 0, 0, 0, true);

		$this->assertSame(['pinned'], $response->getData());
	}

	public function testAccountStatusesOfUnknownAccountIsNotFound(): void {
		$this->cacheActorService->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException('who?'));

		$this->assertNotFound($this->controller()->accountStatuses('nobody'), 'who?');
	}

	private function localHosts(): void {
		$this->configService->method('getCloudHost')->willReturn('cloud.example');
		$this->configService->method('getSocialAddress')->willReturn('social.example');
	}

	public function testAccountFollowersOfLocalAccountAreProbedLocally(): void {
		$this->localHosts();
		$actor = $this->createStub(Person::class);
		$actor->method('getId')->willReturn('https://cloud.example/apps/social/@alice');
		$this->cacheActorService->method('getFromAccount')->with('alice@cloud.example')->willReturn($actor);
		$this->curlService->expects($this->never())->method('retrieveObject');
		$captured = null;
		$this->cacheActorService->method('probeActors')->willReturnCallback(function (ProbeOptions $o) use (&$captured): array {
			$captured = $o;

			return ['f'];
		});

		$response = $this->controller()->accountFollowers('alice@cloud.example', 3, 9, 8, 7);

		$this->assertSame(['f'], $response->getData());
		$this->assertSame(ProbeOptions::FOLLOWERS, $captured->getProbe());
		$this->assertSame('https://cloud.example/apps/social/@alice', $captured->getAccountId());
		$this->assertSame([3, 9, 8, 7], [$captured->getLimit(), $captured->getMaxId(), $captured->getMinId(), $captured->getSince()]);
	}

	public function testAccountFollowingOfBareUsernameIsProbedLocally(): void {
		$this->localHosts();
		$actor = $this->createStub(Person::class);
		$this->cacheActorService->method('getFromAccount')->with('alice')->willReturn($actor);
		$captured = null;
		$this->cacheActorService->method('probeActors')->willReturnCallback(function (ProbeOptions $o) use (&$captured): array {
			$captured = $o;

			return [];
		});

		$this->controller()->accountFollowing('alice');

		$this->assertSame(ProbeOptions::FOLLOWING, $captured->getProbe());
	}

	public function testAccountFollowersOfRemoteAccountAreFetchedFromTheirCollection(): void {
		$this->loggedInAs();
		$this->localHosts();
		$actor = $this->createStub(Person::class);
		$actor->method('getFollowers')->willReturn('https://remote.example/users/bob/followers');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		$this->curlService->method('retrieveObject')->willReturnMap([
			['https://remote.example/users/bob/followers', true, ['first' => 'https://remote.example/users/bob/followers?page=1']],
			['https://remote.example/users/bob/followers?page=1', true, ['orderedItems' => [
				'https://remote.example/users/x',
				'https://remote.example/users/y',
				'https://remote.example/users/z',
			]]],
		]);
		$x = $this->createMock(Person::class);
		$x->method('getNid')->willReturn(11);
		$y = $this->createStub(Person::class);
		$y->method('getNid')->willReturn(12);
		$this->cacheActorService->expects($this->once())->method('getCachedFromIds')
			->with(['https://remote.example/users/x', 'https://remote.example/users/y'])
			->willReturn(['https://remote.example/users/x' => $x, 'https://remote.example/users/y' => $y]);
		$x->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);
		$this->cacheActorService->expects($this->never())->method('probeActors');

		$response = $this->controller()->accountFollowers('bob@remote.example', 2);

		$this->assertSame([$x, $y], $response->getData(), 'limit is applied and the third actor is never asked for');
	}

	/**
	 * Resolving the entries over HTTP while the caller waited was a fetch per
	 * account at the federation timeout each: only what is cached is
	 * answered, and the rest is fetched in the background.
	 */
	public function testRemoteCollectionAnswersTheCachedAccountsAndQueuesTheRest(): void {
		$this->loggedInAs();
		$this->localHosts();
		$actor = $this->createStub(Person::class);
		$actor->method('getFollowing')->willReturn('https://remote.example/users/bob/following');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		$this->curlService->method('retrieveObject')->willReturn(['orderedItems' => [
			'https://remote.example/users/x',
			['id' => 'https://other.example/users/y', 'type' => 'Person'],
			'https://other.example/users/z#main-key',
		]]);
		$x = $this->createStub(Person::class);
		$x->method('getNid')->willReturn(11);
		$this->cacheActorService->method('getCachedFromIds')
			->willReturn(['https://remote.example/users/x' => $x]);
		$this->cacheActorService->expects($this->never())->method('getFromId');
		$this->remoteFetchQueue->expects($this->once())->method('resolveActors')
			->with(['https://other.example/users/y', 'https://other.example/users/z']);

		$this->assertSame([$x], $this->controller()->accountFollowing('bob@remote.example')->getData());
	}

	/** Paging through a profile or opening it again does not fetch the collection again. */
	public function testRemoteCollectionIsKeptForAFewMinutes(): void {
		$this->loggedInAs();
		$this->localHosts();
		$actor = $this->createStub(Person::class);
		$actor->method('getFollowers')->willReturn('https://remote.example/users/bob/followers');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		$this->curlService->expects($this->once())->method('retrieveObject')
			->willReturn(['orderedItems' => ['https://remote.example/users/x']]);
		$x = $this->createStub(Person::class);
		$x->method('getNid')->willReturn(11);
		$this->cacheActorService->method('getCachedFromIds')
			->willReturn(['https://remote.example/users/x' => $x]);

		$this->assertSame([$x], $this->controller()->accountFollowers('bob@remote.example')->getData());
		$this->assertSame([$x], $this->controller()->accountFollowers('bob@remote.example')->getData());
	}

	public function testRemoteCollectionDropsActorsWithoutANumericId(): void {
		$this->loggedInAs();
		$this->localHosts();
		$actor = $this->createStub(Person::class);
		$actor->method('getFollowers')->willReturn('https://remote.example/users/bob/followers');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		// the page carries the actors inline, as Mastodon's collections do
		$this->curlService->method('retrieveObject')->willReturn(['orderedItems' => [
			['id' => 'https://remote.example/users/x', 'type' => 'Person'],
			['id' => 'https://remote.example/users/y', 'type' => 'Person'],
		]]);

		$uncached = $this->createStub(Person::class);
		$uncached->method('getNid')->willReturn(0);
		$known = $this->createStub(Person::class);
		$known->method('getNid')->willReturn(9);
		$this->cacheActorService->method('getCachedFromIds')->willReturn([
			'https://remote.example/users/x' => $uncached,
			'https://remote.example/users/y' => $known,
		]);

		// every entity in the API is addressed by its numeric id, so a page of
		// accounts all sharing id "0" is one a client cannot act on at all
		$this->assertSame(
			[$known], $this->controller()->accountFollowers('bob@remote.example', 5)->getData()
		);
	}

	public function testRemoteCollectionFetchFailureYieldsAnEmptyList(): void {
		$this->loggedInAs();
		$this->localHosts();
		$actor = $this->createStub(Person::class);
		$actor->method('getFollowers')->willReturn('https://remote.example/users/bob/followers');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		$this->curlService->method('retrieveObject')->willThrowException(new \RuntimeException('timeout'));

		$response = $this->controller()->accountFollowers('bob@remote.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	public function testRemoteFollowerFanOutIsBoundedByMaxLimit(): void {
		$this->loggedInAs();
		$this->localHosts();
		$actor = $this->createStub(Person::class);
		$actor->method('getFollowers')->willReturn('https://remote.example/users/bob/followers');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);

		// a remote page far larger than MAX_LIMIT: the walk itself stops there,
		// whatever the (already large) limit the caller asked for
		$ids = [];
		for ($i = 0; $i < 500; $i++) {
			$ids[] = 'https://remote.example/users/u' . $i;
		}
		$this->curlService->method('retrieveObject')->willReturn(['orderedItems' => $ids]);
		$this->cacheActorService->method('getCachedFromIds')
			->willReturnCallback(function (array $asked): array {
				$this->assertLessThanOrEqual(ProbeOptions::MAX_LIMIT, count($asked));

				return [];
			});
		$this->remoteFetchQueue->expects($this->once())->method('resolveActors')
			->with($this->callback(fn (array $queued): bool => count($queued) <= ProbeOptions::MAX_LIMIT));

		$this->assertSame([], $this->controller()->accountFollowers('bob@remote.example', 500)->getData());
	}

	// favourites / notifications / tag

	public function testFavouritesProbeTheFavouritesTimeline(): void {
		$this->loggedInAs();
		$options = $this->captureTimelineOptions(['fav']);

		$response = $this->controller()->favourites(4, 30, 20, 10);

		$this->assertSame(['fav'], $response->getData());
		$probe = $options();
		$this->assertSame(ProbeOptions::FAVOURITES, $probe->getProbe());
		$this->assertSame([4, 30, 20, 10], [$probe->getLimit(), $probe->getMaxId(), $probe->getMinId(), $probe->getSince()]);
	}

	public function testFavouritesRequireAViewer(): void {
		$this->assertUnauthorized($this->controller()->favourites());
	}

	public function testNotificationsForwardTypeFilters(): void {
		$this->loggedInAs();
		$options = $this->captureTimelineOptions();

		$this->controller()->notifications(10, 0, 0, 0, ['mention', 'follow'], ['reblog'], 'https://x/acct');

		$probe = $options();
		$this->assertSame(ProbeOptions::NOTIFICATIONS, $probe->getProbe());
		$this->assertSame(['mention', 'follow'], $probe->getTypes());
		$this->assertSame(['reblog'], $probe->getExcludeTypes());
		$this->assertSame('https://x/acct', $probe->getAccountId());
		$this->assertSame(10, $probe->getLimit());
	}

	public function testNotificationsRequireAViewer(): void {
		$this->assertUnauthorized($this->controller()->notifications());
	}

	public function testTagProbesTheHashtagTimeline(): void {
		$this->loggedInAs();
		$options = $this->captureTimelineOptions(['t']);

		$response = $this->controller()->tag('nextcloud', 6, 0, 0, 0, true, true);

		$this->assertSame(['t'], $response->getData());
		$probe = $options();
		$this->assertSame(ProbeOptions::HASHTAG, $probe->getProbe());
		$this->assertSame('nextcloud', $probe->getArgument());
		$this->assertTrue($probe->isLocal());
		$this->assertTrue($probe->isOnlyMedia());
		$this->assertSame(6, $probe->getLimit());
	}

	// pagination Link headers

	/** A page of streams whose nids run from $high down to $low. */
	private function pageOfStreams(int $high, int $low): array {
		$posts = [];
		for ($nid = $high; $nid >= $low; $nid--) {
			$post = $this->createStub(Stream::class);
			$post->method('getNid')->willReturn($nid);
			$post->method('getSubType')->willReturn('');
			$posts[] = $post;
		}

		return $posts;
	}

	private function requestUri(string $uri): void {
		$this->request->method('getRequestUri')->willReturn($uri);
		$this->urlGenerator->method('getAbsoluteURL')
			->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);
	}

	/**
	 * masto.js — which Elk and Phanpy are both built on — takes the next page
	 * from the Link header and nowhere else, so without one those clients show
	 * the first page of a timeline and stop.
	 */
	public function testATimelinePageCarriesTheLinkHeaderMastodonPagesWith(): void {
		$this->loggedInAs();
		$this->requestUri('/index.php/apps/social/api/v1/timelines/home?limit=3');
		$this->captureTimelineOptions($this->pageOfStreams(30, 28));

		$link = $this->controller()->timelines('home', false, 3)->getHeaders()['Link'] ?? '';

		$this->assertStringContainsString(
			'<https://cloud.example/index.php/apps/social/api/v1/timelines/home'
			. '?limit=3&max_id=28>; rel="next"',
			$link
		);
		$this->assertStringContainsString('min_id=30>; rel="prev"', $link);
	}

	/**
	 * A page that lost a boost of a post already in it is still a full page.
	 * Deciding `rel="next"` from what was left of it stopped every client that
	 * pages on the Link header — Mastodon's own web client, Elk, Phanpy —
	 * one page in.
	 */
	public function testAPageThatDroppedADuplicateBoostStillOffersTheNextPage(): void {
		$this->loggedInAs();
		$this->requestUri('/api/v1/timelines/home');
		// the query read twenty rows; one was a boost of a post also in the page
		$this->timelineRows = 20;
		$this->captureTimelineOptions($this->pageOfStreams(30, 12));

		$link = $this->controller()->timelines('home', false, 20)->getHeaders()['Link'] ?? '';

		$this->assertStringContainsString('max_id=12>; rel="next"', $link);
	}

	public function testAPageShorterThanTheLimitHasNoNextLink(): void {
		$this->loggedInAs();
		$this->requestUri('/api/v1/timelines/home');
		$this->captureTimelineOptions($this->pageOfStreams(30, 29));

		$link = $this->controller()->timelines('home', false, 20)->getHeaders()['Link'] ?? '';

		// Mastodon stops offering a next page once one comes back short
		$this->assertStringNotContainsString('rel="next"', $link);
		$this->assertStringContainsString('rel="prev"', $link);
	}

	public function testAnEmptyPageHasNoLinkHeaderAtAll(): void {
		$this->loggedInAs();
		$this->requestUri('/api/v1/timelines/home');
		$this->captureTimelineOptions([]);

		$this->assertArrayNotHasKey('Link', $this->controller()->timelines('home')->getHeaders());
	}

	public function testTheCursorReplacesTheOldOneAndKeepsEveryOtherFilter(): void {
		$this->loggedInAs();
		$this->requestUri('/api/v1/timelines/home?limit=2&max_id=99&only_media=1');
		$this->captureTimelineOptions($this->pageOfStreams(50, 49));

		$link = $this->controller()->timelines('home', false, 2, 99)->getHeaders()['Link'] ?? '';

		$this->assertStringContainsString('only_media=1', $link);
		$this->assertStringNotContainsString('max_id=99', $link);
		$this->assertStringContainsString('max_id=49', $link);
	}

	public function testFollowerPagesArePagedByTheirActorIds(): void {
		$this->localHosts();
		$actor = $this->createStub(Person::class);
		$actor->method('getId')->willReturn('https://cloud.example/apps/social/@alice');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		$this->requestUri('/api/v1/accounts/alice/followers');

		$follower = $this->createStub(Person::class);
		$follower->method('getNid')->willReturn(17);
		$this->cacheActorService->method('probeActors')->willReturn([$follower]);

		$link = $this->controller()->accountFollowers('alice', 1)->getHeaders()['Link'] ?? '';

		$this->assertStringContainsString('max_id=17>; rel="next"', $link);
	}

	// accounts/{id} and accounts/lookup

	public function testAnAccountIsReachableByTheNumericIdEveryEntityEmits(): void {
		// without this route, tapping any author, mention, boost or notification
		// asked for a profile nothing answered
		$this->loggedInAs();
		$target = $this->knownTarget(42);
		$target->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);

		$response = $this->controller()->accountGet('42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($target, $response->getData());
	}

	public function testAnAccountIsAlsoReachableByHandle(): void {
		$this->loggedInAs();
		$target = $this->createStub(Person::class);
		$this->cacheActorService->expects($this->once())
			->method('getFromAccount')->with('bob@remote.example')->willReturn($target);

		$this->assertSame($target, $this->controller()->accountGet('@bob@remote.example')->getData());
	}

	public function testAnAccountIsAlsoReachableByActorUri(): void {
		$this->loggedInAs();
		$target = $this->createStub(Person::class);
		$this->cacheActorService->expects($this->once())
			->method('getFromId')->with('https://remote.example/users/bob')->willReturn($target);

		$this->assertSame(
			$target, $this->controller()->accountGet('https://remote.example/users/bob')->getData()
		);
	}

	public function testAnUnknownAccountIsA404NotA401(): void {
		$this->loggedInAs();
		$this->cacheActorService->method('getFromNids')->willReturn([]);

		$this->assertNotFound($this->controller()->accountGet('42'), 'unknown account');
	}

	/**
	 * These routes are public, and the reference is a string the caller writes.
	 * Resolving an unknown one means fetching whatever URL or handle they
	 * wrote, storing the actor and downloading its icon into appdata — an HTTP
	 * reflector, and a way to fill the disk a request at a time.
	 * `LocalController::knownActor()` refuses the same thing for the same
	 * reason. What this instance already knows stays readable by anybody.
	 */
	public function testAnAnonymousCallerCannotMakeTheInstanceFetchAnActorUri(): void {
		$this->cacheActorService->expects($this->never())->method('getFromId');
		$known = $this->createStub(Person::class);
		$this->cacheActorService->expects($this->once())
			->method('getCachedFromIds')
			->with(['https://remote.example/users/bob'])
			->willReturn(['https://remote.example/users/bob' => $known]);

		$this->assertSame(
			$known, $this->controller()->accountGet('https://remote.example/users/bob')->getData()
		);
	}

	public function testAnAnonymousCallerIsRefusedAnActorThisInstanceDoesNotKnow(): void {
		$this->cacheActorService->method('getCachedFromIds')->willReturn([]);

		$this->assertNotFound(
			$this->controller()->accountGet('https://remote.example/users/nobody'), 'unknown account'
		);
	}

	public function testAnAnonymousCallerCannotMakeTheInstanceWebfingerAHandle(): void {
		$target = $this->createStub(Person::class);
		// `false`: answered from the cache, never fetched
		$this->cacheActorService->expects($this->once())
			->method('getFromAccount')->with('bob@remote.example', false)->willReturn($target);

		$this->assertSame($target, $this->controller()->accountGet('@bob@remote.example')->getData());
	}

	public function testAnAnonymousCallerDoesNotMakeTheInstanceSyncARemoteOutbox(): void {
		$actor = $this->createStub(Person::class);
		$actor->method('getId')->willReturn('https://remote.example/users/bob');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		$this->streamService->expects($this->never())->method('syncRemoteTimeline');
		$this->remoteFetchQueue->expects($this->never())->method('syncTimeline');
		$this->captureTimelineOptions([]);

		$this->controller()->accountStatuses('bob@remote.example');
	}

	public function testAnAnonymousCallerDoesNotMakeTheInstanceReadARemoteCollection(): void {
		$this->localHosts();
		$actor = $this->createStub(Person::class);
		$actor->method('getId')->willReturn('https://remote.example/users/bob');
		$actor->method('getFollowers')->willReturn('https://remote.example/users/bob/followers');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		$this->curlService->expects($this->never())->method('retrieveObject');
		$this->cacheActorService->expects($this->once())->method('probeActors')->willReturn([]);

		$this->assertSame([], $this->controller()->accountFollowers('bob@remote.example')->getData());
	}

	public function testAccountLookupResolvesAHandleWithoutReachingOut(): void {
		$this->loggedInAs();
		$target = $this->createMock(Person::class);
		$target->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);
		// `false`: lookup answers from what is cached, it never fetches
		$this->cacheActorService->expects($this->once())
			->method('getFromAccount')->with('bob@remote.example', false)->willReturn($target);

		$this->assertSame(
			$target, $this->controller()->accountLookup('@bob@remote.example')->getData()
		);
	}

	public function testAccountLookupWithoutAnAcctIsUnprocessable(): void {
		$this->loggedInAs();

		$this->assertUnprocessable($this->controller()->accountLookup(''), 'acct is required');
	}

	public function testAccountStatusesAcceptsTheNumericId(): void {
		$this->loggedInAs();
		$local = $this->knownTarget(42);
		$local->method('getId')->willReturn('https://cloud.example/apps/social/@bob');
		$options = $this->captureTimelineOptions([]);

		$this->controller()->accountStatuses('42');

		$this->assertSame('https://cloud.example/apps/social/@bob', $options()->getAccountId());
	}

	// DELETE /statuses/{id} and /statuses/{id}/source

	/** The viewer's own status, as statusDelete()/statusSource() find it. */
	private function ownStatus(int $nid = 7, string $content = ''): Stream {
		$item = $this->createMock(Stream::class);
		$item->method('getNid')->willReturn($nid);
		$item->method('getType')->willReturn('Note');
		$item->method('getContent')->willReturn($content);
		$item->method('getAttributedTo')->willReturn('https://cloud.example/apps/social/@alice');
		$item->method('exportAsLocal')->willReturn(['id' => (string)$nid]);
		$this->streamService->method('getStreamByNid')->with($nid)->willReturn($item);

		return $item;
	}

	public function testStatusDeleteRemovesTheViewersOwnPostAndReturnsIt(): void {
		$this->loggedInAs();
		$item = $this->ownStatus(7);
		$this->streamService->expects($this->once())
			->method('deleteLocalItem')->with($this->identicalTo($item), 'Note');

		$response = $this->controller()->statusDelete(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// Mastodon answers with the status that went, which is what
		// "delete & redraft" puts back in the composer
		$this->assertSame(['id' => '7'], $response->getData());
	}

	public function testStatusDeleteOfSomebodyElsesPostIsA404AndDeletesNothing(): void {
		$this->loggedInAs();
		$item = $this->createStub(Stream::class);
		$item->method('getAttributedTo')->willReturn('https://cloud.example/apps/social/@bob');
		$this->streamService->method('getStreamByNid')->willReturn($item);
		$this->streamService->expects($this->never())->method('deleteLocalItem');

		// the same answer an unknown id gets: whether somebody else's post
		// exists is not this route's to tell
		$this->assertNotFound($this->controller()->statusDelete(7), 'Stream not found');
	}

	public function testStatusDeleteNeedsAWriteToken(): void {
		$this->route = 'social.StatusApi.statusDelete';
		$this->bearerFor(['read']);
		$this->streamService->expects($this->never())->method('deleteLocalItem');

		$this->assertInsufficientScope(
			$this->controller('Bearer s3cret')->statusDelete(7),
			'token scope does not allow this request (needs write:statuses)'
		);
	}

	public function testStatusSourceGivesBackTheTextThatWasWritten(): void {
		$this->loggedInAs();
		$item = $this->ownStatus(7, 'first line<br />second &amp; last');
		$item->method('getSpoilerText')->willReturn('spoilers');

		$response = $this->controller()->statusSource(7);

		$this->assertSame(
			[
				'id' => '7',
				'text' => "first line\nsecond & last",
				'spoiler_text' => 'spoilers',
			],
			$response->getData()
		);
	}

	public function testStatusSourceOfSomebodyElsesPostIsA404(): void {
		$this->loggedInAs();
		$item = $this->createStub(Stream::class);
		$item->method('getAttributedTo')->willReturn('https://cloud.example/apps/social/@bob');
		$this->streamService->method('getStreamByNid')->willReturn($item);

		$this->assertNotFound($this->controller()->statusSource(7), 'Stream not found');
	}

	// GET /statuses/{id}/delivery

	public function testStatusDeliveryTellsTheAuthorWhereTheirPostGotTo(): void {
		$this->loggedInAs();
		$item = $this->ownStatus(7);
		$item->method('getId')->willReturn('https://cloud.example/apps/social/@alice/7');
		$summary = [
			'delivered' => 2, 'sending' => 0, 'waiting' => 0, 'failing' => 1, 'abandoned' => 0, 'total' => 3,
			'retention' => 604800,
			'instances' => [['host' => 'slow.example', 'state' => 'failing', 'tries' => 4, 'last' => 1700000000]],
		];
		$this->deliveryService->method('forObject')
			->with('https://cloud.example/apps/social/@alice/7')->willReturn($summary);

		$response = $this->controller()->statusDelivery(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => '7'] + $summary + ['bluesky' => ['state' => 'not_applicable', 'uri' => '', 'url' => '']], $response->getData());
	}

	public function testStatusDeliveryOfSomebodyElsesPostIsA404(): void {
		$this->loggedInAs();
		$item = $this->createStub(Stream::class);
		$item->method('getAttributedTo')->willReturn('https://cloud.example/apps/social/@bob');
		$this->streamService->method('getStreamByNid')->willReturn($item);
		// which servers a post reached is a fact about the author's account and
		// nobody else's; the queue is not even asked
		$this->deliveryService->expects($this->never())->method('forObject');

		$this->assertNotFound($this->controller()->statusDelivery(7), 'Stream not found');
	}

	// Idempotency-Key

	private function bearerPostingA(string $statusNid): Stream {
		$this->route = 'social.StatusApi.statusNew';
		$this->bearerFor(['write']);
		$this->request->method('getParams')->willReturn(['status' => 'hello']);

		$activity = $this->createStub(ACore::class);
		$activity->method('getObjectId')->willReturn('https://cloud.example/apps/social/@alice/n1');
		$this->postService->method('createPost')->willReturn($activity);

		$item = $this->createMock(Stream::class);
		$item->method('getNid')->willReturn((int)$statusNid);
		$this->streamService->method('getStreamById')->willReturn($item);
		$this->streamService->method('getStreamByNid')->willReturn($item);

		return $item;
	}

	/**
	 * Tusky and Ivory retry a post when the connection drops, so on a flaky
	 * link the same post used to be created — and federated to every follower —
	 * more than once.
	 */
	public function testARetriedPostWithTheSameIdempotencyKeyIsNotPostedTwice(): void {
		$item = $this->bearerPostingA('11');
		$this->postService->expects($this->once())->method('createPost');

		$controller = $this->controllerWithHeaders('Bearer s3cret', ['Idempotency-Key' => 'k-1']);
		$first = $controller->statusNew();
		$second = $controller->statusNew();

		$this->assertSame($item, $first->getData());
		$this->assertSame($item, $second->getData(), 'the same status comes back');
	}

	public function testADifferentIdempotencyKeyPostsAgain(): void {
		$this->bearerPostingA('11');
		$this->postService->expects($this->exactly(2))->method('createPost');

		$this->controllerWithHeaders('Bearer s3cret', ['Idempotency-Key' => 'k-1'])->statusNew();
		$this->controllerWithHeaders('Bearer s3cret', ['Idempotency-Key' => 'k-2'])->statusNew();
	}

	public function testWithoutAnIdempotencyKeyEveryPostIsANewOne(): void {
		$this->bearerPostingA('11');
		$this->postService->expects($this->exactly(2))->method('createPost');

		$controller = $this->controller('Bearer s3cret');
		$controller->statusNew();
		$controller->statusNew();
	}

	// media scoping

	private function attachedDocument(bool $public = false): Document {
		$document = new Document();
		$document->setId('https://cloud.example/documents/local/1');
		$document->setPublic($public);
		$this->documentService->method('getMediaFromArray')->willReturn([$document]);

		return $document;
	}

	private function postWith(array $params): ?Post {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn($params);
		$activity = $this->createMock(ACore::class);
		$activity->method('getObjectId')->willReturn('https://cloud.example/apps/social/@alice/n1');

		$created = null;
		$this->postService->method('createPost')
			->willReturnCallback(function (Post $post) use (&$created, $activity): ACore {
				$created = $post;

				return $activity;
			});
		$this->streamService->method('getStreamById')->willReturn($this->createStub(Stream::class));
		$this->controller()->statusNew();

		return $created;
	}

	/**
	 * `public` on a cached document decides whether the unauthenticated
	 * /media/{uuid} route serves the file. Every upload used to be stored with
	 * it set, so an attachment to a direct message was, by the row's own
	 * account, readable by anybody who came by the uuid.
	 */
	public function testAttachmentsOfAPublicPostAreMarkedPublic(): void {
		$document = $this->attachedDocument(false);
		$this->cacheDocumentsRequest->expects($this->once())
			->method('update')->with($this->identicalTo($document));

		$this->postWith(['status' => 'hi', 'media_ids' => ['1'], 'visibility' => 'public']);

		$this->assertTrue($document->isPublic());
	}

	public function testAttachmentsOfADirectMessageAreNotPublic(): void {
		$document = $this->attachedDocument(true);
		$this->cacheDocumentsRequest->expects($this->once())
			->method('update')->with($this->identicalTo($document));

		$this->postWith(['status' => 'hi', 'media_ids' => ['1'], 'visibility' => 'direct']);

		$this->assertFalse($document->isPublic());
	}

	public function testAttachmentsOfAFollowersOnlyPostAreNotPublic(): void {
		$document = $this->attachedDocument(true);

		// Mastodon's followers-only value; the app calls it `followers`
		$this->postWith(['status' => 'hi', 'media_ids' => ['1'], 'visibility' => 'private']);

		$this->assertFalse($document->isPublic());
	}

	public function testAnAttachmentAlreadyScopedCorrectlyIsNotRewritten(): void {
		$this->attachedDocument(true);
		$this->cacheDocumentsRequest->expects($this->never())->method('update');

		$this->postWith(['status' => 'hi', 'media_ids' => ['1'], 'visibility' => 'unlisted']);
	}

	// instance

	public function testTheV2InstanceEntityIsServedToo(): void {
		// newer clients ask for v2 first and only fall back to v1 on a 404
		$instance = (new Instance())->setUri('cloud.example')->setTitle('Ours');
		$this->instanceService->method('getLocal')->willReturn($instance);

		$response = $this->controller()->instanceV2();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('cloud.example', $response->getData()['domain']);
	}

	// search

	public function testTheV1SearchPathAnswersWithTheMastodonShape(): void {
		// this path used to be the app's own web-UI search, which answered a
		// client with a Nextcloud envelope it could make nothing of
		$this->loggedInAs();
		$this->searchService->method('searchUri')->willReturn([]);
		$this->searchService->method('searchAccounts')->willReturn([]);
		$this->searchService->method('searchStreamContent')->willReturn([]);
		$this->searchService->method('searchHashtags')->willReturn([]);

		$data = $this->controller()->search('cats')->getData();

		$this->assertSame(['accounts', 'statuses', 'hashtags'], array_keys($data));
	}

	public function testSearchNarrowsByTypeLikeTheV2Route(): void {
		$this->loggedInAs();
		$this->searchService->expects($this->never())->method('searchAccounts');
		$this->searchService->method('searchStreamContent')->willReturn(['s']);
		$this->searchService->method('searchHashtags')->willReturn([]);

		$data = $this->controller()->search('cats', 'statuses')->getData();

		$this->assertSame(['s'], $data['statuses']);
		$this->assertSame([], $data['accounts']);
	}

	// notifications

	public function testANotificationTypeNoClientKnowsIsLeftOutOfThePage(): void {
		$this->loggedInAs();
		$known = $this->createStub(Stream::class);
		$known->method('getSubType')->willReturn('Like');
		$known->method('getNid')->willReturn(3);
		$unknown = $this->createStub(Stream::class);
		$unknown->method('getSubType')->willReturn('SomethingElse');
		$unknown->method('getNid')->willReturn(2);
		$this->captureTimelineOptions([$known, $unknown]);

		// it would serialise as `"type": ""`, which a client with a closed enum
		// cannot decode — and one bad entry loses the whole page
		$this->assertSame([$known], $this->controller()->notifications()->getData());
	}

	/**
	 * Whether there is a next page is what the query returned, not what
	 * survived the filter above: a page shortened here says nothing about
	 * older notifications, and Elk and Phanpy — which page on the `Link`
	 * header and nowhere else — stopped there with the rest still in the
	 * database.
	 */
	public function testAFilteredNotificationPageStillOffersTheNextOne(): void {
		$this->loggedInAs();
		$this->requestUri('/api/v1/notifications?limit=3');
		$page = [];
		foreach ([30, 29] as $nid) {
			$known = $this->createStub(Stream::class);
			$known->method('getSubType')->willReturn('Like');
			$known->method('getNid')->willReturn($nid);
			$page[] = $known;
		}
		$unknown = $this->createStub(Stream::class);
		$unknown->method('getSubType')->willReturn('SomethingElse');
		$unknown->method('getNid')->willReturn(28);
		$page[] = $unknown;
		$this->captureTimelineOptions($page);

		$response = $this->controller()->notifications(3);

		$this->assertCount(2, $response->getData());
		$link = $response->getHeaders()['Link'] ?? '';
		$this->assertStringContainsString('max_id=28>; rel="next"', $link);
	}

	// public timeline

	public function testThePublicTimelineIsReadableWithoutTheClientHavingAToken(): void {
		// a client asks for it before it has one, to show what is here
		$this->userSession->method('getUser')->willReturn(null);
		$options = $this->captureTimelineOptions([]);

		$response = $this->controller()->timelines('public');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(ProbeOptions::PUBLIC, $options()->getProbe());
	}

	public function testARevokedTokenOnThePublicTimelineStillSaysSo(): void {
		// silently serving the anonymous view would leave the client believing
		// its token is fine
		$this->clientService->method('getFromToken')->willThrowException(new ClientNotFoundException());
		$this->streamService->expects($this->never())->method('getTimeline');

		$this->assertUnauthorized($this->controller('Bearer gone')->timelines('public'));
	}

	public function testEveryOtherTimelineStillNeedsAViewer(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->streamService->expects($this->never())->method('getTimeline');

		$this->assertUnauthorized($this->controller()->timelines('home'));
	}

	// media

	public function testMediaNewWithoutUploadIsABadRequest(): void {
		$this->loggedInAs();

		$this->assertUnprocessable($this->controller()->mediaNew(), 'no media found');
	}

	public function testMediaNewRequiresAViewer(): void {
		$_FILES['file'] = ['tmp_name' => '/tmp/x', 'size' => 1, 'type' => 'image/png', 'error' => UPLOAD_ERR_OK];
		$this->cacheDocumentService->expects($this->never())->method('saveFromTempToCache');

		$this->assertUnauthorized($this->controller()->mediaNew());
	}

	public function testMediaNewRefusesAFileOverTheSizeLimit(): void {
		$this->loggedInAs();
		$_FILES['file'] = [
			'tmp_name' => '/tmp/php-upload',
			'size' => 10 * 1048576 + 1,
			'type' => 'image/png',
			'error' => UPLOAD_ERR_OK,
		];
		$this->cacheDocumentService->expects($this->never())->method('saveFromTempToCache');

		$this->assertUnprocessable(
			$this->controller()->mediaNew(), 'file is larger than the 10MB limit'
		);
	}

	/**
	 * A file PHP refused for its size is answered with the limit a client can
	 * shrink it to, and the reason is logged for the admin who can raise it.
	 */
	#[DataProvider('oversizedUploadErrors')]
	public function testMediaNewNamesTheLimitOfAFilePhpRefusedForItsSize(int $error): void {
		$this->loggedInAs();
		$_FILES['file'] = ['tmp_name' => '', 'size' => 0, 'type' => 'image/jpeg', 'error' => $error];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('larger than this server accepts'));
		$this->cacheDocumentService->expects($this->never())->method('saveFromTempToCache');

		$this->assertUnprocessable(
			$this->controllerWithHeaders('', [], $logger)->mediaNew(),
			'the file is larger than this server accepts (2 MB)'
		);
	}

	public static function oversizedUploadErrors(): array {
		return [
			'over upload_max_filesize' => [UPLOAD_ERR_INI_SIZE],
			'over the form\'s MAX_FILE_SIZE' => [UPLOAD_ERR_FORM_SIZE],
		];
	}

	public function testMediaNewNamesTheVideoLimitForAVideo(): void {
		$this->loggedInAs();
		$this->instanceService = $this->createMock(InstanceService::class);
		$this->instanceService->method('imageSizeLimit')->willReturn(2 * 1048576);
		$this->instanceService->method('videoSizeLimit')->willReturn(64 * 1048576);
		$_FILES['file'] = ['tmp_name' => '', 'size' => 0, 'type' => 'video/mp4', 'error' => UPLOAD_ERR_INI_SIZE];

		$this->assertUnprocessable(
			$this->controller()->mediaNew(), 'the file is larger than this server accepts (64 MB)'
		);
	}

	public function testMediaNewAsksForARetryOfAnInterruptedUpload(): void {
		$this->loggedInAs();
		$_FILES['file'] = ['tmp_name' => '', 'size' => 0, 'type' => '', 'error' => UPLOAD_ERR_PARTIAL];

		$this->assertUnprocessable(
			$this->controller()->mediaNew(), 'the file upload was interrupted, try again'
		);
	}

	/**
	 * Nothing is wrong with the file when PHP cannot store it, so the answer
	 * is a 500 that says so instead of a refusal of the file.
	 */
	#[DataProvider('serverUploadErrors')]
	public function testMediaNewAnswersAServerProblemAsOne(int $error, string $reason): void {
		$this->loggedInAs();
		$_FILES['file'] = ['tmp_name' => '', 'size' => 0, 'type' => 'image/png', 'error' => $error];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains($reason));
		$logger->expects($this->never())->method('error');

		$response = $this->controllerWithHeaders('', [], $logger)->mediaNew();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(
			['error' => 'the file could not be received because of a problem on this server (' . $reason . ')'],
			$response->getData()
		);
	}

	public static function serverUploadErrors(): array {
		return [
			'no temporary directory' => [UPLOAD_ERR_NO_TMP_DIR, 'PHP has no temporary directory for uploads'],
			'cannot write' => [UPLOAD_ERR_CANT_WRITE, 'PHP could not write the upload to disk'],
			'an extension stopped it' => [UPLOAD_ERR_EXTENSION, 'a PHP extension stopped the upload'],
		];
	}

	/** PHP empties `$_FILES` for a body over post_max_size; that is a size refusal, not a missing file. */
	public function testMediaNewRecognisesABodyOverPostMaxSize(): void {
		$this->loggedInAs();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('post_max_size'));

		$this->assertUnprocessable(
			$this->controllerWithHeaders('', ['Content-Length' => (string)(8 * 1048576 + 1)], $logger)->mediaNew(),
			'the file is larger than this server accepts (2 MB)'
		);
	}

	public function testMediaNewWithASmallBodyAndNoFileFindsNoMedia(): void {
		$this->loggedInAs();

		$this->assertUnprocessable(
			$this->controllerWithHeaders('', ['Content-Length' => '512'])->mediaNew(), 'no media found'
		);
	}

	public function testMediaNewRejectsUploadsWithoutAType(): void {
		$this->loggedInAs();
		$_FILES['file'] = ['tmp_name' => '/tmp/upload', 'size' => 10, 'type' => '', 'error' => UPLOAD_ERR_OK];
		$this->cacheDocumentService->expects($this->never())->method('saveFromTempToCache');

		$this->assertSame(['error' => 'missing details'], $this->controller()->mediaNew()->getData());
	}

	/**
	 * A cover for a video arrives as `thumbnail`, the field Mastodon's API
	 * names, and is handed to the store once the video is in it.
	 */
	public function testMediaNewHandsACoverToTheStore(): void {
		$this->loggedInAs();
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');
		$saved = null;
		$tmpSeen = null;
		$this->expectDocumentSaved($saved, $tmpSeen);
		$this->instanceService->method('maxVideoUploadSize')->willReturn(2048 * 1048576);
		$video = tempnam(sys_get_temp_dir(), 'social-itest');
		$cover = tempnam(sys_get_temp_dir(), 'social-itest');
		$this->tempFiles[] = $video;
		$this->tempFiles[] = $cover;
		$_FILES['file'] = ['tmp_name' => $video, 'size' => 10, 'type' => 'video/mp4', 'error' => UPLOAD_ERR_OK, 'name' => 'short.mp4'];
		$_FILES['thumbnail'] = ['tmp_name' => $cover, 'size' => 10, 'type' => 'image/jpeg', 'error' => UPLOAD_ERR_OK];

		$this->cacheDocumentService->expects($this->once())
			->method('applyCustomPoster')
			->with($this->isInstanceOf(Document::class), $cover);

		$response = $this->controller()->mediaNew();
		unset($_FILES['thumbnail']);
		$this->assertSame(Http::STATUS_OK, $response->getStatus(), json_encode($response->getData()));
	}

	public function testMediaNewWithoutACoverKeepsThePosterItMade(): void {
		$this->loggedInAs();
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');
		$saved = null;
		$tmpSeen = null;
		$this->expectDocumentSaved($saved, $tmpSeen);
		$this->instanceService->method('maxVideoUploadSize')->willReturn(2048 * 1048576);
		$video = tempnam(sys_get_temp_dir(), 'social-itest');
		$this->tempFiles[] = $video;
		$_FILES['file'] = ['tmp_name' => $video, 'size' => 10, 'type' => 'video/mp4', 'error' => UPLOAD_ERR_OK, 'name' => 'short.mp4'];

		$this->cacheDocumentService->expects($this->never())->method('applyCustomPoster');

		$this->assertSame(Http::STATUS_OK, $this->controller()->mediaNew()->getStatus());
	}

	/**
	 * Mastodon sends the banner as `header` on this route, and it used to be
	 * accepted with a 200 and dropped — so changing it in a client appeared to
	 * work and did nothing.
	 */
	public function testUpdateCredentialsStoresAHeaderUpload(): void {
		$this->loggedInAs();
		$tmp = tempnam(sys_get_temp_dir(), 'social-itest');
		$this->tempFiles[] = $tmp;
		$_FILES['header'] = ['tmp_name' => $tmp, 'size' => 10, 'type' => 'image/png', 'error' => UPLOAD_ERR_OK];

		$this->bannerService->expects($this->once())
			->method('setFromTempFile')
			->with('alice', $tmp);

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	public function testUpdateCredentialsIgnoresAHeaderUploadThatFailed(): void {
		$this->loggedInAs();
		$_FILES['header'] = ['tmp_name' => '', 'size' => 0, 'type' => '', 'error' => UPLOAD_ERR_NO_FILE];

		$this->bannerService->expects($this->never())->method('setFromTempFile');

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	/**
	 * Mastodon clients send this route as multipart whenever a picture is in
	 * it, and PHP parses a multipart body for a POST only: the fields and the
	 * picture were never seen, and the client got a 200 over the old profile.
	 */
	public function testUpdateCredentialsAppliesAMultipartPatch(): void {
		$this->loggedInAs();
		$avatar = ['tmp_name' => '/tmp/parsed-avatar', 'error' => UPLOAD_ERR_OK, 'name' => 'me.png'];
		$header = ['tmp_name' => '/tmp/parsed-header', 'error' => UPLOAD_ERR_OK, 'name' => 'banner.png'];
		$this->multipartBodyService->method('read')->willReturn([
			'fields' => ['display_name' => 'Changed', 'source' => ['privacy' => 'unlisted']],
			'files' => ['avatar' => $avatar, 'header' => $header],
		]);

		$this->accountService->expects($this->once())->method('setDisplayName')->with('alice', 'Changed');
		$this->accountService->expects($this->once())->method('setDefaultPrivacy')->with('alice', 'unlisted');
		$this->avatarService->expects($this->once())->method('setFromTempFile')->with('alice', $avatar);
		$this->bannerService->expects($this->once())->method('setFromTempFile')->with('alice', '/tmp/parsed-header');

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	/** @param array<string, mixed> $fields */
	private function updateCredentialsSends(array $fields, array $files = []): void {
		$this->multipartBodyService->method('read')->willReturn(['fields' => $fields, 'files' => $files]);
	}

	/**
	 * Everything that can be refused is refused before anything is written:
	 * the bio used to be stored and the request answered with an error.
	 */
	public function testUpdateCredentialsRefusesAnUnknownAudienceBeforeWritingAnything(): void {
		$this->loggedInAs();
		$this->updateCredentialsSends([
			'note' => 'A new bio', 'display_name' => 'Alice', 'source' => ['privacy' => 'friends'],
		]);
		$this->accountService->method('assertDefaultPrivacy')
			->willThrowException(new InvalidActionException('unknown visibility: friends'));
		$this->accountService->expects($this->never())->method('setSummary');
		$this->accountService->expects($this->never())->method('setDisplayName');
		$this->accountService->expects($this->never())->method('setDefaultPrivacy');

		$this->assertUnprocessable(
			$this->controller()->updateCredentials(), 'unknown visibility: friends'
		);
	}

	public function testUpdateCredentialsRefusesANameTheBackendOwnsBeforeWritingAnything(): void {
		$this->loggedInAs();
		$this->updateCredentialsSends(['note' => 'A new bio', 'display_name' => 'Alice']);
		$this->accountService->method('assertDisplayNameWritable')
			->willThrowException(new InvalidActionException('the display name of this account is managed outside Nextcloud'));
		$this->accountService->expects($this->never())->method('setSummary');
		$this->accountService->expects($this->never())->method('setDisplayName');

		$this->assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller()->updateCredentials()->getStatus()
		);
	}

	public function testUpdateCredentialsRefusesAnAvatarThatIsNotAPictureBeforeWritingAnything(): void {
		$this->loggedInAs();
		$avatar = ['tmp_name' => '/tmp/parsed-avatar', 'error' => UPLOAD_ERR_OK, 'name' => 'me.png'];
		$this->updateCredentialsSends(['note' => 'A new bio'], ['avatar' => $avatar]);
		$this->avatarService->method('checkUpload')
			->willThrowException(new InvalidActionException('an avatar has to be a JPEG, PNG, GIF or WebP image'));
		$this->avatarService->expects($this->never())->method('setFromTempFile');
		$this->accountService->expects($this->never())->method('setSummary');

		$this->assertUnprocessable(
			$this->controller()->updateCredentials(), 'an avatar has to be a JPEG, PNG, GIF or WebP image'
		);
	}

	/**
	 * The banner is the one part whose bytes are only judged once stored, so
	 * it goes first and its refusal leaves the rest unwritten.
	 */
	public function testUpdateCredentialsWritesTheBannerFirstAndARefusedOneWritesNothing(): void {
		$this->loggedInAs();
		$header = ['tmp_name' => '/tmp/parsed-header', 'error' => UPLOAD_ERR_OK, 'name' => 'banner.txt'];
		$this->updateCredentialsSends(['note' => 'A new bio', 'display_name' => 'Alice'], ['header' => $header]);
		$this->bannerService->method('setFromTempFile')
			->willThrowException(new \OCA\Social\Exceptions\CacheContentMimeTypeException('not an image'));
		$this->accountService->expects($this->never())->method('setSummary');
		$this->accountService->expects($this->never())->method('setDisplayName');

		$this->assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller()->updateCredentials()->getStatus()
		);
	}

	/**
	 * A part that fails after the first was stored no longer turns the whole
	 * save into a 500 over half a profile: the rest is written, the failure
	 * logged, and the entity answered shows what was stored.
	 */
	public function testUpdateCredentialsStoresTheRestWhenALaterPartFails(): void {
		$this->loggedInAs();
		$this->updateCredentialsSends([
			'display_name' => 'Alice',
			'note' => 'A new bio',
			'fields_attributes' => [['name' => 'Web', 'value' => 'https://alice.example']],
		]);
		$written = [];
		$this->accountService->expects($this->once())->method('setDisplayName')
			->willReturnCallback(function () use (&$written): void {
				$written[] = 'display_name';
			});
		$this->accountService->expects($this->once())->method('setSummary')
			->willThrowException(new \RuntimeException('database went away'));
		$this->accountService->expects($this->once())->method('setFields')
			->willReturnCallback(function () use (&$written): void {
				$written[] = 'fields_attributes';
			});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('could not store note'));
		$logger->expects($this->never())->method('error');

		$response = $this->controllerWithHeaders('', [], $logger)->updateCredentials();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['display_name', 'fields_attributes'], $written);
	}

	public function testUpdateCredentialsWritesTheDisplayNameBeforeTheBio(): void {
		$this->loggedInAs();
		$this->updateCredentialsSends(['note' => 'A new bio', 'display_name' => 'Alice']);
		$written = [];
		$this->accountService->method('setSummary')->willReturnCallback(function () use (&$written): void {
			$written[] = 'note';
		});
		$this->accountService->method('setDisplayName')->willReturnCallback(function () use (&$written): void {
			$written[] = 'display_name';
		});

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
		$this->assertSame(['display_name', 'note'], $written);
	}

	public function testUpdateCredentialsAnswersTheFirstPartsFailureWhenNothingWasWritten(): void {
		$this->loggedInAs();
		$this->updateCredentialsSends(['display_name' => 'Alice', 'note' => 'A new bio']);
		$this->accountService->method('setDisplayName')->willThrowException(new \RuntimeException('backend down'));
		$this->accountService->expects($this->never())->method('setSummary');

		$this->assertServerError($this->controller()->updateCredentials());
	}

	/** A body that cannot be read is an error, not a 200 over the old profile. */
	public function testUpdateCredentialsRefusesAMultipartBodyItCannotRead(): void {
		$this->loggedInAs();
		$this->multipartBodyService->method('read')
			->willThrowException(new InvalidActionException('the multipart body could not be read'));
		$this->accountService->expects($this->never())->method('setDisplayName');

		$response = $this->controller()->updateCredentials();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	/**
	 * A picture that was sent and did not arrive refuses the request before
	 * any of it is written, so the client never shows half a save as done.
	 */
	public function testUpdateCredentialsRefusesAPictureThatWasTooLargeAndWritesNothing(): void {
		$this->loggedInAs();
		$this->multipartBodyService->method('read')->willReturn([
			'fields' => ['display_name' => 'Changed'],
			'files' => ['avatar' => ['tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'name' => 'huge.png']],
		]);
		$this->accountService->expects($this->never())->method('setDisplayName');
		$this->avatarService->expects($this->never())->method('setFromTempFile');

		$response = $this->controller()->updateCredentials();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(
			['error' => 'the avatar is larger than this server accepts (2 MB)'], $response->getData()
		);
	}

	public function testUpdateCredentialsAnswersAPictureTheServerCouldNotStoreAsAServerProblem(): void {
		$this->loggedInAs();
		$_FILES['header'] = ['tmp_name' => '', 'size' => 0, 'type' => '', 'error' => UPLOAD_ERR_CANT_WRITE];
		$this->accountService->expects($this->never())->method('setSummary');
		$this->bannerService->expects($this->never())->method('setFromTempFile');

		$response = $this->controller()->updateCredentials();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(
			['error' => 'the header could not be received because of a problem on this server (PHP could not write the upload to disk)'],
			$response->getData()
		);
	}

	public function testUpdateCredentialsRefusesAHeaderUploadThatStoppedHalfway(): void {
		$this->loggedInAs();
		$_FILES['header'] = ['tmp_name' => '', 'size' => 0, 'type' => '', 'error' => UPLOAD_ERR_PARTIAL];
		$this->bannerService->expects($this->never())->method('setFromTempFile');

		$this->assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller()->updateCredentials()->getStatus()
		);
	}

	// mediaFromFile()

	/**
	 * The viewer's own files, with one readable file at $path.
	 *
	 * @param ?string $relative what getRelativePath() reports for the node —
	 *                          null means the node resolved outside the folder
	 */
	private function userFolderHolding(
		string $path, string $contents = 'PNGDATA', ?string $relative = 'Photos/beach.jpg',
	): File {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $contents);
		rewind($stream);

		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(strlen($contents));
		$file->method('getName')->willReturn(basename($path));
		$file->method('getPath')->willReturn('/alice/files/' . ltrim($path, '/'));
		$file->method('fopen')->willReturn($stream);

		$folder = $this->userFolderMock();
		$folder->method('get')->with($path)->willReturn($file);
		$folder->method('getRelativePath')->willReturn($relative);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($folder);

		return $file;
	}

	/** A real temporary file, cleaned up after the test. */
	private function temporaryFile(): string {
		$tmp = tempnam(sys_get_temp_dir(), 'social-itest');
		$this->tempManager->method('getTemporaryFile')->willReturn($tmp);
		$this->tempFiles[] = $tmp;

		return $tmp;
	}

	private function expectDocumentSaved(?string &$saved, ?string &$tmpSeen): void {
		$this->cacheDocumentService->method('saveFromTempToCache')
			->willReturnCallback(function (Document $document, string $tmpPath) use (&$saved, &$tmpSeen): void {
				$saved = $document;
				$tmpSeen = $tmpPath;
			});
		$interface = $this->createStub(IActivityPubInterface::class);
		$interface->method('save');
		AP::set($this->createStub(AP::class));
		AP::instance()->method('getInterfaceForItem')->willReturn($interface);
	}

	/**
	 * The point of the route: the picture is already on this server, and the
	 * bytes are copied into the app's own store rather than referenced, so a
	 * post keeps what it was published with.
	 */
	/**
	 * A mock of whatever `IRootFolder::getUserFolder()` is declared to return.
	 *
	 * Nextcloud 36 narrowed that from `Folder` to `IUserFolder`, and the app
	 * supports 35 as well, so this follows whichever OCP the lockfile
	 * installed: PHPUnit checks a stubbed return value against the declared
	 * type, and refuses a plain `Folder` mock on 36.
	 */
	private function userFolderMock(): MockObject {
		return $this->createMock(
			interface_exists(IUserFolder::class) ? IUserFolder::class : Folder::class
		);
	}

	public function testMediaFromFileAttachesTheViewersOwnFile(): void {
		$this->loggedInAs();
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');
		$this->pathParam = '/Photos/beach.jpg';
		$this->userFolderHolding('/Photos/beach.jpg');
		$tmp = $this->temporaryFile();
		$this->expectDocumentSaved($saved, $tmpSeen);

		$response = $this->controller()->mediaFromFile();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertInstanceOf(MediaAttachment::class, $response->getData());
		$this->assertSame($tmp, $tmpSeen, 'the file was not copied through a temporary file');
		$this->assertSame('PNGDATA', file_get_contents($tmp), 'the bytes never reached the store');
		$this->assertTrue($saved->isLocal());
		// the same rule an upload follows: /media/{uuid} is unauthenticated and
		// serves only what this flag allows, and a post sets it later
		$this->assertFalse($saved->isPublic());
		$this->assertSame('alice', $saved->getAccount());
	}

	/** So that replies to the post can be shown on the file it was made from. */
	public function testMediaFromFileRemembersWhichFileItCameFrom(): void {
		$this->loggedInAs();
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');
		$this->pathParam = '/Photos/beach.jpg';
		$file = $this->userFolderHolding('/Photos/beach.jpg');
		$file->method('getId')->willReturn(4711);
		$this->temporaryFile();
		$this->expectDocumentSaved($saved, $tmpSeen);

		$this->fileCommentsService->expects($this->once())->method('rememberAttachment')
			->with('alice', 4711, $this->isString());

		$response = $this->controller()->mediaFromFile();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testMediaFromFileCarriesTheAltText(): void {
		$this->loggedInAs();
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');
		$this->pathParam = '/Photos/beach.jpg';
		$this->description = 'The sea at dusk';
		$this->userFolderHolding('/Photos/beach.jpg');
		$this->temporaryFile();
		$this->expectDocumentSaved($saved, $tmpSeen);

		$this->controller()->mediaFromFile();

		$this->assertSame('The sea at dusk', $saved->getDescription());
	}

	public function testMediaFromFileNeedsAPath(): void {
		$this->loggedInAs();
		$this->cacheDocumentService->expects($this->never())->method('saveFromTempToCache');

		$this->assertSame(['error' => 'no file named'], $this->controller()->mediaFromFile()->getData());
	}

	/**
	 * The route names a file and returns its contents, so the boundary is the
	 * whole of it: everything is resolved inside the viewer's own user folder.
	 */
	public function testMediaFromFileRefusesAPathOutsideTheViewersFiles(): void {
		$this->loggedInAs();
		$this->pathParam = '../../../../etc/passwd';
		$folder = $this->userFolderMock();
		$folder->method('get')->willThrowException(new NotFoundException());
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($folder);
		$this->cacheDocumentService->expects($this->never())->method('saveFromTempToCache');

		$this->assertSame(['error' => 'no such file'], $this->controller()->mediaFromFile()->getData());
	}

	/**
	 * Belt and braces: even were a node to resolve outside the folder, it is
	 * checked against it again before a byte is read.
	 */
	public function testMediaFromFileRefusesANodeThatResolvedOutsideTheFolder(): void {
		$this->loggedInAs();
		$this->pathParam = '/Photos/beach.jpg';
		$this->userFolderHolding('/Photos/beach.jpg', 'PNGDATA', null);
		$this->cacheDocumentService->expects($this->never())->method('saveFromTempToCache');

		$this->assertSame(['error' => 'no such file'], $this->controller()->mediaFromFile()->getData());
	}

	public function testMediaFromFileRefusesAFolder(): void {
		$this->loggedInAs();
		$this->pathParam = '/Photos';
		$folder = $this->userFolderMock();
		$folder->method('get')->with('/Photos')->willReturn($this->createStub(Folder::class));
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($folder);
		$this->cacheDocumentService->expects($this->never())->method('saveFromTempToCache');

		$this->assertSame(['error' => 'that is not a file'], $this->controller()->mediaFromFile()->getData());
	}

	/**
	 * The ceiling an upload is held to, applied to the same bytes — and checked
	 * before anything is read, because reading is what costs.
	 */
	public function testMediaFromFileRefusesAFileOverTheLimit(): void {
		$this->loggedInAs();
		$this->pathParam = '/Photos/huge.png';
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(11 * 1048576);
		$file->method('getPath')->willReturn('/alice/files/Photos/huge.png');
		$file->expects($this->never())->method('fopen');
		$folder = $this->userFolderMock();
		$folder->method('get')->with('/Photos/huge.png')->willReturn($file);
		$folder->method('getRelativePath')->willReturn('Photos/huge.png');
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($folder);
		$this->cacheDocumentService->expects($this->never())->method('saveFromTempToCache');

		$this->assertSame(
			['error' => 'file is larger than the 10MB limit'],
			$this->controller()->mediaFromFile()->getData()
		);
	}

	/**
	 * The two ways a picture gets in share their storing half, and the things
	 * that must not differ between them are the ones a mistake would be
	 * invisible in: an attachment that arrived public would be readable over
	 * the unauthenticated /media/{uuid} route before any post had said so.
	 */
	#[DataProvider('waysToAttachAPicture')]
	public function testEveryWayInStoresTheSameKindOfDocument(string $how): void {
		$this->loggedInAs();
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');

		if ($how === 'upload') {
			$tmp = tempnam(sys_get_temp_dir(), 'social-itest');
			$this->tempFiles[] = $tmp;
			$_FILES['file'] = ['tmp_name' => $tmp, 'size' => 10, 'type' => 'image/png', 'error' => UPLOAD_ERR_OK];
		} else {
			$this->pathParam = '/Photos/beach.jpg';
			$this->userFolderHolding('/Photos/beach.jpg');
			$this->temporaryFile();
		}

		$this->expectDocumentSaved($saved, $tmpSeen);

		$response = ($how === 'upload')
			? $this->controller()->mediaNew()
			: $this->controller()->mediaFromFile();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertInstanceOf(MediaAttachment::class, $response->getData());
		$this->assertTrue($saved->isLocal());
		$this->assertFalse($saved->isPublic(), 'an attachment was public before a post said so');
		$this->assertSame('alice', $saved->getAccount());
		$this->assertNotSame('', $saved->getId(), 'the document was stored without an id');
	}

	/** @return iterable<string, array{string}> */
	public static function waysToAttachAPicture(): iterable {
		yield 'uploaded from the device' => ['upload'];
		yield 'picked out of Nextcloud Files' => ['from-file'];
	}

	public function testMediaNewStoresTheUploadAsANonPublicLocalDocument(): void {
		$this->loggedInAs();
		$_FILES['file'] = ['tmp_name' => '/tmp/php-upload', 'size' => 10, 'type' => 'image/png', 'error' => UPLOAD_ERR_OK];
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');

		$saved = null;
		$this->cacheDocumentService->expects($this->once())->method('saveFromTempToCache')
			->willReturnCallback(function (Document $document, string $tmpPath) use (&$saved): void {
				$this->assertSame('/tmp/php-upload', $tmpPath);
				$saved = $document;
			});

		$interface = $this->createMock(IActivityPubInterface::class);
		$interface->expects($this->once())->method('save')->with($this->isInstanceOf(Document::class));
		AP::set($this->createStub(AP::class));
		AP::instance()->method('getInterfaceForItem')->willReturn($interface);

		$response = $this->controller()->mediaNew();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertInstanceOf(MediaAttachment::class, $response->getData());
		$this->assertTrue($saved->isLocal());
		// not world-readable until a post says so: /media/{uuid} is
		// unauthenticated and only serves what this flag allows
		$this->assertFalse($saved->isPublic());
		$this->assertSame('alice', $saved->getAccount());
		$this->assertStringStartsWith('https://cloud.example/documents/local/', $saved->getId());
	}

	public function testBookmarksIsTheBookmarksTimeline(): void {
		$this->loggedInAs();
		$posts = [$this->createMock(Stream::class)];
		$grab = $this->captureTimelineOptions($posts);

		$response = $this->controller()->bookmarks(30, 9, 4, 2);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($posts, $response->getData());
		$options = $grab();
		$this->assertSame('bookmarks', $options->getProbe());
		$this->assertSame(30, $options->getLimit());
		$this->assertSame(9, $options->getMaxId());
		$this->assertSame(4, $options->getMinId());
	}

	public function testMediaNewStoresTheAltText(): void {
		$this->loggedInAs();
		$_FILES['file'] = ['tmp_name' => '/tmp/php-upload', 'size' => 10, 'type' => 'image/png', 'error' => UPLOAD_ERR_OK];
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');

		$saved = null;
		$this->cacheDocumentService->method('saveFromTempToCache')
			->willReturnCallback(function (Document $document) use (&$saved): void {
				$saved = $document;
			});
		$interface = $this->createStub(IActivityPubInterface::class);
		AP::set($this->createStub(AP::class));
		AP::instance()->method('getInterfaceForItem')->willReturn($interface);

		$this->description = 'a cat sleeping on a laptop';
		$this->controller()->mediaNew();

		$this->assertSame('a cat sleeping on a laptop', $saved->getDescription());
	}

	public function testMediaNewV2IsTheSameUpload(): void {
		// modern clients POST /api/v2/media and only fall back to v1 on a 404
		$this->loggedInAs();

		$this->assertUnprocessable($this->controller()->mediaNewV2(), 'no media found');
	}

	private function ownDocumentInService(string $nid, string $description = ''): Document {
		$document = new Document();
		$document->setNid((int)$nid);
		$document->setId('https://cloud.example/documents/local/doc-' . $nid);
		$document->setDescription($description);
		$this->documentService->method('getMediaFromArray')
			->with([$nid], 'alice')
			->willReturn([$document]);

		return $document;
	}

	public function testMediaGetReturnsTheViewersOwnAttachment(): void {
		$this->loggedInAs();
		$this->ownDocumentInService('7', 'alt text');

		$response = $this->controller()->mediaGet('7');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$attachment = $response->getData();
		$this->assertInstanceOf(MediaAttachment::class, $attachment);
		$this->assertSame('7', $attachment->getId());
		$this->assertSame('alt text', $attachment->getDescription());
	}

	public function testMediaGetOfSomeoneElsesAttachmentIsA404(): void {
		$this->loggedInAs();
		$this->documentService->method('getMediaFromArray')->willReturn([]);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->mediaGet('7')->getStatus());
	}

	public function testMediaUpdateChangesTheAltText(): void {
		$this->loggedInAs();
		$document = $this->ownDocumentInService('7', 'old');
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => $name === 'Content-Type' ? 'application/x-www-form-urlencoded' : ''
		);
		$this->request->method('getParams')->willReturn(['description' => 'new alt text']);
		$this->documentService->expects($this->once())
			->method('updateDescription')
			->with($this->identicalTo($document));

		$response = $this->controller()->mediaUpdate('7');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('new alt text', $document->getDescription());
		$this->assertSame('new alt text', $response->getData()->getDescription());
	}

	/**
	 * Where the subject of a picture is, so a square crop keeps it in frame.
	 * The composer's crosshair saves through this, the same request the
	 * description travels by.
	 */
	public function testMediaUpdateSetsTheFocalPoint(): void {
		$this->loggedInAs();
		$document = $this->ownDocumentInService('7', 'alt');
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => $name === 'Content-Type' ? 'application/x-www-form-urlencoded' : ''
		);
		$this->request->method('getParams')->willReturn(['focus' => '0.5,-0.25']);
		$this->documentService->expects($this->once())
			->method('updateFocus')
			->with($this->identicalTo($document), 0.5, -0.25);

		$this->assertSame(Http::STATUS_OK, $this->controller()->mediaUpdate('7')->getStatus());
	}

	/** Anything that is not a pair of numbers leaves the picture as it was. */
	public function testMediaUpdateIgnoresAFocalPointItCannotRead(): void {
		$this->loggedInAs();
		$this->ownDocumentInService('7', 'alt');
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => $name === 'Content-Type' ? 'application/x-www-form-urlencoded' : ''
		);
		$this->request->method('getParams')->willReturn(['focus' => 'the middle']);
		$this->documentService->expects($this->never())->method('updateFocus');

		$this->assertSame(Http::STATUS_OK, $this->controller()->mediaUpdate('7')->getStatus());
	}

	/**
	 * A post keeps its own copy of what it carries, and only an edit tells the
	 * servers that hold the post. Changing the upload alone answered 200 while
	 * the post kept the old words; it is refused now, as Mastodon refuses it.
	 */
	public function testMediaUpdateOfAnAttachedUploadIsRefusedAndPointsAtTheEdit(): void {
		$this->loggedInAs();
		$document = $this->ownDocumentInService('7', 'old');
		$this->request->method('getParams')->willReturn(['description' => 'new alt text']);
		$this->documentService->method('isAttachedToAPostBy')->with($document)->willReturn(true);
		$this->documentService->expects($this->never())->method('updateDescription');

		$response = $this->controller()->mediaUpdate('7');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('media_attributes', $response->getData()['error']);
		$this->assertSame('old', $document->getDescription());
	}

	public function testMediaUpdateOfSomeoneElsesAttachmentIsA404(): void {
		$this->loggedInAs();
		$this->documentService->method('getMediaFromArray')->willReturn([]);
		$this->documentService->expects($this->never())->method('updateDescription');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->mediaUpdate('7')->getStatus());
	}

	public function testMediaOpenServesTheStoredMediaType(): void {
		$file = $this->createStub(ISimpleFile::class);
		$file->method('getName')->willReturn('abc');
		$file->method('getETag')->willReturn('etag');
		$file->method('getMTime')->willReturn(1700000000);
		$document = $this->createStub(\OCA\Social\Model\ActivityPub\Object\Document::class);
		$document->method('getMediaType')->willReturn('image/png');
		$this->documentService->expects($this->once())->method('getFromUuid')->with('abc')->willReturn([$file, $document]);

		$response = $this->controller()->mediaOpen('abc.png');

		$this->assertInstanceOf(RangedFileResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('image/png', $response->getHeaders()['Content-Type']);
		// the offer has to be made before a browser will ask for a range
		$this->assertSame('bytes', $response->getHeaders()['Accept-Ranges']);
	}

	/**
	 * A video's resized copy is its poster frame, which is a JPEG. Served as
	 * `video/mp4` -- the document's own type -- a browser with `nosniff` on,
	 * which is every Nextcloud, refuses to draw it.
	 */
	public function testMediaOpenServesAVideoPosterAsAnImage(): void {
		$file = $this->createStub(ISimpleFile::class);
		$file->method('getName')->willReturn('poster');
		$document = $this->createStub(\OCA\Social\Model\ActivityPub\Object\Document::class);
		$document->method('getMediaType')->willReturn('video/mp4');
		$document->method('getResizedCopy')->willReturn('poster');
		$this->documentService->method('getFromUuid')->with('poster')->willReturn([$file, $document]);

		$this->assertSame(
			'image/jpeg',
			$this->controller()->mediaOpen('poster.jpeg')->getHeaders()['Content-Type']
		);
	}

	/** The video itself keeps its own type, resized copy or not. */
	public function testMediaOpenServesTheVideoItselfAsAVideo(): void {
		$file = $this->createStub(ISimpleFile::class);
		$file->method('getName')->willReturn('movie');
		$document = $this->createStub(\OCA\Social\Model\ActivityPub\Object\Document::class);
		$document->method('getMediaType')->willReturn('video/mp4');
		$document->method('getResizedCopy')->willReturn('poster');
		$this->documentService->method('getFromUuid')->with('movie')->willReturn([$file, $document]);

		$this->assertSame(
			'video/mp4',
			$this->controller()->mediaOpen('movie.mp4')->getHeaders()['Content-Type']
		);
	}

	public function testMediaOpenIgnoresTheRequestersExtension(): void {
		// the media type was sniffed at ingest; the URL suffix is attacker-chosen
		$file = $this->createStub(ISimpleFile::class);
		$file->method('getName')->willReturn('abc');
		$document = $this->createStub(\OCA\Social\Model\ActivityPub\Object\Document::class);
		$document->method('getMediaType')->willReturn('image/png');
		$this->documentService->method('getFromUuid')->with('abc')->willReturn([$file, $document]);

		$this->assertSame('image/png', $this->controller()->mediaOpen('abc.svg')->getHeaders()['Content-Type']);
	}

	public function testMediaOpenServesAFollowersOnlyAttachmentByItsUuidWithoutSharedCaching(): void {
		// Mastodon's model: media is a capability URL, reachable by whoever holds
		// the unguessable uuid, whatever the post's visibility — Mastodon fetches
		// it unsigned. The `public` flag only decides whether a shared proxy may
		// keep a copy.
		$file = $this->createStub(ISimpleFile::class);
		$file->method('getName')->willReturn('abc');
		$document = $this->createStub(\OCA\Social\Model\ActivityPub\Object\Document::class);
		$document->method('getMediaType')->willReturn('image/jpeg');
		$document->method('isPublic')->willReturn(false);
		$this->documentService->expects($this->once())->method('getFromUuid')->with('abc')->willReturn([$file, $document]);

		$response = $this->controller()->mediaOpen('abc.jpg');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('image/jpeg', $response->getHeaders()['Content-Type']);
		$this->assertSame('private, max-age=31536000, immutable', $response->getHeaders()['Cache-Control']);
	}

	public function testMediaOpenOfAPublicAttachmentMayBeKeptByASharedCache(): void {
		$file = $this->createStub(ISimpleFile::class);
		$file->method('getName')->willReturn('abc');
		$document = $this->createStub(\OCA\Social\Model\ActivityPub\Object\Document::class);
		$document->method('getMediaType')->willReturn('image/png');
		$document->method('isPublic')->willReturn(true);
		$this->documentService->method('getFromUuid')->willReturn([$file, $document]);

		$response = $this->controller()->mediaOpen('abc.png');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('public, max-age=31536000, immutable', $response->getHeaders()['Cache-Control']);
	}

	public function testMediaOpenOfUnknownFileIs404(): void {
		$this->documentService->method('getFromUuid')->willThrowException(new NotFoundException('no file'));

		$response = $this->controller()->mediaOpen('abc.png');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'no file'], $response->getData());
	}

	public function testMediaOpenOtherFailuresAreBadRequests(): void {
		$this->documentService->method('getFromUuid')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller()->mediaOpen('abc.png');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'boom'], $response->getData());
	}

	// custom emoji

	/**
	 * The route answered `[]` unconditionally: emoji from every other instance
	 * rendered here and this one could publish none.
	 */
	public function testTheCustomEmojiRouteListsWhatTheInstancePublishes(): void {
		$blobcat = (new CustomEmoji('blobcat', 'blobcat.png', 'image/png', 'blobs'))
			->setUrl('https://cloud.example/apps/social/emoji/blobcat');
		$this->emojiService->method('visible')->willReturn([$blobcat]);

		$data = $this->controller()->customEmojis()->getData();

		$this->assertSame([[
			'shortcode' => 'blobcat',
			'url' => 'https://cloud.example/apps/social/emoji/blobcat',
			'static_url' => 'https://cloud.example/apps/social/emoji/blobcat',
			'visible_in_picker' => true,
			'category' => 'blobs',
		]], array_map(
			static fn (CustomEmoji $emoji): array => $emoji->jsonSerialize(), $data
		));
	}

	public function testAnInstanceWithNoEmojiPublishesNone(): void {
		$this->emojiService->method('visible')->willReturn([]);

		$this->assertSame([], $this->controller()->customEmojis()->getData());
	}

	/** A shortcode nobody published is a 404, not a broken image forever. */
	public function testAskingForAPictureNobodyPublishedIsNotFound(): void {
		$this->emojiService->method('byShortcode')->willReturn(null);

		$response = $this->controller()->emojiOpen('blobcat');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// registration, which is the server's and not this app's

	/**
	 * A 404 reads as "this server is broken" and shows a person nothing they
	 * can act on. A 403 in Mastodon's error shape is read, shown, and says
	 * where to go instead.
	 */
	public function testSigningUpSaysWhereToSignUpInstead(): void {
		$response = $this->controller()->accountNew();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString(
			'not handled by this application', $response->getData()['error']
		);
		$this->assertStringContainsString(
			'administrator',
			$response->getData()['details']->base[0]->description
		);
	}

	/** With a sign-up app on the server, there is somewhere to point at. */
	public function testWithARegistrationAppTheAdviceIsItsAddress(): void {
		$this->registrationApp = true;
		$this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://cloud.example' . $path
		);

		$description = $this->controller()->accountNew()
			->getData()['details']->base[0]->description;

		$this->assertStringContainsString('/apps/registration/', $description);
	}

	/** Mastodon's shape, so a client can decode and show it. */
	public function testTheRefusalIsShapedAsMastodonsRegistrationError(): void {
		$data = $this->controller()->accountNew()->getData();

		$this->assertArrayHasKey('error', $data);
		$this->assertSame('ERR_BLOCKED', $data['details']->base[0]->error);
	}

	// the three instance sub-routes

	/**
	 * The rules were already served *inside* the instance entity, so the data
	 * was here and the route a client reads it from was a 404.
	 */
	public function testTheRulesHaveARouteOfTheirOwn(): void {
		$instance = new Instance();
		$instance->setRules([['id' => '1', 'text' => 'be kind']]);
		$this->instanceService->method('getLocal')->willReturn($instance);

		$this->assertSame(
			[['id' => '1', 'text' => 'be kind']],
			$this->controller()->instanceRules()->getData()
		);
	}

	/**
	 * Whether this server wants its deny list read by anybody is a disclosure
	 * decision its admin makes, not a default.
	 */
	public function testTheBlockListIsNotPublishedUnlessAnAdminSaysSo(): void {
		$this->blockedInstances = ['evil.example'];

		$this->assertSame([], $this->controller()->instanceDomainBlocks()->getData());
	}

	public function testWithTheOptInTheBlockListIsPublished(): void {
		$this->appValues[ConfigService::SOCIAL_PUBLISH_BLOCKS] = '1';
		$this->blockedInstances = ['evil.example'];

		$this->assertSame([[
			'domain' => 'evil.example',
			'digest' => hash('sha256', 'evil.example'),
			'severity' => 'suspend',
			'comment' => '',
		]], $this->controller()->instanceDomainBlocks()->getData());
	}

	/**
	 * In allow-list mode the same column holds the instances this server
	 * *does* talk to, and publishing that as a block list would be exactly
	 * backwards.
	 */
	public function testAnAllowListIsNeverPublishedAsABlockList(): void {
		$this->appValues[ConfigService::SOCIAL_PUBLISH_BLOCKS] = '1';
		$this->accessType = 'none_but';
		$this->blockedInstances = ['friend.example'];

		$this->assertSame([], $this->controller()->instanceDomainBlocks()->getData());
	}

	/**
	 * An empty page where a server has written a description elsewhere is
	 * worse than repeating it.
	 */
	public function testTheExtendedDescriptionFallsBackToTheShortOne(): void {
		$instance = new Instance();
		$instance->setDescription('a safe home for your data');
		$this->instanceService->method('getLocal')->willReturn($instance);

		$this->assertSame(
			'a safe home for your data',
			$this->controller()->instanceExtendedDescription()->getData()['content']
		);
	}

	public function testTheExtendedDescriptionIsWhatWasWritten(): void {
		$this->appValues[ConfigService::SOCIAL_EXTENDED_DESCRIPTION] = 'the long version';
		$this->instanceService->method('getLocal')->willReturn(new Instance());

		$this->assertSame(
			'the long version',
			$this->controller()->instanceExtendedDescription()->getData()['content']
		);
	}

	// removing the pictures: the half update_credentials cannot express

	public function testTheAvatarCanBeRemoved(): void {
		$this->loggedInAs();
		$this->avatarService->expects($this->once())->method('remove')->with('alice');

		$this->assertSame(
			Http::STATUS_OK, $this->controller()->profileAvatarDelete()->getStatus()
		);
	}

	/** A backend that owns the picture refuses, rather than answering 200 over an unchanged one. */
	public function testRemovingAManagedAvatarIsRefused(): void {
		$this->loggedInAs();
		$this->avatarService->method('remove')
			->willThrowException(new InvalidActionException('the avatar of this account is managed outside Nextcloud'));

		$this->assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$this->controller()->profileAvatarDelete()->getStatus()
		);
	}

	public function testTheBannerCanBeRemoved(): void {
		$this->loggedInAs();
		$this->bannerService->expects($this->once())->method('remove')->with('alice');

		$this->assertSame(
			Http::STATUS_OK, $this->controller()->profileHeaderDelete()->getStatus()
		);
	}

	public function testRemovingAPictureNeedsAViewer(): void {
		$this->assertUnauthorized($this->controller()->profileAvatarDelete());
		$this->assertUnauthorized($this->controller()->profileHeaderDelete());
	}

	// oEmbed

	private function publicPost(string $id = 'https://cloud.example/apps/social/@alice/1'): Note {
		$post = new Note();
		$post->setId($id);
		$post->setLocal(true);
		$post->setVisibility(Stream::TYPE_PUBLIC);
		$post->setAttributedTo('https://cloud.example/apps/social/@alice');

		return $post;
	}

	public function testOembedDescribesAPublicPostOfThisServer(): void {
		$post = $this->publicPost();
		$this->streamService->method('getStreamById')->willReturn($post);
		$author = new Person();
		$author->setId('https://cloud.example/apps/social/@alice');
		$author->setPreferredUsername('alice');
		$this->cacheActorService->method('getFromId')->willReturn($author);
		$this->instanceService->method('getLocal')->willReturn((new Instance())->setTitle('Example'));

		$data = $this->controller()->oembed($post->getId())->getData();

		$this->assertSame('link', $data['type']);
		$this->assertSame('alice', $data['author_name']);
		$this->assertSame($post->getId(), $data['url']);
	}

	/** Not `rich`: there is no embed page here, and framing the whole app is not one. */
	public function testOembedIsNeverRich(): void {
		$this->streamService->method('getStreamById')->willReturn($this->publicPost());
		$this->cacheActorService->method('getFromId')->willReturn(new Person());
		$this->instanceService->method('getLocal')->willReturn(new Instance());

		$this->assertArrayNotHasKey('html', $this->controller()->oembed('https://cloud.example/x')->getData());
	}

	public function testOembedRefusesAPostThatIsNotPublic(): void {
		$post = $this->publicPost();
		$post->setVisibility(Stream::TYPE_FOLLOWERS);
		$this->streamService->method('getStreamById')->willReturn($post);

		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller()->oembed('https://cloud.example/x')->getStatus()
		);
	}

	public function testOembedRefusesSomebodyElsesPost(): void {
		$post = $this->publicPost('https://elsewhere.example/@bob/1');
		$post->setLocal(false);
		$this->streamService->method('getStreamById')->willReturn($post);

		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller()->oembed('https://elsewhere.example/@bob/1')->getStatus()
		);
	}

	public function testOembedServesOnlyJson(): void {
		$this->assertSame(
			Http::STATUS_NOT_IMPLEMENTED,
			$this->controller()->oembed('https://cloud.example/x', 'xml')->getStatus()
		);
	}

	// the legal documents, and the languages

	public function testThePrivacyPolicyIsNextcloudsOwn(): void {
		$this->instanceService->method('privacyPolicy')
			->willReturn(['updated_at' => '2026-09-15T00:00:00.000Z', 'content' => '<p>here</p>']);

		$response = $this->controller()->instancePrivacyPolicy();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('<p>here</p>', $response->getData()['content']);
	}

	public function testAServerWithNoPrivacyPolicySaysSo(): void {
		$this->instanceService->method('privacyPolicy')->willReturn(null);

		$this->assertSame(
			Http::STATUS_NOT_FOUND, $this->controller()->instancePrivacyPolicy()->getStatus()
		);
	}

	public function testAServerWithNoTermsSaysSo(): void {
		$this->instanceService->method('termsOfService')->willReturn(null);

		$this->assertSame(
			Http::STATUS_NOT_FOUND, $this->controller()->instanceTermsOfService()->getStatus()
		);
	}

	public function testTheTranslationLanguagesAreTheProvidersOwn(): void {
		$this->translationService->method('languages')->willReturn(['de' => ['en']]);

		$this->assertSame(
			['de' => ['en']], (array)$this->controller()->instanceTranslationLanguages()->getData()
		);
	}

	/** An empty map, not `[]`: a client reads an object there. */
	public function testNoProviderIsAnEmptyObject(): void {
		$this->translationService->method('languages')->willReturn([]);

		$this->assertEquals(
			new stdClass(), $this->controller()->instanceTranslationLanguages()->getData()
		);
	}

	// translating a status

	public function testTranslateAnswersATranslationEntity(): void {
		$this->loggedInAs();
		$this->streamService->method('getStreamByNid')->willReturn($this->publicPost());
		$this->l10nFactory->method('getUserLanguage')->willReturn('en');
		$this->translationService->method('translateStatus')
			->willReturn((new Translation())->setContent('<p>good morning</p>'));

		$data = $this->controller()->statusTranslate(42)->getData()->jsonSerialize();

		$this->assertSame('<p>good morning</p>', $data['content']);
		// a Translation, not a Status: no id, no author, no counters
		$this->assertArrayNotHasKey('id', $data);
		$this->assertArrayNotHasKey('account', $data);
	}

	/** The language asked for beats the reader's own. */
	public function testTranslateHonoursTheLanguageAsked(): void {
		$this->loggedInAs();
		$this->streamService->method('getStreamByNid')->willReturn($this->publicPost());
		$this->translationService->expects($this->once())
			->method('translateStatus')
			->with($this->anything(), 'fr', 'alice')
			->willReturn(new Translation());

		$this->controller()->statusTranslate(42, 'fr');
	}

	/**
	 * A server with no provider says so, rather than answering with the
	 * original text — which is what this route used to do.
	 */
	public function testTranslateWithoutAProviderIsUnavailable(): void {
		$this->loggedInAs();
		$this->streamService->method('getStreamByNid')->willReturn($this->publicPost());
		$this->l10nFactory->method('getUserLanguage')->willReturn('en');
		$this->translationService->method('translateStatus')
			->willThrowException(new TranslationUnavailableException('no translation provider is configured'));

		$this->assertSame(
			Http::STATUS_SERVICE_UNAVAILABLE, $this->controller()->statusTranslate(42)->getStatus()
		);
	}

	/**
	 * The picker's library is 881 pictures now that the animated emoji are in
	 * it, and every one drawn is one this instance may have to go and fetch.
	 */
	public function testTheGifLibraryIsPaged(): void {
		$this->loggedInAs();
		$this->gifService->method('search')->willReturn(array_map(
			static fn (int $i): Gif => new Gif('g' . $i, $i . '.gif', 'image/gif', 'one'),
			range(1, 150)
		));
		$this->gifService->method('attribution')->willReturn('by somebody, CC BY 4.0');

		$answer = $this->controller()->gifs('', 20, 40)->getData();

		$this->assertCount(20, $answer['gifs']);
		$this->assertSame(150, $answer['total']);
		$this->assertSame('g41', $answer['gifs'][0]->getSlug());
		// CC BY asks for it and the picker shows what this says
		$this->assertSame('by somebody, CC BY 4.0', $answer['attribution']);
	}

	public function testTheGifLibraryWillNotBeAskedForEverythingAtOnce(): void {
		$this->loggedInAs();
		$this->gifService->method('search')->willReturn([]);
		$this->gifService->method('attribution')->willReturn('');

		// a client asking for a thousand gets two hundred
		$this->assertSame(0, count($this->controller()->gifs('', 1000)->getData()['gifs']));
	}

	/**
	 * A picture is the same bytes for everybody, and holding the session while
	 * it is fetched is what made a picker of sixty of them serialise: PHP runs
	 * requests holding one session one at a time, so the sixtieth thumbnail —
	 * and anything the reader did meanwhile — waited for the other fifty-nine.
	 */
	public function testServingAPictureLetsTheSessionGo(): void {
		$this->session->expects($this->once())->method('close');
		$this->gifService->method('bySlug')
			->willReturn(new Gif('noto-1f994', '1f994.webp', 'image/webp', 'hedgehog'));
		$this->gifService->method('file')->willThrowException(new NotFoundException('not here'));

		// what it answers is beside the point; that it let the session go is not
		$this->controller()->gifOpen('noto-1f994');
	}

	/** Every report notifies the moderators, so filing them is limited for every caller. */
	public function testFilingAReportIsRateLimited(): void {
		$method = new ReflectionMethod(ApiController::class, 'reportNew');

		$this->assertNotSame([], $method->getAttributes(AnonRateLimit::class));
		$this->assertNotSame([], $method->getAttributes(UserRateLimit::class));
	}

	/**
	 * The durable cache on its table backend — as on an instance with no
	 * memcache — so the Idempotency-Key round trip is exercised there.
	 */
	private function durableCache(): \OCA\Social\Service\DurableCache {
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(\OCP\AppFramework\Utility\ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$this->durableCacheRequest ??= new \OCA\Social\Tests\Helper\InMemoryDurableCacheRequest();

		return new \OCA\Social\Service\DurableCache($factory, $this->durableCacheRequest, $time);
	}
}
