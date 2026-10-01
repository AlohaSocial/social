<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\SetupChecks;

use OCA\Social\Cron\Queue;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FederationHealthService;
use OCA\Social\Service\VideoTranscodeService;
use OCA\Social\SetupChecks\ClientApiAtRoot;
use OCA\Social\SetupChecks\CloudAddressMatches;
use OCA\Social\SetupChecks\CronRanRecently;
use OCA\Social\SetupChecks\Docs;
use OCA\Social\SetupChecks\MemcacheConfigured;
use OCA\Social\SetupChecks\OutboundQueueNotStuck;
use OCA\Social\SetupChecks\ProxyForwardsTheScheme;
use OCA\Social\SetupChecks\ReachableByStrictPeers;
use OCA\Social\SetupChecks\UploadLimitsAgree;
use OCA\Social\SetupChecks\VideoConverter;
use OCA\Social\SetupChecks\WebFingerReachable;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The checks Administration → Overview shows.
 *
 * Each of them is the only thing that says a particular kind of breakage has
 * happened: an administrator who never opens Social used to find out that
 * nobody could follow anyone here when a user asked. What the tests pin is
 * that a healthy instance is quiet, that a broken one is not, and that the
 * severity matches how broken it is — a warning an administrator learns to
 * ignore is worse than no check.
 */
#[AllowMockObjectsWithoutExpectations]
class SetupChecksTest extends TestCase {
	private IL10N|Stub $l10n;

	protected function setUp(): void {
		$this->l10n = $this->createStub(IL10N::class);
		// positional, like the real one: the address check names the same
		// address twice and a stub that could not would hide it
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string
				=> $parameters === [] ? $text : vsprintf($text, $parameters)
		);
		$this->l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count): string
				=> str_replace('%n', (string)$count, $count === 1 ? $singular : $plural)
		);
	}

	private function checkService(): CheckService|MockObject {
		return $this->createMock(CheckService::class);
	}

	private function actor(string $username): Person {
		$actor = new Person();
		$actor->setPreferredUsername($username);

		return $actor;
	}

	public function testWebFingerIsProbedForAnAccountThatExistsRatherThanForTheAdministrator(): void {
		// the administrator may never have opened Social, and a probe for an
		// account that does not exist answers 404 whatever the redirects do
		$actorsRequest = $this->createStub(ActorsRequest::class);
		$actorsRequest->method('getAny')->willReturn($this->actor('alice'));

		$checkService = $this->checkService();
		$checkService->expects($this->once())->method('checkWellKnown')
			->with('alice')->willReturn(true);

		$result = (new WebFingerReachable($this->l10n, $checkService, $actorsRequest))->run();

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}

	public function testAnInstanceMastodonAppsCanReachIsQuiet(): void {
		$checkService = $this->checkService();
		$checkService->expects($this->once())->method('checkClientApiRoot')->willReturn(true);

		$result = (new ClientApiAtRoot($this->l10n, $checkService))->run();

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}

	public function testAnInstanceNoMastodonAppCanAddIsAWarning(): void {
		// a warning, not an error: the web interface and federation both work
		// without the root rules, so this is not a broken instance
		$checkService = $this->checkService();
		$checkService->method('checkClientApiRoot')->willReturn(false);

		$result = (new ClientApiAtRoot($this->l10n, $checkService))->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('/api/v1/instance', (string)$result->getDescription());
		$this->assertSame(ClientApiAtRoot::DOC, $result->getLinkToDoc());
	}

	/**
	 * Invisible from inside: the app answers, the routes work, and the
	 * addresses in the answers are wrong. It took a web server's access log to
	 * find, which is the argument for a check saying it.
	 */
	public function testAProxyThatDropsTheSchemeIsAWarning(): void {
		$checkService = $this->checkService();
		$checkService->method('clientApiSchemeIsWrong')->willReturn(true);

		$result = (new ProxyForwardsTheScheme($this->l10n, $checkService))->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('X-Forwarded-Proto', (string)$result->getDescription());
	}

	public function testNothingIsSaidWhileTheSchemeSurvivesTheProxy(): void {
		$checkService = $this->checkService();
		$checkService->method('clientApiSchemeIsWrong')->willReturn(false);

		$this->assertSame(
			SetupResult::SUCCESS,
			(new ProxyForwardsTheScheme($this->l10n, $checkService))->run()->getSeverity()
		);
	}

	public function testAnInstanceNobodyCanBeFoundOnIsAnError(): void {
		$actorsRequest = $this->createStub(ActorsRequest::class);
		$actorsRequest->method('getAny')->willReturn($this->actor('alice'));

		$checkService = $this->checkService();
		$checkService->method('checkWellKnown')->willReturn(false);

		$result = (new WebFingerReachable($this->l10n, $checkService, $actorsRequest))->run();

		$this->assertSame(SetupResult::ERROR, $result->getSeverity());
		$this->assertStringContainsString('@alice', (string)$result->getDescription());
		$this->assertSame(WebFingerReachable::DOC, $result->getLinkToDoc());
	}

	public function testAnAppNobodyHasOpenedYetIsNotProbedAtAll(): void {
		$actorsRequest = $this->createStub(ActorsRequest::class);
		$actorsRequest->method('getAny')->willReturn(null);

		$checkService = $this->checkService();
		$checkService->expects($this->never())->method('checkWellKnown');

		$result = (new WebFingerReachable($this->l10n, $checkService, $actorsRequest))->run();

		$this->assertSame(SetupResult::INFO, $result->getSeverity());
	}

	/** A database that will not answer is not a failing WebFinger. */
	public function testALookupFailureIsNotReportedAsAnUnreachableInstance(): void {
		$actorsRequest = $this->createStub(ActorsRequest::class);
		$actorsRequest->method('getAny')->willThrowException(new \RuntimeException('no database'));

		$result = (new WebFingerReachable($this->l10n, $this->checkService(), $actorsRequest))->run();

		$this->assertSame(SetupResult::INFO, $result->getSeverity());
	}

	public function testAnAddressThatStillMatchesTheServerIsQuiet(): void {
		$checkService = $this->checkService();
		$checkService->method('cloudAddresses')->willReturn([
			'configured' => 'https://cloud.example', 'expected' => 'https://cloud.example',
		]);
		$checkService->method('checkCloudAddress')->willReturn(true);
		$checkService->method('checkSocialUrl')->willReturn(true);

		$result = (new CloudAddressMatches($this->l10n, $checkService))->run();

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}

	/**
	 * The cloud address is right and the ids are not: `social_url` came from
	 * the first request's host. Nothing compared the two.
	 */
	public function testIdsMintedFromAnotherAddressAreAnErrorThatNamesBoth(): void {
		$checkService = $this->checkService();
		$checkService->method('cloudAddresses')->willReturn([
			'configured' => 'https://cloud.example/index.php', 'expected' => 'https://cloud.example/index.php',
		]);
		$checkService->method('configuredSocialUrl')->willReturn('http://internal:8080/index.php/apps/social/');
		$checkService->method('checkCloudAddress')->willReturn(true);
		$checkService->method('checkSocialUrl')->willReturn(false);

		$result = (new CloudAddressMatches($this->l10n, $checkService))->run();
		$description = (string)$result->getDescription();

		$this->assertSame(SetupResult::ERROR, $result->getSeverity());
		$this->assertStringContainsString('http://internal:8080/index.php/apps/social/', $description);
		$this->assertStringContainsString('occ config:app:delete social social_url', $description);
		$this->assertStringContainsString('occ social:reset --uri=https://cloud.example/index.php', $description);
	}

	/**
	 * The one failure that cannot be corrected, only decided about: the stored
	 * address is inside every id already federated.
	 */
	public function testARenamedServerIsAnErrorThatNamesBothAddressesAndTheWayOut(): void {
		$checkService = $this->checkService();
		$checkService->method('cloudAddresses')->willReturn([
			'configured' => 'https://old.example', 'expected' => 'https://new.example',
		]);
		$checkService->method('checkCloudAddress')->willReturn(false);

		$result = (new CloudAddressMatches($this->l10n, $checkService))->run();
		$description = (string)$result->getDescription();

		$this->assertSame(SetupResult::ERROR, $result->getSeverity());
		$this->assertStringContainsString('https://old.example', $description);
		$this->assertStringContainsString('https://new.example', $description);
		$this->assertStringContainsString('occ social:reset --uri=https://new.example', $description);
	}

	public function testAnAppThatHasNeverBeenOpenedIsNotADisagreement(): void {
		$checkService = $this->checkService();
		$checkService->method('cloudAddresses')->willReturn(['configured' => '', 'expected' => 'https://cloud.example']);

		$result = (new CloudAddressMatches($this->l10n, $checkService))->run();

		$this->assertSame(SetupResult::INFO, $result->getSeverity());
	}

	public function testAServerThatDeclaresNoUrlIsAWarningRatherThanAMismatch(): void {
		$checkService = $this->checkService();
		$checkService->method('cloudAddresses')->willReturn(['configured' => 'https://cloud.example', 'expected' => '']);

		$result = (new CloudAddressMatches($this->l10n, $checkService))->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('overwrite.cli.url', (string)$result->getDescription());
	}

	private function cron(int $lastRun, int $now = 1_757_548_800): CronRanRecently {
		$job = $this->createStub(IJob::class);
		$job->method('getLastRun')->willReturn($lastRun);
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('getJobs')->with(Queue::class, 1, 0)->willReturn($lastRun < 0 ? [] : [$job]);

		$timeFactory = $this->createStub(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn($now);

		return new CronRanRecently($this->l10n, $jobList, $timeFactory);
	}

	public function testADeliveryJobThatRanThisHourIsQuiet(): void {
		$result = $this->cron(1_757_548_800 - 600)->run();

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
		$this->assertStringContainsString('10 minutes ago', (string)$result->getDescription());
	}

	public function testADeliveryJobThatHasNotRunForHoursIsAWarning(): void {
		$result = $this->cron(1_757_548_800 - 7200)->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('120 minutes ago', (string)$result->getDescription());
	}

	public function testADeliveryJobThatHasNeverRunIsAWarningAboutCron(): void {
		$result = $this->cron(0)->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('never run', (string)$result->getDescription());
	}

	/** Nothing this instance sends would ever leave, which is not a warning. */
	public function testADeliveryJobThatIsNotRegisteredAtAllIsAnError(): void {
		$result = $this->cron(-1)->run();

		$this->assertSame(SetupResult::ERROR, $result->getSeverity());
	}

	private function memcache(bool $available): MemcacheConfigured {
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn($available);

		return new MemcacheConfigured($this->l10n, $cacheFactory);
	}

	public function testAnInstanceWithAMemcacheIsQuiet(): void {
		$this->assertSame(SetupResult::SUCCESS, $this->memcache(true)->run()->getSeverity());
	}

	/**
	 * Nextcloud's own check says a memcache would be faster. Here some of what
	 * is lost without one is protection, and the warning has to say which, or
	 * it reads like the one the administrator has already decided to ignore.
	 */
	public function testNoMemcacheIsAWarningThatNamesWhatIsOff(): void {
		$result = $this->memcache(false)->run();
		$description = (string)$result->getDescription();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('memcache.local', $description);
		$this->assertStringContainsString('inbox throttle', $description);
		$this->assertStringContainsString('duplicate-post protection', $description);
		// the breaker is in the database whether or not there is a memcache,
		// so the warning must not say it is off
		$this->assertStringNotContainsString('delivery breaker', $description);
	}

	private function queue(int $abandoned, int $stale): OutboundQueueNotStuck {
		$health = $this->createStub(FederationHealthService::class);
		$health->method('stuck')->willReturn(['abandoned' => $abandoned, 'stale' => $stale]);

		return new OutboundQueueNotStuck($this->l10n, $health);
	}

	public function testAQueueThatIsMovingIsQuiet(): void {
		$this->assertSame(SetupResult::SUCCESS, $this->queue(0, 0)->run()->getSeverity());
	}

	public function testBothKindsOfStuckDeliveryAreNamedSeparately(): void {
		$result = $this->queue(3, 1)->run();
		$description = (string)$result->getDescription();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('1 delivery has been waiting for more than a day', $description);
		$this->assertStringContainsString('3 deliveries were given up on', $description);
		$this->assertStringContainsString('occ social:queue:status', $description);
	}

	public function testOnlyWhatIsActuallyStuckIsMentioned(): void {
		$description = (string)$this->queue(2, 0)->run()->getDescription();

		$this->assertStringNotContainsString('waiting for more than a day', $description);
		$this->assertStringContainsString('2 deliveries were given up on', $description);
	}

	/**
	 * A check nobody can act on is a check nobody reads twice, so each one
	 * links to the section of the guide that says what to do — and a section
	 * renamed without the constant is a link to nowhere, which nothing but
	 * this would catch.
	 */
	public function testEveryFailureSendsTheAdministratorSomewhereTheGuideExplainsIt(): void {
		$anchors = $this->anchorsOfTheGuide();

		foreach ([
			WebFingerReachable::DOC,
			CloudAddressMatches::DOC,
			CronRanRecently::DOC,
			OutboundQueueNotStuck::DOC,
			MemcacheConfigured::DOC,
			VideoConverter::DOC,
		] as $link) {
			$this->assertStringStartsWith(Docs::ADMIN_GUIDE . '#', $link);
			$anchor = substr($link, strlen(Docs::ADMIN_GUIDE) + 1);
			$this->assertContains($anchor, $anchors, $anchor . ' has no section in docs/Admin.md');
		}
	}

	/**
	 * Every heading of docs/Admin.md, as the fragment GitHub makes of it.
	 *
	 * @return string[]
	 */
	private function anchorsOfTheGuide(): array {
		$guide = (string)file_get_contents(__DIR__ . '/../../docs/Admin.md');
		preg_match_all('/^#+ (.+)$/m', $guide, $matches);

		return array_map(
			static fn (string $heading): string => trim(preg_replace(
				'/[^a-z0-9 -]/', '', strtolower(strip_tags($heading))
			) ?? '', ' -') === ''
				? ''
				: str_replace(' ', '-', trim((string)preg_replace(
					'/[^a-z0-9 -]/', '', strtolower($heading)
				))),
			$matches[1]
		);
	}

	// Social: video conversion

	private function videoCheck(bool $ffmpeg, bool $switchedOn, bool $ffprobe = true): VideoConverter {
		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValueBool')->willReturn($switchedOn);
		$transcode = $this->createStub(VideoTranscodeService::class);
		$transcode->method('isAvailable')->willReturn($ffmpeg);
		$transcode->method('canProbe')->willReturn($ffprobe);

		return new VideoConverter($this->l10n, $config, $transcode);
	}

	/**
	 * Without ffmpeg nothing says so: the upload works, the video plays in
	 * Safari here, and it never arrives anywhere that matters.
	 */
	public function testNoFfmpegIsAWarningThatSaysWhatWillNotPlay(): void {
		$result = $this->videoCheck(false, true)->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('iPhone .mov videos and HEVC videos will not play on other servers', $result->getDescription());
		$this->assertStringContainsString('Chrome and Firefox', $result->getDescription());
		$this->assertSame(VideoConverter::DOC, $result->getLinkToDoc());
	}

	/** Switched off with ffmpeg present is a decision, and said as one. */
	public function testConversionSwitchedOffIsInformationNotAWarning(): void {
		$result = $this->videoCheck(true, false)->run();

		$this->assertSame(SetupResult::INFO, $result->getSeverity());
		$this->assertStringContainsString('switched off', $result->getDescription());
	}

	public function testConversionThatWorksIsQuiet(): void {
		$this->assertSame(SetupResult::SUCCESS, $this->videoCheck(true, true)->run()->getSeverity());
	}

	/** Without ffprobe an MP4's codec is unknown, which the success says. */
	public function testConversionWithoutFfprobeSaysMp4sAreLeftAlone(): void {
		$result = $this->videoCheck(true, true, false)->run();

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
		$this->assertStringContainsString('ffprobe was not found', $result->getDescription());
	}

	// Social: upload size

	private function uploadCheck(int $megabytes): UploadLimitsAgree {
		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValueInt')->willReturn($megabytes);

		return new UploadLimitsAgree($this->l10n, $config);
	}

	/**
	 * The app's own ceiling is in `/api/v1/instance` and in the composer's
	 * refusal; PHP's is enforced before a byte reaches this app's code. When
	 * the app's is the larger one, an upload between them is refused with
	 * nothing in the log and nothing useful said to the person.
	 */
	public function testAnUploadCeilingPhpWillNotHonourIsAWarning(): void {
		$php = $this->bytes((string)ini_get('upload_max_filesize'));
		$this->assertGreaterThan(0, $php, 'this test needs PHP to have a limit at all');

		$result = $this->uploadCheck((int)ceil($php / 1024 / 1024) + 64)->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('refused before this app can say why', $result->getDescription());
	}

	public function testAgreeingLimitsAreQuiet(): void {
		$result = $this->uploadCheck(1)->run();

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}

	private function bytes(string $value): int {
		$number = (int)$value;

		return match (strtolower(substr(trim($value), -1))) {
			'g' => $number * 1024 * 1024 * 1024,
			'm' => $number * 1024 * 1024,
			'k' => $number * 1024,
			default => $number,
		};
	}

	// Social: reachable by other servers

	private function reachCheck(string $socialUrl): ReachableByStrictPeers {
		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValue')->willReturn($socialUrl);

		return new ReachableByStrictPeers($this->l10n, $config);
	}

	/**
	 * Federation does not fail all at once: a plain-HTTP instance federates
	 * with a permissive peer and is refused at the first gate by Pixelfed,
	 * which is the network a photo server most wants to reach.
	 */
	public function testPlainHttpIsAWarningThatNamesWhoRefusesIt(): void {
		$result = $this->reachCheck('http://cloud.example.org/apps/social/')->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('plain HTTP', $result->getDescription());
		$this->assertStringContainsString('Pixelfed', $result->getDescription());
	}

	public function testAPrivateAddressIsAWarningToo(): void {
		$result = $this->reachCheck('https://192.168.1.10/apps/social/')->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('private address', $result->getDescription());
	}

	public function testAnInstanceThatHasNotBeenSetUpIsNotToldItIsBroken(): void {
		$this->assertSame(SetupResult::INFO, $this->reachCheck('')->run()->getSeverity());
	}
}
