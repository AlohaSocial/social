<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\InstanceStatsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\DirectoryAccount;
use OCA\Social\Model\Client\DirectorySource;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DirectoryService;
use OCA\Social\Service\FediverseDirectoryService;
use OCA\Social\Service\FediverseService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Asking other servers who is there.
 *
 * Every assertion here is about something that can only be got wrong once a
 * stranger's server is answering: a handle that a directory left bare, a
 * Misskey user whose `host` is null because they are that server's own, a
 * source that simply does not reply. None of it is reachable from a test that
 * mocks the whole service, so what is mocked here is exactly the network and
 * nothing else.
 */
#[AllowMockObjectsWithoutExpectations]
class FediverseDirectoryServiceTest extends TestCase {
	private const LOCAL_HOST = 'cloud.example';

	private ConfigService|Stub $configService;
	private CurlService|Stub $curlService;
	private DirectoryService|Stub $directoryService;
	private CacheActorService|Stub $cacheActorService;
	private FediverseService|Stub $fediverseService;
	private InstanceStatsRequest|MockObject $instanceStatsRequest;
	/** how many federated peers to ask, as the app value would say it */
	private string $peersWanted = '0';
	/** whether the server directory is asked, as the app value would say it */
	private string $discovery = '0';
	/** what refresh() last wrote about servers */
	private string $stored = '';
	private FediverseDirectoryService $service;

	/** The configured `directories` value, as an administrator would write it. */
	private string $configured = '';
	/** url or path => what that request answers with, or a Throwable to raise. */
	private array $answers = [];
	/** Every URL that was actually requested, in order. */
	private array $asked = [];
	/** Hosts this instance refuses to federate with. */
	private array $blocked = [];
	/** Handles this instance already holds. */
	private array $known = [];
	/** What the local directory page answers with. */
	private array $localPeople = [];

	protected function setUp(): void {
		parent::setUp();

		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValue')
			->willReturnCallback(function (string $key): string {
				return match ($key) {
					FediverseDirectoryService::CONFIG_KEY => $this->configured,
					FediverseDirectoryService::CONFIG_PEERS => $this->peersWanted,
					// off here, so a test that counts requests counts the ones
					// it made; the tests below about discovery turn it on
					FediverseDirectoryService::CONFIG_DISCOVERY => $this->discovery,
					FediverseDirectoryService::CONFIG_KNOWN => $this->stored,
					default => '',
				};
			});
		$this->configService->method('setAppValue')
			->willReturnCallback(function (string $key, string $value): void {
				if ($key === FediverseDirectoryService::CONFIG_KNOWN) {
					$this->stored = $value;
				}
			});
		$this->configService->method('getCloudHost')->willReturn(self::LOCAL_HOST);

		$this->curlService = $this->createStub(CurlService::class);
		$this->curlService->method('retrieveJson')
			->willReturnCallback(function (string $method, string $url): array {
				$this->asked[] = $url;
				foreach ($this->answers as $needle => $answer) {
					if (str_contains($url, (string)$needle)) {
						if ($answer instanceof \Throwable) {
							throw $answer;
						}

						return $answer;
					}
				}

				throw new RuntimeException('nothing answers ' . $url);
			});

		$this->directoryService = $this->createStub(DirectoryService::class);
		$this->directoryService->method('page')->willReturnCallback(fn (): array => $this->localPeople);

		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->cacheActorService->method('searchCachedAccounts')
			->willReturnCallback(fn (): array => $this->localPeople);
		$this->cacheActorService->method('getFromAccount')
			->willReturnCallback(function (string $handle): Person {
				if (!in_array($handle, $this->known, true)) {
					throw new RuntimeException('not held here');
				}

				$person = new Person();
				$person->setAccount($handle);

				return $person;
			});

		$this->fediverseService = $this->createStub(FediverseService::class);
		$this->fediverseService->method('authorized')
			->willReturnCallback(function (string $host): bool {
				if (in_array($host, $this->blocked, true)) {
					throw new RuntimeException('not federating with ' . $host);
				}

				return true;
			});
		$this->fediverseService->method('isSilenced')->willReturn(false);

		// an instance that federates with nobody, so the sources a test sees
		// are the ones it configured; the tests about federated peers say
		// which servers this instance knows
		$this->instanceStatsRequest = $this->createMock(InstanceStatsRequest::class);
		$this->instanceStatsRequest->method('remoteHostCounts')->willReturn([]);

		// a cache that keeps nothing, so each test asks what it means to ask;
		// the one test about caching supplies its own
		$this->service = $this->build($this->createStub(ICache::class));
	}

	private function build(ICache|MockObject $cache): FediverseDirectoryService {
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new FediverseDirectoryService(
			$this->curlService,
			$this->configService,
			$this->directoryService,
			$this->cacheActorService,
			$this->fediverseService,
			$this->instanceStatsRequest,
			new NullLogger(),
			$factory
		);
	}

	private function person(string $handle, string $name = ''): Person {
		$person = new Person();
		$person->setAccount($handle);
		$person->setPreferredUsername(explode('@', $handle)[0]);
		$person->setDisplayName($name);
		$person->setId('https://' . self::LOCAL_HOST . '/users/' . explode('@', $handle)[0]);

		return $person;
	}

	/** @return string[] */
	private function handles(array $result): array {
		return array_map(
			static fn (DirectoryAccount $account): string => $account->getAcct(),
			$result['accounts']
		);
	}

	// sources

	public function testThisInstanceIsAlwaysTheFirstSourceAsked(): void {
		$sources = $this->service->sources();

		$this->assertSame(self::LOCAL_HOST, $sources[0]->getHost());
		$this->assertTrue($sources[0]->isLocal());
	}

	public function testTheShippedSourcesAreUsedWhenNothingIsConfigured(): void {
		$hosts = array_map(
			static fn (DirectorySource $source): string => $source->getHost(), $this->service->sources()
		);

		$this->assertSame(
			[self::LOCAL_HOST, 'fedi.directory', 'mastodon.social', 'misskey.io', 'lemmy.world', 'pixelfed.social'],
			$hosts
		);
	}

	public function testAnAdministratorReplacesThemEntirely(): void {
		$this->configured = json_encode([['host' => 'chaos.social', 'kind' => 'mastodon']]);

		$hosts = array_map(
			static fn (DirectorySource $source): string => $source->getHost(), $this->service->sources()
		);

		$this->assertSame([self::LOCAL_HOST, 'chaos.social'], $hosts);
	}

	// the directory somebody keeps by hand

	/** One entry as fedi.directory publishes it: prose, with a handle in it. */
	private function entry(string $title, string $text, string $link = ''): array {
		return [
			'title' => ['rendered' => $title],
			'excerpt' => ['rendered' => '<p>' . $text . '</p>'],
			'content' => ['rendered' => '<p>' . $text . '</p>'],
			'link' => $link,
		];
	}

	public function testACuratedEntryContributesThePersonItIsAbout(): void {
		$this->configured = json_encode([['host' => 'fedi.directory', 'kind' => 'wordpress']]);
		$this->answers['fedi.directory/wp-json'] = [
			$this->entry('Phosh', 'Interface for Linux phones. Fediverse address:@phosh@social.phosh.mobi', 'https://fedi.directory/phosh/'),
		];

		$result = $this->service->search('linux');
		$account = $result['accounts'][0];

		$this->assertSame(['phosh@social.phosh.mobi'], $this->handles($result));
		// the entry is about somebody else's account, so the host comes out of
		// the handle and not off the directory that wrote it down
		$this->assertSame('social.phosh.mobi', $account->getHost());
		$this->assertSame('Phosh', $account->getDisplayName());
	}

	public function testTheQueryIsWhatTheCuratedDirectoryIsAskedFor(): void {
		$this->configured = json_encode([['host' => 'fedi.directory', 'kind' => 'wordpress']]);
		$this->answers['fedi.directory/wp-json'] = [];

		$this->service->search('mycology');

		// this is the one source that can answer a subject rather than a name
		$this->assertStringContainsString('search=mycology', $this->asked[0]);
	}

	public function testAnEntryThatNamesNobodyIsNotAPerson(): void {
		$this->configured = json_encode([['host' => 'fedi.directory', 'kind' => 'wordpress']]);
		$this->answers['fedi.directory/wp-json'] = [
			$this->entry('A blog', 'Read it at https://example.org/blog, no handle anywhere'),
			$this->entry('Someone', 'Find them at @someone@example.org'),
		];

		$this->assertSame(['someone@example.org'], $this->handles($this->service->search('x')));
	}

	public function testACuratedEntryOnABlockedHostIsNotOffered(): void {
		$this->configured = json_encode([['host' => 'fedi.directory', 'kind' => 'wordpress']]);
		$this->blocked = ['spam.example'];
		$this->answers['fedi.directory/wp-json'] = [
			$this->entry('Spam', 'Find them at @spammer@spam.example'),
		];

		$this->assertSame([], $this->handles($this->service->search('x')));
	}

	public function testACuratedDirectoryWithNothingToSearchForIsNotAsked(): void {
		$this->configured = json_encode([['host' => 'fedi.directory', 'kind' => 'wordpress']]);

		$this->service->search('');

		// its endpoint would answer with whatever is newest, which is not a
		// directory and not what was asked
		$this->assertSame([], $this->asked);
	}

	/**
	 * Pixelfed answers the exact lookup and 404s the directory page, and it is
	 * not alone — the directory is the part of that API a server may turn off.
	 * Whoever the lookup found is still found.
	 */
	public function testAServerWithNoDirectoryStillAnswersTheExactHandle(): void {
		$this->configured = json_encode([['host' => 'pixelfed.example', 'kind' => 'mastodon']]);
		$this->answers['accounts/lookup'] = ['acct' => 'dansup', 'display_name' => 'Daniel'];
		$this->answers['api/v1/directory'] = new RuntimeException('404 from a server that has none');

		$result = $this->service->search('dansup');

		$this->assertSame(['dansup@pixelfed.example'], $this->handles($result));
		$this->assertSame('ok', $result['sources'][1]['status']);
	}

	public function testAServerThatAnswersNeitherIsReportedAsFailing(): void {
		$this->configured = json_encode([['host' => 'gone.example', 'kind' => 'mastodon']]);
		$this->answers['accounts/lookup'] = new RuntimeException('no such handle');
		$this->answers['api/v1/directory'] = new RuntimeException('nothing there either');

		$result = $this->service->search('somebody');

		// "nobody by that name" and "that server did not answer" are different
		// answers and this is the second one
		$this->assertSame('failed', $result['sources'][1]['status']);
	}

	// the servers this instance actually federates with

	/** A host that answers NodeInfo, saying it runs `$software`. */
	private function nodeinfoFor(string $host, string $software): void {
		$this->answers[$host . '/.well-known/nodeinfo'] = [
			'links' => [['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.0', 'href' => 'https://' . $host . '/nodeinfo/2.0']],
		];
		$this->answers[$host . '/nodeinfo/2.0'] = ['software' => ['name' => $software]];
	}

	public function testTheServersThisInstanceKnowsAreAskedToo(): void {
		$this->peersWanted = '2';
		$this->instanceStatsRequest = $this->createMock(InstanceStatsRequest::class);
		// most-federated first, which is the order they are worth asking in
		$this->instanceStatsRequest->method('remoteHostCounts')
			->willReturn(['chaos.social' => 120, 'shonk.example' => 40, 'third.example' => 2]);
		$this->service = $this->build($this->createStub(ICache::class));
		$this->nodeinfoFor('chaos.social', 'mastodon');
		$this->nodeinfoFor('shonk.example', 'sharkey');
		$this->nodeinfoFor('third.example', 'mastodon');

		$this->service->refresh();
		$sources = $this->service->sources();
		$peers = array_values(array_filter(
			$sources,
			static fn (DirectorySource $source): bool => $source->getOrigin() === DirectorySource::ORIGIN_FEDERATION
		));

		$this->assertSame(['chaos.social', 'shonk.example'], array_map(
			static fn (DirectorySource $source): string => $source->getHost(), $peers
		));
		// the kind is what the server said it runs, because the kind decides
		// which API it is asked in
		$this->assertSame(DirectorySource::KIND_MASTODON, $peers[0]->getKind());
		$this->assertSame(DirectorySource::KIND_MISSKEY, $peers[1]->getKind());
	}

	public function testAPeerRunningSomethingUnknownIsLeftOutRatherThanGuessedAt(): void {
		$this->peersWanted = '3';
		$this->instanceStatsRequest = $this->createMock(InstanceStatsRequest::class);
		$this->instanceStatsRequest->method('remoteHostCounts')
			->willReturn(['writefreely.example' => 90, 'chaos.social' => 10]);
		$this->service = $this->build($this->createStub(ICache::class));
		$this->nodeinfoFor('writefreely.example', 'writefreely');
		$this->nodeinfoFor('chaos.social', 'mastodon');

		$this->service->refresh();
		$hosts = array_map(
			static fn (DirectorySource $source): string => $source->getHost(), $this->service->sources()
		);

		$this->assertNotContains('writefreely.example', $hosts);
		$this->assertContains('chaos.social', $hosts);
	}

	public function testAskingNoPeersIsARealAnswer(): void {
		$this->peersWanted = '0';
		$this->instanceStatsRequest = $this->createMock(InstanceStatsRequest::class);
		$this->instanceStatsRequest->expects($this->never())->method('remoteHostCounts');
		$this->service = $this->build($this->createStub(ICache::class));

		$this->service->refresh();
		$this->service->sources();
	}

	public function testAPeerThisInstanceRefusesToFederateWithIsNotAsked(): void {
		$this->peersWanted = '2';
		$this->blocked = ['spam.example'];
		$this->instanceStatsRequest = $this->createMock(InstanceStatsRequest::class);
		$this->instanceStatsRequest->method('remoteHostCounts')
			->willReturn(['spam.example' => 300, 'chaos.social' => 1]);
		$this->service = $this->build($this->createStub(ICache::class));
		$this->nodeinfoFor('chaos.social', 'mastodon');

		$this->service->refresh();
		$hosts = array_map(
			static fn (DirectorySource $source): string => $source->getHost(), $this->service->sources()
		);

		$this->assertNotContains('spam.example', $hosts);
		foreach ($this->asked as $url) {
			$this->assertStringNotContainsString('spam.example', $url);
		}
	}

	/**
	 * The Discover page lists the sources on every load. It used to find out
	 * each peer's software and the directory of servers' list right there,
	 * which on an instance without a memcache was ten live requests and three
	 * to five seconds on every load.
	 */
	public function testListingTheSourcesAsksNoRemoteServer(): void {
		$this->peersWanted = '2';
		$this->discovery = '1';
		$this->instanceStatsRequest = $this->createMock(InstanceStatsRequest::class);
		$this->instanceStatsRequest->method('remoteHostCounts')->willReturn(['chaos.social' => 120]);
		$this->service = $this->build($this->createStub(ICache::class));
		$this->nodeinfoFor('chaos.social', 'mastodon');
		$this->answers['fediverse.info'] = ['data' => [['domain' => 'sharkey.example', 'software_name' => 'sharkey']]];

		// before the first refresh there is nothing stored, and nothing is asked
		$first = $this->service->sources();
		$this->assertSame([], $this->asked);
		$this->assertNotContains('chaos.social', array_map(
			static fn (DirectorySource $source): string => $source->getHost(), $first
		));

		$this->service->refresh();
		$this->asked = [];
		$hosts = array_map(
			static fn (DirectorySource $source): string => $source->getHost(), $this->service->sources()
		);

		$this->assertSame([], $this->asked);
		$this->assertContains('chaos.social', $hosts);
		$this->assertContains('sharkey.example', $hosts);
	}

	/** What the cron found out a week ago is not asked again every twelve minutes. */
	public function testARefreshAsksAPeerItAlreadyKnowsNothing(): void {
		$this->peersWanted = '1';
		$this->instanceStatsRequest = $this->createMock(InstanceStatsRequest::class);
		$this->instanceStatsRequest->method('remoteHostCounts')->willReturn(['chaos.social' => 120]);
		$this->service = $this->build($this->createStub(ICache::class));
		$this->nodeinfoFor('chaos.social', 'mastodon');

		$this->service->refresh();
		$this->asked = [];
		$this->service->refresh();

		$this->assertSame([], $this->asked);
	}

	/** A cron run is bounded however many servers this instance knows. */
	public function testOneRefreshAsksABoundedNumberOfPeers(): void {
		$this->peersWanted = '50';
		$hosts = [];
		for ($i = 0; $i < 30; $i++) {
			$hosts['peer' . $i . '.example'] = 100 - $i;
		}
		$this->instanceStatsRequest = $this->createMock(InstanceStatsRequest::class);
		$this->instanceStatsRequest->method('remoteHostCounts')->willReturn($hosts);
		$this->service = $this->build($this->createStub(ICache::class));

		$this->service->refresh();

		$nodeinfo = array_filter($this->asked, static fn (string $url): bool => str_contains($url, '/.well-known/nodeinfo'));
		$this->assertCount(FediverseDirectoryService::LOOKUPS_PER_REFRESH, $nodeinfo);
	}

	// servers a directory of servers names

	public function testServersCanBeDiscoveredFromADirectoryOfServers(): void {
		$this->discovery = '1';
		$this->answers['fediverse.info'] = ['data' => [
			['domain' => 'pixelfed.social', 'software_name' => 'pixelfed'],
			['domain' => 'sharkey.example', 'software_name' => 'sharkey'],
		]];

		$this->service->refresh();
		$discovered = array_values(array_filter(
			$this->service->sources(),
			static fn (DirectorySource $source): bool => $source->getOrigin() === DirectorySource::ORIGIN_DISCOVERED
		));

		$this->assertSame('sharkey.example', $discovered[0]->getHost());
		$this->assertSame(DirectorySource::KIND_MISSKEY, $discovered[0]->getKind());
		// pixelfed.social already ships in the list above, and a host is one
		// source however many ways it arrives
		$this->assertCount(1, $discovered);
	}

	public function testADirectoryOfServersThatDoesNotAnswerCostsNothingTwice(): void {
		$this->discovery = '1';

		$this->service->refresh();
		$this->service->refresh();

		$this->assertCount(1, array_filter(
			$this->asked, static fn (string $url): bool => str_contains($url, 'fediverse.info')
		));
	}

	/** An empty list is "ask nobody but ourselves", which is a real answer. */
	public function testAnEmptyListLeavesOnlyThisInstance(): void {
		$this->configured = '[]';

		$this->assertCount(1, $this->service->sources());
	}

	/** A typo in a config value must not be why a page is empty. */
	public function testMalformedConfigurationFallsBackToWhatShips(): void {
		$this->configured = 'not json at all';

		// this instance, and the list that ships
		$this->assertCount(6, $this->service->sources());
	}

	public function testAHostWrittenAsAUrlIsReadAsAHost(): void {
		$this->configured = json_encode([['host' => 'https://chaos.social/explore', 'kind' => 'mastodon']]);

		$this->assertSame('chaos.social', $this->service->sources()[1]->getHost());
	}

	public function testAnUnknownKindIsReadAsTheOneMostServersSpeak(): void {
		$this->configured = json_encode([['host' => 'chaos.social', 'kind' => 'gotosocial']]);

		$this->assertSame(DirectorySource::KIND_MASTODON, $this->service->sources()[1]->getKind());
	}

	/** `local` is this instance and cannot be claimed by a remote entry. */
	public function testAConfiguredSourceCannotCallItselfLocal(): void {
		$this->configured = json_encode([['host' => 'chaos.social', 'kind' => 'local']]);

		$this->assertSame(DirectorySource::KIND_MASTODON, $this->service->sources()[1]->getKind());
	}

	// what the sources say

	public function testAMastodonDirectoryHandleIsQualifiedWithTheServerThatSaidIt(): void {
		$this->configured = json_encode([['host' => 'chaos.social', 'kind' => 'mastodon']]);
		$this->answers = [
			'/api/v1/accounts/lookup' => new RuntimeException('404'),
			// Mastodon writes `acct` bare for its own people
			'/api/v1/directory' => [['acct' => 'jens', 'display_name' => 'Jens', 'note' => '<p>hi</p>']],
		];

		$this->assertSame(['jens@chaos.social'], $this->handles($this->service->search('jens')));
	}

	public function testAMastodonDirectoryKeepsAForeignHandleAsItStands(): void {
		$this->configured = json_encode([['host' => 'chaos.social', 'kind' => 'mastodon']]);
		$this->answers = [
			'/api/v1/accounts/lookup' => new RuntimeException('404'),
			'/api/v1/directory' => [['acct' => 'jens@elsewhere.example']],
		];

		$this->assertSame(['jens@elsewhere.example'], $this->handles($this->service->search('jens')));
	}

	/**
	 * Mastodon has no public search, so a Mastodon-kind source is asked the two
	 * public questions it does have — and the exact lookup is the one that
	 * finds a person the directory page does not happen to hold.
	 */
	public function testTheExactHandleIsAskedForAsWellAsTheDirectory(): void {
		$this->configured = json_encode([['host' => 'chaos.social', 'kind' => 'mastodon']]);
		$this->answers = [
			'/api/v1/accounts/lookup' => ['acct' => 'nextcloud', 'display_name' => 'Nextcloud'],
			'/api/v1/directory' => [],
		];

		$this->assertSame(['nextcloud@chaos.social'], $this->handles($this->service->search('nextcloud')));
		$this->assertStringContainsString('acct=nextcloud', $this->asked[0]);
	}

	/** A phrase is not a handle, so there is nothing to look one up by. */
	public function testAQueryWithASpaceIsNotLookedUpAsAHandle(): void {
		$this->configured = json_encode([['host' => 'chaos.social', 'kind' => 'mastodon']]);
		$this->answers = ['/api/v1/directory' => []];

		$this->service->search('two words');

		$this->assertCount(1, $this->asked);
		$this->assertStringContainsString('/api/v1/directory', $this->asked[0]);
	}

	/** The endpoint takes no query, so the filtering is this app's to do. */
	public function testTheQueryIsAppliedToADirectoryThatCannotBeAsked(): void {
		$this->configured = json_encode([['host' => 'chaos.social', 'kind' => 'mastodon']]);
		$this->answers = [
			'/api/v1/accounts/lookup' => new RuntimeException('404'),
			'/api/v1/directory' => [
				['acct' => 'jens', 'display_name' => 'Jens'],
				['acct' => 'maria', 'display_name' => 'Maria'],
				['acct' => 'paul', 'display_name' => 'Paul', 'note' => 'writes about jens sometimes'],
			],
		];

		$this->assertSame(
			['jens@chaos.social', 'paul@chaos.social'],
			$this->handles($this->service->search('jens'))
		);
	}

	/**
	 * A Misskey user's `host` is null when they are that server's own, which is
	 * the one thing that makes the handle ambiguous if taken at face value.
	 */
	public function testAMisskeyUserWithoutAHostBelongsToTheServerThatAnswered(): void {
		$this->configured = json_encode([['host' => 'misskey.example', 'kind' => 'misskey']]);
		$this->answers = ['/api/users/search' => [
			['username' => 'ai', 'name' => 'Ai', 'host' => null],
			['username' => 'far', 'name' => 'Far', 'host' => 'other.example'],
		]];

		$this->assertSame(
			['ai@misskey.example', 'far@other.example'],
			$this->handles($this->service->search('a'))
		);
	}

	/** Lemmy hands over the full actor id, so the handle is read out of it. */
	public function testALemmyPersonIsAttributedToTheServerTheyAreActuallyOn(): void {
		$this->configured = json_encode([['host' => 'lemmy.example', 'kind' => 'lemmy']]);
		$this->answers = ['/api/v3/search' => ['users' => [
			['person' => ['name' => 'sam', 'actor_id' => 'https://other.example/u/sam', 'bio' => 'hi']],
		]]];

		$this->assertSame(['sam@other.example'], $this->handles($this->service->search('sam')));
	}

	// what comes back

	/**
	 * "Nobody by that name" and "that server did not answer" are different
	 * answers, and a reader about to try another spelling needs to know which
	 * one they got.
	 */
	public function testASourceThatFailsIsReportedRatherThanDropped(): void {
		$this->configured = json_encode([
			['host' => 'up.example', 'kind' => 'misskey'],
			['host' => 'down.example', 'kind' => 'misskey'],
		]);
		$this->answers = [
			'up.example' => [['username' => 'ai', 'host' => null]],
			'down.example' => new RuntimeException('timeout'),
		];

		$result = $this->service->search('ai');

		$this->assertSame(['ai@up.example'], $this->handles($result));
		$statuses = [];
		foreach ($result['sources'] as $report) {
			$statuses[$report['host']] = $report['status'];
		}
		$this->assertSame('ok', $statuses['up.example']);
		$this->assertSame('failed', $statuses['down.example']);
		// this instance is asked too, and answers without a network
		$this->assertSame('ok', $statuses[self::LOCAL_HOST]);
	}

	/** One person on two servers' lists is one row, kept as the first source gave it. */
	public function testSomebodyNamedByTwoSourcesAppearsOnce(): void {
		$this->configured = json_encode([
			['host' => 'first.example', 'kind' => 'misskey'],
			['host' => 'second.example', 'kind' => 'misskey'],
		]);
		$this->answers = [
			'first.example' => [['username' => 'ai', 'host' => 'home.example', 'name' => 'As first said']],
			'second.example' => [['username' => 'ai', 'host' => 'home.example', 'name' => 'As second said']],
		];

		$result = $this->service->search('ai');

		$this->assertSame(['ai@home.example'], $this->handles($result));
		$this->assertSame('As first said', $result['accounts'][0]->getDisplayName());
	}

	/**
	 * The fetch is already guarded, but that guards the server being *asked*.
	 * These are people it is telling us about, and offering a follow button for
	 * somebody on a blocked domain would be this instance recommending exactly
	 * what it refuses to deliver to.
	 */
	public function testNobodyOnABlockedDomainIsOffered(): void {
		$this->blocked = ['bad.example'];
		$this->configured = json_encode([['host' => 'misskey.example', 'kind' => 'misskey']]);
		$this->answers = ['/api/users/search' => [
			['username' => 'ok', 'host' => 'good.example'],
			['username' => 'no', 'host' => 'bad.example'],
		]];

		$this->assertSame(['ok@good.example'], $this->handles($this->service->search('o')));
	}

	/** So the row can offer the profile here rather than somebody else's website. */
	public function testSomebodyThisInstanceAlreadyHoldsIsMarkedAsKnown(): void {
		$this->known = ['ai@misskey.example'];
		$this->configured = json_encode([['host' => 'misskey.example', 'kind' => 'misskey']]);
		$this->answers = ['/api/users/search' => [
			['username' => 'ai', 'host' => null],
			['username' => 'stranger', 'host' => null],
		]];

		$accounts = $this->service->search('a')['accounts'];

		$this->assertTrue($accounts[0]->isKnown());
		$this->assertFalse($accounts[1]->isKnown());
	}

	/** One source at a time, for a reader who knows where they are looking. */
	public function testOneSourceCanBeAskedOnItsOwn(): void {
		$this->configured = json_encode([
			['host' => 'first.example', 'kind' => 'misskey'],
			['host' => 'second.example', 'kind' => 'misskey'],
		]);
		$this->answers = ['second.example' => [['username' => 'ai', 'host' => null]]];

		$result = $this->service->search('ai', 'second.example');

		$this->assertSame(['ai@second.example'], $this->handles($result));
		$this->assertCount(1, $result['sources']);
	}

	/** A handle is the one thing every result carries, so it is what is asked for. */
	public function testALeadingAtIsNotPartOfTheQuery(): void {
		$this->configured = json_encode([['host' => 'misskey.example', 'kind' => 'misskey']]);
		$this->answers = ['/api/users/search' => []];

		$this->service->search('  @jens  ');

		$this->assertCount(1, $this->asked);
	}

	/**
	 * Misskey's only listing endpoint answers a different question with a
	 * different shape, so an unsearched Misskey source contributes nothing
	 * rather than something misleading.
	 */
	public function testAnEmptyQueryAsksTheSearchOnlySourcesNothing(): void {
		$this->configured = json_encode([['host' => 'misskey.example', 'kind' => 'misskey']]);

		$result = $this->service->search('');

		$this->assertSame([], $this->asked);
		$this->assertSame([], $this->handles($result));
	}

	/** This instance answers an empty query with its own directory, and no network. */
	public function testAnEmptyQueryStillAsksThisInstance(): void {
		$this->configured = '[]';
		$this->localPeople = [$this->person('alice@' . self::LOCAL_HOST, 'Alice')];

		$this->assertSame(
			['alice@' . self::LOCAL_HOST], $this->handles($this->service->search(''))
		);
	}

	/** A local account is stored bare and has to be qualified to be followed. */
	public function testALocalAccountIsAnsweredAsAFullHandle(): void {
		$this->configured = '[]';
		$this->localPeople = [$this->person('alice', 'Alice')];

		$accounts = $this->service->search('alice')['accounts'];

		$this->assertSame('alice@' . self::LOCAL_HOST, $accounts[0]->getAcct());
		$this->assertTrue($accounts[0]->isKnown());
	}

	/** Typing must not be four requests a keystroke. */
	public function testAnAnswerIsKeptForALittleWhile(): void {
		$cache = $this->createStub(ICache::class);
		$held = [];
		// by reference: an arrow function would capture the empty array as it
		// stands now, and every read would miss
		$cache->method('get')->willReturnCallback(function (string $key) use (&$held) {
			return $held[$key] ?? null;
		});
		$cache->method('set')->willReturnCallback(function (string $key, $value) use (&$held): bool {
			$held[$key] = $value;

			return true;
		});

		$service = $this->build($cache);
		$this->configured = json_encode([['host' => 'misskey.example', 'kind' => 'misskey']]);
		$this->answers = ['/api/users/search' => [['username' => 'ai', 'host' => null]]];

		$first = $service->search('ai');
		$second = $service->search('ai');

		$this->assertSame($this->handles($first), $this->handles($second));
		$this->assertCount(1, $this->asked);
	}
}
