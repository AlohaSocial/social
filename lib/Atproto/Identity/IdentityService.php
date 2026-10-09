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
	 * The identity of a local actor when it is on Bluesky now, for acting as
	 * the person there; never made. Null as forActor() without $create, and
	 * while the person has switched their presence on Bluesky off.
	 */
	public function activeForActor(Person $actor): ?Identity {
		$identity = $this->forActor($actor, false);

		return $identity !== null && $identity->isActive() ? $identity : null;
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

	/** How many people switched their presence on Bluesky off. */
	public function countDeactivated(): int {
		return $this->identityRequest->countInState(Identity::STATE_DEACTIVATED);
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
	 * Hands the DID to another PDS (§13.2): its signing key, its rotation
	 * keys and its endpoint, as that PDS recommends them, with the person's
	 * recovery key kept first so the DID stays theirs. Signed with this
	 * server's rotation key, which the new document no longer lists. Sent at
	 * once and not left for repair: a move goes on only once the directory
	 * took it.
	 *
	 * @param array $credentials the other PDS's `getRecommendedDidCredentials`
	 * @throws AtprotoException
	 */
	public function handOver(Identity $identity, array $credentials): void {
		$signing = (string)($credentials['verificationMethods']['atproto'] ?? '');
		$handle = (string)preg_replace('#^at://#', '', (string)($credentials['alsoKnownAs'][0] ?? ''));
		$endpoint = (string)($credentials['services']['atproto_pds']['endpoint'] ?? '');
		$rotation = [];
		if ($identity->recoveryPublic !== '') {
			$rotation[] = $identity->recoveryPublic;
		}
		foreach (is_array($credentials['rotationKeys'] ?? null) ? $credentials['rotationKeys'] : [] as $key) {
			if (is_string($key) && str_starts_with($key, 'did:key:') && !in_array($key, $rotation, true)) {
				$rotation[] = $key;
			}
		}
		$rotation = array_slice($rotation, 0, 5);
		if (!str_starts_with($signing, 'did:key:') || $handle === '' || !preg_match('#^https?://#', $endpoint) || $rotation === []) {
			throw new AtprotoException('The other PDS recommended no usable credentials');
		}
		$operation = PlcOperation::sign(
			PlcOperation::build($rotation, $signing, $handle, $endpoint, $this->prev($identity)),
			$this->instanceKeys->rotationKey(),
		);
		$logId = $this->plcLog->record($identity->did, PlcOperation::cid($operation)->toString(), $operation);
		$this->plcLog->markSent($logId);
		try {
			$this->plc->submit($identity->did, $operation);
		} catch (AtprotoException $e) {
			// not left for repair(): a move is started again by the person,
			// never finished behind their back
			$this->plcLog->remove($logId);
			throw $e;
		}
		$this->plcLog->markConfirmed($logId);
	}

	/**
	 * A DID that moved here (§13.1) becomes the account's: the directory takes
	 * the operation the old PDS signed for it, the DID this server had made
	 * for the account is retired — tombstoned, its repository dropped — and
	 * the account's identity row takes the moved DID with the signing key
	 * made for it here, keeping its handle. The network is told both.
	 *
	 * @param array $operation the signed PLC operation that names this PDS
	 * @return Identity the account's identity, with its new DID
	 * @throws AtprotoException when the directory refuses the operation; nothing has changed then
	 */
	public function adopt(Identity $current, string $did, PrivateKey $signingKey, string $oldPds, array $operation): Identity {
		$this->submit($did, $operation);

		return $this->receive($current, $did, $signingKey, $oldPds);
	}

	/**
	 * Sends an operation someone else signed for a DID moving here, logged
	 * as this server's own are.
	 *
	 * @throws AtprotoException when the directory refuses it; it is not logged then
	 */
	public function submit(string $did, array $operation): void {
		$logId = $this->plcLog->record($did, PlcOperation::cid($operation)->toString(), $operation);
		$this->plcLog->markSent($logId);
		try {
			$this->plc->submit($did, $operation);
		} catch (AtprotoException $e) {
			$this->plcLog->remove($logId);
			throw $e;
		}
		$this->plcLog->markConfirmed($logId);
	}

	/**
	 * A DID that the other side already pointed here (Bridgy Fed, a migration
	 * tool) becomes the account's, as in adopt(), with nothing sent to the
	 * directory.
	 *
	 * @return Identity the account's identity, with its new DID
	 * @throws AtprotoException
	 */
	public function receive(Identity $current, string $did, PrivateKey $signingKey, string $oldPds): Identity {
		if ($current->did !== $did) {
			$this->tombstone($current);
		}
		$this->identityRequest->adoptDid($current->id, $did, $this->cipher->seal($signingKey->secret()), $signingKey->didKey(), $oldPds);
		$adopted = $this->identityRequest->getByDid($did);
		$this->events->identity($did, $adopted->handle);
		$this->events->account($did, true);
		$this->logger->info('Bluesky account moved here', ['did' => $did, 'retired' => $current->did, 'from' => $oldPds]);

		return $adopted;
	}

	/**
	 * The account lives elsewhere now: announced inactive here, nothing more
	 * published for it, and no new identity made for the local account.
	 */
	public function markMovedAway(Identity $identity): void {
		$this->identityRequest->setState($identity->did, Identity::STATE_MOVED_AWAY);
		$this->events->account($identity->did, false, 'deactivated');
	}

	/**
	 * Switches a person's presence on Bluesky off (D22, §4.6): the
	 * repository stays and the DID and the handle stay theirs, but the
	 * account is announced inactive, the sync endpoints and the handle say
	 * so, and nothing more is published for it.
	 */
	public function deactivate(Identity $identity): void {
		if ($identity->state !== Identity::STATE_ACTIVE) {
			return;
		}
		$this->identityRequest->setState($identity->did, Identity::STATE_DEACTIVATED);
		$this->events->account($identity->did, false, 'deactivated');
	}

	/**
	 * Switches it back on, the repository as it was left: announced active,
	 * the handle to be resolved again, and the head commit as a `#sync`, so
	 * a relay that dropped what was removed while it was off fetches the
	 * repository again.
	 */
	public function activate(Identity $identity): void {
		if ($identity->state !== Identity::STATE_DEACTIVATED) {
			return;
		}
		$this->identityRequest->setState($identity->did, Identity::STATE_ACTIVE);
		$this->events->account($identity->did, true);
		$this->events->identity($identity->did, $identity->handle);
		$head = $this->repositories->headCommit($identity->did);
		if ($head !== null) {
			$this->events->sync($identity->did, $head);
		}
	}

	/**
	 * Ends the identity for good: the DID is tombstoned at the directory,
	 * the repository and its blobs are dropped, and the firehose says so.
	 * The row stays, so neither the DID nor the handle is issued again.
	 *
	 * @throws AtprotoException
	 */
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
