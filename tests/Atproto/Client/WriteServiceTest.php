<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Client;

use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Client\WriteService;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\RepoHead;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Publisher\InteractionPublisher;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\LikeService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\PostReviewService;
use OCA\Social\Service\PostService;
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
	private WriteService $writes;
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
		$this->writes = new WriteService(
			$accounts, $this->posts, $this->review, $this->createMock(ModerationService::class), $this->streams, $this->likes, $this->createMock(BoostService::class), $this->createMock(FollowService::class),
			$this->createMock(CacheActorService::class), $this->createMock(ReportService::class), $this->documents,
			$this->publisher, $this->pictures, $this->videos, $this->createMock(InteractionPublisher::class), $this->repositories, $this->local, $this->postStore,
			$this->blobs, $this->createMock(IURLGenerator::class), new NullLogger(),
		);
		$this->session = new ClientSession('alice', new Identity(1, $this->alice->getId(), self::DID, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0), 'jti');
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
			[['repo' => self::DID, 'collection' => 'app.bsky.graph.list', 'record' => []], 'does not write app.bsky.graph.list'],
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
