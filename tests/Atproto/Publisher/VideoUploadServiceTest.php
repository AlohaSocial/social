<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Client\ServiceAuthGrant;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\VideoUpload;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Atproto\Publisher\VideoUploadService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoVideoRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DocumentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class VideoUploadServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const HERE = 'did:web:social.test';
	private const POST = 'https://social.test/@alice/1';
	private const SERVICE = 'https://video.bsky.app';

	private string $service = self::SERVICE;
	private int $now = 1760000000;
	/** @var array<string, VideoUpload> */
	private array $rows = [];
	/** @var array<string, BlobRef> */
	private array $blobs = [];
	/** @var list<array{url: string, options: array}> */
	private array $sent = [];
	/** @var array{0: int, 1: array} what the video service answers an upload */
	private array $uploadAnswer = [200, []];
	/** @var array<string, array> what it answers a GET, by method */
	private array $answers = [];
	private Identity $alice;
	private Person $author;
	private Document $video;
	private PrivateKey $key;
	/** @var AtprotoVideoRequest&MockObject */
	private AtprotoVideoRequest $videoRequest;
	private VideoUploadService $uploads;

	protected function setUp(): void {
		$this->key = PrivateKey::generate(Curve::K256);
		$this->alice = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0);
		$this->author = new Person();
		$this->author->setPreferredUsername('alice');
		$this->video = new Document();
		$this->video->setNid(7);
		$this->video->setId('https://social.test/documents/local/7');
		$this->video->setMimeType('video/mp4');
		$this->video->setSizeBytes(5000000);
		$this->video->setLocalCopy('uuid-7');
		$this->video->setLocalCopySize(1080, 1920);
		$this->video->setDescription('Waves at sunset');

		$config = $this->createMock(AtprotoConfig::class);
		$config->method('videoService')->willReturnCallback(fn (): string => $this->service);
		$config->method('videoServiceDid')->willReturn('did:web:video.bsky.app');
		$config->method('serviceDid')->willReturn(self::HERE);

		$this->videoRequest = $this->createMock(AtprotoVideoRequest::class);
		$this->videoRequest->method('get')->willReturnCallback(fn (string $id): ?VideoUpload => $this->rows[$id] ?? null);
		$this->videoRequest->method('add')->willReturnCallback(function (string $postId, string $did, string $documentId): bool {
			$this->rows[$postId] = new VideoUpload(count($this->rows) + 1, $postId, $did, $documentId, VideoUpload::QUEUED, creation: $this->now);

			return true;
		});
		$this->videoRequest->method('inState')->willReturnCallback(fn (string $state): array => array_values(array_filter($this->rows, static fn (VideoUpload $u): bool => $u->state === $state)));
		$this->videoRequest->method('update')->willReturnCallback(function (VideoUpload $upload): void {
			$this->rows[$upload->postId] = $upload;
		});
		$this->videoRequest->method('remove')->willReturnCallback(function (string $postId): void {
			unset($this->rows[$postId]);
		});

		$blobRequest = $this->createMock(AtprotoBlobRequest::class);
		$blobRequest->method('get')->willReturnCallback(fn (string $did, string $cid): ?BlobRef => $this->blobs[$cid] ?? null);
		$blobRequest->method('getByDocument')->willReturnCallback(function (string $did, string $documentId): ?BlobRef {
			foreach ($this->blobs as $blob) {
				if ($blob->documentId === $documentId) {
					return $blob;
				}
			}

			return null;
		});

		$identities = $this->createMock(IdentityService::class);
		$identities->method('getByDid')->willReturn($this->alice);
		$identities->method('signingKey')->willReturnCallback(fn (): PrivateKey => $this->key);

		$documents = $this->createMock(DocumentService::class);
		$documents->method('getMediaFromArray')->willReturnCallback(fn (array $ids, string $account): array => $ids === ['7'] && $account === 'alice' ? [$this->video] : []);
		$documents->method('getDocumentById')->willReturnCallback(fn (): Document => $this->video);

		$file = $this->createMock(ISimpleFile::class);
		$file->method('getSize')->willReturn(5000000);
		$file->method('read')->willReturnCallback(static fn () => fopen('php://memory', 'rb'));
		$cache = $this->createMock(CacheDocumentService::class);
		$cache->method('getFromUuid')->willReturn($file);

		$curl = $this->createMock(CurlService::class);
		$curl->method('retrieveJson')->willReturnCallback(function (string $verb, string $url, array $options = []): array {
			$this->sent[] = ['url' => $url, 'options' => $options];
			$method = (string)preg_replace('#^.*/xrpc/([^?]+).*$#', '$1', $url);

			return $this->answers[$method] ?? [];
		});
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $url, array $options): IResponse {
			$this->sent[] = ['url' => $url, 'options' => $options];
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($this->uploadAnswer[0]);
			$response->method('getBody')->willReturn((string)json_encode($this->uploadAnswer[1]));

			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$this->uploads = new VideoUploadService(
			$config, $this->videoRequest, $blobRequest, $identities, new ServiceAuth($time), $documents, $cache,
			$curl, $clients, $time, new NullLogger(),
		);
	}

	private function post(string $type = 'video'): Note {
		$post = new Note();
		$post->setId(self::POST);
		$attachment = new MediaAttachment();
		$attachment->setId('7');
		$attachment->setType($type);
		$post->setAttachments([$attachment]);

		return $post;
	}

	public function testAPostWithoutAVideoHasNone(): void {
		$this->assertSame(['state' => 'none'], $this->uploads->forPost($this->post('image'), $this->alice, $this->author));
	}

	public function testAVideoAnAppUploadedThroughTheVideoServiceIsReady(): void {
		$cid = Cid::forRaw('made by the video service');
		$this->blobs[$cid->toString()] = new BlobRef(self::DID, $cid, $this->video->getId(), 'video/mp4', 4000000);

		$video = $this->uploads->forPost($this->post(), $this->alice, $this->author);

		$this->assertSame('ready', $video['state']);
		$this->assertSame($cid->toString(), $video['blob']->cid->toString());
		$this->assertSame(['alt' => 'Waves at sunset', 'width' => 1080, 'height' => 1920], array_intersect_key($video, ['alt' => 1, 'width' => 1, 'height' => 1]));
		$this->assertSame([], $this->rows, 'nothing to send');
	}

	public function testWithoutAVideoServiceTheVideoIsALink(): void {
		$this->service = '';

		$this->assertSame(['state' => 'link'], $this->uploads->forPost($this->post(), $this->alice, $this->author));
	}

	public function testAVideoIsQueuedOnceAndThePostWaits(): void {
		$this->assertSame(['state' => 'waiting'], $this->uploads->forPost($this->post(), $this->alice, $this->author));
		$this->assertSame(['state' => 'waiting'], $this->uploads->forPost($this->post(), $this->alice, $this->author));

		$this->assertCount(1, $this->rows);
		$this->assertSame(VideoUpload::QUEUED, $this->rows[self::POST]->state);
		$this->assertSame($this->video->getId(), $this->rows[self::POST]->documentId);
	}

	public function testAVideoBlueskyDoesNotTakeIsALinkAndNeverSent(): void {
		$meta = new AttachmentMeta();
		$meta->setDuration(600.0);
		$this->video->setMeta($meta);
		$this->assertSame(['state' => 'link'], $this->uploads->forPost($this->post(), $this->alice, $this->author), 'ten minutes');

		$this->video->setMeta(null);
		$this->video->setSizeBytes(150000000);
		$this->assertSame(['state' => 'link'], $this->uploads->forPost($this->post(), $this->alice, $this->author), '150 MB');

		$this->video->setSizeBytes(1000);
		$this->video->setMimeType('video/x-msvideo');
		$this->assertSame(['state' => 'link'], $this->uploads->forPost($this->post(), $this->alice, $this->author), 'an AVI');
		$this->assertSame([], $this->rows);
	}

	public function testTheVideoIsSentInTheAccountsNameAndThePostWaitsForTheJob(): void {
		$this->uploads->forPost($this->post(), $this->alice, $this->author);
		$this->answers['app.bsky.video.getUploadLimits'] = ['canUpload' => true, 'remainingDailyVideos' => 24];
		$this->uploadAnswer = [200, ['jobId' => 'job-1', 'did' => self::DID, 'state' => 'JOB_STATE_CREATED']];

		$this->assertSame([], $this->uploads->advance());

		$this->assertSame(VideoUpload::PROCESSING, $this->rows[self::POST]->state);
		$this->assertSame('job-1', $this->rows[self::POST]->jobId);
		$limits = $this->sent[0];
		$this->assertSame(self::SERVICE . '/xrpc/app.bsky.video.getUploadLimits', $limits['url']);
		$this->assertSame('did:web:video.bsky.app', $this->claims($limits['options']['headers']['Authorization'])['aud']);
		$upload = $this->sent[1];
		$this->assertSame(self::SERVICE . '/xrpc/app.bsky.video.uploadVideo?did=' . urlencode(self::DID) . '&name=video.mp4', $upload['url']);
		$this->assertSame('video/mp4', $upload['options']['headers']['Content-Type']);
		$claims = $this->claims($upload['options']['headers']['Authorization']);
		$this->assertSame(['iss' => self::DID, 'aud' => self::HERE, 'lxm' => ServiceAuthGrant::UPLOAD], array_intersect_key($claims, ['iss' => 1, 'aud' => 1, 'lxm' => 1]), 'for the service to store the result here');
		$this->assertSame($this->now + ServiceAuthGrant::MAX_LIFETIME, $claims['exp']);
	}

	public function testAFinishedJobNamesTheBlobTheServiceStoredHere(): void {
		$this->sentAndProcessing();
		$cid = Cid::forRaw('the stream source');
		$this->answers['app.bsky.video.getJobStatus'] = ['jobStatus' => ['jobId' => 'job-1', 'state' => 'JOB_STATE_ENCODING', 'progress' => 40]];
		$this->assertSame([], $this->uploads->advance(), 'still being made');

		$this->blobs[$cid->toString()] = new BlobRef(self::DID, $cid, 'https://social.test/documents/local/8', 'video/mp4', 4000000);
		$this->answers['app.bsky.video.getJobStatus'] = ['jobStatus' => ['jobId' => 'job-1', 'state' => 'JOB_STATE_COMPLETED', 'blob' => ['$type' => 'blob', 'ref' => ['$link' => $cid->toString()], 'mimeType' => 'video/mp4', 'size' => 4000000]]];
		$this->assertSame([self::POST], $this->uploads->advance());

		$video = $this->uploads->forPost($this->post(), $this->alice, $this->author);
		$this->assertSame('ready', $video['state']);
		$this->assertSame($cid->toString(), $video['blob']->cid->toString());
		$this->assertSame('Waves at sunset', $video['alt'], 'the alt text of the video posted here');
	}

	public function testAJobThatFailsOrStoredNothingHereLeavesALink(): void {
		$this->sentAndProcessing();
		$this->answers['app.bsky.video.getJobStatus'] = ['jobStatus' => ['state' => 'JOB_STATE_COMPLETED', 'blob' => ['ref' => ['$link' => Cid::forRaw('elsewhere')->toString()]]]];
		$this->assertSame([self::POST], $this->uploads->advance());
		$this->assertSame(VideoUpload::FAILED, $this->rows[self::POST]->state);
		$this->assertSame(['state' => 'link'], $this->uploads->forPost($this->post(), $this->alice, $this->author));

		$this->rows = [];
		$this->sentAndProcessing();
		$this->answers['app.bsky.video.getJobStatus'] = ['jobStatus' => ['state' => 'JOB_STATE_FAILED', 'error' => 'Video too long']];
		$this->assertSame([self::POST], $this->uploads->advance());
		$this->assertSame('Video too long', $this->rows[self::POST]->error);
	}

	public function testAJobThatTakesTooLongLeavesALink(): void {
		$this->sentAndProcessing();
		$this->now += 3600;

		$this->assertSame([self::POST], $this->uploads->advance());
		$this->assertSame(VideoUpload::FAILED, $this->rows[self::POST]->state);
	}

	public function testTheDailyLimitOrARefusalLeavesALink(): void {
		$this->uploads->forPost($this->post(), $this->alice, $this->author);
		$this->answers['app.bsky.video.getUploadLimits'] = ['canUpload' => false, 'message' => 'You have reached your daily upload limit'];
		$this->assertSame([self::POST], $this->uploads->advance());
		$this->assertSame('You have reached your daily upload limit', $this->rows[self::POST]->error);
		$this->assertCount(1, $this->sent, 'not sent');

		$this->rows = [];
		$this->answers = [];
		$this->uploads->forPost($this->post(), $this->alice, $this->author);
		$this->uploadAnswer = [400, ['error' => 'InvalidRequest', 'message' => 'Unsupported video']];
		$this->assertSame([self::POST], $this->uploads->advance());
		$this->assertSame('Unsupported video', $this->rows[self::POST]->error);
	}

	public function testAnUnreachableServiceIsTriedThreeTimes(): void {
		$this->uploads->forPost($this->post(), $this->alice, $this->author);
		$this->uploadAnswer = [503, []];

		$this->assertSame([], $this->uploads->advance());
		$this->assertSame([], $this->uploads->advance());
		$this->assertSame(VideoUpload::QUEUED, $this->rows[self::POST]->state);
		$this->assertSame([self::POST], $this->uploads->advance());
		$this->assertSame(VideoUpload::FAILED, $this->rows[self::POST]->state);
	}

	public function testAVideoTheServiceHasSeenIsFollowedByItsJob(): void {
		$this->uploads->forPost($this->post(), $this->alice, $this->author);
		$this->uploadAnswer = [409, ['error' => 'already_exists', 'jobId' => 'job-0']];

		$this->uploads->advance();

		$this->assertSame(VideoUpload::PROCESSING, $this->rows[self::POST]->state);
		$this->assertSame('job-0', $this->rows[self::POST]->jobId);
	}

	private function sentAndProcessing(): void {
		$this->answers = [];
		$this->uploads->forPost($this->post(), $this->alice, $this->author);
		$this->uploadAnswer = [200, ['jobId' => 'job-1', 'state' => 'JOB_STATE_CREATED']];
		$this->uploads->advance();
		$this->assertSame(VideoUpload::PROCESSING, $this->rows[self::POST]->state);
	}

	private function claims(string $authorization): array {
		return json_decode(Encoding::base64UrlDecode(explode('.', substr($authorization, 7))[1]), true);
	}
}
