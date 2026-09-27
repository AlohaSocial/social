<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\ModerationController;
use OCA\Social\Db\DiscoverCategoriesRequest;
use OCA\Social\Db\MediaBlocksRequest;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\AdminAccount;
use OCA\Social\Model\Report;
use OCA\Social\Model\Strike;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AdminApiService;
use OCA\Social\Service\BlocklistImportService;
use OCA\Social\Service\BlocklistSubscriptionService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\PostReviewService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\TrendReviewService;
use OCA\Social\Settings\AdminSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ModerationControllerTest extends TestCase {
	private ReportService|MockObject $reportService;
	private FediverseService|MockObject $fediverseService;
	private ConfigService|MockObject $configService;
	private ModerationService|MockObject $moderationService;
	private AdminApiService|Stub $adminApiService;
	private PostReviewService|Stub $postReviewService;
	private MediaBlocksRequest|MockObject $mediaBlocksRequest;
	private DiscoverCategoriesRequest|MockObject $discoverCategoriesRequest;
	private TrendReviewService|Stub $trendReviewService;
	private HashtagService|Stub $hashtagService;
	private EmojiService|Stub $emojiService;
	private \OCP\IUserSession|Stub $userSession;
	private BlocklistImportService|Stub $blocklistImportService;
	private BlocklistSubscriptionService|Stub $blocklistSubscriptionService;
	private AccountService|Stub $accountService;
	private ModerationController $controller;

	/** The arguments the account page was asked for. */
	private array $accountQuery = [];
	/** @var AdminAccount[] what the account page answers */
	private array $accounts = [];
	/** @var array<string, int> how many strikes each account has */
	private array $strikeCounts = [];
	/** @var string[] the accounts the counts were asked for */
	private array $countedFor = [];

	protected function setUp(): void {
		$this->reportService = $this->createMock(ReportService::class);
		$this->fediverseService = $this->createMock(FediverseService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->moderationService = $this->createMock(ModerationService::class);
		$this->adminApiService = $this->createStub(AdminApiService::class);
		$this->postReviewService = $this->createStub(PostReviewService::class);
		$this->mediaBlocksRequest = $this->createMock(MediaBlocksRequest::class);
		$this->discoverCategoriesRequest = $this->createMock(DiscoverCategoriesRequest::class);
		$user = $this->createStub(\OCP\IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createStub(\OCP\IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->accountService = $this->createStub(AccountService::class);
		$this->trendReviewService = $this->createStub(TrendReviewService::class);
		$this->hashtagService = $this->createStub(HashtagService::class);
		$this->emojiService = $this->createStub(EmojiService::class);

		$this->blocklistImportService = $this->createStub(BlocklistImportService::class);
		$this->blocklistSubscriptionService = $this->createStub(BlocklistSubscriptionService::class);
		$this->controller = new ModerationController(
			$this->createStub(IRequest::class),
			$this->reportService,
			$this->fediverseService,
			$this->configService,
			$this->moderationService,
			$this->adminApiService,
			$this->postReviewService,
			$this->accountService,
			$this->mediaBlocksRequest,
			$this->discoverCategoriesRequest,
			$this->trendReviewService,
			$this->hashtagService,
			$this->emojiService,
			$this->userSession,
			$this->blocklistImportService,
			$this->blocklistSubscriptionService
		);

		$this->adminApiService->method('accountPage')->willReturnCallback(
			function (
				?bool $local, string $username, string $displayName, string $domain,
				string $status, int $limit, int $maxId,
			): array {
				$this->accountQuery = compact(
					'local', 'username', 'displayName', 'domain', 'status', 'limit', 'maxId'
				);

				return ['accounts' => $this->accounts, 'cursors' => [9, 7]];
			}
		);

		$this->moderationService->method('strikeCounts')->willReturnCallback(
			function (array $actorIds): array {
				$this->countedFor = $actorIds;

				return $this->strikeCounts;
			}
		);
	}

	public function testModerationRoutesRequireASessionAndCsrf(): void {
		// no PublicPage/NoAdminRequired/NoCSRFRequired — neither as attribute
		// nor as legacy annotation: the server only dispatches these routes
		// for a logged-in session with a CSRF token
		$reflection = new \ReflectionClass(ModerationController::class);
		$doc = (string)$reflection->getDocComment();
		$attributes = [];
		foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			$doc .= (string)$method->getDocComment();
			foreach ($method->getAttributes() as $attribute) {
				$attributes[] = $attribute->getName();
			}
		}

		foreach (['PublicPage', 'NoAdminRequired', 'NoCSRFRequired'] as $relaxation) {
			$this->assertStringNotContainsString('@' . $relaxation, $doc);
			foreach ($attributes as $attribute) {
				$this->assertStringNotContainsString($relaxation, $attribute);
			}
		}
	}

	/**
	 * Without the attribute a method here is admin-only, which is safe and
	 * wrong: the page around it opens for a delegated moderator and the
	 * buttons on it would answer 403.
	 */
	public function testEveryModerationActionIsOpenToADelegatedModerator(): void {
		$reflection = new \ReflectionClass(ModerationController::class);

		foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->isConstructor() || $method->getDeclaringClass()->getName() !== ModerationController::class) {
				continue;
			}

			$attributes = $method->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(
				1, $attributes, $method->getName() . '() carries no AuthorizedAdminSetting'
			);
			$this->assertSame(
				['settings' => AdminSettings::class],
				$attributes[0]->getArguments(),
				$method->getName() . '() is delegated through the wrong settings class'
			);
		}
	}

	public function testReportResolveMarksTheReport(): void {
		$report = (new Report())->setId(7)->setResolved(true);
		$this->reportService->expects($this->once())
			->method('setResolved')->with(7, true)->willReturn($report);

		$response = $this->controller->reportResolve(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($report, $response->getData());
	}

	public function testReportResolveCanReopen(): void {
		$this->reportService->expects($this->once())
			->method('setResolved')->with(7, false)->willReturn(new Report());

		$this->controller->reportResolve(7, false);
	}

	public function testReportResolveOfAnUnknownReportIsNotFound(): void {
		$this->reportService->method('setResolved')
			->willThrowException(new ReportNotFoundException());

		$response = $this->controller->reportResolve(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testFediverseAddNormalisesAndReturnsTheList(): void {
		$this->fediverseService->expects($this->once())->method('addAddress')->with('evil.example');
		$this->fediverseService->method('getListedAddresses')->willReturn(['evil.example']);

		$response = $this->controller->fediverseAdd('  EVIL.example ');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['list' => ['evil.example']], $response->getData());
	}

	public function testFediverseAddRefusesAnInvalidAddress(): void {
		$this->fediverseService->expects($this->never())->method('addAddress');

		$this->assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$this->controller->fediverseAdd('not a hostname!')->getStatus()
		);
		$this->assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$this->controller->fediverseAdd('')->getStatus()
		);
	}

	public function testFediverseRemoveReturnsTheRemainingList(): void {
		$this->fediverseService->expects($this->once())->method('removeAddress')->with('evil.example');
		$this->fediverseService->method('getListedAddresses')->willReturn([]);

		$response = $this->controller->fediverseRemove('evil.example');

		$this->assertSame(['list' => []], $response->getData());
	}

	public function testRetentionStoresTheConfiguredPeriod(): void {
		$this->configService->expects($this->once())
			->method('setAppValue')->with(ConfigService::SOCIAL_RETENTION_DAYS, '90');

		$response = $this->controller->retention(90);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['retentionDays' => 90], $response->getData());
	}

	public function testRetentionRefusesAnInvalidPeriod(): void {
		$this->configService->expects($this->never())->method('setAppValue');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller->retention(-1)->getStatus());
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller->retention(99999)->getStatus());
	}

	public function testFediverseAccessRejectsAnUnknownType(): void {
		$this->fediverseService->method('setAccessType')
			->willThrowException(new \Exception('invalid type'));

		$this->assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$this->controller->fediverseAccess('everything')->getStatus()
		);
	}

	public function testFediverseAccessSwitchesTheMode(): void {
		$this->fediverseService->expects($this->once())->method('setAccessType')->with('none_but');
		$this->fediverseService->method('getAccessType')->willReturn('none_but');

		$response = $this->controller->fediverseAccess('none_but');

		$this->assertSame(['accessType' => 'none_but'], $response->getData());
	}

	private function remotePerson(string $id, string $handle): Person {
		$person = new Person();
		$person->setId($id)->setPreferredUsername(explode('@', $handle)[0]);
		$person->setAccount($handle)->setLocal(false);

		return $person;
	}

	/**
	 * Only a *reported* account could be acted on from the web: an instance
	 * with a problem nobody had filed a report about needed a moderation
	 * client and a token.
	 */
	public function testTheAccountBrowserAnswersWhatTheTableDraws(): void {
		$this->accounts = [
			AdminAccount::fromPerson(
				$this->remotePerson('https://remote.example/users/bob', 'bob@remote.example'), 'silence'
			),
		];

		$data = $this->controller->accounts()->getData();

		$this->assertSame([[
			'actor_id' => 'https://remote.example/users/bob',
			'handle' => 'bob@remote.example',
			'username' => 'bob',
			'domain' => 'remote.example',
			'local' => false,
			'level' => 'silence',
			'strikes' => 0,
		]], $data['accounts']);
		$this->assertSame([9, 7], $data['cursors'], 'the cursors page the browser');
	}

	#[DataProvider('provideWhatAModeratorWouldType')]
	public function testWhatWasTypedIsReadAsBothHalves(
		string $query, string $username, string $domain,
	): void {
		$this->controller->accounts($query);

		$this->assertSame($username, $this->accountQuery['username'], $query . ' names this account');
		$this->assertSame($domain, $this->accountQuery['domain'], $query . ' names this instance');
	}

	public static function provideWhatAModeratorWouldType(): iterable {
		yield 'a handle' => ['bob@remote.example', 'bob', 'remote.example'];
		yield 'a handle with the leading at' => ['@bob@remote.example', 'bob', 'remote.example'];
		yield 'an instance' => ['remote.example', '', 'remote.example'];
		yield 'a username anywhere' => ['bob', 'bob', ''];
		yield 'nothing at all' => ['   ', '', ''];
		yield 'mixed case' => ['Bob@Remote.Example', 'Bob', 'remote.example'];
	}

	#[DataProvider('provideOrigins')]
	public function testTheOriginNarrowsToOneSideOfTheFederation(string $origin, ?bool $local): void {
		$this->controller->accounts('', $origin);

		$this->assertSame($local, $this->accountQuery['local']);
	}

	public static function provideOrigins(): iterable {
		yield 'this instance' => ['local', true];
		yield 'the rest' => ['remote', false];
		yield 'both' => ['', null];
		yield 'nonsense is both, not nothing' => ['elsewhere', null];
	}

	public function testTheStateFilterIsPassedThroughAsItIs(): void {
		$this->controller->accounts('', '', 'suspended');

		$this->assertSame('suspended', $this->accountQuery['status']);
	}

	/** A page the browser can draw, and a cursor it can go on from. */
	public function testThePageIsBoundedAndCanBeContinued(): void {
		$this->controller->accounts('', '', '', 7);

		$this->assertSame(40, $this->accountQuery['limit']);
		$this->assertSame(7, $this->accountQuery['maxId']);
	}

	/**
	 * A suspension deletes the cached actor, so the accounts a moderator most
	 * needs to find are the ones with no handle left to show.
	 */
	public function testAnAccountWithNothingLeftOfItStillNamesItself(): void {
		$person = new Person();
		$person->setId('https://gone.example/users/carol')->setPreferredUsername('carol');
		$person->setLocal(false);
		$this->accounts = [AdminAccount::fromPerson($person, 'suspend')];

		$account = $this->controller->accounts()->getData()['accounts'][0];

		$this->assertSame('', $account['handle']);
		$this->assertSame('carol', $account['username']);
		$this->assertSame('https://gone.example/users/carol', $account['actor_id']);
	}

	/**
	 * A page of forty accounts asked for forty-one queries when the count was
	 * read a row at a time, and the column is only a number.
	 */
	public function testTheStrikeCountsForAPageAreAskedForOnce(): void {
		$this->accounts = [
			AdminAccount::fromPerson(
				$this->remotePerson('https://remote.example/users/bob', 'bob@remote.example')
			),
			AdminAccount::fromPerson(
				$this->remotePerson('https://remote.example/users/carol', 'carol@remote.example')
			),
		];
		$this->strikeCounts = ['https://remote.example/users/bob' => 3];

		$accounts = $this->controller->accounts()->getData()['accounts'];

		$this->assertSame([
			'https://remote.example/users/bob', 'https://remote.example/users/carol',
		], $this->countedFor);
		$this->assertSame(3, $accounts[0]['strikes']);
		$this->assertSame(0, $accounts[1]['strikes'], 'an account with no history has none');
	}

	/**
	 * `social_moderation` holds what stands now and is deleted by a lift, so
	 * without this the third silence in a month looked exactly like the first.
	 */
	public function testTheHistoryIsWhatWasDecidedBeforeNow(): void {
		$this->moderationService->expects($this->once())
			->method('history')->with('https://remote.example/users/bob')
			->willReturn([
				new Strike('https://remote.example/users/bob', 'silence', 'spam', 'mod', 4, 1757548800),
			]);

		$data = $this->controller->accountHistory('https://remote.example/users/bob')->getData();

		$this->assertSame([[
			'action' => 'silence',
			'text' => 'spam',
			'moderator' => 'mod',
			'report_id' => 4,
			'creation' => 1757548800,
		]], $data['strikes']);
	}

	public function testAHistoryOfNobodyIsRefused(): void {
		$this->moderationService->expects($this->never())->method('history');

		$response = $this->controller->accountHistory('  ');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	/** A report the page renders itself and one it fetches must read alike. */
	public function testAPageOfReportsCarriesWhatTheTableDraws(): void {
		$target = new Person();
		$target->setId('https://spam.example/users/spammer');
		$target->setAccount('spammer@spam.example');

		$report = new Report();
		$report->setId(7);
		$report->setTargetAccount($target);
		$report->setAccountId('https://spam.example/users/spammer');
		$report->setActorId('https://cloud.example/users/alice');
		$report->setCategory('spam');
		$report->setComment('endless crypto');
		$report->setStatusIds(['https://spam.example/notes/1']);

		$this->reportService->expects($this->once())->method('page')->with(false, 2)
			->willReturn(['reports' => [$report], 'total' => 137, 'page' => 2, 'perPage' => 50]);
		$this->moderationService->method('decisions')->willReturn([
			new \OCA\Social\Model\Moderation('https://spam.example/users/spammer', 'suspend'),
		]);

		$data = $this->controller->reports(false, 2)->getData();

		$this->assertSame(137, $data['total']);
		$this->assertSame(2, $data['page']);
		$this->assertSame(50, $data['perPage']);
		$this->assertSame([
			'id' => 7,
			'account_id' => 'https://spam.example/users/spammer',
			'account' => 'spammer@spam.example',
			'reporter' => 'https://cloud.example/users/alice',
			'local' => true,
			'category' => 'spam',
			'comment' => 'endless crypto',
			'status_ids' => ['https://spam.example/notes/1'],
			'creation' => 0,
			'resolved' => false,
			// what stands against the account *now*, so a report from last
			// month says whether the account it named is still suspended
			'level' => 'suspend',
		], $data['reports'][0]);
	}

	public function testAReportWhoseAccountIsNoLongerCachedStillNamesIt(): void {
		$report = new Report();
		$report->setId(8);
		$report->setAccountId('https://gone.example/users/x');
		$this->reportService->method('page')
			->willReturn(['reports' => [$report], 'total' => 1, 'page' => 1, 'perPage' => 50]);
		$this->moderationService->method('decisions')->willReturn([]);

		$row = $this->controller->reports()->getData()['reports'][0];

		$this->assertSame('https://gone.example/users/x', $row['account_id']);
		$this->assertSame('', $row['account']);
		$this->assertSame('', $row['level']);
	}

	public function testTheResolvedOnesAreAskedForSeparately(): void {
		$this->reportService->expects($this->once())->method('page')->with(true, 1)
			->willReturn(['reports' => [], 'total' => 0, 'page' => 1, 'perPage' => 50]);
		$this->moderationService->method('decisions')->willReturn([]);

		$this->controller->reports(true);
	}

	/** Lifting used to drop the comment on the floor; the history keeps it now. */
	public function testLiftingPassesOnWhyItWasLifted(): void {
		$this->moderationService->expects($this->once())
			->method('lift')->with('https://spam.example/users/spammer', 'appealed');

		$this->controller->accountModerate('https://spam.example/users/spammer', '', 'appealed');
	}

	// media blocklist and forced sensitivity

	/**
	 * Every other tool here acts on an account, and none of them stops a file
	 * coming back — the account is suspended and the picture is posted again
	 * by the next one.
	 */
	public function testAPictureIsBlockedByItsHash(): void {
		$hash = str_repeat('a1', 32);
		$this->mediaBlocksRequest->expects($this->once())->method('block')
			->with($hash, 'the same image for the third time', 'alice');
		$this->mediaBlocksRequest->method('getPage')->willReturn([]);

		$this->assertSame(
			Http::STATUS_OK,
			$this->controller->mediaBlockAdd($hash, 'the same image for the third time')->getStatus()
		);
	}

	/** @return array<int, array<string, mixed>> rows with ids $from down to $to */
	private function mediaBlockRows(int $from, int $to): array {
		$rows = [];
		for ($id = $from; $id >= $to; $id--) {
			$rows[] = [
				'id' => $id, 'hash' => hash('sha256', (string)$id), 'reason' => '',
				'moderator' => 'alice', 'blocked' => 0, 'creation' => '2026-09-15 10:00:00',
			];
		}

		return $rows;
	}

	/**
	 * A refused file is enforced whether anybody can see it or not, so the
	 * list cannot simply stop: a full page says where the next one starts,
	 * and how many there are in all.
	 */
	public function testAFullPageOfRefusedFilesSaysWhereTheNextOneStarts(): void {
		$this->mediaBlocksRequest->expects($this->once())->method('getPage')
			->with(101, 0)->willReturn($this->mediaBlockRows(600, 500));
		$this->mediaBlocksRequest->method('count')->willReturn(600);

		$data = $this->controller->mediaBlocks()->getData();

		$this->assertCount(100, $data['blocks']);
		$this->assertSame(600, $data['blocks'][0]['id']);
		$this->assertSame(501, $data['next']);
		$this->assertSame(600, $data['total']);
	}

	public function testALaterPageIsReadFromTheCursorAndTheLastOneSaysSo(): void {
		$this->mediaBlocksRequest->expects($this->once())->method('getPage')
			->with(101, 101)->willReturn($this->mediaBlockRows(100, 1));
		$this->mediaBlocksRequest->method('count')->willReturn(200);

		$data = $this->controller->mediaBlocks(101)->getData();

		$this->assertCount(100, $data['blocks']);
		$this->assertNull($data['next']);
	}

	public function testAShortListIsOnePage(): void {
		$this->mediaBlocksRequest->method('getPage')->willReturn($this->mediaBlockRows(2, 1));
		$this->mediaBlocksRequest->method('count')->willReturn(2);

		$this->assertSame(
			['blocks' => $this->mediaBlockRows(2, 1), 'next' => null, 'total' => 2],
			$this->controller->mediaBlocks()->getData()
		);
	}

	public function testAFileIsAllowedAgainByItsHashWhicheverPageItWasOn(): void {
		$hash = str_repeat('b2', 32);
		$this->mediaBlocksRequest->expects($this->once())->method('unblock')->with($hash);
		$this->mediaBlocksRequest->method('getPage')->willReturn([]);
		$this->mediaBlocksRequest->method('count')->willReturn(0);

		$data = $this->controller->mediaBlockRemove(' ' . strtoupper($hash) . ' ')->getData();

		$this->assertSame(['blocks' => [], 'next' => null, 'total' => 0], $data);
	}

	/** A row that can never match a file is a row nobody can explain later. */
	public function testSomethingThatIsNotAHashIsRefused(): void {
		$this->mediaBlocksRequest->expects($this->never())->method('block');

		$response = $this->controller->mediaBlockAdd('not-a-hash');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testForcingSensitiveIsRecordedAgainstTheAccount(): void {
		$this->moderationService->expects($this->once())->method('forceSensitive')
			->with('https://remote.example/users/x', true);

		$response = $this->controller->accountForceSensitive('https://remote.example/users/x');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['sensitive']);
	}

	public function testForcingSensitiveNeedsAnAccount(): void {
		$this->moderationService->expects($this->never())->method('forceSensitive');

		$this->assertSame(
			Http::STATUS_BAD_REQUEST, $this->controller->accountForceSensitive('  ')->getStatus()
		);
	}

	// the curated part of Explore

	/**
	 * Hashtags are written the way people write them — spaces or commas, with
	 * or without the hash — because that is what an administrator will paste.
	 */
	public function testTheHashtagsAreReadTheWayPeopleWriteThem(): void {
		$this->discoverCategoriesRequest->method('count')->willReturn(0);
		$this->discoverCategoriesRequest->method('getAll')->willReturn([]);
		$this->discoverCategoriesRequest->expects($this->once())->method('create')
			->with('Architecture', ['brutalism', 'concrete', 'stairwells'], 0);

		$response = $this->controller->discoverCategoryAdd(
			'Architecture', '#brutalism, concrete   #stairwells'
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/** A subject with no hashtags means nothing and is refused. */
	public function testASubjectWithNoHashtagsIsRefused(): void {
		$this->discoverCategoriesRequest->method('count')->willReturn(0);
		$this->discoverCategoriesRequest->expects($this->never())->method('create');

		$this->assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$this->controller->discoverCategoryAdd('Architecture', '  ')->getStatus()
		);
	}

	public function testASubjectNeedsAName(): void {
		$this->discoverCategoriesRequest->expects($this->never())->method('create');

		$this->assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$this->controller->discoverCategoryAdd(' ', '#concrete')->getStatus()
		);
	}

	/** Past the ceiling it is not a shelf, it is a list. */
	public function testThereIsACeilingOnHowManySubjects(): void {
		$this->discoverCategoriesRequest->method('count')
			->willReturn(DiscoverCategoriesRequest::MAX_CATEGORIES);
		$this->discoverCategoriesRequest->expects($this->never())->method('create');

		$this->assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$this->controller->discoverCategoryAdd('One more', '#more')->getStatus()
		);
	}
}
