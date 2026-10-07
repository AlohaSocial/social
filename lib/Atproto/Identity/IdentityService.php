<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Repository\Repository;
use OCA\Social\Service\ConfigService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class IdentityService {
	public const STATE_ACTIVE = 'active';
	public const STATE_DEACTIVATED = 'deactivated';
	public const STATE_MOVED_AWAY = 'moved_away';
	public const STATE_TOMBSTONED = 'tombstoned';
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly ConfigService $configService,
		private readonly AtprotoDid $atprotoDid,
		private readonly HandleMapper $handleMapper,
		private readonly KeyManager $keyManager,
		private readonly PlcClient $plcClient,
		private readonly Repository $repository,
		private readonly \OCA\Social\Atproto\Firehose\EventStore $events,
	) {
	}
	public function isEnabled(): bool {
		return in_array($this->configService->getAppValue(ConfigService::ATPROTO_ENABLED), ['1', 'true'], true);
	}
	public function getPdsEndpoint(): string {
		return $this->handleMapper->getPdsEndpoint();
	}
	public function getServiceDid(): string {
		return 'did:web:' . str_replace(':', '%3A', ConfigService::authorityOf($this->getPdsEndpoint()));
	}
	public function actorIdForUser(string $userId): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('social_actor')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))->andWhere($qb->expr()->isNull('deleted'));
		$actor = $qb->executeQuery()->fetchOne();
		return $actor === false ? null : (string)$actor;
	}
	public function createIdentity(string $actorId): array {
		if (!$this->isEnabled()) {
			throw new \RuntimeException('AT Protocol is disabled');
		}
		$existing = $this->getIdentityByActor($actorId);
		if ($existing !== null) {
			$this->registerPending($existing['did']);
			return $this->getIdentityByDid($existing['did']) ?? throw new \RuntimeException('Identity disappeared during registration');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('preferred_username')->from('social_actor')->where($qb->expr()->eq('id', $qb->createNamedParameter($actorId)))->andWhere($qb->expr()->isNull('deleted'));
		$username = $qb->executeQuery()->fetchOne();
		if ($username === false) {
			throw new \InvalidArgumentException('Local actor not found');
		}
		$signing = $this->keyManager->generateSigningKey();
		$rotation = $this->getInstanceKey('rotation');
		$recovery = $this->keyManager->generateRotationKey();
		$handle = $this->handleMapper->resolveCollision($this->handleMapper->mapUsernameToHandle((string)$username), $this->handleExists(...));
		$operation = AtprotoDid::signOperation(AtprotoDid::operation($signing['didKey'], [$rotation['didKey'], $recovery['didKey']], $handle, $this->getPdsEndpoint()), $rotation['private'], $this->keyManager);
		$did = AtprotoDid::fromSignedGenesis($operation);
		$now = gmdate('Y-m-d H:i:s');
		// Persist the sealed keys and genesis before external registration. A failed request
		// can be retried with the same DID; it must not create a new identity each time.
		$this->db->beginTransaction();
		try {
			$identity = ['actor_id' => $actorId, 'did' => $did, 'handle' => $handle, 'signing_key' => $this->keyManager->sealPrivateKey($signing['private']),
				'signing_public' => $signing['didKey'], 'recovery_public' => $recovery['didKey'], 'state' => self::STATE_DEACTIVATED, 'created_at' => $now, 'updated_at' => $now];
			$this->insert('social_atpds_identity', $identity);
			$this->insert('social_atpds_recovery', ['actor_id' => $actorId, 'recovery_phrase' => $this->keyManager->sealPrivateKey(RecoveryPhrase::encode($recovery['private'])), 'created_at' => $now]);
			$this->insert('social_atpds_plc_log', ['did' => $did, 'cid' => Cid::hash(DagCbor::encode($operation)), 'operation' => json_encode($operation, JSON_THROW_ON_ERROR)]);
			$this->insert('social_atpds_repo', ['did' => $did, 'record_count' => 0, 'blob_bytes' => 0, 'updated' => $now]);
			$this->repository->commit($did, $signing['private']);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->registerPending($did);
		return $this->getIdentityByDid($did) ?? throw new \RuntimeException('Identity disappeared during registration');
	}
	public function registerPending(string $did): bool {
		if (!$this->isEnabled()) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('social_atpds_plc_log')->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))->andWhere($qb->expr()->isNull('confirmed'))->orderBy('id', 'ASC');
		$rows = $qb->executeQuery()->fetchAllAssociative();
		foreach ($rows as $row) {
			if (!$this->plcClient->submitOperation($did, json_decode($row['operation'], true, 512, JSON_THROW_ON_ERROR))) {
				return false;
			}
			$qb = $this->db->getQueryBuilder();
			$now = gmdate('Y-m-d H:i:s');
			$qb->update('social_atpds_plc_log')->set('sent', $qb->createNamedParameter($now))->set('confirmed', $qb->createNamedParameter($now))->where($qb->expr()->eq('id', $qb->createNamedParameter($row['id'])))->executeStatement();
		}
		if ($rows !== []) {
			$this->setState($did, self::STATE_ACTIVE);
		}
		return true;
	}
	public function getIdentityByActor(string $actorId): ?array {
		return $this->find('actor_id', $actorId);
	}
	public function getIdentityByDid(string $did): ?array {
		return $this->find('did', $did);
	}
	public function getIdentityByHandle(string $handle): ?array {
		return $this->find('handle', strtolower($handle));
	}
	private function find(string $column, string $value): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('social_atpds_identity')->where($qb->expr()->eq($column, $qb->createNamedParameter($value)));
		return $qb->executeQuery()->fetchAssociative() ?: null;
	}
	public function handleExists(string $handle): bool {
		return $this->getIdentityByHandle($handle) !== null;
	}
	public function getSigningKey(string $actorId): ?string {
		$identity = $this->getIdentityByActor($actorId);
		return $identity === null ? null : $this->keyManager->unsealPrivateKey($identity['signing_key']);
	}
	public function getRecoveryPhrase(string $actorId): ?string {
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'recovery_phrase')->from('social_atpds_recovery')->where($qb->expr()->eq('actor_id', $qb->createNamedParameter($actorId)));
			$row = $qb->executeQuery()->fetchAssociative();
			if (!$row) {
				$this->db->commit();
				return null;
			}
			$phrase = $this->keyManager->unsealPrivateKey($row['recovery_phrase']);
			$qb = $this->db->getQueryBuilder();
			$deleted = $qb->delete('social_atpds_recovery')->where($qb->expr()->eq('id', $qb->createNamedParameter($row['id'])))->executeStatement();
			$this->db->commit();
			return $deleted === 1 ? $phrase : null;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
	public function updateHandle(string $actorId, string $newHandle): void {
		if ($this->handleExists($newHandle)) {
			throw new \InvalidArgumentException('Handle already issued');
		}
		$this->updateOperation($actorId, ['alsoKnownAs' => ['at://' . $newHandle]]);
		$identity = $this->getIdentityByActor($actorId);
		$qb = $this->db->getQueryBuilder();
		$qb->update('social_atpds_identity')->set('handle', $qb->createNamedParameter($newHandle))->where($qb->expr()->eq('did', $qb->createNamedParameter($identity['did'])))->executeStatement();
		$this->event($identity['did'], '#identity', ['did' => $identity['did'], 'handle' => $newHandle, 'time' => gmdate('Y-m-d\TH:i:s\Z')]);
	}
	public function rotateKeys(string $actorId, bool $regenerateRecovery = false): void {
		if (!$regenerateRecovery) {
			throw new \RuntimeException('Instance rotation requires a queued migration');
		}
		$recovery = $this->keyManager->generateRotationKey();
		$instance = $this->getInstanceKey('rotation');
		$this->updateOperation($actorId, ['rotationKeys' => [$instance['didKey'], $recovery['didKey']]]);
		$identity = $this->getIdentityByActor($actorId);
		$qb = $this->db->getQueryBuilder();
		$qb->update('social_atpds_identity')->set('recovery_public', $qb->createNamedParameter($recovery['didKey']))->where($qb->expr()->eq('did', $qb->createNamedParameter($identity['did'])))->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('social_atpds_recovery')->where($qb->expr()->eq('actor_id', $qb->createNamedParameter($actorId)))->executeStatement();
		$this->insert('social_atpds_recovery', ['actor_id' => $actorId, 'recovery_phrase' => $this->keyManager->sealPrivateKey(RecoveryPhrase::encode($recovery['private'])), 'created_at' => gmdate('Y-m-d H:i:s')]);
	}
	private function updateOperation(string $actorId, array $changes): void {
		$identity = $this->getIdentityByActor($actorId);
		if (!$identity || !$this->isEnabled()) {
			throw new \RuntimeException('Active identity required');
		}
		$log = $this->plcClient->getOperationLog($identity['did']);
		$last = null;
		foreach ($log as $entry) {
			if (empty($entry['nullified'])) {
				$last = $entry;
			}
		}
		if ($last === null || !isset($last['operation'], $last['cid'])) {
			throw new \RuntimeException('Cannot read current PLC operation');
		}
		$op = $last['operation'];
		unset($op['sig']);
		$op = array_replace($op, $changes);
		$op['prev'] = $last['cid'];
		$op = AtprotoDid::signOperation($op, $this->getInstanceKey('rotation')['private'], $this->keyManager);
		if (!$this->plcClient->submitOperation($identity['did'], $op)) {
			throw new \RuntimeException('PLC rejected update');
		}
		$this->insert('social_atpds_plc_log', ['did' => $identity['did'], 'cid' => Cid::hash(DagCbor::encode($op)), 'operation' => json_encode($op), 'sent' => gmdate('Y-m-d H:i:s'), 'confirmed' => gmdate('Y-m-d H:i:s')]);
	}
	public function deactivate(string $actorId): void {
		$identity = $this->getIdentityByActor($actorId);
		if ($identity) {
			$this->setState($identity['did'], self::STATE_DEACTIVATED);
		}
	}
	private function setState(string $did, string $state): void {
		$this->repository->transaction(function () use ($did, $state) {
			$current = $this->getIdentityByDid($did);
			if ($current === null || $current['state'] === $state) {
				return;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update('social_atpds_identity')->set('state', $qb->createNamedParameter($state))->set('updated_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))->executeStatement();
			$active = $state === self::STATE_ACTIVE;
			if ($active) {
				$this->event($did, '#identity', ['did' => $did, 'handle' => $current['handle'], 'time' => gmdate('Y-m-d\TH:i:s\Z')]);
			}
			$body = ['did' => $did, 'active' => $active, 'time' => gmdate('Y-m-d\TH:i:s\Z')];
			if (!$active) {
				$body['status'] = 'deactivated';
			}
			$this->event($did, '#account', $body);
		});
	}
	private function event(string $did, string $kind, array $body): void {
		$this->events->append($did, $kind, $body);
	}
	public function getInstanceKey(string $kind): array {
		if (!in_array($kind, ['service', 'rotation'], true)) {
			throw new \InvalidArgumentException('Invalid instance key kind');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('social_atpds_instance_key')->where($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))->orderBy('created', 'DESC')->setMaxResults(1);
		$row = $qb->executeQuery()->fetchAssociative();
		if ($row) {
			return ['private' => $this->keyManager->unsealPrivateKey($row['private_key']), 'didKey' => $row['public_key'], 'multibase' => substr($row['public_key'], 8)];
		}
		$key = $this->keyManager->generateSigningKey();
		$this->insert('social_atpds_instance_key', ['kind' => $kind, 'private_key' => $this->keyManager->sealPrivateKey($key['private']), 'public_key' => $key['didKey'], 'created' => gmdate('Y-m-d H:i:s')]);
		return $key;
	}
	private function insert(string $table, array $values): void {
		$qb = $this->db->getQueryBuilder();
		$params = [];
		foreach ($values as $name => $value) {
			$params[$name] = $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
		}
		$qb->insert($table)->values($params)->executeStatement();
	}
}
