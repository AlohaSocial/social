<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\PostHoldsRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\HeldPost;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The review queue: a held post is the request, kept once however often it
 * is sent, and readable by the moderator and by its own author only.
 */
class PostHoldsRequestTest extends TestCase {
	private const ALICE = 'https://itest.example/users/held-alice';
	private const BOB = 'https://itest.example/users/held-bob';

	private PostHoldsRequest $holds;

	protected function setUp(): void {
		parent::setUp();
		$this->holds = Server::get(PostHoldsRequest::class);
		$this->holds->deleteByActor(self::ALICE);
		$this->holds->deleteByActor(self::BOB);
	}

	protected function tearDown(): void {
		$this->holds->deleteByActor(self::ALICE);
		$this->holds->deleteByActor(self::BOB);
		parent::tearDown();
	}

	private function held(string $actor, string $text): HeldPost {
		return (new HeldPost())
			->setActorId($actor)
			->setParams(['text' => $text, 'visibility' => 'public'])
			->setReason(HeldPost::REASON_FIRST_POST);
	}

	public function testAHeldPostIsKeptWithWhatItAskedFor(): void {
		$id = $this->holds->save($this->held(self::ALICE, 'hello fediverse'));

		$this->assertGreaterThan(0, $id);
		$stored = $this->holds->getById($id);
		$this->assertSame(self::ALICE, $stored->getActorId());
		$this->assertSame(HeldPost::REASON_FIRST_POST, $stored->getReason());
		$this->assertGreaterThan(0, $stored->getCreation());
	}

	/** a client told its post was held presses again; that is still one post */
	public function testTheSamePostSentAgainIsNotASecondRow(): void {
		$this->assertGreaterThan(0, $this->holds->save($this->held(self::ALICE, 'same text')));
		$this->assertSame(0, $this->holds->save($this->held(self::ALICE, 'same text')));
		$this->assertSame(1, $this->holds->countForActor(self::ALICE));

		$this->assertGreaterThan(0, $this->holds->save($this->held(self::BOB, 'same text')), 'another author is another post');
	}

	public function testAnAuthorReadsOnlyTheirOwn(): void {
		$id = $this->holds->save($this->held(self::ALICE, 'mine'));

		$this->assertSame($id, $this->holds->getByIdForActor($id, self::ALICE)->getId());
		$this->expectException(ItemNotFoundException::class);
		$this->holds->getByIdForActor($id, self::BOB);
	}

	public function testTheAuthorsListIsNewestFirstAndTheQueueOldestFirst(): void {
		$first = $this->holds->save($this->held(self::ALICE, 'one'));
		$second = $this->holds->save($this->held(self::ALICE, 'two'));

		$this->assertSame([$second, $first], array_map(static fn (HeldPost $h): int => $h->getId(), $this->holds->getByActor(self::ALICE)));

		$queue = array_values(array_filter(
			$this->holds->page(500),
			static fn (HeldPost $h): bool => $h->getActorId() === self::ALICE
		));
		$this->assertSame([$first, $second], array_map(static fn (HeldPost $h): int => $h->getId(), $queue));
		$this->assertGreaterThanOrEqual(2, $this->holds->countAll());
	}

	public function testDeletingTakesTheRowOut(): void {
		$id = $this->holds->save($this->held(self::ALICE, 'gone soon'));

		$this->assertTrue($this->holds->delete($id));
		$this->assertFalse($this->holds->delete($id));
		$this->assertSame(0, $this->holds->countForActor(self::ALICE));
	}
}
