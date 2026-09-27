<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Interfaces\Activity\AddInterface;
use OCA\Social\Interfaces\Activity\FeaturedCollection;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Add;
use OCA\Social\Model\ActivityPub\Object\Story;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

require_once __DIR__ . '/../ActivityPubTestCase.php';

/**
 * The story half of `Add`: Pixelfed publishes a story with it, addressed to
 * the author's followers. The pin half — `Add` with a `featured` target — is
 * covered by FeaturedCollectionTest.
 */
#[AllowMockObjectsWithoutExpectations]
class AddInterfaceTest extends ActivityPubTestCase {
	private const BOB = self::REMOTE_URL . '/users/bob';

	private AddInterface $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->handler = new AddInterface($this->createStub(FeaturedCollection::class));
	}

	private function addStory(string $actorId, string $attributedTo): ACore {
		$story = new Story();
		$story->setId(self::REMOTE_URL . '/stories/1');
		$story->setAttributedTo($attributedTo);

		return $this->incoming(Add::TYPE, self::REMOTE_URL . '/adds/1', $actorId, $story);
	}

	public function testAStoryIsHandedToTheStoryInterfaceWithTheActivitysOrigin(): void {
		$add = $this->addStory(self::BOB, self::BOB);

		/** @var Story|null $saved */
		$saved = null;
		$this->storyInterface->expects($this->once())->method('save')
			->willReturnCallback(function (ACore $item) use (&$saved): void {
				$saved = $item;
			});

		$this->handler->processIncomingRequest($add);

		$this->assertInstanceOf(Story::class, $saved);
		$this->assertSame(self::REMOTE_HOST, $saved->getOrigin());
	}

	/**
	 * The author is whoever performed the Add. The origin check the story then
	 * goes through is host-wide, so a payload naming a neighbour as
	 * `attributedTo` published a story onto that neighbour's profile.
	 */
	public function testTheStorysAuthorIsTheActorThatAddedIt(): void {
		$add = $this->addStory(self::BOB, self::REMOTE_URL . '/users/gargron');

		/** @var Story|null $saved */
		$saved = null;
		$this->storyInterface->expects($this->once())->method('save')
			->willReturnCallback(function (ACore $item) use (&$saved): void {
				$saved = $item;
			});

		$this->handler->processIncomingRequest($add);

		$this->assertInstanceOf(Story::class, $saved);
		$this->assertSame(self::BOB, $saved->getAttributedTo());
	}
}
