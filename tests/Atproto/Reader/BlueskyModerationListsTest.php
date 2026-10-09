<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Publisher\BlueskyBlocks;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyModerationLists;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyModerationListsTest extends TestCase {
	private const LIST = 'at://did:plc:owner/app.bsky.graph.list/3kmods';

	private bool $enabled = true;
	private bool $publishesBlocks = false;
	/** @var list<array{string, array}> */
	private array $queries = [];
	/** @var list<string> */
	private array $procedures = [];
	/** @var list<string> */
	private array $stored = [];
	/** @var list<string> */
	private array $written = [];
	/** @var list<string> */
	private array $removed = [];

	private function lists(): BlueskyModerationLists {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);

		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturnCallback(function (string $method, array $params = []): array {
			$this->queries[] = [$method, $params];

			return match (true) {
				$method === 'com.atproto.identity.resolveHandle' => $params['handle'] === 'owner.test' ? ['did' => 'did:plc:owner'] : throw new AtprotoException('Unable to resolve handle'),
				$params['list'] !== self::LIST => throw new AtprotoException('List not found'),
				!isset($params['cursor']) => ['list' => ['uri' => self::LIST, 'name' => 'Spammers'], 'cursor' => 'page2', 'items' => [
					['subject' => ['did' => 'did:plc:a', 'handle' => 'a.test']],
					['subject' => ['did' => 'did:plc:nohandle']],
				]],
				default => ['list' => ['uri' => self::LIST, 'name' => 'Spammers'], 'items' => [
					['subject' => ['did' => 'did:plc:b', 'handle' => 'b.test']],
					['subject' => ['did' => 'did:plc:a', 'handle' => 'a.test']],
				]],
			};
		});
		$appView->method('procedureAs')->willReturnCallback(function (string $did, PrivateKey $key, string $method, array $input): array {
			$this->procedures[] = $method . ' ' . $input['list'];

			return [];
		});

		$identities = $this->createMock(IdentityService::class);
		$identities->method('activeForActor')->willReturn(new Identity(1, 'https://social.test/users/alice', 'did:plc:alice', 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0));
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));

		$actors = $this->createMock(BlueskyActorService::class);
		$actors->method('cached')->willReturnCallback(fn (string $did): ?Person => $did === 'did:plc:b' ? new Person() : null);
		$actors->method('store')->willReturnCallback(function (Person $person): void {
			$this->stored[] = $person->getId();
		});
		$mapper = $this->createMock(ActorMapper::class);
		$mapper->method('person')->willReturnCallback(fn (array $profile): Person => (new Person())->setId('https://bsky.app/profile/' . $profile['did']));

		$blocks = $this->createMock(BlueskyBlocks::class);
		$blocks->method('isPublished')->willReturnCallback(fn (): bool => $this->publishesBlocks);
		$publisher = $this->createMock(Publisher::class);
		$publisher->method('writeRecord')->willReturnCallback(function (Person $actor, string $collection, array $record): bool {
			$this->written[] = $collection . ' ' . $record['subject'];

			return true;
		});
		$publisher->method('removeRecord')->willReturnCallback(function (string $collection): bool {
			$this->removed[] = $collection;

			return true;
		});

		return new BlueskyModerationLists($config, $appView, $identities, $actors, $mapper, $blocks, $publisher, $this->createMock(ITimeFactory::class), new NullLogger());
	}

	private function alice(): Person {
		$person = new Person();
		$person->setId('https://social.test/users/alice');
		$person->setUserId('alice');

		return $person;
	}

	public function testAListIsNamedByItsLinkOrItsAddress(): void {
		$expected = ['uri' => self::LIST, 'name' => 'Spammers'];

		$this->assertSame($expected, $this->lists()->describe('https://bsky.app/profile/owner.test/lists/3kmods'));
		$this->assertSame($expected, $this->lists()->describe('https://bsky.app/profile/did:plc:owner/lists/3kmods'));
		$this->assertSame($expected, $this->lists()->describe(' ' . self::LIST . ' '));
		$this->assertNull($this->lists()->describe('https://bsky.app/profile/nobody.test/lists/3kmods'));
		$this->assertNull($this->lists()->describe('at://did:plc:owner/app.bsky.feed.post/3kmods'));
		$this->assertNull($this->lists()->describe('https://example.com/lists/3kmods'));
	}

	public function testNoListWhileBlueskyIsOff(): void {
		$this->enabled = false;

		$this->assertNull($this->lists()->describe(self::LIST));
		$this->assertSame([], $this->lists()->members(self::LIST, 10));
		$this->assertSame([], $this->queries);
	}

	public function testTheMembersAreReadPageByPageAndCached(): void {
		$this->assertSame(
			['https://bsky.app/profile/did:plc:a', 'https://bsky.app/profile/did:plc:b'],
			$this->lists()->members(self::LIST, 10),
		);
		$this->assertCount(2, $this->queries);
		$this->assertSame(['https://bsky.app/profile/did:plc:a', 'https://bsky.app/profile/did:plc:a'], $this->stored, 'the one cached already is not stored again');
	}

	public function testNoMoreMembersThanAsked(): void {
		$this->assertSame(['https://bsky.app/profile/did:plc:a'], $this->lists()->members(self::LIST, 1));
		$this->assertCount(1, $this->queries);
	}

	public function testAMuteListIsMutedAtTheAppView(): void {
		$this->lists()->subscribed($this->alice(), self::LIST, 'mute', true);
		$this->lists()->subscribed($this->alice(), self::LIST, 'mute', false);

		$this->assertSame(['app.bsky.graph.muteActorList ' . self::LIST, 'app.bsky.graph.unmuteActorList ' . self::LIST], $this->procedures);
		$this->assertSame([], $this->written);
	}

	public function testABlockListIsPublishedOnlyByWhoPublishesTheirBlocks(): void {
		$this->lists()->subscribed($this->alice(), self::LIST, 'block', true);
		$this->assertSame([], $this->written);

		$this->publishesBlocks = true;
		$this->lists()->subscribed($this->alice(), self::LIST, 'block', true);
		$this->lists()->subscribed($this->alice(), self::LIST, 'block', false);

		$this->assertSame([BlueskyModerationLists::LISTBLOCK . ' ' . self::LIST], $this->written);
		$this->assertSame([BlueskyModerationLists::LISTBLOCK], $this->removed);
		$this->assertSame([], $this->procedures);
	}
}
