<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use InvalidArgumentException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\SectionsService;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * What the instance offers: the four sections, and the groups that become lists.
 *
 * The defaults are the point of most of this. Three sections and stories are on
 * for an instance that has never been to the page, and the groups are empty --
 * a group list tells everybody in the group who else is in it, so nothing until
 * somebody chooses.
 */
class SectionsServiceTest extends TestCase {
	private ConfigService|Stub $configService;
	private IGroupManager|Stub $groupManager;
	private SectionsService $service;

	/** What the app values hold, so a write can be read back. */
	private array $stored = [];

	/** The groups this server has. */
	private array $groups = ['design', 'berlin', 'everyone'];

	protected function setUp(): void {
		$this->stored = [
			ConfigService::SOCIAL_STORIES => '1',
			ConfigService::SOCIAL_SECTION_PHOTOS => '1',
			ConfigService::SOCIAL_SECTION_VIDEOS => '1',
			ConfigService::SOCIAL_GROUP_LISTS => '[]',
		];

		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string => $this->stored[$key] ?? '');
		$this->configService->method('getAppValueBool')
			->willReturnCallback(fn (string $key): bool => ($this->stored[$key] ?? '0') === '1');
		$this->configService->method('setAppValue')
			->willReturnCallback(function (string $key, string $value): void {
				$this->stored[$key] = $value;
			});

		$this->groupManager = $this->createStub(IGroupManager::class);
		$this->groupManager->method('groupExists')
			->willReturnCallback(fn (string $gid): bool => in_array($gid, $this->groups, true));

		$this->service = new SectionsService($this->configService, $this->groupManager);
	}

	public function testEverythingIsOfferedByDefault(): void {
		$current = $this->service->current();

		$this->assertTrue($current[ConfigService::SOCIAL_STORIES]);
		$this->assertTrue($current[ConfigService::SOCIAL_SECTION_PHOTOS]);
		$this->assertTrue($current[ConfigService::SOCIAL_SECTION_VIDEOS]);
	}

	/** News is gone from the app, and with it the switch that offered it. */
	public function testNewsIsNoLongerASection(): void {
		$this->assertArrayNotHasKey('section_news', $this->service->current());
		$this->assertNotContains('section_news', SectionsService::KEYS);
	}

	public function testNoGroupBecomesAListByDefault(): void {
		$this->assertSame([], $this->service->current()[ConfigService::SOCIAL_GROUP_LISTS]);
		$this->assertFalse($this->service->groupHasList('design'));
	}

	public function testSavingTurnsSectionsOffAndOnAgain(): void {
		$saved = $this->service->save(false, false, true, []);

		$this->assertFalse($saved[ConfigService::SOCIAL_STORIES]);
		$this->assertFalse($saved[ConfigService::SOCIAL_SECTION_PHOTOS]);
		$this->assertTrue($saved[ConfigService::SOCIAL_SECTION_VIDEOS]);
		$this->assertFalse($this->service->storiesEnabled());

		$this->assertTrue($this->service->save(true, true, true, [])[ConfigService::SOCIAL_STORIES]);
		$this->assertTrue($this->service->storiesEnabled());
	}

	public function testChosenGroupsAreKeptInOrderAndAnsweredBack(): void {
		$saved = $this->service->save(true, true, true, ['berlin', 'design']);

		$this->assertSame(['berlin', 'design'], $saved[ConfigService::SOCIAL_GROUP_LISTS]);
		$this->assertTrue($this->service->groupHasList('berlin'));
		$this->assertTrue($this->service->groupHasList('design'));
		$this->assertFalse($this->service->groupHasList('everyone'));
	}

	public function testTheSameGroupTwiceIsStoredOnce(): void {
		$saved = $this->service->save(true, true, true, ['design', 'design', ' ']);

		$this->assertSame(['design'], $saved[ConfigService::SOCIAL_GROUP_LISTS]);
	}

	/**
	 * Refused rather than dropped: saving four of the five groups somebody
	 * picked, without saying so, is what all-or-nothing exists to avoid.
	 */
	public function testAGroupThatDoesNotExistIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->save(true, true, true, ['design', 'nosuchgroup']);
	}

	public function testNothingIsWrittenWhenOneGroupIsRefused(): void {
		try {
			$this->service->save(false, false, false, ['nosuchgroup']);
		} catch (InvalidArgumentException) {
		}

		$this->assertTrue($this->service->current()[ConfigService::SOCIAL_STORIES]);
	}

	public function testTooManyGroupsAreRefused(): void {
		$many = [];
		for ($i = 0; $i < SectionsService::MAX_GROUPS + 1; $i++) {
			$many[] = 'group' . $i;
		}
		$this->groups = $many;

		$this->expectException(InvalidArgumentException::class);
		$this->service->save(true, true, true, $many);
	}

	/**
	 * A group can be deleted after it was chosen. Dropped on the way out
	 * rather than written back, because the administrator may be about to
	 * recreate it and a read is not the place to decide they were not.
	 */
	public function testAGroupDeletedSinceItWasChosenIsNotAnswered(): void {
		$this->service->save(true, true, true, ['design', 'berlin']);
		$this->groups = ['design'];

		$this->assertSame(['design'], $this->service->groupLists());
		$this->assertFalse($this->service->groupHasList('berlin'));
	}

	public function testRubbishInTheStoredValueReadsAsNoGroups(): void {
		$this->stored[ConfigService::SOCIAL_GROUP_LISTS] = 'not json';

		$this->assertSame([], $this->service->groupLists());
	}
}
