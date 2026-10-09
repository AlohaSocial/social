<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\AP;
use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Model\Watch;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Reader\NotificationPoller;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class NotificationPollerTest extends TestCase {
	private const LOCAL = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const BOB = 'did:plc:z72i7hdynmk6r22z27h6tvur';
	private const NOW = 1760000000;
	private const TABLE = CoreRequestBuilder::TABLE_ATPROTO_NOTIFY_CURSOR;

	/** @var AppViewClient&MockObject */
	private AppViewClient $appView;
	/** @var AtprotoWatchRequest&MockObject */
	private AtprotoWatchRequest $cursors;
	/** @var PostStore&MockObject */
	private PostStore $store;
	/** @var BlueskyActorService&MockObject */
	private BlueskyActorService $actors;
	/** @var IdentityService&MockObject */
	private IdentityService $identities;
	private NotificationPoller $poller;
	/** @var NotificationService&MockObject */
	private NotificationService $notifications;
	private Identity $alice;
	/** @var ACore[] */
	private array $imported = [];

	protected function setUp(): void {
		$ap = $this->createMock(AP::class);
		$ap->method('getItemFromData')->willReturnCallback(static function (array $data): ACore {
			$item = match ($data['type']) {
				'Follow' => new Follow(),
				'Like' => new Like(),
				'Announce' => new Announce(),
				'Person' => new Person(),
			};
			$item->setUrlCloud('https://social.test');
			$item->import($data);

			return $item;
		});
		AP::set($ap);
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('syncCeiling')->willReturn(200);
		$this->alice = new Identity(1, 'https://social.test/@alice', self::LOCAL, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0);
		$this->identities = $this->createMock(IdentityService::class);
		$this->identities->method('getByDid')->willReturn($this->alice);
		$this->identities->method('getAll')->willReturn([$this->alice]);
		$this->identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$this->cursors = $this->createMock(AtprotoWatchRequest::class);
		$this->appView = $this->createMock(AppViewClient::class);
		$this->store = $this->createMock(PostStore::class);
		$this->actors = $this->createMock(BlueskyActorService::class);
		$this->actors->method('cached')->willReturnCallback(static function (string $did): Person {
			$p = new Person();
			$p->setId('https://bsky.app/profile/' . $did);

			return $p;
		});
		$identityRequest = $this->createMock(AtprotoIdentityRequest::class);
		$identityRequest->method('getByDid')->willReturnCallback(fn (string $did): Identity => $did === self::LOCAL ? $this->alice : throw new AtprotoIdentityNotFoundException());
		$records = $this->createMock(AtprotoRepoRequest::class);
		$records->method('getRecord')->willReturnCallback(static fn (string $did, string $c, string $rkey): ?StoredRecord => $rkey === '3kmine' ? new StoredRecord($did, $c, $rkey, Cid::forRaw('r'), '', 'https://social.test/@alice/1', 0) : null);
		$import = $this->createMock(ImportService::class);
		$import->method('parseIncomingRequest')->willReturnCallback(function (ACore $a): void {
			$this->imported[] = $a;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->poller = new NotificationPoller($config, $this->identities, $this->cursors, $this->appView, $this->store, new LocalRecordResolver($identityRequest, $records), new ActorMapper(), $this->actors, $this->createMock(Blocklist::class), $import, $this->notifications, $time, new NullLogger());
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	public function testFollowsLikesAndRepostsBecomeTheActivitiesFromBskyApp(): void {
		$this->assertTrue($this->poller->handle($this->alice, $this->notification('follow', 'app.bsky.graph.follow', '3kfollow')));
		$this->assertTrue($this->poller->handle($this->alice, $this->notification('like', 'app.bsky.feed.like', '3klike', 'at://' . self::LOCAL . '/' . RecordMapper::POST . '/3kmine')));
		$this->assertTrue($this->poller->handle($this->alice, $this->notification('repost', 'app.bsky.feed.repost', '3krepost', 'at://' . self::LOCAL . '/' . RecordMapper::POST . '/3kmine')));

		[$follow, $like, $announce] = $this->imported;
		$this->assertInstanceOf(Follow::class, $follow);
		$this->assertSame('https://bsky.app/profile/' . self::BOB, $follow->getActorId());
		$this->assertSame('https://social.test/@alice', $follow->getObjectId());
		$this->assertSame('bsky.app', $follow->getOrigin());
		$this->assertInstanceOf(Like::class, $like);
		$this->assertSame('https://social.test/@alice/1', $like->getObjectId(), 'the like is of the local post the record stands for');
		$this->assertSame('https://bsky.app/profile/' . self::BOB . '/like/3klike', $like->getId());
		$this->assertInstanceOf(Announce::class, $announce);
		$this->assertSame('https://social.test/@alice/1', $announce->getObjectId());
	}

	public function testALikeOfSomethingNotOursAndOtherReasonsAreIgnored(): void {
		$this->assertFalse($this->poller->handle($this->alice, $this->notification('like', 'app.bsky.feed.like', '3k', 'at://' . self::BOB . '/' . RecordMapper::POST . '/3kother')));
		$this->assertFalse($this->poller->handle($this->alice, $this->notification('like', 'app.bsky.feed.like', '3k', 'at://' . self::LOCAL . '/' . RecordMapper::POST . '/3knever')), 'a record we did not write');
		$this->assertFalse($this->poller->handle($this->alice, $this->notification('something-new', 'app.bsky.graph.whatever', '3k')));
		$this->assertSame([], $this->imported);
	}

	public function testAPostOfABellRungOnBlueskyIsStoredAndTheBellsNotification(): void {
		$this->store->expects($this->once())->method('storeByUri')->with('at://' . self::BOB . '/app.bsky.feed.post/3kbell');
		$this->notifications->expects($this->once())->method('onSubscribedPost')->with('https://bsky.app/profile/' . self::BOB . '/post/3kbell', $this->alice->actorId);

		$this->assertTrue($this->poller->handle($this->alice, $this->notification('subscribed-post', RecordMapper::POST, '3kbell')));
	}

	public function testALikeOrRepostOfARepostTellsOfThePostThatWasReposted(): void {
		$events = [];
		$this->notifications->method('onBlueskyEvent')->willReturnCallback(function (string $subType, string $to, string $by, string $object) use (&$events): void {
			$events[] = [$subType, $to, $by, $object];
		});
		$like = $this->notification('like-via-repost', 'app.bsky.feed.like', '3kl');
		$like['record'] = ['subject' => ['uri' => 'at://' . self::LOCAL . '/' . RecordMapper::POST . '/3kmine', 'cid' => 'x'], 'via' => ['uri' => 'at://' . self::LOCAL . '/app.bsky.feed.repost/3kr', 'cid' => 'y']];
		$repost = $this->notification('repost-via-repost', 'app.bsky.feed.repost', '3kp');
		$repost['record'] = ['subject' => ['uri' => 'at://did:plc:carol/' . RecordMapper::POST . '/3kc', 'cid' => 'x']];
		$this->store->expects($this->once())->method('storeByUri')->with('at://did:plc:carol/' . RecordMapper::POST . '/3kc');

		$this->assertTrue($this->poller->handle($this->alice, $like));
		$this->assertTrue($this->poller->handle($this->alice, $repost));
		$bob = 'https://bsky.app/profile/' . self::BOB;
		$this->assertSame([
			[Stream::SUBTYPE_BLUESKY_REPOST_LIKED, $this->alice->actorId, $bob, 'https://social.test/@alice/1'],
			[Stream::SUBTYPE_BLUESKY_REPOST_REPOSTED, $this->alice->actorId, $bob, 'https://bsky.app/profile/did:plc:carol/post/3kc'],
		], $events);
	}

	public function testVerificationAndAStarterPackJoinedAreTold(): void {
		$events = [];
		$this->notifications->method('onBlueskyEvent')->willReturnCallback(function (string $subType) use (&$events): void {
			$events[] = $subType;
		});

		$this->assertTrue($this->poller->handle($this->alice, $this->notification('verified', 'app.bsky.graph.verification', '3kv')));
		$this->assertTrue($this->poller->handle($this->alice, $this->notification('unverified', 'app.bsky.graph.verification', '3ku')));
		$this->assertTrue($this->poller->handle($this->alice, $this->notification('starterpack-joined', 'app.bsky.graph.starterpack', '3ks')));
		$this->assertSame([Stream::SUBTYPE_BLUESKY_VERIFIED, Stream::SUBTYPE_BLUESKY_UNVERIFIED, Stream::SUBTYPE_BLUESKY_STARTER_PACK], $events);
	}

	public function testRepliesMentionsAndQuotesStoreTheirPost(): void {
		$this->store->expects($this->exactly(3))->method('storeByUri')->with($this->stringStartsWith('at://' . self::BOB . '/app.bsky.feed.post/3k'))->willReturn(true);
		foreach (['reply', 'mention', 'quote'] as $reason) {
			$this->assertTrue($this->poller->handle($this->alice, $this->notification($reason, RecordMapper::POST, '3k' . $reason)));
		}
	}

	public function testAnAccountIsAskedAsItselfAndTheCursorAdvances(): void {
		$this->appView->expects($this->once())->method('queryAs')->with(self::LOCAL, $this->isInstanceOf(PrivateKey::class), 'app.bsky.notification.listNotifications', ['limit' => 50])->willReturn(['notifications' => [
			$this->notification('follow', 'app.bsky.graph.follow', '3knew', '', '2026-10-08T12:00:00.000Z'),
			$this->notification('follow', 'app.bsky.graph.follow', '3kold', '', '2026-10-08T10:00:00.000Z'),
		]]);
		$this->cursors->expects($this->once())->method('synced')->with(self::LOCAL, '2026-10-08T12:00:00.000Z', self::NOW, self::NOW + NotificationPoller::INTERVAL, self::TABLE);

		$this->assertSame(1, $this->poller->pollAccount(new Watch(self::LOCAL, 'alice.social.test', '2026-10-08T10:00:00.000Z', self::NOW - 1000, self::NOW, 0, '', 0)));
		$this->assertCount(1, $this->imported);
	}

	public function testAPassEnrolsEveryActiveIdentityAndVisitsTheDueOnes(): void {
		$this->cursors->expects($this->once())->method('add')->with(self::LOCAL, 'alice.social.test', self::TABLE);
		$this->cursors->method('getDue')->with(self::NOW, NotificationPoller::BATCH, self::TABLE)->willReturn([new Watch(self::LOCAL, 'alice.social.test', '', 0, self::NOW, 0, '', 0)]);
		$this->appView->method('queryAs')->willReturn(['notifications' => []]);
		$this->cursors->expects($this->once())->method('synced')->with(self::LOCAL, '', self::NOW, self::NOW + NotificationPoller::INTERVAL, self::TABLE);

		$this->assertSame(['accounts' => 1, 'handled' => 0, 'requests' => 1], $this->poller->poll());
	}

	public function testADeactivatedIdentityLosesItsCursor(): void {
		$gone = new Identity(1, 'https://social.test/@alice', self::LOCAL, 'alice.social.test', 'sealed', '', '', Identity::STATE_DEACTIVATED, '', 0);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('getByDid')->willReturn($gone);
		$poller = new NotificationPoller($this->createMock(AtprotoConfig::class), $identities, $this->cursors, $this->appView, $this->store, $this->createMock(LocalRecordResolver::class), new ActorMapper(), $this->actors, $this->createMock(Blocklist::class), $this->createMock(ImportService::class), $this->createMock(NotificationService::class), $this->createMock(ITimeFactory::class), new NullLogger());
		$this->cursors->expects($this->once())->method('remove')->with(self::LOCAL, self::TABLE);
		$this->appView->expects($this->never())->method('queryAs');

		$this->assertSame(0, $poller->pollAccount(new Watch(self::LOCAL, '', '', 0, 0, 0, '', 0)));
	}

	public function testABlockedAccountsInteractionsAreDroppedOnArrival(): void {
		$blocklist = $this->createMock(Blocklist::class);
		$blocklist->method('isBlockedDid')->willReturn(true);
		$poller = new NotificationPoller($this->createMock(AtprotoConfig::class), $this->identities, $this->cursors, $this->appView, $this->store, $this->createMock(LocalRecordResolver::class), new ActorMapper(), $this->actors, $blocklist, $this->createMock(ImportService::class), $this->createMock(NotificationService::class), $this->createMock(ITimeFactory::class), new NullLogger());
		$this->store->expects($this->never())->method('storeByUri');

		$this->assertFalse($poller->handle($this->alice, $this->notification('follow', 'app.bsky.graph.follow', '3kf')));
		$this->assertFalse($poller->handle($this->alice, $this->notification('reply', RecordMapper::POST, '3kr')));
	}

	private function notification(string $reason, string $collection, string $rkey, string $subject = '', string $indexedAt = '2026-10-08T12:00:00.000Z'): array {
		$n = [
			'uri' => 'at://' . self::BOB . '/' . $collection . '/' . $rkey,
			'cid' => 'bafy' . $rkey,
			'author' => ['did' => self::BOB, 'handle' => 'bob.bsky.social', 'displayName' => 'Bob'],
			'reason' => $reason,
			'record' => [],
			'isRead' => false,
			'indexedAt' => $indexedAt,
		];
		if ($subject !== '') {
			$n['reasonSubject'] = $subject;
		}

		return $n;
	}
}
