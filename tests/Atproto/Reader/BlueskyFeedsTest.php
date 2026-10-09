<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use InvalidArgumentException;
use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Client\Preferences;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Reader\BlueskyFeeds;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyFeedsTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const BOB = 'did:plc:z72i7hdynmk6r22z27h6tvur';
	private const FEED = 'at://' . self::BOB . '/app.bsky.feed.generator/cats';
	private const LIST = 'at://' . self::BOB . '/app.bsky.graph.list/3kfriends';

	private Person $alice;
	private ?Identity $identity;
	/** @var array<string, string> the user's stored settings */
	private array $stored = [];
	/** @var array<string, mixed> */
	private array $cached = [];
	/** @var list<array{string, array, bool}> what the AppView was asked, and whether as the person */
	private array $asked = [];
	/** @var array<string, array> the AppView's answers, by method */
	private array $answers = [];
	/** @var string[] posts hidden from the viewer */
	private array $hidden = [];

	protected function setUp(): void {
		$this->alice = new Person();
		$this->alice->setId('https://social.test/@alice');
		$this->alice->setUserId('alice');
		$this->identity = new Identity(1, $this->alice->getId(), self::DID, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0);
		$this->answers = [
			'app.bsky.feed.getFeedGenerators' => ['feeds' => [['uri' => self::FEED, 'displayName' => 'Cats', 'description' => 'Only cats', 'avatar' => 'https://cdn.test/cats.jpg', 'creator' => ['handle' => 'bob.test']]]],
			'app.bsky.graph.getList' => ['list' => ['uri' => self::LIST, 'name' => 'Friends', 'creator' => ['handle' => 'bob.test']], 'items' => []],
			'com.atproto.identity.resolveHandle' => ['did' => self::BOB],
		];
	}

	private function feeds(): BlueskyFeeds {
		$appView = $this->createMock(AppViewClient::class);
		$answer = function (string $method, array $params, bool $asPerson): array {
			$this->asked[] = [$method, $params, $asPerson];

			return $this->answers[$method] ?? throw new \RuntimeException('not answered: ' . $method);
		};
		$appView->method('query')->willReturnCallback(fn (string $method, array $params = []): array => $answer($method, $params, false));
		$appView->method('queryAs')->willReturnCallback(fn (string $did, PrivateKey $key, string $method, array $params = []): array => $answer($method, $params, true));
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturnCallback(fn (): ?Identity => $this->identity);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(fn (string $user, string $app, string $key, $default = ''): string => $this->stored[$key] ?? $default);
		$config->method('setUserValue')->willReturnCallback(function (string $user, string $app, string $key, $value): void {
			$this->stored[$key] = $value;
		});
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturnCallback(function (string $id): Note {
			if (in_array($id, $this->hidden, true)) {
				throw new StreamNotFoundException();
			}
			$note = new Note();
			$note->setId($id);
			$note->setNid(crc32($id));

			return $note;
		});
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cached[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
			$this->cached[$key] = $value;

			return true;
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		return new BlueskyFeeds($appView, $identities, new Preferences($config), $this->createMock(PostStore::class), $streams, $cacheFactory, new NullLogger());
	}

	/** @return list<array> the saved-feeds items as stored */
	private function savedItems(): array {
		foreach (json_decode($this->stored['atproto_preferences'] ?? '[]', true) as $preference) {
			if ($preference['$type'] === BlueskyFeeds::SAVED) {
				return $preference['items'];
			}
		}

		return [];
	}

	public function testOnlyFeedsAndListsAreNamedSo(): void {
		$this->assertSame(BlueskyFeeds::FEED, BlueskyFeeds::typeOf(self::FEED));
		$this->assertSame(BlueskyFeeds::LIST, BlueskyFeeds::typeOf(self::LIST));
		$this->assertNull(BlueskyFeeds::typeOf('at://' . self::BOB . '/app.bsky.feed.post/3kpost'));
		$this->assertNull(BlueskyFeeds::typeOf('at://bob.test/app.bsky.feed.generator/cats'), 'by DID only');
		$this->assertNull(BlueskyFeeds::typeOf('https://bsky.app/profile/bob.test/feed/cats'));
	}

	public function testAFeedIsKeptInTheBlueskyPreferenceByItsAddressOnce(): void {
		$this->stored['atproto_preferences'] = json_encode([['$type' => 'app.bsky.actor.defs#adultContentPref', 'enabled' => false]]);

		$added = $this->feeds()->add($this->alice, 'https://bsky.app/profile/bob.test/feed/cats');
		$this->feeds()->add($this->alice, self::FEED);

		$this->assertSame(['uri' => self::FEED, 'type' => 'feed', 'name' => 'Cats', 'description' => 'Only cats', 'avatar' => 'https://cdn.test/cats.jpg', 'creator' => 'bob.test', 'pinned' => true], $added);
		$items = $this->savedItems();
		$this->assertCount(1, $items, 'kept once');
		$this->assertSame(['type' => 'feed', 'value' => self::FEED, 'pinned' => true], array_diff_key($items[0], ['id' => 1]));
		$this->assertStringContainsString('adultContentPref', $this->stored['atproto_preferences'], 'the other preferences as they were');
	}

	public function testWhatIsNotAFeedOrListBlueskyKnowsIsNotKept(): void {
		foreach (['https://example.com/feed/cats', 'at://' . self::BOB . '/app.bsky.feed.generator/dogs'] as $typed) {
			try {
				$this->feeds()->add($this->alice, $typed);
				$this->fail($typed);
			} catch (InvalidArgumentException) {
			}
		}
		$this->assertSame([], $this->savedItems());
	}

	public function testTheSavedOnesArePinnedFirstAndOnesBlueskyForgotAreLeftOut(): void {
		$this->stored['atproto_preferences'] = json_encode([['$type' => BlueskyFeeds::SAVED, 'items' => [
			['type' => 'timeline', 'value' => 'following', 'pinned' => true, 'id' => 'a'],
			['type' => 'list', 'value' => self::LIST, 'pinned' => false, 'id' => 'b'],
			['type' => 'feed', 'value' => 'at://' . self::BOB . '/app.bsky.feed.generator/gone', 'pinned' => true, 'id' => 'c'],
			['type' => 'feed', 'value' => self::FEED, 'pinned' => true, 'id' => 'd'],
		]]]);

		$saved = $this->feeds()->saved($this->alice);

		$this->assertSame([[self::FEED, true], [self::LIST, false]], array_map(static fn (array $one): array => [$one['uri'], $one['pinned']], $saved));
	}

	public function testRemovingOneKeepsTheRest(): void {
		$this->stored['atproto_preferences'] = json_encode([['$type' => BlueskyFeeds::SAVED, 'items' => [
			['type' => 'timeline', 'value' => 'following', 'pinned' => true, 'id' => 'a'],
			['type' => 'feed', 'value' => self::FEED, 'pinned' => true, 'id' => 'd'],
		]]]);

		$this->feeds()->remove($this->alice, self::FEED);

		$this->assertSame([['type' => 'timeline', 'value' => 'following', 'pinned' => true, 'id' => 'a']], $this->savedItems());
	}

	public function testAFeedIsReadAsThePersonPageByPageInItsOwnOrder(): void {
		$uri = static fn (string $rkey): string => 'at://' . self::BOB . '/app.bsky.feed.post/' . $rkey;
		$this->answers['app.bsky.feed.getFeed'] = ['feed' => [['post' => ['uri' => $uri('3kb')]], ['post' => ['uri' => $uri('3ka')]], ['post' => ['uri' => $uri('3kc')]]], 'cursor' => 'next-1'];
		$this->hidden = [BlueskyIds::postIdOfUri($uri('3kc'))];

		$first = $this->feeds()->page($this->alice, self::FEED, '', 2);

		$this->assertSame([BlueskyIds::postIdOfUri($uri('3kb')), BlueskyIds::postIdOfUri($uri('3ka'))], array_map(static fn (Note $note): string => $note->getId(), $first), 'its order; a hidden post left out');
		$this->assertSame(['app.bsky.feed.getFeed', ['feed' => self::FEED, 'limit' => 2], true], $this->asked[0]);

		$this->answers['app.bsky.feed.getFeed'] = ['feed' => []];
		$this->feeds()->page($this->alice, self::FEED, (string)end($first)->getNid(), 2);
		$this->assertSame(['feed' => self::FEED, 'limit' => 2, 'cursor' => 'next-1'], $this->asked[1][1], 'the page after the last post');

		$this->assertSame([], $this->feeds()->page($this->alice, self::FEED, '12345', 2), 'past the end');
		$this->assertCount(2, $this->asked, 'without asking again');
	}

	public function testAListIsReadAsItsFeedAndWithoutAnIdentityAsAnyone(): void {
		$this->identity = null;
		$this->answers['app.bsky.feed.getListFeed'] = ['feed' => []];

		$this->feeds()->page($this->alice, self::LIST, '', 500);

		$this->assertSame(['app.bsky.feed.getListFeed', ['list' => self::LIST, 'limit' => 100], false], $this->asked[0]);
		$this->expectException(InvalidArgumentException::class);
		$this->feeds()->page($this->alice, 'at://' . self::BOB . '/app.bsky.feed.post/3kpost', '', 20);
	}
}
