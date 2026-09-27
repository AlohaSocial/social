<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Migration\CacheFeaturedCollections;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\PinService;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Pins against the real actions table: they round-trip, are scoped to one
 * actor and one action type, and the pinned posts are read back through the
 * stream both viewer-bound (the profile) and viewer-less (the federated
 * featured collection). The unit suite covers the rules with mocks; only this
 * proves the queries work on every supported database.
 */
class PinnedPostsTest extends TestCase {
	private const AUTHOR = 'https://cloud.example.org/pintest/@alice';
	private const OTHER = 'https://cloud.example.org/pintest/@bob';

	private const NOTES = [1, 2, 3];

	private PinService $pinService;
	private ActionsRequest $actionsRequest;
	private StreamRequest $streamRequest;
	private StreamDestRequest $streamDestRequest;
	private CacheActorsRequest $cacheActorsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->pinService = Server::get(PinService::class);
		$this->actionsRequest = Server::get(ActionsRequest::class);
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->streamDestRequest = Server::get(StreamDestRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->cleanup();

		// reading a post back joins its author in the actor cache
		$author = new Person();
		$author->setId(self::AUTHOR)
			->setPreferredUsername('pintest-alice');
		$author->setAccount('pintest-alice@cloud.example.org');
		$this->cacheActorsRequest->save($author);
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->actionsRequest->deleteByActor(self::AUTHOR);
		$this->actionsRequest->deleteByActor(self::OTHER);
		foreach (self::NOTES as $n) {
			$this->streamRequest->deleteById($this->noteId($n), Note::TYPE);
		}
		$this->cacheActorsRequest->deleteCacheById(self::AUTHOR);
	}

	private function noteId(int $n): string {
		return self::AUTHOR . '/notes/' . $n;
	}

	private function pin(string $actorId, string $objectId): void {
		$pin = new Like();
		$pin->setType(PinService::TYPE);
		$pin->setId($objectId . '#pin/' . md5($actorId));
		$pin->setActorId($actorId);
		$pin->setObjectId($objectId);
		$this->actionsRequest->save($pin);
	}

	private function storeNote(int $n): Note {
		$note = new Note();
		$note->setId($this->noteId($n));
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setVisibility('public');
		$note->setContent('<p>pinned ' . $n . '</p>');
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);
		$this->streamDestRequest->generateStreamDest($note);

		return $note;
	}

	private function viewer(): Person {
		$viewer = new Person();
		$viewer->setId(self::AUTHOR);
		$viewer->setLocal(true);

		return $viewer;
	}

	public function testAnActorWithoutPinsHasNone(): void {
		$this->assertSame([], $this->pinService->getPinnedIds(self::AUTHOR));
	}

	public function testPinsRoundTripAndAreScopedToTheirActor(): void {
		$this->pin(self::AUTHOR, $this->noteId(1));
		$this->pin(self::OTHER, $this->noteId(2));

		$this->assertSame([$this->noteId(1)], $this->pinService->getPinnedIds(self::AUTHOR));
		$this->assertSame([$this->noteId(2)], $this->pinService->getPinnedIds(self::OTHER));
		$this->assertTrue($this->pinService->isPinned(self::AUTHOR, $this->noteId(1)));
		$this->assertFalse($this->pinService->isPinned(self::AUTHOR, $this->noteId(2)));
	}

	public function testUnpinningRemovesOnlyThatOnePin(): void {
		foreach (self::NOTES as $n) {
			$this->pin(self::AUTHOR, $this->noteId($n));
		}

		$this->actionsRequest->deleteAction(self::AUTHOR, $this->noteId(2), PinService::TYPE);

		$remaining = $this->pinService->getPinnedIds(self::AUTHOR);
		sort($remaining);
		$this->assertSame([$this->noteId(1), $this->noteId(3)], $remaining);
	}

	public function testUnpinningNeverTouchesAnotherActorsPinOfTheSamePost(): void {
		$this->pin(self::AUTHOR, $this->noteId(1));
		$this->pin(self::OTHER, $this->noteId(1));

		$this->actionsRequest->deleteAction(self::AUTHOR, $this->noteId(1), PinService::TYPE);

		$this->assertSame([], $this->pinService->getPinnedIds(self::AUTHOR));
		$this->assertSame([$this->noteId(1)], $this->pinService->getPinnedIds(self::OTHER));
	}

	public function testUnpinningLeavesOtherActionTypesOnTheSamePostAlone(): void {
		$this->pin(self::AUTHOR, $this->noteId(1));

		$like = new Like();
		$like->setId($this->noteId(1) . '#like');
		$like->setActorId(self::AUTHOR);
		$like->setObjectId($this->noteId(1));
		$this->actionsRequest->save($like);

		$this->actionsRequest->deleteAction(self::AUTHOR, $this->noteId(1), PinService::TYPE);

		$this->assertSame([], $this->pinService->getPinnedIds(self::AUTHOR));
		$this->assertSame(
			1,
			$this->actionsRequest->countActions($this->noteId(1), Like::TYPE),
			'the like on the same post is untouched'
		);
	}

	public function testThePinnedPostsAreReadBackForBothTheProfileAndFederation(): void {
		$this->storeNote(1);
		$this->pin(self::AUTHOR, $this->noteId(1));

		// viewer-less: the federated featured collection
		$federated = $this->pinService->getPinnedPosts(self::AUTHOR);
		$this->assertCount(1, $federated);
		$this->assertSame($this->noteId(1), $federated[0]->getId());
		$this->assertTrue($federated[0]->isPinned());

		// viewer-bound: the profile of the author themselves
		$own = $this->pinService->getPinnedPosts(self::AUTHOR, $this->viewer());
		$this->assertCount(1, $own);
		$this->assertSame($this->noteId(1), $own[0]->getId());
		$this->assertTrue($own[0]->isPinned());
	}

	public function testAPinPointingAtAPostThatIsGoneIsSkipped(): void {
		$this->storeNote(1);
		$this->pin(self::AUTHOR, $this->noteId(1));
		$this->pin(self::AUTHOR, $this->noteId(2)); // never stored

		$posts = $this->pinService->getPinnedPosts(self::AUTHOR);

		$this->assertCount(1, $posts, 'a deleted post never breaks the profile');
		$this->assertSame($this->noteId(1), $posts[0]->getId());
	}

	public function testTheRepairStepPublishesTheFeaturedCollectionOfLocalActors(): void {
		Server::get(CacheFeaturedCollections::class)->run($this->createStub(IOutput::class));

		// an actor row whose Nextcloud user is gone cannot be cached at all —
		// the step warns and moves on — so only the cached ones are asserted
		$cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$checked = 0;
		foreach (Server::get(ActorsRequest::class)->getAll() as $local) {
			try {
				$cached = $cacheActorsRequest->getFromLocalAccount($local->getPreferredUsername());
			} catch (CacheActorDoesNotExistException $e) {
				continue;
			}
			// the cached actor is what remote servers are served, and it has to
			// tell them where the pinned posts live
			$this->assertSame(
				$cached->getId() . '/collections/featured',
				$cached->getFeatured(),
				'remote servers learn where to fetch the pinned posts'
			);
			$checked++;
		}

		if ($checked === 0) {
			$this->markTestSkipped('no cacheable local actor on this instance');
		}
	}

	public function testMarkPinnedFlagsThePinnedPostOfAPage(): void {
		$page = [$this->storeNote(1), $this->storeNote(2)];
		$this->pin(self::AUTHOR, $this->noteId(2));

		$this->pinService->markPinned($page, self::AUTHOR);

		$this->assertFalse($page[0]->isPinned());
		$this->assertTrue($page[1]->isPinned());
	}
}
