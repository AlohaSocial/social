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
use OCA\Social\Atproto\Move\RotationKeys;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoPlcLogRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Exceptions\AtprotoKeysSealedException;
use OCA\Social\Exceptions\AtprotoPlcRefusedException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Security\PrivateKeyCipher;
use Psr\Log\LoggerInterface;

/**
 * Every local actor's AT Protocol identity: made on first need, registered
 * with the PLC directory, its signing key sealed here and opened for one
 * signature at a time.
 *
 * A PLC operation is logged before it is sent and goes out in its turn,
 * built on the operation the directory confirmed last. One the directory
 * could not be asked waits in the log for repair(), which compares the
 * log with the directory's and sends what is pending again — signed again
 * on the directory's head where that moved on. One the directory refused
 * or nullified is dead: kept for the record, never sent again, and not
 * the `prev` of anything. The genesis operation decides the DID, so an
 * identity whose registration is pending is kept and the same operation
 * sent again; until the directory takes it the identity is `pending`,
 * nothing is published for it and the firehose does not name it. The
 * `#identity` event goes out when the directory confirms an operation,
 * from wherever that happens.
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
		private HandleVerifier $handles,
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

	public function firstActive(): ?Identity {
		return $this->identityRequest->firstActive();
	}

	public function latestActive(): ?Identity {
		return $this->identityRequest->latestActive();
	}

	public function count(): int {
		return $this->identityRequest->count();
	}

	/** Changes whenever an identity is made, moved in, or switched on or off. */
	public function activeSignature(): string {
		return $this->identityRequest->activeSignature();
	}

	/** How many people switched their presence on Bluesky off. */
	public function countDeactivated(): int {
		return $this->identityRequest->countInState(Identity::STATE_DEACTIVATED);
	}

	/**
	 * The signing key, opened.
	 *
	 * @throws AtprotoKeysSealedException when the seal does not open (the instance secret changed)
	 */
	public function signingKey(Identity $identity): PrivateKey {
		$secret = $this->cipher->open($identity->sealedSigningKey);
		if ($secret === '') {
			$this->logger->error('The signing key of a Bluesky identity cannot be opened with the instance secret', ['did' => $identity->did]);

			throw new AtprotoKeysSealedException('signing key of ' . $identity->did);
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

		$this->identityRequest->create($actor->getId(), $did, $handle, $this->cipher->seal($signing->secret()), $signing->didKey(), '', Identity::STATE_PENDING);
		$this->plcLog->record($did, PlcOperation::cid($genesis)->toString(), $genesis);
		$this->logger->info('Bluesky identity made', ['did' => $did, 'handle' => $handle, 'actor' => $actor->getId()]);
		$this->flush($did);

		return $this->identityRequest->getByDid($did);
	}

	/**
	 * Gives the account a recovery key of its own: in the place of the one
	 * it replaces, else next to the instance's (RotationKeys::carry()),
	 * every other key the DID lists kept where it is. The phrase is the
	 * only copy of the private half.
	 *
	 * @return string the twelve words
	 * @throws AtprotoException also when the DID lists five rotation keys already
	 */
	public function issueRecoveryKey(Identity $identity): string {
		[$phrase, $recovery] = Mnemonic::generate();
		$replace = $identity->recoveryPublic !== '' ? [$identity->recoveryPublic => $recovery->didKey()] : [];
		$this->update($identity, $identity->handle, $this->config->pdsEndpoint(), $replace, $recovery->didKey());
		$this->identityRequest->setRecoveryPublic($identity->did, $recovery->didKey());

		return $phrase;
	}

	/**
	 * Re-registers after the instance rotation key changed: the new key
	 * takes the old one's place, the old one signs the handover.
	 *
	 * @throws AtprotoException
	 */
	public function rotateInstanceKey(Identity $identity, PrivateKey $newKey, PrivateKey $oldKey): void {
		$this->update($identity, $identity->handle, $this->config->pdsEndpoint(), [$oldKey->didKey() => $newKey->didKey()], '', $oldKey);
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
		$this->update($identity, $identity->handle, $endpoint);

		return true;
	}

	/**
	 * Gives the account another handle, or with '' its assigned one back:
	 * the DID document names it, and once the directory took that, the
	 * firehose tells the network to resolve it again. The handle is checked
	 * by the caller.
	 *
	 * @return Identity the identity as it is now
	 * @throws AtprotoException
	 */
	public function useCustomHandle(Identity $identity, string $handle): Identity {
		$this->identityRequest->setCustomHandle($identity->did, $handle);
		$updated = $this->identityRequest->getByDid($identity->did);
		$this->update($updated, $updated->handle, $this->config->pdsEndpoint());

		return $updated;
	}

	/**
	 * Hands the DID to another PDS (§13.2): its signing key, its rotation
	 * keys and its endpoint, as that PDS recommends them, with the person's
	 * recovery key kept first so the DID stays theirs. Signed with the
	 * rotation key of this server that the directory's head names, which
	 * the new document no longer lists. Sent at once and not left for
	 * repair: a move goes on only once the directory took it.
	 *
	 * @param array $credentials the other PDS's `getRecommendedDidCredentials`
	 * @throws AtprotoException also while an earlier operation for the DID is not confirmed
	 */
	public function handOver(Identity $identity, array $credentials): void {
		try {
			$unsigned = PlcOperation::fromCredentials($credentials, $identity->recoveryPublic !== '' ? [$identity->recoveryPublic] : [], null);
		} catch (\InvalidArgumentException $e) {
			throw new AtprotoException($e->getMessage(), 0, $e);
		}
		$this->flush($identity->did);
		if ($this->plcLog->getPending($identity->did) !== []) {
			throw new AtprotoException('An earlier PLC operation for ' . $identity->did . ' is not confirmed yet');
		}
		$operation = $this->follow($identity->did, $unsigned, $this->prev($identity, true));
		$logId = $this->plcLog->record($identity->did, PlcOperation::cid($operation)->toString(), $operation);
		$this->plcLog->markSent($logId);
		try {
			$this->plc->submit($identity->did, $operation);
		} catch (AtprotoException $e) {
			// not left for repair(), which would send it again: a move is
			// started again by the person, never finished behind their back.
			// When no answer came the directory may have taken it all the
			// same; the move asks it (pointsAt()) before it goes on.
			$this->plcLog->remove($logId);
			throw $e;
		}
		$this->plcLog->markConfirmed($logId);
	}

	/**
	 * Whether the directory points the DID at a PDS's endpoint. When it
	 * does, what moved it there is taken into the log from the directory's
	 * (reconcile()): a handover whose answer never came is not in it.
	 *
	 * @throws AtprotoException when the directory cannot be asked
	 */
	public function pointsAt(string $did, string $endpoint): bool {
		$named = (string)($this->plc->data($did)['services']['atproto_pds']['endpoint'] ?? '');
		if ($named === '' || rtrim($named, '/') !== rtrim($endpoint, '/')) {
			return false;
		}
		try {
			$this->reconcile($did);
		} catch (AtprotoException $e) {
			$this->logger->warning('PLC log not compared with the directory', ['did' => $did, 'exception' => $e]);
		}

		return true;
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
			$this->plcLog->record($identity->did, PlcOperation::cid($operation)->toString(), $operation);
			$this->identityRequest->setState($identity->did, Identity::STATE_TOMBSTONED);
			$this->flush($identity->did);
		}
		$this->repositories->delete($identity->did);
		$this->blobRequest->deleteByDid($identity->did);
		$this->events->account($identity->did, false, 'deleted');
	}

	/**
	 * Sends again what the directory never confirmed, each DID's log first
	 * compared with the directory's; once nothing is pending for a DID, so
	 * the log's head is the directory's, the identity row is made to say
	 * what that head says.
	 *
	 * @return int how many operations were confirmed now
	 */
	public function repair(int $limit = 100): int {
		$confirmed = 0;
		foreach (array_unique(array_column($this->plcLog->getUnconfirmed($limit), 'did')) as $did) {
			try {
				$this->reconcile($did);
			} catch (AtprotoException $e) {
				$this->logger->warning('PLC log not compared with the directory', ['did' => $did, 'exception' => $e]);
				continue;
			}
			$confirmed += $this->flush($did);
			if ($this->plcLog->getPending($did) === []) {
				$this->takeFromHead($did);
			}
		}

		return $confirmed;
	}

	/**
	 * The log against the directory's audit log: an operation the directory
	 * holds is confirmed, one it nullified is dead, and one it holds that
	 * this log does not is recorded as confirmed. A directory that does not
	 * know the DID has nothing to compare: the genesis goes out as it is.
	 *
	 * @throws AtprotoException when the directory cannot be reached
	 */
	public function reconcile(string $did): void {
		$audit = $this->plc->auditLog($did);
		if ($audit === []) {
			return;
		}
		$local = [];
		foreach ($this->plcLog->getByDid($did) as $row) {
			$local[$row['cid']] = $row;
		}
		$held = [];
		$dead = [];
		foreach ($audit as $entry) {
			$cid = (string)($entry['cid'] ?? '');
			$operation = is_array($entry['operation'] ?? null) ? $entry['operation'] : [];
			if ($cid === '' || $operation === []) {
				continue;
			}
			$row = $local[$cid] ?? null;
			if (($entry['nullified'] ?? false) === true) {
				if ($row !== null && $row['dead'] === 0) {
					$this->plcLog->markDead($row['id'], 'nullified by the directory');
					$this->logger->warning('PLC operation nullified by the directory', ['did' => $did, 'cid' => $cid]);
					$dead[$cid] = true;
				}
				continue;
			}
			$held[$cid] = true;
			if ($row === null) {
				$id = $this->plcLog->record($did, $cid, $operation);
				$this->plcLog->markSent($id);
				$this->plcLog->markConfirmed($id);
				$this->logger->info('PLC operation taken from the directory', ['did' => $did, 'cid' => $cid]);
			} elseif ($row['confirmed'] === 0 && $row['dead'] === 0) {
				$this->plcLog->markConfirmed($row['id']);
			}
		}
		foreach ($local as $cid => $row) {
			if ($row['confirmed'] !== 0 && $row['dead'] === 0 && !isset($held[$cid]) && !isset($dead[$cid])) {
				$this->plcLog->markDead($row['id'], 'not in the directory\'s log');
				$this->logger->warning('PLC operation confirmed here but not in the directory\'s log', ['did' => $did, 'cid' => $cid]);
			}
		}
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
			if ($row['dead'] === 0 && ($row['operation']['type'] ?? '') === PlcOperation::TYPE_OPERATION) {
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
	 * Signs and sends an operation with the handle and endpoint given, and
	 * the rotation keys of the operation it follows, as RotationKeys::carry()
	 * changes them: $replace and every retired key of this server replaced
	 * in place — the latter by the one in use — and $add listed.
	 *
	 * @param array<string, string> $replace old did:key => new did:key
	 * @throws AtprotoException also when $add would list more than five keys
	 */
	private function update(Identity $identity, string $handle, string $endpoint, array $replace = [], string $add = '', ?PrivateKey $signer = null): void {
		$prev = $this->prev($identity);
		$current = $this->instanceKeys->rotationKey()->didKey();
		foreach ($this->instanceKeys->rotationDidKeys() as $key => $retired) {
			if ($retired && !isset($replace[$key])) {
				$replace[$key] = $current;
			}
		}
		try {
			$rotation = RotationKeys::carry(self::authority($this->operationOf($identity->did, $prev) ?? []), $replace, $current, $add);
		} catch (\InvalidArgumentException $e) {
			throw new AtprotoException($e->getMessage() . ' for ' . $identity->did, 0, $e);
		}
		if ($rotation === []) {
			throw new AtprotoException('The operation ' . $prev . ' of ' . $identity->did . ' names no rotation key to keep');
		}
		$signer ??= $this->instanceKeys->rotationKey();
		$operation = PlcOperation::sign(
			PlcOperation::build($rotation, $identity->signingPublic, $handle, $endpoint, $prev),
			$signer,
		);
		$this->plcLog->record($identity->did, PlcOperation::cid($operation)->toString(), $operation);
		$this->flush($identity->did);
	}

	/**
	 * The operation the log holds under $cid, or null.
	 */
	private function operationOf(string $did, string $cid): ?array {
		foreach ($this->plcLog->getByDid($did) as $row) {
			if ($row['cid'] === $cid) {
				return $row['operation'];
			}
		}

		return null;
	}

	/**
	 * The CID the next operation follows: the latest live one in the log,
	 * or with $confirmed the latest the directory took.
	 *
	 * @throws AtprotoException when the log has no operation to follow
	 */
	private function prev(Identity $identity, bool $confirmed = false): string {
		$prev = $confirmed ? $this->plcLog->confirmedCid($identity->did) : $this->plcLog->latestCid($identity->did);
		if ($prev === '') {
			throw new AtprotoException('No ' . ($confirmed ? 'confirmed ' : '') . 'PLC operation on record for ' . $identity->did);
		}

		return $prev;
	}

	/**
	 * Sends a DID's pending operations in order, each signed on the
	 * directory's head as it is then, with the rotation key of this
	 * instance the head names; a genesis goes as it is. A refusal makes the
	 * operation dead and the next one is tried on the same head; a
	 * directory out of reach leaves the rest for repair().
	 *
	 * @return int how many were confirmed now
	 */
	private function flush(string $did): int {
		$confirmed = 0;
		$head = $this->plcLog->confirmedCid($did);
		foreach ($this->plcLog->getPending($did) as $row) {
			$operation = $row['operation'];
			if ($head !== '' || ($operation['prev'] ?? null) !== null) {
				try {
					$operation = $this->follow($did, $operation, $head);
				} catch (AtprotoException $e) {
					$this->plcLog->markDead($row['id'], $e->getMessage());
					$this->logger->warning('PLC operation cannot follow the directory\'s head', ['did' => $did, 'cid' => $row['cid'], 'reason' => $e->getMessage()]);
					continue;
				}
				$cid = PlcOperation::cid($operation)->toString();
				if ($cid !== $row['cid']) {
					$this->plcLog->rewrite($row['id'], $cid, $operation);
				}
			}
			$this->plcLog->markSent($row['id']);
			try {
				$this->plc->submit($did, $operation);
			} catch (AtprotoPlcRefusedException $e) {
				$this->plcLog->markDead($row['id'], $e->getMessage());
				$this->logger->warning('PLC operation refused by the directory', ['did' => $did, 'cid' => $row['cid'], 'reason' => $e->getMessage()]);
				continue;
			} catch (AtprotoException $e) {
				$this->logger->warning('PLC operation not accepted yet', ['did' => $did, 'exception' => $e]);
				break;
			}
			$this->plcLog->markConfirmed($row['id']);
			$head = PlcOperation::cid($operation)->toString();
			$confirmed++;
			$this->confirmed($did, $operation);
		}

		return $confirmed;
	}

	/**
	 * The directory took an operation: the network is told to resolve the
	 * DID again, and a genesis makes a pending identity active — it is on
	 * Bluesky from now on.
	 */
	private function confirmed(string $did, array $operation): void {
		if (($operation['type'] ?? '') !== PlcOperation::TYPE_OPERATION) {
			return;
		}
		if (($operation['prev'] ?? null) === null) {
			try {
				if ($this->identityRequest->getByDid($did)->state === Identity::STATE_PENDING) {
					$this->identityRequest->setState($did, Identity::STATE_ACTIVE);
					$this->logger->info('Bluesky identity registered', ['did' => $did]);
				}
			} catch (AtprotoIdentityNotFoundException) {
			}
		}
		$this->events->identity($did, (string)preg_replace('#^at://#', '', (string)($operation['alsoKnownAs'][0] ?? '')));
	}

	/**
	 * The operation signed to follow $head, with the rotation key of this
	 * instance that $head names — the retired ones included, for a head
	 * from before a rotation. One that follows the head with that key
	 * already is returned as it is; one built on another operation takes
	 * $head's rotation keys with its own changes to them carried over
	 * (RotationKeys::rebase()), so a key the person added meanwhile stays.
	 *
	 * @throws AtprotoException when nothing is confirmed, or no key of this instance has authority
	 */
	private function follow(string $did, array $operation, string $head): array {
		if ($head === '') {
			throw new AtprotoException('no confirmed operation to follow');
		}
		$authority = self::authority($this->operationOf($did, $head) ?? []);
		$prev = $operation['prev'] ?? null;
		if (is_string($prev) && $prev !== $head && ($operation['type'] ?? '') === PlcOperation::TYPE_OPERATION) {
			$base = $this->operationOf($did, $prev);
			if ($base !== null) {
				$operation['rotationKeys'] = RotationKeys::rebase(self::authority($base), $operation['rotationKeys'] ?? [], $authority, $this->instanceKeys->rotationKey()->didKey());
			}
		}
		foreach ($this->instanceKeys->rotationKeys() as $key) {
			if (in_array($key->didKey(), $authority, true)) {
				if (($operation['prev'] ?? null) === $head && PlcOperation::verify($operation, $key->publicKey())) {
					return $operation;
				}
				$operation['prev'] = $head;

				return PlcOperation::sign($operation, $key);
			}
		}

		throw new AtprotoException('no rotation key of this server is named by the directory\'s latest operation');
	}

	/**
	 * The identity row as the log's confirmed head has it — the directory's
	 * latest operation, once nothing is pending: its handle, when it is the
	 * assigned one or a custom handle this server would take (one that
	 * names the DID, CustomHandleService), and its recovery key only while
	 * the head still lists it. A signing key or an instance key the head no
	 * longer names is nothing this server can put right by itself, so it is
	 * warned of.
	 */
	private function takeFromHead(string $did): void {
		$state = null;
		foreach ($this->plcLog->getByDid($did) as $row) {
			if ($row['dead'] === 0 && $row['confirmed'] !== 0) {
				$state = ($row['operation']['type'] ?? '') === PlcOperation::TYPE_OPERATION ? $row['operation'] : null;
			}
		}
		if ($state === null) {
			return;
		}
		try {
			$identity = $this->identityRequest->getByDid($did);
		} catch (AtprotoIdentityNotFoundException) {
			return;
		}
		$handle = HandleVerifier::normal((string)preg_replace('#^at://#', '', (string)($state['alsoKnownAs'][0] ?? '')));
		if ($handle !== '' && $handle !== $identity->handle) {
			if ($handle === $identity->assignedHandle() || $this->isCustomHandleFor($handle, $did)) {
				$this->identityRequest->setCustomHandle($did, $handle === $identity->assignedHandle() ? '' : $handle);
				$this->logger->warning('Bluesky handle taken from the directory', ['did' => $did, 'handle' => $handle, 'was' => $identity->handle]);
				$this->events->identity($did, $handle);
			} else {
				$this->logger->warning('Bluesky handle the directory names not taken: it is not one this server would give the account', ['did' => $did, 'handle' => $handle, 'kept' => $identity->handle]);
			}
		}
		$rotation = self::stringList($state['rotationKeys'] ?? null);
		if ($identity->recoveryPublic !== '' && !in_array($identity->recoveryPublic, $rotation, true)) {
			$this->identityRequest->setRecoveryPublic($did, '');
			$this->logger->warning('Recovery key no longer listed by the directory', ['did' => $did]);
		}
		$instance = array_map(static fn (PrivateKey $key): string => $key->didKey(), $this->instanceKeys->rotationKeys());
		if (array_intersect($instance, $rotation) === []) {
			$this->logger->warning('No rotation key of this server is listed by the directory', ['did' => $did, 'listed' => $rotation]);
		}
		if (($state['verificationMethods']['atproto'] ?? '') !== $identity->signingPublic) {
			$this->logger->warning('The directory names another signing key', ['did' => $did, 'listed' => $state['verificationMethods']['atproto'] ?? '']);
		}
	}

	/**
	 * Whether a handle may be the DID's custom handle here, as
	 * CustomHandleService::set() would take it: a domain outside this
	 * server's handles, nobody else's here, that names the DID.
	 */
	private function isCustomHandleFor(string $handle, string $did): bool {
		if ($this->handles->refusal($handle) !== '') {
			return false;
		}
		try {
			if ($this->identityRequest->getByHandle($handle)->did !== $did) {
				return false;
			}
		} catch (AtprotoIdentityNotFoundException) {
		}

		return $this->handles->verify($handle, $did) !== '';
	}

	/**
	 * The keys an operation gives authority over the next one: its
	 * rotation keys, or for a legacy `create` its recovery and signing key.
	 *
	 * @return list<string>
	 */
	private static function authority(array $operation): array {
		if (($operation['type'] ?? '') === 'create') {
			return array_values(array_filter([$operation['recoveryKey'] ?? null, $operation['signingKey'] ?? null], 'is_string'));
		}

		return self::stringList($operation['rotationKeys'] ?? null);
	}

	/**
	 * The strings of a decoded JSON list, in order; anything else is none.
	 *
	 * @return list<string>
	 */
	private static function stringList(mixed $value): array {
		if (!is_array($value)) {
			return [];
		}

		return array_values(array_filter($value, 'is_string'));
	}
}
