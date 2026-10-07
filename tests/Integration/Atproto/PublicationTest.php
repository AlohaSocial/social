<?php
declare(strict_types=1);
namespace OCA\Social\Tests\Integration\Atproto;
use OCA\Social\Atproto\Identity\{IdentityService, KeyManager};
use OCA\Social\Atproto\Repository\Repository;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\RecordMapper\OutboundWorker;
use OCA\Social\Db\{ActorsRequest, CacheActorsRequest, StreamRequest};
use OCA\Social\Model\{Post, ActivityPub\Actor\Person};
use OCA\Social\Service\{ConfigService, PostService, ActorService, SignatureService, StreamService};
use OCP\{Server, IDBConnection, IUserManager};
use PHPUnit\Framework\TestCase;
/** Composer service → event listener → SQL outbox → signed native repo; no production PLC writes. */
class PublicationTest extends TestCase {
	private IDBConnection $db;
	private Person $actor;
	private string $did;
	private string $enabled;
	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class); $this->db->beginTransaction();
		$config = Server::get(ConfigService::class); $this->enabled = $config->getAppValue(ConfigService::ATPROTO_ENABLED); $config->setAppValue(ConfigService::ATPROTO_ENABLED, '1');
		$users = Server::get(IUserManager::class)->search('', 1); self::assertNotEmpty($users);
		$this->actor = new Person(); $this->actor->setPreferredUsername('atproto-test-' . bin2hex(random_bytes(4)))->setUserId((string)array_key_first($users));
		Server::get(SignatureService::class)->generateKeys($this->actor); Server::get(ActorsRequest::class)->create($this->actor);
		$this->actor = Server::get(ActorsRequest::class)->getFromUsername($this->actor->getPreferredUsername());
		Server::get(ActorService::class)->cacheLocalActor($this->actor);
		$keyManager = Server::get(KeyManager::class); $key = $keyManager->generateSigningKey(); $this->did = 'did:plc:' . substr(Cid::base32(random_bytes(32)), 0, 24);
		// Supply an already registered account at the network boundary. Actual PLC acceptance
		// must be tested separately; the publishing path uses the real encrypted database key.
		$qb = $this->db->getQueryBuilder(); $values = ['actor_id' => $this->actor->getId(), 'did' => $this->did, 'handle' => $this->actor->getPreferredUsername() . '.pds.example',
			'signing_key' => $keyManager->sealPrivateKey($key['private']), 'signing_public' => $key['didKey'], 'recovery_public' => $key['didKey'], 'state' => 'active', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')];
		$params = []; foreach ($values as $k => $v) { $params[$k] = $qb->createNamedParameter($v); } $qb->insert('social_atproto_identity')->values($params)->executeStatement();
		$qb = $this->db->getQueryBuilder(); $qb->insert('social_atproto_repo')->values(['did' => $qb->createNamedParameter($this->did), 'updated' => $qb->createNamedParameter(gmdate('Y-m-d H:i:s'))])->executeStatement();
		Server::get(Repository::class)->commit($this->did, $key['private']);
	}
	protected function tearDown(): void {
		Server::get(ConfigService::class)->setAppValue(ConfigService::ATPROTO_ENABLED, $this->enabled); $this->db->rollBack();
	}
	private function publish(string $text, string $visibility = 'public', string $parent = '', string $target = ''): \OCA\Social\Model\ActivityPub\Stream {
		$post = new Post($this->actor); $post->setContent($text); $post->setType($visibility); $post->setReplyTo($parent); $post->setPublishTarget($target);
		$result = Server::get(PostService::class)->createPost($post); self::assertNotNull($result);
		return Server::get(StreamRequest::class)->getStreamById($result->getObjectId());
	}
	public function testComposerPublicationRepliesAndDeletionReachTheNativeRepository(): void {
		$parent = $this->publish('Grüße aus Social 😀 https://example.org/');
		Server::get(OutboundWorker::class)->run(); $repo = Server::get(Repository::class); $records = $repo->getRecords($this->did, 'app.bsky.feed.post');
		self::assertCount(1, $records); $record = reset($records); self::assertSame((string)$parent->getNid(), $record->localId); self::assertStringContainsString('Grüße aus Social', $record->getValue()['text']);
		$identity = Server::get(IdentityService::class)->getIdentityByDid($this->did); $repo->verify($this->did, $identity['signing_public']);
		self::assertNotNull($repo->getRecord($this->did, 'app.bsky.actor.profile', 'self'));
		$this->publish('Native Antwort', 'public', $parent->getId()); Server::get(OutboundWorker::class)->run(); $records = $repo->getRecords($this->did, 'app.bsky.feed.post'); self::assertCount(2, $records);
		$reply = array_values(array_filter($records, static fn ($r) => isset($r->getValue()['reply']))); self::assertCount(1, $reply);
		self::assertEquals(['uri' => $record->getAtUri(), 'cid' => $record->cid], $reply[0]->getValue()['reply']['parent']);
		// Optional artifacts contain only public data and public keys for the independent
		// reference implementation, never private keys or recovery material.
		$export = getenv('ATPROTO_INTEROP_EXPORT_DIR');
		if ($export !== false && $export !== '') {
			if (!is_dir($export)) { mkdir($export, 0700, true); }
			file_put_contents($export . '/publication.car', $repo->exportCar($this->did));
			file_put_contents($export . '/publication.json', json_encode(['did' => $this->did, 'signingPublic' => $identity['signing_public'], 'posts' => 2], JSON_THROW_ON_ERROR));
		}
		Server::get(StreamService::class)->deleteLocalItem($parent); Server::get(OutboundWorker::class)->run(); self::assertNull($repo->getRecord($this->did, $record->collection, $record->rkey)); $repo->verify($this->did, $identity['signing_public']);
	}
	public function testEveryPrivateVisibilityStaysOutOfNativeRecords(): void {
		foreach (['unlisted', 'followers', 'direct'] as $visibility) { $this->publish('PRIVATE ' . $visibility, $visibility); }
		Server::get(OutboundWorker::class)->run(); self::assertSame([], Server::get(Repository::class)->getRecords($this->did, 'app.bsky.feed.post'));
	}
	public function testExplicitTransportChoicesAreStoredAndRespectedByTheOutbox(): void {
		$fediverse = $this->publish('Fediverse only', 'public', '', 'fediverse');
		$native = $this->publish('ATProto only', 'public', '', 'atproto');
		$both = $this->publish('Both networks', 'public', '', 'both');
		Server::get(OutboundWorker::class)->run();
		$records = Server::get(Repository::class)->getRecords($this->did, 'app.bsky.feed.post');
		self::assertCount(2, $records); $ids = array_map(static fn ($r) => $r->localId, $records);
		self::assertNotContains((string)$fediverse->getNid(), $ids); self::assertContains((string)$native->getNid(), $ids); self::assertContains((string)$both->getNid(), $ids);
		self::assertSame(['fediverse' => false, 'atproto' => true], $native->getDetails(\OCA\Social\Model\Details::PUBLICATION));
		// Re-importing a stale indexed copy must neither duplicate an owned post nor
		// resurrect it after its local original was withdrawn.
		Server::get(StreamService::class)->deleteLocalItem($native); Server::get(OutboundWorker::class)->run();
		self::assertCount(1, Server::get(Repository::class)->getRecords($this->did, 'app.bsky.feed.post'));
	}

	public function testAnOrdinarySocialImageBecomesAReadableNativeBlobWithAltText(): void {
		$document = new \OCA\Social\Model\ActivityPub\Object\Document();
		$document->setLocal(true)->setAccount($this->actor->getPreferredUsername())->setUrlCloud('https://pds.example');
		$document->generateUniqueId('/documents/local'); $document->setPublic(true)->setDescription('A red rectangle');
		$file = tempnam(sys_get_temp_dir(), 'social-native-image-'); $image = imagecreatetruecolor(40, 20);
		imagefilledrectangle($image, 0, 0, 39, 19, imagecolorallocate($image, 200, 60, 30)); imagepng($image, $file); unset($image);
		try { Server::get(\OCA\Social\Service\CacheDocumentService::class)->saveFromTempToCache($document, $file); } finally { unlink($file); }
		Server::get(\OCA\Social\Db\CacheDocumentsRequest::class)->save($document);
		$post = new Post($this->actor); $post->setContent('Photo from the shared composer'); $post->setType('public'); $post->setPublishTarget('atproto');
		$post->setMedias([$document->convertToMediaAttachment(Server::get(\OCP\IURLGenerator::class), \OCA\Social\Model\ActivityPub\ACore::FORMAT_ACTIVITYPUB)]);
		Server::get(PostService::class)->createPost($post); Server::get(OutboundWorker::class)->run();
		$records = Server::get(Repository::class)->getRecords($this->did, 'app.bsky.feed.post'); self::assertCount(1, $records);
		$value = reset($records)->getValue(); self::assertSame('app.bsky.embed.images', $value['embed']['$type']);
		self::assertSame('A red rectangle', $value['embed']['images'][0]['alt']);
		$cid = $value['embed']['images'][0]['image']['ref']['$link']; $blob = Server::get(\OCA\Social\Atproto\Repository\BlobService::class)->read($this->did, $cid);
		self::assertSame('image/jpeg', $blob['mime']); self::assertSame($cid, Cid::hash($blob['bytes'], 0x55));
		self::assertSame(40, getimagesizefromstring($blob['bytes'])[0]);
	}

	public function testSocialAccountCleanupDeactivatesThePdsAndQueuesAnAccountEvent(): void {
		$this->publish('Before deleting the account'); Server::get(OutboundWorker::class)->run();
		Server::get(\OCA\Social\Service\ActorCascadeService::class)->purge($this->actor->getId());
		self::assertSame(IdentityService::STATE_DEACTIVATED, Server::get(IdentityService::class)->getIdentityByDid($this->did)['state']);
		$event = $this->latestEvent(); self::assertSame('#account', $event['kind']);
		$body = \OCA\Social\Atproto\Protocol\DagCbor::decode(Repository::bytes($event['bytes']));
		self::assertSame(false, $body['active']); self::assertSame('deactivated', $body['status']);
	}

	public function testInductiveDeleteCarriesThePreviousMstAndRecordCids(): void {
		$post = $this->publish('Delete proof'); Server::get(OutboundWorker::class)->run();
		$repo = Server::get(Repository::class); $before = $repo->getHead($this->did);
		$commit = \OCA\Social\Atproto\Protocol\DagCbor::decode($repo->getBlock($this->did, $before['commit_cid']));
		$record = array_values($repo->getRecords($this->did, 'app.bsky.feed.post'))[0];
		Server::get(StreamService::class)->deleteLocalItem($post); Server::get(OutboundWorker::class)->run();
		$event = $this->latestEvent(); $body = \OCA\Social\Atproto\Protocol\DagCbor::decode(Repository::bytes($event['bytes']));
		self::assertSame('#commit', $event['kind']); self::assertSame($before['rev'], $body['since']);
		self::assertSame($commit['data']->value, $body['prevData']->value);
		self::assertCount(1, $body['ops']); self::assertSame('delete', $body['ops'][0]['action']);
		self::assertSame($record->cid, $body['ops'][0]['prev']->value); self::assertNull($body['ops'][0]['cid']);
	}

	public function testLargeBatchesUseASignedSyncEventForRelayResynchronization(): void {
		$repo = Server::get(Repository::class);
		for ($i = 0; $i < 201; $i++) { $repo->createRecord($this->did, 'app.bsky.feed.post', 'batch' . $i, ['$type' => 'app.bsky.feed.post', 'text' => 'Batch ' . $i, 'createdAt' => gmdate('Y-m-d\TH:i:s\Z')]); }
		$repo->commit($this->did, Server::get(IdentityService::class)->getSigningKey($this->actor->getId()));
		$event = $this->latestEvent(); self::assertSame('#sync', $event['kind']);
		$body = \OCA\Social\Atproto\Protocol\DagCbor::decode(Repository::bytes($event['bytes']));
		self::assertSame($this->did, $body['did']); self::assertSame($repo->getHead($this->did)['rev'], $body['rev']);
		self::assertNotEmpty($body['blocks']->value); $repo->verify($this->did, Server::get(IdentityService::class)->getIdentityByDid($this->did)['signing_public']);
	}

	private function latestEvent(): array {
		$qb = $this->db->getQueryBuilder(); $qb->select('*')->from('social_atproto_event')->where($qb->expr()->eq('did', $qb->createNamedParameter($this->did)))->orderBy('seq', 'DESC')->setMaxResults(1);
		return $qb->executeQuery()->fetchAssociative();
	}

}
