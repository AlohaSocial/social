<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\DiscoverCategoriesRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The hashtag shelves an administrator puts on the Discover page.
 */
class DiscoverCategoriesRequestTest extends TestCase {
	private DiscoverCategoriesRequest $categories;
	/** @var int[] */
	private array $created = [];

	protected function setUp(): void {
		parent::setUp();
		$this->categories = Server::get(DiscoverCategoriesRequest::class);
	}

	protected function tearDown(): void {
		foreach ($this->created as $id) {
			$this->categories->delete($id);
		}
		parent::tearDown();
	}

	private function create(string $name, array $hashtags, int $position): int {
		return $this->created[] = $this->categories->create($name, $hashtags, $position);
	}

	public function testCategoriesAreReadInTheAdministratorsOrder(): void {
		$before = $this->categories->count();
		$music = $this->create('itest Music', ['jazz', 'folk'], -99);
		$nature = $this->create('itest Nature', ['birds'], -100);

		$this->assertSame($before + 2, $this->categories->count());
		$ours = array_values(array_filter(
			$this->categories->getAll(),
			fn (array $c) => in_array($c['id'], [$music, $nature], true)
		));
		$this->assertSame([
			['id' => $nature, 'name' => 'itest Nature', 'hashtags' => ['birds']],
			['id' => $music, 'name' => 'itest Music', 'hashtags' => ['jazz', 'folk']],
		], $ours);
	}

	public function testADeletedCategoryIsGoneAndDeletingItAgainSaysSo(): void {
		$id = $this->create('itest Gone', ['x'], -98);

		$this->assertTrue($this->categories->delete($id));
		$this->assertFalse($this->categories->delete($id));
		$this->assertNotContains($id, array_column($this->categories->getAll(), 'id'));
	}
}
