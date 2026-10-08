<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\ConfigService;
use OCP\Config\Exceptions\TypeConflictException;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ConfigServiceTest extends TestCase {
	private IAppConfig|MockObject $appConfig;
	private IUserConfig|MockObject $userConfig;
	private IConfig|MockObject $config;
	private IURLGenerator|MockObject $urlGenerator;
	private ConfigService $service;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->userConfig = $this->createMock(IUserConfig::class);
		$this->config = $this->createMock(IConfig::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->service = new ConfigService(
			'alice',
			$this->appConfig,
			$this->userConfig,
			$this->config,
			$this->urlGenerator,
		);
	}

	/** Serve app values for the 'social' app from a map, falling back to the requested default. */
	private function withAppValues(array $values): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(function (string $app, string $key, string $default) use ($values) {
				$this->assertSame('social', $app);

				return $values[$key] ?? $default;
			});
	}

	public function testGetAppValuePassesTheKnownDefault(): void {
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('social', ConfigService::SOCIAL_MAX_SIZE, '10')
			->willReturn('20');

		$this->assertSame('20', $this->service->getAppValue(ConfigService::SOCIAL_MAX_SIZE));
	}

	/**
	 * Pixelfed's inbox handles a `Create` only for a `Note`; a `Video` is
	 * dropped without a word. Publishing videos in PeerTube's shape is
	 * therefore something an administrator turns on knowing who reads it,
	 * not something every instance does to every follower.
	 */
	/**
	 * A notification is read once from the bell; ninety days keeps a quarter
	 * of history for a user who was away and lets the table stop growing
	 * everywhere else, with or without status retention.
	 */
	public function testNotificationsAreKeptForNinetyDaysByDefault(): void {
		$this->assertSame('90', $this->service->defaults[ConfigService::SOCIAL_NOTIFICATION_RETENTION_DAYS]);
	}

	public function testVideosGoOutAsNotesUnlessAnAdministratorDecidesOtherwise(): void {
		$this->assertSame('0', $this->service->defaults[ConfigService::SOCIAL_PUBLISH_VIDEO]);
	}

	/**
	 * Pixelfed takes `video/mp4` and nothing else, and drops a `.mov` on
	 * arrival: an instance that never chose converts its videos, which does
	 * nothing where there is no ffmpeg. The ladder is several encodes per
	 * video and stays something an administrator asks for.
	 */
	public function testVideosAreConvertedUnlessAnAdministratorSaysOtherwiseButTheLadderIsNot(): void {
		$this->withAppValues([]);

		$this->assertTrue($this->service->getAppValueBool(ConfigService::SOCIAL_VIDEO_TRANSCODE));
		$this->assertFalse($this->service->getAppValueBool(ConfigService::SOCIAL_VIDEO_LADDER));
	}

	public function testAnAdministratorWhoSwitchedConversionOffKeepsItOff(): void {
		$this->withAppValues([ConfigService::SOCIAL_VIDEO_TRANSCODE => '0']);

		$this->assertFalse($this->service->getAppValueBool(ConfigService::SOCIAL_VIDEO_TRANSCODE));
	}

	public function testGetAppValueHasAnEmptyDefaultForUnknownKey(): void {
		// IAppConfig is typed, so a key with no default of its own asks for ''
		// where the old untyped IConfig call passed null and could hand one back
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('social', 'installed_version', '')
			->willReturn('0.9.0');

		$this->assertSame('0.9.0', $this->service->getAppValue('installed_version'));
	}

	public function testGetAppValueIntCastsTheStoredString(): void {
		$this->withAppValues([ConfigService::SOCIAL_SERVICE => '3']);

		$this->assertSame(3, $this->service->getAppValueInt(ConfigService::SOCIAL_SERVICE));
		$this->assertSame(10, $this->service->getAppValueInt(ConfigService::SOCIAL_MAX_SIZE));
	}

	public function testGetConfigReturnsEveryKnownKeyWithItsDefault(): void {
		$this->withAppValues([ConfigService::CLOUD_URL => 'https://cloud.example.com']);

		$config = $this->service->getConfig();

		$this->assertSame(array_keys($this->service->defaults), array_keys($config));
		$this->assertSame('https://cloud.example.com', $config[ConfigService::CLOUD_URL]);
		$this->assertSame('all_but', $config[ConfigService::SOCIAL_ACCESS_TYPE]);
		$this->assertSame('[]', $config[ConfigService::SOCIAL_ACCESS_LIST]);
		$this->assertSame('0', $config[ConfigService::SOCIAL_SELF_SIGNED]);
	}

	public function testSetAndDeleteAppValueTargetTheSocialApp(): void {
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('social', ConfigService::SOCIAL_ADDRESS, 'social.example.com');
		$this->appConfig->expects($this->once())
			->method('deleteKey')
			->with('social', ConfigService::SOCIAL_ADDRESS);
		$this->appConfig->expects($this->once())
			->method('deleteApp')
			->with('social');

		$this->service->setAppValue(ConfigService::SOCIAL_ADDRESS, 'social.example.com');
		$this->service->deleteAppValue(ConfigService::SOCIAL_ADDRESS);
		$this->service->unsetAppConfig();
	}

	public function testGetUserValueFallsBackToSessionUserAndKnownDefault(): void {
		$this->userConfig->expects($this->once())
			->method('getValueString')
			->with('alice', 'social', ConfigService::SOCIAL_MAX_SIZE, '10')
			->willReturn('5');

		$this->assertSame('5', $this->service->getUserValue(ConfigService::SOCIAL_MAX_SIZE));
	}

	public function testGetUserValueWithoutAUserAnswersTheDefault(): void {
		// a bearer-token request has no session, so there is nobody to read a
		// per-user value for; the backend takes a string and fatals on null
		$service = new ConfigService(
			null,
			$this->appConfig,
			$this->userConfig,
			$this->config,
			$this->urlGenerator,
		);

		$this->userConfig->expects($this->never())->method('getValueString');

		$this->assertSame('10', $service->getUserValue(ConfigService::SOCIAL_MAX_SIZE));
		$this->assertSame('', $service->getUserValue('version', '', 'avatar'));
	}

	public function testGetUserValueForAnotherAppHasEmptyDefault(): void {
		$this->userConfig->expects($this->once())
			->method('getValueString')
			->with('bob', 'avatar', 'version', '')
			->willReturn('3');

		$this->assertSame('3', $this->service->getUserValue('version', 'bob', 'avatar'));
	}

	public function testUserValuesAreWrittenForTheRightUser(): void {
		$written = [];
		$this->userConfig->expects($this->exactly(2))
			->method('setValueString')
			->willReturnCallback(function (...$args) use (&$written): bool {
				$written[] = $args;

				return true;
			});
		$this->userConfig->expects($this->once())
			->method('getValueString')
			->with('bob', 'social', 'key')
			->willReturn('other');

		$this->service->setUserValue('key', 'value');
		$this->service->setValueForUser('bob', 'key', 'other');
		$this->assertSame('other', $this->service->getValueForUser('bob', 'key'));
		// IUserConfig::setValueString takes no $preCondition, so the service
		// passes exactly what it means to write. The trailing false is the
		// interface's own `lazy` and `flags` defaults, which the service never
		// sets.
		$this->assertSame([
			['alice', 'social', 'key', 'value', false, 0],
			['bob', 'social', 'key', 'other', false, 0],
		], $written);
	}

	public function testCoreValuesUseTheCoreAppNamespace(): void {
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('core', 'public_webfinger', 'social/webfinger');
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('core', 'public_webfinger', '')
			->willReturn('social/webfinger');
		$this->appConfig->expects($this->once())
			->method('deleteKey')
			->with('core', 'public_webfinger');

		$this->service->setCoreValue('public_webfinger', 'social/webfinger');
		$this->assertSame('social/webfinger', $this->service->getCoreValue('public_webfinger'));
		$this->service->unsetCoreValue('public_webfinger');
	}

	public function testGetSystemValueDefaultsToEmptyString(): void {
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('overwrite.cli.url', '')
			->willReturn('https://cloud.example.com');

		$this->assertSame('https://cloud.example.com', $this->service->getSystemValue('overwrite.cli.url'));
	}

	/** @return array<string, array{string, bool, string}> */
	public static function cloudUrlProvider(): array {
		return [
			'plain' => ['https://cloud.example.com', false, 'https://cloud.example.com'],
			'trailing slash removed' => ['https://cloud.example.com/', false, 'https://cloud.example.com'],
			'index.php kept by default' => ['https://cloud.example.com/index.php', false, 'https://cloud.example.com/index.php'],
			'index.php stripped' => ['https://cloud.example.com/index.php', true, 'https://cloud.example.com'],
			'subfolder install keeps folder' => ['https://cloud.example.com/nextcloud/index.php', true, 'https://cloud.example.com/nextcloud'],
			'subfolder with trailing slash' => ['https://cloud.example.com/nextcloud/index.php/', false, 'https://cloud.example.com/nextcloud/index.php'],
			'port survives' => ['http://localhost:8080/index.php', true, 'http://localhost:8080'],
		];
	}

	#[DataProvider('cloudUrlProvider')]
	public function testGetCloudUrl(string $stored, bool $noPhp, string $expected): void {
		$this->withAppValues([ConfigService::CLOUD_URL => $stored]);

		$this->assertSame($expected, $this->service->getCloudUrl($noPhp));
	}

	public function testGetCloudUrlThrowsWhenUnconfigured(): void {
		$this->withAppValues([]);

		$this->expectException(SocialAppConfigException::class);
		$this->service->getCloudUrl();
	}

	public function testGetCloudHostIsTheHostOfTheCloudUrl(): void {
		$this->withAppValues([ConfigService::CLOUD_URL => 'https://cloud.example.com/nextcloud/index.php']);

		$this->assertSame('cloud.example.com', $this->service->getCloudHost());
	}

	public function testGetCloudHostDropsThePort(): void {
		$this->withAppValues([ConfigService::CLOUD_URL => 'http://localhost:8080']);

		$this->assertSame('localhost', $this->service->getCloudHost());
	}

	/** @return array<string, array{string, string}> */
	public static function cloudAuthorityProvider(): array {
		return [
			'no port' => ['https://cloud.example.com/nextcloud/index.php', 'cloud.example.com'],
			'non-default port' => ['https://cloud.example.com:8443/', 'cloud.example.com:8443'],
			'default https port' => ['https://cloud.example.com:443/', 'cloud.example.com'],
			'default http port' => ['http://localhost:80', 'localhost'],
			'http on another port' => ['http://localhost:8080', 'localhost:8080'],
		];
	}

	#[DataProvider('cloudAuthorityProvider')]
	public function testGetCloudAuthorityKeepsANonDefaultPort(string $stored, string $expected): void {
		$this->withAppValues([ConfigService::CLOUD_URL => $stored]);

		$this->assertSame($expected, $this->service->getCloudAuthority());
	}

	public function testGetCloudHostThrowsWhenUnconfigured(): void {
		$this->withAppValues([]);

		$this->expectException(SocialAppConfigException::class);
		$this->service->getCloudHost();
	}

	public function testSetCloudUrlAddsAMissingScheme(): void {
		$written = [];
		$this->appConfig->expects($this->exactly(2))
			->method('setValueString')
			->willReturnCallback(function (...$args) use (&$written): bool {
				$written[] = $args;

				return true;
			});

		$this->service->setCloudUrl('cloud.example.com');
		$this->service->setCloudUrl('https://cloud.example.com/nextcloud');
		// the two trailing false are IAppConfig's `lazy` and `sensitive`
		// defaults, which the service never sets
		$this->assertSame([
			['social', ConfigService::CLOUD_URL, 'http://cloud.example.com', false, false],
			['social', ConfigService::CLOUD_URL, 'https://cloud.example.com/nextcloud', false, false],
		], $written);
	}

	public function testGetSocialAddressPrefersTheConfiguredAddress(): void {
		$this->withAppValues([
			ConfigService::SOCIAL_ADDRESS => 'social.example.com',
			ConfigService::CLOUD_URL => 'https://cloud.example.com',
		]);

		$this->assertSame('social.example.com', $this->service->getSocialAddress());
	}

	public function testGetSocialAddressFallsBackToTheCloudHost(): void {
		$this->withAppValues([ConfigService::CLOUD_URL => 'https://cloud.example.com/index.php']);

		$this->assertSame('cloud.example.com', $this->service->getSocialAddress());
	}

	public function testGetSocialAddressThrowsWhenNothingIsConfigured(): void {
		$this->withAppValues([]);

		$this->expectException(SocialAppConfigException::class);
		$this->service->getSocialAddress();
	}

	public function testSetSocialAddress(): void {
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('social', ConfigService::SOCIAL_ADDRESS, 'social.example.com');

		$this->service->setSocialAddress('social.example.com');
	}

	public function testGetSocialUrl(): void {
		$this->withAppValues([ConfigService::SOCIAL_URL => 'https://cloud.example.com/apps/social/']);

		$this->assertSame('https://cloud.example.com/apps/social/', $this->service->getSocialUrl());
	}

	public function testGetSocialUrlThrowsWhenUnconfigured(): void {
		$this->withAppValues([]);

		$this->expectException(SocialAppConfigException::class);
		$this->service->getSocialUrl();
	}

	/**
	 * From the cloud address, not from the request: the request that finds
	 * `social_url` empty may have arrived through an internal name or a proxy
	 * that drops the scheme, and whatever it says is inside every id minted
	 * from then on.
	 */
	public function testSetSocialUrlDerivesItFromTheCloudAddressAndNotTheRequest(): void {
		$this->withAppValues([ConfigService::CLOUD_URL => 'https://cloud.example.com/nextcloud/index.php']);
		// the route as this request's own URL generator writes it
		$this->urlGenerator->method('linkToRoute')
			->with('social.Navigation.navigate')
			->willReturn('/internal/index.php/apps/social/');
		$this->urlGenerator->expects($this->never())->method('getAbsoluteURL');
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('social', ConfigService::SOCIAL_URL, 'https://cloud.example.com/nextcloud/index.php/apps/social/');

		$this->service->setSocialUrl();
	}

	public function testTheIndexPhpOfTheSocialUrlIsTheCloudAddresss(): void {
		$this->withAppValues([ConfigService::CLOUD_URL => 'https://cloud.example.com/']);
		$this->urlGenerator->method('linkToRoute')->willReturn('/index.php/apps/social/');

		$this->assertSame('https://cloud.example.com/apps/social/', $this->service->derivedSocialUrl());
	}

	public function testNoSocialUrlIsStoredBeforeThereIsACloudAddress(): void {
		$this->withAppValues([]);
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->service->setSocialUrl();
	}

	public function testSetSocialUrlAddsAMissingScheme(): void {
		$this->urlGenerator->expects($this->never())->method('linkToRoute');
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('social', ConfigService::SOCIAL_URL, 'http://cloud.example.com/apps/social/');

		$this->service->setSocialUrl('cloud.example.com/apps/social/');
	}

	public function testGenerateIdWithoutRandomPartIsTheSocialUrlPlusPath(): void {
		$this->withAppValues([ConfigService::SOCIAL_URL => 'https://cloud.example.com/apps/social/']);

		$this->assertSame(
			'https://cloud.example.com/apps/social/users/alice/',
			$this->service->generateId('/users/alice', false),
		);
	}

	public function testGenerateIdAppendsTimestampAndChecksum(): void {
		$this->withAppValues([ConfigService::SOCIAL_URL => 'https://cloud.example.com/apps/social/']);

		$before = time();
		$id = $this->service->generateId('documents/avatar');

		$this->assertMatchesRegularExpression(
			'#^https://cloud\.example\.com/apps/social/documents/avatar/(\d{10})(\d+)$#',
			$id,
		);
		preg_match('#/avatar/(\d{10})#', $id, $m);
		$this->assertGreaterThanOrEqual($before, (int)$m[1]);
		$this->assertNotSame($id, $this->service->generateId('documents/avatar'));
	}

	public function testTheRandomHalfOfAnIdIsNotDerivedFromTheClock(): void {
		// crc32(uniqid()) left about a million candidates per second to
		// enumerate offline; the same seed must not reproduce the value
		$this->withAppValues([ConfigService::SOCIAL_URL => 'https://cloud.example.com/apps/social/']);

		mt_srand(7);
		$first = $this->service->generateId('documents/avatar');
		mt_srand(7);

		$this->assertNotSame($first, $this->service->generateId('documents/avatar'));
	}

	public function testGenerateIdRequiresTheSocialUrl(): void {
		$this->withAppValues([]);

		$this->expectException(SocialAppConfigException::class);
		$this->service->generateId('/users/alice');
	}

	public function testWithRequestTimeoutBoundsTheRequestsMadeInside(): void {
		$this->withAppValues([]);

		$returned = $this->service->withRequestTimeout(3, function (): string {
			$this->assertSame(3, $this->service->requestOptions()['timeout']);

			return 'done';
		});

		$this->assertSame('done', $returned);
	}

	public function testABoundedCallOverridesWhatTheCallerAskedFor(): void {
		$this->withAppValues([]);

		$this->service->withRequestTimeout(3, function (): void {
			$this->assertSame(3, $this->service->requestOptions(30)['timeout']);
		});
	}

	public function testReachingAPeerCanBeBudgetedApartFromReadingItsAnswer(): void {
		// one number for both meant DNS+TCP+TLS and the peer rendering its actor
		// document had to share a deadline a slow-but-honest instance could not meet
		$this->withAppValues([]);

		$this->service->withRequestTimeout(10, function (): void {
			$options = $this->service->requestOptions();
			$this->assertSame(10, $options['timeout']);
			$this->assertSame(5, $options['connect_timeout']);
		}, 5);
	}

	public function testAConnectBudgetIsAlsoLiftedAfterTheCall(): void {
		$this->withAppValues([]);

		$this->service->withRequestTimeout(10, fn () => null, 5);

		$options = $this->service->requestOptions(7);
		$this->assertSame(ConfigService::DEFAULT_CONNECT_TIMEOUT, $options['connect_timeout'], 'no separate budget was asked for');
	}

	public function testReachingAPeerHasAShorterBudgetThanReadingItsAnswer(): void {
		// a dead host must not cost the whole read timeout before it counts as failed
		$this->withAppValues([]);

		$options = $this->service->requestOptions(30);
		$this->assertSame(30, $options['timeout']);
		$this->assertSame(ConfigService::DEFAULT_CONNECT_TIMEOUT, $options['connect_timeout']);
		$this->assertLessThanOrEqual(5, ConfigService::DEFAULT_CONNECT_TIMEOUT);
	}

	public function testTheConnectBudgetNeverExceedsTheRequestTimeout(): void {
		$this->withAppValues([]);

		$this->assertSame(2, $this->service->requestOptions(2)['connect_timeout']);
	}

	public function testTheTimeoutOverrideLastsOnlyForThatCall(): void {
		$this->withAppValues([]);

		$this->service->withRequestTimeout(3, fn () => null);

		$this->assertSame(
			ConfigService::DEFAULT_REQUEST_TIMEOUT,
			$this->service->requestOptions()['timeout']
		);
	}

	public function testTheTimeoutOverrideIsLiftedEvenWhenTheCallThrows(): void {
		$this->withAppValues([]);

		try {
			$this->service->withRequestTimeout(3, function (): void {
				throw new \RuntimeException('boom');
			});
			$this->fail('expected the exception to surface');
		} catch (\RuntimeException $e) {
		}

		$this->assertSame(
			ConfigService::DEFAULT_REQUEST_TIMEOUT,
			$this->service->requestOptions()['timeout']
		);
	}

	public function testRequestOptionsAsksForActivityPubOnGet(): void {
		$this->assertSame(
			['Accept' => 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"'],
			$this->service->activityPubHeaders('get')
		);
	}

	public function testRequestOptionsAnnouncesActivityPubOnPost(): void {
		$this->assertSame(['Content-Type' => 'application/activity+json'], $this->service->activityPubHeaders('post'));
	}

	/** Nothing else this app sends carries an ActivityPub content type. */
	public function testNoOtherMethodCarriesActivityPubHeaders(): void {
		$this->assertSame([], $this->service->activityPubHeaders('put'));
		$this->assertSame([], $this->service->activityPubHeaders('delete'));
	}

	public function testRequestOptionsKeepLocalAddressesOffByDefault(): void {
		$this->config->method('getSystemValueBool')
			->with('allow_local_remote_servers', false)->willReturn(false);

		$this->assertFalse($this->service->requestOptions()['nextcloud']['allow_local_address']);
	}

	public function testRequestOptionsAllowLocalAddressesWhenTheInstanceOptsIn(): void {
		$this->config->method('getSystemValueBool')
			->with('allow_local_remote_servers', false)->willReturn(true);

		$this->assertTrue($this->service->requestOptions()['nextcloud']['allow_local_address']);
	}

	public function testRequestOptionsVerifyThePeerUnlessSelfSignedIsAllowed(): void {
		$this->withAppValues([]);

		$this->assertArrayNotHasKey('verify', $this->service->requestOptions());
	}

	public function testRequestOptionsDisablePeerVerificationForSelfSigned(): void {
		$this->withAppValues([ConfigService::SOCIAL_SELF_SIGNED => '1']);

		$this->assertFalse($this->service->requestOptions()['verify']);
	}

	/**
	 * Nextcloud 32 gave stored settings a type, and the avatar app's `version`
	 * is a number. Read as text it threw — out of `createActor()`, so making
	 * an account answered 500 and every request needing an actor answered 401
	 * after it.
	 */
	public function testANumberAnotherAppStoredIsReadAsANumber(): void {
		$this->userConfig->method('getValueInt')
			->with('alice', 'avatar', 'version')
			->willReturn(7);
		$this->userConfig->expects($this->never())->method('getValueString');

		$this->assertSame(7, $this->service->getUserValueInt('version', 'alice', 'avatar'));
	}

	/**
	 * And the same value is text on an older server, where asking for the
	 * number throws instead. Both are live: this app supports three Nextcloud
	 * versions at a time and the owning app decides when it changes.
	 */
	public function testAValueStoredAsTextIsStillRead(): void {
		$this->userConfig->method('getValueInt')
			->willThrowException(new TypeConflictException());
		$this->userConfig->method('getValueString')
			->with('alice', 'avatar', 'version')
			->willReturn('7');

		$this->assertSame(7, $this->service->getUserValueInt('version', 'alice', 'avatar'));
	}

	/** A value that is neither is worth no exception out of a page load. */
	public function testAValueThatCanBeReadAsNeitherIsZero(): void {
		$this->userConfig->method('getValueInt')->willThrowException(new TypeConflictException());
		$this->userConfig->method('getValueString')->willThrowException(new TypeConflictException());

		$this->assertSame(0, $this->service->getUserValueInt('version', 'alice', 'avatar'));
	}

	/** A bearer request has no session, so there is nobody to read one for. */
	public function testNobodyHasNoSettings(): void {
		$this->userConfig->expects($this->never())->method('getValueInt');

		$this->assertSame(0, $this->service->getUserValueInt('version', '', 'avatar'));
	}
}
