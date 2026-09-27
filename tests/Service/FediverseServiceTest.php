<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use Exception;
use OCA\Social\Cron\DomainPurge;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Service\AuditService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\MiscService;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class FediverseServiceTest extends TestCase {
	private ConfigService|MockObject $configService;
	private CacheActorsRequest|Stub $cacheActorsRequest;
	private IJobList|MockObject $jobList;
	private AuditService|MockObject $auditService;
	private FediverseService $service;
	/** What the app values hold, for the tests that write one and read it back. */
	private array $stored = [];

	protected function setUp(): void {
		$this->configService = $this->createMock(ConfigService::class);
		$this->cacheActorsRequest = $this->createStub(CacheActorsRequest::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->auditService = $this->createMock(AuditService::class);
		$this->service = new FediverseService(
			$this->configService, $this->createStub(MiscService::class), $this->cacheActorsRequest,
			$this->jobList, $this->auditService
		);
	}

	private function withAccess(string $type, array $list, string $cloudHost = 'cloud.example.com'): void {
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key) => match ($key) {
				ConfigService::SOCIAL_ACCESS_TYPE => $type,
				ConfigService::SOCIAL_ACCESS_LIST => json_encode($list),
				default => '',
			});
		$this->configService->method('getCloudHost')->willReturn($cloudHost);
	}

	/**
	 * The same, for the tests that go through `setAppValue`: the stub keeps
	 * what was written so the next read sees it.
	 */
	private function withStoredConfig(array $stored): void {
		$this->stored = $stored;
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string => $this->stored[$key] ?? '');
		$this->configService->method('setAppValue')
			->willReturnCallback(function (string $key, string $value): void {
				$this->stored[$key] = $value;
			});
		$this->configService->method('getCloudHost')->willReturn('cloud.example.com');
	}

	// silencing: the tier between a block and nothing

	/**
	 * Blocking an instance also cuts off the local users who deliberately
	 * follow somebody there, so the tool was too blunt to reach for and the
	 * nuisance stayed. A silence takes an instance out of the public and global
	 * timelines and leaves it readable by its followers.
	 */
	public function testASilencedInstanceIsSilencedIncludingItsSubdomains(): void {
		$this->withStoredConfig([ConfigService::SOCIAL_SILENCED_LIST => '[]']);

		$this->service->silenceAddress('noisy.example');

		$this->assertTrue($this->service->isSilenced('noisy.example'));
		$this->assertTrue($this->service->isSilenced('a.noisy.example'), 'as a block reads them');
		$this->assertTrue($this->service->isSilenced('NOISY.example.'), 'case and the absolute form');
		$this->assertFalse($this->service->isSilenced('quiet.example'));
		$this->assertFalse($this->service->isSilenced('notnoisy.example'), 'not a suffix match');
	}

	public function testSilencingTwiceStoresOneEntry(): void {
		$this->withStoredConfig([ConfigService::SOCIAL_SILENCED_LIST => '[]']);

		$this->service->silenceAddress('noisy.example');
		$this->service->silenceAddress('noisy.example');

		$this->assertSame(['noisy.example'], $this->service->getSilencedAddresses());
	}

	/**
	 * A published list silences a couple of thousand instances at once. Each
	 * one used to decode and rewrite the whole list; a batch reads it once
	 * and writes it once.
	 */
	public function testABatchOfSilencesIsOneReadAndOneWrite(): void {
		$reads = 0;
		$writes = 0;
		$stored = '["old.example"]';
		$this->configService->method('getAppValue')
			->willReturnCallback(function (string $key) use (&$reads, &$stored): string {
				$reads++;

				return $stored;
			});
		$this->configService->method('setAppValue')
			->willReturnCallback(function (string $key, string $value) use (&$writes, &$stored): void {
				$writes++;
				$stored = $value;
			});

		$domains = ['sub.old.example', 'dup.example', 'dup.example', 'a.dup.example'];
		for ($i = 0; $i < 2000; $i++) {
			$domains[] = 'n' . $i . '.example';
		}

		$added = $this->service->silenceAddresses($domains);

		$this->assertSame(2001, $added, 'new ones only: a subdomain of a silenced one is covered already');
		$this->assertSame(1, $reads);
		$this->assertSame(1, $writes);
		$this->assertCount(2002, json_decode($stored, true));
	}

	public function testABatchThatAddsNothingWritesNothing(): void {
		$this->withStoredConfig([ConfigService::SOCIAL_SILENCED_LIST => '["noisy.example"]']);
		$this->configService->expects($this->never())->method('setAppValue');

		$this->assertSame(0, $this->service->silenceAddresses(['noisy.example', 'www.noisy.example']));
	}

	/** Nothing was deleted by a silence, so lifting it brings everything back. */
	public function testLiftingASilenceRemovesIt(): void {
		$this->withStoredConfig([
			ConfigService::SOCIAL_SILENCED_LIST => '["noisy.example","other.example"]',
		]);

		$this->service->unsilenceAddress('noisy.example');

		$this->assertSame(['other.example'], $this->service->getSilencedAddresses());
		$this->assertFalse($this->service->isSilenced('noisy.example'));
	}

	/** A silence is not a block: what it silences may still reach us. */
	public function testASilencedInstanceIsStillAllowedToDeliver(): void {
		$this->withStoredConfig([
			ConfigService::SOCIAL_SILENCED_LIST => '["noisy.example"]',
			ConfigService::SOCIAL_ACCESS_TYPE => 'all_but',
			ConfigService::SOCIAL_ACCESS_LIST => '[]',
		]);

		$this->assertTrue($this->service->authorized('https://noisy.example/users/bob'));
	}

	public function testEmptyOriginIsNeverAuthorized(): void {
		$this->withAccess('all_but', []);

		$this->expectException(UnauthorizedFediverseException::class);
		$this->expectExceptionMessage('Empty Origin');
		$this->service->authorized('');
	}

	public function testBlacklistModeAuthorizesUnlistedAddresses(): void {
		$this->withAccess('all_but', ['spam.example']);

		$this->assertTrue($this->service->authorized('mastodon.example'));
	}

	public function testBlacklistModeBlocksListedAddresses(): void {
		$this->withAccess('all_but', ['spam.example']);

		$this->expectException(UnauthorizedFediverseException::class);
		$this->expectExceptionMessage('Unauthorized Fediverse');
		$this->service->authorized('spam.example');
	}

	public function testWhitelistModeAuthorizesListedAddresses(): void {
		$this->withAccess('none_but', ['friend.example']);

		$this->assertTrue($this->service->authorized('friend.example'));
	}

	public function testWhitelistModeAlwaysAuthorizesTheLocalHost(): void {
		$this->withAccess('none_but', []);

		$this->assertTrue($this->service->authorized('cloud.example.com'));
	}

	public function testWhitelistModeBlocksEverythingElse(): void {
		$this->withAccess('none_but', ['friend.example']);

		$this->expectException(UnauthorizedFediverseException::class);
		$this->service->authorized('mastodon.example');
	}

	public function testJailedThrowsForAnEmptyWhitelist(): void {
		$this->withAccess('none_but', []);

		$this->expectException(UnauthorizedFediverseException::class);
		$this->expectExceptionMessage('Jailed Fediverse');
		$this->service->jailed();
	}

	public function testJailedIsQuietWithAPopulatedWhitelist(): void {
		$this->withAccess('none_but', ['friend.example']);

		$this->service->jailed();
		$this->addToAssertionCount(1);
	}

	public function testJailedIsQuietInBlacklistMode(): void {
		$this->withAccess('all_but', []);

		$this->service->jailed();
		$this->addToAssertionCount(1);
	}

	public function testGetAccessTypeReadsTheConfig(): void {
		$this->withAccess('none_but', []);

		$this->assertSame('none_but', $this->service->getAccessType());
	}

	public function testSetAccessTypePersistsAKnownType(): void {
		$this->configService->expects($this->once())
			->method('setAppValue')
			->with(ConfigService::SOCIAL_ACCESS_TYPE, 'none_but');

		$this->service->setAccessType('none_but');
	}

	public function testSetAccessTypeRejectsUnknownTypes(): void {
		$this->configService->expects($this->never())->method('setAppValue');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('invalid type');
		$this->service->setAccessType('whitelist');
	}

	public function testIsLocalComparesAgainstTheCloudHost(): void {
		$this->withAccess('all_but', []);

		$this->assertTrue($this->service->isLocal('cloud.example.com'));
		$this->assertFalse($this->service->isLocal('mastodon.example'));
	}

	public function testListedAddressesAreDecodedFromConfig(): void {
		$this->withAccess('all_but', ['a.example', 'b.example']);

		$this->assertSame(['a.example', 'b.example'], $this->service->getListedAddresses());
		$this->assertTrue($this->service->isListed('b.example'));
		$this->assertFalse($this->service->isListed('c.example'));
	}

	public function testResetAddressesStoresAnEmptyList(): void {
		$this->configService->expects($this->once())
			->method('setAppValue')
			->with(ConfigService::SOCIAL_ACCESS_LIST, '[]');

		$this->service->resetAddresses();
	}

	public function testAddAddressAppendsAndPersists(): void {
		$this->withAccess('all_but', ['a.example']);
		$this->configService->expects($this->once())
			->method('setAppValue')
			->with(ConfigService::SOCIAL_ACCESS_LIST, '["a.example","b.example"]');

		$this->service->addAddress('b.example');
	}

	public function testAddAddressIgnoresDuplicates(): void {
		$this->withAccess('all_but', ['a.example']);
		$this->configService->expects($this->never())->method('setAppValue');

		$this->service->addAddress('a.example');
	}

	public function testAddAddressesPersistsOnceAndAuditsOnlyNewNormalizedEntries(): void {
		$this->withAccess('all_but', ['a.example']);
		$this->configService->expects($this->once())
			->method('setAppValue')
			->with(ConfigService::SOCIAL_ACCESS_LIST, '["a.example","b.example","c.example"]');
		$audited = [];
		$this->auditService->expects($this->exactly(2))
			->method('accessListChanged')
			->willReturnCallback(function (string $address, bool $added, bool $blockList) use (&$audited): void {
				$this->assertTrue($added);
				$this->assertTrue($blockList);
				$audited[] = $address;
			});

		$this->assertSame(2, $this->service->addAddresses(['B.Example.', 'c.example', 'b.example', 'a.example']));
		$this->assertSame(['b.example', 'c.example'], $audited);
	}

	public function testRemoveAddressPersistsTheRemainingList(): void {
		$this->withAccess('all_but', ['a.example', 'b.example']);
		$this->configService->expects($this->once())
			->method('setAppValue')
			->with(ConfigService::SOCIAL_ACCESS_LIST, '["a.example"]');

		$this->service->removeAddress('b.example');
	}

	/**
	 * Here and not at the three callers: the settings page, the Mastodon admin
	 * API and `occ social:fediverse` all end up on this line, and an audit
	 * entry that depended on which of them was used would be worse than none.
	 */
	public function testBlockingAnInstanceIsWrittenToTheAuditLog(): void {
		$this->withAccess('all_but', []);
		$this->auditService->expects($this->once())
			->method('accessListChanged')->with('noisy.test', true, true);

		$this->service->addAddress('noisy.test');
	}

	public function testAnAllowListSaysSoRatherThanClaimingABlock(): void {
		$this->withAccess('none_but', []);
		$this->auditService->expects($this->once())
			->method('accessListChanged')->with('friend.test', true, false);

		$this->service->addAddress('friend.test');
	}

	public function testAddingWhatIsAlreadyThereChangesNothingAndSaysNothing(): void {
		$this->withAccess('all_but', ['noisy.test']);
		$this->auditService->expects($this->never())->method('accessListChanged');

		$this->service->addAddress('noisy.test');
	}

	public function testLiftingABlockIsWrittenToTheAuditLog(): void {
		$this->withAccess('all_but', ['a.example', 'noisy.test']);
		$this->auditService->expects($this->once())
			->method('accessListChanged')->with('noisy.test', false, true);

		$this->service->removeAddress('noisy.test');
	}

	public function testRemovingSomethingThatWasNeverListedIsNotAnEntry(): void {
		$this->withAccess('all_but', ['a.example']);
		$this->auditService->expects($this->never())->method('accessListChanged');

		$this->service->removeAddress('never.listed');
	}

	public function testKnownAddressesIsEmpty(): void {
		$this->assertSame([], $this->service->getKnownAddresses());
	}

	public function testListedAddressesToleratesAnUnreadableStoredValue(): void {
		$this->configService->method('getAppValue')->willReturn('');

		$this->assertSame([], $this->service->getListedAddresses());
		$this->assertFalse($this->service->isListed('spam.example'));
	}

	public function testIsListedIgnoresHostnameCase(): void {
		$this->withAccess('all_but', ['spam.example']);

		$this->assertTrue($this->service->isListed('SPAM.Example'));
	}

	#[DataProvider('provideSubdomainsOfAListedDomain')]
	public function testAListedDomainCoversWhatIsUnderIt(string $address): void {
		// a suspension that only matched the exact string lasted as long as it
		// took to point another wildcard record at the same host
		$this->withAccess('all_but', ['evil.test']);

		$this->assertTrue($this->service->isListed($address), $address . ' should be covered');
	}

	public static function provideSubdomainsOfAListedDomain(): iterable {
		yield 'the domain itself' => ['evil.test'];
		yield 'www' => ['www.evil.test'];
		yield 'a deeper label' => ['a.b.evil.test'];
		yield 'the absolute form' => ['evil.test.'];
		yield 'absolute subdomain' => ['www.evil.test.'];
		yield 'mixed case' => ['WWW.Evil.TEST'];
		yield 'padded' => [' www.evil.test '];
	}

	#[DataProvider('provideNamesThatMerelyLookSimilar')]
	public function testASuffixMatchStopsAtALabelBoundary(string $address): void {
		$this->withAccess('all_but', ['evil.test']);

		$this->assertFalse($this->service->isListed($address), $address . ' is a different name');
	}

	public static function provideNamesThatMerelyLookSimilar(): iterable {
		yield 'longer label' => ['notevil.test'];
		yield 'different tld' => ['evil.testing'];
		yield 'the parent' => ['test'];
		yield 'unrelated' => ['good.example'];
		yield 'empty' => [''];
	}

	public function testBlacklistModeBlocksASubdomainOfABlockedDomain(): void {
		$this->withAccess('all_but', ['evil.test']);

		$this->expectException(UnauthorizedFediverseException::class);
		$this->service->authorized('a.evil.test');
	}

	public function testAWhitelistDoesNotWidenToSubdomains(): void {
		// the other direction: whoever runs the parent domain is not asked
		// before a subdomain appears, so an allow list stays exact
		$this->withAccess('none_but', ['friend.example']);

		$this->assertTrue($this->service->authorized('friend.example'));

		$this->expectException(UnauthorizedFediverseException::class);
		$this->service->authorized('impostor.friend.example');
	}

	public function testAddAddressTreatsAnAbsoluteNameAsTheSameEntry(): void {
		$this->withAccess('all_but', ['a.example']);
		$this->configService->expects($this->never())->method('setAppValue');

		$this->service->addAddress('A.Example.');
	}

	public function testAddAddressStillAddsASubdomainOfAListedDomain(): void {
		// covered by isListed(), but an admin may still want it written down
		$this->withAccess('all_but', ['a.example']);
		$this->configService->expects($this->once())
			->method('setAppValue')
			->with(ConfigService::SOCIAL_ACCESS_LIST, '["a.example","sub.a.example"]');

		$this->service->addAddress('sub.a.example');
	}

	public function testBlacklistModeBlocksListedAddressesRegardlessOfCase(): void {
		$this->withAccess('all_but', ['spam.example']);

		$this->expectException(UnauthorizedFediverseException::class);
		$this->service->authorized('Spam.EXAMPLE');
	}

	public function testRemoveAddressKeepsTheListAList(): void {
		$this->withAccess('all_but', ['a.example', 'b.example', 'c.example']);
		$this->configService->expects($this->once())
			->method('setAppValue')
			->with(
				ConfigService::SOCIAL_ACCESS_LIST,
				$this->callback(fn (string $json): bool => $json === json_encode(['a.example', 'c.example']))
			);

		$this->service->removeAddress('B.example');
	}
	// getKnownAddresses()

	public function testTheKnownAddressesAreTheInstancesThisOneHasMet(): void {
		// an empty section under `- Known address:` told an admin nothing and
		// read as "this instance has met nobody"
		$this->cacheActorsRequest->method('getSharedInboxes')->willReturn([
			'https://mastodon.social/inbox',
			'https://Mastodon.Social/inbox',
			'https://chaos.social/inbox',
		]);

		$this->assertSame(
			['chaos.social', 'mastodon.social'], $this->service->getKnownAddresses()
		);
	}

	public function testAnInboxThatIsNotAUrlNamesNoInstance(): void {
		$this->cacheActorsRequest->method('getSharedInboxes')->willReturn(['not a url', '']);

		$this->assertSame([], $this->service->getKnownAddresses());
	}

	public function testBlockingADomainQueuesThePurgeOfWhatItAlreadySent(): void {
		$this->withAccess('all_but', []);
		// a block only ever stopped the next request; everything the instance
		// already sent stayed, which is what the job is for
		$this->jobList->expects($this->once())->method('add')
			->with(DomainPurge::class, ['domain' => 'spam.example']);

		$this->service->addAddress('spam.example');
	}

	public function testAddingAnAllowedDomainPurgesNothing(): void {
		// the same app value holds the allow list, where an entry is an
		// instance this server is choosing to talk to
		$this->withAccess('none_but', []);
		$this->jobList->expects($this->never())->method('add');

		$this->service->addAddress('friends.example');
	}

	public function testBlockingADomainTwiceQueuesOnePurge(): void {
		$this->withAccess('all_but', ['spam.example']);
		$this->jobList->expects($this->never())->method('add');

		$this->service->addAddress('spam.example');
	}

	public function testUnblockingADomainRestoresNothing(): void {
		$this->withAccess('all_but', ['spam.example']);
		// what the purge deleted is gone: lifting the block only lets the
		// instance reach us again
		$this->jobList->expects($this->never())->method('add');

		$this->service->removeAddress('spam.example');
	}

	public function testABlockStandsEvenWhenItsPurgeCannotBeQueued(): void {
		$this->withAccess('all_but', []);
		$this->jobList->method('add')->willThrowException(new Exception('no job list'));

		$this->service->addAddress('spam.example');

		$this->assertTrue(true, 'addAddress() did not throw');
	}

	// #2281: a post arrives and its pictures do not. Mastodon serves media
	// from a sibling host — `6-28.mastodon.xyz` for a post on `mastodon.xyz` —
	// and an allow list names instances, not the buckets their pictures sit in.

	public function testAnAllowedInstanceMayServeItsMediaFromAnotherHost(): void {
		$this->withAccess('none_but', ['mastodon.xyz']);

		$this->assertTrue(
			$this->service->authorized('6-28.mastodon.xyz', 'mastodon.xyz'),
			'the post was allowed through; its pictures are part of what it published'
		);
	}

	/**
	 * And the allow list still means what it says on its own. A host nobody
	 * named, asked about for nobody, is refused exactly as before — this is
	 * the assertion that would catch the fix being written as "let media
	 * through".
	 */
	public function testAMediaHostOnItsOwnIsStillRefusedUnderAnAllowList(): void {
		$this->withAccess('none_but', ['mastodon.xyz']);

		$this->expectException(UnauthorizedFediverseException::class);
		$this->service->authorized('6-28.mastodon.xyz');
	}

	/** Nor does an allowed instance get to name a host on somebody else's behalf. */
	public function testAnAllowedInstanceCannotNameAnUnallowedOneForSomebodyElse(): void {
		$this->withAccess('none_but', ['mastodon.xyz']);

		$this->expectException(UnauthorizedFediverseException::class);
		$this->service->authorized('files.example.invalid', 'pleroma.example');
	}

	/**
	 * The block list is untouched and judges both ends. It matches subdomains
	 * already, so a blocked instance's media host goes with it...
	 */
	public function testABlockedInstanceMediaHostIsStillBlocked(): void {
		$this->withAccess('all_but', ['mastodon.xyz']);

		$this->expectException(UnauthorizedFediverseException::class);
		$this->service->authorized('6-28.mastodon.xyz', 'mastodon.xyz');
	}

	/** ...and naming a third party's address does not launder a blocked instance. */
	public function testABlockedInstanceCannotLaunderItselfThroughAnotherHost(): void {
		$this->withAccess('all_but', ['blocked.example']);

		$this->expectException(UnauthorizedFediverseException::class);
		$this->service->authorized('cdn.example.invalid', 'blocked.example');
	}

	/** An ordinary fetch under a block list is unaffected. */
	public function testAnUnblockedInstanceMediaIsFetchedAsBefore(): void {
		$this->withAccess('all_but', ['blocked.example']);

		$this->assertTrue($this->service->authorized('6-28.mastodon.xyz', 'mastodon.xyz'));
	}
}
