<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\RecordMapper;

use OCA\Social\Db\QueueRequest;
use OCA\Social\Tools\Nid;
use OCP\IDBConnection;
use OCP\ILogger;

class OutboundQueue {
	public const QUEUE_NAME = 'atproto_outbound';
	public const MAX_RETRIES = 3;
	
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ILogger $logger,
		private readonly QueueRequest $queueRequest
	) {}
	
	/**
	 * Queue a post for publishing to Bluesky
	 */
	public function queuePost(string $nid): void {
		$postId = Nid::fromStorage($nid)->getId();
		
		$this->queueRequest->addItem([
			'queue' => self::QUEUE_NAME,
			'payload' => json_encode([
				'action' => 'publish_post',
				'postId' => $postId,
			]),
			'priority' => 5,
		]);
	}
	
	/**
	 * Queue a follow for publishing to Bluesky
	 */
	public function queueFollow(int $actorId, string $targetDid): void {
		$this->queueRequest->addItem([
			'queue' => self::QUEUE_NAME,
			'payload' => json_encode([
				'action' => 'publish_follow',
				'actorId' => $actorId,
				'targetDid' => $targetDid,
			]),
			'priority' => 5,
		]);
	}
	
	/**
	 * Queue a like for publishing to Bluesky
	 */
	public function queueLike(int $actorId, string $postUri, string $postCid): void {
		$this->queueRequest->addItem([
			'queue' => self::QUEUE_NAME,
			'payload' => json_encode([
				'action' => 'publish_like',
				'actorId' => $actorId,
				'postUri' => $postUri,
				'postCid' => $postCid,
			]),
			'priority' => 5,
		]);
	}
	
	/**
	 * Queue a repost for publishing to Bluesky
	 */
	public function queueRepost(int $actorId, string $postUri, string $postCid): void {
		$this->queueRequest->addItem([
			'queue' => self::QUEUE_NAME,
			'payload' => json_encode([
				'action' => 'publish_repost',
				'actorId' => $actorId,
				'postUri' => $postUri,
				'postCid' => $postCid,
			]),
			'priority' => 5,
		]);
	}
	
	/**
	 * Queue a delete for a Bluesky post
	 */
	public function queueDelete(int $actorId, string $postUri): void {
		$this->queueRequest->addItem([
			'queue' => self::QUEUE_NAME,
			'payload' => json_encode([
				'action' => 'delete_post',
				'actorId' => $actorId,
				'postUri' => $postUri,
			]),
			'priority' => 5,
		]);
	}
}