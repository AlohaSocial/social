<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\MoveInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Move;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamDest;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../ActivityPubTestCase.php';

// see also PersonTest for the alsoKnownAs import/export round-trip
#[AllowMockObjectsWithoutExpectations]
class MoveInterfaceTest extends ActivityPubTestCase {
	private const CAROL = 'https://other.example/users/carol';

	/** @var ActionsRequest&MockObject */
	private $actionsRequest;
	/** @var ReactionsRequest&Stub */
	private $reactionsRequest;
	/** @var CacheActorsRequest&MockObject */
	private $cacheActorsRequest;
	/** @var CacheDocumentsRequest&MockObject */
	private $cacheDocumentsRequest;
	/** @var FollowsRequest&MockObject */
	private $followsRequest;
	/** @var StreamRequest&MockObject */
	private $streamRequest;
	/** @var StreamDestRequest&MockObject */
	private $streamDestRequest;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var ActorsRequest&MockObject */
	private $actorsRequest;
	/** @var ActivityService&MockObject */
	private $activityService;
	private MoveInterface $handler;

	private Person $old;
	private Person $new;

	protected function setUp(): void {
		parent::setUp();

		$this->actionsRequest = $this->createMock(ActionsRequest::class);
		$this->reactionsRequest = $this->createStub(ReactionsRequest::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->streamDestRequest = $this->createMock(StreamDestRequest::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->activityService = $this->createMock(ActivityService::class);

		$this->handler = new MoveInterface(
			$this->actionsRequest,
			$this->reactionsRequest,
			$this->cacheActorsRequest,
			$this->cacheDocumentsRequest,
			$this->followsRequest,
			$this->streamRequest,
			$this->streamDestRequest,
			$this->cacheActorService,
			$this->actorsRequest,
			$this->activityService,
			new NullLogger(),
		);

		$this->old = $this->person(self::REMOTE_URL . '/users/bob');
		$this->new = $this->person('https://new.example/users/bob');
		$this->new->setAlsoKnownAs([$this->old->getId()]);
	}

	private function dest(string $streamId, string $type = 'recipient', string $subtype = 'to'): StreamDest {
		$dest = new StreamDest();
		$dest->setStreamId($streamId)
			->setActorId($this->old->getId())
			->setType($type)
			->setSubtype($subtype);

		return $dest;
	}

	/** A Move signed by bob's old server, pointing at the new account. */
	private function move(?string $origin = null): ACore {
		$move = $this->incoming(Move::TYPE, $this->old->getId() . '#moves/1', $this->old->getId(), null, $origin);
		$move->setObjectId($this->old->getId());
		$move->setTarget($this->new->getId());

		return $move;
	}

	public function testMoveAccountRepointsActionsDocumentsAndFollowsToTheNewActor(): void {
		$this->streamDestRequest->method('getRelatedToActor')->willReturn([]);

		$this->actionsRequest->expects($this->once())
			->method('moveAccount')->with($this->old->getId(), $this->new->getId());
		$this->cacheDocumentsRequest->expects($this->once())
			->method('moveAccount')->with($this->old->getId(), $this->new->getId());
		$this->followsRequest->expects($this->once())
			->method('moveAccountFollowers')->with($this->old->getId(), $this->identicalTo($this->new));
		$this->followsRequest->expects($this->once())
			->method('moveAccountFollowing')->with($this->old->getId(), $this->identicalTo($this->new));

		$this->handler->moveAccount($this->old, $this->new);
	}

	public function testMoveAccountReattributesTheOldActorsPosts(): void {
		$this->streamDestRequest->method('getRelatedToActor')->willReturn([]);

		$this->streamRequest->expects($this->once())
			->method('updateAuthor')->with($this->old->getId(), $this->new->getId());

		$this->handler->moveAccount($this->old, $this->new);
	}

	public function testMoveAccountRepointsRecipientReferencesIncludingTheCollections(): void {
		$this->streamDestRequest->method('getRelatedToActor')->willReturn([]);

		$moved = [];
		$this->streamDestRequest->method('moveActor')
			->willReturnCallback(function (string $from, string $to) use (&$moved): void {
				$moved[] = [$from, $to];
			});

		$this->handler->moveAccount($this->old, $this->new);

		$this->assertEqualsCanonicalizing([
			[$this->old->getId(), $this->new->getId()],
			[$this->old->getFollowers(), $this->new->getFollowers()],
			[$this->old->getFollowing(), $this->new->getFollowing()],
		], $moved);
	}

	public function testMoveAccountRewritesRecipientsOfStreamsAddressedToTheOldActor(): void {
		$direct = new Note();
		$direct->setId(self::REMOTE_URL . '/notes/direct');
		$direct->setTo($this->old->getId());

		$toFollowers = new Note();
		$toFollowers->setId(self::REMOTE_URL . '/notes/public');
		$toFollowers->setToArray([$this->old->getFollowers(), self::CAROL]);

		$ccActor = new Note();
		$ccActor->setId(self::REMOTE_URL . '/notes/cc');
		$ccActor->setCcArray([$this->old->getId(), self::CAROL]);

		$unrelated = new Note();
		$unrelated->setId(self::REMOTE_URL . '/notes/unrelated');
		$unrelated->setToArray([self::CAROL]);

		$streams = [];
		foreach ([$direct, $toFollowers, $ccActor, $unrelated] as $stream) {
			$streams[$stream->getId()] = $stream;
		}
		$this->streamRequest->method('getStream')->willReturnCallback(function (string $id) use ($streams): Stream {
			if (!isset($streams[$id])) {
				throw new StreamNotFoundException();
			}

			return $streams[$id];
		});
		$this->streamDestRequest->method('getRelatedToActor')->with($this->identicalTo($this->old))->willReturn([
			$this->dest($direct->getId()),
			$this->dest($toFollowers->getId()),
			$this->dest($ccActor->getId(), 'recipient', 'cc'),
			$this->dest($unrelated->getId()),
			$this->dest(self::REMOTE_URL . '/notes/gone'),
			$this->dest($toFollowers->getId(), 'hashtag', 'x'),
		]);

		$updated = [];
		$this->streamRequest->method('update')->willReturnCallback(function (Stream $stream) use (&$updated): void {
			$updated[] = $stream->getId();
		});

		$this->handler->moveAccount($this->old, $this->new);

		$this->assertSame($this->new->getId(), $direct->getTo());
		$this->assertEqualsCanonicalizing([self::CAROL, $this->new->getFollowers()], $toFollowers->getToArray());
		$this->assertEqualsCanonicalizing([self::CAROL, $this->new->getId()], $ccActor->getCcArray());
		$this->assertSame([self::CAROL], $unrelated->getToArray());
		$this->assertEqualsCanonicalizing([$direct->getId(), $toFollowers->getId(), $ccActor->getId()], $updated);
	}

	public function testIncomingMoveOfAKnownActorIsAppliedToItsTarget(): void {
		$this->cacheActorsRequest->method('getFromId')
			->with($this->old->getId())->willReturn($this->old);
		$this->cacheActorService->method('getFromId')
			->with($this->new->getId(), true)->willReturn($this->new);
		$this->streamDestRequest->method('getRelatedToActor')->willReturn([]);
		$this->followsRequest->expects($this->once())
			->method('moveAccountFollowers')->with($this->old->getId(), $this->identicalTo($this->new));

		$this->handler->processIncomingRequest($this->move());
	}

	/**
	 * The follows were re-pointed and the account that moved still looked like
	 * one that had stayed: its `movedTo` is read off its cached document and
	 * nothing wrote it there. The interop suite found it — and found that
	 * fetching the document again does not help, since Mastodon serves the
	 * pre-move one for minutes.
	 */
	public function testIncomingMoveRecordsOnTheMovedAccountWhereItWent(): void {
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->old);
		$this->cacheActorService->method('getFromId')->willReturn($this->new);
		$this->streamDestRequest->method('getRelatedToActor')->willReturn([]);
		$this->cacheActorsRequest->expects($this->once())
			->method('markMoved')->with($this->old->getId(), $this->new->getId());

		$this->handler->processIncomingRequest($this->move());
	}

	public function testIncomingMoveOfAnUnknownActorIsIgnored(): void {
		$this->cacheActorsRequest->method('getFromId')
			->willThrowException(new CacheActorDoesNotExistException());

		$this->cacheActorService->expects($this->never())->method('getFromId');
		$this->actionsRequest->expects($this->never())->method('moveAccount');
		$this->followsRequest->expects($this->never())->method('moveAccountFollowers');

		$this->handler->processIncomingRequest($this->move());
	}

	public function testIncomingMoveNotComingFromTheActorsOriginIsRefused(): void {
		$this->cacheActorsRequest->expects($this->never())->method('getFromId');
		$this->followsRequest->expects($this->never())->method('moveAccountFollowers');

		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest($this->move('evil.example'));
	}

	public function testIncomingMoveOfSomebodyElsesAccountIsRefused(): void {
		$move = $this->move();
		$move->setObjectId(self::CAROL);

		$this->cacheActorsRequest->expects($this->never())->method('getFromId');

		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest($move);
	}

	public function testIncomingMoveIsRefusedWhenTheTargetDoesNotAcknowledgeTheActor(): void {
		$this->new->setAlsoKnownAs(['https://new.example/users/someoneelse']);
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->old);
		$this->cacheActorService->method('getFromId')->willReturn($this->new);

		$this->actionsRequest->expects($this->never())->method('moveAccount');
		$this->followsRequest->expects($this->never())->method('moveAccountFollowers');
		$this->streamRequest->expects($this->never())->method('updateAuthor');

		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest($this->move());
	}

	public function testIncomingMoveIsRefusedWhenTheTargetHasNoAlsoKnownAs(): void {
		$this->new->setAlsoKnownAs([]);
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->old);
		$this->cacheActorService->method('getFromId')->willReturn($this->new);

		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest($this->move());
	}

	public function testTheTargetIsRefreshedSoTheGuardSeesACurrentAlsoKnownAs(): void {
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->old);
		$this->cacheActorService->expects($this->once())
			->method('getFromId')->with($this->new->getId(), true)->willReturn($this->new);
		$this->streamDestRequest->method('getRelatedToActor')->willReturn([]);

		$this->handler->processIncomingRequest($this->move());
	}

	// re-following the new account

	private function follow(string $actorId, string $objectId): Follow {
		$follow = new Follow();
		$follow->setId(self::LOCAL_URL . '/follows/' . md5($actorId . $objectId));
		$follow->setActorId($actorId);
		$follow->setObjectId($objectId);
		$follow->setAccepted(true);

		return $follow;
	}

	private function acceptMove(): void {
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->old);
		$this->cacheActorService->method('getFromId')->willReturn($this->new);
		$this->streamDestRequest->method('getRelatedToActor')->willReturn([]);
	}

	/**
	 * Rewriting the rows was only half of it: they then said the local user
	 * follows the new account while the new account's instance had never heard
	 * of them, so no posts arrived and a later unfollow sent an Undo for a
	 * follow that was never established.
	 */
	public function testALocalFollowerIsFollowedOverToTheNewAccount(): void {
		$this->acceptMove();
		$alice = self::LOCAL_URL . '/users/alice';
		$known = $this->follow($alice, $this->old->getId());
		$this->followsRequest->method('getFollowersByActorId')
			->with($this->old->getId())->willReturn([$known]);
		$this->actorsRequest->method('getFromId')->with($alice)
			->willReturn($this->person($alice, true));

		/** @var Follow|null $sent */
		$sent = null;
		$this->capture($this->activityService, 'request', $sent, 'token');

		$this->handler->processIncomingRequest($this->move());

		$this->assertInstanceOf(Follow::class, $sent);
		$this->assertSame($alice, $sent->getActorId());
		$this->assertSame($this->new->getId(), $sent->getObjectId());
		$this->assertSame($this->new->getFollowers(), $sent->getFollowId());
		// the id the local row already has, so the Accept matches it and a
		// later Undo names something the target knows
		$this->assertSame($known->getId(), $sent->getId());

		$paths = $sent->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame($this->new->getInbox(), $paths[0]->getUri());
	}

	/** A remote follower's own instance receives the same Move and acts on it. */
	public function testARemoteFollowerIsNotFollowedOverOnTheirBehalf(): void {
		$this->acceptMove();
		$carol = self::CAROL;
		$this->followsRequest->method('getFollowersByActorId')
			->willReturn([$this->follow($carol, $this->old->getId())]);
		$this->actorsRequest->method('getFromId')
			->willThrowException(new ActorDoesNotExistException());

		$this->activityService->expects($this->never())->method('request');

		$this->handler->processIncomingRequest($this->move());
	}

	public function testNothingIsSentToATargetWithoutAnInbox(): void {
		$this->new->setInbox('');
		$this->acceptMove();
		$alice = self::LOCAL_URL . '/users/alice';
		$this->followsRequest->method('getFollowersByActorId')
			->willReturn([$this->follow($alice, $this->old->getId())]);
		$this->actorsRequest->method('getFromId')->willReturn($this->person($alice, true));

		$this->activityService->expects($this->never())->method('request');

		$this->handler->processIncomingRequest($this->move());
	}

	/** One unreachable target must not stop the rest of the migration. */
	public function testAFailedRefollowIsSwallowed(): void {
		$this->acceptMove();
		$alice = self::LOCAL_URL . '/users/alice';
		$this->followsRequest->method('getFollowersByActorId')
			->willReturn([$this->follow($alice, $this->old->getId())]);
		$this->actorsRequest->method('getFromId')->willReturn($this->person($alice, true));
		$this->activityService->method('request')
			->willThrowException(new SocialAppConfigException());

		$this->streamRequest->expects($this->once())->method('updateAuthor');

		$this->handler->processIncomingRequest($this->move());
	}
}
