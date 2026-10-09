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
use OCA\Social\Atproto\Reader\BlueskyBlockedBy;
use OCA\Social\Atproto\Reader\BlueskyDiscovery;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyDiscoveryTest extends TestCase {
	private array $cached = [];
	private array $asked = [];
	private ?array $topics = null;
	private bool $hasIdentity = true;
	private ?BlueskyBlockedBy $blockedBy = null;

	private function discovery(): BlueskyDiscovery {
		$appView = $this->createMock(AppViewClient::class);
		$answer = function (string $method, array $params, bool $asViewer): array {
			$this->asked[] = [$method, $asViewer];

			return match ($method) {
				'app.bsky.unspecced.getTrendingTopics' => $this->topics ?? throw new AtprotoException('Topics agent not available'),
				'com.atproto.identity.resolveHandle' => ['did' => 'did:plc:trending'],
				'app.bsky.actor.getSuggestions' => ['actors' => [
					['did' => 'did:plc:a', 'handle' => 'Ann.test', 'displayName' => 'Ann', 'description' => 'I <3 cats', 'avatar' => 'https://cdn.bsky.app/a.jpg'],
					['did' => 'did:plc:b', 'handle' => 'handle.invalid'],
					['did' => 'did:plc:c', 'handle' => 'cy.test', 'viewer' => ['following' => 'at://x']],
				]],
			};
		};
		$appView->method('query')->willReturnCallback(fn (string $method, array $params = []): array => $answer($method, $params, false));
		$appView->method('queryAs')->willReturnCallback(fn (string $did, PrivateKey $key, string $method, array $params = []): array => $answer($method, $params, true));
		$identities = $this->createMock(IdentityService::class);
		$identities->method('activeForActor')->willReturnCallback(fn (): ?Identity => $this->hasIdentity ? new Identity(1, 'https://social.test/@alice', 'did:plc:alice', 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0) : null);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cached[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
			$this->cached[$key] = $value;

			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new BlueskyDiscovery($appView, $identities, $factory, new NullLogger(), $this->blockedBy);
	}

	public function testATrendIsAFeedToReadHereOrASearch(): void {
		$this->topics = ['topics' => [
			['topic' => 'Eclipse', 'link' => '/profile/trending.bsky.app/feed/665497821'],
			['topic' => 'cats', 'displayName' => 'Cats', 'link' => '/search?q=%23cats'],
			['topic' => 'odd', 'link' => '/somewhere/else'],
			['topic' => '', 'link' => '/search?q=x'],
		]];

		$this->assertSame([
			['topic' => 'Eclipse', 'label' => 'Eclipse', 'feed' => 'at://did:plc:trending/app.bsky.feed.generator/665497821', 'search' => ''],
			['topic' => 'cats', 'label' => 'Cats', 'feed' => '', 'search' => '#cats'],
			['topic' => 'odd', 'label' => 'odd', 'feed' => '', 'search' => 'odd'],
		], $this->discovery()->trends());
		$this->discovery()->trends();
		$this->assertCount(1, array_filter($this->asked, static fn (array $a): bool => $a[0] === 'app.bsky.unspecced.getTrendingTopics'), 'kept a while');
	}

	public function testAnAppViewWithoutTrendsIsNotAskedOnEveryVisit(): void {
		$this->assertSame([], $this->discovery()->trends());
		$this->assertSame([], $this->discovery()->trends());
		$this->assertCount(1, $this->asked);
	}

	public function testSuggestionsAreAccountsToFollowByHandleNotOnesFollowedAlready(): void {
		$accounts = $this->discovery()->suggestions(new Person());

		$this->assertSame([['id' => 'did:plc:a', 'acct' => 'ann.test', 'username' => 'ann.test', 'display_name' => 'Ann', 'avatar' => 'https://cdn.bsky.app/a.jpg', 'note' => 'I &lt;3 cats', 'url' => 'https://bsky.app/profile/ann.test']], $accounts);
		$this->assertSame([['app.bsky.actor.getSuggestions', true]], $this->asked, 'as the viewer');

		$this->hasIdentity = false;
		$this->asked = [];
		$this->discovery()->suggestions(new Person());
		$this->assertSame([['app.bsky.actor.getSuggestions', false]], $this->asked);
	}

	public function testWhoHasBlockedThePersonIsTakenFromTheSuggestionsMadeForThem(): void {
		$alice = new Person();
		$blockedBy = $this->createMock(BlueskyBlockedBy::class);
		$blockedBy->expects($this->once())->method('learn')->with($alice, $this->callback(static fn (array $answer): bool => count($answer['actors']) === 3));
		$this->blockedBy = $blockedBy;

		$this->discovery()->suggestions($alice);
		$this->hasIdentity = false;
		$this->discovery()->suggestions($alice);
	}
}
