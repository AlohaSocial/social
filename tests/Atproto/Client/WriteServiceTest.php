<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Client;

use OCA\Social\Atproto\Chat\ChatDeclaration;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Client\WriteService;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\RepoHead;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Publisher\BlueskyBlocks;
use OCA\Social\Atproto\Publisher\InteractionPublisher;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Repository\CommitResult;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AvatarService;
use OCA\Social\Service\BannerService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\LikeService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\PostReviewService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\RelationshipService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\StreamService;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class WriteServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const BOB = 'did:plc:z72i7hdynmk6r22z27h6tvur';

	/** @var PostService&MockObject */
	private PostService $posts;
	/** @var PostReviewService&MockObject */
	private PostReviewService $review;
	/** @var LikeService&MockObject */
	private LikeService $likes;
	/** @var StreamService&MockObject */
	private StreamService $streams;
	/** @var RepositoryService&MockObject */
	private RepositoryService $repositories;
	/** @var PostStore&MockObject */
	private PostStore $postStore;
	/** @var Publisher&MockObject */
	private Publisher $publisher;
	/** @var AtprotoBlobRequest&MockObject */
	private AtprotoBlobRequest $blobs;
	/** @var PictureService&MockObject */
	private PictureService $pictures;
	/** @var VideoBlobService&MockObject */
	private VideoBlobService $videos;
	/** @var DocumentService&MockObject */
	private DocumentService $documents;
	/** @var LocalRecordResolver&MockObject */
	private LocalRecordResolver $local;
	/** @var AccountService&MockObject */
	private AccountService $accounts;
	/** @var CacheActorService&MockObject */
	private CacheActorService $cacheActors;
	/** @var AvatarService&MockObject */
	private AvatarService $avatars;
	/** @var BannerService&MockObject */
	private BannerService $banners;
	private WriteService $writes;
	/** @var RelationshipService&MockObject */
	private RelationshipService $relationships;
	/** @var BlueskyBlocks&MockObject */
	private BlueskyBlocks $blocks;
	private bool $publishesBlocks = false;
	/** @var ChatDeclaration&MockObject */
	private ChatDeclaration $declaration;
	private ClientSession $session;
	private Person $alice;
	/** @var StoredRecord[] */
	private array $records = [];

	protected function setUp(): void {
		$this->alice = new Person();
		$this->alice->setId('https://social.test/@alice');
		$this->alice->setLocal(true);
		$accounts = $this->createMock(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn($this->alice);
		$accounts->method('changingProfile')->willReturnCallback(static fn (string $userId, callable $changes): mixed => $changes());
		$this->accounts = $accounts;
		$this->avatars = $this->createMock(AvatarService::class);
		$this->cacheActors = $this->createMock(CacheActorService::class);
		$this->banners = $this->createMock(BannerService::class);
		$this->posts = $this->createMock(PostService::class);
		$this->streams = $this->createMock(StreamService::class);
		$this->review = $this->createMock(PostReviewService::class);
		$this->likes = $this->createMock(LikeService::class);
		$this->repositories = $this->createMock(RepositoryService::class);
		$this->repositories->method('getRecordsByLocalId')->willReturnCallback(fn (string $id): array => array_values(array_filter($this->records, static fn (StoredRecord $r): bool => $r->localId === $id)));
		$this->repositories->method('getRecord')->willReturnCallback(fn (string $did, string $c, string $rkey): ?StoredRecord => array_values(array_filter($this->records, static fn (StoredRecord $r): bool => $r->collection === $c && $r->rkey === $rkey))[0] ?? null);
		$this->repositories->method('getHead')->willReturn(new RepoHead(self::DID, 'bafyreicommit', '3kzrev', 3, 0, 0));
		$this->postStore = $this->createMock(PostStore::class);
		$this->publisher = $this->createMock(Publisher::class);
		$this->pictures = $this->createMock(PictureService::class);
		$this->blobs = $this->createMock(AtprotoBlobRequest::class);
		$this->videos = $this->createMock(VideoBlobService::class);
		$this->documents = $this->createMock(DocumentService::class);
		$this->local = $this->createMock(LocalRecordResolver::class);
		$this->local->method('postId')->willReturnCallback(static fn (string $uri): string => match ($uri) {
			'at://' . self::DID . '/app.bsky.feed.post/3kmine' => 'https://social.test/@alice/1',
			'at://' . self::BOB . '/app.bsky.feed.post/3kbob' => 'https://bsky.app/profile/' . self::BOB . '/post/3kbob',
			default => '',
		});
		$this->relationships = $this->createMock(RelationshipService::class);
		$this->blocks = $this->createMock(BlueskyBlocks::class);
		$this->blocks->method('isPublished')->willReturnCallback(fn (): bool => $this->publishesBlocks);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$this->declaration = $this->createMock(ChatDeclaration::class);
		$this->writes = new WriteService(
			$accounts, $this->posts, $this->review, $this->createMock(ModerationService::class), $this->streams, $this->likes, $this->createMock(BoostService::class), $this->createMock(FollowService::class),
			$this->cacheActors, $this->createMock(ReportService::class), $this->documents,
			$this->publisher, $this->pictures, $this->videos, $this->createMock(InteractionPublisher::class), $this->repositories, $this->local, $this->postStore,
			$this->blobs, $this->createMock(IURLGenerator::class), new NullLogger(), $this->avatars, $this->banners, $identities,
			$this->relationships, $this->blocks, $this->declaration,
		);
		$this->session = new ClientSession('alice', new Identity(1, $this->alice->getId(), self::DID, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0), 'jti');
	}

	/** The profile record this server published, naming an avatar and a banner. */
	private function publishedProfile(Cid $avatar, Cid $banner): void {
		$blob = static fn (Cid $cid): array => ['$type' => 'blob', 'ref' => $cid, 'mimeType' => 'image/jpeg', 'size' => 9];
		$bytes = DagCbor::encode(['$type' => RecordMapper::PROFILE, 'displayName' => 'Alice', 'avatar' => $blob($avatar), 'banner' => $blob($banner)]);
		$this->records[] = new StoredRecord(self::DID, RecordMapper::PROFILE, RecordMapper::PROFILE_RKEY, Cid::forDagCbor($bytes), $bytes, '', 0);
	}

	public function testAProfileAnAppSavesTakesItsNewAvatarAndLeavesTheBannerAlone(): void {
		$avatar = Cid::forRaw('the old face');
		$banner = Cid::forRaw('the sea');
		$this->publishedProfile($avatar, $banner);
		$new = Cid::forRaw('a new face');
		$this->blobs->method('get')->with(self::DID, $new->toString())->willReturn(new BlobRef(self::DID, $new, 'https://social.test/documents/9', 'image/png', 10));
		$this->pictures->method('read')->willReturn('a new face');
		$this->avatars->expects($this->once())->method('setFromFile')->with('alice', $this->callback(static fn (string $path): bool => file_get_contents($path) === 'a new face'));
		$this->banners->expects($this->never())->method('setFromTempFile');
		$this->banners->expects($this->never())->method('remove');
		$this->accounts->expects($this->once())->method('setDisplayName')->with('alice', 'Alice A.');
		$changed = new Person();
		$changed->setId('https://social.test/@alice');
		$this->cacheActors->method('getFromId')->willReturn($changed);
		$this->publisher->expects($this->once())->method('publishProfile')->with($this->identicalTo($changed));
		$ref = static fn (Cid $cid): array => ['$type' => 'blob', 'ref' => ['$link' => $cid->toString()], 'mimeType' => 'image/jpeg', 'size' => 9];

		$this->writes->put($this->session, ['repo' => self::DID, 'collection' => RecordMapper::PROFILE, 'rkey' => RecordMapper::PROFILE_RKEY, 'record' => [
			'$type' => RecordMapper::PROFILE, 'displayName' => 'Alice A.', 'avatar' => $ref($new), 'banner' => $ref($banner),
		]]);
	}

	public function testThePronounsAndTheWebsiteAnAppSavesAreTheProfileRows(): void {
		$this->publishedProfile(Cid::forRaw('a face'), Cid::forRaw('the sea'));
		$this->alice->setFields([['name' => 'Mastodon', 'value' => 'https://mastodon.example/@alice']]);
		$this->accounts->expects($this->once())->method('setFields')->with('alice', [
			['name' => 'Mastodon', 'value' => 'https://mastodon.example/@alice'],
			['name' => 'Pronouns', 'value' => 'she/her'],
			['name' => 'Website', 'value' => 'https://alice.example'],
		]);
		$face = ['$type' => 'blob', 'ref' => ['$link' => Cid::forRaw('a face')->toString()], 'mimeType' => 'image/jpeg', 'size' => 6];
		$sea = ['$type' => 'blob', 'ref' => ['$link' => Cid::forRaw('the sea')->toString()], 'mimeType' => 'image/jpeg', 'size' => 7];

		$this->writes->put($this->session, ['repo' => self::DID, 'collection' => RecordMapper::PROFILE, 'rkey' => RecordMapper::PROFILE_RKEY, 'record' => [
			'$type' => RecordMapper::PROFILE, 'displayName' => 'Alice', 'avatar' => $face, 'banner' => $sea, 'pronouns' => 'she/her', 'website' => 'https://alice.example',
		]]);
	}

	public function testAProfileSavedWithItsRowsAsTheyWereLeavesThemAlone(): void {
		$this->publishedProfile(Cid::forRaw('a face'), Cid::forRaw('the sea'));
		$this->accounts->expects($this->never())->method('setFields');

		$this->writes->put($this->session, ['repo' => self::DID, 'collection' => RecordMapper::PROFILE, 'rkey' => RecordMapper::PROFILE_RKEY, 'record' => [
			'$type' => RecordMapper::PROFILE, 'displayName' => 'Alice A.',
		]]);
	}

	public function testAPictureLeftOutOfTheProfileIsTakenAway(): void {
		$this->publishedProfile(Cid::forRaw('a face'), Cid::forRaw('the sea'));
		$this->avatars->expects($this->never())->method('setFromFile');
		$this->avatars->expects($this->never())->method('remove');
		$this->banners->expects($this->once())->method('remove')->with('alice');

		$this->writes->put($this->session, ['repo' => self::DID, 'collection' => RecordMapper::PROFILE, 'rkey' => RecordMapper::PROFILE_RKEY, 'record' => [
			'$type' => RecordMapper::PROFILE, 'avatar' => ['$type' => 'blob', 'ref' => ['$link' => Cid::forRaw('a face')->toString()], 'mimeType' => 'image/jpeg', 'size' => 6],
		]]);
	}

	public function testAnAvatarNotUploadedHereIsRefused(): void {
		$this->publishedProfile(Cid::forRaw('a face'), Cid::forRaw('the sea'));
		$this->blobs->method('get')->willReturn(null);

		$this->expectException(XrpcException::class);
		$this->writes->put($this->session, ['repo' => self::DID, 'collection' => RecordMapper::PROFILE, 'rkey' => RecordMapper::PROFILE_RKEY, 'record' => [
			'$type' => RecordMapper::PROFILE, 'avatar' => ['$type' => 'blob', 'ref' => ['$link' => Cid::forRaw('elsewhere')->toString()], 'mimeType' => 'image/jpeg', 'size' => 9],
		]]);
	}

	/** Writes go into the repository double as they would into the repository. */
	private function keepWrites(): void {
		$this->repositories->method('write')->willReturnCallback(function (string $did, $key, array $writes): CommitResult {
			foreach ($writes as $write) {
				$this->records = array_values(array_filter($this->records, static fn (StoredRecord $r): bool => !($r->collection === $write->collection && $r->rkey === $write->rkey)));
				if ($write->action !== RepoWrite::DELETE) {
					$bytes = DagCbor::encode($write->record);
					$this->records[] = new StoredRecord($did, $write->collection, $write->rkey, Cid::forDagCbor($bytes), $bytes, '', 0);
				}
			}

			return new CommitResult($did, Cid::forRaw('commit'), '3krev', 1, []);
		});
	}

	public function testAListAndItsMembersAreKeptAsTheAppWroteThem(): void {
		$this->keepWrites();
		$list = ['$type' => 'app.bsky.graph.list', 'purpose' => 'app.bsky.graph.defs#curatelist', 'name' => 'Friends', 'createdAt' => '2026-10-09T10:00:00.000Z'];

		$made = $this->writes->create($this->session, ['repo' => self::DID, 'collection' => 'app.bsky.graph.list', 'record' => $list]);
		$this->assertStringStartsWith('at://' . self::DID . '/app.bsky.graph.list/', $made['uri']);
		$rkey = substr($made['uri'], (int)strrpos($made['uri'], '/') + 1);

		$this->writes->put($this->session, ['repo' => self::DID, 'collection' => 'app.bsky.graph.list', 'rkey' => $rkey, 'record' => ['name' => 'Close friends'] + $list]);
		$this->assertSame('Close friends', DagCbor::decode($this->records[0]->bytes)['name'], 'replaced under its key');

		$this->writes->delete($this->session, ['repo' => self::DID, 'collection' => 'app.bsky.graph.list', 'rkey' => $rkey]);
		$this->assertSame([], $this->records);
	}

	public function testAGateIsKeptOnlyUnderTheKeyOfTheAccountsOwnPost(): void {
		$this->keepWrites();
		$gate = static fn (string $post): array => ['$type' => 'app.bsky.feed.threadgate', 'post' => $post, 'allow' => [], 'createdAt' => '2026-10-09T10:00:00.000Z'];

		$made = $this->writes->create($this->session, ['repo' => self::DID, 'collection' => 'app.bsky.feed.threadgate', 'record' => $gate('at://' . self::DID . '/app.bsky.feed.post/3kmine')]);
		$this->assertSame('at://' . self::DID . '/app.bsky.feed.threadgate/3kmine', $made['uri']);

		$this->expectException(XrpcException::class);
		$this->writes->create($this->session, ['repo' => self::DID, 'collection' => 'app.bsky.feed.threadgate', 'record' => $gate('at://' . self::BOB . '/app.bsky.feed.post/3kbob')]);
	}

	public function testABlockFromAnAppIsABlockHereWhenThePersonPublishesTheirs(): void {
		$this->publishesBlocks = true;
		$bob = (new Person())->setId('https://bsky.app/profile/' . self::BOB);
		$this->cacheActors->method('getFromId')->with('https://bsky.app/profile/' . self::BOB)->willReturn($bob);
		$this->relationships->expects($this->once())->method('block')->with($this->alice, $bob)->willReturnCallback(function (): void {
			$bytes = DagCbor::encode(['$type' => BlueskyBlocks::COLLECTION, 'subject' => self::BOB, 'createdAt' => '2026-10-09T10:00:00.000Z']);
			$this->records[] = new StoredRecord(self::DID, BlueskyBlocks::COLLECTION, '3kblock', Cid::forDagCbor($bytes), $bytes, BlueskyBlocks::localId($this->alice->getId(), 'https://bsky.app/profile/' . self::BOB), 0);
		});

		$made = $this->writes->create($this->session, ['repo' => self::DID, 'collection' => BlueskyBlocks::COLLECTION, 'record' => ['$type' => BlueskyBlocks::COLLECTION, 'subject' => self::BOB, 'createdAt' => '2026-10-09T10:00:00.000Z']]);

		$this->assertSame('at://' . self::DID . '/' . BlueskyBlocks::COLLECTION . '/3kblock', $made['uri']);
		// as BlueskyBlocks does once unblocked: the record is withdrawn
		$this->relationships->expects($this->once())->method('unblock')->with($this->alice, $bob)->willReturnCallback(function (): void {
			$this->records = [];
		});
		$this->repositories->expects($this->never())->method('write');
		$this->writes->delete($this->session, ['repo' => self::DID, 'collection' => BlueskyBlocks::COLLECTION, 'rkey' => '3kblock']);
	}

	/** Who may send direct messages, as an app sets it: the setting here, which writes the record the app gets back. */
	public function testAnAppsChatDeclarationIsTheSettingHere(): void {
		$record = ['$type' => ChatDeclaration::COLLECTION, 'allowIncoming' => 'none'];
		$this->declaration->expects($this->exactly(2))->method('fromApp')->with($this->session, 'self', $record)->willReturnCallback(function () use ($record): void {
			$bytes = DagCbor::encode($record);
			$this->records = [new StoredRecord(self::DID, ChatDeclaration::COLLECTION, 'self', Cid::forDagCbor($bytes), $bytes, '', 0)];
		});
		$this->repositories->expects($this->never())->method('write');

		$put = $this->writes->put($this->session, ['repo' => self::DID, 'collection' => ChatDeclaration::COLLECTION, 'rkey' => 'self', 'record' => $record]);
		$created = $this->writes->create($this->session, ['repo' => self::DID, 'collection' => ChatDeclaration::COLLECTION, 'rkey' => 'self', 'record' => $record]);

		$this->assertSame('at://' . self::DID . '/' . ChatDeclaration::COLLECTION . '/self', $put['uri']);
		$this->assertSame($put['uri'], $created['uri']);
	}

	public function testAListBlockIsABlockAndRefused(): void {
		$this->repositories->expects($this->never())->method('write');

		$this->expectException(XrpcException::class);
		$this->writes->create($this->session, ['repo' => self::DID, 'collection' => 'app.bsky.graph.listblock', 'record' => ['$type' => 'app.bsky.graph.listblock', 'subject' => 'at://x', 'createdAt' => '2026-10-09T10:00:00.000Z']]);
	}

	public function testAPostFromAnAppIsASocialPostAndTheAnswerIsItsRecord(): void {
		$made = new Note();
		$made->setId('https://social.test/@alice/2');
		$this->postStore->method('isKnown')->willReturn(true);
		$this->posts->expects($this->once())->method('createPost')->with($this->callback(function (Post $post): bool {
			$this->assertSame('Read https://example.com/a/very/long/path?x=1 now @bob.bsky.social', $post->getContent(), 'the whole link, as Social links what the text says');
			$this->assertSame('public', $post->getType());
			$this->assertSame('de', $post->getLanguage());
			$this->assertSame('https://bsky.app/profile/' . self::BOB . '/post/3kbob', $post->getReplyTo());

			return true;
		}))->willReturnCallback(function () use ($made): Create {
			$this->records[] = $this->record(RecordMapper::POST, '3knew', $made->getId());
			$activity = new Create();
			$activity->setObjectId($made->getId());

			return $activity;
		});
		$this->streams->method('getStreamById')->willReturnCallback(static fn (string $id): Note => $made);
		$this->publisher->expects($this->once())->method('publishPost')->with($made);
		$text = 'Read example.com/a/very/lo… now @bob.bsky.social';
		$start = strpos($text, 'example');
		$end = strpos($text, ' now');

		$answer = $this->writes->create($this->session, ['repo' => self::DID, 'collection' => RecordMapper::POST, 'record' => [
			'$type' => RecordMapper::POST,
			'text' => $text,
			'facets' => [['index' => ['byteStart' => $start, 'byteEnd' => $end], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com/a/very/long/path?x=1']]]],
			'langs' => ['de'],
			'reply' => ['root' => ['uri' => 'at://' . self::BOB . '/app.bsky.feed.post/3kbob', 'cid' => 'x'], 'parent' => ['uri' => 'at://' . self::BOB . '/app.bsky.feed.post/3kbob', 'cid' => 'x']],
			'createdAt' => '2026-10-08T10:00:00.000Z',
		]]);

		$this->assertSame('at://' . self::DID . '/app.bsky.feed.post/3knew', $answer['uri']);
		$this->assertSame(['cid' => 'bafyreicommit', 'rev' => '3kzrev'], $answer['commit']);
		$this->assertSame('valid', $answer['validationStatus']);
	}

	public function testAPostTheReviewRulesHoldIsKeptAndNotPublished(): void {
		$this->review->method('assess')->willReturn('first_post');
		$this->review->expects($this->once())->method('hold')->with($this->alice, $this->callback(function (array $params): bool {
			$this->assertSame('Hello from an app', $params['text']);
			$this->assertSame('public', $params['visibility']);
			$this->assertSame([], $params['media_ids']);
			$this->assertNull($params['in_reply_to_id']);

			return true;
		}), 'first_post');
		$this->posts->expects($this->never())->method('createPost');
		$this->publisher->expects($this->never())->method('publishPost');

		try {
			$this->writes->create($this->session, ['repo' => self::DID, 'collection' => RecordMapper::POST, 'record' => [
				'$type' => RecordMapper::POST, 'text' => 'Hello from an app', 'createdAt' => '2026-10-08T10:00:00.000Z',
			]]);
			$this->fail('a held post is not made');
		} catch (XrpcException $e) {
			$this->assertSame(400, $e->status);
			$this->assertStringContainsString('waiting for a moderator', $e->getMessage());
		}
	}

	public function testAnUploadIsNamedByTheCidOfWhatWasStored(): void {
		$stored = new Document();
		$stored->setId('https://social.test/documents/local/1');
		$this->documents->expects($this->once())->method('storeLocalAttachment')->with($this->alice)->willReturn($stored);
		$cid = Cid::forRaw('the stored bytes, metadata removed');
		$this->pictures->expects($this->once())->method('blobFor')->with($this->session->identity, $this->alice, $stored)
			->willReturn(['blob' => new BlobRef(self::DID, $cid, $stored->getId(), 'image/jpeg', 34), 'width' => 10, 'height' => 10]);

		$answer = $this->writes->upload($this->session, $this->file('the bytes the app sent, with GPS'), 'image/jpeg');

		$this->assertSame(['$type' => 'blob', 'ref' => ['$link' => $cid->toString()], 'mimeType' => 'image/jpeg', 'size' => 34], $answer['blob']);
	}

	public function testAnUploadBlueskyCannotShowIsRefused(): void {
		$this->documents->method('storeLocalAttachment')->willReturn(new Document());
		$this->pictures->method('blobFor')->willReturn(null);

		$this->expectException(XrpcException::class);
		$this->expectExceptionMessage('cannot be shown on Bluesky');
		$this->writes->upload($this->session, $this->file('bytes'), 'application/pdf');
	}

	public function testAVideoUploadIsStoredAsItCame(): void {
		$stored = new Document();
		$stored->setId('https://social.test/documents/local/2');
		$stored->setMimeType('video/mp4');
		$this->documents->method('storeLocalAttachment')->willReturn($stored);
		$cid = Cid::forRaw('a video');
		$this->videos->expects($this->once())->method('blobFor')->with($this->session->identity, $stored)
			->willReturn(new BlobRef(self::DID, $cid, $stored->getId(), 'video/mp4', 7));
		$this->pictures->expects($this->never())->method('blobFor');

		$answer = $this->writes->upload($this->session, $this->file('a video'), 'video/mp4');

		$this->assertSame($cid->toString(), $answer['blob']['ref']['$link']);
		$this->assertSame('video/mp4', $answer['blob']['mimeType']);
	}

	public function testAPostWithAVideoIsASocialPostWithThatVideo(): void {
		$cid = Cid::forRaw('a video');
		$video = new Document();
		$video->setNid(9);
		$video->setId('https://social.test/documents/local/2');
		$video->setMimeType('video/mp4');
		$video->setMediaType('video/mp4');
		$this->blobs->method('get')->with(self::DID, $cid->toString())->willReturn(new BlobRef(self::DID, $cid, $video->getId(), 'video/mp4', 7));
		$this->documents->method('getDocumentById')->with($video->getId())->willReturn($video);
		$this->documents->expects($this->once())->method('updateDescription')->with($this->callback(static fn (Document $d): bool => $d->getDescription() === 'Waves'));
		$made = new Note();
		$made->setId('https://social.test/@alice/3');
		$this->posts->expects($this->once())->method('createPost')->with($this->callback(function (Post $post): bool {
			$this->assertCount(1, $post->getMedias());
			$this->assertSame('9', $post->getMedias()[0]->getId());

			return true;
		}))->willReturnCallback(function () use ($made): Create {
			$this->records[] = $this->record(RecordMapper::POST, '3kvid', $made->getId());
			$activity = new Create();
			$activity->setObjectId($made->getId());

			return $activity;
		});
		$this->streams->method('getStreamById')->willReturn($made);

		$answer = $this->writes->create($this->session, ['repo' => self::DID, 'collection' => RecordMapper::POST, 'record' => [
			'$type' => RecordMapper::POST, 'text' => 'At the beach', 'createdAt' => '2026-10-08T10:00:00.000Z',
			'embed' => ['$type' => 'app.bsky.embed.video', 'alt' => 'Waves', 'video' => ['$type' => 'blob', 'ref' => ['$link' => $cid->toString()], 'mimeType' => 'video/mp4', 'size' => 7]],
		]]);

		$this->assertSame('at://' . self::DID . '/app.bsky.feed.post/3kvid', $answer['uri']);
	}

	private function file(string $bytes): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'social-test-');
		file_put_contents($path, $bytes);
		register_shutdown_function(static fn () => @unlink($path));

		return $path;
	}

	public function testALikeOfABlueskyPostNotReadHereFetchesItFirst(): void {
		$bobPost = 'https://bsky.app/profile/' . self::BOB . '/post/3kbob';
		$known = false;
		$this->postStore->method('isKnown')->willReturnCallback(static function () use (&$known): bool {
			return $known;
		});
		$this->postStore->expects($this->once())->method('storeByUri')->with('at://' . self::BOB . '/app.bsky.feed.post/3kbob')->willReturnCallback(static function () use (&$known): bool {
			$known = true;

			return true;
		});
		$like = new Like();
		$like->setId('https://social.test/@alice#like/1');
		$this->likes->expects($this->once())->method('create')->with($this->alice, $bobPost)->willReturn($like);
		$this->streams->method('getStreamById')->willReturn(new Note());
		$this->records[] = $this->record(RecordMapper::LIKE, '3klike', $like->getId());

		$answer = $this->writes->create($this->session, ['repo' => 'alice.social.test', 'collection' => RecordMapper::LIKE, 'record' => ['subject' => ['uri' => 'at://' . self::BOB . '/app.bsky.feed.post/3kbob', 'cid' => 'x']]]);
		$this->assertSame('at://' . self::DID . '/app.bsky.feed.like/3klike', $answer['uri']);
	}

	public function testOnlyTheAccountsOwnRepositoryAndOnlyWhatSocialPublishes(): void {
		foreach ([
			[['repo' => self::BOB, 'collection' => RecordMapper::POST, 'record' => []], 'another repository'],
			[['repo' => self::DID, 'collection' => 'app.bsky.graph.block', 'record' => ['subject' => self::BOB]], 'Blocks stay on this server'],
			[['repo' => self::DID, 'collection' => 'app.bsky.graph.listblock', 'record' => []], 'does not write app.bsky.graph.listblock'],
			[['repo' => self::DID, 'collection' => RecordMapper::POST, 'record' => [], 'validate' => false], 'always validated'],
		] as [$body, $why]) {
			try {
				$this->writes->create($this->session, $body);
				$this->fail($why);
			} catch (XrpcException $e) {
				$this->assertStringContainsStringIgnoringCase(explode(' ', $why)[0] === 'another' ? 'own repository' : $why, $e->getMessage());
			}
		}
	}

	public function testDeletingAPostRecordDeletesTheSocialPost(): void {
		$post = new Note();
		$post->setId('https://social.test/@alice/1');
		$post->setAttributedTo($this->alice->getId());
		$this->records[] = $this->record(RecordMapper::POST, '3kmine', $post->getId());
		$this->streams->method('getStreamById')->with($post->getId())->willReturn($post);
		$this->streams->expects($this->once())->method('deleteLocalItem')->with($post);
		$this->publisher->expects($this->once())->method('deletePost')->with($post->getId());

		$this->assertSame(['commit' => ['cid' => 'bafyreicommit', 'rev' => '3kzrev']], $this->writes->delete($this->session, ['repo' => self::DID, 'collection' => RecordMapper::POST, 'rkey' => '3kmine']));
		$this->assertSame(['cid' => 'bafyreicommit', 'rev' => '3kzrev'], $this->writes->delete($this->session, ['repo' => self::DID, 'collection' => RecordMapper::POST, 'rkey' => '3kgone'])['commit'], 'already gone is done');
	}

	public function testLinksTheAppShortenedAreWholeAgainAndBrokenFacetsAreIgnored(): void {
		$this->assertSame('see https://a.example/x', WriteService::fullText('see a.example/x', [['index' => ['byteStart' => 4, 'byteEnd' => 15], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://a.example/x']]]]));
		$this->assertSame('see a.example/x', WriteService::fullText('see a.example/x', [['index' => ['byteStart' => 4, 'byteEnd' => 99], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://a.example/x']]]]));
		$this->assertSame('see x', WriteService::fullText('see x', [['index' => ['byteStart' => 4, 'byteEnd' => 5], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'javascript:alert(1)']]]]));
	}

	private function record(string $collection, string $rkey, string $localId): StoredRecord {
		$bytes = DagCbor::encode(['$type' => $collection]);

		return new StoredRecord(self::DID, $collection, $rkey, Cid::forDagCbor($bytes), $bytes, $localId, 0);
	}
}
