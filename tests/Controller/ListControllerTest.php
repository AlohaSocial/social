<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\ListController;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Relationship;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\Counts\CountService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\GroupListService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Tests\Mock\TCacheActorServiceMock;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The ten routes a client uses to keep lists.
 *
 * A list is private to the account that made it and there is nobody else who
 * may read or change one, so the contract checked here is as much about what a
 * caller is refused as about what they are given: the entity shape, the
 * pagination cursor, the scopes, and — on every route that names a list — that
 * somebody else's list is a 404 and nothing at all is written.
 */
#[AllowMockObjectsWithoutExpectations]
class ListControllerTest extends TestCase {
	use TCacheActorServiceMock;

	private const VIEWER = 'https://cloud.example/users/alice';
	private const STRANGER = 'https://cloud.example/users/bob';
	private const FOLLOWED = 'https://remote.example/users/carol';

	/** @var IRequest&Stub */
	private $request;
	private AccountService|Stub $accountService;
	private CacheActorService|MockObject $cacheActorService;
	private ClientService|Stub $clientService;
	private FollowService|Stub $followService;
	private LinkPreviewService|Stub $linkPreviewService;
	private ListsRequest|Stub $listsRequest;
	private GroupListService|MockObject $groupListService;
	private IUserSession|Stub $userSession;

	/** @var array<string, string> the request headers the controller will see */
	private array $headers = [];
	/** @var array<int, MastodonList> the stored lists, by id */
	private array $lists = [];
	/** @var array<int, array<int, string>> list id => member actor ids, oldest first */
	private array $members = [];
	/** @var string[] actor ids the viewer follows */
	private array $follows = [self::FOLLOWED];
	/** @var string[] actor ids with a follow the other side has not answered */
	private array $requested = [];
	/** @var array<int, array> [method, …] of every write */
	private array $writes = [];
	/** @var int[] the nids the timeline returns */
	private array $timeline = [];
	/** @var MastodonList|null the list the timeline was asked for */
	private ?MastodonList $timelineOf = null;
	private int $nextId = 1;
	private string $uri = '/index.php/apps/social/api/v1/lists';
	private bool $csrf = true;

	protected function setUp(): void {
		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getRequestUri')->willReturnCallback(fn (): string => $this->uri);
		$this->request->method('getParam')->willReturn('');
		$this->request->method('getParams')->willReturn([]);

		$this->userSession = $this->createStub(IUserSession::class);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->accountService = $this->createStub(AccountService::class);
		$this->accountService->method('getActorFromUserId')->willReturn($this->person(self::VIEWER, 1));

		$this->clientService = $this->createStub(ClientService::class);
		$this->linkPreviewService = $this->createStub(LinkPreviewService::class);

		$this->cacheActorService = $this->cacheActorServiceMock();
		$this->cacheActorService->method('getFromNids')
			->willReturnCallback(function (array $nids): array {
				$byNid = [1 => self::VIEWER, 2 => self::STRANGER, 3 => self::FOLLOWED];
				$actors = [];
				foreach ($nids as $nid) {
					if (isset($byNid[$nid])) {
						$actors[] = $this->person($byNid[$nid], $nid);
					}
				}

				return $actors;
			});
		$this->cacheActorService->method('getFromId')
			->willReturnCallback(fn (string $id): Person => $this->person($id, 0));
		$this->cacheActorService->method('getCachedFromIds')
			->willReturnCallback(function (array $ids): array {
				$actors = [];
				foreach ($ids as $id) {
					$actors[$id] = $this->person($id, 0);
				}

				return $actors;
			});

		$this->followService = $this->createStub(FollowService::class);
		$this->followService->method('getRelationshipWith')
			->willReturnCallback(function (Person $target): Relationship {
				$relationship = new Relationship($target->getNid());
				$relationship->setFollowing(in_array($target->getId(), $this->follows, true));
				$relationship->setRequested(in_array($target->getId(), $this->requested, true));

				return $relationship;
			});

		$this->mockListsRequest();
		$this->groupListService = $this->createMock(GroupListService::class);

		// Response::getHeaders() asks the container for the request
		\OC::$server->register(IRequest::class, $this->request);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function person(string $id, int $nid): Person {
		$person = new Person();
		$person->setId($id);
		$person->setNid($nid);

		return $person;
	}

	/**
	 * A store that enforces the one rule the real one enforces in SQL: a list
	 * is only ever reachable through the account that owns it.
	 */
	private function mockListsRequest(): void {
		$this->listsRequest = $this->createStub(ListsRequest::class);

		$this->listsRequest->method('getOwnedById')
			->willReturnCallback(function (string $actorId, int $id): MastodonList {
				$list = $this->lists[$id] ?? null;
				if ($list === null || $list->getOwnerId() !== $actorId) {
					throw new ItemNotFoundException('Record not found');
				}

				return $list;
			});

		$this->listsRequest->method('getByActor')
			->willReturnCallback(fn (string $actorId): array => array_values(array_filter(
				$this->lists, static fn (MastodonList $l): bool => $l->getOwnerId() === $actorId
			)));

		$this->listsRequest->method('getByMember')
			->willReturnCallback(fn (string $actorId, string $memberId): array => array_values(array_filter(
				$this->lists,
				fn (MastodonList $l): bool => $l->getOwnerId() === $actorId
					&& in_array($memberId, $this->members[$l->getId()] ?? [], true)
			)));

		$this->listsRequest->method('create')
			->willReturnCallback(function (MastodonList $list): MastodonList {
				$list->setId($this->nextId++);
				$this->lists[$list->getId()] = $list;
				$this->writes[] = ['create', $list->getId(), $list->getTitle()];

				return $list;
			});

		$this->listsRequest->method('update')
			->willReturnCallback(function (MastodonList $list): void {
				$this->writes[] = ['update', $list->getId(), $list->getTitle()];
			});

		$this->listsRequest->method('delete')
			->willReturnCallback(function (MastodonList $list): void {
				unset($this->lists[$list->getId()], $this->members[$list->getId()]);
				$this->writes[] = ['delete', $list->getId()];
			});

		$this->listsRequest->method('addMember')
			->willReturnCallback(function (MastodonList $list, string $memberId): void {
				$this->writes[] = ['addMember', $list->getId(), $memberId];
				if (!in_array($memberId, $this->members[$list->getId()] ?? [], true)) {
					$this->members[$list->getId()][] = $memberId;
				}
			});

		$this->listsRequest->method('removeMember')
			->willReturnCallback(function (MastodonList $list, string $memberId): void {
				$this->writes[] = ['removeMember', $list->getId(), $memberId];
				$this->members[$list->getId()] = array_values(
					array_diff($this->members[$list->getId()] ?? [], [$memberId])
				);
			});

		$this->listsRequest->method('getMembers')
			->willReturnCallback(function (MastodonList $list, int $limit): array {
				$rows = [];
				foreach (array_reverse($this->members[$list->getId()] ?? [], true) as $offset => $actorId) {
					$rows[] = ['id' => $offset + 1, 'actorId' => $actorId];
				}

				return array_slice($rows, 0, $limit);
			});

		$this->listsRequest->method('getTimeline')
			->willReturnCallback(function (MastodonList $list, ProbeOptions $options): array {
				$this->timelineOf = $list;

				return array_map(static function (int $nid): Note {
					$note = new Note();
					$note->setNid($nid);

					return $note;
				}, $this->timeline);
			});
	}

	/**
	 * The bearer token is parsed in the constructor, so a test that presents
	 * one has to say so before the controller exists.
	 */
	private function controller(string $authorization = '', ?CountService $counts = null): ListController {
		// the getHeader() callback is registered once, in setUp(): a second
		// method() on the same mock never wins over the first
		$this->headers = ['Authorization' => $authorization];

		return new ListController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->cacheActorService,
			$this->clientService,
			$this->followService,
			$this->linkPreviewService,
			$this->listsRequest,
			$this->createStub(PlaceService::class),
			$this->groupListService,
			null,
			$counts,
		);
	}

	/** A list that follows a Nextcloud group, owned by whoever is named. */
	private function givenGroupList(int $id, string $owner, string $gid = 'design', string $title = 'Design'): MastodonList {
		return $this->given($id, $owner, $title)->setGroupId($gid);
	}

	/** A list that is already there, owned by whoever is named. */
	private function given(int $id, string $owner, string $title = 'Friends'): MastodonList {
		$list = (new MastodonList())->setId($id)->setOwnerId($owner)->setTitle($title);
		$this->lists[$id] = $list;
		$this->nextId = max($this->nextId, $id + 1);

		return $list;
	}

	private function token(array $scopes): SocialClient {
		$this->csrf = false;
		$client = new SocialClient();
		$client->setAuthUserId('alice');
		$client->setAuthScopes($scopes);
		$this->clientService->method('getFromToken')->willReturn($client);

		return $client;
	}

	public function testCreatingAListAnswersWithTheListCreated(): void {
		$response = $this->controller()->create('Friends');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['id' => '1', 'title' => 'Friends', 'replies_policy' => 'list', 'exclusive' => false, 'nextcloud_group' => null],
			$response->getData()->jsonSerialize()
		);
		$this->assertSame([['create', 1, 'Friends']], $this->writes);
	}

	public function testANewListCarriesTheSettingsItWasAskedFor(): void {
		$response = $this->controller()->create('Work', 'followed', true);

		$this->assertSame('followed', $response->getData()->getRepliesPolicy());
		$this->assertTrue($response->getData()->isExclusive());
	}

	public function testAListWithNoTitleIsRefused(): void {
		// Mastodon's "Title can't be blank"; storing it would be a list the
		// user cannot tell from any other blank one
		foreach (['', '   ', "\n"] as $blank) {
			$response = $this->controller()->create($blank);
			$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
			$this->assertArrayHasKey('error', $response->getData());
		}

		$this->assertSame([], $this->writes, 'nothing is written');
	}

	public function testAPolicyThatIsNotOneIsRefusedRatherThanQuietlyReplaced(): void {
		// a client that asked for one thing must not be shown another
		$response = $this->controller()->create('Friends', 'everything');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('replies_policy', $response->getData()['error']);
		$this->assertSame([], $this->writes);
	}

	// the lists a Nextcloud group gives its members

	public function testAskingForTheListsIsWhatMakesTheGroupListsTheViewerIsMissing(): void {
		$this->groupListService->expects($this->once())->method('ensureForViewer')
			->with($this->callback(fn (Person $viewer): bool => $viewer->getId() === self::VIEWER));

		$this->assertSame(Http::STATUS_OK, $this->controller()->index()->getStatus());
	}

	public function testTheSidebarSurvivesTheGroupListsFailing(): void {
		$this->given(1, self::VIEWER, 'Friends');
		$this->groupListService->method('ensureForViewer')->willThrowException(new \RuntimeException('groups backend down'));

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testAGroupListIsHandedBackWithTheGroupItFollows(): void {
		$this->givenGroupList(4, self::VIEWER);

		$this->assertSame('design', $this->controller()->get(4)->getData()->jsonSerialize()['nextcloud_group']);
	}

	public function testAGroupListKeepsTheGroupsNameButTakesTheOtherSettings(): void {
		$this->givenGroupList(4, self::VIEWER);

		$response = $this->controller()->update(4, 'My design people', 'none', true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Design', $response->getData()->getTitle(), 'called what the group is called');
		$this->assertSame('none', $response->getData()->getRepliesPolicy());
		$this->assertTrue($response->getData()->isExclusive());
	}

	public function testAGroupListCannotBeDeletedOrHaveItsMembersEdited(): void {
		$this->givenGroupList(4, self::VIEWER);
		$controller = $this->controller();

		foreach ([
			$controller->delete(4),
			$controller->addAccounts(4, ['3']),
			$controller->removeAccounts(4, ['3']),
		] as $response) {
			// a 422 with the reason, not a 404: the list is there and is theirs
			$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
			$this->assertStringContainsString('Nextcloud group "Design"', $response->getData()['error']);
		}
		$this->assertSame([], $this->writes, 'nothing is written');
		$this->assertArrayHasKey(4, $this->lists);
	}

	public function testTheIndexIsEveryListTheViewerOwnsAndNobodyElses(): void {
		$this->given(1, self::VIEWER, 'Friends');
		$this->given(2, self::STRANGER, 'Bobs list');
		$this->given(3, self::VIEWER, 'Work');

		$response = $this->controller()->index();

		$this->assertSame(
			['Friends', 'Work'],
			array_map(static fn (MastodonList $l): string => $l->getTitle(), $response->getData())
		);
	}

	public function testALookupAnswersWithTheList(): void {
		$this->given(4, self::VIEWER, 'Friends');

		$response = $this->controller()->get(4);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('4', $response->getData()->jsonSerialize()['id']);
	}

	public function testUpdatingChangesTheTitleAndTheSettings(): void {
		$this->given(4, self::VIEWER, 'Friends');

		$response = $this->controller()->update(4, 'Close friends', 'none', true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Close friends', $response->getData()->getTitle());
		$this->assertSame('none', $response->getData()->getRepliesPolicy());
		$this->assertTrue($response->getData()->isExclusive());
		$this->assertSame([['update', 4, 'Close friends']], $this->writes);
	}

	public function testUpdatingWithNoTitleIsRefusedRatherThanKeepingTheOldOne(): void {
		// Mastodon requires title on an update as it does on a create
		$this->given(4, self::VIEWER, 'Friends');

		$response = $this->controller()->update(4, '');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame([], $this->writes);
	}

	public function testDeletingAnswersWithAnEmptyObject(): void {
		$this->given(4, self::VIEWER);

		$response = $this->controller()->delete(4);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
		$this->assertSame([['delete', 4]], $this->writes);
	}

	public function testSomebodyElsesListIsNotThereOnAnyRouteThatNamesOne(): void {
		// and it is a 404, not a 403: telling the two apart would say whether
		// an id exists
		$this->given(9, self::STRANGER, 'Bobs list');

		$controller = $this->controller();
		$responses = [
			$controller->get(9),
			$controller->update(9, 'mine now'),
			$controller->delete(9),
			$controller->accounts(9),
			$controller->addAccounts(9, ['3']),
			$controller->removeAccounts(9, ['3']),
			$controller->timeline(9),
		];

		foreach ($responses as $response) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame('Record not found', $response->getData()['error']);
		}

		$this->assertSame([], $this->writes, 'nothing is written to a list that is not yours');
		$this->assertSame(['Bobs list'], [$this->lists[9]->getTitle()], 'and nothing is changed');
	}

	public function testAListThatNeverExistedIsTheSameAnswer(): void {
		$response = $this->controller()->get(4242);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Record not found', $response->getData()['error']);
	}

	public function testAddingAnAccountTheViewerFollows(): void {
		$this->given(4, self::VIEWER);

		$response = $this->controller()->addAccounts(4, ['3']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData(), 'Mastodon answers {}');
		$this->assertSame([['addMember', 4, self::FOLLOWED]], $this->writes);
	}

	public function testAnAccountTheViewerDoesNotFollowMayNotBeAdded(): void {
		// a list is a view of what you already follow, not a second way of
		// reading somebody
		$this->given(4, self::VIEWER);

		$response = $this->controller()->addAccounts(4, ['2']);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $this->writes);
	}

	public function testAFollowThatHasNotBeenAnsweredYetCounts(): void {
		// otherwise a locked account could never be put in a list
		$this->given(4, self::VIEWER);
		$this->follows = [];
		$this->requested = [self::STRANGER];

		$response = $this->controller()->addAccounts(4, ['2']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['addMember', 4, self::STRANGER]], $this->writes);
	}

	public function testTheViewerMayBeInTheirOwnListWithoutFollowingThemself(): void {
		$this->given(4, self::VIEWER);
		$this->follows = [];

		$response = $this->controller()->addAccounts(4, ['1']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['addMember', 4, self::VIEWER]], $this->writes);
	}

	public function testOneUnaddableAccountAddsNoneOfThem(): void {
		// a partly applied write is one a client cannot retry safely
		$this->given(4, self::VIEWER);

		$response = $this->controller()->addAccounts(4, ['3', '2']);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $this->writes);
	}

	public function testOneIdSentWithoutTheBracketsStillWorks(): void {
		// PHP hands a bare `account_ids=3` over as a scalar, and a TypeError in
		// the dispatcher would be a Nextcloud error page, not `{"error": …}`
		$this->given(4, self::VIEWER);

		$response = $this->controller()->addAccounts(4, '3');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['addMember', 4, self::FOLLOWED]], $this->writes);
	}

	public function testAddingWithNoAccountsAtAllIsA422RatherThanA500(): void {
		// a request-bound array parameter is filled in by the dispatcher,
		// before the handler's try block
		$this->given(4, self::VIEWER);

		$response = $this->controller()->addAccounts(4);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame([], $this->writes);
	}

	public function testRemovingAnAccountThatIsNotInTheListIsNotAnError(): void {
		$this->given(4, self::VIEWER);

		$response = $this->controller()->removeAccounts(4, ['3']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
		$this->assertSame([['removeMember', 4, self::FOLLOWED]], $this->writes);
	}

	public function testRemovingNeedsNoFollowAtAll(): void {
		// unfollowing somebody must not leave them stuck in a list forever
		$this->given(4, self::VIEWER);
		$this->members[4] = [self::STRANGER];
		$this->follows = [];

		$response = $this->controller()->removeAccounts(4, ['2']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['removeMember', 4, self::STRANGER]], $this->writes);
		$this->assertSame([], $this->members[4]);
	}

	public function testTheMembersAreAccountsNewestAdditionFirst(): void {
		$this->given(4, self::VIEWER);
		$this->members[4] = [self::FOLLOWED, self::STRANGER];

		$response = $this->controller()->accounts(4);

		$this->assertSame(
			[self::STRANGER, self::FOLLOWED],
			array_map(static fn (Person $p): string => $p->getId(), $response->getData())
		);
	}

	/**
	 * The controller used to read `limit=0` as "all of them", which Mastodon
	 * documents. It never ran: Nextcloud's dispatcher applies a range of 1–500
	 * to any parameter named `limit` and throws before the method is entered,
	 * so every request the settings page made came back a 500. What is left is
	 * a plain clamp, and these pin its two edges.
	 */
	public function testALimitLargerThanTheCeilingIsClampedToIt(): void {
		$this->given(4, self::VIEWER);
		$this->members[4] = array_fill(0, 120, self::FOLLOWED);

		$response = $this->controller()->accounts(4, 900);

		$this->assertCount(120, $response->getData(), 'nothing under the ceiling is dropped');
	}

	public function testALimitOfNoneIsStillOneRatherThanNothing(): void {
		$this->given(4, self::VIEWER);
		$this->members[4] = [self::FOLLOWED, self::STRANGER];

		// the dispatcher refuses this before the method in a real request;
		// the clamp is what keeps it sane if it ever arrives another way
		$response = $this->controller()->accounts(4, 0);

		$this->assertCount(1, $response->getData());
	}

	public function testTheMembersPageOnTheMembershipRowIdRatherThanTheAccount(): void {
		// an account can be removed from a list and added again, so its own id
		// does not move in one direction
		$this->uri = '/index.php/apps/social/api/v1/lists/4/accounts?limit=2';
		$this->given(4, self::VIEWER);
		$this->members[4] = [self::FOLLOWED, self::STRANGER];

		$link = $this->controller()->accounts(4, 2)->getHeaders()['Link'] ?? '';

		$this->assertStringContainsString('max_id=1', $link);
		$this->assertStringContainsString('rel="next"', $link);
		$this->assertStringContainsString('min_id=2', $link);
		$this->assertStringContainsString('rel="prev"', $link);
		$this->assertStringContainsString('limit=2', $link, 'the caller\'s own filters survive');
	}

	public function testAShortPageOfMembersIsTheLastOne(): void {
		$this->given(4, self::VIEWER);
		$this->members[4] = [self::FOLLOWED];

		$link = $this->controller()->accounts(4, 40)->getHeaders()['Link'] ?? '';

		$this->assertStringNotContainsString('rel="next"', $link);
		$this->assertStringContainsString('rel="prev"', $link);
	}

	public function testAnEmptyListOfMembersCarriesNoCursorAtAll(): void {
		$this->given(4, self::VIEWER);

		$response = $this->controller()->accounts(4);

		$this->assertSame([], $response->getData());
		$this->assertArrayNotHasKey('Link', $response->getHeaders());
	}

	public function testAskingForEverythingIsStillBounded(): void {
		// The page is built in memory, one cached actor per row, so there is a
		// ceiling however much is asked for. It is 500 because that is the
		// most Nextcloud's dispatcher will pass through for a `limit` at all.
		$this->given(4, self::VIEWER);
		$asked = 0;
		$this->listsRequest = $this->createStub(ListsRequest::class);
		$this->listsRequest->method('getOwnedById')->willReturn($this->lists[4]);
		$this->listsRequest->method('getMembers')
			->willReturnCallback(function (MastodonList $list, int $limit) use (&$asked): array {
				$asked = $limit;

				return [];
			});

		$this->controller()->accounts(4, 900);

		$this->assertGreaterThan(80, $asked, 'more than one page');
		$this->assertLessThanOrEqual(500, $asked, 'and never past what the dispatcher allows');
	}

	public function testAMemberWhoseActorIsGoneIsLeftOutRatherThanHalfFilled(): void {
		$this->given(4, self::VIEWER);
		// the newest membership row is the one whose actor has gone
		$this->members[4] = [self::FOLLOWED, 'https://gone.example/users/dave'];
		$this->cacheActorService = $this->cacheActorServiceMock();
		$this->cacheActorService->method('getCachedFromIds')
			->willReturn([self::FOLLOWED => $this->person(self::FOLLOWED, 3)]);

		$response = $this->controller()->accounts(4, 2);

		$this->assertSame(
			[self::FOLLOWED],
			array_map(static fn (Person $p): string => $p->getId(), $response->getData())
		);

		$link = $response->getHeaders()['Link'] ?? '';
		$this->assertStringContainsString(
			'min_id=2',
			$link,
			'the dropped row still decides the cursor, so paging does not stall on it'
		);
		$this->assertStringContainsString(
			'rel="next"',
			$link,
			'and a full page of rows is a full page even when one of them could not be drawn'
		);
	}

	public function testWhichListsAnAccountIsInIsOnlyEverTheViewersOwn(): void {
		// which lists a stranger put somebody in is not a thing either of them
		// may read
		$this->given(1, self::VIEWER, 'Friends');
		$this->given(2, self::STRANGER, 'Bobs list');
		$this->members[1] = [self::FOLLOWED];
		$this->members[2] = [self::FOLLOWED];

		$response = $this->controller()->accountLists('3');

		$this->assertSame(
			['Friends'],
			array_map(static fn (MastodonList $l): string => $l->getTitle(), $response->getData())
		);
	}

	public function testAnAccountThatDoesNotExistIsA404(): void {
		$this->cacheActorService = $this->cacheActorServiceMock();
		$this->cacheActorService->method('getFromNids')->willReturn([]);

		$response = $this->controller()->accountLists('77');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testTheTimelineIsTheOneTheListNames(): void {
		$this->given(4, self::VIEWER);
		$this->timeline = [9, 7];

		$response = $this->controller()->timeline(4);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(4, $this->timelineOf?->getId());
		$this->assertSame([9, 7], array_map(static fn (Note $n): int => $n->getNid(), $response->getData()));
	}

	public function testTheTimelineHasItsPostsCountsAskedFor(): void {
		$this->given(4, self::VIEWER);
		$this->timeline = [9, 7];
		$counts = $this->createMock(CountService::class);
		$counts->expects($this->once())->method('seen')
			->with($this->callback(static fn (array $posts): bool => array_map(static fn (Note $n): int => $n->getNid(), $posts) === [9, 7]));

		$this->controller('', $counts)->timeline(4);
	}

	public function testTheTimelinePagesOnTheStatusIdAsEveryOtherTimelineDoes(): void {
		$this->uri = '/index.php/apps/social/api/v1/timelines/list/4?limit=2';
		$this->given(4, self::VIEWER);
		$this->timeline = [9, 7];

		$link = $this->controller()->timeline(4, 2)->getHeaders()['Link'] ?? '';

		$this->assertStringContainsString('max_id=7', $link);
		$this->assertStringContainsString('rel="next"', $link);
		$this->assertStringContainsString('min_id=9', $link);
		$this->assertStringContainsString('rel="prev"', $link);
	}

	public function testTheTimelineIsReadAsTheListsOwner(): void {
		// the visibility filters are the home timeline's, and they are about
		// the viewer: a query run without one would be answering as nobody
		$this->given(4, self::VIEWER);
		$seen = null;
		$this->listsRequest->method('setViewer')
			->willReturnCallback(function (Person $viewer) use (&$seen): void {
				$seen = $viewer->getId();
			});

		$this->controller()->timeline(4);

		$this->assertSame(self::VIEWER, $seen);
	}

	public function testAnUnauthenticatedCallerIsToldSoRatherThanServedNothing(): void {
		$this->csrf = false;
		$this->given(4, self::VIEWER);

		$controller = $this->controller();
		$responses = [
			$controller->index(),
			$controller->create('Friends'),
			$controller->get(4),
			$controller->update(4, 'Friends'),
			$controller->delete(4),
			$controller->accounts(4),
			$controller->addAccounts(4, ['3']),
			$controller->removeAccounts(4, ['3']),
			$controller->accountLists('3'),
			$controller->timeline(4),
		];

		foreach ($responses as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertArrayHasKey('error', $response->getData());
		}

		$this->assertSame([], $this->writes);
	}

	public function testABearerTokenIsAcceptedWithoutASession(): void {
		// which is the only way a Mastodon client ever calls this
		$this->token(['read:lists', 'write:lists']);

		$this->assertSame(
			Http::STATUS_OK, $this->controller('Bearer sometoken')->create('Friends')->getStatus()
		);
	}

	public function testARevokedTokenIsA401(): void {
		$this->csrf = false;
		$this->clientService->method('getFromToken')
			->willThrowException(new ClientNotFoundException('the access_token was revoked'));

		$response = $this->controller('Bearer stale')->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('the access_token was revoked', $response->getData()['error']);
	}

	public function testTheBroadScopeCarriesTheGranularOne(): void {
		// 'read' is 'read:lists' and everything else; a client that asked for
		// the whole of read does not have to ask again
		$this->token(['read']);
		$this->given(4, self::VIEWER);

		$this->assertSame(Http::STATUS_OK, $this->controller('Bearer t')->index()->getStatus());
	}

	public function testAnotherGranularReadScopeIsNotThisOne(): void {
		// lists are the one thing in this API that nobody but their owner may
		// see, so read:statuses is not read:lists
		$this->token(['read:statuses', 'write:statuses']);
		$this->given(4, self::VIEWER);

		$response = $this->controller('Bearer t')->index();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertArrayNotHasKey('WWW-Authenticate', $response->getHeaders());
	}

	public function testATokenThatMayOnlyReadMayNotWrite(): void {
		$this->token(['read:lists']);
		$this->given(4, self::VIEWER);

		$controller = $this->controller('Bearer readonly');
		foreach ([
			$controller->create('Friends'),
			$controller->update(4, 'Friends'),
			$controller->delete(4),
			$controller->addAccounts(4, ['3']),
			$controller->removeAccounts(4, ['3']),
		] as $response) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		}

		$this->assertSame([], $this->writes);

		// and reading is still a read
		$this->assertSame(Http::STATUS_OK, $controller->index()->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->get(4)->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->accounts(4)->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->timeline(4)->getStatus());
	}
}
