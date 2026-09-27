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
use OCA\Social\Service\AccountService;
use OCA\Social\Service\MigrationArchiveService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\PostImportService;
use OCA\Social\Service\SwitchService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
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

	public function testFollowsAreReadFromTheUploadedCsv(): void {
		$path = tempnam(sys_get_temp_dir(), 'social-follows-test');
		file_put_contents($path, "Account address\nbob@remote.example\n");
		$this->upload(['tmp_name' => $path]);
		$this->migrationService->expects($this->once())->method('importFollows')
			->with('alice', "Account address\nbob@remote.example\n")
			->willReturn(['followed' => 1, 'skipped' => 0, 'failed' => []]);

		$response = $this->controller()->importFollows();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['followed' => 1, 'skipped' => 0, 'failed' => []], $response->getData());
		@unlink($path);
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

	public function testBlocksAreReadFromTheUploadedCsv(): void {
		$this->uploadWith("carol@remote.example\n");
		$this->migrationService->expects($this->once())->method('importBlocks')
			->with('alice', "carol@remote.example\n")
			->willReturn(['blocked' => 1, 'skipped' => 0, 'failed' => []]);

		$response = $this->controller()->importBlocks();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['blocked' => 1, 'skipped' => 0, 'failed' => []], $response->getData());
	}

	public function testMutesAreReadFromTheUploadedCsv(): void {
		$this->uploadWith("Account address,Hide notifications\ncarol@remote.example,true\n");
		$this->migrationService->expects($this->once())->method('importMutes')
			->willReturn(['muted' => 1, 'skipped' => 0, 'failed' => []]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->importMutes()->getStatus());
	}

	public function testListsAreReadFromTheUploadedCsv(): void {
		$this->uploadWith("Friends,carol@remote.example\n");
		$this->migrationService->expects($this->once())->method('importLists')
			->willReturn(['lists' => 1, 'added' => 1, 'skipped' => 0, 'failed' => []]);

		$this->assertSame(1, $this->controller()->importLists()->getData()['added']);
	}

	public function testTheOtherImportsWithNoFileSaySo(): void {
		foreach ([
			fn (): object => $this->controller()->importBlocks(),
			fn (): object => $this->controller()->importMutes(),
			fn (): object => $this->controller()->importLists(),
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
	public function testImportingPostsHandsTheUploadToTheImporterAndAnswersItsTally(): void {
		$this->withUpload('outbox.json');
		$this->postImportService->expects($this->once())
			->method('import')
			->with($this->isInstanceOf(Person::class), '/tmp/uploaded', true)
			->willReturn([
				'imported' => 12, 'skipped' => 2, 'already' => 0,
				'media' => 5, 'failed' => 0, 'total' => 14, 'capped' => false,
			]);

		$response = $this->controller()->importPosts();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(12, $response->getData()['imported']);
	}

	/** Fetching a picture tells the old server the import is happening, so it is a choice. */
	public function testTheReaderCanDeclineTheFetchFromTheOldServer(): void {
		$this->withUpload('pixelfed-statuses.json');
		$this->params = ['fetch_media' => '0'];
		$this->postImportService->expects($this->once())
			->method('import')
			->with($this->anything(), $this->anything(), false)
			->willReturn(['imported' => 1, 'skipped' => 0, 'already' => 0, 'media' => 0, 'failed' => 0, 'total' => 1, 'capped' => false]);

		$this->controller()->importPosts();
	}

	public function testImportingPostsWithoutAnUploadIsABadRequest(): void {
		$this->postImportService->expects($this->never())->method('import');

		$response = $this->controller()->importPosts();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testImportingPostsWithoutAnAccountIsUnauthorized(): void {
		$this->postImportService->expects($this->never())->method('import');

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
