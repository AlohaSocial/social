<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Social\Atproto\Crypto\PublicKey;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Db\AtprotoPlcLogRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Who controls a person's DID, read from the PLC directory: the rotation keys
 * of its latest operation, each named by role (this server's key, their
 * recovery phrase, or unknown), and for 72 hours after an operation, until
 * when a higher-ranked key can still undo it.
 *
 * The directory's answer is cached a minute, keyed by the operation this
 * server confirmed last, so its own operations show at once.
 */
class DidControlService {
	/** the PLC rule: a higher rotation key can nullify an operation for this long */
	public const RECOVERY_WINDOW = 72 * 3600;
	public const ROLE_SERVER = 'server';
	public const ROLE_SERVER_RETIRED = 'server-retired';
	public const ROLE_RECOVERY = 'recovery';
	public const ROLE_UNKNOWN = 'unknown';
	private const CACHE_TTL = 60;

	private ?ICache $cache = null;

	public function __construct(
		private PlcClient $plc,
		private AtprotoPlcLogRequest $plcLog,
		private InstanceKeyService $instanceKeys,
		private ICacheFactory $cacheFactory,
		private ITimeFactory $time,
	) {
	}

	/**
	 * The DID's rotation keys by role, the latest operation and who signed
	 * it, and while it can still be undone, until when and by which keys.
	 * `state` is `active` or `tombstoned`; `undo` is null once nothing can
	 * undo the latest operation any more.
	 *
	 * @return array{did: string, state: string, rotation_keys: list<array{key: string, role: string}>, last_operation: array{cid: string, created_at: int, signed_by: array{key: string, role: string}|null}, undo: array{until: int, by: list<array{key: string, role: string}>}|null}
	 * @throws AtprotoException when the directory cannot be reached
	 * @throws InvalidArgumentException when the directory does not know the DID
	 */
	public function forIdentity(Identity $identity): array {
		$live = array_values(array_filter($this->auditLog($identity->did), static fn (array $entry): bool => ($entry['nullified'] ?? false) !== true && is_array($entry['operation'] ?? null)));
		if ($live === []) {
			throw new InvalidArgumentException('The PLC directory does not know ' . $identity->did);
		}
		$last = $live[count($live) - 1];
		$operation = $last['operation'];
		$roles = $this->roles($identity);
		$name = static fn (string $key): array => ['key' => $key, 'role' => $roles[$key] ?? self::ROLE_UNKNOWN];
		$tombstoned = ($operation['type'] ?? '') === PlcOperation::TYPE_TOMBSTONE;
		$created = self::time((string)($last['createdAt'] ?? ''));

		$previous = null;
		foreach ($live as $entry) {
			if (($entry['cid'] ?? null) === ($operation['prev'] ?? false)) {
				$previous = $entry['operation'];
			}
		}
		$authority = $previous === null ? [] : self::authority($previous);
		$signer = self::signer($operation, $authority);
		$undo = null;
		if ($signer !== null && $signer > 0 && $created > 0 && $created + self::RECOVERY_WINDOW > $this->time->getTime()) {
			$undo = ['until' => $created + self::RECOVERY_WINDOW, 'by' => array_map($name, array_slice($authority, 0, $signer))];
		}

		return [
			'did' => $identity->did,
			'state' => $tombstoned ? 'tombstoned' : 'active',
			'rotation_keys' => $tombstoned ? [] : array_map($name, self::authority($operation)),
			'last_operation' => [
				'cid' => (string)($last['cid'] ?? ''),
				'created_at' => $created,
				'signed_by' => $signer === null ? null : $name($authority[$signer]),
			],
			'undo' => $undo,
		];
	}

	/**
	 * The directory's audit log, kept a minute under the operation this
	 * server confirmed last.
	 *
	 * @throws AtprotoException
	 */
	private function auditLog(string $did): array {
		$this->cache ??= $this->cacheFactory->createDistributed('social-atproto-did-control');
		$key = hash('sha256', $did . ' ' . $this->plcLog->confirmedCid($did));
		$cached = $this->cache->get($key);
		if (is_array($cached)) {
			return $cached;
		}
		$log = $this->plc->auditLog($did);
		$this->cache->set($key, $log, self::CACHE_TTL);

		return $log;
	}

	/**
	 * @return array<string, string> did:key => role
	 */
	private function roles(Identity $identity): array {
		$roles = [];
		foreach ($this->instanceKeys->rotationDidKeys() as $key => $retired) {
			$roles[$key] = $retired ? self::ROLE_SERVER_RETIRED : self::ROLE_SERVER;
		}
		if ($identity->recoveryPublic !== '') {
			$roles[$identity->recoveryPublic] = self::ROLE_RECOVERY;
		}

		return $roles;
	}

	/**
	 * Which of the keys that had authority signed the operation, by rank;
	 * null for a genesis or a signature none of them made.
	 *
	 * @param list<string> $authority
	 */
	private static function signer(array $operation, array $authority): ?int {
		foreach ($authority as $rank => $key) {
			try {
				if (PlcOperation::verify($operation, PublicKey::fromDidKey($key))) {
					return $rank;
				}
			} catch (InvalidArgumentException) {
			}
		}

		return null;
	}

	/**
	 * The keys an operation gives authority over the next one, highest
	 * first: its rotation keys, or for a legacy `create` its recovery and
	 * signing key.
	 *
	 * @return list<string>
	 */
	private static function authority(array $operation): array {
		$keys = ($operation['type'] ?? '') === 'create'
			? [$operation['recoveryKey'] ?? null, $operation['signingKey'] ?? null]
			: (is_array($operation['rotationKeys'] ?? null) ? $operation['rotationKeys'] : []);

		return array_values(array_filter($keys, 'is_string'));
	}

	private static function time(string $iso): int {
		try {
			return $iso === '' ? 0 : (new DateTimeImmutable($iso))->getTimestamp();
		} catch (\Exception) {
			return 0;
		}
	}
}
