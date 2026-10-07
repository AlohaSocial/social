<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Sync;

use OCA\Social\Atproto\Auth\ServiceAuth;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\NativeFeedService;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\Details;
use OCA\Social\Service\NotificationService;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/** Authenticated notifications are persisted in the same stream and bell used by ActivityPub. */
class AtprotoNotifications {
	public const DEFAULT_BATCH = 25;
	public const DEFAULT_CEILING = 200;
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly IdentityService $identities,
		private readonly ServiceAuth $auth,
		private readonly IClientService $clients,
		private readonly NativeFeedService $feed,
		private readonly StreamRequest $streams,
		private readonly NotificationService $notifications,
	) {
	}
	public function run(int $batch = self::DEFAULT_BATCH, bool $dryRun = false): array {
		$stats = ['accounts_checked' => 0, 'new_notifications' => 0, 'errors' => 0, 'duration_ms' => 0];
		$start = microtime(true);
		if (!$this->identities->isEnabled()) {
			return $stats;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.did', 'i.actor_id', 'c.cursor')->from('social_atpds_identity', 'i')->leftJoin('i', 'social_atpds_notify_cursor', 'c', 'c.did = i.did')
			->where($qb->expr()->eq('i.state', $qb->createNamedParameter('active')))->andWhere($qb->expr()->orX($qb->expr()->isNull('c.next_sync'), $qb->expr()->lte('c.next_sync', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))))
			->orderBy('c.next_sync', 'ASC')->setMaxResults(max(1, min(25, $batch)));
		foreach ($qb->executeQuery()->fetchAllAssociative() as $account) {
			$stats['accounts_checked']++;
			if ($dryRun) {
				continue;
			} $failed = false;
			$cursor = $account['cursor'] ?? '';
			try {
				$params = ['limit' => 100];
				if ($cursor !== '') {
					$params['cursor'] = $cursor;
				}
				$response = $this->clients->newClient()->get('https://api.bsky.app/xrpc/app.bsky.notification.listNotifications?' . http_build_query($params), ['timeout' => 10, 'allow_redirects' => false,
					'headers' => ['Authorization' => 'Bearer ' . $this->auth->token($account['did'], 'app.bsky.notification.listNotifications')]]);
				$body = $response->getBody();
				if (is_resource($body)) {
					$body = stream_get_contents($body, 2 * 1024 * 1024 + 1);
				}
				if ($response->getStatusCode() !== 200 || strlen($body) > 2 * 1024 * 1024) {
					throw new \RuntimeException('Notification AppView response invalid');
				}
				$data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
				foreach ($data['notifications'] ?? [] as $item) {
					$stats['new_notifications'] += (int)$this->store($account['actor_id'], $item);
				}
				$cursor = (string)($data['cursor'] ?? '');
				if (strlen($cursor) > 255) {
					throw new \RuntimeException('Notification cursor exceeds storage limit');
				}
			} catch (\Throwable $e) {
				$failed = true;
				$stats['errors']++;
				$this->logger->warning('AT Protocol notification synchronization failed', ['did' => $account['did'], 'exception' => $e]);
			}
			$this->schedule($account['did'], $failed ? ($account['cursor'] ?? '') : $cursor, $failed);
		}
		$stats['duration_ms'] = (int)((microtime(true) - $start) * 1000.0);
		return $stats;
	}
	private function store(string $recipient, array $item): bool {
		$type = ['follow' => 'Follow', 'like' => 'Like', 'repost' => 'Announce', 'reply' => 'Mention', 'mention' => 'Mention', 'quote' => 'Mention'][$item['reason'] ?? ''] ?? null;
		if ($type === null || !isset($item['uri'], $item['author']['did'], $item['indexedAt'])) {
			return false;
		}
		$id = $recipient . '#atproto-notification/' . hash('sha256', $item['uri'] . '|' . $item['reason']);
		try {
			$this->streams->getStreamById($id);
			return false;
		} catch (\OCA\Social\Exceptions\StreamNotFoundException) {
		}
		$actor = $this->feed->cacheProfile($item['author']);
		$notification = new SocialAppNotification();
		$notification->setId($id)->setActorId($actor->getId())->setAttributedTo($actor->getId())->setTo($recipient)->setSubType($type)->setLocal(true);
		$notification->setPublished($item['indexedAt']);
		$notification->convertPublished();
		$subject = $item['reasonSubject'] ?? (($type === 'Mention') ? $item['uri'] : null);
		if (is_string($subject)) {
			$post = $this->feed->resolvePost($subject);
			if ($post !== null) {
				$notification->setDetailItem(Details::POST, $post);
			}
		}
		$this->streams->save($notification);
		$this->streams->getStreamById($id);
		$this->notifications->onNotification($notification, $actor->getId());
		return true;
	}
	private function schedule(string $did, string $cursor, bool $failed): void {
		$values = ['cursor' => $cursor, 'last_sync' => gmdate('Y-m-d H:i:s'), 'next_sync' => gmdate('Y-m-d H:i:s', time() + ($failed ? 600 : 120)), 'failures' => $failed ? 1 : 0];
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('social_atpds_notify_cursor')->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$id = $qb->executeQuery()->fetchOne();
		$qb = $this->db->getQueryBuilder();
		if ($id === false) {
			$params = ['did' => $qb->createNamedParameter($did)];
			foreach ($values as $key => $value) {
				$params[$key] = $qb->createNamedParameter($value);
			} $qb->insert('social_atpds_notify_cursor')->values($params);
		} else {
			$qb->update('social_atpds_notify_cursor');
			foreach ($values as $key => $value) {
				$qb->set($key, $qb->createNamedParameter($value));
			} $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		}
		$qb->executeStatement();
	}
}
