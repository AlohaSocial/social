<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\StatusParams;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\StatusAssemblyService;
use OCA\Social\Service\StreamService;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The post a stored request becomes.
 *
 * A scheduled post and a held post are both a client's request kept rather
 * than a note rendered, and both have to come back out of storage as the same
 * `Post` an immediate request would have produced. What is asserted here is
 * that sameness, and the two decisions around it: what is stored, and what
 * happens when the world moved while the post waited.
 */
#[AllowMockObjectsWithoutExpectations]
class StatusAssemblyServiceTest extends TestCase {
	private DocumentService|MockObject $documentService;
	private StreamService|MockObject $streamService;
	private CacheDocumentsRequest|Stub $cacheDocumentsRequest;
	private StatusAssemblyService $service;

	/** @var Document[] what the media ids resolve to */
	private array $media = [];
	/** @var Document[] every document written back */
	private array $updated = [];

	protected function setUp(): void {
		parent::setUp();

		$this->documentService = $this->createMock(DocumentService::class);
		$this->documentService->method('getMediaFromArray')
			->willReturnCallback(fn (array $ids): array => array_values(array_intersect_key(
				$this->media, array_flip($ids)
			)));

		$this->streamService = $this->createMock(StreamService::class);
		$this->cacheDocumentsRequest = $this->createStub(CacheDocumentsRequest::class);
		$this->cacheDocumentsRequest->method('update')
			->willReturnCallback(function (Document $document): void {
				$this->updated[] = $document;
			});

		$this->service = new StatusAssemblyService(
			$this->documentService,
			$this->streamService,
			$this->cacheDocumentsRequest,
			$this->createStub(IURLGenerator::class),
			new NullLogger()
		);
	}

	private function actor(): Person {
		$actor = new Person();
		$actor->setId('https://cloud.example/apps/social/@alice');
		$actor->setPreferredUsername('alice');

		return $actor;
	}

	private function document(int $id, bool $public): Document {
		$document = new Document();
		$document->setId('https://cloud.example/document/' . $id);
		$document->setPublic($public);
		$this->media[$id] = $document;

		return $document;
	}

	/** @param array<string, mixed> $params */
	private function params(array $params): StatusParams {
		return new class($params) implements StatusParams {
			public function __construct(
				private array $params,
			) {
			}

			public function paramText(): string {
				return (string)($this->params['text'] ?? '');
			}

			public function paramString(string $key): string {
				return (string)($this->params[$key] ?? '');
			}

			public function paramBool(string $key): bool {
				return (bool)($this->params[$key] ?? false);
			}

			public function paramPoll(): ?array {
				return $this->params['poll'] ?? null;
			}

			public function paramMediaIds(): array {
				return array_map('intval', $this->params['media_ids'] ?? []);
			}
		};
	}

	public function testWhatIsStoredIsWhatThisAppUnderstoodNotTheRawRequest(): void {
		$status = (new Status())
			->setStatus('Cais do Sodré at six')
			->setMediaIds([7, 9])
			->setInReplyToId(42)
			->setQuotedId('112233')
			->setSensitive(true)
			->setSpoilerText('sunset')
			->setLanguage('pt');

		$params = $this->service->paramsOf($status, Stream::TYPE_PUBLIC);

		$this->assertSame('Cais do Sodré at six', $params['text']);
		// every id Mastodon shows a client is a string, and this array is
		// echoed back to the client verbatim
		$this->assertSame(['7', '9'], $params['media_ids']);
		$this->assertSame('42', $params['in_reply_to_id']);
		$this->assertSame('112233', $params['quoted_status_id']);
		$this->assertTrue($params['sensitive']);
		$this->assertSame('sunset', $params['spoiler_text']);
		$this->assertSame(Stream::TYPE_PUBLIC, $params['visibility']);
		$this->assertSame('pt', $params['language']);
	}

	/**
	 * A scheduled post's time lives on its own row, where rescheduling keeps it
	 * current; a copy in `params` would be the stale one the moment the post is
	 * moved, and a client cannot tell which of the two it is meant to believe.
	 */
	public function testTheTimeAPostIsDueIsNotCopiedIntoTheStoredRequest(): void {
		$params = $this->service->paramsOf(new Status(), Stream::TYPE_PUBLIC);

		$this->assertArrayNotHasKey('scheduled_at', $params);
	}

	public function testNothingThatWasNotAskedForIsStored(): void {
		$params = $this->service->paramsOf(new Status(), Stream::TYPE_PUBLIC);

		$this->assertNull($params['in_reply_to_id']);
		$this->assertNull($params['quoted_status_id']);
		$this->assertNull($params['poll']);
	}

	public function testAStoredRequestComesBackAsThePostItWouldHaveBeen(): void {
		$post = $this->service->fromParams($this->actor(), $this->params([
			'text' => 'Cais do Sodré at six',
			'spoiler_text' => 'sunset',
			'sensitive' => true,
			'visibility' => Stream::TYPE_UNLISTED,
			'language' => 'pt',
			'quoted_status_id' => '112233',
			'poll' => ['options' => ['yes', 'no']],
		]));

		$this->assertSame('Cais do Sodré at six', $post->getContent());
		$this->assertSame('sunset', $post->getSpoilerText());
		$this->assertTrue($post->isSensitive());
		$this->assertSame(Stream::TYPE_UNLISTED, $post->getType());
		$this->assertSame('pt', $post->getLanguage());
		$this->assertSame('112233', $post->getQuotedId());
		$this->assertSame(['options' => ['yes', 'no']], $post->getPoll());
	}

	public function testTheReplyFindsThePostItAnswers(): void {
		$parent = new Stream();
		$parent->setId('https://cloud.example/apps/social/@bob/42');
		$this->streamService->method('getStreamByNid')->with(42)->willReturn($parent);

		$post = $this->service->fromParams($this->actor(), $this->params(['in_reply_to_id' => '42']));

		$this->assertSame('https://cloud.example/apps/social/@bob/42', $post->getReplyTo());
	}

	public function testAStoredAtprotoReplyFindsItsImportedParent(): void {
		$reference = 'https://cloud.example/apps/social/ap/bluesky/did:plc:bob/app.bsky.feed.post/3xyz';
		$parent = new Stream();
		$parent->setId($reference);
		$this->streamService->expects($this->once())->method('getStreamById')->with($reference, true)->willReturn($parent);

		$post = $this->service->fromParams($this->actor(), $this->params(['in_reply_to_id' => $reference]));

		$this->assertSame($reference, $post->getReplyTo());
	}

	/**
	 * Likelier here than on an immediate post: the post being replied to can
	 * be deleted while this one waits. The reply still goes out, as a post of
	 * its own, rather than being lost with it.
	 */
	public function testAReplyToAPostThatIsGoneIsStillAPost(): void {
		$this->streamService->method('getStreamByNid')->willThrowException(new RuntimeException('gone'));

		$post = $this->service->fromParams($this->actor(), $this->params([
			'text' => 'still worth saying',
			'in_reply_to_id' => '42',
		]));

		$this->assertSame('', $post->getReplyTo());
		$this->assertSame('still worth saying', $post->getContent());
	}

	/**
	 * Whether the bytes may be cached on the way to a reader is only known
	 * when the post is created, which for these posts is now and not when the
	 * media was uploaded.
	 */
	public function testAttachmentsAreToldWhetherThePostTheyLandOnIsPublic(): void {
		$this->document(7, false);
		$this->document(9, false);

		$post = $this->service->fromParams($this->actor(), $this->params([
			'media_ids' => ['7', '9'],
			'visibility' => Stream::TYPE_PUBLIC,
		]));

		$this->assertCount(2, $post->getMedias());
		$this->assertCount(2, $this->updated);
		$this->assertTrue($this->media[7]->isPublic());
	}

	public function testAttachmentsOnAPrivatePostAreMarkedPrivate(): void {
		$this->document(7, true);

		$this->service->fromParams($this->actor(), $this->params([
			'media_ids' => ['7'],
			'visibility' => Stream::TYPE_FOLLOWERS,
		]));

		$this->assertFalse($this->media[7]->isPublic());
		$this->assertCount(1, $this->updated);
	}

	/** A write per attachment per replay, for a value that has not moved. */
	public function testAnAttachmentAlreadyMarkedRightIsNotWrittenAgain(): void {
		$this->document(7, true);

		$this->service->fromParams($this->actor(), $this->params([
			'media_ids' => ['7'],
			'visibility' => Stream::TYPE_PUBLIC,
		]));

		$this->assertSame([], $this->updated);
	}

	public function testAPostWithNoMediaAsksForNone(): void {
		$this->documentService->expects($this->never())->method('getMediaFromArray');

		$post = $this->service->fromParams($this->actor(), $this->params(['text' => 'words only']));

		$this->assertSame([], $post->getMedias());
	}
}
