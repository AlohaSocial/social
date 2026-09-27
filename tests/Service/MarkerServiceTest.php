<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\MarkerService;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class MarkerServiceTest extends TestCase {
	private const USER = 'alice';

	private IUserConfig|Stub $userConfig;
	private MarkerService $service;

	/** in-memory stand-in for the user's stored config value */
	private string $stored = '{}';

	protected function setUp(): void {
		$this->userConfig = $this->createStub(IUserConfig::class);
		$this->userConfig->method('getValueString')->willReturnCallback(
			fn (string $user, string $app, string $key, string $default = '') => $this->stored
		);
		$this->userConfig->method('setValueString')->willReturnCallback(
			function (string $user, string $app, string $key, string $value): bool {
				$this->stored = $value;

				return true;
			}
		);

		$this->service = new MarkerService($this->userConfig);
	}

	public function testAnUnreadTimelineHasNoMarkerAndReadsAsZero(): void {
		$this->assertSame([], $this->service->get(self::USER));
		$this->assertSame('0', $this->service->lastReadId(self::USER, 'notifications'));
	}

	public function testAMarkerRoundTrips(): void {
		$this->service->set(self::USER, 'notifications', '42');

		$this->assertSame('42', $this->service->get(self::USER)['notifications']['last_read_id']);
		$this->assertSame('42', $this->service->lastReadId(self::USER, 'notifications'));
	}

	public function testTimelinesAreKeptApart(): void {
		$this->service->set(self::USER, 'notifications', '42');
		$this->service->set(self::USER, 'home', '7');

		$this->assertSame('42', $this->service->lastReadId(self::USER, 'notifications'));
		$this->assertSame('7', $this->service->lastReadId(self::USER, 'home'));
	}

	public function testOnlyTheTimelinesAskedForComeBack(): void {
		$this->service->set(self::USER, 'notifications', '42');
		$this->service->set(self::USER, 'home', '7');

		$this->assertSame(['home'], array_keys($this->service->get(self::USER, ['home'])));
	}

	public function testAMarkerNeverMovesBackwards(): void {
		$this->service->set(self::USER, 'notifications', '42');
		$returned = $this->service->set(self::USER, 'notifications', '10');

		// a second client further behind must not un-read what the first saw
		$this->assertSame('42', $this->service->lastReadId(self::USER, 'notifications'));
		$this->assertSame('42', $returned['last_read_id']);
	}

	public function testTheVersionCountsForwardMoves(): void {
		$this->assertSame(1, $this->service->set(self::USER, 'notifications', '1')['version']);
		$this->assertSame(2, $this->service->set(self::USER, 'notifications', '2')['version']);
		// the refused one does not count
		$this->assertSame(2, $this->service->set(self::USER, 'notifications', '1')['version']);
	}

	/**
	 * A position is a nid, which is wider than a PHP int on a 32-bit server.
	 * Cast, two wide positions compare equal at PHP_INT_MAX, so the marker
	 * refused to move forward between them and the badge never cleared.
	 */
	public function testAWidePositionIsKeptAndComparedExactly(): void {
		$this->service->set(self::USER, 'notifications', '92233720368547758070');
		$this->assertSame('92233720368547758070', $this->service->lastReadId(self::USER, 'notifications'));

		$this->service->set(self::USER, 'notifications', '92233720368547758071');
		$this->assertSame('92233720368547758071', $this->service->lastReadId(self::USER, 'notifications'));

		$this->service->set(self::USER, 'notifications', '92233720368547758070');
		$this->assertSame('92233720368547758071', $this->service->lastReadId(self::USER, 'notifications'));
	}

	public function testStoredNonsenseReadsAsUnreadRatherThanBlowingUp(): void {
		$this->stored = 'not json at all';
		$this->assertSame([], $this->service->getAll(self::USER));

		$this->stored = '{"notifications":{"last_read_id":"abc"}}';
		$this->assertSame('0', $this->service->lastReadId(self::USER, 'notifications'));
	}
}
