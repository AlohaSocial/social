<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\MediaTagsRequest;
use OCA\Social\Db\PlacesRequest;
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Db\StreamCardsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Place;
use OCA\Social\Model\StreamCard;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\PostDecorationService;
use OCA\Social\Service\ReactionSummaryService;
use OCA\Social\Service\RemoteFetchQueue;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What a client is shown on a post besides the post itself: its card, its
 * place, its reaction bar and the people in its pictures — one query per kind
 * for the whole list, however many posts are on it.
 */
#[AllowMockObjectsWithoutExpectations]
class PostDecorationServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/users/alice';
	private const BOB = 'https://cloud.example/users/bob';

	private StreamCardsRequest&MockObject $cards;
	private PlacesRequest&MockObject $places;
	private ReactionsRequest&MockObject $reactions;
	private MediaTagsRequest&MockObject $mediaTags;
	private CacheActorService&MockObject $cacheActorService;
	private RemoteFetchQueue&MockObject $queue;
	private PostDecorationService $service;

	protected function setUp(): void {
		$this->cards = $this->createMock(StreamCardsRequest::class);
		$this->places = $this->createMock(PlacesRequest::class);
		$this->reactions = $this->createMock(ReactionsRequest::class);
		$this->mediaTags = $this->createMock(MediaTagsRequest::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->queue = $this->createMock(RemoteFetchQueue::class);

		$this->service = new PostDecorationService(
			new LinkPreviewService($this->cards, $this->createStub(CurlService::class), new NullLogger()),
			new PlaceService($this->places, $this->createStub(StreamRequest::class)),
			new ReactionSummaryService($this->reactions, $this->createStub(EmojiService::class)),
			$this->mediaTags,
			$this->cacheActorService,
			$this->queue,
		);
	}

	private function post(int $nid, int $placeId = 0): Note {
		$post = new Note();
		$post->setId('https://cloud.example/notes/' . $nid);
		$post->setNid($nid);
		$post->setPlaceId($placeId);

		return $post;
	}

	private function reaction(string $actor, string $emoji): EmojiReact {
		$reaction = new EmojiReact();
		$reaction->setActorId($actor);
		$reaction->setContent($emoji);

		return $reaction;
	}

	public function testEveryKindIsReadOnceForTheWholeList(): void {
		$first = $this->post(1, 9);
		$second = $this->post(2, 9);
		$bob = (new Person())->setId(self::BOB);
		$card = (new StreamCard())->setUrl('https://example.org/article');
		$lisbon = (new Place())->setId(9)->setName('Lisbon');

		$this->cards->expects($this->once())->method('getByStreamIds')
			->with([$first->getId(), $second->getId()])
			->willReturn([md5($first->getId()) => $card]);
		$this->places->expects($this->once())->method('getByIds')->with([9])->willReturn([9 => $lisbon]);
		$this->reactions->expects($this->once())->method('getByObjectIds')
			->with([$first->getId(), $second->getId()])
			->willReturn([md5($second->getId()) => [$this->reaction(self::ALICE, '🎉'), $this->reaction(self::BOB, '🎉')]]);
		$this->mediaTags->expects($this->once())->method('forStreams')->with(['1', '2'])->willReturn([1 => [self::BOB]]);
		$this->cacheActorService->expects($this->once())->method('getCachedFromIds')->with([self::BOB])->willReturn([self::BOB => $bob]);

		$this->service->decorate([$first, $second], self::ALICE);

		$this->assertSame($card, $first->getCard());
		$this->assertNull($second->getCard());
		$this->assertSame($lisbon, $first->getPlace());
		$this->assertSame($lisbon, $second->getPlace());
		$this->assertSame([], $first->getReactions());
		$this->assertSame([['name' => '🎉', 'count' => 2, 'me' => true]], $second->getReactions());
		$this->assertSame([$bob], $first->getTaggedPeople());
		$this->assertSame([], $second->getTaggedPeople());
	}

	/** An anonymous reader is nobody in a reaction bar. */
	public function testAnAnonymousReaderHasNoReactionOfTheirOwn(): void {
		$post = $this->post(1);
		$this->reactions->method('getByObjectIds')->willReturn([md5($post->getId()) => [$this->reaction(self::ALICE, '👍')]]);

		$this->service->decorate([$post]);

		$this->assertSame([['name' => '👍', 'count' => 1, 'me' => false]], $post->getReactions());
	}

	/**
	 * The people named in the pictures come out of the actor cache only;
	 * somebody not cached is left off this page and fetched in the
	 * background, never over HTTP while the page is being built.
	 */
	public function testTaggedPeopleAreReadFromTheCacheAndMissesAreQueued(): void {
		$bob = (new Person())->setId(self::BOB);
		$this->mediaTags->method('forStreams')->willReturn([
			1 => [self::BOB, 'https://gone.example/users/carol'],
			2 => [self::BOB],
		]);
		$this->cacheActorService->expects($this->never())->method('getFromId');
		$this->cacheActorService->expects($this->once())->method('getCachedFromIds')
			->with([self::BOB, 'https://gone.example/users/carol'])
			->willReturn([self::BOB => $bob]);
		$this->queue->expects($this->once())->method('resolveActors')->with(['https://gone.example/users/carol']);
		$first = $this->post(1);
		$second = $this->post(2);

		$this->service->decorate([$first, $second]);

		$this->assertSame([$bob], $first->getTaggedPeople());
		$this->assertSame([$bob], $second->getTaggedPeople());
		$this->assertSame(ACore::FORMAT_LOCAL, $bob->getExportFormat());
	}

	public function testAnEmptyListAsksNothing(): void {
		$this->cards->expects($this->never())->method('getByStreamIds');
		$this->places->expects($this->never())->method('getByIds');
		$this->reactions->expects($this->never())->method('getByObjectIds');
		$this->mediaTags->expects($this->never())->method('forStreams');
		$this->cacheActorService->expects($this->never())->method('getCachedFromIds');

		$this->service->decorate([], self::ALICE);
	}
}
