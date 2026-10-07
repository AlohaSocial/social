<?php
declare(strict_types=1);
namespace OCA\Social\Tests\Atproto;
use OCA\Social\Atproto\RecordMapper\StrongRefResolver;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Details;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
#[AllowMockObjectsWithoutExpectations]
class StrongRefTest extends TestCase {
	public function testAWithdrawnPostCannotBecomeAReplyOrQuoteReference(): void {
		$post = new Note(); $post->setVisibility('followers')->setTo('https://example.org/@alice/followers');
		$post->setDetailArray(Details::ATPROTO, ['uri' => 'at://did:plc:abcdefghijklmnopqrstuvwx/app.bsky.feed.post/old', 'cid' => 'old-cid']);
		$streams = $this->createMock(StreamRequest::class); $streams->method('getStreamById')->willReturn($post);
		$db = $this->createMock(IDBConnection::class); $db->expects(self::never())->method('getQueryBuilder');
		self::assertNull((new StrongRefResolver($streams, $db))->resolve('https://example.org/@alice/old'));
	}
	public function testAReplyRetainsItsNativeRootAndStringCids(): void {
		$post = new Note(); $post->setVisibility('public')->setTo('https://www.w3.org/ns/activitystreams#Public');
		$parent = ['uri' => 'at://did:plc:abcdefghijklmnopqrstuvwx/app.bsky.feed.post/reply', 'cid' => 'parent-cid']; $root = ['uri' => 'at://did:plc:abcdefghijklmnopqrstuvwx/app.bsky.feed.post/root', 'cid' => 'root-cid'];
		$post->setDetailArray(Details::ATPROTO, $parent + ['reply' => ['root' => $root]]);
		$streams = $this->createMock(StreamRequest::class); $streams->method('getStreamById')->willReturn($post);
		$db = $this->createMock(IDBConnection::class); $db->expects(self::never())->method('getQueryBuilder');
		self::assertSame(['ref' => $parent, 'root' => $root], (new StrongRefResolver($streams, $db))->resolve('https://bsky.app/profile/did:plc:abcdefghijklmnopqrstuvwx/post/reply'));
	}
}
