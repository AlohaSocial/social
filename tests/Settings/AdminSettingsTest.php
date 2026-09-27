<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Settings;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\Report;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FederationHealthService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\MediaUsageService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\PostReviewService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\ServerSettingsService;
use OCA\Social\Settings\AdminSection;
use OCA\Social\Settings\AdminSettings;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Settings\IDelegatedSettings;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The administration page and the state it hands its Vue application.
 *
 * The page renders no markup of its own any more: the template is the element
 * the application mounts on, and everything the server knows when it renders —
 * the first page of the open reports, the federation summary, the access list,
 * the Server card — travels as initial state. So what these assertions are
 * about is that payload: a key the components read but nobody provides is a
 * section that draws nothing and says nothing about why.
 */
#[AllowMockObjectsWithoutExpectations]
class AdminSettingsTest extends TestCase {
	private FederationHealthService|Stub $federationHealthService;
	private ModerationService|Stub $moderationService;
	private PostReviewService|Stub $postReviewService;
	private StreamRequest|Stub $streamRequest;
	private MediaUsageService|Stub $mediaUsageService;
	private ReportService|Stub $reportService;
	private ServerSettingsService|Stub $serverSettingsService;
	private $sectionsService;
	private IInitialState|Stub $initialState;
	private AdminSettings $settings;

	/** Translates nothing, which is what a test needs of it. */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return $l10n;
	}

	protected function setUp(): void {
		// getForm() registers the page's scripts, which resolve the l10n
		// factory off the container rather than taking it as a dependency
		\OC::$server->register(IFactory::class, $this->createStub(IFactory::class));

		$this->reportService = $this->createStub(ReportService::class);
		$this->federationHealthService = $this->createStub(FederationHealthService::class);
		$this->moderationService = $this->createStub(ModerationService::class);
		$this->initialState = $this->createStub(IInitialState::class);

		$this->serverSettingsService = $this->createStub(ServerSettingsService::class);
		$this->serverSettingsService->method('current')->willReturn($this->serverSettings());

		$this->sectionsService = $this->createStub(\OCA\Social\Service\SectionsService::class);
		$this->sectionsService->method('current')->willReturn([
			'stories' => true,
			'section_photos' => true,
			'section_videos' => true,
			'group_lists' => [],
		]);

		$this->settings = $this->settingsFor(true);
	}

	protected function tearDown(): void {
		\OC::$server->reset();

		parent::tearDown();
	}

	/**
	 * The panel as an administrator sees it, or as a delegate does.
	 *
	 * @param bool $administrator whether the viewer administers the server
	 */
	private function settingsFor(bool $administrator): AdminSettings {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createStub(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createStub(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($administrator);
		// the picker is sent every group there is; a mock that was never told
		// what to answer gives null, and the page then walks over it
		$groupManager->method('search')->willReturn([]);

		$this->postReviewService = $this->createStub(PostReviewService::class);
		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->mediaUsageService = $this->createStub(MediaUsageService::class);
		$this->streamRequest->method('localActivitySince')->willReturn(['posts' => 0, 'authors' => 0]);

		$fediverseService = $this->createStub(FediverseService::class);
		$fediverseService->method('getAccessType')->willReturn('all_but');
		$fediverseService->method('getListedAddresses')->willReturn(['noisy.example']);

		return new AdminSettings(
			$this->reportService,
			$fediverseService,
			$this->createStub(ConfigService::class),
			$this->moderationService,
			$this->postReviewService,
			$this->streamRequest,
			$this->mediaUsageService,
			$this->createStub(\OCA\Social\Service\VideoQuotaService::class),
			$this->createStub(\OCA\Social\Service\SensitiveMediaService::class),
			$this->federationHealthService,
			$this->createStub(\OCA\Social\Service\BackgroundHealthService::class),
			$this->l10n(),
			$this->serverSettingsService,
			$this->sectionsService,
			$this->createStub(\OCA\Social\Service\InterestService::class),
			$userSession,
			$groupManager,
			$this->initialState,
		);
	}

	/** What the Server card reads, with nothing an administrator has set. */
	private function serverSettings(): array {
		return [
			'contact_email' => '',
			'extended_description' => '',
			'max_size' => 10,
			'max_video_size' => 2048,
			'inbox_throttle' => 300,
			'secure_mode' => false,
			'publish_blocks' => false,
			'allow_self_signed' => false,
		];
	}

	/**
	 * @return array{waiting: int, running: int, failing: int, atRisk: int, maxTries: int, truncated: bool, instances: array}
	 */
	private function summary(array $overrides = []): array {
		return array_merge([
			'waiting' => 0,
			'running' => 0,
			'failing' => 0,
			'atRisk' => 0,
			'abandoned' => 0,
			'maxTries' => 15,
			'truncated' => false,
			'abandonedTruncated' => false,
			'retentionDays' => 7,
			'instances' => [],
			'givenUp' => [],
		], $overrides);
	}

	/**
	 * Renders the panel and answers with the state it provided.
	 *
	 * @param \OCA\Social\Model\Report[] $reports the first page of the open ones
	 * @param \OCA\Social\Model\Moderation[] $decisions what stands against whom
	 *
	 * @return array<string, mixed>
	 */
	private function state(
		array $summary,
		array $reports = [],
		array $decisions = [],
		int $resolved = 0,
		?AdminSettings $settings = null,
	): array {
		$this->reportService->method('page')->willReturn([
			'reports' => $reports,
			'total' => count($reports),
			'page' => 1,
			'perPage' => 50,
		]);
		$this->reportService->method('countResolved')->willReturn($resolved);
		$this->moderationService->method('decisions')->willReturn($decisions);
		$this->federationHealthService->method('summary')->willReturn($summary);

		$provided = [];
		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $value) use (&$provided): void {
				$provided[$key] = $value;
			});

		($settings ?? $this->settings)->getForm();

		$this->assertArrayHasKey('adminSettings', $provided, 'the page provided no state at all');

		return $provided['adminSettings'];
	}

	/**
	 * One report about a spammer, as the service hands it over.
	 *
	 * A real Report, not a mock: the page asks it to describe itself, which is
	 * the same call `ModerationController::reports()` makes, and a stub of
	 * that method would only prove the test agrees with itself.
	 */
	private function report(string $actorId = 'https://spam.example/users/spammer'): Report {
		$target = $this->createStub(\OCA\Social\Model\ActivityPub\Actor\Person::class);
		$target->method('getId')->willReturn($actorId);
		$target->method('getAccount')->willReturn('spammer@spam.example');

		$report = new Report();
		$report->setId(1)
			->setTargetAccount($target)
			->setAccountId($actorId)
			->setActorId('https://cloud.example/users/alice')
			->setStatusIds(['https://spam.example/notes/1'])
			->setCategory('spam')
			->setLocal(false)
			->setComment('endless crypto');

		return $report;
	}

	public function testTheTemplateIsTheElementTheApplicationMountsOn(): void {
		$template = (string)file_get_contents(__DIR__ . '/../../templates/settings/admin.php');
		$entry = (string)file_get_contents(__DIR__ . '/../../src/adminSettings.js');

		$this->assertStringContainsString('id="social-admin-settings"', $template);
		// the two halves of a mount point, in two files: a rename on either
		// side is a page that renders nothing and reports nothing
		$this->assertStringContainsString("getElementById('social-admin-settings')", $entry);
	}

	public function testTheFirstPageOfOpenReportsTravelsWithThePage(): void {
		$state = $this->state($this->summary(), [$this->report()]);

		$this->assertSame(1, $state['openReports']);
		$this->assertSame(50, $state['reportsPerPage']);
		$this->assertSame([
			'id' => 1,
			'account_id' => 'https://spam.example/users/spammer',
			'account' => 'spammer@spam.example',
			'reporter' => 'https://cloud.example/users/alice',
			'local' => false,
			'category' => 'spam',
			'comment' => 'endless crypto',
			'status_ids' => ['https://spam.example/notes/1'],
			'creation' => 0,
			'resolved' => false,
			'level' => '',
		], $state['reports'][0]);
	}

	/**
	 * A row that arrived with the page and one fetched from /moderation/reports
	 * afterwards are appended to the same table, so they have to be the same
	 * shape — including what stands against the account right now.
	 */
	public function testAnAccountAlreadyDealtWithSaysSo(): void {
		$decision = new \OCA\Social\Model\Moderation('https://spam.example/users/spammer', 'suspend');

		$state = $this->state($this->summary(), [$this->report()], [$decision]);

		$this->assertSame('suspend', $state['reports'][0]['level']);
	}

	public function testTheStateCarriesTheFederationSummary(): void {
		$state = $this->state($this->summary(['waiting' => 4, 'failing' => 6, 'atRisk' => 2]));

		$this->assertSame(4, $state['federation']['waiting']);
		$this->assertSame(6, $state['federation']['failing']);
		$this->assertSame(2, $state['federation']['atRisk']);
		$this->assertSame(15, $state['federation']['maxTries']);
	}

	public function testTheInstancesGivenUpOnAreCarriedSeparatelyFromTheFailingOnes(): void {
		$state = $this->state($this->summary([
			'abandoned' => 12,
			'givenUp' => [
				['host' => 'gone.example', 'requests' => 12, 'tries' => 16, 'last' => 1_700_000_000],
			],
		]));

		$this->assertSame(12, $state['federation']['abandoned']);
		$this->assertSame('gone.example', $state['federation']['givenUp'][0]['host']);
		// the one state an administrator has to act on was once no state at
		// all: a delivery left the failing count the moment it was given up on
		$this->assertSame(0, $state['federation']['failing']);
	}

	/**
	 * Two hundred reports, open and resolved mixed together, used to be the
	 * whole table: on an instance with a busy month behind it the resolved
	 * ones pushed the open ones off the bottom. The count is what the fold
	 * says; the rows behind it are fetched when it is opened.
	 */
	public function testTheResolvedReportsAreCountedButNotSent(): void {
		$state = $this->state($this->summary(), [], [], 12);

		$this->assertSame(12, $state['resolvedReports']);
		$this->assertSame([], $state['reports']);
	}

	public function testTheAccessListAndRetentionTravelWithThePage(): void {
		$state = $this->state($this->summary());

		$this->assertSame('all_but', $state['accessType']);
		$this->assertSame(['noisy.example'], $state['accessList']);
		$this->assertSame(0, $state['retentionDays']);
	}

	public function testTheServerCardSaysWhatTheInstanceIsSetTo(): void {
		$state = $this->state($this->summary());

		$this->assertSame($this->serverSettings(), $state['server']);
	}

	/**
	 * A delegate moderates; they do not administer. What the card holds —
	 * whether unsigned fetches are answered, how large an upload may be — is
	 * a decision about the server, and its endpoint refuses them anyway. The
	 * section is not rendered because there is nothing for it to render.
	 */
	public function testADelegateIsNotShownTheServerCard(): void {
		$state = $this->state($this->summary(), [], [], 0, $this->settingsFor(false));

		$this->assertNull($state['server']);
	}

	/**
	 * Moderating used to mean administering the whole server. The section is
	 * offered in Administration privileges so an administrator can hand it to
	 * a group and nothing else with it.
	 */
	public function testTheSectionCanBeDelegated(): void {
		$this->assertInstanceOf(IDelegatedSettings::class, $this->settings);
		$this->assertSame('Moderation', $this->settings->getName());
		$this->assertSame(AdminSection::SECTION_ID, $this->settings->getSection());
	}

	/**
	 * A delegate writes through ModerationController, which validates what it
	 * is given; core's own app-config route would let them write any key under
	 * `social` as a raw string.
	 */
	public function testDelegationCarriesNoAppConfigWriteAccess(): void {
		$this->assertSame([], $this->settings->getAuthorizedAppConfig());
	}
}
