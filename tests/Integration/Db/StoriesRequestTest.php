<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\StoriesRequest;
use OCA\Social\Db\StoryInteractionsRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\Story;
use OCA\Social\Model\Client\StoryInteraction;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Stories and what hangs off them, against the real database: live until
 * they expire, seen once per viewer, and answered at most by a source id.
 */
class StoriesRequestTest extends TestCase {
	private const ALICE = 'https://itest.example/users/story-alice';
	private const BOB = 'https://itest.example/users/story-bob';
	private const VIEWER = 'https://itest.example/users/story-viewer';

	private StoriesRequest $stories;
	private StoryInteractionsRequest $interactions;

	protected function setUp(): void {
		parent::setUp();
		$this->stories = Server::get(StoriesRequest::class);
		$this->interactions = Server::get(StoryInteractionsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->stories->deleteRelatedId(self::ALICE);
		$this->stories->deleteRelatedId(self::BOB);
		$this->interactions->deleteByActor(self::VIEWER);
	}

	private function story(string $owner, int $expiresAt = 0, string $source = ''): Story {
		return $this->stories->save((new Story())
			->setOwnerId($owner)
			->setDocumentId('https://itest.example/media/' . bin2hex(random_bytes(4)))
			->setCaption('a caption')
			->setDuration(Story::DEFAULT_DURATION)
			->setExpiresAt($expiresAt)
			->setSourceId($source !== '' ? $source : 'https://itest.example/stories/' . bin2hex(random_bytes(4)))
			->setLocal(true));
	}

	public function testAStoryIsLiveForADayByDefault(): void {
		$story = $this->story(self::ALICE);

		$this->assertGreaterThan(0, $story->getId());
		$this->assertEqualsWithDelta(time() + Story::LIFETIME, $story->getExpiresAt(), 5);
		$this->assertSame('a caption', $this->stories->getLiveById($story->getId())->getCaption());
		$this->assertSame(1, $this->stories->countLiveByActor(self::ALICE));
	}

	public function testAnExpiredStoryIsNotLiveAndIsSweptUp(): void {
		$expired = $this->story(self::ALICE, time() - 60);
		$live = $this->story(self::ALICE);

		$this->assertSame([$live->getId()], array_map(static fn (Story $s): int => $s->getId(), $this->stories->getLiveByActor(self::ALICE)));
		$this->assertContains($expired->getId(), array_map(static fn (Story $s): int => $s->getId(), $this->stories->getExpired()));

		$this->stories->deleteByIds([$expired->getId()]);
		$this->expectException(ItemNotFoundException::class);
		$this->stories->getLiveById($expired->getId());
	}

	public function testTheBarReadsEveryonesLiveStoriesInOneQuery(): void {
		$a = $this->story(self::ALICE);
		$b = $this->story(self::BOB);

		$ids = array_map(static fn (Story $s): int => $s->getId(), $this->stories->getLiveByActors([self::ALICE, self::BOB]));

		$this->assertSame([$a->getId(), $b->getId()], $ids);
		$this->assertSame([], $this->stories->getLiveByActors([]));
	}

	public function testAViewIsCountedOncePerViewer(): void {
		$story = $this->story(self::ALICE);
		$other = $this->story(self::ALICE);

		$this->stories->markSeen($story->getId(), self::VIEWER);
		$this->stories->markSeen($story->getId(), self::VIEWER);

		$this->assertSame(1, $this->stories->countViews($story->getId()));
		$this->assertSame([$story->getId()], $this->stories->seenAmong([$story->getId(), $other->getId()], self::VIEWER));
		$this->assertSame([], $this->stories->seenAmong([], self::VIEWER));
	}

	/** a `Delete` for a story names it by its address, and only its author may send one */
	public function testAStoryIsWithdrawnByItsAddressAndOnlyByItsAuthor(): void {
		$story = $this->story(self::ALICE, 0, 'https://itest.example/stories/withdrawn');

		$this->stories->deleteBySourceId('https://itest.example/stories/withdrawn', self::BOB);
		$this->assertSame($story->getId(), $this->stories->getBySourceId('https://itest.example/stories/withdrawn')->getId());

		$this->stories->deleteBySourceId('https://itest.example/stories/withdrawn', self::ALICE);
		$this->expectException(ItemNotFoundException::class);
		$this->stories->getBySourceId('https://itest.example/stories/withdrawn');
	}

	public function testAnAuthorDeletesTheirOwnStoryOnly(): void {
		$story = $this->story(self::ALICE);

		$this->stories->delete(self::BOB, $story->getId());
		$this->assertSame($story->getId(), $this->stories->getLiveById($story->getId())->getId());

		$this->stories->delete(self::ALICE, $story->getId());
		$this->assertSame(0, $this->stories->countLiveByActor(self::ALICE));
	}

	public function testAnswersAreKeptOncePerSourceAndCountedPerViewer(): void {
		$story = $this->story(self::ALICE);
		$answer = (new StoryInteraction())
			->setStoryId($story->getId())
			->setActorId(self::VIEWER)
			->setType(StoryInteraction::TYPE_REACTION)
			->setContent("\u{1F525}")
			->setSourceId('https://itest.example/answers/1');

		$this->assertTrue($this->interactions->save($answer));
		$this->assertFalse($this->interactions->save((clone $answer)->setId(0)), 'the same answer delivered twice');
		$this->assertTrue($this->interactions->save((new StoryInteraction())
			->setStoryId($story->getId())
			->setActorId(self::VIEWER)
			->setType(StoryInteraction::TYPE_REPLY)
			->setContent('nice')
			->setSourceId('https://itest.example/answers/2')));

		$this->assertSame(2, $this->interactions->countByActor($story->getId(), self::VIEWER));
		$this->assertCount(2, $this->interactions->forStory($story->getId()));

		$this->interactions->deleteByStory($story->getId());
		$this->assertSame([], $this->interactions->forStory($story->getId()));
	}
}
