<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\InstancesRequest;
use OCA\Social\Db\InstanceStatsRequest;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Instance;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\TranslationService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class InstanceServiceTest extends TestCase {
	private InstancesRequest|MockObject $instancesRequest;
	private InstanceStatsRequest|MockObject $statsRequest;
	private TranslationService|Stub $translationService;
	private AccountService|MockObject $accountService;
	private ConfigService|Stub $configService;
	private IAppConfig|MockObject $appConfig;
	private IConfig|Stub $config;
	private IUserManager|Stub $userManager;
	private CacheDocumentService|Stub $cacheDocumentService;
	private InstanceService $service;

	protected function setUp(): void {
		$this->instancesRequest = $this->createMock(InstancesRequest::class);
		$this->configService = $this->createStub(ConfigService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->config = $this->createStub(IConfig::class);
		$this->userManager = $this->createStub(IUserManager::class);
		$this->userManager->method('countUsers')->willReturn(['Database' => 3]);
		$this->cacheDocumentService = $this->createStub(CacheDocumentService::class);
		$this->statsRequest = $this->createMock(InstanceStatsRequest::class);
		$this->translationService = $this->createStub(TranslationService::class);
		$this->accountService = $this->createMock(AccountService::class);

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn('/apps/social/img/social.svg');
		$urlGenerator->method('getAbsoluteURL')
			->willReturnCallback(static fn (string $path): string => 'https://cloud.example.org' . $path);

		$this->service = new InstanceService(
			$this->instancesRequest,
			$this->configService,
			$this->appConfig,
			$this->config,
			$urlGenerator,
			$this->userManager,
			$this->cacheDocumentService,
			$this->statsRequest,
			$this->translationService,
			$this->accountService,
		);
	}

	/** Only the mime types the cache service actually accepts. */
	private function allowMimeTypes(array $allowed): void {
		$this->cacheDocumentService->method('filterMimeTypes')
			->willReturnCallback(static function (string $mime) use ($allowed): void {
				if (!in_array($mime, $allowed, true)) {
					throw new CacheContentMimeTypeException();
				}
			});
	}

	private function theming(array $values): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(
				fn (string $app, string $key, $default = '') => $values[$app . '.' . $key] ?? $default
			);
	}

	public function testCreateLocalBuildsTheInstanceFromAppAndThemingConfig(): void {
		$this->theming([
			'social.installed_version' => '0.9.1',
			'theming.slogan' => 'Our slogan',
			'theming.name' => 'Our Cloud',
		]);
		$this->configService->method('getSocialAddress')->willReturn('social.example.org');
		$saved = null;
		$this->instancesRequest->expects($this->once())
			->method('save')
			->willReturnCallback(function (Instance $instance) use (&$saved) {
				$saved = $instance;
			});

		$instance = $this->service->createLocal();

		$this->assertSame($saved, $instance);
		$this->assertTrue($instance->isLocal());
		$this->assertSame('0.9.1', $instance->getVersion());
		$this->assertSame('Our slogan', $instance->getDescription());
		$this->assertSame('Our Cloud', $instance->getTitle());
		$this->assertFalse($instance->isApprovalRequired());
		// the entity used to be saved with an empty uri, which a client reads
		// as "this is not a fediverse server"
		$this->assertSame('social.example.org', $instance->getUri());
	}

	public function testCreateLocalUsesNextcloudDefaultsWhenThemingIsUnset(): void {
		$this->theming([]);

		$instance = $this->service->createLocal();

		$this->assertSame('0.0', $instance->getVersion());
		$this->assertSame('a safe home for your data', $instance->getDescription());
		$this->assertSame('Aloha Social', $instance->getTitle());
		$this->assertSame('Aloha Social', $instance->jsonSerialize()['title']);
	}

	public function testTheUriFallsBackToTheCloudHost(): void {
		$this->theming([]);
		$this->configService->method('getSocialAddress')->willReturn('');
		$this->configService->method('getCloudHost')->willReturn('cloud.example.org');

		$this->assertSame('cloud.example.org', $this->service->createLocal()->getUri());
	}

	public function testGetLocalRefreshesTheStoredRowFromTheLiveConfiguration(): void {
		// the row is written once at install and never updated, so an instance
		// that has been renamed since would keep answering with the old name
		$stored = (new Instance())->setTitle('what we were called in 2019');
		$this->instancesRequest->expects($this->once())
			->method('getLocal')
			->with(ACore::FORMAT_ACTIVITYPUB)
			->willReturn($stored);
		$this->instancesRequest->expects($this->never())->method('save');
		$this->theming(['theming.name' => 'What We Are Called Now']);

		$instance = $this->service->getLocal(ACore::FORMAT_ACTIVITYPUB);

		$this->assertSame($stored, $instance);
		$this->assertSame('What We Are Called Now', $instance->getTitle());
	}

	public function testGetLocalReturnsTheConfiguredCachedContactAccount(): void {
		$stored = (new Instance())->setLocal(true);
		$this->instancesRequest->method('getLocal')->willReturn($stored);
		$this->theming(['social.contact_account' => 'alice']);
		$owner = (new Person())->setUserId('alice')->setPreferredUsername('alice')->setLocal(true);
		$cached = (new Person())->setPreferredUsername('alice')->setLocal(true);
		$this->accountService->expects($this->once())->method('getActorFromUserId')
			->with('alice')->willReturn($owner);
		$this->accountService->expects($this->once())->method('getCachedLocalActor')
			->with('alice')->willReturn($cached);

		$instance = $this->service->getLocal(ACore::FORMAT_LOCAL);

		$this->assertSame($cached, $instance->getContactAccount());
	}

	public function testGetLocalCreatesTheInstanceWhenMissing(): void {
		$this->instancesRequest->method('getLocal')
			->with(ACore::FORMAT_LOCAL)
			->willThrowException(new InstanceDoesNotExistException());
		$this->theming([]);
		$this->instancesRequest->expects($this->once())->method('save');

		$instance = $this->service->getLocal();

		$this->assertTrue($instance->isLocal());
		$this->assertSame('Aloha Social', $instance->getTitle());
	}

	public function testStatsAndUrlsAndConfigurationSerialiseAsObjects(): void {
		$this->theming([]);
		$this->allowMimeTypes(['image/png']);

		$json = json_decode(
			(string)json_encode($this->service->createLocal()->jsonSerialize()), false
		);

		// a client decoding stats.user_count out of a list fails, and reports
		// the whole instance as unreachable
		$this->assertIsObject($json->stats);
		$this->assertIsObject($json->urls);
		$this->assertIsObject($json->configuration);
		$this->assertSame(3, $json->stats->user_count);
		$this->assertSame(0, $json->stats->status_count);
		$this->assertSame(0, $json->stats->domain_count);
	}

	public function testStatusAndDomainCountsAreComputedOnceAndRememberedInAppConfig(): void {
		// /api/v1/instance is the first request every client makes; the two
		// aggregates behind these numbers must not run on every hit
		$this->theming([]);
		$this->statsRequest->expects($this->once())->method('countLocalStatuses')->willReturn(42);
		$this->statsRequest->expects($this->once())->method('countRemoteDomains')->willReturn(7);
		$remembered = null;
		$this->appConfig->expects($this->once())->method('setValueString')
			->with('social', InstanceService::STATS_CACHE_KEY, $this->isString())
			->willReturnCallback(function (string $app, string $key, string $value) use (&$remembered): bool {
				$remembered = json_decode($value, true);

				return true;
			});

		$json = json_decode((string)json_encode($this->service->createLocal()->jsonSerialize()), false);

		$this->assertSame(42, $json->stats->status_count);
		$this->assertSame(7, $json->stats->domain_count);
		$this->assertSame(3, $json->stats->user_count);
		$this->assertSame(42, $this->service->createLocal()->getUsage()['localPosts']);
		$this->assertSame(42, $remembered['status_count']);
		$this->assertSame(7, $remembered['domain_count']);
		$this->assertEqualsWithDelta(time(), $remembered['computed_at'], 5);
	}

	public function testFreshRememberedStatsAreServedWithoutTouchingTheDatabase(): void {
		$this->theming([
			'social.' . InstanceService::STATS_CACHE_KEY => json_encode(
				['status_count' => 5, 'domain_count' => 2, 'computed_at' => time() - 60]
			),
		]);
		$this->statsRequest->expects($this->never())->method('countLocalStatuses');
		$this->statsRequest->expects($this->never())->method('countRemoteDomains');
		$this->appConfig->expects($this->never())->method('setValueString');

		$stats = $this->service->createLocal()->getStats();

		$this->assertSame(5, $stats['status_count']);
		$this->assertSame(2, $stats['domain_count']);
	}

	public function testStaleRememberedStatsAreRecomputed(): void {
		$this->theming([
			'social.' . InstanceService::STATS_CACHE_KEY => json_encode(
				['status_count' => 5, 'domain_count' => 2, 'computed_at' => time() - InstanceService::STATS_CACHE_SECONDS - 1]
			),
		]);
		$this->statsRequest->method('countLocalStatuses')->willReturn(6);
		$this->statsRequest->method('countRemoteDomains')->willReturn(3);
		$this->appConfig->expects($this->once())->method('setValueString');

		$stats = $this->service->createLocal()->getStats();

		$this->assertSame(6, $stats['status_count']);
		$this->assertSame(3, $stats['domain_count']);
	}

	public function testTheConfigurationBlockCarriesTheRealLimits(): void {
		$this->theming([]);
		$this->configService->method('getAppValueInt')->willReturnMap([
			[ConfigService::SOCIAL_MAX_SIZE, 20],
			[ConfigService::SOCIAL_MAX_VIDEO_SIZE, 2048],
		]);
		$this->allowMimeTypes(['image/png', 'video/mp4']);

		$configuration = $this->service->createLocal()->getConfiguration();

		$this->assertSame(
			InstanceService::MAX_CHARACTERS, $configuration['statuses']['max_characters']
		);
		$this->assertSame(
			Stream::MAX_ATTACHMENTS, $configuration['statuses']['max_media_attachments']
		);
		$this->assertSame(
			PostService::POLL_MAX_OPTIONS, $configuration['polls']['max_options']
		);
		// what is advertised is what PostService lets a poll run for
		$this->assertSame(
			PostService::POLL_MIN_EXPIRATION, $configuration['polls']['min_expiration']
		);
		$this->assertSame(
			PostService::POLL_MAX_EXPIRATION, $configuration['polls']['max_expiration']
		);
		$this->assertSame(
			20 * 1048576, $configuration['media_attachments']['image_size_limit']
		);
		// video has a ceiling of its own, and a client reads this one to decide
		// whether to offer the upload at all
		$this->assertSame(
			2048 * 1048576, $configuration['media_attachments']['video_size_limit']
		);
	}

	public function testTheSupportedMimeTypesAreTheOnesTheCacheServiceAccepts(): void {
		// asked of the one place that decides, so the two cannot drift and a
		// client is never offered an upload the server would refuse
		$this->allowMimeTypes(['image/png', 'audio/flac']);

		$this->assertSame(['image/png', 'audio/flac'], $this->service->supportedMimeTypes());
	}

	public function testRulesAreOnePerLineOfTheAppValue(): void {
		$this->theming(['social.rules' => "Be kind.\n\nNo spam.\n"]);

		$this->assertSame(
			[
				['id' => '1', 'text' => 'Be kind.'],
				['id' => '2', 'text' => 'No spam.'],
			],
			$this->service->createLocal()->getRules()
		);
	}

	public function testTheV2EntityReshapesTheSameFacts(): void {
		$this->theming([
			'theming.name' => 'Our Cloud',
			'theming.slogan' => 'Our slogan',
		]);
		$this->configService->method('getSocialAddress')->willReturn('social.example.org');

		$v2 = json_decode((string)json_encode($this->service->createLocal()->asV2()), false);

		$this->assertSame('social.example.org', $v2->domain);
		$this->assertSame('Our Cloud', $v2->title);
		$this->assertSame('Our slogan', $v2->description);
		$this->assertIsObject($v2->configuration);
		$this->assertIsObject($v2->registrations);
		$this->assertFalse($v2->registrations->enabled);
		$this->assertIsObject($v2->contact);
		$this->assertSame(3, $v2->usage->users->active_month);
	}
}
