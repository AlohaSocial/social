<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\PortfoliosRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\Portfolio;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The portfolio page: one per account, created the first time it is saved.
 */
class PortfoliosRequestTest extends TestCase {
	private const ALICE = 'https://itest.example/users/portfolio-alice';

	private PortfoliosRequest $portfolios;

	protected function setUp(): void {
		parent::setUp();
		$this->portfolios = Server::get(PortfoliosRequest::class);
		$this->portfolios->deleteByActor(self::ALICE);
	}

	protected function tearDown(): void {
		$this->portfolios->deleteByActor(self::ALICE);
		parent::tearDown();
	}

	public function testThereIsNoPortfolioUntilOneIsSaved(): void {
		$this->expectException(ItemNotFoundException::class);
		$this->portfolios->getByActor(self::ALICE);
	}

	public function testTheFirstSaveCreatesItAndLaterSavesUpdateIt(): void {
		$created = $this->portfolios->save((new Portfolio())
			->setActorId(self::ALICE)
			->setTitle('Photographs')
			->setShowCaptions(true));
		$this->assertGreaterThan(0, $created->getId());

		$this->portfolios->save((new Portfolio())
			->setActorId(self::ALICE)
			->setActive(true)
			->setTitle('Landscapes')
			->setIntro('Mostly mountains')
			->setCollectionId(7)
			->setShowCaptions(false)
			->setShowPlaces(true));

		$stored = $this->portfolios->getByActor(self::ALICE);
		$this->assertSame($created->getId(), $stored->getId());
		$this->assertTrue($stored->isActive());
		$this->assertSame('Landscapes', $stored->getTitle());
		$this->assertSame('Mostly mountains', $stored->getIntro());
		$this->assertSame(7, $stored->getCollectionId());
		$this->assertFalse($stored->showsCaptions());
		$this->assertTrue($stored->showsPlaces());
	}
}
