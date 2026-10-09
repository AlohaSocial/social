<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Reader\BlueskyPostSource;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Service\AtprotoConfig;
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
}
