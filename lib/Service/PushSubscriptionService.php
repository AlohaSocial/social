<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\RefusalException;
use OCA\Social\Security\RemoteAddress;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/** Per-account Mastodon Web Push subscriptions, with secrets encrypted at rest. */
class PushSubscriptionService {
	public const CONFIG_KEY = 'mastodon_push_subscriptions';
	public const ALERTS = ['follow', 'favourite', 'reblog', 'mention', 'poll', 'status', 'update', 'follow_request', 'quote', 'quoted_update'];
	public const POLICIES = ['all', 'followed', 'follower', 'none'];
	private const MAX_PER_ACCOUNT = 20;
	private const MAX_ENDPOINT_BYTES = 2048;

	public function __construct(
		private ConfigService $configService,
		private ICrypto $crypto,
		private ILockingProvider $lockingProvider,
		private LoggerInterface $logger,
	) {
	}

	/** @return list<array{id: string, token: string, accessToken?: string, endpoint: string, p256dh: string, auth: string, standard: bool, alerts: array<string, bool>, policy: string, created: int}> */
	public function all(string $userId): array {
		return array_values($this->read($userId));
	}

	/**
	 * Whether the account may have a subscription, without decrypting the
	 * store: an account that never subscribed, or removed every subscription,
	 * has no stored value.
	 */
	public function hasAny(string $userId): bool {
		return $userId !== '' && $this->configService->getUserValue(self::CONFIG_KEY, $userId) !== '';
	}

	/** @return array{id: string, token: string, accessToken?: string, endpoint: string, p256dh: string, auth: string, standard: bool, alerts: array<string, bool>, policy: string, created: int}|null */
	public function forToken(string $userId, string $token): ?array {
		foreach ($this->read($userId) as $subscription) {
			if (hash_equals($subscription['token'], $token)) {
				return $subscription;
			}
		}

		return null;
	}

	/** @param array<string, mixed> $alerts */
	public function save(
		string $userId,
		string $token,
		string $accessToken,
		string $endpoint,
		string $p256dh,
		string $auth,
		array $alerts,
		string $policy,
		bool $standard = false,
	): array {
		if ($userId === '' || $token === '' || $accessToken === '') {
			throw new RefusalException('A signed-in account and app token are required', 'push-auth-required');
		}
		self::validateEndpoint($endpoint);
		if (strlen($p256dh) > 256 || preg_match('/^[A-Za-z0-9_-]{80,100}$/', $p256dh) !== 1
			|| strlen($auth) > 128 || preg_match('/^[A-Za-z0-9_-]{16,64}$/', $auth) !== 1) {
			throw new RefusalException('The push subscription keys are invalid', 'push-keys-invalid');
		}
		if (!in_array($policy, self::POLICIES, true)) {
			throw new RefusalException('The notification policy is invalid', 'push-policy-invalid');
		}
		$normalizedAlerts = self::normalizeAlerts($alerts);

		return $this->mutate($userId, function (array &$subscriptions) use ($token, $accessToken, $endpoint, $p256dh, $auth, $normalizedAlerts, $policy, $standard): array {
			$existingId = '';
			$created = time();
			foreach ($subscriptions as $id => $subscription) {
				if (hash_equals($subscription['token'], $token)) {
					$existingId = (string)$id;
					$created = $subscription['created'];
					unset($subscriptions[$id]);
				}
			}
			if ($existingId === '' && count($subscriptions) >= self::MAX_PER_ACCOUNT) {
				throw new RefusalException(
					'This account already has the maximum number of push subscriptions',
					'push-subscriptions-full',
					null,
					['limit' => self::MAX_PER_ACCOUNT],
				);
			}

			$id = $existingId !== '' ? $existingId : (string)random_int(1, PHP_INT_MAX);
			$subscription = [
				'id' => $id,
				'token' => $token,
				'accessToken' => $accessToken,
				'endpoint' => $endpoint,
				'p256dh' => $p256dh,
				'auth' => $auth,
				'standard' => $standard,
				'alerts' => $normalizedAlerts,
				'policy' => $policy,
				'created' => $created,
			];
			$subscriptions[$id] = $subscription;

			return $subscription;
		});
	}

	/** @return list<array{id: string, token: string, accessToken: string, endpoint: string, p256dh: string, auth: string, standard: bool, alerts: array<string, bool>, policy: string, created: int}> */
	public function forUser(string $userId): array {
		$subscriptions = [];
		foreach ($this->read($userId) as $subscription) {
			$accessToken = $subscription['accessToken'] ?? '';
			if ($accessToken === '') {
				continue;
			}
			$subscription['accessToken'] = $accessToken;
			$subscriptions[] = $subscription;
		}

		return $subscriptions;
	}

	public function deleteById(string $userId, string $id): bool {
		return $this->mutate($userId, function (array &$subscriptions) use ($id): bool {
			if (!isset($subscriptions[$id])) {
				return false;
			}
			unset($subscriptions[$id]);

			return true;
		});
	}

	public function update(string $userId, string $token, array $alerts, string $policy): ?array {
		if (!in_array($policy, self::POLICIES, true)) {
			throw new RefusalException('The notification policy is invalid', 'push-policy-invalid');
		}
		$normalizedAlerts = self::normalizeAlerts($alerts);

		return $this->mutate($userId, function (array &$subscriptions) use ($token, $normalizedAlerts, $policy): ?array {
			foreach ($subscriptions as $id => $subscription) {
				if (hash_equals($subscription['token'], $token)) {
					$subscriptions[$id]['alerts'] = $normalizedAlerts;
					$subscriptions[$id]['policy'] = $policy;

					return $subscriptions[$id];
				}
			}

			return null;
		});
	}

	public function delete(string $userId, string $token): bool {
		return $this->mutate($userId, function (array &$subscriptions) use ($token): bool {
			$removed = false;
			foreach ($subscriptions as $id => $subscription) {
				if (hash_equals($subscription['token'], $token)) {
					unset($subscriptions[$id]);
					$removed = true;
				}
			}

			return $removed;
		});
	}

	/**
	 * Removes every subscription of the account. Each one is bound to one of
	 * its OAuth access tokens, so this is what goes with all of them.
	 */
	public function deleteAll(string $userId): void {
		if (!$this->hasAny($userId)) {
			return;
		}

		$this->mutate($userId, static function (array &$subscriptions): void {
			$subscriptions = [];
		});
	}

	/** @return array<string, bool> */
	private static function normalizeAlerts(array $alerts): array {
		$normalized = [];
		foreach (self::ALERTS as $alert) {
			$value = $alerts[$alert] ?? false;
			if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
				throw new RefusalException('The push alert preferences are invalid', 'push-alerts-invalid');
			}
			$normalized[$alert] = in_array($value, [true, 1, '1', 'true'], true);
		}

		return $normalized;
	}

	private static function validateEndpoint(string $endpoint): void {
		$parts = parse_url($endpoint);
		if (strlen($endpoint) > self::MAX_ENDPOINT_BYTES
			|| !is_array($parts)
			|| strtolower($parts['scheme'] ?? '') !== 'https'
			|| empty($parts['host'])
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| isset($parts['fragment'])
			|| RemoteAddress::reachableAddresses($parts['host']) === []) {
			throw new RefusalException('The push endpoint must be a reachable public HTTPS URL', 'push-endpoint-unreachable');
		}
	}

	/**
	 * @param callable $change
	 */
	private function mutate(string $userId, callable $change): mixed {
		$lock = 'social-push-subscriptions-' . hash('sha256', $userId);
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$subscriptions = $this->read($userId);
			$result = $change($subscriptions);
			$this->write($userId, $subscriptions);

			return $result;
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return array<string, array{id: string, token: string, accessToken?: string, endpoint: string, p256dh: string, auth: string, standard: bool, alerts: array<string, bool>, policy: string, created: int}> */
	private function read(string $userId): array {
		$stored = $this->configService->getUserValue(self::CONFIG_KEY, $userId);
		if ($stored === '') {
			return [];
		}

		try {
			$plain = $this->crypto->decrypt($stored);
			$values = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$this->logger->error('Could not read encrypted Mastodon push subscriptions', ['exception' => $e]);
			throw new RuntimeException('The push subscription store cannot be read', 0, $e);
		}
		if (!is_array($values)) {
			throw new RuntimeException('The push subscription store is invalid');
		}

		return $values;
	}

	/** @param array<string, array{id: string, token: string, accessToken?: string, endpoint: string, p256dh: string, auth: string, standard: bool, alerts: array<string, bool>, policy: string, created: int}> $subscriptions */
	private function write(string $userId, array $subscriptions): void {
		if ($subscriptions === []) {
			$this->configService->setValueForUser($userId, self::CONFIG_KEY, '');

			return;
		}
		try {
			$plain = json_encode($subscriptions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
			$encrypted = $this->crypto->encrypt($plain);
		} catch (Throwable $e) {
			throw new RuntimeException('Could not store push subscriptions', 0, $e);
		}
		$this->configService->setValueForUser($userId, self::CONFIG_KEY, $encrypted);
	}
}
