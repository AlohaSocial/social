<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\MigrationController;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ImportJob;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ImportQueueService;
use OCA\Social\Service\MigrationArchiveService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\MoveFinishService;
use OCA\Social\Service\MoveInService;
use OCA\Social\Service\PostImportService;
use OCA\Social\Service\SwitchService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\UserMigration\UserMigrationException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The Migration page's buttons.
 *
 * `$_FILES` rather than a parameter, because that is what the framework hands
 * a multipart upload and what the controller reads; the tests set it the way a
 * request would and clear it afterwards.
 */
#[AllowMockObjectsWithoutExpectations]
class MigrationControllerTest extends TestCase {
	private MigrationArchiveService|MockObject $archiveService;
	private MigrationService|MockObject $migrationService;
	private PostImportService|MockObject $postImportService;
	private ImportQueueService|MockObject $importQueueService;
	private MoveInService|MockObject $moveInService;
	private MoveFinishService|MockObject $moveFinishService;
	private IURLGenerator|Stub $urlGenerator;
	private AccountService|Stub $accountService;
	private SwitchService|Stub $switchService;

	protected function setUp(): void {
		parent::setUp();
		$this->archiveService = $this->createMock(MigrationArchiveService::class);
		$this->migrationService = $this->createMock(MigrationService::class);
		$_FILES = [];

		// Response::getHeaders() asks the container for the request
		\OC::$server->register(IRequest::class, $this->createStub(IRequest::class));

		$this->postImportService = $this->createMock(PostImportService::class);
		$this->importQueueService = $this->createMock(ImportQueueService::class);
		$this->moveInService = $this->createMock(MoveInService::class);
		$this->moveFinishService = $this->createMock(MoveFinishService::class);
		$this->urlGenerator = $this->createStub(IURLGenerator::class);
		$this->urlGenerator->method('linkToRoute')->willReturnCallback(static fn (string $route): string => '/' . $route);
		$this->accountService = $this->createStub(AccountService::class);
		$this->switchService = $this->createStub(SwitchService::class);
		$this->accountService->method('getActorFromUserId')->willReturn(new Person());
	}

	/** @var array<string, mixed> what the request carries */
	private array $params = [];

	/** @var string[] the temporary uploads to clean up */
	private array $uploaded = [];

	protected function tearDown(): void {
		foreach ($this->uploaded as $path) {
			@unlink($path);
		}
		$this->uploaded = [];
		$_FILES = [];
		\OC::$server->reset();
		parent::tearDown();
	}

	private function controller(?string $userId = 'alice'): MigrationController {
		return new MigrationController(
			$this->request(),
			$userId,
			$this->archiveService,
			$this->migrationService,
			$this->postImportService,
			$this->importQueueService,
			$this->moveInService,
			$this->moveFinishService,
			$this->urlGenerator,
			$this->accountService,
			$this->switchService,
			new NullLogger(),
		);
	}

	// alsoKnownAs

	/**
	 * Naming the old account is a statement this server makes about an account
	 * it owns: it federates nothing and is the person's own to make. It used
	 * to need `occ social:account:alias`, so arriving from Pixelfed needed an
	 * administrator for a field the arriver could have filled in themselves.
	 */
	public function testTheAliasesAreTheCallersOwnToReadAndChange(): void {
		$this->migrationService->method('listAliases')->with('alice')
			->willReturn(['https://old.example/users/me']);

		$response = $this->controller()->aliases();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['https://old.example/users/me'], $response->getData()['aliases']);
	}

	public function testAddingAnAliasAnswersWithTheListAsItNowStands(): void {
		$this->migrationService->expects($this->once())->method('addAlias')
			->with('alice', 'https://old.example/users/me')
			->willReturn(['https://old.example/users/me']);

		$response = $this->controller()->aliasAdd('https://old.example/users/me');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['https://old.example/users/me'], $response->getData()['aliases']);
	}

	public function testRemovingAnAliasAnswersWithWhatIsLeft(): void {
		$this->migrationService->expects($this->once())->method('removeAlias')
			->with('alice', 'https://old.example/users/me')->willReturn([]);

		$this->assertSame([], $this->controller()->aliasRemove('https://old.example/users/me')->getData()['aliases']);
	}

	/** An address that is not an account's own is refused, and the reason is the whole of the help there is. */
	public function testAnAddressThatIsNotAnActorIsRefusedWithItsReason(): void {
		$this->migrationService->method('addAlias')
			->willThrowException(new \OCA\Social\Exceptions\InvalidResourceException('that is not an actor id'));

		$response = $this->controller()->aliasAdd('pixelfed.social');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('that is not an actor id', $response->getData()['error']);
	}

	public function testNobodyWithoutASessionReadsOrChangesAnAlias(): void {
		$this->migrationService->expects($this->never())->method('listAliases');
		$this->migrationService->expects($this->never())->method('addAlias');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->aliases()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->aliasAdd('x')->getStatus());
	}

	/** A request that answers the parameters the post import reads. */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')
			->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);

		return $request;
	}

	/** An upload carrying `$contents`, cleaned up when the test ends. */
	private function uploadWith(string $contents): void {
		$path = tempnam(sys_get_temp_dir(), 'social-csv-test');
		file_put_contents($path, $contents);
		$this->uploaded[] = $path;
		$this->upload(['tmp_name' => $path]);
	}

	private function queued(string $kind): ImportJob {
		$job = new ImportJob();
		$job->setId(7)->setUserId('alice')->setKind($kind);

		return $job;
	}

	/** @param array<string, mixed> $file */
	private function upload(array $file): void {
		$_FILES = ['file' => $file + ['error' => UPLOAD_ERR_OK, 'size' => 10, 'tmp_name' => '/tmp/whatever']];
	}

	// export

	public function testExportHandsBackTheArchiveAsADownload(): void {
		$path = tempnam(sys_get_temp_dir(), 'social-export-test');
		file_put_contents($path, 'PK-not-really-a-zip');
		$this->archiveService->method('export')->with('alice')->willReturn($path);
		$this->archiveService->method('filename')->willReturn('social-alice-2026-09-13.zip');

		$response = $this->controller()->export();

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('PK-not-really-a-zip', $response->render());
		$this->assertSame(
			'attachment; filename="social-alice-2026-09-13.zip"',
			$response->getHeaders()['Content-Disposition'] ?? ''
		);
		$this->assertSame('application/zip', $response->getHeaders()['Content-Type'] ?? '');
		@unlink($path);
	}

	public function testExportWithoutAnAccountIsUnauthorized(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->export()->getStatus());
	}

	public function testAFailedExportIsAnErrorRatherThanAnEmptyFile(): void {
		$this->archiveService->method('export')->willThrowException(new UserMigrationException('no room on the disk'));

		$response = $this->controller()->export();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'no room on the disk'], $response->getData());
	}

	// import

	public function testImportReadsTheUploadAndReportsWhatItDid(): void {
		$this->upload(['tmp_name' => '/tmp/archive.zip']);
		$this->archiveService->expects($this->once())->method('import')
			->with('alice', '/tmp/archive.zip')
			->willReturn(['Importing the Social profile…']);

		$response = $this->controller()->import();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['imported' => true, 'log' => ['Importing the Social profile…']], $response->getData());
	}

	public function testImportWithNoFileSaysSo(): void {
		$response = $this->controller()->import();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'no archive was uploaded'], $response->getData());
	}

	public function testAFailedUploadIsNotTreatedAsAnArchive(): void {
		$_FILES = ['file' => ['error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => '', 'size' => 0]];
		$this->archiveService->expects($this->never())->method('import');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->import()->getStatus());
	}

	/** A refusal that names the limit, rather than a truncated archive. */
	public function testAnOversizedArchiveIsRefusedBeforeItIsOpened(): void {
		$this->upload(['size' => 200 * 1024 * 1024]);
		$this->archiveService->expects($this->never())->method('import');

		$response = $this->controller()->import();

		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
		$this->assertStringContainsString('100 MB', $response->getData()['error']);
	}

	public function testAnArchiveWithoutSocialDataIsABadRequest(): void {
		$this->upload([]);
		$this->archiveService->method('import')
			->willThrowException(new UserMigrationException('this archive holds no Social data'));

		$response = $this->controller()->import();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'this archive holds no Social data'], $response->getData());
	}

	public function testImportWithoutAnAccountIsUnauthorized(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->import()->getStatus());
	}

	// follows from another server

	/**
	 * Each row of a follows file is a WebFinger lookup and a delivery, and a
	 * few hundred of them outlive a web request; so the upload is kept and
	 * queued, and the answer is the import as queued rather than its result.
	 */
	public function testFollowsAreKeptAndQueuedRatherThanReadInTheRequest(): void {
		$this->uploadWith("Account address\nbob@remote.example\n");
		$this->migrationService->expects($this->never())->method('importFollows');
		$this->importQueueService->method('hasActive')->willReturn(false);
		$this->importQueueService->expects($this->once())->method('queue')
			->with('alice', ImportJob::KIND_FOLLOWS, $this->isString(), [])
			->willReturn($this->queued(ImportJob::KIND_FOLLOWS));

		$response = $this->controller()->importFollows();

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(ImportJob::KIND_FOLLOWS, $response->getData()['import']->getKind());
	}

	/** A second press while the first runs must not queue the same file twice. */
	public function testASecondImportOfAKindWhileOneRunsIsRefused(): void {
		$this->uploadWith("Account address\nbob@remote.example\n");
		$this->importQueueService->method('hasActive')->with('alice', ImportJob::KIND_FOLLOWS)->willReturn(true);
		$this->importQueueService->expects($this->never())->method('queue');

		$response = $this->controller()->importFollows();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}

	// moving away, from the page

	private function alice(): Person {
		$alice = new Person();
		$alice->setId('https://cloud.example/@alice')->setAccount('alice@cloud.example')->setLocal(true);

		return $alice;
	}

	public function testTheMoveStatusIsTheCallersOwn(): void {
		$this->migrationService->method('moveStatus')->with('alice')
			->willReturn(['moved_to' => '', 'moved_at' => null, 'can_move_at' => 0]);

		$response = $this->controller()->moveStatus();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('', $response->getData()['moved_to']);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->moveStatus()->getStatus());
	}

	/** The typed handle is one of the three things in front of the move; the password is the attribute's. */
	public function testMovingAwayNeedsTheOwnHandleTypedBack(): void {
		$this->accountService = $this->createStub(AccountService::class);
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->migrationService->expects($this->never())->method('move');

		$response = $this->controller()->moveOut('@alice@new.example', 'alice@elsewhere.example');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('alice@cloud.example', $response->getData()['error']);
	}

	public function testMovingAwayWithTheHandleConfirmedMovesAndAnswersTheStatus(): void {
		$this->accountService = $this->createStub(AccountService::class);
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$target = new Person();
		$target->setId('https://new.example/users/alice')->setAccount('alice@new.example')->setUrl('https://new.example/@alice');
		$this->migrationService->expects($this->once())->method('move')->with('alice', '@alice@new.example')->willReturn($target);
		$this->migrationService->method('moveStatus')
			->willReturn(['moved_to' => 'https://new.example/users/alice', 'moved_at' => 1000, 'can_move_at' => 1000 + 30 * 86400]);

		// the handle with or without its @, whatever the case
		$response = $this->controller()->moveOut('@alice@new.example', '@Alice@cloud.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('https://new.example/users/alice', $response->getData()['moved_to']);
		$this->assertSame('alice@new.example', $response->getData()['target']['acct']);
	}

	public function testAMoveTheServiceRefusesIsRefusedWithItsReason(): void {
		$this->accountService = $this->createStub(AccountService::class);
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->migrationService->method('move')
			->willThrowException(new \OCA\Social\Exceptions\InvalidResourceException('this account moved on 2026-10-01 and can move again on 2026-10-31'));

		$response = $this->controller()->moveOut('@alice@new.example', 'alice@cloud.example');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('can move again', $response->getData()['error']);
	}

	public function testUndoingTheMoveAnswersTheStatusAfterwards(): void {
		$this->migrationService->expects($this->once())->method('undoMove')->with('alice');
		$this->migrationService->method('moveStatus')->willReturn(['moved_to' => '', 'moved_at' => 1000, 'can_move_at' => 1000 + 30 * 86400]);

		$response = $this->controller()->undoMove();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('', $response->getData()['moved_to']);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->undoMove()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->moveOut('x', 'y')->getStatus());
	}

	/** The irreversible routes are behind Nextcloud's password confirmation. */
	public function testTheMoveAndItsUndoRequireAFreshPassword(): void {
		foreach (['moveOut', 'undoMove'] as $method) {
			$attributes = (new \ReflectionMethod(MigrationController::class, $method))
				->getAttributes(\OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired::class);
			$this->assertCount(1, $attributes, $method . ' is not behind a password confirmation');
		}
	}

	// moving in from the old handle

	public function testInspectingTheOldAccountAnswersWhatItsServerLetsUsRead(): void {
		$this->moveInService->method('inspect')->with('@alice@old.example')->willReturn([
			'id' => 'https://old.example/users/alice', 'acct' => 'alice@old.example', 'name' => 'Alice',
			'url' => 'https://old.example/@alice', 'avatar' => '',
			'following' => ['total' => 120, 'readable' => true], 'posts' => ['total' => 900, 'readable' => false],
		]);

		$response = $this->controller()->moveInInspect('@alice@old.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(120, $response->getData()['account']['following']['total']);
		$this->assertFalse($response->getData()['account']['posts']['readable']);
	}

	public function testInspectingNobodyIsRefusedWithTheReason(): void {
		$this->moveInService->method('inspect')
			->willThrowException(new \OCA\Social\Exceptions\InvalidResourceException('no account answers to alice@gone.example'));

		$response = $this->controller()->moveInInspect('alice@gone.example');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('no account answers to alice@gone.example', $response->getData()['error']);
	}

	/** The alias is set in the request; the follows and the posts are the queued run's. */
	public function testFinishingFromHereAnswersWhereToSendThePerson(): void {
		$this->moveFinishService->expects($this->once())->method('start')->with('alice', '@alice@old.example')
			->willReturn('https://old.example/index.php/apps/social/oauth/authorize?client_id=c');

		$response = $this->controller()->moveInFinishStart('@alice@old.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('https://old.example/index.php/apps/social/oauth/authorize?client_id=c', $response->getData()['authorize_url']);
	}

	public function testFinishingFromAServerThatIsNotThisAppIsRefusedWithTheReason(): void {
		$this->moveFinishService->method('start')->willThrowException(new InvalidResourceException('the old account is not on an Aloha Social server; finish the move there, with its own button'));

		$response = $this->controller()->moveInFinishStart('@alice@mastodon.example');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('finish the move there', $response->getData()['error']);
	}

	public function testTheCallbackSpendsTheCodeAndLandsOnTheMigrationPageEitherWay(): void {
		$this->moveFinishService->expects($this->once())->method('finish')->with('alice', 'the-code', 'the-state')
			->willReturn(['status' => 'done', 'acct' => 'alice@old.example', 'at' => 1, 'error' => '']);

		$response = $this->controller()->moveInFinishCallback('the-code', 'the-state');
		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('/social.Navigation.navigatemigration', $response->getRedirectURL());

		// a refusal is the service's to record; the person still lands on the page
		$this->moveFinishService = $this->createMock(MoveFinishService::class);
		$this->moveFinishService->method('finish')->willThrowException(new InvalidResourceException('this is not the move that was started'));
		$response = $this->controller()->moveInFinishCallback('the-code', 'wrong');
		$this->assertSame('/social.Navigation.navigatemigration', $response->getRedirectURL());
	}

	public function testTheFinishStatusIsAnsweredAsTheServiceKeepsIt(): void {
		$this->moveFinishService->method('status')->willReturn(['status' => 'failed', 'acct' => 'alice@old.example', 'at' => 0, 'error' => 'refused']);

		$response = $this->controller()->moveInFinishStatus();

		$this->assertSame('failed', $response->getData()['status']);
		$this->assertSame('refused', $response->getData()['error']);
	}

	public function testMovingInSetsTheAliasAndQueuesTheRun(): void {
		$this->importQueueService->method('hasActive')->with('alice', ImportJob::KIND_MOVE_IN)->willReturn(false);
		$options = ['source' => 'https://old.example/users/alice', 'acct' => 'alice@old.example', 'follows' => true, 'posts' => false, 'fetch_media' => true];
		$this->moveInService->expects($this->once())->method('prepare')
			->with('alice', '@alice@old.example', true, false, true)->willReturn($options);
		$this->importQueueService->expects($this->once())->method('queue')
			->with('alice', ImportJob::KIND_MOVE_IN, null, $options)->willReturn($this->queued(ImportJob::KIND_MOVE_IN));

		$response = $this->controller()->moveIn('@alice@old.example', '1', '0', 'yes');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(ImportJob::KIND_MOVE_IN, $response->getData()['import']->getKind());
	}

	public function testASecondMoveInWhileOneRunsIsRefused(): void {
		$this->importQueueService->method('hasActive')->willReturn(true);
		$this->moveInService->expects($this->never())->method('prepare');

		$this->assertSame(Http::STATUS_CONFLICT, $this->controller()->moveIn('@alice@old.example')->getStatus());
	}

	public function testMovingInWithoutAnAccountIsUnauthorized(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->moveIn('@alice@old.example')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->moveInInspect('@alice@old.example')->getStatus());
	}

	public function testTheImportsAreListedForTheirOwner(): void {
		$this->importQueueService->method('listFor')->with('alice')->willReturn([$this->queued(ImportJob::KIND_POSTS)]);

		$response = $this->controller()->imports();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData()['imports']);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->imports()->getStatus());
	}

	public function testAFinishedImportCanBeDismissedAndARunningOneCannot(): void {
		$this->importQueueService->method('dismiss')->willReturnMap([['alice', 7, true], ['alice', 8, false]]);
		$this->importQueueService->method('listFor')->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->dismissImport(7)->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->dismissImport(8)->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->dismissImport(7)->getStatus());
	}

	public function testFollowsWithNoFileSaysSo(): void {
		$response = $this->controller()->importFollows();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'no file was uploaded'], $response->getData());
	}

	public function testFollowsWithoutAnAccountIsUnauthorized(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->importFollows()->getStatus());
	}

	// the other three lists another server exported

	public function testBlocksMutesAndListsAreQueuedUnderTheirOwnKind(): void {
		$this->importQueueService->method('hasActive')->willReturn(false);
		$kinds = [];
		$this->importQueueService->method('queue')
			->willReturnCallback(function (string $userId, string $kind) use (&$kinds): ImportJob {
				$kinds[] = $kind;

				return $this->queued($kind);
			});

		$this->uploadWith("carol@remote.example\n");
		$this->assertSame(Http::STATUS_ACCEPTED, $this->controller()->importBlocks()->getStatus());
		$this->uploadWith("Account address,Hide notifications\ncarol@remote.example,true\n");
		$this->assertSame(Http::STATUS_ACCEPTED, $this->controller()->importMutes()->getStatus());
		$this->uploadWith("Friends,carol@remote.example\n");
		$this->assertSame(Http::STATUS_ACCEPTED, $this->controller()->importLists()->getStatus());
		$this->uploadWith("https://remote.example/users/carol/statuses/1\n");
		$this->assertSame(Http::STATUS_ACCEPTED, $this->controller()->importBookmarks()->getStatus());
		$this->uploadWith("spam.example\n");
		$this->assertSame(Http::STATUS_ACCEPTED, $this->controller()->importDomainBlocks()->getStatus());

		$this->assertSame([
			ImportJob::KIND_BLOCKS, ImportJob::KIND_MUTES, ImportJob::KIND_LISTS,
			ImportJob::KIND_BOOKMARKS, ImportJob::KIND_DOMAIN_BLOCKS,
		], $kinds);
	}

	public function testTheOtherImportsWithNoFileSaySo(): void {
		foreach ([
			fn (): object => $this->controller()->importBlocks(),
			fn (): object => $this->controller()->importMutes(),
			fn (): object => $this->controller()->importLists(),
			fn (): object => $this->controller()->importBookmarks(),
			fn (): object => $this->controller()->importDomainBlocks(),
		] as $call) {
			$response = $call();
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			$this->assertSame(['error' => 'no file was uploaded'], $response->getData());
		}
	}

	public function testTheOtherImportsWithoutAnAccountAreUnauthorized(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->importBlocks()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->importMutes()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->importLists()->getStatus());
	}

	// one list at a time, back out

	public function testASingleListIsHandedOverAsANamedCsvDownload(): void {
		$this->migrationService->expects($this->once())->method('exportCsv')
			->with('alice', 'blocks')
			->willReturn(['blocked_accounts.csv', "carol@remote.example\n"]);

		$response = $this->controller()->exportCsv('blocks');

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame("carol@remote.example\n", $response->render());
		$this->assertSame(
			'attachment; filename="blocked_accounts.csv"',
			$response->getHeaders()['Content-Disposition']
		);
		$this->assertStringStartsWith('text/csv', $response->getHeaders()['Content-Type']);
	}

	/** A kind this account keeps no list of is a 404, not a server error. */
	public function testAnUnknownKindIsNotFound(): void {
		$this->migrationService->method('exportCsv')
			->willThrowException(new InvalidResourceException('"secrets" is not something this account keeps a list of'));

		$response = $this->controller()->exportCsv('secrets');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testExportingAListWithoutAnAccountIsUnauthorized(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->exportCsv('blocks')->getStatus());
	}
	public function testImportingPostsIsQueuedWithTheFetchDecision(): void {
		$this->withUpload('outbox.json');
		$this->postImportService->expects($this->never())->method('import');
		$this->importQueueService->method('hasActive')->willReturn(false);
		$this->importQueueService->expects($this->once())->method('queue')
			->with('alice', ImportJob::KIND_POSTS, '/tmp/uploaded', ['fetch_media' => true])
			->willReturn($this->queued(ImportJob::KIND_POSTS));

		$response = $this->controller()->importPosts();

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(ImportJob::KIND_POSTS, $response->getData()['import']->getKind());
	}

	/** Fetching a picture tells the old server the import is happening, so it is a choice. */
	public function testTheReaderCanDeclineTheFetchFromTheOldServer(): void {
		$this->withUpload('pixelfed-statuses.json');
		$this->params = ['fetch_media' => '0'];
		$this->importQueueService->method('hasActive')->willReturn(false);
		$this->importQueueService->expects($this->once())->method('queue')
			->with('alice', ImportJob::KIND_POSTS, $this->anything(), ['fetch_media' => false])
			->willReturn($this->queued(ImportJob::KIND_POSTS));

		$this->controller()->importPosts();
	}

	public function testImportingPostsWithoutAnUploadIsABadRequest(): void {
		$this->importQueueService->expects($this->never())->method('queue');

		$response = $this->controller()->importPosts();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testImportingPostsWithoutAnAccountIsUnauthorized(): void {
		$this->importQueueService->expects($this->never())->method('queue');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->importPosts()->getStatus());
	}

	private function withUpload(string $name): void {
		$_FILES['file'] = [
			'name' => $name,
			'tmp_name' => '/tmp/uploaded',
			'error' => UPLOAD_ERR_OK,
			'size' => 1024,
			'type' => 'application/json',
		];
	}
}
