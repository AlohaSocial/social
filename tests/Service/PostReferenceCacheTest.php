<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\PostReferenceCache;
use OCP\Collaboration\Reference\IReferenceManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PostReferenceCacheTest extends TestCase {
	private const SOCIAL = 'https://cloud.example/apps/social/';
	private const APP = 'https://cloud.example/index.php/apps/social/';
	private const PRETTY = 'https://cloud.example/apps/social/';

	private IReferenceManager&MockObject $referenceManager;
	private StreamRequest&MockObject $streamRequest;
	/** @var string[] the prefixes invalidated, in order */
	private array $invalidated = [];
	private PostReferenceCache $cache;

	protected function setUp(): void {
		$this->referenceManager = $this->createMock(IReferenceManager::class);
		$this->referenceManager->method('invalidateCache')
			->willReturnCallback(function (string $prefix, ?string $key = null): void {
				$this->assertNull($key, 'the provider caches one card per link, under the link alone');
				$this->invalidated[] = $prefix;
			});
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn(self::APP);
		$configService = $this->createStub(ConfigService::class);
		$configService->method('getSocialUrl')->willReturn(self::SOCIAL);
		$this->streamRequest = $this->createMock(StreamRequest::class);

		$this->cache = new PostReferenceCache($this->referenceManager, $urlGenerator, $configService, $this->streamRequest);
	}

	/** Every address the provider reads a local post by, as it would have been pasted. */
	public function testALocalPostIsDroppedUnderEachAddressOfIt(): void {
		$post = new Note();
		$post->setId(self::SOCIAL . '@alice/17580000000012345678');
		$post->setNid(42);

		$this->cache->forgetPost($post);

		$this->assertEqualsCanonicalizing([
			self::APP . '@alice/17580000000012345678',
			self::APP . '@alice/17580000000012345678/',
			self::PRETTY . '@alice/17580000000012345678',
			self::PRETTY . '@alice/17580000000012345678/',
			self::APP . '@alice/42',
			self::APP . '@alice/42/',
			self::PRETTY . '@alice/42',
			self::PRETTY . '@alice/42/',
		], $this->invalidated);
	}

	public function testARemotePostIsLeftAlone(): void {
		$post = new Note();
		$post->setId('https://remote.example/users/bob/statuses/1');
		$post->setNid(7);

		$this->cache->forgetPost($post);

		$this->assertSame([], $this->invalidated);
	}

	/** A suspended account's profile card goes, and the card of every open post of it. */
	public function testALocalAuthorIsDroppedWithTheirPosts(): void {
		$this->streamRequest->expects($this->exactly(2))->method('getOpenPostAddressesByAuthor')
			->willReturnCallback(fn (string $actorId, int $limit, int|string $after): array => match ((string)$after) {
				'0' => [['id' => self::SOCIAL . '@alice/17580000000000000001', 'nid' => 5]],
				'5' => [],
			});

		$this->cache->forgetAuthor(self::SOCIAL . '@alice');

		$this->assertContains(self::APP . '@alice', $this->invalidated);
		$this->assertContains(self::PRETTY . '@alice/', $this->invalidated);
		$this->assertContains(self::APP . '@alice/17580000000000000001', $this->invalidated);
		$this->assertContains(self::PRETTY . '@alice/5', $this->invalidated);
		$this->assertCount(12, $this->invalidated);
	}

	public function testARemoteAuthorAsksNothing(): void {
		$this->streamRequest->expects($this->never())->method('getOpenPostAddressesByAuthor');

		$this->cache->forgetAuthor('https://remote.example/users/bob');

		$this->assertSame([], $this->invalidated);
	}
}
