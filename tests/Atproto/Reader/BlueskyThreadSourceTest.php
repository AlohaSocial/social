<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Publisher\PostRefs;
use OCA\Social\Atproto\Reader\BlueskyThreadSource;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Object\Note;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyThreadSourceTest extends TestCase {
	private const VIEW = 'app.bsky.feed.defs#threadViewPost';

	private static function node(string $uri, array $replies = [], ?array $parent = null): array {
		return ['$type' => self::VIEW, 'post' => ['uri' => $uri], 'replies' => $replies] + ($parent === null ? [] : ['parent' => $parent]);
	}

	public function testTheThreadIsStoredParentsFirstThenTheRepliesLevelByLevel(): void {
		$thread = self::node('at://p/2', [
			self::node('at://r/1', [self::node('at://r/1a')]),
			['$type' => 'app.bsky.feed.defs#notFoundPost', 'uri' => 'at://gone'],
			self::node('at://r/2'),
		], self::node('at://p/1', [], self::node('at://p/0')));
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturnCallback(static fn (string $id): ?array => $id === 'https://social.test/@alice/2' ? ['uri' => 'at://p/2', 'cid' => 'bafy'] : null);
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->once())->method('query')->with('app.bsky.feed.getPostThread', $this->callback(static fn (array $p): bool => $p['uri'] === 'at://p/2'))->willReturn(['thread' => $thread]);
		$stored = [];
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturnCallback(static function (array $post) use (&$stored): bool {
			$stored[] = $post['uri'];

			return $post['uri'] !== 'at://p/2';
		});
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger());
		$post = (new Note())->setId('https://social.test/@alice/2');

		$this->assertTrue($source->supports($post));
		$this->assertFalse($source->supports((new Note())->setId('https://social.test/@alice/9')), 'not on Bluesky');
		$this->assertSame(4, $source->fill($post, 4));
		$this->assertSame(['at://p/0', 'at://p/1', 'at://p/2', 'at://r/1', 'at://r/2'], $stored, 'the budget counts what was new');
	}
}
