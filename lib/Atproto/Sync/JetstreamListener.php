<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\Sync;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Repository\Repository;
use OCA\Social\Service\CurlService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCP\IConfig;
use Ratchet\Client\WebSocketClient;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Factory;
use React\Socket\Connector;

class JetstreamListener {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly IConfig $config,
		private readonly CurlService $curlService,
		private readonly IdentityService $identityService,
		private readonly Repository $repository
	) {}
	
	public function run(bool $once = false, int $maxSeconds = 0): void {
		$jetstreamUrl = $this->config->getAppValue('social', 'atproto_jetstream', '');
		if (empty($jetstreamUrl)) {
			throw new \RuntimeException('Jetstream endpoint not configured');
		}
		
		$loop = Factory::create();
		$connector = new Connector($loop);
		
		$wantedCollections = [
			'app.bsky.feed.post',
			'app.bsky.feed.repost',
			'app.bsky.feed.like',
			'app.bsky.graph.follow'
		];
		
		// Get all watched DIDs + local DIDs
		$wantedDids = $this->getWantedDids();
		
		$cursor = $this->getCursor();
		
		$url = $jetstreamUrl . '/subscribe?' . http_build_query([
			'wantedCollections' => implode(',', $wantedCollections),
			'wantedDids' => implode(',', $wantedDids),
			'cursor' => $cursor
		]);
		
		$connector->connect($url)->then(
			function ($conn) use ($loop, $once, $maxSeconds, &$cursor) {
				$conn->on('data', function ($data) use (&$cursor) {
					$events = json_decode($data, true);
					if (is_array($events)) {
						foreach ($events as $event) {
							$this->processEvent($event);
							if (isset($event['cursor'])) {
								$cursor = $event['cursor'];
							}
						}
					}
				});
				
				$conn->on('close', function () use ($loop) {
					$loop->stop();
				});
				
				$conn->on('error', function (\Exception $e) use ($loop) {
					$this->logger->error('Jetstream connection error', ['error' => $e->getMessage()]);
					$loop->stop();
				});
			},
			function (\Exception $e) use ($loop) {
				$this->logger->error('Jetstream connection failed', ['error' => $e->getMessage()]);
				$loop->stop();
			}
		);
		
		if ($maxSeconds > 0) {
			$loop->addTimer($maxSeconds, function () use ($loop) {
				$loop->stop();
			});
		}
		
		$loop->run();
		
		// Persist cursor
		if ($cursor) {
			$this->saveCursor($cursor);
		}
	}
	
	private function getWantedDids(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('did')
			->from('social_atproto_watch');
		
		$watchedDids = array_column($qb->executeQuery()->fetchAllAssociative(), 'did');
		
		// Add local DIDs
		$qb = $this->db->getQueryBuilder();
		$qb->select('did')
			->from('social_atproto_identity')
			->where($qb->expr()->eq('state', $qb->createNamedParameter('active')));
		
		$localDids = array_column($qb->executeQuery()->fetchAllAssociative(), 'did');
		
		return array_unique(array_merge($watchedDids, $localDids));
	}
	
	private function getCursor(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('cursor')
			->from('social_atproto_jetstream_cursor')
			->setMaxResults(1);
		
		return (int)($qb->executeQuery()->fetchOne() ?? 0);
	}
	
	private function saveCursor(int $cursor): void {
		$qb = $this->db->getQueryBuilder();
		$qb->upsert('social_atproto_jetstream_cursor')
			->set('cursor', $qb->createNamedParameter($cursor))
			->set('updated', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
			->executeStatement();
	}
	
	private function processEvent(array $event): void {
		$kind = $event['kind'] ?? '';
		$did = $event['did'] ?? '';
		$commit = $event['commit'] ?? [];
		$ops = $commit['ops'] ?? [];
		
		foreach ($ops as $op) {
			$action = $op['action'] ?? '';
			$path = $op['path'] ?? '';
			
			if (str_starts_with($path, 'app.bsky.feed.post/')) {
				if ($action === 'create' || $action === 'update') {
					// New/updated post - fetch and store
					$rkey = substr($path, strlen('app.bsky.feed.post/'));
					$this->fetchAndStorePost($did, $rkey);
				} elseif ($action === 'delete') {
					// Deleted post
					$rkey = substr($path, strlen('app.bsky.feed.post/'));
					$this->deletePost($did, $rkey);
				}
			} elseif (str_starts_with($path, 'app.bsky.feed.like/') && $action === 'create') {
				// Like - would need to fetch the like record
			} elseif (str_starts_with($path, 'app.bsky.feed.repost/') && $action === 'create') {
				// Repost
			} elseif (str_starts_with($path, 'app.bsky.graph.follow/') && $action === 'create') {
				// Follow
			}
		}
	}
	
	private function fetchAndStorePost(string $authorDid, string $rkey): void {
		// Fetch post from AppView
		$appView = $this->config->getAppValue('social', 'atproto_appview', 'https://public.api.bsky.app');
		$url = $appView . "/xrpc/com.atproto.sync.getRecord?did=$authorDid&collection=app.bsky.feed.post&rkey=$rkey";
		
		$response = $this->curlService->get($url);
		$data = json_decode($response, true);
		
		if ($data && isset($data['value'])) {
			// Store the post
			$this->storeBlueskyPost($authorDid, $rkey, $data['value'], $data['cid'] ?? '');
		}
	}
	
	private function storeBlueskyPost(string $authorDid, string $rkey, array $record, string $cid): void {
		// Similar to AtprotoSync::storeBlueskyPost
	}
	
	private function deletePost(string $authorDid, string $rkey): void {
		// Delete from stream
		$uri = "at://$authorDid/app.bsky.feed.post/$rkey";
		$qb = $this->db->getQueryBuilder();
		$qb->delete('social_stream')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($uri)))
			->executeStatement();
	}
}