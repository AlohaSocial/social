<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\Sync;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Service\CurlService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCP\IConfig;

class AtprotoNotifications {
	public const DEFAULT_BATCH = 50;
	public const DEFAULT_CEILING = 200;
	public const APPVIEW_URL = 'https://api.bsky.app'; // Authenticated AppView for notifications
	
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly IConfig $config,
		private readonly CurlService $curlService,
		private readonly IdentityService $identityService
	) {}
	
	public function run(int $batch = self::DEFAULT_BATCH, bool $dryRun = false): array {
		$startTime = microtime(true);
		
		$ceiling = $this->config->getAppValue('social', 'atproto_sync_ceiling', self::DEFAULT_CEILING);
		$batch = min($batch, $ceiling);
		
		// Get local accounts with Bluesky identities
		$accounts = $this->getAccountsWithIdentities($batch);
		
		$stats = [
			'accounts_checked' => 0,
			'new_notifications' => 0,
			'errors' => 0,
			'duration_ms' => 0
		];
		
		foreach ($accounts as $account) {
			if ($stats['accounts_checked'] >= $ceiling) {
				break;
			}
			
			try {
				$result = $this->fetchNotifications($account, $dryRun);
				$stats['accounts_checked']++;
				$stats['new_notifications'] += $result['count'] ?? 0;
				
				// Update cursor
				$this->updateCursor($account['did'], $result['cursor'] ?? '');
			} catch (\Throwable $e) {
				$stats['errors']++;
				$this->logger->error('Failed to fetch notifications', [
					'did' => $account['did'],
					'error' => $e->getMessage()
				]);
			}
		}
		
		$stats['duration_ms'] = (int)((microtime(true) - $startTime) * 1000);
		return $stats;
	}
	
	private function getAccountsWithIdentities(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ai.did, ai.actor_id, nc.cursor, nc.last_sync, nc.next_sync, nc.failures')
			->from('social_atproto_identity', 'ai')
			->leftJoin('ai', 'social_atproto_notify_cursor', 'nc', 'ai.did = nc.did')
			->where($qb->expr()->eq('ai.state', $qb->createNamedParameter('active')))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('nc.next_sync'),
				$qb->expr()->lte('nc.next_sync', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
			))
			->orderBy('nc.next_sync', 'ASC')
			->setMaxResults($limit);
		
		return $qb->executeQuery()->fetchAllAssociative();
	}
	
	private function fetchNotifications(array $account, bool $dryRun): array {
		$did = $account['did'];
		$cursor = $account['cursor'] ?? '';
		
		// Get service auth token for this user
		$serviceAuth = $this->getServiceAuthToken($did);
		
		$url = self::APPVIEW_URL . '/xrpc/app.bsky.notification.listNotifications';
		
		$params = ['limit' => 50];
		if ($cursor) {
			$params['cursor'] = $cursor;
		}
		
		$response = $this->curlService->get($url, $params, [
			'Authorization' => 'Bearer ' . $serviceAuth,
			'Atproto-Accept-Labelers' => $this->getLabelersHeader($account['actor_id'] ?? 0)
		]);
		
		$data = json_decode($response, true);
		
		if (!$data || !isset($data['notifications'])) {
			return ['cursor' => $cursor, 'count' => 0];
		}
		
		$newCursor = $data['cursor'] ?? $cursor;
		$count = 0;
		
		foreach ($data['notifications'] as $notification) {
			if (!$dryRun) {
				$this->processNotification($notification, $account['actor_id'] ?? 0);
			}
			$count++;
		}
		
		return ['cursor' => $newCursor, 'count' => $count];
	}
	
	private function processNotification(array $notification, int $localActorId): void {
		$type = $notification['reason'] ?? '';
		$authorDid = $notification['author']['did'] ?? '';
		
		switch ($type) {
			case 'follow':
				$this->processFollow($notification, $localActorId);
				break;
			case 'like':
				$this->processLike($notification, $localActorId);
				break;
			case 'repost':
				$this->processRepost($notification, $localActorId);
				break;
			case 'reply':
			case 'mention':
				$this->processReplyOrMention($notification, $localActorId);
				break;
			case 'quote':
				$this->processQuote($notification, $localActorId);
				break;
		}
	}
	
	private function processFollow(array $notification, int $localActorId): void {
		// Create Follow activity
		// Bluesky follows are automatic (no approval needed)
	}
	
	private function processLike(array $notification, int $localActorId): void {
		// Create Like activity
	}
	
	private function processRepost(array $notification, int $localActorId): void {
		// Create Announce (boost) activity
	}
	
	private function processReplyOrMention(array $notification, int $localActorId): void {
		// Fetch the reply/mention post and store it
		$postUri = $notification['subject']['uri'] ?? '';
		if ($postUri) {
			$this->fetchAndStorePost($postUri);
		}
	}
	
	private function processQuote(array $notification, int $localActorId): void {
		// Fetch the quote post and store it
		$postUri = $notification['subject']['uri'] ?? '';
		if ($postUri) {
			$this->fetchAndStorePost($postUri);
		}
	}
	
	private function fetchAndStorePost(string $uri): void {
		// Fetch post from AppView and store
	}
	
	private function updateCursor(string $did, string $cursor): void {
		$qb = $this->db->getQueryBuilder();
		$qb->upsert('social_atproto_notify_cursor')
			->set('did', $qb->createNamedParameter($did))
			->set('cursor', $qb->createNamedParameter($cursor))
			->set('last_sync', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
			->set('failures', $qb->createNamedParameter(0, \PDO::PARAM_INT))
			->executeStatement();
	}
	
	private function getServiceAuthToken(string $did): string {
		// Generate service auth JWT for the user
		// aud: did:web:api.bsky.app
		// lxm: app.bsky.notification.listNotifications
		return 'service-auth-jwt';
	}
	
	private function getLabelersHeader(int $actorId): string {
		// Get user's subscribed labelers
		return '';
	}
}