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
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\ImportedPostsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
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
	private array $parsed = [];
	private array $tied = [];

	private function record(string $rkey, array $value, string $localId = ''): StoredRecord {
		$bytes = DagCbor::encode(['$type' => RecordMapper::POST, 'createdAt' => '2025-03-04T05:06:07.000Z'] + $value);

		return new StoredRecord(self::DID, RecordMapper::POST, $rkey, Cid::forDagCbor($bytes), $bytes, $localId, 0);
	}

	private function history(): PostHistory {
		$repositories = $this->createMock(RepositoryService::class);
		$repositories->method('listRecords')->willReturnCallback(fn (string $did, string $collection): array => $collection === RecordMapper::POST ? $this->records : []);
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

		return new PostHistory($repositories, $repoRequest, $blobs, $pictures, $imports, $imported, $streams, $temp, new NullLogger());
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
		$this->assertCount(3, $this->parsed, 'an empty post is left out, and one that is a post here already');
		$this->assertSame(['3kaaa', '3kbbb', '3kccc'], array_keys($this->tied));
		$this->assertSame('https://social.test/@alice/' . md5($first['source']), $this->tied['3kaaa']);
	}
}
