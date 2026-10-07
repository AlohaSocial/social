<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\RecordMapper;

use OCA\Social\Db\QueueRequest;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Repository\Repository;
use OCP\IDBConnection;
use OCP\ILogger;
use OCP\IDispatcher;

class OutboundWorker {
	public const QUEUE_NAME = 'atproto_outbound';
	public const MAX_RETRIES = 3;
	
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ILogger $logger,
		private readonly QueueRequest $queueRequest,
		private readonly IdentityService $identityService,
		private readonly Repository $repository,
		private readonly RecordMapper $recordMapper
	) {}
	
	/**
	 * Process a single item from the queue
	 */
	public function process(string $action, array $payload): bool {
		$this->logger->debug('Processing atproto outbound action', ['action' => $action, 'payload' => $payload]);
		
		try {
			return match ($action) {
				'publish_post' => $this->handlePublishPost($payload['postId']),
				'publish_follow' => $this->handlePublishFollow($payload['actorId'], $payload['targetDid']),
				'publish_like' => $this->handlePublishLike($payload['actorId'], $payload['postUri'], $payload['postCid']),
				'publish_repost' => $this->handlePublishRepost($payload['actorId'], $payload['postUri'], $payload['postCid']),
				'delete_post' => $this->handleDeletePost($payload['actorId'], $payload['postUri']),
				default => throw new \InvalidArgumentException("Unknown action: $action"),
			};
		} catch (\Throwable $e) {
			$this->logger->error('Atproto outbound action failed', [
				'action' => $action,
				'payload' => $payload,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return false;
		}
	}
	
	private function handlePublishPost(int $postId): bool {
		$mapped = $this->recordMapper->mapPost($postId);
		if (!$mapped) {
			return true; // Not a public post or no Bluesky identity
		}
		
		$identity = $this->identityService->getIdentityByActor(
			$this->getPostActorId($postId)
		);
		
		if (!$identity) {
			return true;
		}
		
		$did = $identity['did'];
		$signingKey = $this->identityService->getSigningKey($identity['actor_id']);
		
		if (!$signingKey) {
			$this->logger->error('No signing key for identity', ['did' => $did]);
			return false;
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
		
		return true;
	}
	
	private function handlePublishFollow(int $actorId, string $targetDid): bool {
		$identity = $this->identityService->getIdentityByActor($actorId);
		if (!$identity) return false;
		
		$signingKey = $this->identityService->getSigningKey($actorId);
		if (!$signingKey) return false;
		
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
		
		return true;
	}
	
	private function handlePublishLike(int $actorId, string $postUri, string $postCid): bool {
		$identity = $this->identityService->getIdentityByActor($actorId);
		if (!$identity) return false;
		
		$signingKey = $this->identityService->getSigningKey($actorId);
		if (!$signingKey) return false;
		
		$likeRecord = [
			'$type' => 'app.bsky.feed.like',
			'subject' => [
				'uri' => $postUri,
				'cid' => $postCid
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
		
		return true;
	}
	
	private function handlePublishRepost(int $actorId, string $postUri, string $postCid): bool {
		$identity = $this->identityService->getIdentityByActor($actorId);
		if (!$identity) return false;
		
		$signingKey = $this->identityService->getSigningKey($actorId);
		if (!$signingKey) return false;
		
		$repostRecord = [
			'$type' => 'app.bsky.feed.repost',
			'subject' => [
				'uri' => $postUri,
				'cid' => $postCid
			],
			'createdAt' => (new \DateTime())->format('c')
		];
		
		$rkey = $this->generateTid();
		$this->repository->createRecord(
			$identity['did'],
			'app.bsky.feed.repost',
			$rkey,
			$repostRecord
		);
		
		$this->repository->commit($identity['did'], $signingKey);
		
		return true;
	}
	
	private function handleDeletePost(int $actorId, string $postUri): bool {
		$identity = $this->identityService->getIdentityByActor($actorId);
		if (!$identity) return false;
		
		$signingKey = $this->identityService->getSigningKey($actorId);
		if (!$signingKey) return false;
		
		// Extract rkey from at:// URI
		preg_match('/\/([^\/]+)$/', $postUri, $matches);
		$rkey = $matches[1] ?? '';
		
		if (!$rkey) return false;
		
		$this->repository->deleteRecord($identity['did'], 'app.bsky.feed.post', $rkey);
		$this->repository->commit($identity['did'], $signingKey);
		
		return true;
	}
	
	private function getPostActorId(int $postId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('actor_id')
			->from('social_post')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($postId, \PDO::PARAM_INT)));
		return (int)($qb->executeQuery()->fetchOne() ?? 0);
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