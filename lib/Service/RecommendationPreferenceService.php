<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;
use OCA\Social\Db\RecommendationPreferenceRequest;

/** Owns revisioned, account-scoped activity-learning consent. */
class RecommendationPreferenceService {
	public function __construct(
		private RecommendationPreferenceRequest $request,
		private ConfigService $configService,
	) {
	}

	/** Instance kill switch: absent configuration is always disabled. */
	public function isEnabled(): bool {
		return $this->configService->getAppValueBool(ConfigService::REC_V4_ENABLED);
	}

	public function setEnabled(bool $enabled): bool {
		if ($this->isEnabled() !== $enabled) {
			$this->request->invalidateAllLearningGenerations();
		}
		$this->configService->setAppValue(ConfigService::REC_V4_ENABLED, $enabled ? '1' : '0');
		return $this->isEnabled();
	}

	/** Whether For You may request new posts from remote discovery providers. */
	public function remoteDiscoveryPaused(): bool {
		return $this->configService->getAppValueBool(ConfigService::REC_REMOTE_DISCOVERY_PAUSED);
	}

	/** Global emergency stop for consented behavioural learning. */
	public function learningPaused(): bool {
		return $this->configService->getAppValueBool(ConfigService::REC_LEARNING_PAUSED);
	}

	/** Whether the web For You page must use the established interest feed. */
	public function forceLegacyFeed(): bool {
		return $this->configService->getAppValueBool(ConfigService::REC_FORCE_LEGACY_FEED);
	}

	/** @return array{enabled: bool, remoteDiscoveryPaused: bool, learningPaused: bool, forceLegacyFeed: bool} */
	public function adminSettings(): array {
		return [
			'enabled' => $this->isEnabled(),
			'remoteDiscoveryPaused' => $this->remoteDiscoveryPaused(),
			'learningPaused' => $this->learningPaused(),
			'forceLegacyFeed' => $this->forceLegacyFeed(),
		];
	}

	/** Update only supplied operational switches and return their effective state. */
	public function saveAdminSettings(?bool $enabled, ?bool $remoteDiscoveryPaused, ?bool $learningPaused, ?bool $forceLegacyFeed): array {
		if ($enabled !== null) {
			$this->setEnabled($enabled);
		}
		if ($learningPaused !== null) {
			if ($this->learningPaused() !== $learningPaused) {
				$this->request->invalidateAllLearningGenerations();
			}
			$this->configService->setAppValue(ConfigService::REC_LEARNING_PAUSED, $learningPaused ? '1' : '0');
		}
		foreach ([
			ConfigService::REC_REMOTE_DISCOVERY_PAUSED => $remoteDiscoveryPaused,
			ConfigService::REC_FORCE_LEGACY_FEED => $forceLegacyFeed,
		] as $key => $value) {
			if ($value !== null) {
				$this->configService->setAppValue($key, $value ? '1' : '0');
			}
		}
		return $this->adminSettings();
	}

	/** @return array{revision: int, generation: int, learning_mode: string, learning: bool} */
	public function get(string $actorKey): array {
		return self::learningState($this->request->get($actorKey));
	}

	/** Change the separate, default-off consent for behavioural learning. */
	public function setLearningMode(string $actorKey, int $revision, string $mode): ?array {
		if (!$this->isEnabled()) {
			throw new InvalidArgumentException('Recommendations are disabled');
		}
		$state = $this->request->setLearningMode($actorKey, $revision, $mode);
		return $state === null ? null : self::learningState($state);
	}

	/** @return array{revision: int, generation: int, learning_mode: string, learning: bool}|null */
	public function reset(string $actorKey, int $revision): ?array {
		$state = $this->request->reset($actorKey, $revision);
		return $state === null ? null : self::learningState($state);
	}

	/** Clear only implicit signals and increment their reset generation.
	 * @return array{revision: int, generation: int, learning_mode: string, learning: bool}|null
	 */
	public function resetLearning(string $actorKey, int $revision): ?array {
		$state = $this->request->resetLearning($actorKey, $revision);
		return $state === null ? null : self::learningState($state);
	}

	/** @param array<string, mixed> $state @return array{revision: int, generation: int, learning_mode: string, learning: bool} */
	private static function learningState(array $state): array {
		$mode = (string)($state['learning_mode'] ?? 'off');
		return [
			'revision' => (int)($state['revision'] ?? 0),
			'generation' => (int)($state['generation'] ?? 0),
			'learning_mode' => $mode,
			'learning' => array_key_exists('learning', $state) ? (bool)$state['learning'] : $mode === 'on',
		];
	}
}
