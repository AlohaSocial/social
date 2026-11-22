<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\TimelineRevisionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * How a post is addressed when it is stored.
 *
 * `generateStreamDest()` runs inside the transaction that stores the post.
 * Deciding whether the post is a direct message needs the author's followers
 * collection, and that used to go through `CacheActorService::getFromId()`,
 * which fetches an author it does not have over HTTP — a request to another
 * server while the row locks are held.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamDestRequestTest extends TestCase {
	private const ALICE = 'https://cloud.example/@alice';
	private const BOB = 'https://other.example/@bob';

	private CacheActorsRequest|MockObject $cacheActorsRequest;
	/** @var list<array{string, string}> follow_id_prim, object_id_prim of every follow stored here */
	private array $follows = [];
	/** @var list<list<string>> the prims of every follow lookup */
	private array $asked = [];
	/** @var list<array{string, string, string}> actor, type, subtype of every dest row written */
	private array $rows = [];
	/** @var list<array{string, string}> post, parent of every thread root stored */
	private array $roots = [];
	/** @var list<array{string, string}> actor, key of every badge number moved */
	private array $bumped = [];

	private function request(): StreamDestRequest {
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);

		$request = $this->getMockBuilder(StreamDestRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['createRecipients', 'followsOf'])
			->getMock();
		$request->method('followsOf')->willReturnCallback(function (array $prims): array {
			$this->asked[] = $prims;

			return array_values(array_filter(
				$this->follows, static fn (array $follow): bool => in_array($follow[0], $prims, true)
			));
		});
		$request->method('createRecipients')->willReturnCallback(
			function (string $streamId, array $recipients, int|string $nid = 0): void {
				foreach ($recipients as $actorId => [$type, $subType]) {
					$this->rows[] = [(string)$actorId, $type, $subType];
				}
			}
		);
		(new ReflectionProperty(StreamDestRequest::class, 'cacheActorsRequest'))->setValue($request, $this->cacheActorsRequest);
		(new ReflectionProperty(StreamDestRequest::class, 'logger'))->setValue($request, new NullLogger());

		$conversations = $this->createStub(ConversationsRequest::class);
		$conversations->method('storeRoot')->willReturnCallback(function (string $id, string $inReplyTo): void {
			$this->roots[] = [$id, $inReplyTo];
		});
		(new ReflectionProperty(StreamDestRequest::class, 'conversationsRequest'))->setValue($request, $conversations);
		$revisions = $this->createStub(TimelineRevisionService::class);
		$revisions->method('bumpForActor')->willReturnCallback(function (string $actorId, string $key): void {
			$this->bumped[] = [$actorId, $key];
		});
		(new ReflectionProperty(StreamDestRequest::class, 'timelineRevisionService'))->setValue($request, $revisions);

		return $request;
	}

	private function alice(): Person {
		$alice = new Person();
		$alice->setId(self::ALICE);
		$alice->setFollowers(self::ALICE . '/followers');

		return $alice;
	}

	private function note(array $to): Stream {
		$note = new Note();
		$note->setId(self::ALICE . '/1');
		$note->setAttributedTo(self::ALICE);
		$note->setTo($to[0]);
		$note->setToArray($to);

		return $note;
	}

	public function testTheAuthorIsReadFromTheCacheAndNeverFetched(): void {
		$request = $this->request();
		$this->cacheActorsRequest->expects($this->once())->method('getFromId')
			->with(self::ALICE)->willReturn($this->alice());

		$request->generateStreamDest($this->note([self::BOB]));

		$this->assertSame([[self::BOB, 'dm', ''], [self::ALICE, 'dm', '']], $this->rows);
	}

	/**
	 * A direct message stores its thread's root, and the badge of everybody
	 * it reaches moves — the author's other devices included.
	 */
	public function testADirectMessageStoresItsRootAndMovesTheConversationsBadge(): void {
		$request = $this->request();
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->alice());
		$note = $this->note([self::BOB]);
		$note->setInReplyTo(self::BOB . '/1');

		$request->generateStreamDest($note);

		$this->assertSame([[self::ALICE . '/1', self::BOB . '/1']], $this->roots);
		$this->assertSame([
			[self::BOB, TimelineRevisionService::CONVERSATIONS],
			[self::ALICE, TimelineRevisionService::CONVERSATIONS],
		], $this->bumped);
	}

	public function testANotificationMovesTheNotificationsBadge(): void {
		$request = $this->request();
		$notification = new SocialAppNotification();
		$notification->setId(self::ALICE . '/notification/1');
		$notification->setTo(self::ALICE);

		$request->generateStreamDest($notification);

		$this->assertSame([[self::ALICE, 'notif', '']], $this->rows);
		$this->assertSame([[self::ALICE, TimelineRevisionService::NOTIFICATIONS]], $this->bumped);
		$this->assertSame([], $this->roots);
	}

	public function testAPostForTheHomeTimelineStoresNoRootAndMovesNoBadge(): void {
		$request = $this->request();
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->alice());

		$request->generateStreamDest($this->note([Stream::CONTEXT_PUBLIC]));

		$this->assertSame([], $this->roots);
		$this->assertSame([], $this->bumped);
	}

	public function testAPostToTheFollowersIsNotDirect(): void {
		$request = $this->request();
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->alice());

		$request->generateStreamDest($this->note([self::ALICE . '/followers']));

		$this->assertSame([
			[self::ALICE . '/followers', 'recipient', 'to'],
			[self::ALICE, 'recipient', 'to'],
		], $this->rows);
	}

	/**
	 * Without the author's followers collection the post cannot be told apart
	 * from a followers-only one, and the home-timeline rows are the answer
	 * that loses nothing: a direct message addressed that way is still only
	 * readable by whoever it names.
	 */
	public function testAnUncachedAuthorLeavesThePostAddressedForTheHomeTimeline(): void {
		$request = $this->request();
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());

		$request->generateStreamDest($this->note([self::BOB]));

		$this->assertSame([
			[self::BOB, 'recipient', 'to'],
			[self::ALICE, 'recipient', 'to'],
		], $this->rows);
	}

	/**
	 * PeerTube addresses a video to the followers of the account behind its
	 * channel and files it under the channel: a follower of the channel was
	 * no recipient, and the video never reached their home timeline.
	 */
	public function testAPublicPostReachesItsAuthorsFollowersWhateverItNames(): void {
		$request = $this->request();
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->alice());

		$request->generateStreamDest($this->note([Stream::CONTEXT_PUBLIC]));

		$this->assertSame([
			[Stream::CONTEXT_PUBLIC, 'recipient', 'to'],
			[self::ALICE, 'recipient', 'to'],
			[self::ALICE . '/followers', 'recipient', 'cc'],
		], $this->rows);
	}

	/** A follow of `$followed`'s followers collection, as `social_follow` holds it. */
	private function followed(string $followed, string $collection = ''): void {
		$this->follows[] = [md5($collection === '' ? $followed . '/followers' : $collection), md5($followed)];
	}

	/** A local account's Loopback row, which names the account itself. */
	private function local(string $account): void {
		$this->follows[] = [md5($account), md5($account)];
	}

	/**
	 * A home timeline reads every post naming a collection its account
	 * follows: a stranger's post naming somebody else's followers would
	 * reach all of them, and one naming a local account's followers would
	 * reach that account and its followers.
	 */
	public function testAnotherAccountsFollowedCollectionIsNotARecipient(): void {
		$request = $this->request();
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->alice());
		$carol = 'https://social.example/@carol';
		$this->followed(self::BOB);
		$this->local($carol);

		$request->generateStreamDest($this->note([Stream::CONTEXT_PUBLIC, self::BOB . '/followers', $carol . '/followers']));

		$this->assertSame([
			[Stream::CONTEXT_PUBLIC, 'recipient', 'to'],
			[self::ALICE, 'recipient', 'to'],
			[self::ALICE . '/followers', 'recipient', 'cc'],
		], $this->rows);
		$this->assertCount(1, $this->asked, 'one lookup for the post');
	}

	/**
	 * Accounts by id stay, cached or not, local or not: a mention reaches the
	 * home timeline of a local account, and a URL no follow here targets
	 * reaches nobody's.
	 */
	public function testAccountsAndCollectionsNobodyFollowsStayRecipients(): void {
		$request = $this->request();
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->alice());
		$carol = 'https://social.example/@carol';
		$this->local($carol);

		$request->generateStreamDest($this->note([Stream::CONTEXT_PUBLIC, self::BOB, $carol, self::BOB . '/followers']));

		$this->assertSame([
			[Stream::CONTEXT_PUBLIC, 'recipient', 'to'],
			[self::BOB, 'recipient', 'to'],
			[$carol, 'recipient', 'to'],
			[self::BOB . '/followers', 'recipient', 'to'],
			[self::ALICE, 'recipient', 'to'],
			[self::ALICE . '/followers', 'recipient', 'cc'],
		], $this->rows);
	}

	public function testAPostNamingOnlyItsAuthorsOwnCollectionsAsksNoFollows(): void {
		$request = $this->request();
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->alice());

		$request->generateStreamDest($this->note([Stream::CONTEXT_PUBLIC, self::ALICE . '/followers']));

		$this->assertSame([], $this->asked);
	}

	/** An actor document can name any URL as its followers collection. */
	public function testAnAuthorsFollowersCollectionOnAnotherServerIsNotItsOwn(): void {
		$request = $this->request();
		$alice = $this->alice();
		$alice->setFollowers(self::BOB . '/followers');
		$this->cacheActorsRequest->method('getFromId')->willReturn($alice);
		$this->followed(self::BOB);

		$request->generateStreamDest($this->note([Stream::CONTEXT_PUBLIC, self::BOB . '/followers']));

		$this->assertSame([
			[Stream::CONTEXT_PUBLIC, 'recipient', 'to'],
			[self::ALICE, 'recipient', 'to'],
		], $this->rows);
	}

	/** A boost is addressed for its booster's followers, and for nobody else's. */
	public function testABoostKeepsOnlyItsBoostersFollowers(): void {
		$request = $this->request();
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->alice());
		$this->followed(self::BOB);
		$boost = new Announce();
		$boost->setId(self::ALICE . '/1/activity');
		$boost->setAttributedTo(self::ALICE);
		$boost->setTo(Stream::CONTEXT_PUBLIC);
		$boost->setCcArray([self::ALICE . '/followers', self::BOB . '/followers']);

		$request->generateStreamDest($boost);

		$this->assertSame([
			[Stream::CONTEXT_PUBLIC, 'recipient', 'to'],
			[self::ALICE, 'recipient', 'to'],
			[self::ALICE . '/followers', 'recipient', 'cc'],
		], $this->rows);
	}

	/**
	 * PeerTube files a video under its channel and addresses it to the
	 * followers of the account behind the channel, which the channel's own
	 * document names.
	 */
	public function testAChannelsVideoReachesTheFollowersOfTheAccountBehindIt(): void {
		$request = $this->request();
		$channel = new Person();
		$channel->setId('https://tube.example/video-channels/films');
		$channel->setFollowers('https://tube.example/video-channels/films/followers');
		$channel->setAttributedToActors([['type' => 'Person', 'id' => 'https://tube.example/accounts/dan']]);
		$this->cacheActorsRequest->method('getFromId')->willReturn($channel);
		$this->followed('https://tube.example/accounts/dan');
		$this->followed(self::BOB);

		$video = new Note();
		$video->setId('https://tube.example/videos/watch/1');
		$video->setAttributedTo($channel->getId());
		$video->setTo(Stream::CONTEXT_PUBLIC);
		$video->setCcArray(['https://tube.example/accounts/dan/followers', self::BOB . '/followers']);

		$request->generateStreamDest($video);

		$this->assertSame([
			[Stream::CONTEXT_PUBLIC, 'recipient', 'to'],
			[$channel->getId(), 'recipient', 'to'],
			['https://tube.example/accounts/dan/followers', 'recipient', 'cc'],
			[$channel->getFollowers(), 'recipient', 'cc'],
		], $this->rows);
	}

	/** Without the author cached, the follows say which collections are its own. */
	public function testAnUncachedAuthorKeepsTheCollectionsFollowedAsItsOwn(): void {
		$request = $this->request();
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->followed(self::ALICE);
		$this->followed(self::BOB);

		$request->generateStreamDest($this->note([Stream::CONTEXT_PUBLIC, self::ALICE . '/followers', self::BOB . '/followers']));

		$this->assertSame([
			[Stream::CONTEXT_PUBLIC, 'recipient', 'to'],
			[self::ALICE . '/followers', 'recipient', 'to'],
			[self::ALICE, 'recipient', 'to'],
		], $this->rows);
	}

	public function testAPostThatNamesTheFollowersAlreadyNamesThemOnce(): void {
		$request = $this->request();
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->alice());

		$request->generateStreamDest($this->note([Stream::CONTEXT_PUBLIC, self::ALICE . '/followers']));

		$this->assertSame([
			[Stream::CONTEXT_PUBLIC, 'recipient', 'to'],
			[self::ALICE . '/followers', 'recipient', 'to'],
			[self::ALICE, 'recipient', 'to'],
		], $this->rows);
	}

	public function testAFragmentOnTheAuthorIdIsNotPartOfTheLookup(): void {
		$request = $this->request();
		$this->cacheActorsRequest->expects($this->once())->method('getFromId')
			->with(self::ALICE)->willReturn($this->alice());

		$note = $this->note([self::BOB]);
		$note->setAttributedTo(self::ALICE . '#main-key');

		$request->generateStreamDest($note);
	}
}
