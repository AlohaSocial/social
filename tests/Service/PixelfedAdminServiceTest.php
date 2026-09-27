<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use InvalidArgumentException;
use OCA\Social\Db\InstanceStatsRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\AdminAccount;
use OCA\Social\Model\Client\AdminDomainBlock;
use OCA\Social\Model\HeldPost;
use OCA\Social\Model\Instance;
use OCA\Social\Model\Moderation;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AdminApiService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\PixelfedAdminService;
use OCA\Social\Service\PostReviewService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PixelfedAdminServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';
	private const BOB = 'https://cloud.example/apps/social/@bob';

	private AdminApiService|MockObject $adminApiService;
	private FediverseService|MockObject $fediverseService;
	private InstanceStatsRequest|Stub $instanceStatsRequest;
	private PostReviewService|MockObject $postReviewService;
	private ModerationService|MockObject $moderationService;
	private AccountService|Stub $accountService;
	private PixelfedAdminService $service;
	private array $accessList = [];
	private array $silencedList = [];
	private string $accessType = 'all_but';

	protected function setUp(): void {
		parent::setUp();

		$this->adminApiService = $this->createMock(AdminApiService::class);
		$this->adminApiService->method('isAdministrator')
			->willReturnCallback(static fn (string $userId): bool => $userId === 'alice');

		$this->fediverseService = $this->createMock(FediverseService::class);
		$this->fediverseService->method('getAccessType')->willReturnCallback(fn (): string => $this->accessType);
		$this->fediverseService->method('getListedAddresses')->willReturnCallback(fn (): array => $this->accessList);
		$this->fediverseService->method('isListed')
			->willReturnCallback(fn (string $host): bool => in_array($host, $this->accessList, true));
		$this->fediverseService->method('isSilenced')
			->willReturnCallback(fn (string $host): bool => in_array($host, $this->silencedList, true));
		$this->fediverseService->method('silenceAddress')
			->willReturnCallback(function (string $host): void {
				$this->silencedList[] = $host;
			});
		$this->fediverseService->method('addAddress')
			->willReturnCallback(function (string $host): void {
				$this->accessList[] = $host;
			});

		$instance = (new Instance())->setStats(['user_count' => 36, 'status_count' => 692, 'domain_count' => 3]);
		$instanceService = $this->createStub(InstanceService::class);
		$instanceService->method('getLocal')->willReturn($instance);

		$this->instanceStatsRequest = $this->createStub(InstanceStatsRequest::class);
		$this->instanceStatsRequest->method('remoteHostCounts')
			->willReturn(['big.example' => 40, 'loud.example' => 3, 'evil.example' => 1]);

		$configService = $this->createStub(ConfigService::class);
		$configService->accessTypeList = ['BLACKLIST' => 'all_but', 'WHITELIST' => 'none_but'];

		$this->postReviewService = $this->createMock(PostReviewService::class);
		$this->moderationService = $this->createMock(ModerationService::class);
		$this->accountService = $this->createStub(AccountService::class);

		$this->service = new PixelfedAdminService(
			$this->adminApiService,
			$this->fediverseService,
			$instanceService,
			$this->instanceStatsRequest,
			$configService,
			$this->postReviewService,
			$this->accountService,
			$this->moderationService,
		);
	}

	private function account(string $id, string $username, int $nid, int $created, string $level = ''): AdminAccount {
		$person = new Person();
		$person->setId($id);
		$person->setNid($nid);
		$person->setPreferredUsername($username);
		$person->setCreation($created);
		$person->setLocal(true);

		return AdminAccount::fromPerson($person, $level);
	}

	public function testTheStatsAreTheInstancesOwnAndTheAutospamCountIsTheReviewQueue(): void {
		$this->postReviewService->method('countPending')->willReturn(4);

		$stats = $this->service->stats();

		$this->assertSame(36, $stats['users_count']);
		$this->assertSame(692, $stats['posts_count']);
		$this->assertSame(3, $stats['instances_count']);
		$this->assertSame(4, $stats['autospam_count']);
	}

	/** The switches describe this instance, and none of them can be flipped from here. */
	public function testTheConfigSwitchesTellTheTruthAndCannotBeFlippedFromTheApp(): void {
		$byKey = array_column($this->service->config(), 'state', 'key');

		$this->assertTrue($byKey['federation.activitypub.enabled']);
		$this->assertFalse($byKey['pixelfed.open_registration']);
		$this->assertFalse($byKey['pixelfed.bouncer.enabled']);

		$this->expectException(InvalidArgumentException::class);
		$this->service->updateConfig('pixelfed.open_registration');
	}

	public function testTheUserBrowserListsLocalAccountsNewestFirstAndSaysWhoIsAnAdministrator(): void {
		$this->adminApiService->method('accountPage')
			->with(true, 'a', '', '', '', PixelfedAdminService::PAGE, 0, 0)
			->willReturn(['accounts' => [
				$this->account(self::ALICE, 'alice', 7, 1_700_000_000),
				$this->account(self::BOB, 'bob', 9, 1_700_005_000, Moderation::SUSPEND),
			], 'cursors' => []]);

		$users = $this->service->users('a', 'desc');

		$this->assertSame(['bob', 'alice'], array_column($users['data'], 'username'));
		$this->assertSame('disabled', $users['data'][0]['status']);
		$this->assertTrue($users['data'][1]['is_admin']);
		$this->assertSame('', $users['data'][1]['email'], 'the address is the Nextcloud account\'s, not a client\'s');
	}

	/** The app's "delete" is this instance's suspension, recorded like any other. */
	public function testDeletingAUserFromTheAppIsASuspensionHere(): void {
		$account = $this->account(self::BOB, 'bob', 9, 1);
		$this->adminApiService->method('account')->with('9')->willReturn($account);
		$this->adminApiService->expects($this->once())->method('act')
			->with($account, AdminApiService::ACTION_SUSPEND, $this->anything());

		$this->assertSame('deleted', $this->service->userAction('9', 'delete')['msg']);
	}

	/**
	 * Two of Pixelfed's three per-account flags are words for things this
	 * instance does have: `unlisted` is the silence tier, and `cw` is marking
	 * everything an account posts sensitive. They were refused with a 422 that
	 * was true of the words and not of the instance.
	 */
	public function testUnlistedIsTheSilenceTier(): void {
		$account = $this->account(self::ALICE, 'alice', 7, 1_700_000_000);
		$this->adminApiService->method('account')->willReturn($account);
		$this->adminApiService->expects($this->once())->method('act')
			->with($account, AdminApiService::ACTION_SILENCE, $this->anything());

		$this->assertSame('unlisted', $this->service->userAction('9', 'unlisted')['msg']);
	}

	public function testCwMarksEverythingTheAccountPostsSensitive(): void {
		$this->adminApiService->method('account')->willReturn($this->account(self::ALICE, 'alice', 7, 1_700_000_000));
		$this->moderationService->expects($this->once())->method('forceSensitive')
			->with(self::ALICE, true);

		$this->assertSame('cw', $this->service->userAction('9', 'cw')['msg']);
	}

	/** The one that is genuinely not a state an account has here. */
	public function testAFlagAnAccountDoesNotHaveHereIsRefusedRatherThanFaked(): void {
		$this->adminApiService->expects($this->never())->method('act');

		$this->expectException(InvalidArgumentException::class);
		$this->service->userAction('9', 'no_autolink');
	}

	public function testIgnoringAReportResolvesItAndTheOtherActionsAreRefused(): void {
		$this->adminApiService->expects($this->once())->method('resolveReport')->with(4, 'alice');

		$this->assertSame(['success' => true], $this->service->handleModReport(4, 'ignore', 'alice'));

		$this->expectException(InvalidArgumentException::class);
		$this->service->handleModReport(4, 'unlist', 'alice');
	}

	/**
	 * Pixelfed's autospam screen draws this instance's own review queue: the
	 * posts held because an account is new or a rule tripped.
	 */
	public function testTheAutospamQueueIsTheReviewQueue(): void {
		$held = (new HeldPost())->setId(7)
			->setActorId('https://cloud.example/@alice')
			->setReason(HeldPost::REASON_FIRST_POST)
			->setParams(['text' => 'hello everybody', 'visibility' => 'public']);
		$this->postReviewService->method('pending')->willReturn([$held]);
		$this->postReviewService->method('reasonText')->willReturn('the first post of a new account');

		$rows = $this->service->autospam()['data'];

		$this->assertCount(1, $rows);
		$this->assertSame('7', $rows[0]['id']);
		$this->assertSame('hello everybody', $rows[0]['content']);
		// a rule, in both forms: Pixelfed shows a score, and a score is not
		// something a moderator can act on
		$this->assertSame(HeldPost::REASON_FIRST_POST, $rows[0]['reason']);
		$this->assertSame('the first post of a new account', $rows[0]['reason_text']);
	}

	public function testApprovingFromTheAppPublishesAndDeletingRefuses(): void {
		$held = (new HeldPost())->setId(7)->setActorId('https://cloud.example/@alice');
		$this->postReviewService->method('heldPost')->with(7)->willReturn($held);
		$author = new Person();
		$author->setId('https://cloud.example/@alice');
		$this->accountService->method('getFromId')->willReturn($author);

		$this->postReviewService->expects($this->once())->method('approve')->with(7, $author);
		$this->assertSame(['success' => true], $this->service->handleAutospam(7, 'approve'));

		$this->postReviewService->expects($this->once())->method('reject')->with(7);
		$this->assertSame(['success' => true], $this->service->handleAutospam(7, 'delete'));

		$this->expectException(InvalidArgumentException::class);
		$this->service->handleAutospam(7, 'cw');
	}

	/** Pixelfed's `unlisted` is this app's silence, its `banned` the deny list. */
	public function testTheInstancesCarryThisInstancesOwnDecisionsInPixelfedsWords(): void {
		$this->accessList = ['evil.example'];
		$this->silencedList = ['loud.example'];

		$rows = $this->service->instances('', 'desc', 'user_count')['data'];

		$this->assertSame(['big.example', 'loud.example', 'evil.example'], array_column($rows, 'domain'));
		$this->assertSame(40, $rows[0]['user_count']);
		$this->assertTrue($rows[1]['unlisted']);
		$this->assertFalse($rows[1]['banned']);
		$this->assertTrue($rows[2]['banned']);
		$this->assertSame(AdminDomainBlock::idOf('evil.example'), $rows[2]['id']);

		$this->assertSame(['loud.example'], array_column($this->service->instances('', 'desc', 'id', 'unlisted')['data'], 'domain'));
		$this->assertSame(['big.example'], array_column($this->service->instances('big')['data'], 'domain'));
	}

	public function testAnInstanceIsFoundByItsIdOrItsDomain(): void {
		$this->assertSame('loud.example', $this->service->instance(AdminDomainBlock::idOf('loud.example'))['data']['domain']);
		$this->assertSame('loud.example', $this->service->instance('loud.example')['data']['domain']);

		$this->expectException(ItemNotFoundException::class);
		$this->service->instance('nobody.example');
	}

	public function testModeratingAnInstanceWritesTheListThePixelfedWordNames(): void {
		$this->assertTrue($this->service->moderateInstance('loud.example', 'unlisted', true)['data']['unlisted']);
		$this->assertSame(['loud.example'], $this->silencedList);

		$this->assertTrue($this->service->moderateInstance('evil.example', 'banned', true)['data']['banned']);
		$this->assertSame(['evil.example'], $this->accessList);

		$this->expectException(InvalidArgumentException::class);
		$this->service->moderateInstance('big.example', 'auto_cw', true);
	}

	/** On an allow-list instance "banning" a domain would mean the opposite of what the app said. */
	public function testBanningIsRefusedOnAnAllowListInstance(): void {
		$this->accessType = 'none_but';
		$this->fediverseService->expects($this->never())->method('addAddress');

		$this->expectException(InvalidArgumentException::class);
		$this->service->moderateInstance('evil.example', 'banned', true);
	}
}
