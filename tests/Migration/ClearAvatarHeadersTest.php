<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\ClearAvatarHeaders;
use OCA\Social\Service\ConfigService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The repair step that removes the avatar the old header fallback stored as
 * the banner of a cached local actor (alohasocial/social#2487).
 */
#[AllowMockObjectsWithoutExpectations]
class ClearAvatarHeadersTest extends TestCase {
	private const MARKER = 'migration_avatar_headers_cleared';

	private ConfigService&MockObject $configService;

	protected function setUp(): void {
		parent::setUp();
		$this->configService = $this->createMock(ConfigService::class);
	}

	private function repair(FakeConnection $connection): void {
		(new ClearAvatarHeaders($connection, $this->configService))->run($this->createStub(IOutput::class));
	}

	/** @return array<string, mixed> a cached local actor row */
	private function row(int $nid, string $header, string $icon = 'https://cloud.example/index.php/avatar/alice/128', string $handle = 'alice'): array {
		$source = ['id' => 'https://cloud.example/apps/social/users/' . $handle, 'type' => 'Person'];
		if ($icon !== '') {
			$source['icon'] = ['type' => 'Image', 'url' => $icon];
		}
		if ($header !== '') {
			$source['image'] = ['type' => 'Image', 'url' => $header];
		}

		return ['nid' => $nid, 'preferred_username' => $handle, 'source' => json_encode($source, JSON_UNESCAPED_SLASHES)];
	}

	public static function avatarHeaders(): array {
		return [
			'the icon itself' => ['https://cloud.example/index.php/avatar/alice/128'],
			'the route with pretty URLs' => ['https://cloud.example/avatar/alice/128'],
			'another size' => ['https://cloud.example/nextcloud/index.php/avatar/alice/512'],
			'the dark variant' => ['https://cloud.example/index.php/avatar/alice/64/dark'],
		];
	}

	#[DataProvider('avatarHeaders')]
	public function testTheAccountsOwnAvatarIsTakenOffTheStoredDocument(string $header): void {
		$connection = new FakeConnection([[$this->row(7, $header)]]);

		$this->repair($connection);

		$writes = $connection->writes();
		$this->assertCount(1, $writes);
		$this->assertSame('social_cache_actor', $writes[0]->table);
		$this->assertSame(['nid = 7'], $writes[0]->wheres);
		$source = json_decode((string)$writes[0]->sets['source'], true);
		$this->assertArrayNotHasKey('image', $source);
		// the rest of the document is kept as it was
		$this->assertSame('https://cloud.example/index.php/avatar/alice/128', $source['icon']['url']);
	}

	public function testTheRouteOfTheHandleCountsWithoutAnIcon(): void {
		$connection = new FakeConnection([[$this->row(7, 'https://cloud.example/index.php/avatar/alice/128', '')]]);

		$this->repair($connection);

		$this->assertCount(1, $connection->writes());
	}

	public static function realBanners(): array {
		return [
			'an uploaded banner' => ['https://cloud.example/apps/social/media/9f0c.png'],
			'somebody else\'s avatar' => ['https://cloud.example/index.php/avatar/bob/128'],
		];
	}

	#[DataProvider('realBanners')]
	public function testABannerIsLeftAlone(string $header): void {
		$connection = new FakeConnection([[$this->row(7, $header)]]);

		$this->repair($connection);

		$this->assertSame([], $connection->writes());
	}

	public function testAnAccountWithoutAHeaderIsNotWritten(): void {
		$connection = new FakeConnection([[$this->row(7, ''), ['nid' => 8, 'preferred_username' => 'x', 'source' => '']]]);

		$this->repair($connection);

		$this->assertSame([], $connection->writes());
	}

	public function testOnlyLocalRowsAreReadPagedOnThePrimaryKey(): void {
		$first = [];
		for ($nid = 1; $nid <= 500; $nid++) {
			$first[] = $this->row($nid, '');
		}
		$connection = new FakeConnection([$first, []]);

		$this->repair($connection);

		$reads = array_values(array_filter(
			$connection->queries,
			static fn (FakeQueryBuilder $query): bool => $query->statements === 0
		));
		$this->assertCount(2, $reads);
		$this->assertSame('social_cache_actor', $reads[0]->table);
		$this->assertSame(['local = 1', 'nid > 0'], $reads[0]->wheres);
		$this->assertSame(['local = 1', 'nid > 500'], $reads[1]->wheres);
		$this->assertSame('nid asc', $reads[0]->orderBy);
	}

	public function testTheMarkerIsSetAndHonoured(): void {
		$this->configService->expects($this->once())->method('setAppValue')->with(self::MARKER, '1');
		$this->repair(new FakeConnection([[$this->row(7, 'https://cloud.example/avatar/alice/128')]]));

		$done = $this->createMock(ConfigService::class);
		$done->method('getAppValueInt')->with(self::MARKER)->willReturn(1);
		$connection = new FakeConnection([[$this->row(7, 'https://cloud.example/avatar/alice/128')]]);
		(new ClearAvatarHeaders($connection, $done))->run($this->createStub(IOutput::class));
		$this->assertSame([], $connection->queries);
	}
}
