<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyFollowListSource;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\FollowList\FollowListService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyFollowListSourceTest extends TestCase {
	private const CAROL = 'https://bsky.app/profile/did:plc:carol';

	/** @var list<array{string, array}> what the AppView was asked */
	private array $asked = [];

	private function source(callable $answer, bool $enabled = true): BlueskyFollowListSource {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn($enabled);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturnCallback(function (string $method, array $params) use ($answer): array {
			$this->asked[] = [$method, $params];

			return $answer($method, $params);
		});
		$actors = $this->createMock(BlueskyActorService::class);
		$actors->method('cached')->willReturnCallback(static fn (string $did): ?Person => $did === 'did:plc:ann' ? new Person() : null);
		$actors->expects($this->atLeast(0))->method('store');
		$mapper = $this->createMock(ActorMapper::class);
		$mapper->method('person')->willReturn(new Person());
		$local = $this->createMock(LocalRecordResolver::class);
		$local->method('actorId')->willReturnCallback(static fn (string $did): string => $did === 'did:plc:alice' ? 'https://social.test/apps/social/@alice' : '');

		return new BlueskyFollowListSource($config, $appView, $mapper, $actors, $local, new NullLogger());
	}

	public function testTheFollowersAreThePagesTheAppViewListsWithOurOwnAccountsAsThemselves(): void {
		$source = $this->source(static fn (string $method, array $params): array => match ($params['cursor'] ?? '') {
			'' => ['followers' => [
				['did' => 'did:plc:ann', 'handle' => 'ann.test'],
				['did' => 'did:plc:alice', 'handle' => 'alice.social.test'],
				['did' => 'did:plc:nohandle'],
			], 'cursor' => 'c1'],
			'c1' => ['followers' => [['did' => 'did:plc:ben', 'handle' => 'ben.test'], ['did' => 'did:plc:carol', 'handle' => 'carol.test']]],
		});
		$carol = (new Person())->setId(self::CAROL);

		$this->assertTrue($source->supports($carol));
		$this->assertSame([
			'https://bsky.app/profile/did:plc:ann',
			'https://social.test/apps/social/@alice',
			'https://bsky.app/profile/did:plc:ben',
		], $source->accounts($carol, FollowListService::FOLLOWERS, 10), 'not the account itself, nor a profile without a handle');
		$this->assertSame(['app.bsky.graph.getFollowers', ['actor' => 'did:plc:carol', 'limit' => 10]], $this->asked[0]);
		$this->assertSame('c1', $this->asked[1][1]['cursor']);
	}

	public function testWhomItFollowsIsAskedForAndTheReadIsCapped(): void {
		$source = $this->source(static fn (string $method, array $params): array => ['follows' => array_map(
			static fn (int $i): array => ['did' => 'did:plc:u' . bin2hex(random_bytes(6)), 'handle' => 'u' . $i . '.test'],
			range(1, $params['limit']),
		), 'cursor' => 'more']);

		$this->assertCount(5, $source->accounts((new Person())->setId(self::CAROL), FollowListService::FOLLOWING, 5));
		$this->assertSame('app.bsky.graph.getFollows', $this->asked[0][0]);
		$this->assertCount(1, $this->asked, 'one page was enough');

		$this->asked = [];
		$this->assertCount(300, $source->accounts((new Person())->setId(self::CAROL), FollowListService::FOLLOWING, 1000));
		$this->assertCount(3, $this->asked, 'a few pages, no more');
	}

	public function testAnAppViewThatFailsListsNobody(): void {
		$source = $this->source(static fn (): array => throw new AtprotoException('down'));

		$this->assertSame([], $source->accounts((new Person())->setId(self::CAROL), FollowListService::FOLLOWERS, 10));
	}

	public function testOnlyABlueskyAccountWhileBlueskyIsOn(): void {
		$this->assertFalse($this->source(static fn (): array => [])->supports((new Person())->setId('https://remote.example/users/bob')));
		$this->assertFalse($this->source(static fn (): array => [], false)->supports((new Person())->setId(self::CAROL)));
	}
}
