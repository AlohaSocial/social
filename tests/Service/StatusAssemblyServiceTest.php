<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCA\Social\Model\StatusParams;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\StatusAssemblyService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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
	private StatusAssemblyService $service;

	/** @var Document[] what the media ids resolve to */
	private array $media = [];
	/** @var list<array{0: list<int|string>, 1: string, 2: string}> what the media resolver was asked */
	private array $resolved = [];

	protected function setUp(): void {
		parent::setUp();

		$this->documentService = $this->createMock(DocumentService::class);
		$this->documentService->method('mediaAttachmentsForEdit')
			->willReturnCallback(function (array $ids, string $account, string $visibility): array {
				$this->resolved[] = [$ids, $account, $visibility];

				return array_map(
					static fn (int|string $id): MediaAttachment => (new MediaAttachment())->setId((string)$id),
					array_values(array_filter($ids, fn (int|string $id): bool => isset($this->media[(int)$id])))
				);
			});

		$this->streamService = $this->createMock(StreamService::class);

		$this->service = new StatusAssemblyService(
			$this->documentService,
			$this->streamService,
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

			public function paramArray(string $key): ?array {
				$value = $this->params[$key] ?? null;

				return is_array($value) ? $value : null;
			}

			public function paramPoll(): ?array {
				return $this->params['poll'] ?? null;
			}

			public function paramEvent(): ?array {
				return $this->params['event'] ?? null;
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

	/** What a held post is stored as comes back, when it is approved, as the post that was written. */
	public function testAPostStoredForTheQueueComesBackAsItWasWritten(): void {
		$written = new Post($this->actor());
		$written->setContent('Cais do Sodré at six');
		$written->setSpoilerText('sunset')
			->setSensitive(true)
			->setType(Stream::TYPE_UNLISTED)
			->setLanguage('pt')
			->setQuotedId('112233')
			->setReplyRule(Stream::REPLY_RULE_MENTIONED)
			->setPoll(['options' => ['yes', 'no']])
			->setAtprotoSelfLabels(['!warn'])
			->setMedias([(new MediaAttachment())->setId('7')]);
		$this->document(7, false);

		$params = StatusAssemblyService::paramsOfPost($written, '4242');
		$this->assertSame('4242', $params['in_reply_to_id']);
		$this->assertSame(['7'], $params['media_ids']);
		$this->assertArrayNotHasKey('atproto_card', $params, 'nothing that was not there');

		$post = $this->service->fromParams($this->actor(), $this->params($params));
		$this->assertSame('Cais do Sodré at six', $post->getContent());
		$this->assertSame('sunset', $post->getSpoilerText());
		$this->assertTrue($post->isSensitive());
		$this->assertSame(Stream::TYPE_UNLISTED, $post->getType());
		$this->assertSame('pt', $post->getLanguage());
		$this->assertSame('112233', $post->getQuotedId());
		$this->assertSame(Stream::REPLY_RULE_MENTIONED, $post->getReplyRule());
		$this->assertSame(['options' => ['yes', 'no']], $post->getPoll());
		$this->assertSame(['!warn'], $post->getAtprotoSelfLabels());
		$this->assertCount(1, $post->getMedias());
	}

	public function testAHeldAtProtoPostKeepsItsSelfLabelsWhenItIsApproved(): void {
		$post = $this->service->fromParams($this->actor(), $this->params([
			'text' => 'A labeled post',
			'atproto_self_labels' => ['!warn', 'custom-label'],
		]));

		$this->assertSame(['!warn', 'custom-label'], $post->getAtprotoSelfLabels());
	}

	public function testWhoMayReplyWaitsWithTheScheduledPost(): void {
		$this->assertArrayNotHasKey('reply_policy', $this->service->paramsOf(new Status(), Stream::TYPE_PUBLIC));
		$params = $this->service->paramsOf((new Status())->setReplyRule(Stream::REPLY_RULE_MENTIONED), Stream::TYPE_PUBLIC);
		$this->assertSame(Stream::REPLY_RULE_MENTIONED, $params['reply_policy']);

		$this->assertSame(Stream::REPLY_RULE_MENTIONED, $this->service->fromParams($this->actor(), $this->params($params))->getReplyRule());
	}

	public function testTheReplyFindsThePostItAnswers(): void {
		$parent = new Stream();
		$parent->setId('https://cloud.example/apps/social/@bob/42');
		$this->streamService->method('getStreamByNid')->with(42)->willReturn($parent);

		$post = $this->service->fromParams($this->actor(), $this->params(['in_reply_to_id' => '42']));

		$this->assertSame('https://cloud.example/apps/social/@bob/42', $post->getReplyTo());
	}

	/**
	 * The parent decides how wide a reply may go: without it a reply to a
	 * direct message would be published to the author's default audience.
	 */
	public function testAReplyToAPostThatIsGoneFailsRatherThanGoingOutAlone(): void {
		$this->streamService->method('getStreamByNid')->willThrowException(new StreamNotFoundException());

		$this->expectException(StreamNotFoundException::class);

		$this->service->fromParams($this->actor(), $this->params([
			'text' => 'still worth saying',
			'in_reply_to_id' => '42',
		]));
	}

	/**
	 * The cron and a moderator's approval run with another viewer or none, and
	 * a followers-only or direct parent is only visible to its readers.
	 */
	public function testTheParentIsLookedUpAsTheAuthor(): void {
		$actor = $this->actor();
		$parent = new Stream();
		$parent->setId('https://cloud.example/apps/social/@bob/42');
		$this->streamService->expects($this->once())->method('setViewer')->with($this->identicalTo($actor));
		$this->streamService->method('getStreamByNid')->with(42)->willReturn($parent);

		$post = $this->service->fromParams($actor, $this->params(['in_reply_to_id' => '42']));

		$this->assertSame('https://cloud.example/apps/social/@bob/42', $post->getReplyTo());
	}

	/**
	 * Whether the bytes may be cached on the way to a reader is only known
	 * when the post is created, which for these posts is now and not when the
	 * media was uploaded.
	 */
	/**
	 * The resolver an edit uses: the author's own uploads in the stored order,
	 * scoped to the audience the post goes out to, and refused when one is no
	 * longer theirs.
	 */
	public function testAttachmentsAreResolvedInOrderForThePostsAudience(): void {
		$this->document(7, false);
		$this->document(9, false);

		$post = $this->service->fromParams($this->actor(), $this->params([
			'media_ids' => ['9', '7'],
			'visibility' => Stream::TYPE_FOLLOWERS,
		]));

		$this->assertSame([[[9, 7], 'alice', Stream::TYPE_FOLLOWERS]], $this->resolved);
		$this->assertSame(['9', '7'], array_map(static fn (MediaAttachment $media): string => $media->getId(), $post->getMedias()));
	}

	public function testAPostWithNoMediaAsksForNone(): void {
		$this->documentService->expects($this->never())->method('mediaAttachmentsForEdit');

		$post = $this->service->fromParams($this->actor(), $this->params(['text' => 'words only']));

		$this->assertSame([], $post->getMedias());
	}
}
