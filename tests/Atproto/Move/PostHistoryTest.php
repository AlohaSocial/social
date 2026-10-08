<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Move\PostHistory;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\ImportedPostsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\LikeService;
use OCA\Social\Service\PostImportService;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PostHistoryTest extends TestCase {
	private const DID = 'did:plc:z72i7hdynmk6r22z27h6tvur';

	private string $picture = 'a picture, byte for byte';
	/** @var StoredRecord[] */
	private array $records = [];
	/** @var array<string, StoredRecord[]> the likes and reposts, by collection */
	private array $actionRecords = [];
	/** @var string[] the Bluesky posts known here */
	private array $known = [];
	private array $fetched = [];
	private array $recorded = [];
	private array $parsed = [];
	private array $tied = [];

	private function record(string $rkey, array $value, string $localId = ''): StoredRecord {
		$bytes = DagCbor::encode(['$type' => RecordMapper::POST, 'createdAt' => '2025-03-04T05:06:07.000Z'] + $value);

		return new StoredRecord(self::DID, RecordMapper::POST, $rkey, Cid::forDagCbor($bytes), $bytes, $localId, 0);
	}

	private function history(): PostHistory {
		$repositories = $this->createMock(RepositoryService::class);
		$repositories->method('listRecords')->willReturnCallback(fn (string $did, string $collection): array => $collection === RecordMapper::POST ? $this->records : ($this->actionRecords[$collection] ?? []));
		$repoRequest = $this->createMock(AtprotoRepoRequest::class);
		$repoRequest->method('setLocalId')->willReturnCallback(function (string $did, string $collection, string $rkey, string $localId): void {
			$this->tied[$rkey] = $localId;
		});
		$cid = Cid::forRaw($this->picture);
		$blobs = $this->createMock(AtprotoBlobRequest::class);
		$blobs->method('get')->willReturnCallback(static fn (string $did, string $c): ?BlobRef => $c === $cid->toString() ? new BlobRef(self::DID, $cid, 'doc', 'image/png', 24) : null);
		$pictures = $this->createMock(PictureService::class);
		$pictures->method('read')->willReturn($this->picture);
		$imports = $this->createMock(PostImportService::class);
		$imports->method('importParsed')->willReturnCallback(function (Person $actor, array $parsed): array {
			foreach ($parsed as $post) {
				foreach ($post['attachments'] as $attachment) {
					$this->assertSame($this->picture, file_get_contents($attachment['path']), 'the blob held here');
				}
			}
			$this->parsed = $parsed;

			return ['imported' => count($parsed), 'skipped' => 0, 'already' => 0, 'media' => 0, 'failed' => 0, 'failures' => [], 'total' => count($parsed), 'capped' => false];
		});
		$imported = $this->createMock(ImportedPostsRequest::class);
		$imported->method('knownAmong')->willReturnCallback(static fn (string $actor, array $sources): array => array_combine($sources, array_map(static fn (string $s): string => md5($s), $sources)));
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStream')->willReturnCallback(static function (string $prim): Stream {
			$note = new Note();
			$note->setId('https://social.test/@alice/' . $prim);

			return $note;
		});
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(static fn (): string => (string)tempnam(sys_get_temp_dir(), 'social-test-'));
		$local = $this->createMock(LocalRecordResolver::class);
		$local->method('postId')->willReturnCallback(static fn (string $uri): string => str_starts_with($uri, 'at://' . self::DID . '/')
			? 'https://social.test/@alice/' . md5($uri)
			: BlueskyIds::postIdOfUri($uri));
		$postStore = $this->createMock(PostStore::class);
		$postStore->method('isKnown')->willReturnCallback(fn (string $id): bool => in_array($id, $this->known, true));
		$postStore->method('storeByUri')->willReturnCallback(function (string $uri): bool {
			$this->fetched[] = $uri;
			$this->known[] = BlueskyIds::postIdOfUri($uri);

			return true;
		});
		$likes = $this->createMock(LikeService::class);
		$likes->method('recordWithoutSending')->willReturnCallback(function (Person $actor, string $postId, string $when): Like {
			$this->recorded[] = ['like', $postId, $when];
			$like = new Like();
			$like->setId('https://social.test/@alice#like/' . count($this->recorded));

			return $like;
		});
		$boosts = $this->createMock(BoostService::class);
		$boosts->method('recordWithoutSending')->willReturnCallback(function (Person $actor, string $postId, string $when): Announce {
			$this->recorded[] = ['boost', $postId, $when];
			$announce = new Announce();
			$announce->setId('https://social.test/@alice/boost/' . count($this->recorded));

			return $announce;
		});

		return new PostHistory($repositories, $repoRequest, $blobs, $pictures, $imports, $imported, $streams, $temp, $local, $postStore, $likes, $boosts, new NullLogger());
	}

	public function testTheMovedPostsBecomePostsHereTiedToTheirRecords(): void {
		$picture = ['$type' => 'blob', 'ref' => Cid::forRaw($this->picture), 'mimeType' => 'image/png', 'size' => 24];
		$this->records = [
			$this->record('3kaaa', [
				'text' => 'Look example.com/a… #sunset',
				'facets' => [
					['index' => ['byteStart' => 5, 'byteEnd' => 21], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com/a/long/path']]],
					['index' => ['byteStart' => 22, 'byteEnd' => 29], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'sunset']]],
				],
				'langs' => ['en'],
				'embed' => ['$type' => 'app.bsky.embed.images', 'images' => [['image' => $picture, 'alt' => 'The sea']]],
				'labels' => ['$type' => 'com.atproto.label.defs#selfLabels', 'values' => [['val' => 'graphic-media']]],
			]),
			$this->record('3kbbb', ['text' => 'And a reply to myself', 'reply' => ['root' => ['uri' => 'at://' . self::DID . '/app.bsky.feed.post/3kaaa', 'cid' => 'x'], 'parent' => ['uri' => 'at://' . self::DID . '/app.bsky.feed.post/3kaaa', 'cid' => 'x']]]),
			$this->record('3kccc', ['text' => 'A reply to somebody', 'reply' => ['root' => ['uri' => 'at://did:plc:other/app.bsky.feed.post/1', 'cid' => 'x'], 'parent' => ['uri' => 'at://did:plc:other/app.bsky.feed.post/1', 'cid' => 'x']]]),
			$this->record('3kddd', ['text' => '   ']),
			$this->record('3keee', ['text' => 'Already a post here'], 'https://social.test/@alice/9'),
		];

		$this->assertSame(3, $this->history()->import(new Person(), self::DID));

		[$first, $reply, $other] = $this->parsed;
		$this->assertSame('at://' . self::DID . '/app.bsky.feed.post/3kaaa', $first['source']);
		$this->assertSame('Look https://example.com/a/long/path #sunset', $first['text'], 'the whole link again');
		$this->assertSame(['sunset'], $first['hashtags']);
		$this->assertSame([Stream::TYPE_PUBLIC, true, 'en', strtotime('2025-03-04T05:06:07Z')], [$first['visibility'], $first['sensitive'], $first['language'], $first['published']]);
		$this->assertSame('The sea', $first['attachments'][0]['name']);
		$this->assertFileDoesNotExist($first['attachments'][0]['path'], 'the temporary file is gone');
		$this->assertSame($first['source'], $reply['replyTo'], 'a reply to the account\'s own post hangs off it');
		$this->assertSame('', $other['replyTo']);
		$this->assertSame('https://bsky.app/profile/did:plc:other/post/1', $other['replyToId'], 'a reply to somebody else hangs off their post, fetched');
		$this->assertSame(['at://did:plc:other/app.bsky.feed.post/1'], $this->fetched);
		$this->assertCount(3, $this->parsed, 'an empty post is left out, and one that is a post here already');
		$this->assertSame(['3kaaa', '3kbbb', '3kccc'], array_keys($this->tied));
		$this->assertSame('https://social.test/@alice/' . md5($first['source']), $this->tied['3kaaa']);
	}

	public function testTheLatestLikesAndRepostsBecomeLikesAndBoostsTiedToTheirRecords(): void {
		$action = function (string $collection, string $rkey, string $subject, string $localId = ''): StoredRecord {
			$bytes = DagCbor::encode(['$type' => $collection, 'subject' => ['uri' => $subject, 'cid' => 'x'], 'createdAt' => '2025-06-07T08:09:10.000Z']);

			return new StoredRecord(self::DID, $collection, $rkey, Cid::forDagCbor($bytes), $bytes, $localId, 0);
		};
		$this->known = [BlueskyIds::postIdOfUri('at://did:plc:bob/app.bsky.feed.post/2')];
		$this->actionRecords = [
			RecordMapper::LIKE => [
				$action(RecordMapper::LIKE, '3klike1', 'at://did:plc:bob/app.bsky.feed.post/2'),
				$action(RecordMapper::LIKE, '3klike2', 'at://' . self::DID . '/app.bsky.feed.post/3kaaa'),
				$action(RecordMapper::LIKE, '3klike3', 'at://did:plc:bob/app.bsky.feed.post/9', 'https://social.test/@alice#like/old'),
			],
			RecordMapper::REPOST => [$action(RecordMapper::REPOST, '3krepost', 'at://did:plc:carol/app.bsky.feed.post/5')],
		];

		$this->assertSame(['likes' => 2, 'reposts' => 1], $this->history()->importActions(new Person(), self::DID));

		$this->assertSame([
			['like', 'https://bsky.app/profile/did:plc:bob/post/2', '2025-06-07T08:09:10.000Z'],
			['like', 'https://social.test/@alice/' . md5('at://' . self::DID . '/app.bsky.feed.post/3kaaa'), '2025-06-07T08:09:10.000Z'],
			['boost', 'https://bsky.app/profile/did:plc:carol/post/5', '2025-06-07T08:09:10.000Z'],
		], $this->recorded, 'dated when they were made; one taken over already is left');
		$this->assertSame(['at://did:plc:carol/app.bsky.feed.post/5'], $this->fetched, 'a post not here is fetched, a known one not');
		$this->assertSame(['3klike1' => 'https://social.test/@alice#like/1', '3klike2' => 'https://social.test/@alice#like/2', '3krepost' => 'https://social.test/@alice/boost/3'], $this->tied);
	}
}
