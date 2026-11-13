<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AlohaDirectory;

use OCA\Social\AlohaDirectory\DirectoryConfig;
use OCA\Social\AlohaDirectory\DirectoryLists;
use OCA\Social\AlohaDirectory\ListVerifier;
use OCA\Social\Security\PrivateKeyCipher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DirectoryListsTest extends TestCase {
	private AppConfigStore $store;
	private FakeCurl $curl;
	private int $now;
	private DirectoryLists $lists;

	protected function setUp(): void {
		$meta = json_decode((string)file_get_contents(__DIR__ . '/fixtures/meta.json'), true);
		$this->store = new AppConfigStore();
		$this->store->values[DirectoryConfig::ROOT_KEYS] = (string)json_encode(['test-root' => $meta['root_public']]);
		$this->curl = new FakeCurl();
		foreach (['keys', 'servers', 'search'] as $list) {
			$this->curl->answer('GET', 'https://directory.aloha.social/lists/v1/' . $list . '.json', 200, (string)file_get_contents(__DIR__ . '/fixtures/' . $list . '.json'));
		}
		$this->now = (int)strtotime('2026-11-14T00:00:00Z');

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$system = $this->createStub(IConfig::class);
		$system->method('getSystemValue')->willReturn('de_DE');
		$config = new DirectoryConfig($this->store->mock($this), $this->createStub(PrivateKeyCipher::class));
		$this->lists = new DirectoryLists($config, $this->curl, new ListVerifier(), $system, $time, new NullLogger());
	}

	public function testVerifiedListsAreKept(): void {
		$result = $this->lists->refresh();

		$this->assertTrue($result['ok'], $result['error']);
		$this->assertSame(['keys', 'servers', 'search'], $result['changed']);
		$this->assertCount(2, $this->lists->servers());
		$this->assertSame(1794586000, $this->lists->summary()['lists']['servers']['serial']);
	}

	public function testUnchangedListsAreNotCheckedAgain(): void {
		$this->lists->refresh();
		$this->now += 86400;

		$result = $this->lists->refresh();
		$this->assertTrue($result['ok'], $result['error']);
		$this->assertSame([], $result['changed']);
	}

	public function testAReplayedOlderListLeavesTheKeptOneInPlace(): void {
		$this->lists->refresh();
		$this->curl->answer('GET', 'https://directory.aloha.social/lists/v1/servers.json', 200, (string)file_get_contents(__DIR__ . '/fixtures/servers-older.json'));

		$result = $this->lists->refresh();
		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('not higher', $result['error']);
		$this->assertCount(2, $this->lists->servers());
	}

	public function testNothingIsReadWithoutARootKey(): void {
		unset($this->store->values[DirectoryConfig::ROOT_KEYS]);

		$result = $this->lists->refresh();
		$this->assertFalse($result['ok']);
		$this->assertSame([], $this->curl->sent);
		$this->assertFalse($this->lists->summary()['rootKeyConfigured']);
	}

	public function testAMirrorIsReadInstead(): void {
		$this->store->values[DirectoryConfig::LIST_MIRROR] = 'https://mirror.example.net/directory';
		foreach (['keys', 'servers', 'search'] as $list) {
			$this->curl->answer('GET', 'https://mirror.example.net/directory/lists/v1/' . $list . '.json', 200, (string)file_get_contents(__DIR__ . '/fixtures/' . $list . '.json'));
		}

		$this->assertTrue($this->lists->refresh()['ok']);
		$this->assertStringStartsWith('https://mirror.example.net/', $this->curl->sent[0]['url']);
	}

	public function testRefreshIsDueDailyAndHourlyAfterAFailure(): void {
		$this->assertTrue($this->lists->refreshDue());
		$this->lists->refresh();
		$this->now += 3600;
		$this->assertFalse($this->lists->refreshDue());

		$this->curl->answers = [];
		$this->now += 86400;
		$this->lists->refresh();
		$this->now += 3600;
		$this->assertTrue($this->lists->refreshDue());
	}

	public function testSearchProvidersAreThoseThatSearchAccountsSharingTheLanguageFirst(): void {
		$this->lists->refresh();

		$providers = $this->lists->curatedSearchProviders('cloud.example.com');
		$domains = array_column($providers, 'domain');
		$this->assertSame('suche.example.org', $domains[0], 'German first, the default language is de_DE');
		$sorted = $domains;
		sort($sorted);
		$this->assertSame(['search.example.net', 'suche.example.org'], $sorted);
		$this->assertNotContains('tags-only.example.net', $domains, 'it does not search accounts');
		$this->assertNotContains('cloud.example.com', $domains, 'never this server itself');
	}

	public function testTheCuratedListCanBeTurnedOff(): void {
		$this->lists->refresh();
		$this->store->values[DirectoryConfig::ALOHA_LIST] = '0';

		$this->assertSame([], $this->lists->curatedSearchProviders('cloud.example.com'));
	}

	public function testAListOlderThanNinetyDaysIsNotUsed(): void {
		$this->lists->refresh();
		$this->now = (int)strtotime('2027-03-01T00:00:00Z');

		$this->assertSame([], $this->lists->servers());
		$this->assertTrue($this->lists->summary()['lists']['servers']['tooOld']);
	}
}
