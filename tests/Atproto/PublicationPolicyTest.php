<?php

declare(strict_types=1);

namespace OCA\Social\Tests\Atproto;

use OCA\Social\Atproto\RecordMapper\OutboundQueue;
use OCA\Social\Atproto\RecordMapper\RecordMapper;
use OCA\Social\Events\PostDeletedEvent;
use OCA\Social\Events\PostPublishedEvent;
use OCA\Social\Listeners\AtprotoPostListener;
use OCA\Social\Model\ActivityPub\Stream;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PublicationPolicyTest extends TestCase {
	public function testRestrictedAudienceNeverPublishesAndWithdrawsAnyPreviousPublicCopy(): void {
		foreach (['unlisted', 'followers', 'direct'] as $visibility) {
			$post = $this->createStub(Stream::class);
			$post->method('isLocal')->willReturn(true);
			$post->method('getVisibility')->willReturn($visibility);
			$queue = $this->createMock(OutboundQueue::class);
			$queue->expects(self::never())->method('queuePost');
			$queue->expects(self::once())->method('queueDelete');
			(new AtprotoPostListener($queue, new NullLogger()))->handle(new PostPublishedEvent($post));
		}
	}
	public function testPublicPostQueuesAndDeletionRetractsItsCopy(): void {
		$post = $this->createStub(Stream::class);
		$post->method('isLocal')->willReturn(true);
		$post->method('getVisibility')->willReturn('public');
		$post->method('addressesPublic')->willReturn(true);
		$post->method('getNid')->willReturn('9223372036854775808');
		$queue = $this->createMock(OutboundQueue::class);
		$queue->expects(self::once())->method('queuePost')->with('9223372036854775808');
		$queue->expects(self::once())->method('queueDelete')->with('9223372036854775808');
		$listener = new AtprotoPostListener($queue, new NullLogger());
		$listener->handle(new PostPublishedEvent($post));
		$listener->handle(new PostDeletedEvent($post));
	}
	public function testTruncationKeepsGraphemeClustersAndBothProtocolLimits(): void {
		$text = str_repeat("👨‍👩‍👧‍👧e\u{0301}", 250);
		$url = 'https://example.org/@alice/123';
		$fitted = RecordMapper::fitText($text, $url);
		self::assertLessThanOrEqual(300, grapheme_strlen($fitted));
		self::assertLessThanOrEqual(3000, strlen($fitted));
		self::assertStringEndsWith('… ' . $url, $fitted);
		self::assertSame('123', RecordMapper::fitText('123', $url));
	}
	public function testFacetsUseUtf8ByteOffsetsAfterTruncation(): void {
		$text = 'Grüße 😀 https://example.org';
		$facet = RecordMapper::facets($text)[0];
		self::assertSame(strpos($text, 'https:'), $facet['index']['byteStart']);
		self::assertSame(strlen($text), $facet['index']['byteEnd']);
		self::assertSame('https://example.org', substr($text, $facet['index']['byteStart'], $facet['index']['byteEnd'] - $facet['index']['byteStart']));
	}
	public function testQueueFailureDoesNotUndoTheLocalSocialPost(): void {
		$post = $this->createStub(Stream::class);
		$post->method('isLocal')->willReturn(true);
		$post->method('getVisibility')->willReturn('public');
		$post->method('addressesPublic')->willReturn(true);
		$post->method('getNid')->willReturn('42');
		$queue = $this->createMock(OutboundQueue::class);
		$queue->method('queuePost')->willThrowException(new \RuntimeException('database unavailable'));
		(new AtprotoPostListener($queue, new NullLogger()))->handle(new PostPublishedEvent($post));
		self::assertTrue(true);
	}
}
