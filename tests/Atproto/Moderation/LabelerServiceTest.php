<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Moderation;

use InvalidArgumentException;
use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoLabelerRequest;
use OCA\Social\Model\Client\Filter;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class LabelerServiceTest extends TestCase {
	private const MOD = 'did:plc:ar7c4by46qjdydhdevvrndac';
	private const XBLOCK = 'did:plc:newitj5jo3uel7o4mnf3vj2o';

	/** @var array<string, array<string, array<string, string>>> */
	private array $rows = [];
	/** @var AppViewClient&MockObject */
	private AppViewClient $appView;
	private LabelerService $service;

	protected function setUp(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('moderationDid')->willReturn(self::MOD);
		$request = $this->createMock(AtprotoLabelerRequest::class);
		$request->method('getByUser')->willReturnCallback(fn (string $user): array => $this->rows[$user] ?? []);
		$request->method('subscribe')->willReturnCallback(function (string $user, string $did): bool {
			$this->rows[$user][$did] ??= [];

			return true;
		});
		$request->method('unsubscribe')->willReturnCallback(function (string $user, string $did): bool {
			unset($this->rows[$user][$did]);

			return true;
		});
		$request->method('setSettings')->willReturnCallback(function (string $user, string $did, array $settings): void {
			$this->rows[$user][$did] = $settings;
		});
		$request->method('getSubscribedDids')->willReturn([self::XBLOCK]);
		$this->appView = $this->createMock(AppViewClient::class);
		$this->appView->method('query')->willReturnCallback(static function (string $method, array $params): array {
			if ($method === 'com.atproto.identity.resolveHandle') {
				return ['did' => $params['handle'] === 'xblock.aendra.dev' ? self::XBLOCK : 'did:plc:notalabeler00000000000000'];
			}
			$views = [];
			foreach ($params['dids'] as $did) {
				if ($did === self::XBLOCK) {
					$views[] = ['creator' => ['did' => self::XBLOCK, 'handle' => 'xblock.aendra.dev', 'displayName' => 'XBlock'], 'policies' => [
						'labelValues' => ['twitter-screenshot', 'uncategorised-screenshot', '!hide'],
						'labelValueDefinitions' => [
							['identifier' => 'twitter-screenshot', 'severity' => 'inform', 'blurs' => 'media', 'defaultSetting' => 'hide', 'locales' => [['lang' => 'de', 'name' => 'X-Bildschirmfoto', 'description' => ''], ['lang' => 'en', 'name' => 'Screenshot of X', 'description' => 'A screenshot of a post on X']]],
						],
					]];
				}
				if ($did === self::MOD) {
					$views[] = ['creator' => ['did' => self::MOD, 'handle' => 'moderation.bsky.app', 'displayName' => 'Bluesky Moderation Service'], 'policies' => ['labelValues' => ['porn', 'spam']]];
				}
			}

			return ['views' => $views];
		});
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$this->service = new LabelerService($config, $request, $this->appView, new NullLogger(), $factory);
	}

	public function testBlueskysOwnServiceIsAlwaysThereAndCannotBeRemoved(): void {
		$labelers = $this->service->forUser('alice');
		$this->assertCount(1, $labelers);
		$this->assertSame(self::MOD, $labelers[0]['did']);
		$this->assertFalse($labelers[0]['removable']);
		$this->assertSame('Bluesky Moderation Service', $labelers[0]['name']);
	}

	public function testALabelerIsSubscribedByHandleWithItsOwnDefaults(): void {
		$this->assertSame(self::XBLOCK, $this->service->subscribe('alice', '@xblock.aendra.dev'));
		$xblock = $this->service->forUser('alice')[1];
		$this->assertSame('XBlock', $xblock['name']);
		$this->assertTrue($xblock['removable']);
		$this->assertSame(['value' => 'twitter-screenshot', 'name' => 'Screenshot of X', 'description' => 'A screenshot of a post on X', 'setting' => 'hide'], $xblock['labels'][0], 'English, and the labeler\'s default');
		$this->assertSame('warn', $xblock['labels'][1]['setting'], 'a value without a definition warns');
		$this->assertCount(2, $xblock['labels'], 'no `!` values: those are Bluesky\'s own');
	}

	public function testSomethingThatIsNoLabelerIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->subscribe('alice', 'someone.bsky.social');
	}

	public function testTheChoicesDecideWhatALabelDoes(): void {
		$this->service->subscribe('alice', self::XBLOCK);
		$labels = [['src' => self::XBLOCK, 'val' => 'twitter-screenshot'], ['src' => self::XBLOCK, 'val' => 'uncategorised-screenshot'], ['src' => 'did:plc:someoneelse0000000000000', 'val' => 'spam'], ['src' => self::XBLOCK, 'val' => '!hide']];

		$results = $this->service->results('alice', $labels);
		$this->assertSame([Filter::ACTION_HIDE, Filter::ACTION_WARN], array_map(static fn (array $r): string => $r['filter']['filter_action'], $results));
		$this->assertSame('Screenshot of X', $results[0]['filter']['title']);
		$this->assertSame('bluesky-label:' . self::XBLOCK . ':twitter-screenshot', $results[0]['filter']['id']);

		$this->service->setSetting('alice', self::XBLOCK, 'twitter-screenshot', 'ignore');
		$this->assertSame([Filter::ACTION_WARN], array_map(static fn (array $r): string => $r['filter']['filter_action'], $this->service->results('alice', $labels)));
		$this->assertSame([], $this->service->results('bob', $labels), 'bob subscribes to nothing');
	}

	public function testASettingIsOneOfThreeForASubscribedLabeler(): void {
		$this->service->subscribe('alice', self::XBLOCK);
		foreach ([[self::XBLOCK, 'x', 'blur'], [self::MOD, 'porn', 'hide'], [self::XBLOCK, 'Not A Label', 'hide']] as [$did, $label, $setting]) {
			try {
				$this->service->setSetting('alice', $did, $label, $setting);
				$this->fail('accepted ' . $did . ' ' . $label . ' ' . $setting);
			} catch (InvalidArgumentException) {
			}
		}
		$this->assertTrue($this->service->unsubscribe('alice', self::XBLOCK));
		$this->assertCount(1, $this->service->forUser('alice'));
	}

	public function testTheSharedReadsAskForEveryLabelerSubscribedHere(): void {
		$this->assertSame(self::MOD . ', ' . self::XBLOCK, $this->service->acceptHeader());
	}
}
