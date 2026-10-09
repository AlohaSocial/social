<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use InvalidArgumentException;
use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Reader\BlueskyFeeds;
use OCA\Social\Atproto\Reader\StarterPacks;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\FollowService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class StarterPacksTest extends TestCase {
	private const BOB = 'did:plc:z72i7hdynmk6r22z27h6tvur';
	private const PACK = 'at://' . self::BOB . '/app.bsky.graph.starterpack/3ks';
	private const LIST = 'at://' . self::BOB . '/app.bsky.graph.list/3kl';
	private const FEED = 'at://' . self::BOB . '/app.bsky.feed.generator/cats';

	/** @var list<array{string, array, bool}> */
	private array $asked = [];
	/** @var string[] the members' DIDs, in the list's order */
	private array $members = ['did:plc:a', 'did:plc:b'];
	/** @var string[] whom the viewer follows on Bluesky */
	private array $following = ['did:plc:b'];
	private bool $hasIdentity = true;
	/** @var string[] followed through FollowService */
	private array $followed = [];
	/** @var string[] kept through BlueskyFeeds */
	private array $kept = [];

	private function packs(): StarterPacks {
		$appView = $this->createMock(AppViewClient::class);
		$answer = function (string $method, array $params, bool $asViewer): array {
			$this->asked[] = [$method, $params, $asViewer];

			return match ($method) {
				'com.atproto.identity.resolveHandle' => ['did' => self::BOB],
				'app.bsky.graph.getStarterPack' => ['starterPack' => [
					'uri' => self::PACK, 'record' => ['name' => 'Start here', 'description' => 'Good people', 'list' => self::LIST],
					'creator' => ['did' => self::BOB, 'handle' => 'bob.test', 'displayName' => 'Bob'],
					'list' => ['uri' => self::LIST], 'joinedAllTimeCount' => 7,
					'feeds' => [['uri' => self::FEED, 'displayName' => 'Cats', 'creator' => ['handle' => 'bob.test']]],
				]],
				'app.bsky.graph.getList' => ['items' => array_map(fn (string $did): array => ['subject' => ['did' => $did, 'handle' => substr($did, 8) . '.test'] + (in_array($did, $this->following, true) ? ['viewer' => ['following' => 'at://x/app.bsky.graph.follow/1']] : [])], $this->members)],
			};
		};
		$appView->method('query')->willReturnCallback(fn (string $method, array $params = []): array => $answer($method, $params, false));
		$appView->method('queryAs')->willReturnCallback(fn (string $did, PrivateKey $key, string $method, array $params = []): array => $answer($method, $params, true));
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturnCallback(fn (): ?Identity => $this->hasIdentity ? new Identity(1, 'https://social.test/@alice', 'did:plc:alice', 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0) : null);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getFromId')->willReturnCallback(static fn (string $id): Person => (new Person())->setId($id));
		$follows = $this->createMock(FollowService::class);
		$follows->method('followActor')->willReturnCallback(function (Person $actor, Person $remote): bool {
			if ($remote->getId() === 'https://bsky.app/profile/did:plc:b') {
				throw new FollowSameAccountException('no');
			}
			$this->followed[] = $remote->getId();

			return true;
		});
		$feeds = $this->createMock(BlueskyFeeds::class);
		$feeds->method('add')->willReturnCallback(function (Person $actor, string $uri): array {
			$this->kept[] = $uri;

			return [];
		});

		return new StarterPacks($appView, $identities, $cacheActors, $follows, $feeds, new NullLogger());
	}

	public function testAPackIsReadByItsAddressAsTheViewer(): void {
		$pack = $this->packs()->read(new Person(), 'https://bsky.app/starter-pack/bob.test/3ks');

		$this->assertSame([self::PACK, 'https://bsky.app/starter-pack/bob.test/3ks', 'Start here', 'Good people', 7, 'bob.test'], [$pack['uri'], $pack['url'], $pack['name'], $pack['description'], $pack['joined'], $pack['creator']['handle']]);
		$this->assertSame([['did:plc:a', false], ['did:plc:b', true]], array_map(static fn (array $m): array => [$m['did'], $m['following']], $pack['members']), 'whom the viewer follows already, marked');
		$this->assertSame([self::FEED], array_column($pack['feeds'], 'uri'));
		$this->assertSame(['app.bsky.graph.getStarterPack', ['starterPack' => self::PACK], true], $this->asked[1]);
	}

	public function testOnlyAStarterPacksAddressIsOne(): void {
		$this->assertSame(self::PACK, $this->packs()->uriOf(self::PACK));
		$this->expectException(InvalidArgumentException::class);
		$this->packs()->uriOf('https://bsky.app/profile/bob.test/lists/3kl');
	}

	public function testMembersAreFollowedOnlyWhenTheyAreInThePackAndTheFeedsKeptWhenAsked(): void {
		$result = $this->packs()->follow(new Person(), self::PACK, ['did:plc:a', 'did:plc:b', 'did:plc:stranger'], true);

		$this->assertSame(['followed' => ['did:plc:a'], 'failed' => ['did:plc:b', 'did:plc:stranger']], $result);
		$this->assertSame(['https://bsky.app/profile/did:plc:a'], $this->followed);
		$this->assertSame([self::FEED], $this->kept);
	}

	public function testAtMostABatchAtATime(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->packs()->follow(new Person(), self::PACK, array_map(static fn (int $i): string => 'did:plc:' . $i, range(1, StarterPacks::FOLLOW_BATCH + 1)), false);
	}

	public function testWithoutABlueskyIdentityThePackIsReadAsAnyone(): void {
		$this->hasIdentity = false;
		$this->packs()->read(new Person(), self::PACK);

		$this->assertSame([false, false], array_column($this->asked, 2));
	}
}
