<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\AP;
use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Publisher\InteractionPublisher;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Reader\PostMapper;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\StreamCardsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Model\StreamCard;
use OCA\Social\Service\ImportService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PostStoreTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const OTHER = 'did:plc:z72i7hdynmk6r22z27h6tvur';

	/** @var ImportService&MockObject */
	private ImportService $import;
	/** @var StreamRequest&MockObject */
	private StreamRequest $streams;
	/** @var BlueskyActorService&MockObject */
	private BlueskyActorService $actors;
	private PostStore $store;
	/** @var AppViewClient&MockObject */
	private AppViewClient $appView;
	/** @var InteractionPublisher&MockObject */
	private InteractionPublisher $interactions;
	/** @var string[] ids the stream already has */
	private array $known = [];
	/** @var ACore[] what reached the import path */
	private array $imported = [];
	/** @var StreamCardsRequest&\PHPUnit\Framework\MockObject\MockObject */
	private StreamCardsRequest $cards;

	protected function setUp(): void {
		$ap = $this->createMock(AP::class);
		$ap->method('getItemFromData')->willReturnCallback(static function (array $data): ACore {
			$item = match ($data['type']) {
				'Create' => new Create(),
				'Announce' => new Announce(),
				'Delete' => new Delete(),
				'Person' => new Person(),
			};
			$item->setUrlCloud('https://social.test');
			$item->import($data);
			if (is_array($data['object'] ?? null)) {
				$note = new Note();
				$note->setUrlCloud('https://social.test');
				$note->import($data['object']);
				$item->setObject($note);
			}

			return $item;
		});
		AP::set($ap);
		$this->import = $this->createMock(ImportService::class);
		$this->import->method('parseIncomingRequest')->willReturnCallback(function (ACore $activity): void {
			$this->imported[] = $activity;
			// the post is known once its Create went through
			if ($activity instanceof Create && $activity->getObject() !== null) {
				$this->known[] = $activity->getObject()->getId();
			}
		});
		$this->streams = $this->createMock(StreamRequest::class);
		$this->streams->method('getStreamById')->willReturnCallback(fn (string $id): Stream => in_array($id, $this->known, true) ? new Note() : throw new StreamNotFoundException());
		$this->streams->method('getAnnounceBy')->willThrowException(new StreamNotFoundException());
		$this->actors = $this->createMock(BlueskyActorService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$this->appView = $this->createMock(AppViewClient::class);
		$this->interactions = $this->createMock(InteractionPublisher::class);
		$this->cards = $this->createMock(StreamCardsRequest::class);
		$this->store = new PostStore(new PostMapper($this->resolver()), $this->appView, $this->interactions, new ActorMapper(), $this->actors, $this->createMock(Blocklist::class), $this->createMock(LabelerService::class), $this->import, $this->streams, $time, new NullLogger(), $this->cards);
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	public function testAPostGoesThroughTheImportPathFromBskyApp(): void {
		$this->actors->method('cached')->willReturn($this->person(self::DID));
		$this->assertSame(1, $this->store->storeFeedItem(['post' => $this->postView()]));

		$this->assertCount(1, $this->imported);
		$create = $this->imported[0];
		$this->assertInstanceOf(Create::class, $create);
		$this->assertSame('bsky.app', $create->getOrigin(), 'the origin every id is checked against');
		$this->assertSame('https://bsky.app/profile/' . self::DID, $create->getActorId());
		$note = $create->getObject();
		$this->assertSame('https://bsky.app/profile/' . self::DID . '/post/3kznmn7xqxl22', $note->getId());
		$this->assertSame(3, $note->getDetailInt(Details::LIKES));
		$this->assertSame(3, $note->getDetailInt(Details::REMOTE_LIKES));
		$this->assertSame(1, $note->getDetailInt(Details::BOOSTS));
		$this->assertSame('at://' . self::DID . '/app.bsky.feed.post/3kznmn7xqxl22', $note->getDetails(PostMapper::DETAIL)['uri']);
	}

	public function testAQuoteItsQuotedAuthorDetachedArrivesWithdrawn(): void {
		$this->actors->method('cached')->willReturn($this->person(self::DID));
		$view = $this->postView();
		$view['embed'] = ['$type' => 'app.bsky.embed.record#view', 'record' => ['$type' => 'app.bsky.embed.record#viewDetached', 'uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kquoted', 'detached' => true]];
		$this->store->storeFeedItem(['post' => $view]);
		$note = $this->imported[0]->getObject();

		$this->assertSame('https://bsky.app/profile/' . self::OTHER . '/post/3kquoted', $note->getQuote());
		$this->assertSame(Stream::QUOTE_REVOKED, $note->getQuoteState());
		$this->assertSame('revoked', $note->exportAsLocal()['quote']['state']);
	}

	public function testAQuoteOnBlueskyStandsWithoutAStamp(): void {
		$this->actors->method('cached')->willReturn($this->person(self::DID));
		$view = $this->postView();
		$view['embed'] = ['$type' => 'app.bsky.embed.record#view', 'record' => ['$type' => 'app.bsky.embed.record#viewRecord', 'uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kquoted', 'cid' => 'bafyq']];
		$this->store->storeFeedItem(['post' => $view]);
		$held = $this->createStub(StreamRequest::class);
		$held->method('getStreamById')->willThrowException(new StreamNotFoundException());
		\OC::$server->register(StreamRequest::class, $held);

		$this->assertSame('accepted', $this->imported[0]->getObject()->exportAsLocal()['quote']['state']);
	}

	public function testTheRepliesABlueskyAuthorHidAreHiddenHere(): void {
		$root = (new Note())->setId('https://bsky.app/profile/' . self::DID . '/post/3kroot');
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturn($root);
		$streams->expects($this->once())->method('updateDetails')->with($root);
		$store = new PostStore(new PostMapper($this->resolver()), $this->appView, $this->interactions, new ActorMapper(), $this->actors, $this->createMock(Blocklist::class), $this->createMock(LabelerService::class), $this->import, $streams, $this->createMock(ITimeFactory::class), new NullLogger(), $this->cards);
		$gate = ['uri' => 'at://x', 'record' => ['post' => 'at://' . self::DID . '/app.bsky.feed.post/3kroot', 'hiddenReplies' => ['at://' . self::OTHER . '/app.bsky.feed.post/3kr', 'not a uri']]];

		$store->rememberHiddenReplies($gate);
		$store->rememberHiddenReplies($gate);

		$this->assertSame(['https://bsky.app/profile/' . self::OTHER . '/post/3kr'], $root->getHiddenReplies(), 'and written once');
	}

	public function testAFeedThePostEmbedsIsItsCard(): void {
		$this->actors->method('cached')->willReturn($this->person(self::DID));
		$view = $this->postView();
		$view['embed'] = ['$type' => 'app.bsky.embed.record#view', 'record' => [
			'$type' => 'app.bsky.feed.defs#generatorView', 'uri' => 'at://' . self::DID . '/app.bsky.feed.generator/cats', 'cid' => 'bafyfeed', 'did' => 'did:web:feeds.test',
			'creator' => ['did' => self::DID, 'handle' => 'alice.bsky.social'], 'displayName' => 'Cats', 'description' => 'Only cats', 'avatar' => 'https://cdn.bsky.app/img/avatar/plain/cats@jpeg', 'indexedAt' => '2026-10-08T10:00:00.000Z',
		]];
		$this->cards->expects($this->once())->method('save')->willReturnCallback(function (StreamCard $card): void {
			$this->assertSame(['https://bsky.app/profile/' . self::DID . '/post/3kznmn7xqxl22', 'https://bsky.app/profile/alice.bsky.social/feed/cats', 'Cats', 'Only cats', 'https://cdn.bsky.app/img/avatar/plain/cats@jpeg', 'Bluesky feed by @alice.bsky.social'],
				[$card->getStreamId(), $card->getUrl(), $card->getTitle(), $card->getDescription(), $card->getImage(), $card->getProviderName()]);
		});

		$this->assertSame(1, $this->store->storeFeedItem(['post' => $view]));
		$this->assertArrayNotHasKey('card', $this->imported[0]->getObject()->getDetails(PostMapper::DETAIL), 'not kept twice');
	}

	public function testAKnownPostIsNotStoredAgain(): void {
		$this->known[] = 'https://bsky.app/profile/' . self::DID . '/post/3kznmn7xqxl22';
		$this->assertSame(0, $this->store->storeFeedItem(['post' => $this->postView()]));
		$this->assertSame([], $this->imported);
	}

	public function testADirectMessageGoesThroughTheSameDoorOnce(): void {
		$id = 'https://bsky.app/profile/' . self::DID . '/convo/c1/m1';
		$create = ['id' => $id . '/activity', 'type' => 'Create', 'actor' => 'https://bsky.app/profile/' . self::DID, 'to' => ['https://social.test/users/alice'], 'cc' => [], 'object' => [
			'id' => $id, 'type' => 'Note', 'attributedTo' => 'https://bsky.app/profile/' . self::DID, 'to' => ['https://social.test/users/alice'], 'cc' => [], 'content' => '<p>hi</p>',
		]];

		$this->assertTrue($this->store->storeMessage($create));
		$this->assertFalse($this->store->storeMessage($create), 'a message is stored once');
		$this->assertCount(1, $this->imported);
		$this->assertSame('bsky.app', $this->imported[0]->getOrigin());
		$this->assertSame(['https://social.test/users/alice'], $this->imported[0]->getObject()->getToArray());
	}

	public function testAnUnseenAuthorIsMadeACachedActorFirst(): void {
		$this->actors->method('cached')->willReturn(null);
		$this->actors->expects($this->once())->method('store')->with($this->callback(static fn (Person $p): bool => $p->getId() === 'https://bsky.app/profile/' . self::DID && $p->getAccount() === 'alice.bsky.social'));
		$this->assertTrue($this->store->storePost($this->postView()));
	}

	public function testALimitedAuthorsPostsAreNotStored(): void {
		$limited = $this->person(self::DID);
		$limited->setDetailArray(ActorMapper::DETAIL, ['limited' => true]);
		$this->actors->method('cached')->willReturn($limited);
		$this->assertFalse($this->store->storePost($this->postView()));
		$this->assertSame([], $this->imported);
	}

	public function testARepostStoresThePostThenTheAnnounceByTheReposter(): void {
		$this->actors->method('cached')->willReturnCallback(fn (string $did): ?Person => $this->person($did));
		$item = ['post' => $this->postView(), 'reason' => ['$type' => 'app.bsky.feed.defs#reasonRepost', 'by' => ['did' => self::OTHER, 'handle' => 'bob.bsky.social'], 'indexedAt' => '2026-10-08T11:00:00.000Z']];
		$this->assertSame(2, $this->store->storeFeedItem($item));
		$this->assertInstanceOf(Create::class, $this->imported[0]);
		$announce = $this->imported[1];
		$this->assertInstanceOf(Announce::class, $announce);
		$this->assertSame('https://bsky.app/profile/' . self::OTHER, $announce->getActorId());
		$this->assertSame('https://bsky.app/profile/' . self::DID . '/post/3kznmn7xqxl22', $announce->getObjectId());
		$this->assertSame('bsky.app', $announce->getOrigin());
	}

	public function testAReplyWhoseParentIsNotHereFetchesTheParentOneHopUp(): void {
		$this->actors->method('cached')->willReturnCallback(fn (string $did): ?Person => $this->person($did));
		$reply = $this->postView();
		$reply['uri'] = 'at://' . self::DID . '/app.bsky.feed.post/3kreply';
		$reply['record']['reply'] = [
			'root' => ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kroot', 'cid' => 'bafyroot'],
			'parent' => ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kparent', 'cid' => 'bafyparent'],
		];
		$parent = $this->postView();
		$parent['uri'] = 'at://' . self::OTHER . '/app.bsky.feed.post/3kparent';
		$parent['author'] = ['did' => self::OTHER, 'handle' => 'bob.bsky.social'];
		$parent['record']['reply'] = ['root' => ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kroot', 'cid' => 'bafyroot'], 'parent' => ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kroot', 'cid' => 'bafyroot']];
		$this->appView->expects($this->once())->method('query')->with('app.bsky.feed.getPosts', ['uris' => ['at://' . self::OTHER . '/app.bsky.feed.post/3kparent']])->willReturn(['posts' => [$parent]]);

		$this->assertTrue($this->store->storePost($reply));
		$this->assertCount(2, $this->imported, 'the parent, then the reply; the parent\'s own parent is not fetched');
		$this->assertSame('https://bsky.app/profile/' . self::OTHER . '/post/3kparent', $this->imported[0]->getObject()->getId());
		$this->assertSame('https://bsky.app/profile/' . self::DID . '/post/3kreply', $this->imported[1]->getObject()->getId());
	}

	public function testPostsTheAppViewNoLongerHasAreDeletedAndTheirRecordsWithThem(): void {
		$kept = 'https://bsky.app/profile/' . self::DID . '/post/3kkept';
		$gone = 'https://bsky.app/profile/' . self::DID . '/post/3kgone';
		$this->known = [$kept, $gone];
		$this->appView->expects($this->once())->method('query')->with('app.bsky.feed.getPosts', ['uris' => ['at://' . self::DID . '/app.bsky.feed.post/3kkept', 'at://' . self::DID . '/app.bsky.feed.post/3kgone']])
			->willReturn(['posts' => [['uri' => 'at://' . self::DID . '/app.bsky.feed.post/3kkept']]]);
		$this->interactions->expects($this->once())->method('removeAllOf')->with($gone);

		$this->assertSame(1, $this->store->deleteGone([$kept, $gone, 'https://mastodon.test/x']));
		$this->assertCount(1, $this->imported);
		$this->assertSame($gone, $this->imported[0]->getObjectId());
	}

	public function testNothingIsConcludedFromAnAppViewThatDidNotAnswer(): void {
		$this->known = ['https://bsky.app/profile/' . self::DID . '/post/3k'];
		$this->appView->method('query')->willThrowException(new AtprotoException('down'));
		$this->assertSame(0, $this->store->deleteGone($this->known));
		$this->appView = $this->createMock(AppViewClient::class);
		$this->assertSame([], $this->imported);
	}

	public function testADeleteIsSentForAKnownPostOnly(): void {
		$id = 'https://bsky.app/profile/' . self::DID . '/post/3kznmn7xqxl22';
		$this->assertFalse($this->store->delete($id));
		$this->known[] = $id;
		$this->assertTrue($this->store->delete($id));
		$delete = $this->imported[0];
		$this->assertInstanceOf(Delete::class, $delete);
		$this->assertSame('https://bsky.app/profile/' . self::DID, $delete->getActorId());
		$this->assertSame($id, $delete->getObjectId());
	}

	private function person(string $did): Person {
		$person = new Person();
		$person->setId('https://bsky.app/profile/' . $did);

		return $person;
	}

	private function postView(): array {
		return [
			'uri' => 'at://' . self::DID . '/app.bsky.feed.post/3kznmn7xqxl22',
			'cid' => 'bafyreipost',
			'author' => ['did' => self::DID, 'handle' => 'alice.bsky.social', 'displayName' => 'Alice'],
			'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'Hello', 'createdAt' => '2026-10-08T10:00:00.000Z'],
			'replyCount' => 0, 'repostCount' => 1, 'likeCount' => 3, 'quoteCount' => 0,
			'indexedAt' => '2026-10-08T10:00:01.000Z',
		];
	}

	private function resolver(): LocalRecordResolver {
		$identities = $this->createMock(AtprotoIdentityRequest::class);
		$identities->method('getByDid')->willThrowException(new AtprotoIdentityNotFoundException());

		return new LocalRecordResolver($identities, $this->createMock(AtprotoRepoRequest::class));
	}
}
