<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\DiscoveryController;
use OCA\Social\Db\DiscoveryRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\FeaturedTag;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Client\Suggestion;
use OCA\Social\Model\Client\TrendingLink;
use OCA\Social\Model\StreamCard;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\DirectoryService;
use OCA\Social\Service\FeaturedTagService;
use OCA\Social\Service\FediverseDirectoryService;
use OCA\Social\Service\FollowGraphService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PeerTrendService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\ProfileHighlightsService;
use OCA\Social\Service\StarterPackService;
use OCA\Social\Service\SuggestionService;
use OCA\Social\Service\TrendService;
use OCA\Social\Tests\Mock\TCacheActorServiceMock;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;

/**
 * The discovery routes.
 *
 * What is checked here is the part a client can tell apart from the outside:
 * which routes answer an anonymous caller and which refuse one, what each
 * bearer scope buys, and that the parameters a client sends reach the query
 * unchanged. The rankings themselves belong to the services.
 *
 * The split between public and viewer-required is the one thing here that is
 * not cosmetic. `/directory`, `/trends/*` and another account's featured tags
 * are things this instance publishes about itself; `/suggestions` and an
 * account's own featured tags are about the asking account, and there is no
 * anonymous answer to "who should I follow".
 */
class DiscoveryControllerTest extends TestCase {
	use TCacheActorServiceMock;

	private const VIEWER = 'https://cloud.example/users/alice';
	private const OTHER = 'https://cloud.example/users/bob';

	/** @var IRequest&MockObject */
	private $request;
	private AccountService|MockObject $accountService;
	private CacheActorService|MockObject $cacheActorService;
	private ClientService|MockObject $clientService;
	private DirectoryService|MockObject $directoryService;
	private SuggestionService|MockObject $suggestionService;
	private FollowGraphService|MockObject $followGraphService;
	private TrendService|MockObject $trendService;
	private FeaturedTagService|MockObject $featuredTagService;
	private LinkPreviewService|MockObject $linkPreviewService;
	private StarterPackService|MockObject $starterPackService;
	private ProfileHighlightsService|MockObject $profileHighlightsService;
	private AccountRelationService|MockObject $accountRelationService;
	private FediverseDirectoryService|MockObject $fediverseDirectoryService;
	private PeerTrendService|MockObject $peerTrendService;
	private IUserSession|MockObject $userSession;

	/** @var array<string, string> the request headers the controller will see */
	private array $headers = [];
	private bool $csrf = true;
	private bool $hasSession = true;

	/** @var array{order: string, limit: int, offset: int}|null what the directory was asked */
	private ?array $directoryAsked = null;
	/** @var array{period: string, limit: int, offset: int}|null what the trends were asked */
	private ?array $trendAsked = null;
	/** @var array<string, mixed> what the link timeline was asked for */
	private array $linkAsked = [];
	/** @var Note[] what the link timeline answers */
	private array $linkTimeline = [];
	/** @var Stream[] the statuses the trends answer with */
	private array $trendingStatuses = [];
	/** @var bool whether the page was handed to the link-preview loader */
	private bool $cardsAttached = false;
	/** @var array<int, FeaturedTag> the viewer's featured tags, by id */
	private array $featured = [];
	/** @var string|null the account whose featured tags were asked for */
	private ?string $featuredOf = null;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getRequestUri')->willReturn('/api/v1/directory');
		$this->request->method('getParam')->willReturn('');
		$this->request->method('getParams')->willReturn([]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')
			->willReturnCallback(fn (): ?IUser => $this->hasSession ? $user : null);

		$this->accountService = $this->createMock(AccountService::class);
		$this->accountService->method('getActorFromUserId')
			->willReturnCallback(fn (): Person => $this->person(self::VIEWER, 1));

		$this->cacheActorService = $this->cacheActorServiceMock();
		$this->cacheActorService->method('getFromNids')
			->willReturnCallback(fn (array $nids): array => ($nids === [2])
				? [$this->person(self::OTHER, 2)]
				: []);
		$this->cacheActorService->method('getFromId')
			->willReturnCallback(fn (string $id): Person => $this->person($id, 0));
		$this->cacheActorService->method('getFromAccount')
			->willReturnCallback(fn (): Person => $this->person(self::OTHER, 2));

		$this->clientService = $this->createMock(ClientService::class);
		$this->starterPackService = $this->createMock(StarterPackService::class);

		$this->directoryService = $this->createMock(DirectoryService::class);
		$this->directoryService->method('page')
			->willReturnCallback(function (string $order, int $limit, int $offset): array {
				$this->directoryAsked = ['order' => $order, 'limit' => $limit, 'offset' => $offset];

				return [$this->person(self::OTHER, 2)];
			});

		$this->suggestionService = $this->createMock(SuggestionService::class);
		$this->followGraphService = $this->createMock(FollowGraphService::class);
		$this->suggestionService->method('suggestions')
			->willReturnCallback(fn (): array => [
				new Suggestion($this->person(self::OTHER, 2), Suggestion::SOURCE_FRIENDS),
			]);

		$this->trendService = $this->createMock(TrendService::class);
		$this->trendService->method('trendingStatuses')
			->willReturnCallback(function (
				string $period,
				int $limit,
				int $offset,
				bool $onlyMedia = false,
				string $mediaType = '',
			): array {
				$this->trendAsked = [
					'period' => $period,
					'limit' => $limit,
					'offset' => $offset,
					'onlyMedia' => $onlyMedia,
					'mediaType' => $mediaType,
				];

				return $this->trendingStatuses;
			});
		$this->trendService->method('trendingLinks')
			->willReturnCallback(function (string $period, int $limit, int $offset): array {
				$this->trendAsked = ['period' => $period, 'limit' => $limit, 'offset' => $offset];

				return [new TrendingLink((new StreamCard())->setUrl('https://example.org/a'), 3)];
			});

		$this->trendService->method('linkTimeline')
			->willReturnCallback(function (string $url, int $limit, int $maxId, int $minId): array {
				$this->linkAsked = compact('url', 'limit', 'maxId', 'minId');

				return $this->linkTimeline;
			});

		$this->featuredTagService = $this->createMock(FeaturedTagService::class);
		$this->featuredTagService->method('featured')
			->willReturnCallback(function (string $actorId): array {
				$this->featuredOf = $actorId;

				return array_values($this->featured);
			});
		$this->featuredTagService->method('feature')
			->willReturnCallback(function (string $actorId, string $name): FeaturedTag {
				if ($name === '') {
					throw new InvalidResourceException("Name can't be blank");
				}

				$tag = (new FeaturedTag())->setId(1)->setOwnerId($actorId)->setHashtag($name);
				$this->featured[1] = $tag;

				return $tag;
			});
		$this->featuredTagService->method('unfeature')
			->willReturnCallback(function (string $actorId, int $id): void {
				if (!isset($this->featured[$id])) {
					throw new ItemNotFoundException('Record not found');
				}

				unset($this->featured[$id]);
			});
		$this->featuredTagService->method('suggestions')
			->willReturn([['name' => 'cycling']]);

		$this->profileHighlightsService = $this->createMock(ProfileHighlightsService::class);

		$this->accountRelationService = $this->createMock(AccountRelationService::class);

		$this->fediverseDirectoryService = $this->createMock(FediverseDirectoryService::class);
		$this->peerTrendService = $this->createMock(PeerTrendService::class);

		$this->linkPreviewService = $this->createMock(LinkPreviewService::class);
		$this->linkPreviewService->method('attachCards')
			->willReturnCallback(function (): void {
				$this->cardsAttached = true;
			});

		// Response::getHeaders() asks the container for the request
		\OC::$server->register(IRequest::class, $this->request);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function controller(): DiscoveryController {
		return new DiscoveryController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->cacheActorService,
			$this->clientService,
			$this->directoryService,
			$this->suggestionService,
			$this->followGraphService,
			$this->trendService,
			$this->featuredTagService,
			$this->linkPreviewService,
			$this->createMock(PlaceService::class),
			$this->starterPackService,
			$this->profileHighlightsService,
			$this->accountRelationService,
			$this->fediverseDirectoryService,
			$this->peerTrendService
		);
	}

	private function person(string $id, int $nid): Person {
		$person = new Person();
		$person->setId($id);
		$person->setNid($nid);

		return $person;
	}

	/** A token whose grant is exactly these scopes. */
	private function bearer(array $scopes): void {
		$client = new SocialClient();
		$client->setAuthUserId('alice');
		$client->setAuthScopes($scopes);

		$this->headers['Authorization'] = 'Bearer sometoken';
		$this->clientService->method('getFromToken')->willReturn($client);
	}

	private function anonymous(): void {
		$this->hasSession = false;
		$this->csrf = false;
	}

	public function testTheDirectoryAnswersAnAnonymousCaller(): void {
		$this->anonymous();

		$response = $this->controller()->directory();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testTheDirectoryPassesItsParametersThrough(): void {
		$this->controller()->directory(60, 25, 'new');

		$this->assertSame(
			['order' => 'new', 'limit' => 25, 'offset' => 60], $this->directoryAsked
		);
	}

	/** Mastodon's default, and what a client that sends nothing gets. */
	public function testTheDirectoryDefaultsToActiveAndFortyPerPage(): void {
		$this->controller()->directory();

		$this->assertSame(DiscoveryRequest::ORDER_ACTIVE, $this->directoryAsked['order']);
		$this->assertSame(DirectoryService::LIMIT, $this->directoryAsked['limit']);
	}

	/**
	 * This route makes requests to other servers on the caller's say-so, and an
	 * endpoint that does that for anonymous callers is an open proxy with extra
	 * steps.
	 */
	public function testSearchingOtherDirectoriesRefusesACallerWithNoCredentials(): void {
		$this->anonymous();

		$response = $this->controller()->searchDirectories('jens');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	/** And neither is the list of which servers get asked. */
	public function testTheDirectoryListRefusesACallerWithNoCredentials(): void {
		$this->anonymous();

		$response = $this->controller()->directories();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testSearchingOtherDirectoriesPassesItsParametersThrough(): void {
		$asked = [];
		$this->fediverseDirectoryService->method('search')
			->willReturnCallback(function (string $q, string $host, int $limit) use (&$asked): array {
				$asked = ['q' => $q, 'source' => $host, 'limit' => $limit];

				return ['accounts' => [], 'sources' => []];
			});

		$this->controller()->searchDirectories('jens', 'chaos.social', 5);

		$this->assertSame(['q' => 'jens', 'source' => 'chaos.social', 'limit' => 5], $asked);
	}

	/**
	 * The client is expected to show which sources were quiet, so the route has
	 * to hand both halves over rather than only the people.
	 */
	public function testSearchingOtherDirectoriesAnswersWithTheSourcesAsWellAsThePeople(): void {
		$this->fediverseDirectoryService->method('search')->willReturn([
			'accounts' => [],
			'sources' => [['host' => 'misskey.io', 'status' => 'failed', 'count' => 0]],
		]);

		$response = $this->controller()->searchDirectories('jens');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayHasKey('accounts', $response->getData());
		$this->assertSame('failed', $response->getData()['sources'][0]['status']);
	}

	public function testAskingOtherServersAboutHashtagsPassesItsParametersThrough(): void {
		$asked = [];
		$this->peerTrendService->method('tags')
			->willReturnCallback(function (string $q, string $host, int $limit) use (&$asked): array {
				$asked = ['q' => $q, 'source' => $host, 'limit' => $limit];

				return ['tags' => [], 'sources' => []];
			});

		$this->controller()->searchHashtagDirectories('berlin', 'chaos.social', 5);

		$this->assertSame(['q' => 'berlin', 'source' => 'chaos.social', 'limit' => 5], $asked);
	}

	/**
	 * Unlike the people search beside it, this one answers anybody: a tag is a
	 * string, so nothing is fetched on the caller's say-so and there is no
	 * outbound-fetch amplifier to guard against.
	 */
	public function testAskingOtherServersAboutHashtagsAnswersAnAnonymousCaller(): void {
		$this->anonymous();
		$this->peerTrendService->method('tags')->willReturn([
			'tags' => [],
			'sources' => [['host' => 'misskey.io', 'status' => 'failed', 'count' => 0]],
		]);

		$response = $this->controller()->searchHashtagDirectories();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('failed', $response->getData()['sources'][0]['status']);
	}

	/** There is no anonymous answer to "who should I follow". */
	public function testSuggestionsRefuseACallerWithNoCredentials(): void {
		$this->anonymous();

		$response = $this->controller()->suggestions();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(
			'Bearer error="invalid_token"', $response->getHeaders()['WWW-Authenticate']
		);
	}

	public function testSuggestionsAreAnsweredToTheSessionUser(): void {
		$response = $this->controller()->suggestions();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertInstanceOf(Suggestion::class, $response->getData()[0]);
	}

	/** The v1 shape is the same list without the source that explains it. */
	public function testTheV1RouteAnswersBareAccounts(): void {
		$response = $this->controller()->suggestionsV1();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertInstanceOf(Person::class, $response->getData()[0]);
		$this->assertSame(self::OTHER, $response->getData()[0]->getId());
	}

	public function testAWriteOnlyTokenMayNotReadSuggestions(): void {
		$this->bearer(['write']);

		$response = $this->controller()->suggestions();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			'Bearer error="insufficient_scope"', $response->getHeaders()['WWW-Authenticate']
		);
	}

	public function testTheTrendsAnswerAnAnonymousCaller(): void {
		$this->anonymous();

		$this->assertSame(Http::STATUS_OK, $this->controller()->trendStatuses()->getStatus());
		$this->assertSame(Http::STATUS_OK, $this->controller()->trendLinks()->getStatus());
	}

	/** The trends take the window `/api/v1/trends/tags` already takes. */
	public function testTheTrendsTakeTheSameWindowAsTheHashtagTrends(): void {
		$this->controller()->trendStatuses(15, 5, '12h');

		$this->assertSame(
			['period' => '12h', 'limit' => 15, 'offset' => 5, 'onlyMedia' => false, 'mediaType' => ''],
			$this->trendAsked
		);
	}

	/**
	 * `media` is this app's own parameter, so a Pixelfed client sending none
	 * has to keep getting what it always got: every post with an attachment.
	 */
	public function testTheDiscoverGridIsEveryAttachmentUnlessAKindIsNamed(): void {
		$this->controller()->discoverPosts();

		$this->assertTrue($this->trendAsked['onlyMedia']);
		$this->assertSame('', $this->trendAsked['mediaType']);
	}

	#[DataProvider('provideDiscoverMediaKinds')]
	public function testTheDiscoverGridNarrowsToOneKind(string $sent, string $asked): void {
		$this->controller()->discoverPosts(media: $sent);

		$this->assertTrue($this->trendAsked['onlyMedia']);
		$this->assertSame($asked, $this->trendAsked['mediaType']);
	}

	/** @return array<string, array{string, string}> */
	public static function provideDiscoverMediaKinds(): array {
		return [
			'pictures' => ['image', 'image'],
			'videos' => ['video', 'video'],
			'audio' => ['audio', 'audio'],
			// a kind this instance does not sort by is ignored rather than
			// refused: an unknown one is a client asking for something that
			// does not exist, not an error worth a 4xx on a shop window
			'an unknown kind falls back to every attachment' => ['hologram', ''],
			'nothing is every attachment' => ['', ''],
		];
	}

	/** One query for the whole page, as the timelines do it. */
	public function testTrendingStatusesArriveWithTheirLinkPreviews(): void {
		$note = new Note();
		$note->setNid(7);
		$this->trendingStatuses = [$note];

		$this->controller()->trendStatuses();

		$this->assertTrue($this->cardsAttached);
	}

	public function testATrendingLinkIsAPreviewCardWithItsCounts(): void {
		$link = $this->controller()->trendLinks()->getData()[0]->jsonSerialize();

		$this->assertSame('https://example.org/a', $link['url']);
		$this->assertSame('3', $link['history'][0]['uses']);
	}

	public function testTheViewersOwnFeaturedTagsNeedAViewer(): void {
		$this->anonymous();

		$this->assertSame(
			Http::STATUS_UNAUTHORIZED, $this->controller()->featuredTags()->getStatus()
		);
	}

	public function testFeaturingATagNeedsAWriteScope(): void {
		$this->bearer(['read']);

		$this->assertSame(
			Http::STATUS_FORBIDDEN, $this->controller()->featureTag('photography')->getStatus()
		);
	}

	/**
	 * A granular sibling of the same parent is not this scope: a token granted
	 * the reader's statuses has not been granted their profile settings.
	 */
	public function testASiblingGranularScopeMayNotReadFeaturedTags(): void {
		$this->bearer(['read:statuses']);

		$response = $this->controller()->featuredTags();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('read:accounts', $response->getData()['error']);
	}

	public function testFeaturingATagAnswersWithIt(): void {
		$this->bearer(['write:accounts']);

		$response = $this->controller()->featureTag('photography');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('photography', $response->getData()->jsonSerialize()['name']);
	}

	public function testANameThatIsNotAHashtagIsUnprocessable(): void {
		$response = $this->controller()->featureTag('');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(["Name can't be blank"], array_values($response->getData()));
	}

	public function testUnfeaturingAnswersAnEmptyObject(): void {
		$this->controller()->featureTag('photography');

		$response = $this->controller()->unfeatureTag(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	/** Somebody else's row, and a row that is not there, are one answer. */
	public function testUnfeaturingSomebodyElsesTagIsNotFound(): void {
		$response = $this->controller()->unfeatureTag(4242);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Record not found'], $response->getData());
	}

	public function testTheSuggestionsRouteAnswersTagEntities(): void {
		$response = $this->controller()->featuredTagSuggestions();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['name' => 'cycling']], $response->getData());
	}

	/** A profile is public, so the tags drawn on it are too. */
	public function testAnotherAccountsFeaturedTagsAnswerAnAnonymousCaller(): void {
		$this->anonymous();

		$response = $this->controller()->accountFeaturedTags('2');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::OTHER, $this->featuredOf);
	}

	public function testAnAccountMayBeNamedByHandleOrUri(): void {
		$this->controller()->accountFeaturedTags('@bob@cloud.example');
		$this->assertSame(self::OTHER, $this->featuredOf);

		$this->controller()->accountFeaturedTags(self::OTHER);
		$this->assertSame(self::OTHER, $this->featuredOf);
	}

	public function testAnAccountThatIsNotThereIsNotFound(): void {
		$response = $this->controller()->accountFeaturedTags('999');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Record not found'], $response->getData());
	}

	/**
	 * An unrecognised failure is a bug on this side: 500, and its message is
	 * not echoed on a public route.
	 */
	public function testAnUnexpectedFailureDoesNotLeakItsMessage(): void {
		$this->directoryService = $this->createMock(DirectoryService::class);
		$this->directoryService->method('page')
			->willThrowException(new RuntimeException('connection to 10.0.0.4 refused'));

		$response = $this->controller()->directory();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'internal server error'], $response->getData());
	}

	// the link timeline

	/**
	 * What a reader gets by tapping a trending link rather than following it
	 * off the instance. The links were already served; the timeline that reads
	 * them was not.
	 */
	public function testTheLinkTimelineAnswersThePostsCarryingIt(): void {
		$note = new Note();
		$note->setId('https://cloud.example/notes/1');
		$this->linkTimeline = [$note];

		$response = $this->controller()->linkTimeline('https://example.org/a', 15, 9, 3);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'url' => 'https://example.org/a', 'limit' => 15, 'maxId' => 9, 'minId' => 3,
		], $this->linkAsked);
	}

	/** One query for the whole page, as the timelines do it. */
	public function testTheLinkTimelineArrivesWithItsPreviews(): void {
		$note = new Note();
		$note->setId('https://cloud.example/notes/1');
		$this->linkTimeline = [$note];

		$this->controller()->linkTimeline('https://example.org/a');

		$this->assertTrue($this->cardsAttached);
	}

	/** The link a client holds may be one nobody here has posted since. */
	public function testAnUnknownLinkIsAnEmptyTimelineRatherThanAnError(): void {
		$response = $this->controller()->linkTimeline('https://example.org/nothing');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	public function testTheLinkTimelineAnswersAnAnonymousCaller(): void {
		$this->anonymous();

		$this->assertSame(
			Http::STATUS_OK, $this->controller()->linkTimeline('https://example.org/a')->getStatus()
		);
	}

	// dismissing a suggestion

	public function testASuggestionCanBeDismissed(): void {
		$this->accountRelationService->expects($this->once())
			->method('dismissSuggestion')
			->with(
				$this->callback(static fn (Person $viewer): bool => $viewer->getId() === self::VIEWER),
				$this->callback(static fn (Person $target): bool => $target->getId() === self::OTHER)
			);

		$response = $this->controller()->suggestionDismiss('2');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals(new stdClass(), $response->getData());
	}

	public function testDismissingASuggestionNeedsAViewer(): void {
		$this->anonymous();

		$this->assertSame(
			Http::STATUS_UNAUTHORIZED, $this->controller()->suggestionDismiss('2')->getStatus()
		);
	}

	public function testDismissingAnUnknownAccountIsNotFound(): void {
		$this->assertSame(
			Http::STATUS_NOT_FOUND, $this->controller()->suggestionDismiss('999')->getStatus()
		);
	}

	/** An account may be named by handle, as everywhere else it may. */
	public function testASuggestionMayBeDismissedByHandle(): void {
		$this->accountRelationService->expects($this->once())->method('dismissSuggestion');

		$this->assertSame(
			Http::STATUS_OK, $this->controller()->suggestionDismiss('bob@cloud.example')->getStatus()
		);
	}

}
