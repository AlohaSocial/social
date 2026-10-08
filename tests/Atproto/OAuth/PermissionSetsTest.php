<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\OAuth;

use OCA\Social\Atproto\Identity\DnsLookup;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Move\PdsClient;
use OCA\Social\Atproto\OAuth\PermissionSets;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PermissionSetsTest extends TestCase {
	private const SET = 'app.example.feed.authOnlyPost';
	private const AUTHORITY = 'did:plc:lexiconauthority0000000';

	private array $lookedUp = [];
	private array $cached = [];
	private int $fetched = 0;

	private function sets(): PermissionSets {
		$dns = $this->createMock(DnsLookup::class);
		$dns->method('txt')->willReturnCallback(function (string $name): array {
			$this->lookedUp[] = $name;

			return $name === '_lexicon.feed.example.app' ? ['did=' . self::AUTHORITY] : [];
		});
		$plc = $this->createMock(PlcClient::class);
		$plc->method('data')->willReturn(['services' => ['atproto_pds' => ['endpoint' => 'https://pds.example.app']]]);
		$pds = $this->createMock(PdsClient::class);
		$pds->method('origin')->willReturnArgument(0);
		$pds->method('call')->willReturnCallback(function (string $origin, string $method, string $verb, array $query): array {
			$this->fetched++;
			$this->assertSame(['repo' => self::AUTHORITY, 'collection' => 'com.atproto.lexicon.schema', 'rkey' => self::SET], $query);

			return ['status' => 200, 'body' => ['value' => ['lexicon' => 1, 'id' => self::SET, 'defs' => ['main' => [
				'type' => 'permission-set',
				'title' => 'Posting only',
				'title:lang' => ['de' => 'Nur Beiträge'],
				'detail' => 'Write posts and read the feed',
				'permissions' => [
					['type' => 'permission', 'resource' => 'repo', 'collection' => ['app.example.feed.post']],
					['type' => 'permission', 'resource' => 'repo', 'collection' => ['app.example.actor.profile']],
					['type' => 'permission', 'resource' => 'rpc', 'lxm' => ['app.example.feed.getFeed'], 'inheritAud' => true],
					['type' => 'permission', 'resource' => 'blob', 'accept' => ['*/*']],
				],
			]]]]];
		});
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->cached[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, $value): bool {
			$this->cached[$key] = $value;

			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new PermissionSets($dns, $plc, $pds, $factory, new NullLogger());
	}

	public function testASetIsResolvedFromItsAuthoritysRepositoryAndKeptToItsNamespace(): void {
		$set = $this->sets()->resolve(self::SET);

		$this->assertSame(['_lexicon.feed.example.app'], $this->lookedUp, 'the authority, reversed, without the name');
		$this->assertSame(['Posting only', 'Nur Beiträge'], [$set['title'], $set['title_lang']['de']]);
		$this->assertSame([
			['resource' => 'repo', 'collection' => ['app.example.feed.post'], 'action' => ['create', 'update', 'delete']],
			['resource' => 'rpc', 'lxm' => ['app.example.feed.getFeed'], 'aud' => '', 'inheritAud' => true],
		], $set['permissions'], 'a sibling namespace and a blob are not a set\'s to give');
	}

	public function testAnIncludeGivesItsAudienceToWhatInheritsIt(): void {
		$sets = $this->sets();

		$rules = $sets->expand(self::SET, 'did:web:api.example.app#feed');
		$this->assertSame('did:web:api.example.app#feed', $rules[1]['aud']);
		$this->assertCount(1, $sets->expand(self::SET, ''), 'an rpc permission without an audience goes');
		$this->assertSame(1, $this->fetched, 'resolved once, then kept');
		$this->assertNull($sets->resolve('app.unknown.feed.set'));
	}
}
