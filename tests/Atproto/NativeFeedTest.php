<?php
declare(strict_types=1);
namespace OCA\Social\Tests\Atproto;
use OCA\Social\Atproto\NativeFeedService;
use OCA\Social\Atproto\Protocol\{Cid, DagCbor};
use OCA\Social\Model\Details;
use PHPUnit\Framework\TestCase;
class NativeFeedTest extends TestCase {
	private function post(): array {
		$did = 'did:plc:abcdefghijklmnopqrstuvwx'; $record = ['$type' => 'app.bsky.feed.post', 'text' => '<img src=x onerror=alert(1)> Grüße', 'createdAt' => '2026-10-07T12:00:00Z'];
		return ['uri' => 'at://' . $did . '/app.bsky.feed.post/3abcdefgh2345', 'cid' => Cid::hash(DagCbor::encode($record)), 'record' => $record, 'author' => ['did' => $did, 'handle' => 'alice.bsky.social']];
	}
	public function testNativePostUsesOrdinaryNoteAndSafeText(): void {
		$post = $this->post(); $note = NativeFeedService::note($post);
		self::assertSame('Note', $note->getType()); self::assertFalse($note->isLocal()); self::assertTrue($note->isPublic());
		self::assertStringContainsString('&lt;img', $note->getContent()); self::assertStringNotContainsString('<img', $note->getContent());
		self::assertSame('https://bsky.app/profile/' . $post['author']['did'], $note->getAttributedTo());
		self::assertSame($post['uri'], $note->getDetails(Details::ATPROTO)['uri']); self::assertSame($post['cid'], $note->getDetails(Details::ATPROTO)['cid']);
	}
	public function testRemoteLabelsRemainCoveredInSharedTimeline(): void {
		$post = $this->post(); $post['labels'] = [['val' => 'porn']]; self::assertTrue(NativeFeedService::note($post)->isSensitive());
	}
	public function testCannotImpersonateAnotherPostAuthor(): void {
		$post = $this->post(); $post['author']['did'] = 'did:plc:aaaaaaaaaaaaaaaaaaaaaaaa';
		$this->expectException(\InvalidArgumentException::class); NativeFeedService::note($post);
	}
}
