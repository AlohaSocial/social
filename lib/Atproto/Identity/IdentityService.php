<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Crypto\PublicKey;
use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoPlcLogRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Security\PrivateKeyCipher;
use Psr\Log\LoggerInterface;

/**
 * Every local actor's AT Protocol identity: made on first need, registered
 * with the PLC directory, its signing key sealed here and opened for one
 * signature at a time.
 *
 * A PLC operation is logged before it is sent. The genesis operation
 * decides the DID, so an identity whose registration failed is kept and
 * the same operation sent again by repair(): sending it twice is harmless.
 */
class IdentityService {
	public function __construct(
		private AtprotoConfig $config,
		private AtprotoIdentityRequest $identityRequest,
		private AtprotoPlcLogRequest $plcLog,
		private AtprotoBlobRequest $blobRequest,
		private InstanceKeyService $instanceKeys,
		private PlcClient $plc,
		private EventService $events,
		private RepositoryService $repositories,
		private PrivateKeyCipher $cipher,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The identity of a local actor, made now if it has none and $create.
	 *
	 * Null when Bluesky is off for the instance, the actor is not local, has
	 * moved away, or has none and $create is false.
	 *
	 * @throws AtprotoException when the identity cannot be made
	 */
	public function forActor(Person $actor, bool $create = true): ?Identity {
		if (!$this->config->isEnabled() || !$actor->isLocal()) {
			return null;
		}
		try {
			return $this->identityRequest->getByActorId($actor->getId());
		} catch (AtprotoIdentityNotFoundException) {
		}
		if (!$create || $actor->getMovedTo() !== '') {
			return null;
		}

		return $this->create($actor);
	}

	/**
	 * @throws AtprotoIdentityNotFoundException
	 */
	public function getByDid(string $did): Identity {
		return $this->identityRequest->getByDid($did);
	}

	/**
	 * @throws AtprotoIdentityNotFoundException
	 */
	public function getByHandle(string $handle): Identity {
		return $this->identityRequest->getByHandle($handle);
	}

	/**
	 * @throws AtprotoIdentityNotFoundException
	 */
	public function getByActorId(string $actorId): Identity {
		return $this->identityRequest->getByActorId($actorId);
	}

	/**
	 * @return Identity[]
	 */
	public function getAll(int $limit = 1000, int $offset = 0): array {
		return $this->identityRequest->getAll($limit, $offset);
	}

	public function count(): int {
		return $this->identityRequest->count();
	}

	/**
	 * The signing key, opened.
	 *
	 * @throws AtprotoException when the seal does not open (the instance secret changed)
	 */
	public function signingKey(Identity $identity): PrivateKey {
		$secret = $this->cipher->open($identity->sealedSigningKey);
		if ($secret === '') {
			throw new AtprotoException('The signing key of ' . $identity->did . ' cannot be opened');
		}

		return new PrivateKey(Curve::K256, $secret);
	}

	public function signingPublicKey(Identity $identity): PublicKey {
		return PublicKey::fromDidKey($identity->signingPublic);
	}

	/**
	 * Makes and registers an identity.
	 *
	 * @throws AtprotoException
	 */
	public function create(Person $actor): Identity {
		$signing = PrivateKey::generate(Curve::K256);
		$handle = HandleMapper::handle(
			$actor->getPreferredUsername(),
			$this->config->handleHost(),
			fn (string $candidate): bool => $this->identityRequest->handleExists($candidate),
		);
		$rotation = $this->instanceKeys->rotationKey();
		$genesis = PlcOperation::sign(
			PlcOperation::build([$rotation->didKey()], $signing->didKey(), $handle, $this->config->pdsEndpoint(), null),
			$rotation,
		);
		$did = PlcOperation::didOf($genesis);

		$this->identityRequest->create($actor->getId(), $did, $handle, $this->cipher->seal($signing->secret()), $signing->didKey(), '');
		$logId = $this->plcLog->record($did, PlcOperation::cid($genesis)->toString(), $genesis);
		$identity = $this->identityRequest->getByDid($did);
		$this->logger->info('Bluesky identity made', ['did' => $did, 'handle' => $handle, 'actor' => $actor->getId()]);

		$this->send($logId, $did, $genesis);
		$this->events->identity($did, $handle);

		return $identity;
	}

	/**
	 * Gives the account a recovery key of its own, second in authority to
	 * the instance's. The phrase is the only copy of the private half.
	 *
	 * @return string the twelve words
	 * @throws AtprotoException
	 */
	public function issueRecoveryKey(Identity $identity): string {
		[$phrase, $recovery] = Mnemonic::generate();
		$this->update($identity, [$this->instanceKeys->rotationKey()->didKey(), $recovery->didKey()], $identity->handle, $this->config->pdsEndpoint());
		$this->identityRequest->setRecoveryPublic($identity->did, $recovery->didKey());

		return $phrase;
	}

	/**
	 * Re-registers after the instance rotation key changed: the new key
	 * takes the first place, the old one signs the handover.
	 *
	 * @throws AtprotoException
	 */
	public function rotateInstanceKey(Identity $identity, PrivateKey $newKey, PrivateKey $oldKey): void {
		$keys = [$newKey->didKey()];
		if ($identity->recoveryPublic !== '') {
			$keys[] = $identity->recoveryPublic;
		}
		$this->update($identity, $keys, $identity->handle, $this->config->pdsEndpoint(), $oldKey);
	}

	/**
	 * Re-registers with the instance's current handle host and endpoint,
	 * for a host that moved. Nothing is sent when nothing changed.
	 *
	 * @throws AtprotoException
	 */
	public function refresh(Identity $identity): bool {
		$data = $this->plc->data($identity->did);
		$endpoint = $this->config->pdsEndpoint();
		if ($data !== null
			&& ($data['alsoKnownAs'][0] ?? '') === 'at://' . $identity->handle
			&& ($data['services']['atproto_pds']['endpoint'] ?? '') === $endpoint
			&& ($data['verificationMethods']['atproto'] ?? '') === $identity->signingPublic) {
			return false;
		}
		$keys = [$this->instanceKeys->rotationKey()->didKey()];
		if ($identity->recoveryPublic !== '') {
			$keys[] = $identity->recoveryPublic;
		}
		$this->update($identity, $keys, $identity->handle, $endpoint);

		return true;
	}

	/**
	 * Gives the account another handle, or with '' its assigned one back:
	 * the DID document names it, and the firehose tells the network to
	 * resolve it again. The handle is checked by the caller.
	 *
	 * @return Identity the identity as it is now
	 * @throws AtprotoException
	 */
	public function useCustomHandle(Identity $identity, string $handle): Identity {
		$this->identityRequest->setCustomHandle($identity->did, $handle);
		$updated = $this->identityRequest->getByDid($identity->did);
		$keys = [$this->instanceKeys->rotationKey()->didKey()];
		if ($updated->recoveryPublic !== '') {
			$keys[] = $updated->recoveryPublic;
		}
		$this->update($updated, $keys, $updated->handle, $this->config->pdsEndpoint());
		$this->events->identity($updated->did, $updated->handle);

		return $updated;
	}

	/**
	 * Ends the identity for good: the DID is tombstoned at the directory,
	 * the repository and its blobs are dropped, and the firehose says so.
	 * The row stays, so neither the DID nor the handle is issued again.
	 *
	 * @throws AtprotoException
	 */
	/**
	 * Switches a person's Bluesky presence off (§19): the repository stays
	 * and the DID stays theirs, but the account is announced inactive, the
	 * sync endpoints say so, and nothing more is published for it.
	 */
	public function deactivate(Identity $identity): void {
		if ($identity->state !== Identity::STATE_ACTIVE) {
			return;
		}
		$this->identityRequest->setState($identity->did, Identity::STATE_DEACTIVATED);
		$this->events->account($identity->did, false, 'deactivated');
	}

	/**
	 * Switches it back on; the repository is as it was left.
	 */
	public function activate(Identity $identity): void {
		if ($identity->state !== Identity::STATE_DEACTIVATED) {
			return;
		}
		$this->identityRequest->setState($identity->did, Identity::STATE_ACTIVE);
		$this->events->account($identity->did, true);
	}

	public function tombstone(Identity $identity): void {
		if ($identity->state !== Identity::STATE_TOMBSTONED) {
			$operation = PlcOperation::sign(PlcOperation::tombstone($this->prev($identity)), $this->instanceKeys->rotationKey());
			$logId = $this->plcLog->record($identity->did, PlcOperation::cid($operation)->toString(), $operation);
			$this->identityRequest->setState($identity->did, Identity::STATE_TOMBSTONED);
			$this->send($logId, $identity->did, $operation);
		}
		$this->repositories->delete($identity->did);
		$this->blobRequest->deleteByDid($identity->did);
		$this->events->account($identity->did, false, 'deleted');
	}

	/**
	 * Sends again what the directory never confirmed.
	 *
	 * @return int how many operations were confirmed now
	 */
	public function repair(int $limit = 100): int {
		$confirmed = 0;
		foreach ($this->plcLog->getUnconfirmed($limit) as $row) {
			try {
				$this->plc->submit($row['did'], $row['operation']);
				$this->plcLog->markSent($row['id']);
				$this->plcLog->markConfirmed($row['id']);
				$confirmed++;
			} catch (AtprotoException $e) {
				$this->logger->warning('PLC operation still not accepted', ['did' => $row['did'], 'exception' => $e]);
			}
		}

		return $confirmed;
	}

	/**
	 * The directory's view beside this app's log, for `occ social:atproto:plc`.
	 *
	 * @return array{log: array, directory: array|null, document: array|null}
	 */
	public function compare(Identity $identity): array {
		return [
			'log' => $this->plcLog->getByDid($identity->did),
			'directory' => $this->plc->data($identity->did),
			'document' => $this->plc->document($identity->did),
		];
	}

	/**
	 * The DID document as this app would write it, from its own log: what
	 * the directory serves when every operation went through.
	 */
	public function document(Identity $identity): array {
		$operations = $this->plcLog->getByDid($identity->did);
		$state = [];
		foreach ($operations as $row) {
			if (($row['operation']['type'] ?? '') === PlcOperation::TYPE_OPERATION) {
				$state = $row['operation'];
			}
		}

		return PlcOperation::document($identity->did, $state);
	}

	/**
	 * The instance's own `did:web` document: the service key, and the PDS.
	 */
	public function instanceDocument(): array {
		$did = $this->config->serviceDid();

		return [
			'@context' => ['https://www.w3.org/ns/did/v1', 'https://w3id.org/security/multikey/v1', 'https://w3id.org/security/suites/secp256k1-2019/v1'],
			'id' => $did,
			// a DID document without a handle is not an atproto identity to
			// the reference resolver, which is what checks this server's
			// tokens — the moderation service's, for a forwarded report
			'alsoKnownAs' => ['at://' . $this->config->handleHost()],
			'verificationMethod' => [[
				'id' => $did . '#atproto',
				'type' => 'Multikey',
				'controller' => $did,
				'publicKeyMultibase' => $this->instanceKeys->serviceKey()->publicKey()->multibase(),
			]],
			'service' => [[
				'id' => '#atproto_pds',
				'type' => PlcOperation::PDS_SERVICE_TYPE,
				'serviceEndpoint' => $this->config->pdsEndpoint(),
			]],
		];
	}

	/**
	 * @param string[] $rotationKeys
	 * @throws AtprotoException
	 */
	private function update(Identity $identity, array $rotationKeys, string $handle, string $endpoint, ?PrivateKey $signer = null): void {
		$signer ??= $this->instanceKeys->rotationKey();
		$operation = PlcOperation::sign(
			PlcOperation::build($rotationKeys, $identity->signingPublic, $handle, $endpoint, $this->prev($identity)),
			$signer,
		);
		$logId = $this->plcLog->record($identity->did, PlcOperation::cid($operation)->toString(), $operation);
		$this->send($logId, $identity->did, $operation);
	}

	/**
	 * @throws AtprotoException when the log has no operation to follow
	 */
	private function prev(Identity $identity): string {
		$prev = $this->plcLog->latestCid($identity->did);
		if ($prev === '') {
			throw new AtprotoException('No PLC operation on record for ' . $identity->did);
		}

		return $prev;
	}

	/**
	 * Sends a logged operation; a failure is logged and left for repair().
	 */
	private function send(int $logId, string $did, array $operation): void {
		$this->plcLog->markSent($logId);
		try {
			$this->plc->submit($did, $operation);
			$this->plcLog->markConfirmed($logId);
		} catch (AtprotoException $e) {
			$this->logger->warning('PLC operation not accepted yet', ['did' => $did, 'exception' => $e]);
		}
	}
}
