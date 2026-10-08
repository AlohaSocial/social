<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Client\ServiceAuthGrant;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\VideoUpload;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoVideoRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DocumentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A local post's video, made into a Bluesky video (D11).
 *
 * Bluesky's apps play what its video service made of a video, so a video is
 * not simply a blob in the post: it is sent to the video service in the
 * account's name, the service makes its stream and stores the result here
 * with `uploadBlob` (a token the account signed for exactly that, see
 * `ServiceAuthGrant`), and the post goes to Bluesky once the job is done,
 * naming that blob. That takes minutes, so the post waits in
 * `social_atproto_video` and a background job moves it on. A video the
 * service will not take, refuses or does not finish in time leaves the post
 * to go out as it did before: text and a link to the post here.
 */
class VideoUploadService {
	/** what the video service is sent; Bluesky's apps send these */
	private const SENT_TYPES = ['video/mp4', 'video/webm', 'video/quicktime', 'video/mpeg'];
	/** the tries at sending one video before its post goes out as a link */
	private const MAX_ATTEMPTS = 3;
	/** how long a video may take to be made, before its post goes out as a link */
	private const PROCESSING_TIMEOUT = 1800;
	/** how long a finished row is kept, for `occ` and for the logs to make sense */
	private const KEEP_ENDED = 7 * 86400;
	private const COMPLETED = 'JOB_STATE_COMPLETED';
	private const FAILED = 'JOB_STATE_FAILED';

	public function __construct(
		private AtprotoConfig $config,
		private AtprotoVideoRequest $videoRequest,
		private AtprotoBlobRequest $blobRequest,
		private IdentityService $identities,
		private ServiceAuth $serviceAuth,
		private DocumentService $documents,
		private CacheDocumentService $cache,
		private CurlService $curl,
		private IClientService $clientService,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * What a post's video is, for publishing it now:
	 *
	 * - `none`: the post has no video;
	 * - `ready`: the blob to name, with its alt text and size;
	 * - `waiting`: the video service is making it, so the post waits;
	 * - `link`: it will not be a Bluesky video, so the post links here.
	 *
	 * The first time a post with a video is published, its video is queued.
	 *
	 * @return array{state: string, blob?: BlobRef, alt?: string, width?: int, height?: int}
	 */
	public function forPost(Stream $post, Identity $identity, Person $author): array {
		$document = $this->videoOf($post, $author);
		if ($document === null) {
			return ['state' => 'none'];
		}
		$ready = fn (BlobRef $blob): array => ['state' => 'ready', 'blob' => $blob, 'alt' => $document->getDescription()]
			+ self::sizeOf($document);

		// uploaded by a Bluesky app, through the video service: the post's
		// file is the blob the service stored here
		$own = $this->blobRequest->getByDocument($identity->did, $document->getId());
		if ($own !== null && $own->mime === VideoBlobService::TYPE) {
			return $ready($own);
		}
		if ($this->config->videoService() === '') {
			return ['state' => 'link'];
		}

		$upload = $this->videoRequest->get($post->getId());
		if ($upload === null) {
			$refusal = $this->refusal($document);
			if ($refusal !== '') {
				$this->logger->info('Video stays a link on Bluesky', ['post' => $post->getId(), 'reason' => $refusal]);

				return ['state' => 'link'];
			}
			$this->videoRequest->add($post->getId(), $identity->did, $document->getId());

			return ['state' => 'waiting'];
		}
		if ($upload->isWaiting()) {
			return ['state' => 'waiting'];
		}
		$blob = $upload->state === VideoUpload::DONE ? $this->blobRequest->get($identity->did, $upload->blobCid) : null;

		return $blob === null ? ['state' => 'link'] : $ready($blob);
	}

	/**
	 * Moves the queued and processing videos on, a few per run.
	 *
	 * @return string[] the posts whose video has ended, to be published now
	 */
	public function advance(int $limit = 3): array {
		$ended = [];
		foreach ($this->videoRequest->inState(VideoUpload::QUEUED, $limit) as $upload) {
			if ($this->send($upload)) {
				$ended[] = $upload->postId;
			}
		}
		foreach ($this->videoRequest->inState(VideoUpload::PROCESSING, $limit * 10) as $upload) {
			if ($this->poll($upload)) {
				$ended[] = $upload->postId;
			}
		}
		$this->videoRequest->pruneEnded($this->time->getTime() - self::KEEP_ENDED);

		return $ended;
	}

	/**
	 * A post's video is no longer wanted: the post was deleted.
	 */
	public function forget(string $postId): void {
		$this->videoRequest->remove($postId);
	}

	/**
	 * @return bool whether the video has ended, one way or the other
	 */
	private function send(VideoUpload $upload): bool {
		try {
			$identity = $this->identities->getByDid($upload->did);
			$document = $this->documents->getDocumentById($upload->documentId);
		} catch (Throwable) {
			return $this->end($upload, VideoUpload::FAILED, 'The video or its account is gone');
		}
		$limit = $this->uploadLimit($identity);
		if ($limit !== '') {
			return $this->end($upload, VideoUpload::FAILED, $limit);
		}

		$upload->attempts++;
		try {
			$file = $this->cache->getFromUuid($document->getLocalCopy());
			$token = $this->serviceAuth->token(
				$this->identities->signingKey($identity), $identity->did, $this->config->serviceDid(),
				ServiceAuthGrant::UPLOAD, ServiceAuthGrant::MAX_LIFETIME
			);
			$response = $this->clientService->newClient()->post(
				$this->config->videoService() . '/xrpc/app.bsky.video.uploadVideo?' . http_build_query([
					'did' => $identity->did,
					'name' => self::fileName($document),
				]),
				[
					'body' => $file->read(),
					'headers' => [
						'Authorization' => 'Bearer ' . $token,
						'Content-Type' => strtolower($document->getMimeType()),
						'Content-Length' => (string)$file->getSize(),
						'Accept' => 'application/json',
					],
					'timeout' => 600,
					'http_errors' => false,
				]
			);
			$answer = json_decode((string)$response->getBody(), true);
			$status = $response->getStatusCode();
		} catch (Throwable $e) {
			$this->logger->notice('Video not sent to Bluesky\'s video service', ['post' => $upload->postId, 'exception' => $e]);
			$answer = null;
			$status = 0;
		}

		// a video the service has seen already is answered with its job
		$jobId = is_array($answer) ? (string)($answer['jobId'] ?? $answer['jobStatus']['jobId'] ?? '') : '';
		if ($jobId !== '') {
			$upload->jobId = $jobId;
			$upload->state = VideoUpload::PROCESSING;
			$this->videoRequest->update($upload);

			return $this->settle($upload, is_array($answer['jobStatus'] ?? null) ? $answer['jobStatus'] : $answer);
		}
		$reason = is_array($answer) ? trim((string)($answer['message'] ?? $answer['error'] ?? '')) : '';
		if ($status >= 400 && $status < 500 && $status !== 429) {
			return $this->end($upload, VideoUpload::FAILED, $reason !== '' ? $reason : 'Refused by the video service (' . $status . ')');
		}
		if ($upload->attempts >= self::MAX_ATTEMPTS) {
			return $this->end($upload, VideoUpload::FAILED, $reason !== '' ? $reason : 'The video service could not be reached');
		}
		$upload->error = $reason;
		$this->videoRequest->update($upload);

		return false;
	}

	/**
	 * @return bool whether the video has ended
	 */
	private function poll(VideoUpload $upload): bool {
		if ($this->time->getTime() - $upload->creation > self::PROCESSING_TIMEOUT) {
			return $this->end($upload, VideoUpload::FAILED, 'The video service did not finish in time');
		}
		try {
			$answer = $this->curl->retrieveJson('get', $this->config->videoService() . '/xrpc/app.bsky.video.getJobStatus?' . http_build_query(['jobId' => $upload->jobId]), [
				'accept_errors' => true,
				'timeout' => 20,
			]);
		} catch (Throwable $e) {
			$this->logger->debug('Video job status not read', ['post' => $upload->postId, 'exception' => $e]);

			return false;
		}

		return $this->settle($upload, is_array($answer['jobStatus'] ?? null) ? $answer['jobStatus'] : []);
	}

	/**
	 * Ends a job the service says has ended. A finished one has to have
	 * stored its blob here, which is what the post will name.
	 *
	 * @return bool whether the video has ended
	 */
	private function settle(VideoUpload $upload, array $status): bool {
		$state = (string)($status['state'] ?? '');
		if ($state === self::FAILED) {
			return $this->end($upload, VideoUpload::FAILED, trim((string)($status['message'] ?? $status['error'] ?? 'The video service could not make the video')));
		}
		if ($state !== self::COMPLETED) {
			return false;
		}
		$cid = (string)($status['blob']['ref']['$link'] ?? '');
		if ($cid === '' || $this->blobRequest->get($upload->did, $cid) === null) {
			return $this->end($upload, VideoUpload::FAILED, 'The video service did not store the video here');
		}
		$upload->blobCid = $cid;

		return $this->end($upload, VideoUpload::DONE, '');
	}

	private function end(VideoUpload $upload, string $state, string $error): bool {
		$upload->state = $state;
		$upload->error = $error;
		$this->videoRequest->update($upload);
		if ($state === VideoUpload::FAILED) {
			$this->logger->info('Video goes to Bluesky as a link', ['post' => $upload->postId, 'reason' => $error]);
		}

		return true;
	}

	/**
	 * Why the service would not take an account's video today, '' when it
	 * would or cannot be asked: it does not hold every service to answering.
	 */
	private function uploadLimit(Identity $identity): string {
		$audience = $this->config->videoServiceDid();
		if ($audience === '') {
			return '';
		}
		try {
			$token = $this->serviceAuth->token($this->identities->signingKey($identity), $identity->did, $audience, 'app.bsky.video.getUploadLimits');
			$limits = $this->curl->retrieveJson('get', $this->config->videoService() . '/xrpc/app.bsky.video.getUploadLimits', [
				'headers' => ['Authorization' => 'Bearer ' . $token],
				'accept_errors' => true,
				'timeout' => 20,
			]);
		} catch (Throwable) {
			return '';
		}
		if (($limits['canUpload'] ?? true) === false) {
			return trim((string)($limits['message'] ?? $limits['error'] ?? 'The daily video limit is reached'));
		}

		return '';
	}

	/**
	 * Why a video will not be sent at all, '' when it will.
	 */
	private function refusal(Document $document): string {
		if (!in_array(strtolower($document->getMimeType()), self::SENT_TYPES, true)) {
			return 'Bluesky does not take ' . $document->getMimeType();
		}
		$size = $document->getSizeBytes();
		if ($size > VideoBlobService::MAX_BYTES) {
			return 'Larger than Bluesky takes';
		}
		$duration = (float)($document->getMeta()?->getDuration() ?? 0);
		if ($duration > VideoBlobService::MAX_SECONDS) {
			return 'Longer than Bluesky takes';
		}

		return '';
	}

	/**
	 * The post's video: its first, as a Bluesky post has room for one.
	 */
	private function videoOf(Stream $post, Person $author): ?Document {
		foreach ($post->getAttachments() as $attachment) {
			if ($attachment->getType() !== 'video') {
				continue;
			}
			try {
				return $this->documents->getMediaFromArray([$attachment->getId()], $author->getPreferredUsername())[0] ?? null;
			} catch (Throwable) {
				return null;
			}
		}

		return null;
	}

	/**
	 * @return array{width: int, height: int}
	 */
	private static function sizeOf(Document $document): array {
		[$width, $height] = $document->getLocalCopySize();

		return ['width' => (int)$width, 'height' => (int)$height];
	}

	private static function fileName(Document $document): string {
		return match (strtolower($document->getMimeType())) {
			'video/webm' => 'video.webm',
			'video/quicktime' => 'video.mov',
			'video/mpeg' => 'video.mpeg',
			default => 'video.mp4',
		};
	}
}
