<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Object;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Object\NoteInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Model\StreamQueue;
use OCA\Social\Service\ForwardService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\PushService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StatusRevisionService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;

require_once __DIR__ . '/../ActivityPubTestCase.php';

#[AllowMockObjectsWithoutExpectations]
class NoteInterfaceTest extends ActivityPubTestCase {
	private const NOTE = self::REMOTE_URL . '/notes/1';
	private const PARENT = self::LOCAL_URL . '/notes/parent';

	/** @var StreamRequest&MockObject */
	private $streamRequest;
	/** @var CacheActorsRequest&Stub */
	private $cacheActorsRequest;
	/** @var PollService&Stub */
	private $pollService;
	/** @var PushService&MockObject */
	private $pushService;
	private StreamQueueService|MockObject $streamQueueService;
	private LinkPreviewService|MockObject $linkPreviewService;
	private ForwardService|MockObject $forwardService;
	private NoteInterface $handler;

	private Person $alice;
	private StatusRevisionService|MockObject $revisionService;
	private Person $bob;
	private Person $carol;

	protected function setUp(): void {
		parent::setUp();

		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->cacheActorsRequest = $this->createStub(CacheActorsRequest::class);
		$this->pushService = $this->createMock(PushService::class);

		$this->pollService = $this->createStub(PollService::class);
		$this->streamQueueService = $this->createMock(StreamQueueService::class);
		$this->linkPreviewService = $this->createMock(LinkPreviewService::class);
		$this->forwardService = $this->createMock(ForwardService::class);
		$this->revisionService = $this->createMock(StatusRevisionService::class);
		$this->handler = new NoteInterface(
			$this->streamRequest,
			$this->cacheActorsRequest,
			$this->pollService,
			$this->pushService,
			$this->streamQueueService,
			$this->linkPreviewService,
			$this->forwardService,
			$this->createStub(\OCA\Social\Service\NotificationService::class),
			$this->revisionService
		);

		$this->alice = $this->person(self::LOCAL_URL . '/users/alice', true);
		$this->bob = $this->person(self::REMOTE_URL . '/users/bob');
		$this->carol = $this->person('https://other.example/users/carol');
	}

	/** A note written by bob (remote). */
	private function incomingNote(): Note {
		return $this->note(self::NOTE, $this->bob->getId());
	}

	/** The activity from bob's server wrapping the note. */
	private function wrap(string $type, Note $note, string $origin = self::REMOTE_HOST): ACore {
		return $this->incoming($type, self::NOTE . '/activity', $this->bob->getId(), $note, $origin);
	}

	/** The note as it comes back from storage in local format: with its author attached. */
	private function storedCopy(): Note {
		$post = $this->note(self::NOTE, $this->bob->getId());
		$post->setActor($this->bob);

		return $post;
	}

	private function nothingStored(): void {
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
	}

	/** Nothing is stored under the note's id yet, but its stored copy is available once saved. */
	private function storedAfterSave(): void {
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id, bool $asViewer = false, int $format = ACore::FORMAT_ACTIVITYPUB): Stream {
				if ($id === self::NOTE && $format === ACore::FORMAT_LOCAL) {
					return $this->storedCopy();
				}

				throw new StreamNotFoundException();
			});
	}

	private function knownActors(Person ...$actors): void {
		$this->cacheActorsRequest->method('getFromId')->willReturnCallback(function (string $id) use ($actors): Person {
			foreach ($actors as $actor) {
				if ($actor->getId() === $id) {
					return $actor;
				}
			}

			throw new CacheActorDoesNotExistException();
		});
	}

	// visibility of incoming notes

	public static function visibilityProvider(): array {
		$public = 'https://www.w3.org/ns/activitystreams#Public';
		$followers = self::REMOTE_URL . '/users/bob/followers';

		return [
			'as:Public in to is public' => [[$public], [], 'public'],
			'as:Public in cc is unlisted' => [[$followers], [$public], 'unlisted'],
			'followers collection only is followers' => [[$followers], [], 'followers'],
			'individual recipients only is direct' => [[self::LOCAL_URL . '/users/alice'], [], 'direct'],
		];
	}

	#[DataProvider('visibilityProvider')]
	public function testIncomingNoteVisibilityIsEstimatedFromItsAddressing(array $to, array $cc, string $expected): void {
		$this->nothingStored();
		$this->knownActors($this->bob);
		$note = $this->incomingNote();
		foreach ($to as $recipient) {
			$note->addToArray($recipient);
		}
		$note->setCcArray($cc);

		$this->handler->save($note);

		$this->assertSame($expected, $note->getVisibility());
	}

	public function testAnUnknownAuthorDegradesFollowersOnlyToDirect(): void {
		$this->nothingStored();
		$this->knownActors(); // bob's actor is not cached
		$note = $this->incomingNote();
		$note->addToArray(self::REMOTE_URL . '/users/bob/followers');

		$this->handler->save($note);

		$this->assertSame('direct', $note->getVisibility());
	}

	public function testAnAlreadySetVisibilityIsNotOverridden(): void {
		$this->nothingStored();
		$note = $this->incomingNote();
		$note->setVisibility('unlisted');
		$note->addToArray('https://www.w3.org/ns/activitystreams#Public');

		$this->handler->save($note);

		$this->assertSame('unlisted', $note->getVisibility());
	}

	public function testAConsumedPollVoteIsNeverStored(): void {
		$this->nothingStored();
		$this->pollService->method('handleIncomingVote')->willReturn(true);
		$this->streamRequest->expects($this->never())->method('save');
		$this->pushService->expects($this->never())->method('onNewStream');

		$this->handler->save($this->incomingNote());
	}

	public function testCreateStoresTheNoteTaggedWithItsActivity(): void {
		$this->nothingStored();
		$note = $this->incomingNote();
		$create = $this->wrap(Create::TYPE, $note);

		$this->streamRequest->expects($this->once())->method('save')->with($this->identicalTo($note));

		$this->handler->activity($create, $note);

		$this->assertSame($create->getId(), $note->getActivityId());
	}

	public function testCreateOfAStoredNoteIsPushedToConnectedClients(): void {
		$this->nothingStored();
		$note = $this->incomingNote();

		$this->pushService->expects($this->once())->method('onNewStream')->with(self::NOTE);

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);
	}

	public function testCreateOfAnAlreadyKnownNoteIsNotStoredTwice(): void {
		$note = $this->incomingNote();
		$this->streamRequest->method('getStreamById')->with(self::NOTE)->willReturn($this->storedCopy());

		$this->streamRequest->expects($this->never())->method('save');
		$this->pushService->expects($this->never())->method('onNewStream');

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);
	}

	public function testCreateOfANewNoteIsOfferedForForwarding(): void {
		$this->nothingStored();
		$note = $this->incomingNote();
		$create = $this->wrap(Create::TYPE, $note);

		// the service decides whether it qualifies; the handler only offers it
		$this->forwardService->expects($this->once())
			->method('forwardReply')
			->with($this->identicalTo($create), $this->identicalTo($note));

		$this->handler->activity($create, $note);
	}

	public function testANoteWeAlreadyHadIsNotForwardedAgain(): void {
		$note = $this->incomingNote();
		$this->streamRequest->method('getStreamById')->with(self::NOTE)->willReturn($this->storedCopy());

		// a sender retrying must not fan the same reply out to everyone twice
		$this->forwardService->expects($this->never())->method('forwardReply');

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);
	}

	/**
	 * The note is attributed to the actor of the Create, so an actor on a third
	 * server is what makes the note's authorship foreign — whatever the note's own
	 * `attributedTo` claimed.
	 */
	public function testCreateByAnActorFromAnotherServerIsRefused(): void {
		$note = $this->incomingNote(); // claims bob, on the note's own server
		$create = $this->incoming(
			Create::TYPE, self::NOTE . '/activity', $this->carol->getId(), $note, self::REMOTE_HOST
		);

		$this->streamRequest->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($create, $note);
	}

	public function testSaveRefusesANoteWhoseAuthorIsOnAnotherServer(): void {
		// save() is the invariant every fetch-and-store path relies on (a cached
		// announced object, a synced outbox) where there is no request origin to
		// compare against: the note's id host and its attributedTo host must match.
		$note = $this->note(self::NOTE, $this->carol->getId());

		$this->streamRequest->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);

		$this->handler->save($note);
	}

	public function testCreateNotComingFromTheNotesServerIsRefused(): void {
		$note = $this->incomingNote();

		$this->streamRequest->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($this->wrap(Create::TYPE, $note, 'evil.example'), $note);
	}

	public function testCreateKeepsTheHashtagsAndMentionsOfTheNote(): void {
		// nobody mentioned is known locally, so mentions fall back to the tag data
		$this->personInterface->method('getItemById')->willThrowException(new ItemNotFoundException());
		$this->storedAfterSave();
		$this->knownActors();

		$create = $this->ap->getItemFromData([
			'type' => 'Create',
			'id' => self::NOTE . '/activity',
			'actor' => $this->bob->getId(),
			'object' => [
				'type' => 'Note',
				'id' => self::NOTE,
				'attributedTo' => $this->bob->getId(),
				'content' => '<p>Hello #Nextcloud and @alice</p>',
				'tag' => [
					['type' => 'Hashtag', 'href' => self::REMOTE_URL . '/tags/nextcloud', 'name' => '#Nextcloud'],
					['type' => 'Mention', 'href' => $this->alice->getId(), 'name' => '@alice@local.example'],
				],
			],
		]);
		$create->setOrigin(self::REMOTE_HOST, SignatureService::ORIGIN_HEADER, time());
		$note = $create->getObject();

		$saved = null;
		$this->capture($this->streamRequest, 'save', $saved);

		$this->handler->activity($create, $note);

		$this->assertInstanceOf(Note::class, $saved);
		$this->assertSame(['Nextcloud'], $saved->getHashtags());
		$mentions = $saved->getDetailsAll()['mentions'];
		$this->assertCount(1, $mentions);
		$this->assertSame($this->alice->getId(), $mentions[0]['url']);
		$this->assertSame('alice@local.example', $mentions[0]['acct']);
	}

	public function testReplyRecountsTheRepliesOfItsParent(): void {
		$this->nothingStored();
		$note = $this->incomingNote();
		$note->setInReplyTo(self::PARENT);

		// the arithmetic — what the parent's own instance reported plus what is
		// stored here — belongs to the recount, which a local delete uses too
		$this->streamRequest->expects($this->once())->method('recountReplies')->with(self::PARENT);

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);
	}

	public function testReplyToAnUnknownParentIsStillStored(): void {
		$this->nothingStored();
		$note = $this->incomingNote();
		$note->setInReplyTo(self::PARENT);

		$this->streamRequest->expects($this->once())->method('save')->with($this->identicalTo($note));

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);
	}

	/**
	 * Nothing used to fetch the parent of a reply this instance never received: the
	 * reply sat in the timeline "in reply to nothing". The parent is queued for
	 * caching the way an Announce queues its object, so the inbox request does not
	 * wait on a stranger's server.
	 */
	public function testReplyToAnUnknownParentQueuesTheParentForFetching(): void {
		$this->nothingStored();
		$note = $this->incomingNote();
		$note->setInReplyTo(self::PARENT);

		$this->streamRequest->expects($this->once())->method('save')
			->with($this->callback(static fn (Note $saved): bool => $saved->hasCache() && $saved->getCache()->hasItem(self::PARENT)));
		$this->streamQueueService->expects($this->once())->method('generateStreamQueue')
			->with($note->getRequestToken(), StreamQueue::TYPE_CACHE, self::NOTE);

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);
	}

	public function testReplyToAKnownParentDoesNotQueueAFetch(): void {
		$parent = $this->note(self::PARENT, $this->alice->getId(), true);
		$this->streamRequest->method('getStreamById')->willReturnCallback(function (string $id) use ($parent): Stream {
			if ($id === self::PARENT) {
				return $parent;
			}

			throw new StreamNotFoundException();
		});
		$note = $this->incomingNote();
		$note->setInReplyTo(self::PARENT);

		$this->streamQueueService->expects($this->never())->method('generateStreamQueue');

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);

		$this->assertFalse($note->hasCache());
	}

	/** A fetched ancestor carries how deep the climb already is; at the cap it stops. */
	public function testTheAncestorClimbStopsAtTheDepthCap(): void {
		$this->nothingStored();
		$note = $this->incomingNote();
		$note->setInReplyTo(self::PARENT);
		$note->setDetailInt(Details::ANCESTOR_DEPTH, StreamQueueService::MAX_ANCESTOR_DEPTH);

		$this->streamRequest->expects($this->once())->method('save');
		$this->streamQueueService->expects($this->never())->method('generateStreamQueue');

		$this->handler->save($note);

		$this->assertFalse($note->hasCache());
	}

	/** `"to": "<Public>"` as a bare string is a public post, not a direct message. */
	public function testANoteAddressedToPublicByABareStringIsPublic(): void {
		$this->nothingStored();
		/** @var Note $note */
		$note = $this->ap->getItemFromData([
			'type' => 'Note',
			'id' => self::NOTE,
			'attributedTo' => $this->bob->getId(),
			'to' => ACore::CONTEXT_PUBLIC,
			'content' => '<p>hello</p>',
		]);

		$this->handler->save($note);

		$this->assertSame(Stream::TYPE_PUBLIC, $note->getVisibility());
	}

	public function testMentioningALocalActorNotifiesThem(): void {
		$this->storedAfterSave();
		$this->knownActors($this->alice);
		$note = $this->incomingNote();
		$note->addTag(['type' => 'Mention', 'href' => $this->alice->getId(), 'name' => '@alice']);

		$notification = null;
		$this->capture($this->notificationInterface, 'save', $notification);

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);

		$this->assertInstanceOf(SocialAppNotification::class, $notification);
		$this->assertSame(Mention::TYPE, $notification->getSubType());
		$this->assertSame($this->alice->getId(), $notification->getTo());
		$this->assertSame(self::NOTE, $notification->getObjectId());
		$this->assertSame(
			self::NOTE . '/notification+mention/' . md5($this->alice->getId()),
			$notification->getId()
		);
		$this->assertSame(['bob@remote.example'], $notification->getDetails('account'));
		// the author, not the reader: this is what the client shows as the
		// acting account, and what the block and mute filter compares against
		$this->assertSame($this->bob->getId(), $notification->getAttributedTo());
		$this->assertTrue($notification->isLocal());
	}

	/**
	 * One row per recipient. A post mentioning three people wrote the same id
	 * three times, and only the first of them survived — so all but one of the
	 * people named in a post were never told.
	 */
	public function testEveryoneMentionedInAPostIsNotified(): void {
		$this->storedAfterSave();
		$this->knownActors($this->alice, $this->dave ?? $this->alice);
		$note = $this->incomingNote();
		$note->addTag(['type' => 'Mention', 'href' => $this->alice->getId(), 'name' => '@alice']);

		$saved = [];
		$this->notificationInterface->method('save')
			->willReturnCallback(static function ($item) use (&$saved): void {
				$saved[] = $item->getId();
			});

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);

		$this->assertSame(count($saved), count(array_unique($saved)), 'two recipients shared one id');
	}

	public function testMentioningARemoteActorDoesNotNotifyAnyone(): void {
		$this->storedAfterSave();
		$this->knownActors($this->carol);
		$note = $this->incomingNote();
		$note->addTag(['type' => 'Mention', 'href' => $this->carol->getId(), 'name' => '@carol']);

		$this->notificationInterface->expects($this->never())->method('save');

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);
	}

	public function testMentioningAnUnknownActorDoesNotNotifyAnyone(): void {
		$this->storedAfterSave();
		$this->knownActors();
		$note = $this->incomingNote();
		$note->addTag(['type' => 'Mention', 'href' => self::LOCAL_URL . '/users/nobody', 'name' => '@nobody']);

		$this->notificationInterface->expects($this->never())->method('save');
		$this->streamRequest->expects($this->once())->method('save');

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);
	}

	public function testDeleteRemovesTheNoteAndItsLinkPreview(): void {
		$note = $this->incomingNote();
		$this->streamRequest->method('getStreamById')->with(self::NOTE)->willReturn($this->storedCopy());

		$this->streamRequest->expects($this->once())->method('deleteById')->with(self::NOTE, Note::TYPE);
		$this->linkPreviewService->expects($this->once())->method('deleteCard')->with(self::NOTE);

		$this->handler->activity($this->wrap(Delete::TYPE, $note), $note);
	}

	public function testDeleteRemovesAPollAsAQuestion(): void {
		$poll = new Question();
		$poll->setId(self::NOTE);

		$this->streamRequest->expects($this->once())->method('deleteById')->with(self::NOTE, Question::TYPE);

		$this->handler->delete($poll);
	}

	public function testDeletingAReplyRecountsItsParent(): void {
		$note = $this->incomingNote();
		$note->setInReplyTo(self::PARENT);
		$this->streamRequest->method('getStreamById')->willReturnCallback(function () use ($note): Stream {
			$stored = clone $note;
			$stored->setActor($this->bob);

			return $stored;
		});

		// a delete used to remove the reply and leave the count alone, so the
		// post went on claiming a reply nothing on any page could show. The
		// recount runs after the row has gone, which is what makes it right.
		$this->streamRequest->expects($this->once())->method('deleteById')->with(self::NOTE, Note::TYPE);
		$this->streamRequest->expects($this->once())->method('recountReplies')->with(self::PARENT);

		$this->handler->activity($this->wrap(Delete::TYPE, $note), $note);
	}

	public function testDeletingAPostThatAnsweredNothingRecountsNothing(): void {
		$note = $this->incomingNote();
		$this->streamRequest->method('getStreamById')->with(self::NOTE)->willReturn($this->storedCopy());

		// an empty parent id is what "this answered nothing" looks like, and
		// the recount is the one place that decides what to do with it
		$this->streamRequest->expects($this->once())->method('recountReplies')->with('');

		$this->handler->activity($this->wrap(Delete::TYPE, $note), $note);
	}

	public function testAStoredNoteThatLinksSomewhereIsHandedToTheQueue(): void {
		$note = $this->incomingNote();
		$note->setContent('<p>see <a href="https://example.org/a">this</a></p>');
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->linkPreviewService->method('extractUrl')->willReturn('https://example.org/a');

		// reading the page here would hold up the inbox
		$this->linkPreviewService->expects($this->never())->method('generate');
		$this->streamQueueService->expects($this->once())->method('generateStreamQueue')
			->with($note->getRequestToken(), StreamQueue::TYPE_LINK_PREVIEW, self::NOTE);

		$this->handler->save($note);
	}

	public function testANoteWithoutALinkIsNotQueued(): void {
		$note = $this->incomingNote();
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->linkPreviewService->method('extractUrl')->willReturn('');

		$this->streamQueueService->expects($this->never())->method('generateStreamQueue');

		$this->handler->save($note);
	}

	public function testDeleteNotComingFromTheNotesServerIsRefused(): void {
		$note = $this->incomingNote();

		$this->streamRequest->expects($this->never())->method('deleteById');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($this->wrap(Delete::TYPE, $note, 'evil.example'), $note);
	}

	public function testUpdateRewritesTheStoredNote(): void {
		$note = $this->incomingNote();
		$note->setContent('<p>edited</p>');
		$update = $this->wrap(Update::TYPE, $note);
		$this->streamRequest->method('getStreamById')->with(self::NOTE)->willReturn($this->storedCopy());

		$this->streamRequest->expects($this->once())->method('update')->with($this->identicalTo($note));
		$this->streamRequest->expects($this->never())->method('save');

		$this->handler->activity($update, $note);

		$this->assertSame($update->getId(), $note->getActivityId());
	}

	/**
	 * An edit from another server is an edit: without the revision,
	 * `GET /statuses/{nid}/history` answered a post edited three times with a
	 * single synthetic version, and the "edited" dialog had nothing to compare.
	 */
	public function testUpdateRecordsTheVersionItReplaces(): void {
		$note = $this->incomingNote();
		$note->setContent('<p>edited</p>');
		$stored = $this->storedCopy();
		$this->streamRequest->method('getStreamById')->with(self::NOTE)->willReturn($stored);

		$this->revisionService->expects($this->once())
			->method('recordEdit')
			->with($this->identicalTo($stored), $this->identicalTo($note));

		$this->handler->activity($this->wrap(Update::TYPE, $note), $note);
	}

	public function testAnUpdateOfANoteNeverReceivedRecordsNoRevision(): void {
		$note = $this->incomingNote();
		$this->nothingStored();

		$this->revisionService->expects($this->never())->method('recordEdit');

		$this->handler->activity($this->wrap(Update::TYPE, $note), $note);
	}

	public function testUpdateOfANoteClaimingAnAuthorFromAnotherServerIsRefused(): void {
		$note = $this->note(self::NOTE, $this->carol->getId());

		$this->streamRequest->expects($this->never())->method('update');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($this->wrap(Update::TYPE, $note), $note);
	}

	/**
	 * Both checks used to stop at the host: any account on the same server as the
	 * author could edit or remove the author's post here. Mastodon looks the status
	 * up by uri *and* account, so a peer's Update or Delete finds nothing.
	 */
	public function testUpdateByAnotherUserOfTheSameServerIsRefused(): void {
		$note = $this->incomingNote();
		$note->setContent('<p>defaced</p>');
		$this->streamRequest->method('getStreamById')->with(self::NOTE)->willReturn($this->storedCopy());
		$mallory = self::REMOTE_URL . '/users/mallory';

		$this->streamRequest->expects($this->never())->method('update');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity(
			$this->incoming(Update::TYPE, $mallory . '#updates/1', $mallory, $note),
			$note
		);
	}

	public function testDeleteByAnotherUserOfTheSameServerIsRefused(): void {
		$note = $this->incomingNote();
		$this->streamRequest->method('getStreamById')->with(self::NOTE)->willReturn($this->storedCopy());
		$mallory = self::REMOTE_URL . '/users/mallory';

		$this->streamRequest->expects($this->never())->method('deleteById');
		$this->linkPreviewService->expects($this->never())->method('deleteCard');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity(
			$this->incoming(Delete::TYPE, $mallory . '#delete/1', $mallory, $note),
			$note
		);
	}

	/**
	 * PeerTube files a video under its channel and sends the video's Update
	 * and Delete as the account that owns the channel; the channel's own
	 * document names that account.
	 */
	public function testUpdateByTheAccountBehindTheChannelRewritesTheVideo(): void {
		[$channel, $owner, $video] = $this->channelVideo(self::REMOTE_URL . '/accounts/owner');
		$this->knownActors($channel);

		$this->streamRequest->expects($this->once())->method('update')->with($this->identicalTo($video));

		$this->handler->activity($this->incoming(Update::TYPE, $owner . '#updates/1', $owner, $video), $video);
	}

	public function testDeleteByTheAccountBehindTheChannelRemovesTheVideo(): void {
		[$channel, $owner, $video] = $this->channelVideo(self::REMOTE_URL . '/accounts/owner');
		$this->knownActors($channel);

		$this->streamRequest->expects($this->once())->method('deleteById')->with(self::NOTE);

		$this->handler->activity($this->incoming(Delete::TYPE, $owner . '#delete/1', $owner, $video), $video);
	}

	public function testUpdateByAnAccountTheChannelDoesNotNameIsRefused(): void {
		[$channel, , $video] = $this->channelVideo(self::REMOTE_URL . '/accounts/owner');
		$this->knownActors($channel);
		$mallory = self::REMOTE_URL . '/accounts/mallory';

		$this->streamRequest->expects($this->never())->method('update');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($this->incoming(Update::TYPE, $mallory . '#updates/1', $mallory, $video), $video);
	}

	public function testAChannelOnAnotherServerCannotBeOwnedFromThisOne(): void {
		$owner = 'https://other.example/accounts/owner';
		[$channel, , $video] = $this->channelVideo($owner);
		$this->knownActors($channel);

		$this->streamRequest->expects($this->never())->method('update');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($this->incoming(Update::TYPE, $owner . '#updates/1', $owner, $video), $video);
	}

	/**
	 * A video stored under a remote channel whose document names `$owner`.
	 *
	 * @return array{0: Person, 1: string, 2: Note}
	 */
	private function channelVideo(string $owner): array {
		$channel = $this->person(self::REMOTE_URL . '/video-channels/films');
		$channel->setType('Group');
		$channel->setAttributedToActors([['type' => Person::TYPE, 'id' => $owner]]);

		$stored = $this->note(self::NOTE, $channel->getId());
		$this->streamRequest->method('getStreamById')->with(self::NOTE)->willReturn($stored);

		return [$channel, $owner, $this->note(self::NOTE, $channel->getId())];
	}

	public function testUpdateOfANoteNeverReceivedRewritesNothing(): void {
		$this->nothingStored();
		$note = $this->incomingNote();

		$this->streamRequest->expects($this->never())->method('update');

		$this->handler->activity($this->wrap(Update::TYPE, $note), $note);
	}

	public function testDeleteOfANoteNeverReceivedDeletesNothing(): void {
		$this->nothingStored();
		$note = $this->incomingNote();

		$this->streamRequest->expects($this->never())->method('deleteById');

		$this->handler->activity($this->wrap(Delete::TYPE, $note), $note);
	}

	/**
	 * Mastodon attributes an incoming Create to the actor that performs it,
	 * whatever `attributedTo` says. Same here — so one user of a shared server
	 * cannot publish under a neighbour's name, and a Create that names its own
	 * actor (the normal case) is stored unchanged.
	 */
	public function testCreateAttributesTheNoteToTheActorOfTheActivity(): void {
		$this->nothingStored();
		$mallory = self::REMOTE_URL . '/users/mallory';
		$note = $this->incomingNote(); // claims bob
		$create = $this->incoming(Create::TYPE, $mallory . '#creates/1', $mallory, $note);

		$saved = null;
		$this->capture($this->streamRequest, 'save', $saved);

		$this->handler->activity($create, $note);

		$this->assertSame($mallory, $saved->getAttributedTo());
	}

	public function testCreateWithoutAnActorIsRefused(): void {
		$this->nothingStored();
		$note = $this->incomingNote();
		$create = $this->incoming(Create::TYPE, self::NOTE . '/activity', '', $note);

		$this->streamRequest->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($create, $note);
	}

	public function testOtherActivitiesLeaveTheNoteAlone(): void {
		$note = $this->incomingNote();

		$this->streamRequest->expects($this->never())->method('save');
		$this->streamRequest->expects($this->never())->method('update');
		$this->streamRequest->expects($this->never())->method('deleteById');

		$this->handler->activity($this->wrap(Accept::TYPE, $note), $note);
	}

	public function testGetItemByIdReturnsTheStoredNote(): void {
		$stored = $this->storedCopy();
		$this->streamRequest->method('getStreamById')->with(self::NOTE)->willReturn($stored);

		$this->assertSame($stored, $this->handler->getItemById(self::NOTE));
	}

	public function testGetItemByIdThrowsWhenTheNoteIsUnknown(): void {
		$this->nothingStored();

		$this->expectException(ItemNotFoundException::class);

		$this->handler->getItemById(self::NOTE);
	}
}
