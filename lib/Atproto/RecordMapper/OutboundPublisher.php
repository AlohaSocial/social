<?php
declare(strict_types=1);
namespace OCA\Social\Atproto\RecordMapper;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Repository\{Repository, Record};
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Db\{StreamRequest, ActorsRequest};
use OCA\Social\Exceptions\ItemUnknownException;
use OCP\IDBConnection;
class OutboundPublisher {
	public function __construct(private readonly IdentityService $identities, private readonly Repository $repository,
		private readonly RecordMapper $mapper, private readonly StreamRequest $streams, private readonly ActorsRequest $actors, private readonly IDBConnection $db) {}
	public function publishPost(string $nid): void {
		if (!$this->identities->isEnabled()) { return; }
		try { $post = $this->streams->getStreamByNid($nid); } catch (ItemUnknownException) { $this->deletePost($nid); return; }
		if (!$post->isLocal() || $post->getVisibility() !== 'public' || !$post->addressesPublic()) { $this->deletePost($nid); return; }
		$identity = $this->identities->getIdentityByActor($post->getAttributedTo()) ?? $this->identities->createIdentity($post->getAttributedTo());
		if ($identity['state'] !== IdentityService::STATE_ACTIVE) { $this->identities->registerPending($identity['did']); $identity = $this->identities->getIdentityByDid($identity['did']); }
		if ($identity['state'] !== IdentityService::STATE_ACTIVE) { throw new \RuntimeException('Identity is awaiting PLC registration'); }
		$existing = $this->localRecord($nid); $mapped = $this->mapper->map($post, $identity['did']);
		if ($mapped === null) { return; }
		if ($existing !== null) {
			$old = Record::decodeCbor(Repository::bytes($existing['bytes']));
			if ($old === $mapped['record'] || time() - $post->getPublishedTime() > 300) { return; }
		}
		$key = $this->identities->getSigningKey($identity['actor_id']);
		$this->repository->transaction(function () use ($existing, $identity, $mapped, $key, $nid) {
			if ($existing !== null) { $this->repository->deleteRecord($existing['did'], $existing['collection'], $existing['rkey']); }
			$this->repository->createRecord($identity['did'], $mapped['collection'], $mapped['rkey'], $mapped['record'], $nid);
			$actor = $this->actors->getFromUserId($this->userId($identity['actor_id']));
			if (!$this->repository->getRecord($identity['did'], 'app.bsky.actor.profile', 'self')) {
				$this->repository->createRecord($identity['did'], 'app.bsky.actor.profile', 'self', ['$type' => 'app.bsky.actor.profile', 'displayName' => grapheme_substr($actor->getName(), 0, 64), 'description' => grapheme_substr(RecordMapper::plainText($actor->getSummary()), 0, 256)]);
			}
			$this->repository->commit($identity['did'], $key);
		});
	}
	public function deletePost(string $nid): void {
		$row = $this->localRecord($nid); if (!$row || !$this->identities->isEnabled()) { return; }
		$identity = $this->identities->getIdentityByDid($row['did']); $key = $this->identities->getSigningKey($identity['actor_id']);
		$this->repository->transaction(function () use ($row, $key) { $this->repository->deleteRecord($row['did'], $row['collection'], $row['rkey']); $this->repository->commit($row['did'], $key); });
	}
	private function localRecord(string $nid): ?array {
		$qb = $this->db->getQueryBuilder(); $qb->select('*')->from('social_atproto_record')->where($qb->expr()->eq('local_id', $qb->createNamedParameter($nid)))->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter('app.bsky.feed.post')));
		return $qb->executeQuery()->fetchAssociative() ?: null;
	}
	private function userId(string $actorId): string {
		$qb = $this->db->getQueryBuilder(); $qb->select('user_id')->from('social_actor')->where($qb->expr()->eq('id', $qb->createNamedParameter($actorId))); return (string)$qb->executeQuery()->fetchOne();
	}
}
