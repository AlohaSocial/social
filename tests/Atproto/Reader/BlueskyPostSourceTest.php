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
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Reader\BlueskyBlockedBy;
use OCA\Social\Atproto\Reader\BlueskyPostSource;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Actor\Person;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyPostSourceTest extends TestCase {
	public function testAHashtagAndASearchAreAskedOfTheAppViewNewestFirst(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$asked = [];
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturnCallback(static function (string $method, array $params) use (&$asked): array {
			$asked[] = $params;

			return ['posts' => [['uri' => 'at://a/1'], ['uri' => 'at://a/2']]];
		});
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturnCallback(static fn (array $post): bool => $post['uri'] === 'at://a/1');
		$source = new BlueskyPostSource($config, $appView, $this->createMock(IdentityService::class), $store, $this->createMock(LabelerService::class), new NullLogger());

		$this->assertSame(1, $source->tagged('nextcloud', 25, null));
		$this->assertSame(1, $source->matching('open source', 25, null));
		$this->assertSame([
			['q' => '#nextcloud', 'tag' => ['nextcloud'], 'sort' => 'latest', 'limit' => 25],
			['q' => 'open source', 'sort' => 'latest', 'limit' => 25],
		], $asked);
	}

	public function testWhoHasBlockedThePersonIsTakenFromASearchMadeAsThem(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$answer = ['posts' => [['uri' => 'at://did:plc:bob/app.bsky.feed.post/1', 'author' => ['did' => 'did:plc:bob', 'viewer' => ['blockedBy' => true]]]]];
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('queryAs')->willReturn($answer);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturn(new Identity(1, 'https://social.test/@alice', 'did:plc:alice', 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0));
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$alice = (new Person())->setId('https://social.test/@alice');
		$blockedBy = $this->createMock(BlueskyBlockedBy::class);
		$blockedBy->expects($this->once())->method('learn')->with($alice, $answer);
		$source = new BlueskyPostSource($config, $appView, $identities, $this->createMock(PostStore::class), $this->createMock(LabelerService::class), new NullLogger(), $blockedBy);

		$source->matching('cats', 20, $alice);
	}
}
