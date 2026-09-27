<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\FeaturedTagsRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\FeaturedTag;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The hashtags an account features on its profile.
 */
class FeaturedTagsRequestTest extends TestCase {
	private const ALICE = 'https://itest.example/users/featured-alice';
	private const BOB = 'https://itest.example/users/featured-bob';

	private FeaturedTagsRequest $featured;

	protected function setUp(): void {
		parent::setUp();
		$this->featured = Server::get(FeaturedTagsRequest::class);
		$this->featured->deleteRelatedId(self::ALICE);
		$this->featured->deleteRelatedId(self::BOB);
	}

	protected function tearDown(): void {
		$this->featured->deleteRelatedId(self::ALICE);
		$this->featured->deleteRelatedId(self::BOB);
		parent::tearDown();
	}

	private function feature(string $actor, string $hashtag): FeaturedTag {
		$tag = new FeaturedTag();
		$tag->setOwnerId($actor);
		$tag->setHashtag($hashtag);

		return $this->featured->create($tag);
	}

	public function testFeaturingATagTwiceAnswersWithTheSameTag(): void {
		$first = $this->feature(self::ALICE, 'jazz');
		$again = $this->feature(self::ALICE, 'jazz');

		$this->assertGreaterThan(0, $first->getId());
		$this->assertSame($first->getId(), $again->getId());
		$this->assertSame(1, $this->featured->countByActor(self::ALICE));
	}

	public function testATagIsOnlyFoundByTheAccountThatFeaturesIt(): void {
		$tag = $this->feature(self::ALICE, 'jazz');
		$this->feature(self::BOB, 'climbing');

		$this->assertSame('jazz', $this->featured->getOwnedById(self::ALICE, $tag->getId())->getHashtag());
		$this->assertSame($tag->getId(), $this->featured->getOwnedByHashtag(self::ALICE, 'jazz')->getId());
		$this->assertSame(['climbing'], array_map(fn (FeaturedTag $t) => $t->getHashtag(), $this->featured->getByActor(self::BOB)));

		$this->expectException(ItemNotFoundException::class);
		$this->featured->getOwnedById(self::BOB, $tag->getId());
	}

	public function testAnUnfeaturedTagIsGone(): void {
		$tag = $this->feature(self::ALICE, 'jazz');
		$this->feature(self::ALICE, 'climbing');

		$this->featured->delete($tag);

		$this->assertSame(1, $this->featured->countByActor(self::ALICE));
		$this->expectException(ItemNotFoundException::class);
		$this->featured->getOwnedByHashtag(self::ALICE, 'jazz');
	}

	public function testAHashtagThatHasNoPostsCountsNone(): void {
		$this->assertSame([], $this->featured->countUsage(self::ALICE, ['jazz']));
		$this->assertSame([], $this->featured->countUsage(self::ALICE, []));
		$this->assertSame([], $this->featured->mostUsed(self::ALICE, 5));
	}

	public function testAHashtagIsStoredTheWayItIsPosted(): void {
		$this->assertSame('jazz', FeaturedTagsRequest::normaliseHashtag('  #Jazz '));
		$this->assertSame('', FeaturedTagsRequest::normaliseHashtag('two words'));
	}
}
