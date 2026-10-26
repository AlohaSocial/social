<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\RecommendationPreferenceRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\RecommendationPreferenceService;
use PHPUnit\Framework\TestCase;

class RecommendationPreferenceServiceTest extends TestCase {
	private RecommendationPreferenceRequest $request;
	private ConfigService $config;
	private RecommendationPreferenceService $service;

	protected function setUp(): void {
		$this->request = $this->createMock(RecommendationPreferenceRequest::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->service = new RecommendationPreferenceService($this->request, $this->config);
	}

	public function testFeatureFlagIsOffUnlessTheInstanceEnablesIt(): void {
		$this->request->expects($this->never())->method('get');
		$this->config->expects($this->once())->method('getAppValueBool')->with(ConfigService::REC_V4_ENABLED)->willReturn(false);
		$this->assertFalse($this->service->isEnabled());
	}

	public function testAdminOperationalSettingsDefaultOffAndSaveOnlyNamedFlags(): void {
		$this->request->expects($this->never())->method('get');
		$this->request->expects($this->once())->method('invalidateAllLearningGenerations');
		$this->config->method('getAppValueBool')->willReturn(false);
		$this->config->expects($this->once())->method('setAppValue')
			->with(ConfigService::REC_LEARNING_PAUSED, '1');

		$this->assertSame([
			'enabled' => false,
			'remoteDiscoveryPaused' => false,
			'learningPaused' => false,
			'forceLegacyFeed' => false,
		], $this->service->adminSettings());

		$this->assertSame([
			'enabled' => false,
			'remoteDiscoveryPaused' => false,
			'learningPaused' => false,
			'forceLegacyFeed' => false,
		], $this->service->saveAdminSettings(null, null, true, null));
	}

	public function testChangingTheGlobalLearningPauseInvalidatesEveryOpenClientGeneration(): void {
		$paused = true;
		$this->config->method('getAppValueBool')->willReturnCallback(
			static function (string $key) use (&$paused): bool {
				return $key === ConfigService::REC_LEARNING_PAUSED && $paused;
			}
		);
		$this->config->expects($this->once())->method('setAppValue')
			->with(ConfigService::REC_LEARNING_PAUSED, '0')
			->willReturnCallback(static function (string $key, string $value) use (&$paused): void {
				$paused = $value === '1';
			});
		$this->request->expects($this->once())->method('invalidateAllLearningGenerations');

		$settings = $this->service->saveAdminSettings(null, null, false, null);

		$this->assertFalse($settings['learningPaused']);
	}

	public function testChangingTheRecommendationsKillSwitchInvalidatesOpenLearningGenerations(): void {
		$enabled = false;
		$this->config->method('getAppValueBool')->willReturnCallback(
			static function (string $key) use (&$enabled): bool {
				return $key === ConfigService::REC_V4_ENABLED && $enabled;
			}
		);
		$this->config->expects($this->exactly(2))->method('setAppValue')
			->willReturnCallback(static function (string $key, string $value) use (&$enabled): void {
				self::assertSame(ConfigService::REC_V4_ENABLED, $key);
				$enabled = $value === '1';
			});
		$this->request->expects($this->exactly(2))->method('invalidateAllLearningGenerations');

		$this->assertTrue($this->service->setEnabled(true));
		$this->assertFalse($this->service->setEnabled(false));
	}

	public function testConsentStateExposesOnlyRevisionGenerationAndLearningMode(): void {
		$this->config->expects($this->never())->method('getAppValueBool');
		$this->request->expects($this->once())->method('get')->with('alice')->willReturn([
			'revision' => 4,
			'generation' => 2,
			'learning_mode' => 'on',
			'topics' => [['topic_id' => 'tech.apple', 'mode' => 'HIDE']],
			'content_balance' => 'discovery',
		]);

		$this->assertSame([
			'revision' => 4,
			'generation' => 2,
			'learning_mode' => 'on',
			'learning' => true,
		], $this->service->get('alice'));
	}

	public function testLearningModeDefaultsToOffWhenTheStoredValueIsUnknown(): void {
		$this->config->expects($this->never())->method('getAppValueBool');
		$this->request->expects($this->once())->method('get')->with('alice')->willReturn([
			'revision' => 0,
			'generation' => 0,
			'learning_mode' => 'unknown',
		]);

		$this->assertFalse($this->service->get('alice')['learning']);
	}
}
