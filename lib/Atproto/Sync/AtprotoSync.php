<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\Sync;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Repository\Repository;
use OCA\Social\Atproto\RecordMapper\RecordMapper;
use OCA\Social\Service\CurlService;
use OCP\IDBConnection;
use OCP\ILogger;
use OCP\IConfig;

class AtprotoSync {
	public const DEFAULT_BATCH = 50;
	public const DEFAULT_CEILING = 200;
	public const APPVIEW_URL = 'https://public.api.bsky.app';
	
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ILogger $logger,
		private readonly IConfig $config,
		private readonly CurlService $curlService,
		private readonly IdentityService $identityService,
		private readonly Repository $repository,
		private readonly RecordMapper $recordMapper
	) {}
	
	public function run(int $batch = self::DEFAULT_BATCH, bool $dryRun = false): array {
		$startTime = microtime(true);
		
		$ceiling = $this->config->getAppValue('social', 'atproto_sync_ceiling', self::DEFAULT_CEILING);
		$batch = min($batch, $ceiling);
		
		// Get watches due for sync
		$watches = $this->getDueWatches($batch);
		
		$stats = [
			'authors_checked' => 0,
			'new_posts' => 0,
			'updated_posts' => 0,
			'errors' => 0,
			'duration_ms' => 0
		];
		
		foreach ($watches as $watch) {
			if ($stats['authors_checked'] >= $ceiling) {
				break;
			}
			
			try {
				$result = $this->syncWatch($watch, $dryRun);
				$stats['authors_checked']++;
				$stats['new_posts'] += $result['new'] ?? 0;
				$stats['updated_posts'] += $result['updated'] ?? 0;
				
				// Update watch cursor and next_sync
				$this->updateWatch($watch['did'], $result['cursor'] ?? $watch['cursor'], $result['nextSync'] ?? null);
			} catch (\Throwable $e) {
				$stats['errors']++;
				$this->logger->error('Failed to sync watch', [
					'did' => $watch['did'],
					'error' => $e->getMessage()
				]);
				$this->incrementWatchFailures($watch['did']);
			}
		}
		
		$stats['duration_ms'] = (int)((microtime(true) - $startTime) * 1000);
		return $stats;
	}
	
	private function getDueWatches(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_watch')
			->where($qb->expr()->lte('next_sync', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s'))))
			->orderBy('next_sync', 'ASC')
			->setMaxResults($limit);
		
		return $qb->executeQuery()->fetchAllAssociative();
	}
	
	private function syncWatch(array $watch, bool $dryRun): array {
		$cursor = $watch['cursor'] ?? '';
		$url = self::APPVIEW_URL . '/xrpc/app.bsky.feed.getAuthorFeed';
		
		$params = [
			'actor' => $watch['did'],
			'filter' => 'posts_with_replies',
			'limit' => 50
		];
		
		if ($cursor) {
			$params['cursor'] = $cursor;
		}
		
		$response = $this->curlService->get($url, $params);
		$data = json_decode($response, true);
		
		if (!$data || !isset($data['feed'])) {
			// Empty or error response - back off
			return [
				'cursor' => $cursor,
				'nextSync' => $this->calculateNextSync($watch['failures'] + 1),
				'new' => 0,
				'updated' => 0
			];
		}
		
		$newPosts = 0;
		$updatedPosts = 0;
		$newCursor = $data['cursor'] ?? $cursor;
		
		foreach ($data['feed'] as $item) {
			if (!isset($item['post'])) continue;
			
			$post = $item['post'];
			$uri = $post['uri'] ?? '';
			$cid = $post['cid'] ?? '';
			
			if (!$uri || !$cid) continue;
			
			// Check if we already have this post
			$existing = $this->getStreamItemByAtUri($uri);
			
			if ($existing) {
				// Update if needed
				if ($existing['details']['atproto']['cid'] !== $cid) {
					if (!$dryRun) {
						$this->storeBlueskyPost($post, $watch['did']);
					}
					$updatedPosts++;
				}
			} else {
				// New post
				if (!$dryRun) {
					$this->storeBlueskyPost($post, $watch['did']);
				}
				$newPosts++;
			}
		}
		
		// Calculate next sync time with backoff
		$hasPosts = ($newPosts + $updatedPosts) > 0;
		$nextSync = $this->calculateNextSync($hasPosts ? 0 : ($watch['failures'] + 1));
		
		return [
			'cursor' => $newCursor,
			'nextSync' => $nextSync,
			'new' => $newPosts,
			'updated' => $updatedPosts
		];
	}
	
	private function calculateNextSync(int $failures): string {
		if ($failures <= 0) {
			// Has posts - sync again in 1 interval (2 minutes)
			return (new \DateTime('+2 minutes'))->format('Y-m-d H:i:s');
		}
		
		// Exponential backoff: 2min, 4min, 8min, 16min, 32min, 1hr, 2hr, 4hr, 6hr (max)
		$intervals = [2, 4, 8, 16, 32, 60, 120, 240, 360];
		$index = min($failures - 1, count($intervals) - 1);
		$minutes = $intervals[$index];
		
		return (new \DateTime("+{$minutes} minutes"))->format('Y-m-d H:i:s');
	}
	
	private function updateWatch(string $did, string $cursor, ?string $nextSync): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('social_atproto_watch')
			->set('cursor', $qb->createNamedParameter($cursor))
			->set('last_sync', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
			->set('failures', $qb->createNamedParameter(0, \PDO::PARAM_INT))
			->set('last_error', $qb->createNamedParameter(''));
		
		if ($nextSync) {
			$qb->set('next_sync', $qb->createNamedParameter($nextSync));
		}
		
		$qb->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->executeStatement();
	}
	
	private function incrementWatchFailures(string $did): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('social_atproto_watch')
			->set('failures', $qb->expr()->add('failures', $qb->createNamedParameter(1, \PDO::PARAM_INT)))
			->set('last_error', $qb->createNamedParameter('Sync failed'))
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->executeStatement();
	}
	
	private function getStreamItemByAtUri(string $atUri): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_stream')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($atUri)));
		
		return $qb->executeQuery()->fetchAssociative() ?: null;
	}
	
	private function storeBlueskyPost(array $post, string $authorDid): void {
		// Convert Bluesky post to Social stream item
		// This uses the ImportService path
		$record = $post['record'] ?? [];
		
		$streamItem = [
			'id' => $post['uri'],
			'id_prim' => $this->generateIdPrim($post['uri']),
			'type' => 'Note',
			'url' => $this->atUriToUrl($post['uri']),
			'attributed_to' => $authorDid, // Would resolve to cached actor
			'content' => $this->rebuildContentFromRecord($record),
			'published' => $post['indexedAt'] ?? (new \DateTime())->format('c'),
			'local' => 0,
			'details' => json_encode([
				'atproto' => [
					'cid' => $post['cid'],
					'uri' => $post['uri'],
					'indexedAt' => $post['indexedAt'] ?? ''
				]
			])
		];
		
		// Insert into social_stream
		$qb = $this->db->getQueryBuilder();
		$qb->insert('social_stream')
			->values($streamItem)
			->executeStatement();
		
		// Also store in social_atproto_record for local reference
		// (This would be done by the RecordMapper)
	}
	
	private function generateIdPrim(string $uri): int {
		// Generate a unique integer ID for the stream item
		return crc32($uri) & 0x7fffffff;
	}
	
	private function atUriToUrl(string $atUri): string {
		// at://did:plc:xyz/app.bsky.feed.post/abc -> https://bsky.app/profile/handle/post/abc
		// For now, construct generic URL
		return 'https://bsky.app/profile/' . $atUri;
	}
	
	private function rebuildContentFromRecord(array $record): string {
		$text = $record['text'] ?? '';
		$facets = $record['facets'] ?? [];
		
		// Rebuild HTML from text and facets
		// This is simplified - real implementation would create proper links/mentions
		return $text;
	}
}