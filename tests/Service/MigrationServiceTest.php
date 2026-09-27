<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Move;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\RelationshipService;
use OCA\Social\Service\SignatureService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
class MigrationServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';
	private const NEW_ALICE = 'https://new.example/users/alice';
	private const BOB = 'https://cloud.example/apps/social/@bob';
	private const CAROL = 'https://remote.example/users/carol';

	private AccountService|MockObject $accountService;
	private ActorsRequest|Stub $actorsRequest;
	private FollowsRequest|MockObject $followsRequest;
	private CacheActorService|MockObject $cacheActorService;
	private FollowService|MockObject $followService;
	private ActivityService|MockObject $activityService;
	private SignatureService|MockObject $signatureService;
	private ActorRelationRequest|Stub $actorRelationRequest;
	private ListsRequest|MockObject $listsRequest;
	private RelationshipService|MockObject $relationshipService;
	private MigrationService $service;

	protected function setUp(): void {
		$this->accountService = $this->createMock(AccountService::class);
		$this->actorsRequest = $this->createStub(ActorsRequest::class);
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->followService = $this->createMock(FollowService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->signatureService = $this->createMock(SignatureService::class);
		$this->actorRelationRequest = $this->createStub(ActorRelationRequest::class);
		$this->listsRequest = $this->createMock(ListsRequest::class);
		$this->relationshipService = $this->createMock(RelationshipService::class);

		$this->service = new MigrationService(
			$this->accountService,
			$this->actorsRequest,
			$this->followsRequest,
			$this->cacheActorService,
			$this->followService,
			$this->activityService,
			$this->signatureService,
			$this->actorRelationRequest,
			$this->listsRequest,
			$this->relationshipService,
			new NullLogger(),
		);
	}

	private function person(string $id, string $account, bool $local = false): Person {
		$person = new Person();
		$person->setId($id)
			->setPreferredUsername(explode('@', $account)[0])
			->setAccount($account)
			->setInbox($id . '/inbox')
			->setFollowers($id . '/followers')
			->setLocal($local);

		return $person;
	}

	private function alice(): Person {
		return $this->person(self::ALICE, 'alice@cloud.example', true);
	}

	private function newAlice(bool $listsAlice = true): Person {
		$target = $this->person(self::NEW_ALICE, 'alice@new.example');
		if ($listsAlice) {
			$target->setAlsoKnownAs([self::ALICE]);
		}

		return $target;
	}

	private function follow(string $actorId): Follow {
		$follow = new Follow();
		$follow->setId('https://cloud.example/apps/social/follow/' . md5($actorId));
		$follow->setActorId($actorId);
		$follow->setObjectId(self::ALICE);
		$follow->setAccepted(true);

		return $follow;
	}

	// --- aliases ----------------------------------------------------------

	public function testAddAliasAppendsToTheListOnce(): void {
		$alice = $this->alice();
		$alice->setAlsoKnownAs(['https://old.example/users/alice']);
		$this->accountService->method('getActorFromUserId')->with('alice')->willReturn($alice);
		$this->accountService->expects($this->once())->method('setAlsoKnownAs')
			->with('alice', ['https://old.example/users/alice', 'https://older.example/users/alice']);

		$aliases = $this->service->addAlias('alice', 'https://older.example/users/alice');

		$this->assertSame(['https://old.example/users/alice', 'https://older.example/users/alice'], $aliases);
	}

	public function testAddAliasThatIsAlreadyListedChangesNothing(): void {
		$alice = $this->alice();
		$alice->setAlsoKnownAs(['https://old.example/users/alice']);
		$this->accountService->method('getActorFromUserId')->willReturn($alice);
		$this->accountService->expects($this->never())->method('setAlsoKnownAs');

		$aliases = $this->service->addAlias('alice', 'https://old.example/users/alice');

		$this->assertSame(['https://old.example/users/alice'], $aliases);
	}

	#[DataProvider('notAnActorIdProvider')]
	public function testAddAliasRefusesWhatIsNotAnActorId(string $alias): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->accountService->expects($this->never())->method('setAlsoKnownAs');

		$this->expectException(InvalidResourceException::class);
		$this->service->addAlias('alice', $alias);
	}

	public static function notAnActorIdProvider(): array {
		return [
			'a handle' => ['alice@old.example'],
			'empty' => [''],
			'no host' => ['https:///users/alice'],
			'not http' => ['ftp://old.example/users/alice'],
			'itself' => [self::ALICE],
		];
	}

	public function testRemoveAliasDropsItFromTheList(): void {
		$alice = $this->alice();
		$alice->setAlsoKnownAs(['https://old.example/users/alice', 'https://older.example/users/alice']);
		$this->accountService->method('getActorFromUserId')->willReturn($alice);
		$this->accountService->expects($this->once())->method('setAlsoKnownAs')
			->with('alice', ['https://older.example/users/alice']);

		$aliases = $this->service->removeAlias('alice', 'https://old.example/users/alice');

		$this->assertSame(['https://older.example/users/alice'], $aliases);
	}

	public function testRemoveAliasThatIsNotListedChangesNothing(): void {
		$alice = $this->alice();
		$this->accountService->method('getActorFromUserId')->willReturn($alice);
		$this->accountService->expects($this->never())->method('setAlsoKnownAs');

		$this->assertSame([], $this->service->removeAlias('alice', 'https://old.example/users/alice'));
	}

	public function testListAliases(): void {
		$alice = $this->alice();
		$alice->setAlsoKnownAs(['https://old.example/users/alice']);
		$this->accountService->method('getActorFromUserId')->willReturn($alice);

		$this->assertSame(['https://old.example/users/alice'], $this->service->listAliases('alice'));
	}

	// --- move -----------------------------------------------------------------

	public function testMoveFederatesAMoveToTheFollowersAndRecordsTheTarget(): void {
		$alice = $this->alice();
		$this->accountService->method('getActorFromUserId')->with('alice')->willReturn($alice);
		// the target is fetched fresh: the alsoKnownAs that counts is the one its server publishes now
		$this->cacheActorService->expects($this->once())->method('getFromId')
			->with(self::NEW_ALICE, true)->willReturn($this->newAlice());
		$this->followsRequest->method('getFollowersByActorId')->willReturn([]);

		$sent = null;
		$this->signatureService->expects($this->once())->method('signObject')
			->with($this->identicalTo($alice), $this->isInstanceOf(Move::class));
		$this->activityService->expects($this->once())->method('request')
			->willReturnCallback(function (ACore $activity) use (&$sent): string {
				$sent = $activity;

				return 'token';
			});
		$this->accountService->expects($this->once())->method('setMovedTo')->with('alice', self::NEW_ALICE);

		$target = $this->service->move('alice', self::NEW_ALICE);

		$this->assertSame(self::NEW_ALICE, $target->getId());
		$this->assertInstanceOf(Move::class, $sent);
		$document = json_decode(json_encode($sent), true);
		$this->assertSame('Move', $document['type']);
		$this->assertSame(self::ALICE, $document['actor']);
		$this->assertSame(self::ALICE, $document['object'], 'the account that moves');
		$this->assertSame(self::NEW_ALICE, $document['target'], 'where it moved to — without it the Move says nothing');
		$this->assertStringStartsWith(self::ALICE . '#', $document['id']);

		$paths = array_map(
			static fn (InstancePath $path): array => [$path->getUri(), $path->getType()],
			$sent->getInstancePaths()
		);
		$this->assertContains([self::ALICE, InstancePath::TYPE_FOLLOWERS], $paths, 'fanned out to every follower inbox');
		$this->assertContains([self::NEW_ALICE . '/inbox', InstancePath::TYPE_INBOX], $paths, 'and told to the new home');
	}

	public function testMoveRefusesATargetThatDoesNotListTheActor(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->cacheActorService->method('getFromId')->willReturn($this->newAlice(false));
		$this->activityService->expects($this->never())->method('request');
		$this->accountService->expects($this->never())->method('setMovedTo');

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/alsoKnownAs/');
		$this->service->move('alice', self::NEW_ALICE);
	}

	public function testMoveRefusesToMoveAnAccountOntoItself(): void {
		$alice = $this->alice();
		$alice->setAlsoKnownAs([self::ALICE]);
		$this->accountService->method('getActorFromUserId')->willReturn($alice);
		$this->cacheActorService->method('getFromId')->willReturn($alice);
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(InvalidResourceException::class);
		$this->service->move('alice', self::ALICE);
	}

	public function testMoveRecordsTheTargetOnlyAfterTheMoveWasQueued(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->cacheActorService->method('getFromId')->willReturn($this->newAlice());
		$this->activityService->method('request')->willThrowException(new RuntimeException('queue is down'));
		$this->accountService->expects($this->never())->method('setMovedTo');

		$this->expectException(RuntimeException::class);
		$this->service->move('alice', self::NEW_ALICE);
	}

	/**
	 * A follower on this very instance never receives the Move — deliveries
	 * to ourselves are dropped — so nothing would re-follow on their behalf.
	 * Mastodon re-follows for its users on receiving a Move; so does this, for
	 * the local ones, through the ordinary follow path.
	 */
	public function testMoveRefollowsTheLocalFollowersAndLeavesRemoteOnesToTheirServer(): void {
		$alice = $this->alice();
		$bob = $this->person(self::BOB, 'bob@cloud.example', true);
		$this->accountService->method('getActorFromUserId')->willReturn($alice);
		$this->cacheActorService->method('getFromId')->willReturn($this->newAlice());
		$this->followsRequest->method('getFollowersByActorId')->with(self::ALICE)
			->willReturn([$this->follow(self::BOB), $this->follow(self::CAROL)]);
		$this->actorsRequest->method('getFromId')->willReturnCallback(function (string $id) use ($bob): Person {
			if ($id === self::BOB) {
				return $bob;
			}
			throw new ActorDoesNotExistException();
		});
		$this->followService->expects($this->once())->method('followAccount')
			->with($this->identicalTo($bob), 'alice@new.example');

		$this->service->move('alice', self::NEW_ALICE);
	}

	public function testOneLocalFollowerThatCannotRefollowDoesNotStopTheOthers(): void {
		$alice = $this->alice();
		$bob = $this->person(self::BOB, 'bob@cloud.example', true);
		$dave = $this->person('https://cloud.example/apps/social/@dave', 'dave@cloud.example', true);
		$this->accountService->method('getActorFromUserId')->willReturn($alice);
		$this->cacheActorService->method('getFromId')->willReturn($this->newAlice());
		$this->followsRequest->method('getFollowersByActorId')
			->willReturn([$this->follow($bob->getId()), $this->follow($dave->getId())]);
		$this->actorsRequest->method('getFromId')->willReturnCallback(
			static fn (string $id): Person => $id === $bob->getId() ? $bob : $dave
		);
		$calls = 0;
		$this->followService->method('followAccount')->willReturnCallback(function () use (&$calls): bool {
			$calls++;
			if ($calls === 1) {
				throw new RuntimeException('unreachable');
			}

			return true;
		});

		$this->service->move('alice', self::NEW_ALICE);

		$this->assertSame(2, $calls);
	}

	// --- follows CSV ------------------------------------------------------------

	private const MASTODON_CSV = "Account address,Show boosts,Notify on new posts,Languages\n"
		. "carol@remote.example,true,false,\n"
		. "dave@other.example,false,false,\"en, de\"\n"
		. "\n"
		. "@erin@third.example,true,false,\n";

	/** Pixelfed hands out a JSON array of actor URLs, never a CSV. */
	public function testParseFollowsReadsPixelfedsJsonExport(): void {
		$json = json_encode([
			'https://pixelfed.social/users/carol',
			'https://pixelfed.social/users/dave/',
			'https://pixelfed.social/users/carol',
		]);

		$this->assertSame(
			['https://pixelfed.social/users/carol', 'https://pixelfed.social/users/dave'],
			MigrationService::parseFollows($json)
		);
	}

	/** An export is the one file its author cannot fix, so the reading is generous. */
	public function testParseFollowsReadsJsonEntriesWhateverShapeTheyTake(): void {
		$json = json_encode(['following' => [
			['url' => 'https://pixelfed.social/users/carol'],
			['acct' => '@dave@other.example'],
			['id' => 'https://third.example/users/erin'],
			'frank@fourth.example',
			'not an account',
			42,
		]]);

		$this->assertSame(
			[
				'https://pixelfed.social/users/carol',
				'dave@other.example',
				'https://third.example/users/erin',
				'frank@fourth.example',
			],
			MigrationService::parseFollows($json)
		);
	}

	/** A CSV is still a CSV, JSON or not at the front of the file. */
	public function testParseFollowsStillReadsACsv(): void {
		$this->assertSame(
			MigrationService::parseFollowsCsv(self::MASTODON_CSV),
			MigrationService::parseFollows(self::MASTODON_CSV)
		);
		// a file that starts like JSON but is not one falls back to the CSV reading
		$this->assertSame([], MigrationService::parseFollows('[not json'));
	}

	/** A URL is fetched and followed as the actor it resolves to, not looked up as a handle. */
	public function testImportFollowsFetchesAnActorUrlAndFollowsWhatCameBack(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$carol = $this->person(self::CAROL, 'carol@remote.example');
		$this->cacheActorService->expects($this->once())
			->method('getFromId')
			->with(self::CAROL, true)
			->willReturn($carol);
		$this->followService->expects($this->once())->method('followActor')->with($this->alice(), $carol);
		$this->followService->expects($this->never())->method('followAccount');

		$result = $this->service->importFollows('alice', json_encode([self::CAROL]));

		$this->assertSame(1, $result['followed']);
	}

	/** An export that names the importing account itself is skipped, by handle or by URL. */
	public function testImportFollowsSkipsTheAccountItself(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->followService->expects($this->never())->method('followActor');
		$this->followService->expects($this->never())->method('followAccount');

		$result = $this->service->importFollows('alice', json_encode([self::ALICE, 'alice@cloud.example']));

		$this->assertSame(2, $result['skipped']);
	}

	public function testParseFollowsCsvReadsTheMastodonExport(): void {
		$this->assertSame(
			['carol@remote.example', 'dave@other.example', 'erin@third.example'],
			MigrationService::parseFollowsCsv(self::MASTODON_CSV)
		);
	}

	public function testParseFollowsCsvReadsTheOlderHeaderlessExport(): void {
		$this->assertSame(
			['carol@remote.example', 'dave@other.example'],
			MigrationService::parseFollowsCsv("carol@remote.example\r\ndave@other.example\r\n")
		);
	}

	public function testParseFollowsCsvFindsTheAddressColumnWhereverItIs(): void {
		$csv = "Show boosts,Account address\ntrue,carol@remote.example\n";

		$this->assertSame(['carol@remote.example'], MigrationService::parseFollowsCsv($csv));
	}

	public function testParseFollowsCsvDropsDuplicatesAndJunk(): void {
		$csv = "Account address\ncarol@remote.example\nCarol@Remote.example\nnot a handle\n  \n";

		$this->assertSame(['carol@remote.example'], MigrationService::parseFollowsCsv($csv));
	}

	/**
	 * A fediverse host is usually dotted, but an instance reached as `devel`
	 * or `cloud` on a private network is not, and insisting on a dot dropped
	 * every handle on such a server — including the ones in this app's own
	 * export.
	 */
	public function testParseFollowsCsvKeepsAHandleOnASingleLabelHost(): void {
		$csv = "Account address\nerik@devel\nhana@cloud\n";

		$this->assertSame(['erik@devel', 'hana@cloud'], MigrationService::parseFollowsCsv($csv));
	}

	public function testImportFollowsFollowsEachHandleAndCounts(): void {
		$alice = $this->alice();
		$this->accountService->method('getActorFromUserId')->with('alice')->willReturn($alice);
		$followed = [];
		$this->followService->method('followAccount')
			->willReturnCallback(function (Person $actor, string $account) use (&$followed): bool {
				$followed[] = $account;

				return true;
			});

		$result = $this->service->importFollows('alice', self::MASTODON_CSV);

		$this->assertSame(['carol@remote.example', 'dave@other.example', 'erin@third.example'], $followed);
		$this->assertSame(3, $result['followed']);
		$this->assertSame(0, $result['skipped']);
		$this->assertSame([], $result['failed']);
	}

	public function testImportFollowsContinuesPastAFailureAndReportsIt(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->followService->method('followAccount')
			->willReturnCallback(static function (Person $actor, string $account): bool {
				if ($account === 'dave@other.example') {
					throw new RuntimeException('instance unreachable');
				}

				return true;
			});

		$result = $this->service->importFollows('alice', self::MASTODON_CSV);

		$this->assertSame(2, $result['followed']);
		$this->assertSame(['dave@other.example' => 'instance unreachable'], $result['failed']);
	}

	public function testImportFollowsSkipsTheImportingAccountItself(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->followService->method('followAccount')
			->willReturnCallback(static function (Person $actor, string $account): bool {
				if ($account === 'bob@cloud.example') {
					throw new FollowSameAccountException();
				}

				return true;
			});

		$result = $this->service->importFollows(
			'alice',
			"Account address\nalice@cloud.example\nbob@cloud.example\ncarol@remote.example\n"
		);

		$this->assertSame(1, $result['followed']);
		$this->assertSame(2, $result['skipped'], 'our own handle, and the one the follow path says is ours');
		$this->assertSame([], $result['failed']);
	}

	public function testImportFollowsWithAnEmptyFileDoesNothing(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->followService->expects($this->never())->method('followAccount');

		$this->assertSame(
			['followed' => 0, 'skipped' => 0, 'failed' => []],
			$this->service->importFollows('alice', "Account address,Show boosts,Notify on new posts,Languages\n")
		);
	}

	public function testExportFollowsCsvWritesTheHeaderMastodonWrites(): void {
		$csv = MigrationService::exportFollowsCsv(['carol@remote.example', 'dave@other.example']);

		$this->assertSame(
			"Account address,Show boosts,Notify on new posts,Languages\n"
			. "carol@remote.example,true,false,\n"
			. "dave@other.example,true,false,\n",
			$csv
		);
	}

	public function testExportFollowsCsvWithNoHandlesIsTheHeaderAlone(): void {
		$this->assertSame(
			"Account address,Show boosts,Notify on new posts,Languages\n",
			MigrationService::exportFollowsCsv([])
		);
	}

	/**
	 * The writer exists so that what leaves here can come back — into this app,
	 * or into Mastodon's "Import follows", which is the same file.
	 */
	public function testExportFollowsCsvRoundTripsThroughTheReader(): void {
		$handles = ['carol@remote.example', 'dave@other.example', 'erin@third.example'];

		$this->assertSame(
			$handles,
			MigrationService::parseFollowsCsv(MigrationService::exportFollowsCsv($handles))
		);
	}

	// --- blocks, mutes and lists -----------------------------------------

	private function relation(string $objectId, string $type, bool $notifications = true): ActorRelation {
		$relation = new ActorRelation();
		$relation->setObjectId($objectId)->setType($type)->setNotifications($notifications);

		return $relation;
	}

	private function mastodonList(int $id, string $title, string $groupId = ''): MastodonList {
		$list = new MastodonList();
		$list->setId($id)->setOwnerId(self::ALICE)->setTitle($title)->setGroupId($groupId);

		return $list;
	}

	public function testImportBlocksBlocksEveryAccountInTheFile(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$carol = $this->person(self::CAROL, 'carol@remote.example');
		$dave = $this->person('https://other.example/users/dave', 'dave@other.example');
		$this->cacheActorService->method('getFromAccount')
			->willReturnCallback(static fn (string $account): Person => $account === 'carol@remote.example' ? $carol : $dave);

		$blocked = [];
		$this->relationshipService->method('block')
			->willReturnCallback(function (Person $actor, Person $target) use (&$blocked): void {
				$blocked[] = $target->getAccount();
			});

		$result = $this->service->importBlocks('alice', "carol@remote.example\ndave@other.example\n");

		$this->assertSame(['carol@remote.example', 'dave@other.example'], $blocked);
		$this->assertSame(2, $result['blocked']);
		$this->assertSame([], $result['failed']);
	}

	/**
	 * An export is the one file its author cannot fix: half a block list is
	 * better than none of it, and the handle that did not resolve is named
	 * rather than dropped — a block that silently did not happen is the
	 * failure that matters here.
	 */
	public function testImportBlocksReportsTheOnesItCouldNotResolve(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->cacheActorService->method('getFromAccount')
			->willReturnCallback(function (string $account): Person {
				if ($account === 'dave@other.example') {
					throw new RuntimeException('instance unreachable');
				}

				return $this->person(self::CAROL, $account);
			});

		$result = $this->service->importBlocks('alice', "carol@remote.example\ndave@other.example\n");

		$this->assertSame(1, $result['blocked']);
		$this->assertSame(['dave@other.example' => 'instance unreachable'], $result['failed']);
	}

	public function testImportBlocksSkipsTheImportingAccountItself(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->relationshipService->expects($this->never())->method('block');

		$result = $this->service->importBlocks('alice', "alice@cloud.example\n");

		$this->assertSame(0, $result['blocked']);
		$this->assertSame(1, $result['skipped']);
	}

	/**
	 * The column says whether notifications are *hidden*; the relation stores
	 * whether they are shown. Reading it the wrong way round would turn every
	 * ordinary mute into a silent one.
	 */
	public function testImportMutesReadsTheHideNotificationsColumn(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->cacheActorService->method('getFromAccount')
			->willReturnCallback(fn (string $account): Person => $this->person(
				'https://remote.example/users/' . explode('@', $account)[0], $account
			));

		$notifications = [];
		$this->relationshipService->method('mute')
			->willReturnCallback(function (Person $actor, Person $target, bool $shown) use (&$notifications): void {
				$notifications[$target->getAccount()] = $shown;
			});

		$result = $this->service->importMutes(
			'alice',
			"Account address,Hide notifications\ncarol@remote.example,true\ndave@remote.example,false\n"
		);

		$this->assertSame(2, $result['muted']);
		$this->assertSame(
			['carol@remote.example' => false, 'dave@remote.example' => true],
			$notifications
		);
	}

	public function testParseListsCsvGroupsTheHandlesUnderTheirList(): void {
		$this->assertSame(
			[
				'Friends' => ['carol@remote.example', 'dave@other.example'],
				'Work' => ['erin@third.example'],
			],
			MigrationService::parseListsCsv(
				"Friends,carol@remote.example\nWork,erin@third.example\nFriends,@dave@other.example\n"
			)
		);
	}

	public function testParseListsCsvKeepsOneListPerTitleAndOneRowPerHandle(): void {
		$this->assertSame(
			['Friends' => ['carol@remote.example']],
			MigrationService::parseListsCsv(
				"Friends,carol@remote.example\nfriends,CAROL@remote.example\nFriends,not-a-handle\n"
			)
		);
	}

	public function testParseListsCsvSkipsAHeaderWhereThereIsOne(): void {
		$this->assertSame(
			['Friends' => ['carol@remote.example']],
			MigrationService::parseListsCsv("List name,Account address\nFriends,carol@remote.example\n")
		);
	}

	public function testImportListsMakesTheListAndFillsItWithWhoIsFollowed(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->listsRequest->method('getByActor')->willReturn([]);
		$this->listsRequest->method('create')
			->willReturnCallback(static function (MastodonList $list): MastodonList {
				return $list->setId(7);
			});
		$carol = $this->person(self::CAROL, 'carol@remote.example');
		$this->cacheActorService->method('getFromAccount')->willReturn($carol);
		$this->followsRequest->method('getByPersons')->willReturn($this->follow(self::CAROL));

		$this->listsRequest->expects($this->once())->method('addMember')
			->with($this->callback(static fn (MastodonList $list): bool => $list->getTitle() === 'Friends'), self::CAROL);

		$result = $this->service->importLists('alice', "Friends,carol@remote.example\n");

		$this->assertSame(['lists' => 1, 'added' => 1, 'skipped' => 0, 'failed' => []], $result);
	}

	/**
	 * A list here holds accounts this one follows, as Mastodon's do. Following
	 * them from a button that says "lists" would federate a request nobody
	 * asked for, so the row is counted as skipped instead.
	 */
	public function testImportListsSkipsAnAccountThatIsNotFollowed(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->listsRequest->method('getByActor')->willReturn([]);
		$this->listsRequest->method('create')
			->willReturnCallback(static fn (MastodonList $list): MastodonList => $list->setId(7));
		$this->cacheActorService->method('getFromAccount')
			->willReturn($this->person(self::CAROL, 'carol@remote.example'));
		$this->followsRequest->method('getByPersons')
			->willThrowException(new FollowNotFoundException());

		$this->listsRequest->expects($this->never())->method('addMember');

		$result = $this->service->importLists('alice', "Friends,carol@remote.example\n");

		$this->assertSame(1, $result['skipped']);
		$this->assertSame(0, $result['added']);
	}

	public function testImportListsFillsAListItAlreadyHasRatherThanMakingASecond(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->listsRequest->method('getByActor')->willReturn([$this->mastodonList(3, 'Friends')]);
		$this->listsRequest->expects($this->never())->method('create');
		$this->cacheActorService->method('getFromAccount')
			->willReturn($this->person(self::CAROL, 'carol@remote.example'));
		$this->followsRequest->method('getByPersons')->willReturn($this->follow(self::CAROL));

		$this->listsRequest->expects($this->once())->method('addMember')
			->with($this->callback(static fn (MastodonList $list): bool => $list->getId() === 3), self::CAROL);

		$result = $this->service->importLists('alice', "friends,carol@remote.example\n");

		$this->assertSame(0, $result['lists']);
		$this->assertSame(1, $result['added']);
	}

	/** A group list's members are the group's; the next reconcile would undo it. */
	public function testImportListsLeavesAGroupListAlone(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->listsRequest->method('getByActor')->willReturn([$this->mastodonList(3, 'Design', 'design')]);
		$this->listsRequest->expects($this->never())->method('addMember');

		$result = $this->service->importLists('alice', "Design,carol@remote.example\n");

		$this->assertSame(1, $result['skipped']);
		$this->assertSame(0, $result['added']);
	}

	// --- the CSV exports --------------------------------------------------

	public function testExportCsvWritesTheFollowsMastodonReads(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$follow = $this->follow(self::CAROL);
		$follow->setActor($this->person(self::CAROL, 'carol@remote.example'));
		$this->followsRequest->method('getFollowingByActorId')
			->willReturnCallback(static fn (string $id, int $limit, int $offset): array => $offset === 0 ? [$follow] : []);

		[$name, $csv] = $this->service->exportCsv('alice', 'following');

		$this->assertSame('following_accounts.csv', $name);
		$this->assertSame(
			"Account address,Show boosts,Notify on new posts,Languages\ncarol@remote.example,true,false,\n",
			$csv
		);
	}

	/**
	 * Every local actor holds a loopback follow of itself, so both lists named
	 * the exporter — and a `following_accounts.csv` naming you is a row
	 * Mastodon's importer tries to follow you with. Found on devel, where the
	 * export of a demo account listed the demo account.
	 */
	public function testExportCsvLeavesTheAccountOutOfItsOwnFollows(): void {
		$alice = $this->alice();
		$this->accountService->method('getActorFromUserId')->willReturn($alice);
		$loopback = $this->follow(self::ALICE);
		$loopback->setActor($this->person(self::ALICE, 'alice@cloud.example', true));
		$carol = $this->follow(self::CAROL);
		$carol->setActor($this->person(self::CAROL, 'carol@remote.example'));
		$this->followsRequest->method('getFollowingByActorId')
			->willReturnCallback(static fn (string $id, int $limit, int $offset): array => $offset === 0 ? [$loopback, $carol] : []);

		[, $csv] = $this->service->exportCsv('alice', 'following');

		$this->assertSame(['carol@remote.example'], MigrationService::parseFollowsCsv($csv));
	}

	public function testExportCsvWritesTheBlocksAsABareList(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->actorRelationRequest->method('getByActor')
			->willReturn([$this->relation(self::CAROL, ActorRelation::TYPE_BLOCK)]);
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::CAROL, 'carol@remote.example'));

		[$name, $csv] = $this->service->exportCsv('alice', 'blocks');

		$this->assertSame('blocked_accounts.csv', $name);
		$this->assertSame("carol@remote.example\n", $csv);
	}

	public function testExportCsvWritesWhetherAMutesNotificationsAreHidden(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->actorRelationRequest->method('getByActor')->willReturn([
			$this->relation(self::CAROL, ActorRelation::TYPE_MUTE, false),
			$this->relation('https://other.example/users/dave', ActorRelation::TYPE_MUTE, true),
		]);
		$this->cacheActorService->method('getFromId')
			->willReturnCallback(fn (string $id): Person => $this->person(
				$id, $id === self::CAROL ? 'carol@remote.example' : 'dave@other.example'
			));

		[$name, $csv] = $this->service->exportCsv('alice', 'mutes');

		$this->assertSame('muted_accounts.csv', $name);
		$this->assertSame(
			"Account address,Hide notifications\ncarol@remote.example,true\ndave@other.example,false\n",
			$csv
		);
	}

	/** What leaves has to be able to come back: the reader is the same one. */
	public function testExportCsvWritesListsTheImporterReadsBack(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->listsRequest->method('getByActor')->willReturn([$this->mastodonList(3, 'Friends')]);
		$this->listsRequest->method('getMemberIds')->willReturn([self::CAROL]);
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::CAROL, 'carol@remote.example'));

		[$name, $csv] = $this->service->exportCsv('alice', 'lists');

		$this->assertSame('lists.csv', $name);
		$this->assertSame("Friends,carol@remote.example\n", $csv);
		$this->assertSame(['Friends' => ['carol@remote.example']], MigrationService::parseListsCsv($csv));
	}

	public function testExportCsvRefusesAKindThisAccountKeepsNoListOf(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());

		$this->expectException(InvalidResourceException::class);

		$this->service->exportCsv('alice', 'secrets');
	}
}
