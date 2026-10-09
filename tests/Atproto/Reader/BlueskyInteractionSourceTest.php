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
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyInteractionSource;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyInteractionSourceTest extends TestCase {
	public function testLikersRepostersAndQuotesAreAskedOfTheAppView(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturnCallback(static fn (string $method): array => match ($method) {
			'app.bsky.feed.getLikes' => ['likes' => [
				['actor' => ['did' => 'did:plc:ann', 'handle' => 'ann.test'], 'createdAt' => 'x'],
				['actor' => ['did' => 'did:plc:ben', 'handle' => 'ben.test'], 'createdAt' => 'x'],
			]],
			'app.bsky.feed.getRepostedBy' => ['repostedBy' => [['did' => 'did:plc:cy', 'handle' => 'cy.test']]],
			'app.bsky.feed.getQuotes' => ['posts' => [['uri' => 'at://q/1'], ['uri' => 'at://q/2']]],
		});
		$actors = $this->createMock(BlueskyActorService::class);
		$actors->method('cached')->willReturnCallback(static fn (string $did): ?Person => $did === 'did:plc:ann' ? new Person() : null);
		$actors->expects($this->exactly(2))->method('store');
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturnCallback(static fn (array $post): bool => $post['uri'] === 'at://q/1');
		$mapper = $this->createMock(ActorMapper::class);
		$mapper->method('person')->willReturn(new Person());
		$source = new BlueskyInteractionSource($config, $appView, $refs, $store, $mapper, $actors, $this->createMock(LabelerService::class), new NullLogger());
		$post = (new Note())->setId('https://social.test/@alice/1');

		$this->assertTrue($source->supports($post));
		$this->assertSame(['https://bsky.app/profile/did:plc:ann', 'https://bsky.app/profile/did:plc:ben'], $source->actors($post, 'Like', 10));
		$this->assertSame(['https://bsky.app/profile/did:plc:cy'], $source->actors($post, 'Announce', 10));
		$this->assertSame(1, $source->quotes($post, 10));
	}
}
