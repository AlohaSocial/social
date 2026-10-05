<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ImportedPostsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\LinkifyService;
use OCA\Social\Service\PostImportService;
use OCA\Social\Service\StreamService;
use OCP\ITempManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

#[AllowMockObjectsWithoutExpectations]
class PostImportServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';

	private ImportedPostsRequest|Stub $importedPostsRequest;
	private \OCA\Social\Service\CurlService|Stub $curlService;
	private \OCA\Social\Service\ConfigService|Stub $configService;
	private StreamRequest|Stub $streamRequest;
	private DocumentService|MockObject $documentService;
	private CacheDocumentService|MockObject $cacheDocumentService;
	private AccountService|Stub $accountService;
	private PostImportService $service;

	/** @var Note[] what the run wrote */
	private array $written = [];
	/** @var array<string, string> what it remembered */
	private array $remembered = [];
	/** @var string[] the temporary files it made */
	private array $temps = [];

	protected function setUp(): void {
		parent::setUp();

		$this->importedPostsRequest = $this->createStub(ImportedPostsRequest::class);
		$this->importedPostsRequest->method('knownAmong')->willReturn([]);
		$this->importedPostsRequest->method('remember')
			->willReturnCallback(function (string $actor, string $source, string $stream): void {
				$this->remembered[$source] = $stream;
			});

		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->streamRequest->method('save')
			->willReturnCallback(function (Stream $stream): void {
				$this->written[] = $stream;
			});
		$this->streamRequest->method('getStream')
			->willReturnCallback(function (string $prim): Stream {
				foreach ($this->written as $stream) {
					if (md5($stream->getId()) === $prim) {
						return $stream;
					}
				}

				throw new \OCA\Social\Exceptions\StreamNotFoundException();
			});

		// the real one mints an id and sets the addressing; what matters here
		// is that the importer asks for it and then overrides the date
		$streamService = $this->createStub(StreamService::class);
		$streamService->method('assignItem')
			->willReturnCallback(static function (Stream $stream): void {
				$stream->setId(self::ALICE . '/' . bin2hex(random_bytes(6)));
				$stream->setLocal(true);
				$stream->setPublished(date('c'));
			});
		$streamService->method('addHashtags')
			->willReturnCallback(static function (Note $note, array $tags): void {
				$note->setHashtags($tags);
			});

		$this->documentService = $this->createMock(DocumentService::class);
		$this->documentService->method('storeLocalAttachment')
			->willReturnCallback(static function (Person $actor, string $path, string $parent, string $description): Document {
				$document = new Document();
				$document->setId('https://cloud.example/documents/' . md5($path . $description));
				$document->setDescription($description);
				$document->setMediaType('image/jpeg');

				return $document;
			});

		$this->cacheDocumentService = $this->createMock(CacheDocumentService::class);

		$linkify = $this->createStub(LinkifyService::class);
		$linkify->method('toHtml')->willReturnCallback(static fn (string $text): string => '<p>' . $text . '</p>');

		$tempManager = $this->createStub(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(function (): string {
			$path = tempnam(sys_get_temp_dir(), 'import');
			$this->temps[] = $path;

			return $path;
		});

		// an Instagram post carries no audience, so the importer asks the
		// account what its own posts get
		$this->accountService = $this->createStub(AccountService::class);

		$this->curlService = $this->createStub(\OCA\Social\Service\CurlService::class);
		$this->configService = $this->createStub(\OCA\Social\Service\ConfigService::class);
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example/');

		$this->service = new PostImportService(
			$this->importedPostsRequest,
			$this->streamRequest,
			$streamService,
			$this->documentService,
			$this->cacheDocumentService,
			$linkify,
			$this->accountService,
			$tempManager,
			$this->createStub(IURLGenerator::class),
			$this->curlService,
			new \OCA\Social\Service\PeerTubeService(
				$this->createStub(\OCA\Social\Interfaces\Object\DocumentInterface::class),
				$this->createStub(IURLGenerator::class),
				new NullLogger(),
			),
			$this->configService,
			new NullLogger(),
		);
	}

	protected function tearDown(): void {
		foreach ($this->temps as $temp) {
			@unlink($temp);
		}
		parent::tearDown();
	}

	private function alice(): Person {
		$actor = new Person();
		$actor->setId(self::ALICE)
			->setPreferredUsername('alice')
			->setAccount('alice@cloud.example');
		$actor->setFollowers(self::ALICE . '/followers');

		return $actor;
	}

	/** @param array<int, mixed> $items */
	private function outbox(array $items): string {
		$path = tempnam(sys_get_temp_dir(), 'outbox') . '.json';
		file_put_contents($path, json_encode([
			'type' => 'OrderedCollection',
			'totalItems' => count($items),
			'orderedItems' => $items,
		]));
		$this->temps[] = $path;

		return $path;
	}

	/** @param array<string, mixed> $values */
	private function note(string $id, array $values = []): array {
		return array_merge([
			'id' => $id,
			'type' => 'Note',
			'published' => '2024-03-17T09:00:00Z',
			'content' => '<p>Hello <b>world</b></p>',
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'cc' => [self::ALICE . '/followers'],
		], $values);
	}

	public function testItWritesEachPostAsALocalPostDatedWhenItWasWritten(): void {
		$path = $this->outbox([
			$this->note('https://old.example/users/alice/statuses/1'),
			$this->note('https://old.example/users/alice/statuses/2', [
				'published' => '2025-01-02T10:30:00Z',
				'content' => '<p>Second</p>',
			]),
		]);

		$tally = $this->service->import($this->alice(), $path);

		$this->assertSame(2, $tally['imported']);
		$this->assertCount(2, $this->written);
		// oldest first, so that a reply is written after what it answers
		$this->assertSame(strtotime('2024-03-17T09:00:00Z'), $this->written[0]->getPublishedTime());
		$this->assertSame(strtotime('2025-01-02T10:30:00Z'), $this->written[1]->getPublishedTime());
		// and as this account's own posts, with ids of this server's minting
		$this->assertTrue($this->written[0]->isLocal());
		$this->assertStringStartsWith(self::ALICE, $this->written[0]->getId());
		$this->assertSame(self::ALICE, $this->written[0]->getAttributedTo());
	}

	/** The whole point of not federating: nothing is queued, ever. */
	public function testNotOneDeliveryIsQueued(): void {
		$path = $this->outbox([$this->note('https://old.example/1')]);

		$this->service->import($this->alice(), $path);

		// the importer writes through the row, not through the delivery path;
		// `save()` is the only write it makes
		$this->assertCount(1, $this->written);
		$this->assertSame(['https://old.example/1'], array_keys($this->remembered));
	}

	public function testTheMarkupBecomesTheTextAPostIsStoredAs(): void {
		$path = $this->outbox([$this->note('https://old.example/1', [
			'content' => '<p>First line<br />second line</p><p>New paragraph &amp; an entity</p>',
		])]);

		$this->service->import($this->alice(), $path);

		$this->assertSame(
			"<p>First line\nsecond line\n\nNew paragraph & an entity</p>",
			$this->written[0]->getContent()
		);
	}

	public function testABoostAndADirectMessageAreNotPostsToBringOver(): void {
		$path = $this->outbox([
			['type' => 'Announce', 'id' => 'https://old.example/1', 'object' => 'https://elsewhere/2'],
			$this->note('https://old.example/3', ['to' => ['https://old.example/users/bob'], 'cc' => []]),
			$this->note('https://old.example/4'),
		]);

		$tally = $this->service->import($this->alice(), $path);

		$this->assertSame(1, $tally['imported']);
		$this->assertSame(2, $tally['skipped']);
		$this->assertSame(['https://old.example/4'], array_keys($this->remembered));
	}

	public function testTheAudienceIsReadOffTheAddressing(): void {
		$path = $this->outbox([
			$this->note('https://old.example/1'),
			$this->note('https://old.example/2', [
				'to' => [self::ALICE . '/followers'],
				'cc' => ['https://www.w3.org/ns/activitystreams#Public'],
			]),
			$this->note('https://old.example/3', ['to' => [self::ALICE . '/followers'], 'cc' => []]),
		]);

		$this->service->import($this->alice(), $path);

		$this->assertSame(
			[Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED, Stream::TYPE_FOLLOWERS],
			array_map(static fn (Stream $post): string => $post->getVisibility(), $this->written)
		);
	}

	/** A Create wrapping a Note is an outbox; a bare Note is this app's own export. */
	public function testItReadsAnOutboxOfActivitiesAsWellAsOneOfObjects(): void {
		$path = $this->outbox([
			['type' => 'Create', 'id' => 'https://old.example/1/activity', 'object' => $this->note('https://old.example/1')],
			$this->note('https://old.example/2'),
		]);

		$this->assertSame(2, $this->service->import($this->alice(), $path)['imported']);
	}

	public function testAReplyKeepsItsParentWhereTheExportHoldsBoth(): void {
		$path = $this->outbox([
			$this->note('https://old.example/2', [
				'published' => '2024-03-18T09:00:00Z',
				'inReplyTo' => 'https://old.example/1',
				'content' => '<p>and another thing</p>',
			]),
			$this->note('https://old.example/1'),
			$this->note('https://old.example/3', [
				'published' => '2024-03-19T09:00:00Z',
				// a reply to a post that is not in the archive: a fragment of
				// somebody else's thread, kept as a post of its own
				'inReplyTo' => 'https://elsewhere.example/9',
			]),
		]);

		$this->service->import($this->alice(), $path);

		$this->assertSame('', $this->written[0]->getInReplyTo());
		$this->assertSame($this->written[0]->getId(), $this->written[1]->getInReplyTo());
		$this->assertSame('', $this->written[2]->getInReplyTo());
	}

	public function testWhatWasBroughtOverAlreadyIsNotBroughtAgain(): void {
		$service = $this->service;
		$this->importedPostsRequest = $this->createStub(ImportedPostsRequest::class);

		$path = $this->outbox([$this->note('https://old.example/1'), $this->note('https://old.example/2')]);
		$service->import($this->alice(), $path);
		$this->assertCount(2, $this->written);

		// a second run, with the first run's rows now on record
		$again = $this->serviceKnowing(['https://old.example/1' => md5('local/1'), 'https://old.example/2' => md5('local/2')]);
		$tally = $again->import($this->alice(), $path);

		$this->assertSame(0, $tally['imported']);
		$this->assertSame(2, $tally['already']);
	}

	public function testAPictureInTheArchiveIsStoredThroughTheOrdinaryUploadPath(): void {
		$zipPath = tempnam(sys_get_temp_dir(), 'archive') . '.zip';
		$this->temps[] = $zipPath;
		$zip = new ZipArchive();
		$zip->open($zipPath, ZipArchive::CREATE);
		$zip->addFromString('social/outbox.json', json_encode(['orderedItems' => [
			$this->note('https://old.example/1', [
				'attachment' => [[
					'type' => 'Document',
					'mediaType' => 'image/jpeg',
					'url' => 'media_attachments/files/1/original.jpeg',
					'name' => 'a pier at low tide',
				]],
			]),
		]]));
		$zip->addFromString('social/media_attachments/files/1/original.jpeg', 'not really a jpeg');
		$zip->close();

		$this->documentService->expects($this->once())
			->method('storeLocalAttachment')
			->with(
				$this->anything(),
				$this->callback(static fn (string $path): bool => file_get_contents($path) === 'not really a jpeg'),
				$this->anything(),
				'a pier at low tide',
				true
			)
			->willReturn((new Document())->setId('https://cloud.example/documents/1'));

		$tally = $this->service->import($this->alice(), $zipPath);

		$this->assertSame(1, $tally['imported']);
		$this->assertSame(1, $tally['media']);
		$this->assertCount(1, $this->written[0]->getAttachments());
	}

	/**
	 * Pixelfed's export names its pictures by address and ships none of them,
	 * so they are fetched — and only when the person asked for that.
	 */
	public function testAPictureNamedOnlyByItsAddressIsFetchedOnlyWhenAsked(): void {
		$path = tempnam(sys_get_temp_dir(), 'pixelfed') . '.json';
		$this->temps[] = $path;
		file_put_contents($path, json_encode([[
			'id' => '712000000000000031',
			'url' => 'https://pixelfed.social/p/alice/712000000000000031',
			'created_at' => '2025-06-01T12:00:00Z',
			'content' => '<p>a pier</p>',
			'visibility' => 'public',
			'media_attachments' => [['url' => 'https://pixelfed.social/storage/m/one.jpg', 'description' => 'a pier']],
		]]));

		$this->cacheDocumentService->expects($this->once())
			->method('retrieveContent')
			->with('https://pixelfed.social/storage/m/one.jpg')
			->willReturn('the bytes');

		$tally = $this->service->import($this->alice(), $path, true);
		$this->assertSame(1, $tally['media']);

		// and with the fetch declined, the post is still written — without it
		$quiet = $this->serviceKnowing([]);
		$this->written = [];
		$tally = $quiet->import($this->alice(), $path, false);
		$this->assertSame(1, $tally['imported']);
		$this->assertSame(0, $tally['media']);
		$this->assertSame([], $this->written[0]->getAttachments());
		// the move-in report told the person "10 failed" for the ten pictures
		// they had asked not to fetch
		$this->assertSame(0, $tally['failed'], 'a picture nobody asked for is not a failure');
	}

	public function testAPictureThatWasAskedForAndCouldNotBeHadIsAFailure(): void {
		$path = tempnam(sys_get_temp_dir(), 'pixelfed') . '.json';
		$this->temps[] = $path;
		file_put_contents($path, json_encode([[
			'id' => '712000000000000032',
			'url' => 'https://pixelfed.social/p/alice/712000000000000032',
			'created_at' => '2025-06-01T12:00:00Z',
			'content' => '<p>a pier</p>',
			'visibility' => 'public',
			'media_attachments' => [['url' => 'https://pixelfed.social/storage/m/gone.jpg', 'description' => 'a pier']],
		]]));
		$this->cacheDocumentService->method('retrieveContent')->willThrowException(new \RuntimeException('404'));

		$tally = $this->service->import($this->alice(), $path, true);

		$this->assertSame(1, $tally['imported'], 'the post is still written, without its picture');
		$this->assertSame(1, $tally['failed']);
	}

	/** A number says something went wrong; the post and the reason say what to do about it. */
	public function testAFailureIsKeptByThePostItConcernsWithItsReason(): void {
		$path = tempnam(sys_get_temp_dir(), 'pixelfed') . '.json';
		$this->temps[] = $path;
		file_put_contents($path, json_encode([[
			'id' => '712000000000000033',
			'url' => 'https://pixelfed.social/p/alice/712000000000000033',
			'created_at' => '2025-06-01T12:00:00Z',
			'content' => '<p>a pier</p>',
			'visibility' => 'public',
			'media_attachments' => [['url' => 'https://pixelfed.social/storage/m/full.jpg', 'description' => 'a pier']],
		]]));
		$this->cacheDocumentService->method('retrieveContent')->willReturn('the bytes');
		$this->documentService->method('storeLocalAttachment')->willThrowException(new \RuntimeException('disk full'));

		$tally = $this->service->import($this->alice(), $path, true);

		$this->assertSame(1, $tally['failed']);
		// named by the export's own id of the post, which for a Pixelfed file is the id
		$this->assertSame(['712000000000000033' => 'disk full'], $tally['failures']);
	}

	public function testAFileThatIsNotAnExportSaysSo(): void {
		$path = tempnam(sys_get_temp_dir(), 'junk');
		$this->temps[] = $path;
		file_put_contents($path, 'not json at all');

		$this->expectException(InvalidResourceException::class);
		$this->service->import($this->alice(), $path);
	}

	public function testTheRunStopsAtItsCeilingAndSaysSo(): void {
		$items = [];
		for ($index = 1; $index <= 5; $index++) {
			$items[] = $this->note('https://old.example/' . $index);
		}
		$path = $this->outbox($items);

		$tally = $this->service->import($this->alice(), $path, true, 3);

		$this->assertSame(3, $tally['imported']);
		$this->assertTrue($tally['capped']);
	}

	// PeerTube

	/**
	 * One entry of `peertube/videos.json`, in the shape their exporter writes.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private function peerTubeVideo(array $values = []): array {
		return array_merge([
			'uuid' => '0f0e0d0c-0b0a-0908-0706-050403020100',
			'url' => 'https://tube.example/videos/watch/0f0e0d0c-0b0a-0908-0706-050403020100',
			'name' => 'A cat and a glass',
			'description' => 'It goes exactly how you think.',
			'publishedAt' => '2025-06-01T12:00:00.000Z',
			'privacy' => 1,
			'duration' => 113,
			'nsfw' => false,
			'isLive' => false,
			'tags' => ['cats', 'physics'],
			'category' => ['id' => 15, 'label' => 'Science & Technology'],
			'licence' => ['id' => 1, 'label' => 'Attribution'],
			'language' => ['id' => 'en', 'label' => 'English'],
			'archiveFiles' => [
				'videoFile' => '../files/videos/video-files/0f0e0d0c-0b0a-0908-0706-050403020100.mp4',
				'thumbnail' => '../files/videos/thumbnails/0f0e0d0c-0b0a-0908-0706-050403020100.jpg',
				'captions' => [],
			],
		], $values);
	}

	/** @param array<int, array<string, mixed>> $videos */
	private function peerTubeExport(array $videos, bool $withFiles = true): string {
		$files = [
			'peertube/videos.json' => (string)json_encode(['videos' => $videos]),
			// the archive really does carry both halves; the reader has to
			// prefer the one that names the copy inside it
			'activity-pub/outbox.json' => (string)json_encode(['orderedItems' => []]),
		];

		if ($withFiles) {
			foreach ($videos as $video) {
				$files['files/videos/video-files/' . $video['uuid'] . '.mp4'] = 'not really a video';
			}
		}

		return $this->archive($files);
	}

	public function testAPeerTubeExportBringsTheVideoOverWithItsTitleAndTags(): void {
		$path = $this->peerTubeExport([$this->peerTubeVideo()]);

		$tally = $this->service->import($this->alice(), $path);

		$this->assertSame(1, $tally['imported']);
		$note = $this->written[0];
		$this->assertStringContainsString('A cat and a glass', $note->getContent());
		$this->assertStringContainsString('It goes exactly how you think.', $note->getContent());
		$this->assertSame(['cats', 'physics'], $note->getHashtags());
		$this->assertSame(strtotime('2025-06-01T12:00:00Z'), $note->getPublishedTime());
	}

	/**
	 * Most of what a video *is* — and what this app reads back out when it
	 * publishes one, so an imported video leaves here as the same `Video` it
	 * arrived as rather than as a post with a rectangle in it.
	 */
	public function testTheTitleRunningTimeCategoryAndLicenceAreKept(): void {
		$this->service->import($this->alice(), $this->peerTubeExport([$this->peerTubeVideo()]));

		$meta = $this->written[0]->getVideoMeta();
		$this->assertSame('A cat and a glass', $meta['title']);
		$this->assertSame(113, $meta['duration']);
		$this->assertSame('Science & Technology', $meta['category']);
		$this->assertSame('Attribution', $meta['licence']);
	}

	/**
	 * The archive's own copy, not the address on the old server: an export
	 * carries the file precisely so the import does not need that server to
	 * still be running.
	 */
	public function testTheFileComesOutOfTheArchiveRatherThanOffTheOldServer(): void {
		$this->documentService->expects($this->once())
			->method('storeLocalAttachment')
			->with(
				$this->anything(),
				$this->callback(static fn (string $p): bool => file_get_contents($p) === 'not really a video'),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn(new Document());
		$this->cacheDocumentService->expects($this->never())->method('retrieveContent');

		$this->service->import($this->alice(), $this->peerTubeExport([$this->peerTubeVideo()]), false);
	}

	/**
	 * Each is a video its author decided not to publish, and there is no
	 * audience here that means "the people who had the password".
	 */
	#[DataProvider('providePrivacies')]
	public function testAVideoItsAuthorDidNotPublishIsNotBroughtOver(int $privacy): void {
		$tally = $this->service->import(
			$this->alice(), $this->peerTubeExport([$this->peerTubeVideo(['privacy' => $privacy])])
		);

		$this->assertSame(0, $tally['imported']);
		$this->assertSame(1, $tally['skipped']);
	}

	/** @return array<string, array{int}> */
	public static function providePrivacies(): array {
		return [
			'private' => [3],
			'internal' => [4],
			'password protected' => [5],
		];
	}

	public function testAnUnlistedVideoStaysUnlisted(): void {
		$this->service->import(
			$this->alice(), $this->peerTubeExport([$this->peerTubeVideo(['privacy' => 2])])
		);

		$this->assertSame(Stream::TYPE_UNLISTED, $this->written[0]->getVisibility());
	}

	/** There is no recording to bring over; a saved replay is a video of its own. */
	public function testALiveIsNotBroughtOver(): void {
		$tally = $this->service->import(
			$this->alice(), $this->peerTubeExport([$this->peerTubeVideo(['isLive' => true])])
		);

		$this->assertSame(0, $tally['imported']);
	}

	/**
	 * Numbers about the old instance's readers. A post here that arrived with
	 * four thousand views would be claiming four thousand people had watched
	 * it on this server.
	 */
	public function testTheOldInstancesCountersAreNotBroughtOver(): void {
		$this->service->import($this->alice(), $this->peerTubeExport([
			$this->peerTubeVideo(['views' => 4000, 'likes' => 300, 'dislikes' => 2]),
		]));

		$meta = $this->written[0]->getVideoMeta();
		$this->assertArrayNotHasKey('views', $meta);
		$this->assertArrayNotHasKey('likes', $meta);
		$this->assertArrayNotHasKey('dislikes', $meta);
	}

	/**
	 * Without its video files the JSON is a catalogue: every entry names a
	 * file that is not there, and the run would report "nothing imported"
	 * about an archive that is perfectly valid and simply not the one to ask
	 * for.
	 */
	public function testAnExportTakenWithoutTheVideoFilesIsRefusedByName(): void {
		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/without its video files/');

		$this->service->import(
			$this->alice(), $this->peerTubeExport([$this->peerTubeVideo()], withFiles: false)
		);
	}

	// PeerTube, one video by its address

	/** @param array<string, mixed> $values */
	private function videoObject(array $values = []): array {
		return array_merge([
			'id' => 'https://tube.example/videos/watch/abc',
			'type' => 'Video',
			'name' => 'A cat and a glass',
			'content' => '<p>It goes exactly how you think.</p>',
			'published' => '2025-06-01T12:00:00Z',
			'duration' => 'PT113S',
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'url' => [
				['type' => 'Link', 'mediaType' => 'text/html', 'href' => 'https://tube.example/w/abc'],
				['type' => 'Link', 'mediaType' => 'video/mp4', 'href' => 'https://tube.example/small.mp4', 'height' => 360],
				['type' => 'Link', 'mediaType' => 'video/mp4', 'href' => 'https://tube.example/big.mp4', 'height' => 1080],
			],
		], $values);
	}

	public function testOneVideoIsBroughtOverByItsAddress(): void {
		$this->curlService->method('retrieveObject')->willReturn($this->videoObject());
		$this->cacheDocumentService->method('retrieveContent')->willReturn('not really a video');

		$tally = $this->service->importVideo(
			$this->alice(), 'https://tube.example/videos/watch/abc'
		);

		$this->assertSame(1, $tally['imported']);
		$this->assertStringContainsString('A cat and a glass', $this->written[0]->getContent());
		$this->assertSame(
			'https://tube.example/videos/watch/abc',
			array_key_first($this->remembered),
			'the original id is remembered, so a second attempt is a no-op'
		);
	}

	/** The best one, because a 360p copy of a 1080p video is not the import anybody wanted. */
	public function testTheTallestFileIsTheOneStored(): void {
		$this->curlService->method('retrieveObject')->willReturn($this->videoObject());
		$this->cacheDocumentService->expects($this->once())->method('retrieveContent')
			->with('https://tube.example/big.mp4')->willReturn('not really a video');

		$this->service->importVideo($this->alice(), 'https://tube.example/videos/watch/abc');
	}

	/**
	 * A watch page is not the object's own id, so the document is trusted when
	 * it names the address it came from — the same evidence
	 * `SearchService::resolveStatus()` requires, one level in.
	 */
	public function testAWatchPageAddressIsAcceptedWhenTheVideoClaimsIt(): void {
		$this->curlService->method('retrieveObject')->willReturn($this->videoObject());
		$this->cacheDocumentService->method('retrieveContent')->willReturn('not really a video');

		$this->assertSame(
			1, $this->service->importVideo($this->alice(), 'https://tube.example/w/abc')['imported']
		);
	}

	/** So a redirect cannot substitute one video for another. */
	public function testAnAddressTheDocumentDoesNotClaimIsRefused(): void {
		$this->curlService->method('retrieveObject')->willReturn($this->videoObject());

		$this->expectException(InvalidResourceException::class);
		$this->service->importVideo($this->alice(), 'https://tube.example/w/somethingelse');
	}

	/** Bringing a neighbour's post over as your own is not an import. */
	public function testAVideoAlreadyOnThisServerIsRefused(): void {
		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/already on this server/');

		$this->service->importVideo($this->alice(), 'https://cloud.example/apps/social/@bob/7');
	}

	public function testSomethingThatIsNotAVideoIsRefused(): void {
		$this->curlService->method('retrieveObject')
			->willReturn($this->note('https://old.example/users/bob/statuses/1'));

		$this->expectException(InvalidResourceException::class);
		$this->service->importVideo($this->alice(), 'https://old.example/users/bob/statuses/1');
	}

	/**
	 * A playlist is a list of a few hundred segments on somebody else's
	 * server; storing it and calling it a video is not an import.
	 */
	public function testAVideoThatOffersOnlyAPlaylistIsRefused(): void {
		$this->curlService->method('retrieveObject')->willReturn($this->videoObject([
			'url' => [
				['type' => 'Link', 'mediaType' => 'text/html', 'href' => 'https://tube.example/videos/watch/abc'],
				['type' => 'Link', 'mediaType' => 'application/x-mpegURL', 'href' => 'https://tube.example/master.m3u8'],
			],
		]));

		$this->expectException(InvalidResourceException::class);
		$this->service->importVideo($this->alice(), 'https://tube.example/videos/watch/abc');
	}

	public function testAVideoBroughtOverTwiceIsBroughtOverOnce(): void {
		$this->curlService->method('retrieveObject')->willReturn($this->videoObject());
		$this->cacheDocumentService->method('retrieveContent')->willReturn('not really a video');

		$this->importedPostsRequest = $this->createStub(ImportedPostsRequest::class);
		$this->importedPostsRequest->method('knownAmong')
			->willReturn(['https://tube.example/videos/watch/abc' => 'someprim']);

		$service = new PostImportService(
			$this->importedPostsRequest,
			$this->streamRequest,
			$this->createStub(StreamService::class),
			$this->documentService,
			$this->cacheDocumentService,
			$this->createStub(LinkifyService::class),
			$this->accountService,
			$this->createStub(ITempManager::class),
			$this->createStub(IURLGenerator::class),
			$this->curlService,
			new \OCA\Social\Service\PeerTubeService(
				$this->createStub(\OCA\Social\Interfaces\Object\DocumentInterface::class),
				$this->createStub(IURLGenerator::class),
				new NullLogger(),
			),
			$this->configService,
			new NullLogger(),
		);

		$tally = $service->importVideo($this->alice(), 'https://tube.example/videos/watch/abc');

		$this->assertSame(0, $tally['imported']);
		$this->assertSame(1, $tally['already']);
	}

	// Instagram

	/**
	 * Instagram's own layout: the JSON under `your_instagram_activity/content/`
	 * and the pictures under `media/`, named from the archive root.
	 *
	 * @param array<string, string> $files path => contents
	 */
	private function archive(array $files): string {
		$path = tempnam(sys_get_temp_dir(), 'instagram') . '.zip';
		$this->temps[] = $path;
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE);
		foreach ($files as $name => $contents) {
			$zip->addFromString($name, $contents);
		}
		$zip->close();

		return $path;
	}

	/**
	 * One post of an Instagram archive.
	 *
	 * @param array<int, array<string, mixed>> $media
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private function instagramPost(array $media, array $values = []): array {
		return array_merge(['media' => $media, 'creation_timestamp' => 1719662400], $values);
	}

	public function testAnInstagramArchiveIsReadAndItsPicturesComeWithIt(): void {
		$path = $this->archive([
			'your_instagram_activity/content/posts_1.json' => json_encode([
				$this->instagramPost(
					[['uri' => 'media/posts/202406/pier.jpg', 'creation_timestamp' => 1719662400]],
					['title' => 'a pier at low tide']
				),
			]),
			'media/posts/202406/pier.jpg' => 'not really a jpeg',
		]);

		$tally = $this->service->import($this->alice(), $path);

		$this->assertSame(1, $tally['imported']);
		$this->assertSame(1, $tally['media']);
		$this->assertSame('<p>a pier at low tide</p>', $this->written[0]->getContent());
		$this->assertSame(1719662400, $this->written[0]->getPublishedTime());
	}

	/**
	 * The export splits at a size nobody can predict, so an archive of ten
	 * years has a dozen of these and every one of them holds posts.
	 */
	public function testEveryNumberedPostsFileIsRead(): void {
		$path = $this->archive([
			'your_instagram_activity/content/posts_1.json' => json_encode([
				$this->instagramPost([['uri' => 'media/a.jpg']], ['title' => 'first']),
			]),
			'your_instagram_activity/content/posts_2.json' => json_encode([
				$this->instagramPost([['uri' => 'media/b.jpg']], ['title' => 'second']),
			]),
			'media/a.jpg' => 'a',
			'media/b.jpg' => 'b',
		]);

		$this->assertSame(2, $this->service->import($this->alice(), $path)['imported']);
	}

	/** An archive taken before Instagram moved the folder still imports. */
	public function testTheOlderLayoutIsReadToo(): void {
		$path = $this->archive([
			'content/posts_1.json' => json_encode([
				$this->instagramPost([['uri' => 'media/old.jpg']], ['title' => 'from before']),
			]),
			'media/old.jpg' => 'a',
		]);

		$this->assertSame(1, $this->service->import($this->alice(), $path)['imported']);
	}

	public function testReelsAreReadFromTheKeyTheyAreWrappedIn(): void {
		$path = $this->archive([
			'your_instagram_activity/content/reels.json' => json_encode([
				'ig_reels_media' => [
					$this->instagramPost([['uri' => 'media/reel.mp4']], ['title' => 'a reel']),
				],
			]),
			'media/reel.mp4' => 'not really a video',
		]);

		$this->assertSame(1, $this->service->import($this->alice(), $path)['imported']);
	}

	/**
	 * A day of somebody's life that was meant to end, what they took down on
	 * purpose, and what they deleted. An importer that republished any of the
	 * three would be worse than one that imported nothing.
	 */
	public function testStoriesArchivedAndDeletedPostsAreLeftAlone(): void {
		$path = $this->archive([
			'your_instagram_activity/content/posts_1.json' => json_encode([
				$this->instagramPost([['uri' => 'media/kept.jpg']], ['title' => 'kept']),
			]),
			'your_instagram_activity/content/stories.json' => json_encode([
				'ig_stories' => [$this->instagramPost([['uri' => 'media/story.jpg']], ['title' => 'a story'])],
			]),
			'your_instagram_activity/content/archived_posts.json' => json_encode([
				'ig_archived_post_media' => [$this->instagramPost([['uri' => 'media/hidden.jpg']])],
			]),
			'your_instagram_activity/content/recently_deleted_content.json' => json_encode([
				'ig_recently_deleted_media' => [$this->instagramPost([['uri' => 'media/gone.jpg']])],
			]),
			'media/kept.jpg' => 'a',
		]);

		$tally = $this->service->import($this->alice(), $path);

		$this->assertSame(1, $tally['imported']);
		$this->assertSame('<p>kept</p>', $this->written[0]->getContent());
	}

	/**
	 * A post of several pictures carries its caption at the top; a post of one
	 * carries it on the picture. Both are read, and the top one wins.
	 */
	public function testTheCaptionIsFoundInBothPlacesItIsWritten(): void {
		$path = $this->archive([
			'content/posts_1.json' => json_encode([
				$this->instagramPost([
					['uri' => 'media/one.jpg', 'title' => 'the caption of a single picture'],
				]),
				$this->instagramPost(
					[['uri' => 'media/two.jpg', 'title' => 'not this one'], ['uri' => 'media/three.jpg']],
					['title' => 'the caption of a carousel']
				),
			]),
			'media/one.jpg' => 'a', 'media/two.jpg' => 'b', 'media/three.jpg' => 'c',
		]);

		$this->service->import($this->alice(), $path);

		$this->assertSame('<p>the caption of a single picture</p>', $this->written[0]->getContent());
		$this->assertSame('<p>the caption of a carousel</p>', $this->written[1]->getContent());
	}

	/**
	 * Every caption in the archive is mojibake: the exporter writes UTF-8 and
	 * escapes each byte as if it were a character. Read back, `né` arrives as
	 * `nÃ©` and an emoji as four accented letters.
	 */
	public function testInstagramsMangledEncodingIsUndone(): void {
		$mangled = json_decode('"CafÃ© ð"');
		$path = $this->archive([
			'content/posts_1.json' => json_encode([
				$this->instagramPost([['uri' => 'media/a.jpg']], ['title' => $mangled]),
			]),
			'media/a.jpg' => 'a',
		]);

		$this->service->import($this->alice(), $path);

		$this->assertSame('<p>Café 😊</p>', $this->written[0]->getContent());
	}

	/** A caption somebody genuinely wrote with an accent in it is left alone. */
	public function testTextThatIsAlreadyRightIsNotConvertedTwice(): void {
		$path = $this->archive([
			'content/posts_1.json' => json_encode([
				$this->instagramPost([['uri' => 'media/a.jpg']], ['title' => 'Trüffel']),
			]),
			'media/a.jpg' => 'a',
		]);

		$this->service->import($this->alice(), $path);

		$this->assertSame('<p>Trüffel</p>', $this->written[0]->getContent());
	}

	/**
	 * On Instagram a hashtag is a word in the caption and nothing else, so
	 * unless they are read out of it an imported post is findable by none of
	 * the tags its author chose.
	 */
	public function testHashtagsAreReadOutOfTheCaption(): void {
		$path = $this->archive([
			'content/posts_1.json' => json_encode([
				$this->instagramPost(
					[['uri' => 'media/a.jpg']],
					['title' => "low tide at the pier\n#seaside #Devon #2024 hello@example.com"]
				),
			]),
			'media/a.jpg' => 'a',
		]);

		$this->service->import($this->alice(), $path);

		// not the bare number, and not the address
		$this->assertSame(['seaside', 'Devon'], $this->written[0]->getHashtags());
	}

	/**
	 * An Instagram post says nothing about who could see it, so the importing
	 * account's own default is used — what their next post would get.
	 */
	public function testThePostsTakeTheAccountsOwnDefaultVisibility(): void {
		$path = $this->archive([
			'content/posts_1.json' => json_encode([
				$this->instagramPost([['uri' => 'media/a.jpg']], ['title' => 'quiet']),
			]),
			'media/a.jpg' => 'a',
		]);

		$this->accountService->method('getDefaultPrivacy')->willReturn(Stream::TYPE_FOLLOWERS);

		$this->service->import($this->alice(), $path);

		$this->assertSame(Stream::TYPE_FOLLOWERS, $this->written[0]->getVisibility());
	}

	/** The same archive twice writes the posts once. */
	public function testASecondRunOfTheSameInstagramArchiveWritesNothing(): void {
		$path = $this->archive([
			'content/posts_1.json' => json_encode([
				$this->instagramPost([['uri' => 'media/a.jpg']], ['title' => 'once']),
			]),
			'media/a.jpg' => 'a',
		]);

		$this->service->import($this->alice(), $path);
		$source = array_key_first($this->remembered);
		$this->assertStringStartsWith('instagram:', $source);

		$again = $this->serviceKnowing([$source => md5('x')]);
		$tally = $again->import($this->alice(), $path);

		$this->assertSame(0, $tally['imported']);
		$this->assertSame(1, $tally['already']);
	}

	/**
	 * The archive as a browser saves it: everything under one folder named for
	 * the account, so nothing is where the export said it would be.
	 */
	public function testPicturesAreFoundWhenTheArchiveIsPackedUnderAFolder(): void {
		$path = $this->archive([
			'instagram-alice-2026-09-15/content/posts_1.json' => json_encode([
				$this->instagramPost([['uri' => 'media/posts/202406/pier.jpg']], ['title' => 'a pier']),
			]),
			'instagram-alice-2026-09-15/media/posts/202406/pier.jpg' => 'not really a jpeg',
		]);

		$tally = $this->service->import($this->alice(), $path);

		$this->assertSame(1, $tally['imported']);
		$this->assertSame(1, $tally['media']);
	}

	/**
	 * The HTML download is the one people pick, because it is the one they can
	 * open. Being told to ask for JSON is the difference between a two-minute
	 * fix and giving up.
	 */
	public function testTheHtmlDownloadSaysWhatToAskInstagramFor(): void {
		$path = $this->archive([
			'your_instagram_activity/content/posts_1.html' => '<html><body>a post</body></html>',
		]);

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/JSON/');
		$this->service->import($this->alice(), $path);
	}

	/** @param array<string, string> $known */
	private function serviceKnowing(array $known): PostImportService {
		$request = $this->createStub(ImportedPostsRequest::class);
		$request->method('knownAmong')->willReturn($known);
		$request->method('remember')
			->willReturnCallback(function (string $actor, string $source, string $stream): void {
				$this->remembered[$source] = $stream;
			});

		$reflection = new \ReflectionClass($this->service);
		$clone = $reflection->newInstanceWithoutConstructor();
		foreach ($reflection->getProperties() as $property) {
			$property->setValue($clone, $property->getValue($this->service));
		}
		$reflection->getProperty('importedPostsRequest')->setValue($clone, $request);

		return $clone;
	}
}
