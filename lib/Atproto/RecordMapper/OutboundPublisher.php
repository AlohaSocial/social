<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\RecordMapper;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Repository\Repository;
use OCP\IDBConnection;
use OCP\ILogger;

class OutboundPublisher {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ILogger $logger,
		private readonly IdentityService $identityService,
		private readonly Repository $repository,
		private readonly RecordMapper $recordMapper
	) {}
	
	public function publishPost(int $postId): void {
		$mapped = $this->recordMapper->mapPost($postId);
		if (!$mapped) {
			return; // Not a public post or no Bluesky identity
		}
		
		$identity = $this->identityService->getIdentityByActor(
			$this->getPostActorId($postId)
		);
		
		if (!$identity) {
			return;
		}
		
		$did = $identity['did'];
		$signingKey = $this->identityService->getSigningKey($identity['actor_id']);
		
		if (!$signingKey) {
			$this->logger->error('No signing key for identity', ['did' => $did]);
			return;
		}
		
		// Create record in repository
		$record = $this->repository->createRecord(
			$did,
			$mapped['collection'],
			$mapped['rkey'],
			$mapped['record'],
			$postId
		);
		
		// Commit
		$this->repository->commit($did, $signingKey);
		
		$this->logger->info('Published post to Bluesky', [
			'post_id' => $postId,
			'did' => $did,
			'uri' => $record->getAtUri()
		]);
	}
	
	public function deletePost(int $postId): void {
		$record = $this->findBlueskyRecord($postId);
		if (!$record) {
			return;
		}
		
		$identity = $this->identityService->getIdentityByDid($record['did']);
		if (!$identity) {
			return;
		}
		
		$signingKey = $this->identityService->getSigningKey($identity['actor_id']);
		if (!$signingKey) {
			return;
		}
		
		// Delete the post record
		$this->repository->deleteRecord($record['did'], $record['collection'], $record['rkey']);
		
		// Also delete associated likes and reposts by local actors
		$this->deleteLocalInteractions($record['did'], $record['rkey']);
		
		// Commit
		$this->repository->commit($record['did'], $signingKey);
	}
	
	public function editPost(int $postId): void {
		$record = $this->findBlueskyRecord($postId);
		if (!$record) {
			return;
		}
		
		$post = $this->getPost($postId);
		if (!$post) {
			return;
		}
		
		$identity = $this->identityService->getIdentityByDid($record['did']);
		if (!$identity) {
			return;
		}
		
		$signingKey = $this->identityService->getSigningKey($identity['actor_id']);
		if (!$signingKey) {
			return;
		}
		
		// Check if within 5-minute grace period
		$createdAt = new \DateTime($post['created_at']);
		$now = new \DateTime();
		$diff = $now->getTimestamp() - $createdAt->getTimestamp();
		
		if ($diff <= 300) { // 5 minutes
			// Delete old record and create new one
			$this->repository->deleteRecord($record['did'], $record['collection'], $record['rkey']);
			
			// Publish new version
			$this->publishPost($postId);
		} else {
			// Past grace period - leave Bluesky record as-is
			// The link in the record points to the current version here
			$this->logger->info('Post edited past grace period, Bluesky copy unchanged', [
				'post_id' => $postId,
				'age_seconds' => $diff
			]);
		}
	}
	
	public function publishLike(int $actorId, int $postId): void {
		$post = $this->getPost($postId);
		if (!$post) return;
		
		$postRecord = $this->findBlueskyRecord($postId);
		if (!$postRecord) {
			// Check if it's a Bluesky post (has at:// URI in stream)
			$streamItem = $this->getStreamItem($postId);
			if (!$streamItem || !str_starts_with($streamItem['id'], 'at://')) {
				return; // Not a Bluesky post
			}
			
			$postRecord = [
				'did' => $this->extractDidFromAtUri($streamItem['id']),
				'collection' => 'app.bsky.feed.post',
				'rkey' => $this->extractRkeyFromAtUri($streamItem['id']),
				'uri' => $streamItem['id'],
				'cid' => $streamItem['details']['atproto']['cid'] ?? ''
			];
		}
		
		$identity = $this->identityService->getIdentityByActor($actorId);
		if (!$identity) return;
		
		$signingKey = $this->identityService->getSigningKey($actorId);
		if (!$signingKey) return;
		
		// Create like record
		$likeRecord = [
			'$type' => 'app.bsky.feed.like',
			'subject' => [
				'uri' => $postRecord['uri'],
				'cid' => $postRecord['cid']
			],
			'createdAt' => (new \DateTime())->format('c')
		];
		
		$rkey = $this->generateTid();
		$this->repository->createRecord(
			$identity['did'],
			'app.bsky.feed.like',
			$rkey,
			$likeRecord
		);
		
		$this->repository->commit($identity['did'], $signingKey);
	}
	
	public function deleteLike(int $actorId, int $postId): void {
		$postRecord = $this->findBlueskyRecord($postId);
		if (!$postRecord) {
			$streamItem = $this->getStreamItem($postId);
			if (!$streamItem || !str_starts_with($streamItem['id'], 'at://')) {
				return;
			}
			$postRecord = [
				'did' => $this->extractDidFromAtUri($streamItem['id']),
				'collection' => 'app.bsky.feed.post',
				'rkey' => $this->extractRkeyFromAtUri($streamItem['id'])
			];
		}
		
		$identity = $this->identityService->getIdentityByActor($actorId);
		if (!$identity) return;
		
		$signingKey = $this->identityService->getSigningKey($actorId);
		if (!$signingKey) return;
		
		// Find and delete the like record
		$likeRecord = $this->findLikeRecord($identity['did'], $postRecord['uri']);
		if ($likeRecord) {
			$this->repository->deleteRecord($identity['did'], 'app.bsky.feed.like', $likeRecord['rkey']);
			$this->repository->commit($identity['did'], $signingKey);
		}
	}
	
	public function publishRepost(int $actorId, int $postId): void {
		// Similar to publishLike but for app.bsky.feed.repost
	}
	
	public function publishFollow(int $actorId, string $targetDid): void {
		$identity = $this->identityService->getIdentityByActor($actorId);
		if (!$identity) return;
		
		$signingKey = $this->identityService->getSigningKey($actorId);
		if (!$signingKey) return;
		
		$followRecord = [
			'$type' => 'app.bsky.graph.follow',
			'subject' => $targetDid,
			'createdAt' => (new \DateTime())->format('c')
		];
		
		$rkey = $this->generateTid();
		$this->repository->createRecord(
			$identity['did'],
			'app.bsky.graph.follow',
			$rkey,
			$followRecord
		);
		
		$this->repository->commit($identity['did'], $signingKey);
	}
	
	private function findBlueskyRecord(int $postId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_record')
			->where($qb->expr()->eq('local_id', $qb->createNamedParameter($postId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter('app.bsky.feed.post')));
		
		return $qb->executeQuery()->fetchAssociative() ?: null;
	}
	
	private function getPostActorId(int $postId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('actor_id')
			->from('social_post')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($postId, \PDO::PARAM_INT)));
		return (int)($qb->executeQuery()->fetchOne() ?? 0);
	}
	
	private function getStreamItem(int $postId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_stream')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($postId)));
		return $qb->executeQuery()->fetchAssociative() ?: null;
	}
	
	private function findLikeRecord(string $did, string $subjectUri): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_record')
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter('app.bsky.feed.like')));
		
		$results = $qb->executeQuery()->fetchAllAssociative();
		
		foreach ($results as $record) {
			$value = json_decode($record['bytes'], true);
			if (($value['subject']['uri'] ?? '') === $subjectUri) {
				return $record;
			}
		}
		
		return null;
	}
	
	private function deleteLocalInteractions(string $did, string $postRkey): void {
		// Delete likes and reposts by local actors for this post
		$qb = $this->db->getQueryBuilder();
		$qb->select('actor_id')
			->from('social_atproto_identity')
			->where($qb->expr()->eq('state', $qb->createNamedParameter('active')));
		
		$localActors = $qb->executeQuery()->fetchAllAssociative();
		
		foreach ($localActors as $actor) {
			$actorIdentity = $this->identityService->getIdentityByActor($actor['actor_id']);
			if (!$actorIdentity) continue;
			
			// Delete like
			$likeRecord = $this->findLikeRecord($actorIdentity['did'], "at://{$did}/app.bsky.feed.post/{$postRkey}");
			if ($likeRecord) {
				$this->repository->deleteRecord($actorIdentity['did'], 'app.bsky.feed.like', $likeRecord['rkey']);
			}
			
			// Delete repost (similar)
		}
	}
	
	private function extractDidFromAtUri(string $atUri): string {
		// at://did:plc:xyz/app.bsky.feed.post/abc
		preg_match('/^at:\/\/([^\/]+)/', $atUri, $matches);
		return $matches[1] ?? '';
	}
	
	private function extractRkeyFromAtUri(string $atUri): string {
		preg_match('/\/([^\/]+)$/', $atUri, $matches);
		return $matches[1] ?? '';
	}
	
	private function generateTid(): string {
		$microtime = (int)(microtime(true) * 1000000);
		$bytes = '';
		for ($i = 5; $i >= 0; $i--) {
			$bytes .= chr(($microtime >> ($i * 8)) & 0xFF);
		}
		$bytes = str_pad($bytes, 8, "\0", STR_PAD_LEFT);
		$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
		$bits = '';
		foreach (str_split($bytes) as $char) {
			$bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
		}
		$bits = str_pad($bits, (int)ceil(strlen($bits) / 5) * 5, '0', STR_PAD_RIGHT);
		$result = '';
		for ($i = 0; $i < strlen($bits); $i += 5) {
			$chunk = substr($bits, $i, 5);
			$result .= $alphabet[bindec($chunk)];
		}
		return substr($result, 0, 13);
	}
}