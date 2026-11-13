<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AlohaDirectory;

use InvalidArgumentException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\InstanceService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * This server's place in the Aloha Directory: enrolment, the domain proof,
 * the profile, the hourly heartbeat, the status and the conversation with the
 * curators, and withdrawing.
 *
 * The phases:
 *
 * - `none`: not enrolled.
 * - `pending`: the directory issued a challenge, which the well-known handler
 *   serves; verification has not been asked for, or failed and may be retried
 *   while the challenge is valid (an hour).
 * - `verifying`: the directory checks the challenge in the background; `sync()`
 *   and the administration page ask for the outcome.
 * - `enrolled`: the domain is proven and requests are signed with the key.
 *   The directory decides about the listing; its state is in `status`.
 */
class DirectoryParticipation {
	public const PHASE_NONE = 'none';
	public const PHASE_PENDING = 'pending';
	public const PHASE_VERIFYING = 'verifying';
	public const PHASE_ENROLLED = 'enrolled';

	private const HEARTBEAT_SECONDS = 3600;
	/** Heartbeats closer together are refused by the directory. */
	private const HEARTBEAT_MIN_SECONDS = 300;
	private const STATUS_SECONDS = 6 * 3600;
	private const CHALLENGE_SECONDS = 3600;

	public function __construct(
		private DirectoryConfig $config,
		private DirectoryClient $client,
		private ProfileBuilder $profileBuilder,
		private HeartbeatBuilder $heartbeatBuilder,
		private ConfigService $configService,
		private InstanceService $instanceService,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$state = $this->config->json(DirectoryConfig::STATE);
		$state['phase'] = is_string($state['phase'] ?? null) ? $state['phase'] : self::PHASE_NONE;

		return $state;
	}

	public function phase(): string {
		return (string)$this->state()['phase'];
	}

	public function domain(): string {
		return $this->configService->getCloudHost();
	}

	/**
	 * Enrols this server and asks the directory to verify the domain.
	 *
	 * @throws InvalidArgumentException when the profile is not complete or the server is enrolled
	 * @throws DirectoryApiException
	 */
	public function enrol(): array {
		if (in_array($this->phase(), [self::PHASE_ENROLLED, self::PHASE_VERIFYING], true)) {
			throw new InvalidArgumentException('This server is enrolled already');
		}
		$missing = $this->profileBuilder->missing();
		if ($missing !== []) {
			throw new InvalidArgumentException('The profile is not complete: ' . implode(', ', $missing));
		}

		$domain = $this->domain();
		$key = DirectoryKey::generate();
		$answer = $this->client->enrol($domain, $key, $this->instanceService->getLocal()->getEmail());
		$challenge = is_string($answer['challenge'] ?? null) ? $answer['challenge'] : '';
		if ($challenge === '') {
			throw new DirectoryApiException(502, 'bad_answer', 'The directory answered the enrolment without a challenge');
		}

		$this->config->setKey($key, DirectoryConfig::PENDING_KEY);
		$this->config->setChallenge($challenge);
		$this->config->saveSettings(['advertise' => true]);
		$this->update([
			'phase' => self::PHASE_PENDING,
			'domain' => $domain,
			'pendingKeyId' => $key->keyId(),
			'challengeExpiresAt' => $this->time->getTime() + self::CHALLENGE_SECONDS,
			'verification' => null,
			'error' => '',
		]);

		return $this->requestVerification();
	}

	/**
	 * Asks the directory to check the challenge again, after a failure.
	 *
	 * @throws InvalidArgumentException
	 * @throws DirectoryApiException
	 */
	public function requestVerification(): array {
		$key = $this->config->key(DirectoryConfig::PENDING_KEY);
		if ($this->phase() !== self::PHASE_PENDING || $key === null) {
			throw new InvalidArgumentException('There is no enrolment waiting for its domain proof');
		}
		if ((int)($this->state()['challengeExpiresAt'] ?? 0) < $this->time->getTime()) {
			$this->reset();
			throw new InvalidArgumentException('The challenge has expired; enrol again');
		}

		$this->client->verify($this->domain(), $key);

		return $this->update(['phase' => self::PHASE_VERIFYING, 'verification' => ['status' => 'queued', 'checkedAt' => $this->time->getTime()]]);
	}

	/**
	 * Asks for the outcome of the verification. Once verified, the pending key
	 * becomes the key, and the profile and a first heartbeat are sent.
	 */
	public function checkVerification(): array {
		$key = $this->config->key(DirectoryConfig::PENDING_KEY);
		if ($this->phase() !== self::PHASE_VERIFYING || $key === null) {
			return $this->state();
		}

		try {
			$answer = $this->client->verificationStatus($this->domain(), $key);
		} catch (DirectoryApiException $e) {
			return $this->update(['error' => $e->getMessage()]);
		}

		$status = is_string($answer['status'] ?? null) ? $answer['status'] : 'queued';
		$verification = [
			'status' => $status,
			'error' => is_string($answer['error'] ?? null) ? $answer['error'] : '',
			'message' => is_string($answer['message'] ?? null) ? $answer['message'] : '',
			'checkedAt' => $this->time->getTime(),
		];
		if ($status === 'failed') {
			return $this->update(['phase' => self::PHASE_PENDING, 'verification' => $verification]);
		}
		if ($status !== 'verified') {
			return $this->update(['verification' => $verification]);
		}

		$this->config->setKey($key);
		$this->config->setKey(null, DirectoryConfig::PENDING_KEY);
		$this->config->setChallenge('');
		$this->update([
			'phase' => self::PHASE_ENROLLED,
			'keyId' => $key->keyId(),
			'pendingKeyId' => null,
			'enrolledAt' => $this->time->getTime(),
			'verification' => $verification,
			'error' => '',
		]);
		$this->pushProfile();
		$this->sendHeartbeat();
		$this->refreshStatus();

		return $this->state();
	}

	/**
	 * Sends the profile; when it is unchanged since the last accepted one, only
	 * when $force says so.
	 */
	public function pushProfile(bool $force = true): array {
		$key = $this->enrolledKey();
		if ($key === null) {
			return $this->state();
		}
		$profile = $this->profileBuilder->build();
		$sent = is_array($this->state()['profile'] ?? null) ? $this->state()['profile'] : [];
		if (!$force && ($sent['etag'] ?? '') === $profile['etag'] && ($sent['error'] ?? '') === '') {
			return $this->state();
		}

		try {
			$answer = $this->client->putProfile($this->domain(), $key, $profile);
		} catch (DirectoryApiException $e) {
			return $this->update(['profile' => [
				'etag' => $sent['etag'] ?? '',
				'sentAt' => $sent['sentAt'] ?? 0,
				'pendingReview' => $sent['pendingReview'] ?? false,
				'error' => $e->getMessage(),
				'details' => $e->getDetails(),
			]]);
		}

		return $this->update(['profile' => [
			'etag' => $profile['etag'],
			'sentAt' => $this->time->getTime(),
			'pendingReview' => (bool)($answer['pending_review'] ?? false),
			'error' => '',
			'details' => [],
		]]);
	}

	/** Sends the heartbeat now, and the profile again when the directory asks for it. */
	public function sendHeartbeat(): array {
		$key = $this->enrolledKey();
		if ($key === null || !$this->config->advertises()) {
			return $this->state();
		}

		$etag = (string)($this->profileBuilder->build()['etag'] ?? '');
		$previous = is_array($this->state()['heartbeat'] ?? null) ? $this->state()['heartbeat'] : [];
		try {
			$answer = $this->client->putHeartbeat($this->domain(), $key, $this->heartbeatBuilder->build($this->domain(), $etag));
		} catch (DirectoryApiException $e) {
			$failures = (int)($previous['failures'] ?? 0) + 1;

			return $this->update(['heartbeat' => [
				'at' => (int)($previous['at'] ?? 0),
				'attemptAt' => $this->time->getTime(),
				'failures' => $failures,
				'error' => $e->getMessage(),
			]]);
		}

		$this->update([
			'listing' => is_string($answer['state'] ?? null) ? $answer['state'] : null,
			'heartbeat' => ['at' => $this->time->getTime(), 'attemptAt' => $this->time->getTime(), 'failures' => 0, 'error' => ''],
		]);
		if (($answer['profile_requested'] ?? false) === true) {
			$this->pushProfile();
		}

		return $this->state();
	}

	/** Reads the listing state, the checks, the curators' questions and shared complaints. */
	public function refreshStatus(): array {
		$key = $this->enrolledKey();
		if ($key === null) {
			return $this->state();
		}

		try {
			$status = $this->client->status($this->domain(), $key);
		} catch (DirectoryApiException $e) {
			return $this->update(['error' => $e->getMessage()]);
		}

		return $this->update([
			'status' => $status,
			'statusAt' => $this->time->getTime(),
			'listing' => is_string($status['state'] ?? null) ? $status['state'] : null,
			'error' => '',
		]);
	}

	/**
	 * @throws InvalidArgumentException
	 * @throws DirectoryApiException
	 */
	public function comment(string $body): array {
		$this->client->comment($this->domain(), $this->requireKey(), $this->text($body));

		return $this->refreshStatus();
	}

	/**
	 * @throws InvalidArgumentException
	 * @throws DirectoryApiException
	 */
	public function respondToComplaint(int $complaintId, string $body): array {
		$this->client->respondToComplaint($this->domain(), $this->requireKey(), $complaintId, $this->text($body));

		return $this->refreshStatus();
	}

	/**
	 * Leaves the directory, or abandons an enrolment that is not verified yet.
	 *
	 * @throws DirectoryApiException
	 */
	public function withdraw(): array {
		$key = $this->enrolledKey();
		if ($key !== null) {
			try {
				$this->client->withdraw($this->domain(), $key);
			} catch (DirectoryApiException $e) {
				// a directory that no longer knows this key has nothing left to withdraw
				if ($e->getStatus() !== 401 && $e->getStatus() !== 404) {
					throw $e;
				}
			}
		}
		$this->config->saveSettings(['advertise' => false]);
		$this->reset();

		return $this->state();
	}

	/**
	 * Replaces the directory key, signed with the current one.
	 *
	 * @throws InvalidArgumentException
	 * @throws DirectoryApiException
	 */
	public function rotateKey(): array {
		$current = $this->requireKey();
		$next = DirectoryKey::generate();
		$this->client->rotateKey($this->domain(), $current, $next);
		$this->config->setKey($next);

		return $this->update(['keyId' => $next->keyId()]);
	}

	/**
	 * What the background job does every few minutes: asks how a verification
	 * went, sends the heartbeat when it is due (sooner after a failure, with
	 * backoff), sends the profile when it changed, and reads the status.
	 */
	public function sync(): void {
		$phase = $this->phase();
		if ($phase === self::PHASE_VERIFYING) {
			$this->checkVerification();

			return;
		}
		if ($phase === self::PHASE_PENDING && (int)($this->state()['challengeExpiresAt'] ?? 0) < $this->time->getTime()) {
			$this->reset();

			return;
		}
		if ($phase !== self::PHASE_ENROLLED || !$this->config->advertises()) {
			return;
		}

		$this->pushProfile(false);
		if ($this->heartbeatDue()) {
			$this->sendHeartbeat();
		}
		if ($this->time->getTime() - (int)($this->state()['statusAt'] ?? 0) >= self::STATUS_SECONDS) {
			$this->refreshStatus();
		}
	}

	private function heartbeatDue(): bool {
		$heartbeat = is_array($this->state()['heartbeat'] ?? null) ? $this->state()['heartbeat'] : [];
		$now = $this->time->getTime();
		$failures = (int)($heartbeat['failures'] ?? 0);
		if ($failures > 0) {
			// 5, 10, 20, 40 minutes, then hourly again
			$wait = min(self::HEARTBEAT_SECONDS, self::HEARTBEAT_MIN_SECONDS << min($failures - 1, 4));

			return $now - (int)($heartbeat['attemptAt'] ?? 0) >= $wait;
		}

		// a little early rather than late: the job runs every five minutes
		return $now - (int)($heartbeat['at'] ?? 0) >= self::HEARTBEAT_SECONDS - self::HEARTBEAT_MIN_SECONDS;
	}

	private function enrolledKey(): ?DirectoryKey {
		return $this->phase() === self::PHASE_ENROLLED ? $this->config->key() : null;
	}

	private function requireKey(): DirectoryKey {
		$key = $this->enrolledKey();
		if ($key === null) {
			throw new InvalidArgumentException('This server is not enrolled in the Aloha Directory');
		}

		return $key;
	}

	private function text(string $body): string {
		$body = trim($body);
		if ($body === '' || mb_strlen($body) > 5000) {
			throw new InvalidArgumentException('Write between 1 and 5000 characters');
		}

		return $body;
	}

	private function reset(): void {
		$this->config->setKey(null);
		$this->config->setKey(null, DirectoryConfig::PENDING_KEY);
		$this->config->setChallenge('');
		$this->config->setJson(DirectoryConfig::STATE, ['phase' => self::PHASE_NONE]);
	}

	/**
	 * @param array<string, mixed> $changes
	 *
	 * @return array<string, mixed>
	 */
	private function update(array $changes): array {
		$state = array_merge($this->state(), $changes);
		$this->config->setJson(DirectoryConfig::STATE, $state);
		if (($changes['error'] ?? '') !== '') {
			$this->logger->info('the Aloha Directory refused a request', ['error' => $changes['error']]);
		}

		return $state;
	}
}
