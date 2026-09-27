<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use Exception;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CheckServiceTest extends TestCase {
	private IUserManager|MockObject $userManager;
	private ICache|MockObject $cache;
	private IConfig|MockObject $config;
	private IClient|MockObject $client;
	private IRequest|MockObject $request;
	private IURLGenerator|MockObject $urlGenerator;
	private FollowsRequest|MockObject $followRequest;
	private ActorsRequest|MockObject $actorsRequest;
	private CacheActorsRequest|MockObject $cacheActorsRequest;
	private StreamRequest|MockObject $streamRequest;
	private AccountService|MockObject $accountService;
	private MiscService|MockObject $miscService;
	private ConfigService|MockObject $configService;
	private CheckService $service;
	private ICacheFactory|MockObject $cacheFactory;
	private IClientService|MockObject $clientService;

	protected function setUp(): void {
		$this->userManager = $this->createMock(IUserManager::class);
		$this->cache = $this->createMock(ICache::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache);
		$this->config = $this->createMock(IConfig::class);
		$this->client = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);
		$this->request = $this->createMock(IRequest::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->followRequest = $this->createMock(FollowsRequest::class);
		// The instance has one account, whose handle is deliberately not the
		// user id the service below is built for: a handle is chosen at setup
		// and the two need not match. Tests that want an instance with no
		// account at all rebuild this.
		$this->actorsRequest = $this->actorsHolding('wanderer');
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->miscService = $this->createMock(MiscService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->cacheFactory = $cacheFactory;
		$this->clientService = $clientService;

		$this->buildService();
	}

	/** An actors table holding one account with that handle, or none. */
	private function actorsHolding(?string $handle): ActorsRequest|MockObject {
		$actors = $this->createMock(ActorsRequest::class);
		$actor = null;
		if ($handle !== null) {
			$actor = new Person();
			$actor->setPreferredUsername($handle);
		}
		$actors->method('getAny')->willReturn($actor);

		return $actors;
	}

	private function buildService(): void {
		$this->service = new CheckService(
			$this->userManager,
			'alice',
			$this->cacheFactory,
			$this->config,
			$this->clientService,
			$this->request,
			$this->urlGenerator,
			$this->followRequest,
			$this->actorsRequest,
			$this->cacheActorsRequest,
			$this->streamRequest,
			$this->accountService,
			$this->configService,
			$this->miscService,
		);
	}

	private function response(int $status): IResponse|MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);

		return $response;
	}

	/**
	 * A setup check runs with nobody logged in, and the cache the server hands
	 * out for `ICache` is the *user's* file cache: it throws
	 * `ForbiddenException` without a session. The WebFinger check crashed in
	 * exactly the place it exists to report from, so the cache has to be one
	 * that belongs to the instance.
	 */
	public function testTheWellKnownAnswerIsRememberedInstanceWideNotPerUser(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->expects($this->once())->method('createDistributed')
			->with($this->stringContains('social'))
			->willReturn($this->createMock(ICache::class));

		new CheckService(
			$this->userManager,
			'alice',
			$factory,
			$this->config,
			$this->createMock(IClientService::class),
			$this->request,
			$this->urlGenerator,
			$this->followRequest,
			$this->actorsRequest,
			$this->cacheActorsRequest,
			$this->streamRequest,
			$this->accountService,
			$this->configService,
			$this->miscService,
		);
	}

	public function testCheckWellKnownTrustsTheCache(): void {
		$this->cache->method('get')->with(CheckService::CACHE_PREFIX . 'wellknown')->willReturn('true');
		$this->client->expects($this->never())->method('get');

		$this->assertTrue($this->service->checkWellKnown());
	}

	public function testCheckWellKnownProbesTheConfiguredAddressFirstAndCachesSuccess(): void {
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('https://social.example.com');
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => $key === 'social.checkssl' ? false : $default
		);
		$this->client->expects($this->once())
			->method('get')
			->with(
				'https://social.example.com/.well-known/webfinger?resource=acct:alice@social.example.com',
				['nextcloud' => ['allow_local_address' => false], 'verify' => false],
			)
			->willReturn($this->response(200));
		$this->cache->expects($this->once())->method('set')->with(CheckService::CACHE_PREFIX . 'wellknown', 'true', 3600);

		$this->assertTrue($this->service->checkWellKnown());
	}

	/**
	 * The probe used to read an app value named `address`, which nothing ever
	 * wrote — the app stores `social_address` — so the branch was dead and
	 * every probe started from the request's own Host header, which is not
	 * where a remote server looks.
	 */
	public function testTheProbeStartsAtTheAddressTheAppIsSetUpFor(): void {
		$this->cache->method('get')->willReturn(null);
		// a bare host, which is what social_address holds
		$this->configService->method('getSocialAddress')->willReturn('social.example.com');
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example.com/index.php');
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => $key === 'social.checkssl' ? false : $default
		);
		$this->client->expects($this->once())
			->method('get')
			->with(
				'https://social.example.com/.well-known/webfinger?resource=acct:alice@social.example.com',
				['nextcloud' => ['allow_local_address' => false], 'verify' => false],
			)
			->willReturn($this->response(200));

		$this->assertTrue($this->service->checkWellKnown());
	}

	/**
	 * A setup check runs for an administrator who may never have opened the
	 * app, so it passes an account that is known to exist rather than the
	 * viewer's — a probe for a username with no actor answers 404 however
	 * well the redirects are set up.
	 */
	public function testTheProbeCanBeAskedAboutAnAccountOtherThanTheViewers(): void {
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('https://social.example.com');
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => $default
		);
		$this->client->expects($this->once())
			->method('get')
			->with($this->stringContains('resource=acct:bob@social.example.com'))
			->willReturn($this->response(200));

		$this->assertTrue($this->service->checkWellKnown('bob'));
	}

	public function testOnlyTheAdminsOwnAddressMayResolveLocally(): void {
		// one of the candidates is built from the Host header, so reaching a
		// local address must be limited to the URL the admin configured
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('http://localhost');
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => match ($key) {
				'overwrite.cli.url' => 'http://localhost',
				'social.checkssl' => false,
				default => $default,
			}
		);
		$this->client->expects($this->once())
			->method('get')
			->with(
				'http://localhost/.well-known/webfinger?resource=acct:alice@localhost',
				['nextcloud' => ['allow_local_address' => true], 'verify' => false],
			)
			->willReturn($this->response(200));

		$this->assertTrue($this->service->checkWellKnown());
	}

	public function testTheHostHeaderCandidateNeverReachesALocalAddress(): void {
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('');
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => match ($key) {
				'overwrite.cli.url' => 'https://cloud.example.com',
				'social.checkssl' => false,
				default => $default,
			}
		);
		$this->request->method('getServerProtocol')->willReturn('http');
		$this->request->method('getServerHost')->willReturn('127.0.0.1');
		$this->urlGenerator->method('getBaseUrl')->willReturn('http://127.0.0.1');
		$this->client->method('get')->willReturnCallback(
			function (string $url, array $options): IResponse {
				$this->assertFalse($options['nextcloud']['allow_local_address']);

				return $this->response(404);
			}
		);

		$this->assertFalse($this->service->checkWellKnown());
	}

	public function testANonWebAddressIsNotProbedAtAll(): void {
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('file:///etc');
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => $default
		);
		$this->request->method('getServerProtocol')->willReturn('https');
		$this->request->method('getServerHost')->willReturn('cloud.example.com');
		$this->urlGenerator->method('getBaseUrl')->willReturn('https://cloud.example.com');
		$this->client->expects($this->exactly(2))->method('get')->willReturn($this->response(404));

		$this->assertFalse($this->service->checkWellKnown());
	}

	public function testCheckWellKnownFallsBackToTheRequestHostThenTheBaseUrl(): void {
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('');
		$this->config->method('getSystemValue')->willReturn(true);
		$this->request->method('getServerProtocol')->willReturn('https');
		$this->request->method('getServerHost')->willReturn('cloud.example.com');
		$this->urlGenerator->method('getBaseUrl')->willReturn('https://cloud.example.com/nextcloud');
		$probed = [];
		$this->client->expects($this->exactly(2))
			->method('get')
			->willReturnCallback(function (string $url) use (&$probed) {
				$probed[] = $url;

				return $this->response(count($probed) === 1 ? 404 : 200);
			});

		$this->assertTrue($this->service->checkWellKnown());
		$this->assertSame([
			'https://cloud.example.com/.well-known/webfinger?resource=acct:alice@cloud.example.com',
			'https://cloud.example.com/nextcloud/.well-known/webfinger?resource=acct:alice@cloud.example.com',
		], $probed);
	}

	public function testCheckWellKnownFailsWhenEveryProbeFails(): void {
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('');
		$this->request->method('getServerProtocol')->willReturn('http');
		$this->request->method('getServerHost')->willReturn('localhost');
		$this->urlGenerator->method('getBaseUrl')->willReturn('http://localhost');
		$this->client->expects($this->exactly(2))
			->method('get')
			->willReturnCallback(function (string $url) {
				if (str_contains($url, '?resource=acct:alice@localhost') && $url === 'http://localhost/.well-known/webfinger?resource=acct:alice@localhost') {
					throw new Exception('connection refused');
				}

				return $this->response(500);
			});
		$this->cache->expects($this->never())->method('set');

		$this->assertFalse($this->service->checkWellKnown());
	}

	/** The server declares a URL and the app agrees with it. */
	private function addressesAgree(): void {
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => $key === 'overwrite.cli.url' ? 'https://cloud.example' : $default
		);
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example/index.php');
	}

	public function testCheckDefaultReportsTheWellKnownCheck(): void {
		$this->cache->method('get')->willReturn('true');
		$this->addressesAgree();

		$this->assertSame(
			[
				'success' => true,
				'checks' => ['wellknown' => true, 'cloudAddress' => true, 'clientApi' => true],
				'addresses' => [
					'configured' => 'https://cloud.example/index.php',
					'expected' => 'https://cloud.example/index.php',
				],
				// nothing to explain while the check passes
				'clientApi' => [],
			],
			$this->service->checkDefault()
		);
	}

	public function testCheckDefaultFailsWhenACheckFails(): void {
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('');
		$this->addressesAgree();
		$this->client->method('get')->willReturn($this->response(404));

		$result = $this->service->checkDefault();

		$this->assertFalse($result['success']);
		$this->assertFalse($result['checks']['wellknown']);
	}

	// which account the WebFinger probe asks about

	/**
	 * An account that exists, not the reader's Nextcloud user id.
	 *
	 * A handle is chosen when the account is set up and need not match the
	 * user id, and an administrator who has never answered the setup screen
	 * has no account at all — so the probe asked about somebody who does not
	 * exist, and the app told them .well-known was misconfigured when it was
	 * fine. It is the account the WebFinger setup check asks about, so the two
	 * now agree.
	 */
	public function testTheProbeAsksAboutAnAccountThatExists(): void {
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('https://social.example.com');
		$asked = [];
		$this->client->method('get')->willReturnCallback(
			function (string $url) use (&$asked): IResponse {
				$asked[] = $url;

				return $this->response(404);
			}
		);

		$this->service->checkDefault();

		$webfinger = array_values(array_filter(
			$asked,
			static fn (string $url): bool => str_contains($url, 'webfinger')
		));
		$this->assertNotEmpty($webfinger);
		// the handle of the account the instance holds, not 'alice', which is
		// the user id the service was built for — see setUp()
		foreach ($webfinger as $url) {
			$this->assertStringContainsString('acct:wanderer@', $url);
			$this->assertStringNotContainsString('acct:alice@', $url);
		}
	}

	public function testAnInstanceWithNoAccountClaimsNothingAboutWebFinger(): void {
		$this->actorsRequest = $this->actorsHolding(null);
		$this->buildService();
		$this->cache->method('get')->willReturn('true');
		$this->addressesAgree();

		$checks = $this->service->checkDefault();

		// null, not false: nobody has an account yet, so nothing was checked,
		// and an untested check must not report the instance as broken
		$this->assertNull($checks['checks']['wellknown']);
		$this->assertTrue($checks['success']);
	}

	// whether a Mastodon app can reach this instance at all

	public function testTheClientApiCheckTrustsARememberedSuccess(): void {
		$this->cache->method('get')->willReturnCallback(
			static fn (string $key): ?string
				=> $key === CheckService::CACHE_PREFIX . 'clientapi' ? 'true' : null
		);
		$this->client->expects($this->never())->method('get');

		$this->assertTrue($this->service->checkClientApiRoot());
	}

	/**
	 * This check runs on every page of the app an administrator opens, and a
	 * failing one has three addresses to try. Without a remembered answer,
	 * every one of those page loads waits for three HTTP requests that are all
	 * going to fail.
	 */
	public function testTheClientApiCheckTrustsARememberedFailureToo(): void {
		$this->cache->method('get')->willReturnCallback(
			static fn (string $key): ?string
				=> $key === CheckService::CACHE_PREFIX . 'clientapi' ? 'false' : null
		);
		$this->client->expects($this->never())->method('get');

		$this->assertFalse($this->service->checkClientApiRoot());
	}

	public function testAFailingClientApiIsRememberedForFiveMinutes(): void {
		// much shorter than the hour a success is kept: an administrator who
		// has just edited the web server should see the warning go
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('');
		$this->client->method('get')->willReturn($this->response(404));

		// two writes now: what the probe saw, then the failure itself
		$written = [];
		$this->cache->method('set')
			->willReturnCallback(function (string $key, $value, int $ttl) use (&$written): bool {
				$written[$key] = [$value, $ttl];

				return true;
			});

		$this->assertFalse($this->service->checkClientApiRoot());
		$this->assertSame(['false', 300], $written[CheckService::CACHE_PREFIX . 'clientapi']);
	}

	/**
	 * A 200 is not enough. A server that answers the root with the Nextcloud
	 * login page, or with a catch-all index, would otherwise read as a working
	 * client API and the check would say apps can connect when they cannot.
	 */
	public function testAnAnswerThatIsNotAnInstanceDocumentDoesNotCount(): void {
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('https://social.example.com');
		$response = $this->response(200);
		$response->method('getBody')->willReturn('<!DOCTYPE html><title>Log in</title>');
		$this->client->method('get')->willReturn($response);

		$this->assertFalse($this->service->checkClientApiRoot());
	}

	public function testAnInstanceDocumentAtTheRootMeansAppsCanConnect(): void {
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('https://social.example.com');
		$response = $this->response(200);
		$response->method('getBody')->willReturn('{"uri":"social.example.com","title":"Nextcloud Social"}');
		$this->client->expects($this->once())
			->method('get')
			->with('https://social.example.com/api/v1/instance', $this->anything())
			->willReturn($response);
		$this->cache->expects($this->once())
			->method('set')
			->with(CheckService::CACHE_PREFIX . 'clientapi', 'true', 3600);

		$this->assertTrue($this->service->checkClientApiRoot());
	}

	/**
	 * The reason any of this is recorded: rules that are absent and rules whose
	 * proxy target is not this Nextcloud both answer 404 here, and an
	 * administrator who has just pasted the rules cannot tell which they have.
	 */
	public function testAFailedProbeRecordsWhatItSaw(): void {
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('https://social.example.com');
		$this->client->method('get')->willReturn($this->response(404));

		$this->service->checkClientApiRoot();
		$seen = $this->service->clientApiDiagnosis();

		$this->assertNotSame([], $seen);
		$this->assertSame('https://social.example.com', $seen[0]['base']);
		$this->assertSame(404, $seen[0]['status']);
		$this->assertSame('status', $seen[0]['reason']);
	}

	public function testAnAnswerFromSomethingOtherThanThisAppIsRecordedAsSuch(): void {
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('https://social.example.com');
		$response = $this->response(200);
		$response->method('getBody')->willReturn('<!DOCTYPE html><title>Log in</title>');
		$this->client->method('get')->willReturn($response);

		$this->service->checkClientApiRoot();
		$seen = $this->service->clientApiDiagnosis();

		$this->assertSame('not-social', $seen[0]['reason']);
	}

	public function testAServerThatCannotBeReachedAtAllIsRecordedAsThat(): void {
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('https://social.example.com');
		$this->client->method('get')->willThrowException(new \Exception('refused'));

		$this->service->checkClientApiRoot();
		$seen = $this->service->clientApiDiagnosis();

		$this->assertSame('unreachable', $seen[0]['reason']);
		$this->assertSame(0, $seen[0]['status']);
	}

	/** The diagnosis is kept with the failure, so a later page load can show it. */
	public function testWhatTheProbeSawIsRememberedAlongsideTheFailure(): void {
		$held = [];
		$this->cache->method('get')
			->willReturnCallback(function (string $key) use (&$held) {
				return $held[$key] ?? null;
			});
		$this->cache->method('set')
			->willReturnCallback(function (string $key, $value) use (&$held): bool {
				$held[$key] = $value;

				return true;
			});
		$this->configService->method('getSocialAddress')->willReturn('https://social.example.com');
		$this->client->method('get')->willReturn($this->response(404));

		$this->service->checkClientApiRoot();

		$this->assertStringContainsString(
			'"reason":"status"', (string)$held[CheckService::CACHE_PREFIX . 'clientapi_why']
		);
	}

	public function testASuccessfulCheckExplainsNothing(): void {
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('https://social.example.com');
		$response = $this->response(200);
		$response->method('getBody')->willReturn('{"uri":"social.example.com"}');
		$this->client->method('get')->willReturn($response);
		$this->addressesAgree();

		$this->assertSame([], $this->service->checkDefault()['clientApi']);
	}

	/**
	 * The discovery document asks this, and it must never be the thing that
	 * makes an unauthenticated route go and fetch three URLs.
	 */
	public function testAskingWhetherTheRootWorksNeverProbes(): void {
		$this->cache->method('get')->willReturn(null);
		$this->client->expects($this->never())->method('get');

		$this->assertFalse($this->service->clientApiRootIsKnownGood());
	}

	/**
	 * Written down, not only cached: the cache is `createDistributed()`, which
	 * on an instance with none configured is APCu, which the web server and
	 * `occ` do not share -- so the answer used to depend on which process had
	 * last run the check.
	 */
	public function testTheRootIsKnownGoodOnlyWhenTheProbeWroteItDown(): void {
		$stored = '';
		$this->configService->method('getAppValue')
			->willReturnCallback(function (string $key) use (&$stored) {
				return $key === ConfigService::CLIENT_API_ROOT ? $stored : '';
			});

		foreach (['1' => true, '0' => false, '' => false] as $value => $expected) {
			$stored = (string)$value;
			$this->assertSame(
				$expected, $this->service->clientApiRootIsKnownGood(), 'stored: ' . $value
			);
		}
	}

	public function testTheProbeWritesDownWhatItConcluded(): void {
		$written = [];
		$this->configService->method('setAppValue')
			->willReturnCallback(function (string $key, $value) use (&$written): void {
				$written[$key] = (string)$value;
			});
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('https://social.example.com');
		$response = $this->response(200);
		$response->method('getBody')->willReturn('{"uri":"social.example.com"}');
		$this->client->method('get')->willReturn($response);

		$this->service->checkClientApiRoot();

		$this->assertSame('1', $written[ConfigService::CLIENT_API_ROOT]);
	}

	public function testAFailedProbeWritesThatDownToo(): void {
		$written = [];
		$this->configService->method('setAppValue')
			->willReturnCallback(function (string $key, $value) use (&$written): void {
				$written[$key] = (string)$value;
			});
		$this->cache->method('get')->willReturn(null);
		$this->configService->method('getSocialAddress')->willReturn('https://social.example.com');
		$this->client->method('get')->willReturn($this->response(404));

		$this->service->checkClientApiRoot();

		$this->assertSame('0', $written[ConfigService::CLIENT_API_ROOT]);
	}

	// the address the app builds ids from vs. the one the server says it has

	public function testTheAddressCheckPassesWhenTheServerAndTheAppAgree(): void {
		$this->addressesAgree();

		$this->assertTrue($this->service->checkCloudAddress());
	}

	/** @param string $socialUrl what social_url holds */
	private function socialUrlIs(string $socialUrl, string $cloudUrl = 'https://social.example/index.php'): void {
		$this->configService->method('getCloudUrl')->willReturn($cloudUrl);
		$this->configService->method('getAppValue')->willReturnCallback(
			fn (string $key): string => $key === ConfigService::SOCIAL_URL ? $socialUrl : ''
		);
	}

	/**
	 * Ids are minted from `social_url`, which used to be the absolute URL of
	 * the first request that opened the app: behind a proxy that does not
	 * pass the scheme on, or opened through an internal name, it disagrees
	 * with `cloud_url` for good and nothing said so.
	 */
	public function testIdsMintedUnderAnotherHostThanTheCloudAddressAreReported(): void {
		$this->socialUrlIs('http://internal-host:8080/index.php/apps/social/');

		$this->assertFalse($this->service->checkSocialUrl());
	}

	public function testIdsMintedOverAnotherSchemeAreReportedToo(): void {
		$this->socialUrlIs('http://social.example/index.php/apps/social/');

		$this->assertFalse($this->service->checkSocialUrl());
	}

	public function testASocialUrlOnTheCloudAddressIsQuietWhateverItsPath(): void {
		// pretty URLs or not, a web root or not: the path is not what other
		// servers resolve an id's host by
		$this->socialUrlIs('https://SOCIAL.example:443/apps/social/');

		$this->assertTrue($this->service->checkSocialUrl());
	}

	public function testNoSocialUrlYetIsNotADisagreement(): void {
		$this->socialUrlIs('');

		$this->assertTrue($this->service->checkSocialUrl());
	}

	public function testTheAddressCheckFailsOnceTheServersUrlHasMovedOn(): void {
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => $key === 'overwrite.cli.url' ? 'https://new.example' : $default
		);
		// what the app was set up with, and still builds every id from
		$this->configService->method('getCloudUrl')->willReturn('https://old.example/index.php');

		$this->assertFalse($this->service->checkCloudAddress());
	}

	public function testATrailingSlashIsNotADisagreement(): void {
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => $key === 'overwrite.cli.url' ? 'https://cloud.example/' : $default
		);
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example/index.php/');

		$this->assertTrue($this->service->checkCloudAddress());
	}

	public function testAServerThatDeclaresNoUrlIsNotADisagreement(): void {
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => $default
		);
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example/index.php');

		// nothing to compare against; this is not the app's problem to report
		$this->assertTrue($this->service->checkCloudAddress());
	}

	public function testAnAppThatWasNeverSetUpIsNotADisagreement(): void {
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => $key === 'overwrite.cli.url' ? 'https://cloud.example' : $default
		);
		$this->configService->method('getCloudUrl')
			->willThrowException(new SocialAppConfigException());

		// the setup screen handles this one; it is not a mismatch
		$this->assertTrue($this->service->checkCloudAddress());
	}

	public function testTheIndexPhpSuffixFollowsTheFrontController(): void {
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => match ($key) {
				'overwrite.cli.url' => 'https://cloud.example',
				'htaccess.IgnoreFrontController' => true,
				default => $default,
			}
		);

		$this->assertSame('https://cloud.example', $this->service->derivedCloudAddress());
	}

	private function follow(string $id, string $actorId, string $objectId): Follow {
		$follow = new Follow();
		$follow->setId($id);
		$follow->setActorId($actorId);
		$follow->setObjectId($objectId);

		return $follow;
	}

	public function testRemoveInvalidFollowsDropsFollowsWithAnUnknownActorOrObject(): void {
		$known = 'https://cloud.example.com/apps/social/@alice';
		$this->followRequest->method('getAll')->willReturn([
			$this->follow('f1', $known, 'https://remote.example/users/bob'),
			$this->follow('f2', $known, 'https://gone.example/users/x'),
			$this->follow('f3', 'https://gone.example/users/y', $known),
		]);
		$this->cacheActorsRequest->method('getFromId')->willReturnCallback(function (string $id) {
			if (str_starts_with($id, 'https://gone.example/')) {
				throw new CacheActorDoesNotExistException();
			}

			return new Person();
		});
		$deleted = [];
		$this->followRequest->expects($this->exactly(2))
			->method('deleteById')
			->willReturnCallback(function (string $id) use (&$deleted): void {
				$deleted[] = $id;
			});
		$this->miscService->expects($this->once())->method('log')->with('removeInvalidFollows removed 2 entries', 1);

		$this->assertSame(2, $this->service->removeInvalidFollows());
		$this->assertSame(['f2', 'f3'], $deleted);
	}

	public function testRemoveInvalidNotesDropsNotesFromUnknownAuthors(): void {
		$valid = new Note();
		$valid->setId('https://remote.example/notes/1');
		$valid->setAttributedTo('https://remote.example/users/bob');
		$orphan = new Note();
		$orphan->setId('https://gone.example/notes/2');
		$orphan->setAttributedTo('https://gone.example/users/x');
		$this->streamRequest->method('getAll')->with(Note::TYPE)->willReturn([$valid, $orphan]);
		$this->cacheActorsRequest->method('getFromId')->willReturnCallback(function (string $id) {
			if ($id === 'https://gone.example/users/x') {
				throw new CacheActorDoesNotExistException();
			}

			return new Person();
		});
		$this->streamRequest->expects($this->once())->method('deleteById')->with('https://gone.example/notes/2', Note::TYPE);
		$this->miscService->expects($this->once())->method('log')->with('removeInvalidNotes removed 1 entries', 1);

		$this->assertSame(1, $this->service->removeInvalidNotes());
	}

	public function testCheckInstallationStatusRunsRepairsAndLoopbackFollows(): void {
		$this->followRequest->method('getAll')->willReturn([]);
		$this->streamRequest->method('getAll')->willReturn([]);
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$this->userManager->method('search')->with('')->willReturn([$alice, $bob]);
		$actor = new Person();
		$this->accountService->method('getActorFromUserId')->willReturnCallback(function (string $uid) use ($actor) {
			if ($uid === 'bob') {
				throw new ActorDoesNotExistException();
			}

			return $actor;
		});
		$this->followRequest->expects($this->once())->method('generateLoopbackAccount')->with($this->identicalTo($actor));

		$result = $this->service->checkInstallationStatus();

		$this->assertSame(['invalidFollows' => 0, 'invalidNotes' => 0], $result);
	}

	public function testLightInstallationStatusSkipsTheRepairs(): void {
		$this->followRequest->expects($this->never())->method('getAll');
		$this->streamRequest->expects($this->never())->method('getAll');
		$this->userManager->method('search')->willReturn([]);

		$this->assertSame([], $this->service->checkInstallationStatus(true));
	}

	public function testCheckInstallationStatusSurvivesLoopbackFailures(): void {
		$this->userManager->method('search')->willThrowException(new Exception('ldap down'));

		$this->assertSame([], $this->service->checkInstallationStatus(true));
	}

	public function testCheckStatusTableFollowsSeedsAPlaceholderWhenEmpty(): void {
		$this->followRequest->method('countFollows')->willReturn(0);
		$this->followRequest->expects($this->once())
			->method('save')
			->with($this->callback(function (Follow $follow) {
				$this->assertSame('Unknown', $follow->getType());
				$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $follow->getId());
				$this->assertNotSame($follow->getActorId(), $follow->getObjectId());

				return true;
			}));

		$this->service->checkStatusTableFollows();
	}

	public function testCheckStatusTableFollowsLeavesAPopulatedTableAlone(): void {
		$this->followRequest->method('countFollows')->willReturn(5);
		$this->followRequest->expects($this->never())->method('save');

		$this->service->checkStatusTableFollows();
	}
}
