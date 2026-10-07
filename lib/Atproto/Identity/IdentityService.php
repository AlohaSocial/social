<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\Identity;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCA\Social\Service\ConfigService;

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
		private readonly PlcClient $plcClient
	) {}
	
	public function createIdentity(int $actorId): array {
		$actor = $this->getActor($actorId);
		if (!$actor) {
			throw new \InvalidArgumentException('Actor not found');
		}
		
		// Check if identity already exists
		$existing = $this->getIdentityByActor($actorId);
		if ($existing) {
			return $existing;
		}
		
		// Generate keys
		$signingKey = $this->keyManager->generateSigningKey();
		$instanceRotationKey = $this->getInstanceRotationKey();
		$recoveryKey = $this->keyManager->generateRotationKey();
		
		// Map username to handle
		$handle = $this->handleMapper->mapUsernameToHandle($actor['preferredUsername']);
		$handle = $this->handleMapper->resolveCollision($handle, [$this, 'handleExists']);
		
		// Get PDS endpoint
		$pdsEndpoint = $this->configService->getSocialAddress();
		
		// Generate DID
		$did = AtprotoDid::generate(
			$signingKey['multibase'],
			[$instanceRotationKey['multibase'], $recoveryKey['multibase']],
			$handle,
			$pdsEndpoint
		);
		
		// Create PLC operation
		$operation = $this->createGenesisOperation(
			$did,
			$signingKey['multibase'],
			[$instanceRotationKey['multibase'], $recoveryKey['multibase']],
			$handle,
			$pdsEndpoint
		);
		
		// Submit to PLC directory
		if (!$this->plcClient->submitOperation($did, $operation)) {
			throw new \RuntimeException('Could not register DID with PLC directory');
		}
		
		// Store identity
		$identity = [
			'actor_id' => $actorId,
			'did' => $did,
			'handle' => $handle,
			'signing_key' => $this->keyManager->sealPrivateKey($signingKey['private']),
			'signing_public' => $signingKey['multibase'],
			'recovery_public' => $recoveryKey['multibase'],
			'state' => self::STATE_ACTIVE,
			'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
			'updated_at' => (new \DateTime())->format('Y-m-d H:i:s')
		];
		
		$this->storeIdentity($identity);
		
		// Create empty repository
		$this->createEmptyRepository($did);
		
		return $identity;
	}
	
	public function getIdentityByActor(int $actorId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_identity')
			->where($qb->expr()->eq('actor_id', $qb->createNamedParameter($actorId, \PDO::PARAM_INT)));
		
		$result = $qb->executeQuery()->fetchAssociative();
		return $result ?: null;
	}
	
	public function getIdentityByDid(string $did): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_identity')
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		
		$result = $qb->executeQuery()->fetchAssociative();
		return $result ?: null;
	}
	
	public function getIdentityByHandle(string $handle): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_identity')
			->where($qb->expr()->eq('handle', $qb->createNamedParameter($handle)));
		
		$result = $qb->executeQuery()->fetchAssociative();
		return $result ?: null;
	}
	
	public function updateHandle(int $actorId, string $newHandle): void {
		$identity = $this->getIdentityByActor($actorId);
		if (!$identity) {
			throw new \InvalidArgumentException('Identity not found');
		}
		
		$newHandle = $this->handleMapper->resolveCollision($newHandle, [$this, 'handleExists']);
		
		// Create PLC operation to update handle
		$operation = [
			'type' => 'update',
			'did' => $identity['did'],
			'handle' => $newHandle,
			'prev' => null // Would need to get previous operation CID
		];
		
		$this->plcClient->submitOperation($identity['did'], $operation);
		
		// Update local record
		$qb = $this->db->getQueryBuilder();
		$qb->update('social_atproto_identity')
			->set('handle', $qb->createNamedParameter($newHandle))
			->set('updated_at', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('actor_id', $qb->createNamedParameter($actorId, \PDO::PARAM_INT)))
			->executeStatement();
	}
	
	public function rotateKeys(int $actorId, bool $regenerateRecovery = false): void {
		$identity = $this->getIdentityByActor($actorId);
		if (!$identity) {
			throw new \InvalidArgumentException('Identity not found');
		}
		
		$instanceRotationKey = $this->getInstanceRotationKey();
		
		$rotationKeys = [$instanceRotationKey['multibase']];
		if ($regenerateRecovery) {
			$recoveryKey = $this->keyManager->generateRotationKey();
			$rotationKeys[] = $recoveryKey['multibase'];
			
			// Update recovery key
			$qb = $this->db->getQueryBuilder();
			$qb->update('social_atproto_identity')
				->set('recovery_public', $qb->createNamedParameter($recoveryKey['multibase']))
				->where($qb->expr()->eq('actor_id', $qb->createNamedParameter($actorId, \PDO::PARAM_INT)))
				->executeStatement();
		} else {
			$rotationKeys[] = $identity['recovery_public'];
		}
		
		// Create PLC operation to rotate keys
		$operation = [
			'type' => 'update',
			'did' => $identity['did'],
			'rotationKeys' => $rotationKeys,
			'prev' => null
		];
		
		$this->plcClient->submitOperation($identity['did'], $operation);
	}
	
	public function deactivate(int $actorId): void {
		$identity = $this->getIdentityByActor($actorId);
		if (!$identity) {
			return;
		}
		
		$qb = $this->db->getQueryBuilder();
		$qb->update('social_atproto_identity')
			->set('state', $qb->createNamedParameter(self::STATE_DEACTIVATED))
			->set('updated_at', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('actor_id', $qb->createNamedParameter($actorId, \PDO::PARAM_INT)))
			->executeStatement();
		
		// Create PLC tombstone operation
		$operation = [
			'type' => 'tombstone',
			'did' => $identity['did'],
			'prev' => null
		];
		
		$this->plcClient->submitOperation($identity['did'], $operation);
	}
	
	public function getSigningKey(int $actorId): ?string {
		$identity = $this->getIdentityByActor($actorId);
		if (!$identity || empty($identity['signing_key'])) {
			return null;
		}
		
		return $this->keyManager->unsealPrivateKey($identity['signing_key']);
	}
	
	public function getRecoveryPhrase(int $actorId): ?string {
		$identity = $this->getIdentityByActor($actorId);
		if (!$identity) {
			return null;
		}
		
		// Recovery phrase is only shown once, stored separately
		$qb = $this->db->getQueryBuilder();
		$qb->select('recovery_phrase')
			->from('social_atproto_recovery_phrase')
			->where($qb->expr()->eq('actor_id', $qb->createNamedParameter($actorId, \PDO::PARAM_INT)));
		
		$result = $qb->executeQuery()->fetchAssociative();
		return $result['recovery_phrase'] ?? null;
	}
	
	public function storeRecoveryPhrase(int $actorId, string $phrase): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('social_atproto_recovery_phrase')
			->values([
				'actor_id' => $qb->createNamedParameter($actorId, \PDO::PARAM_INT),
				'recovery_phrase' => $qb->createNamedParameter($phrase),
				'created_at' => $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s'))
			])
			->executeStatement();
	}
	
	public function handleExists(string $handle): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->selectCount('*', 'count')
			->from('social_atproto_identity')
			->where($qb->expr()->eq('handle', $qb->createNamedParameter($handle)));
		
		$count = $qb->executeQuery()->fetchOne();
		return $count > 0;
	}
	
	private function getActor(int $actorId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_actor')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($actorId, \PDO::PARAM_INT)));
		
		return $qb->executeQuery()->fetchAssociative() ?: null;
	}
	
	private function storeIdentity(array $identity): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('social_atproto_identity')
			->values([
				'actor_id' => $qb->createNamedParameter($identity['actor_id'], \PDO::PARAM_INT),
				'did' => $qb->createNamedParameter($identity['did']),
				'handle' => $qb->createNamedParameter($identity['handle']),
				'signing_key' => $qb->createNamedParameter($identity['signing_key']),
				'signing_public' => $qb->createNamedParameter($identity['signing_public']),
				'recovery_public' => $qb->createNamedParameter($identity['recovery_public']),
				'state' => $qb->createNamedParameter($identity['state']),
				'created_at' => $qb->createNamedParameter($identity['created_at']),
				'updated_at' => $qb->createNamedParameter($identity['updated_at'])
			])
			->executeStatement();
	}
	
	private function createEmptyRepository(string $did): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('social_atproto_repo')
			->values([
				'did' => $qb->createNamedParameter($did),
				'commit_cid' => $qb->createNamedParameter(''),
				'rev' => $qb->createNamedParameter(''),
				'record_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
				'blob_bytes' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
				'updated' => $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s'))
			])
			->executeStatement();
	}
	
	private function getInstanceRotationKey(): array {
		// Get or create instance rotation key
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_instance_key')
			->where($qb->expr()->eq('kind', $qb->createNamedParameter('rotation')))
			->orderBy('created', 'DESC')
			->setMaxResults(1);
		
		$result = $qb->executeQuery()->fetchAssociative();
		if ($result && !empty($result['private_key'])) {
			return [
				'private' => $this->keyManager->unsealPrivateKey($result['private_key']),
				'public' => $result['public_key'],
				'multibase' => $result['public_key']
			];
		}
		
		// Generate new instance rotation key
		$key = $this->keyManager->generateRotationKey();
		$qb->insert('social_atproto_instance_key')
			->values([
				'kind' => $qb->createNamedParameter('rotation'),
				'private_key' => $qb->createNamedParameter($this->keyManager->sealPrivateKey($key['private'])),
				'public_key' => $qb->createNamedParameter($key['multibase']),
				'created' => $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s'))
			])
			->executeStatement();
		
		return $key;
	}
	
	private function createGenesisOperation(string $did, string $signingKey, array $rotationKeys, string $handle, string $pdsEndpoint): array {
		return [
			'type' => 'create',
			'signingKey' => $signingKey,
			'rotationKeys' => $rotationKeys,
			'handle' => $handle,
			'service' => [
				'#atproto_pds' => [
					'type' => 'AtprotoPersonalDataServer',
					'endpoint' => $pdsEndpoint
				]
			],
			'prev' => null
		];
	}
}