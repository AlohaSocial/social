<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Object;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Object\AnnounceInterface;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Model\StreamQueue;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;

require_once __DIR__ . '/../ActivityPubTestCase.php';

#[AllowMockObjectsWithoutExpectations]
class AnnounceInterfaceTest extends ActivityPubTestCase {
	private const POST = self::LOCAL_URL . '/notes/1';

	/** @var StreamRequest&MockObject */
	private $streamRequest;
	/** @var ActionsRequest&MockObject */
	private $actionsRequest;
	/** @var StreamQueueService&MockObject */
	private $streamQueueService;
	/** @var CacheActorService&Stub */
	private $cacheActorService;
	/** @var MiscService&Stub */
	private $miscService;
	private AnnounceInterface $handler;

	private Person $alice;
	private Person $bob;
	private Person $carol;

	protected function setUp(): void {
		parent::setUp();

		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->actionsRequest = $this->createMock(ActionsRequest::class);
		$this->streamQueueService = $this->createMock(StreamQueueService::class);
		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->miscService = $this->createStub(MiscService::class);

		$this->handler = new AnnounceInterface(
			$this->streamRequest,
			$this->actionsRequest,
			$this->streamQueueService,
			$this->cacheActorService,
			$this->miscService,
			$this->createStub(\OCA\Social\Service\NotificationService::class)
		);

		$this->alice = $this->person(self::LOCAL_URL . '/users/alice', true);
		$this->bob = $this->person(self::REMOTE_URL . '/users/bob');
		$this->carol = $this->person('https://other.example/users/carol');
		$this->cacheActorService->method('getFromId')->willReturnCallback(function (string $id): Person {
			if ($id === $this->bob->getId()) {
				return $this->bob;
			}

			throw new CacheActorDoesNotExistException();
		});
	}

	/** bob (remote) boosts something, alice's local post unless told otherwise. */
	private function incomingAnnounce(string $objectId = self::POST, string $origin = self::REMOTE_HOST): Announce {
		$announce = new Announce();
		$announce->setId(self::REMOTE_URL . '/announces/1');
		$announce->setActorId($this->bob->getId());
		$announce->setObjectId($objectId);
		$announce->setRequestToken('token-1');
		$announce->setOrigin($origin, SignatureService::ORIGIN_HEADER, time());

		return $announce;
	}

	/** The boosted post as it is stored here: public, or nobody could have boosted it. */
	private function post(bool $local = true, int $remoteBoosts = 0): Note {
		$post = $this->note(self::POST, $this->alice->getId(), $local);
		$post->setTo(Stream::CONTEXT_PUBLIC);
		$post->setDetailInt('remote_boosts', $remoteBoosts);

		return $post;
	}

	/** The same post addressed to alice's followers only — never legitimately boosted. */
	private function followersOnlyPost(): Note {
		$post = $this->note(self::POST, $this->alice->getId(), true);
		$post->setTo($this->alice->getFollowers());

		return $post;
	}

	/** An Announce stream already stored for the post: carol's unless told otherwise. */
	private function knownAnnounce(?Person $booster = null, string ...$ccFollowers): Announce {
		$booster ??= $this->carol;
		$known = new Announce();
		$known->setId(self::REMOTE_URL . '/announces/0');
		$known->setAttributedTo($booster->getId());
		$known->setCcArray($ccFollowers === [] ? [$booster->getFollowers()] : $ccFollowers);

		return $known;
	}

	private function boostNotification(string ...$accounts): SocialAppNotification {
		$notification = new SocialAppNotification();
		$notification->setId(self::POST . '/notification+boost');
		foreach ($accounts as $account) {
			$notification->addDetail('accounts', $account);
		}

		return $notification;
	}

	/**
	 * getStreamByObjectId() answers by requested stream type; a type not listed
	 * is "not found". getAnnounceBy() answers with the listed Announce only to
	 * the booster it is attributed to.
	 */
	private function storedStreamsByType(array $byType): void {
		$this->streamRequest->method('getStreamByObjectId')
			->willReturnCallback(function (string $objectId, string $type) use ($byType): Stream {
				if (!isset($byType[$type])) {
					throw new StreamNotFoundException();
				}

				return $byType[$type];
			});
		$this->streamRequest->method('getAnnounceBy')
			->willReturnCallback(function (string $objectId, string $actorId) use ($byType): Stream {
				$known = $byType[Announce::TYPE] ?? null;
				if ($known === null || strcasecmp($known->getAttributedTo(), $actorId) !== 0) {
					throw new StreamNotFoundException();
				}

				return $known;
			});
	}

	private function noStoredAction(): void {
		$this->actionsRequest->method('getActionFromItem')->willThrowException(new ActionDoesNotExistException());
	}

	public function testFirstBoostOfAnObjectIsStoredAsAnAnnounceAttributedToTheBooster(): void {
		$this->storedStreamsByType([]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$announce = $this->incomingAnnounce(self::REMOTE_URL . '/notes/9');

		$saved = null;
		$this->capture($this->streamRequest, 'save', $saved);

		$this->handler->processIncomingRequest($announce);

		$this->assertSame($announce, $saved);
		$this->assertSame($this->bob->getId(), $announce->getAttributedTo());
		$this->assertSame(
			self::REMOTE_URL . '/notes/9',
			$announce->getCache()->getItem(self::REMOTE_URL . '/notes/9')->getUrl()
		);
	}

	public function testFirstBoostOfAnObjectWeDoNotHaveYetQueuesItForFetching(): void {
		$this->storedStreamsByType([]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$announce = $this->incomingAnnounce(self::REMOTE_URL . '/notes/9');

		$this->streamQueueService->expects($this->once())
			->method('generateStreamQueue')
			->with('token-1', StreamQueue::TYPE_CACHE, $announce->getId());
		$this->actionsRequest->expects($this->never())->method('save');
		$this->notificationInterface->expects($this->never())->method('save');

		$this->handler->processIncomingRequest($announce);
	}

	/**
	 * One Announce row per booster. Before, a second booster was folded into
	 * the first one's row — re-attributed to the newcomer, with their followers
	 * added to `cc` — so the row said the wrong person boosted, and an Undo
	 * from either of them had one row to aim at.
	 */
	public function testASecondBoosterOfAnObjectGetsARowOfTheirOwn(): void {
		$known = $this->knownAnnounce($this->carol);
		$this->storedStreamsByType([Announce::TYPE => $known]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$announce = $this->incomingAnnounce();

		$saved = null;
		$this->capture($this->streamRequest, 'save', $saved);
		$this->streamRequest->expects($this->never())->method('update');

		$this->handler->processIncomingRequest($announce);

		$this->assertSame($announce, $saved);
		$this->assertSame($this->bob->getId(), $announce->getAttributedTo());
		// carol's row is hers and says so still
		$this->assertSame($this->carol->getId(), $known->getAttributedTo());
		$this->assertSame([$this->carol->getFollowers()], $known->getCcArray());
	}

	public function testTheSameBoosterAnnouncingAgainIsNotStoredTwice(): void {
		$known = $this->knownAnnounce($this->bob);
		$this->storedStreamsByType([Announce::TYPE => $known]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$this->streamRequest->expects($this->never())->method('update');
		$this->streamRequest->expects($this->never())->method('save');

		$this->handler->processIncomingRequest($this->incomingAnnounce());
	}

	public function testBoostOfALocalPostIsCountedOnThePost(): void {
		$this->storedStreamsByType([]);
		$this->noStoredAction();
		$post = $this->post(true, 1);
		$this->streamRequest->method('getStreamById')->willReturn($post);
		$announce = $this->incomingAnnounce();

		$this->actionsRequest->expects($this->once())->method('save')->with($this->identicalTo($announce));
		// recounted in the statement that stores it, never written as a number
		// counted beforehand: see StreamRecountTest
		$this->streamRequest->expects($this->once())->method('recount')
			->with($this->identicalTo($post), Details::BOOSTS);
		$this->streamRequest->expects($this->never())->method('updateDetails');

		$this->handler->processIncomingRequest($announce);
	}

	public function testBoostOfALocalPostNotifiesItsAuthor(): void {
		$this->storedStreamsByType([]);
		$this->noStoredAction();
		$this->streamRequest->method('getStreamById')->willReturn($this->post());

		$notification = null;
		$this->capture($this->notificationInterface, 'save', $notification);

		$this->handler->processIncomingRequest($this->incomingAnnounce());

		$this->assertInstanceOf(SocialAppNotification::class, $notification);
		$this->assertSame(Announce::TYPE, $notification->getSubType());
		$this->assertSame($this->alice->getId(), $notification->getTo());
		$this->assertSame(self::POST, $notification->getObjectId());
		$this->assertSame(self::POST . '/notification+boost', $notification->getId());
		$this->assertSame(['bob@remote.example'], $notification->getDetails('accounts'));
		$this->assertTrue($notification->isLocal());
	}

	public function testFurtherBoostsJoinTheExistingNotification(): void {
		$existing = $this->boostNotification('carol@other.example');
		$this->storedStreamsByType([SocialAppNotification::TYPE => $existing]);
		$this->noStoredAction();
		$this->streamRequest->method('getStreamById')->willReturn($this->post());

		$this->notificationInterface->expects($this->never())->method('save');
		$this->notificationInterface->expects($this->once())->method('update')->with($this->identicalTo($existing));

		$this->handler->processIncomingRequest($this->incomingAnnounce());

		$this->assertSame(['carol@other.example', 'bob@remote.example'], $existing->getDetails('accounts'));
	}

	public function testBoostOfARemotePostDoesNotNotifyAnyone(): void {
		$this->storedStreamsByType([]);
		$this->noStoredAction();
		$this->streamRequest->method('getStreamById')->willReturn($this->post(false));

		$this->streamRequest->expects($this->once())->method('recount');
		$this->notificationInterface->expects($this->never())->method('save');
		$this->notificationInterface->expects($this->never())->method('update');

		$this->handler->processIncomingRequest($this->incomingAnnounce());
	}

	public function testBoostAlreadyCountedIsNotCountedTwice(): void {
		$this->storedStreamsByType([]);
		$announce = $this->incomingAnnounce();
		$this->actionsRequest->method('getActionFromItem')->willReturn($announce);
		$this->streamRequest->method('getStreamById')->willReturn($this->post());

		$this->actionsRequest->expects($this->never())->method('save');
		$this->streamRequest->expects($this->once())->method('recount');

		$this->handler->processIncomingRequest($announce);
	}

	public function testBoostNotSignedByTheBoostersServerIsRefused(): void {
		$this->streamRequest->expects($this->never())->method('save');
		$this->actionsRequest->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest($this->incomingAnnounce(self::POST, 'evil.example'));
	}

	public function testBoostOfAFollowersOnlyPostIsNotStored(): void {
		$this->storedStreamsByType([]);
		$this->noStoredAction();
		$this->streamRequest->method('getStreamById')->willReturn($this->followersOnlyPost());

		// a boost carries the audience of the booster: storing this one would
		// hand alice's followers-only post to everyone bob reaches
		$this->streamRequest->expects($this->never())->method('save');
		$this->streamQueueService->expects($this->never())->method('generateStreamQueue');
		$this->actionsRequest->expects($this->never())->method('save');
		$this->streamRequest->expects($this->never())->method('recount');
		$this->notificationInterface->expects($this->never())->method('save');

		$this->handler->processIncomingRequest($this->incomingAnnounce());
	}

	public function testAnotherBoosterOfAFollowersOnlyPostIsNotStored(): void {
		$known = $this->knownAnnounce($this->carol);
		$this->storedStreamsByType([Announce::TYPE => $known]);
		$this->streamRequest->method('getStreamById')->willReturn($this->followersOnlyPost());

		$this->streamRequest->expects($this->never())->method('update');
		$this->streamRequest->expects($this->never())->method('save');

		$this->handler->processIncomingRequest($this->incomingAnnounce());

		$this->assertSame([$this->carol->getFollowers()], array_values($known->getCcArray()));
	}

	public function testUndoDropsTheBoostersOwnAnnounce(): void {
		$announce = $this->incomingAnnounce();
		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', $this->bob->getId(), $announce);
		$known = $this->knownAnnounce($this->bob);
		$notification = $this->boostNotification('bob@remote.example');
		$this->storedStreamsByType([Announce::TYPE => $known, SocialAppNotification::TYPE => $notification]);
		$this->actionsRequest->method('getActionFromItem')->willReturn($announce);
		$this->streamRequest->method('getStreamById')->willReturn($this->post());

		$this->streamRequest->expects($this->once())->method('deleteById')->with($known->getId(), Announce::TYPE);
		$this->streamRequest->expects($this->never())->method('update');
		$this->actionsRequest->expects($this->once())->method('delete')->with($this->identicalTo($announce));
		$this->streamRequest->expects($this->once())->method('recount');
		$this->notificationInterface->expects($this->once())->method('delete')->with($this->identicalTo($notification));

		$this->handler->activity($undo, $announce);
	}

	/**
	 * Another booster's row is theirs: an Undo from bob leaves carol's row —
	 * including a row stored before boosts had one row each, which carries
	 * bob's followers in `cc` under carol's name — exactly as it is. The
	 * action and the notification are still taken back.
	 */
	public function testUndoLeavesAnotherBoostersAnnounceAlone(): void {
		$announce = $this->incomingAnnounce();
		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', $this->bob->getId(), $announce);
		$known = $this->knownAnnounce($this->carol, $this->carol->getFollowers(), $this->bob->getFollowers());
		$notification = $this->boostNotification('bob@remote.example', 'carol@other.example');
		$this->storedStreamsByType([Announce::TYPE => $known, SocialAppNotification::TYPE => $notification]);
		$this->actionsRequest->method('getActionFromItem')->willReturn($announce);
		$this->streamRequest->method('getStreamById')->willReturn($this->post());

		$this->streamRequest->expects($this->never())->method('deleteById');
		$this->streamRequest->expects($this->never())->method('update');
		$this->actionsRequest->expects($this->once())->method('delete')->with($this->identicalTo($announce));
		$this->notificationInterface->expects($this->never())->method('delete');
		$this->notificationInterface->expects($this->once())->method('update')->with($this->identicalTo($notification));

		$this->handler->activity($undo, $announce);

		$this->assertSame($this->carol->getId(), $known->getAttributedTo());
		$this->assertSame(
			[$this->carol->getFollowers(), $this->bob->getFollowers()], array_values($known->getCcArray())
		);
		$this->assertSame(['carol@other.example'], array_values($notification->getDetails('accounts')));
	}

	public function testUndoOfAnUnknownBoostIsHarmless(): void {
		$announce = $this->incomingAnnounce();
		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', $this->bob->getId(), $announce);
		$this->storedStreamsByType([]);
		$this->noStoredAction();
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$this->streamRequest->expects($this->never())->method('deleteById');
		$this->actionsRequest->expects($this->never())->method('delete');
		$this->notificationInterface->expects($this->never())->method('delete');

		$this->handler->activity($undo, $announce);
	}

	public function testUndoNotComingFromTheBoostersServerIsRefused(): void {
		$announce = $this->incomingAnnounce();
		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', $this->bob->getId(), $announce, 'evil.example');

		$this->streamRequest->expects($this->never())->method('deleteById');
		$this->actionsRequest->expects($this->never())->method('delete');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($undo, $announce);
	}

	public function testAnnouncesCannotBeLookedUpByItem(): void {
		$this->expectException(ItemNotFoundException::class);

		$this->handler->getItem($this->incomingAnnounce());
	}

	public function testAnnouncesCannotBeLookedUpById(): void {
		$this->expectException(ItemNotFoundException::class);

		$this->handler->getItemById(self::REMOTE_URL . '/announces/1');
	}
}
