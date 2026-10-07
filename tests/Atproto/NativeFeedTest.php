<?php
declare(strict_types=1);
namespace OCA\Social\Tests\Atproto;
use OCA\Social\Atproto\NativeFeedService;
use OCA\Social\Atproto\Protocol\{Cid, DagCbor};
use OCA\Social\Model\Details;
use PHPUnit\Framework\TestCase;
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
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
	public function testOwnedWithdrawnCopiesCannotBeReimportedFromAppView(): void {
		$identities = $this->createMock(\OCA\Social\Atproto\Identity\IdentityService::class);
		$identities->method('isEnabled')->willReturn(true);
		$identities->method('getIdentityByDid')->willReturn(['actor_id' => 'https://social.example/@alice', 'state' => 'active']);
		$appview = $this->createMock(\OCA\Social\Atproto\AppViewClient::class); $appview->expects(self::never())->method('get');
		$actors = $this->createMock(\OCA\Social\Db\CacheActorsRequest::class); $actors->expects(self::never())->method('save');
		$streams = $this->createMock(\OCA\Social\Db\StreamRequest::class); $streams->expects(self::never())->method('save');
		$db = $this->createMock(\OCP\IDBConnection::class); $db->expects(self::never())->method('getQueryBuilder');
		$feed = new NativeFeedService($appview, $identities, $actors, $streams, $db);
		self::assertFalse($feed->importPost($this->post()));
	}

}
