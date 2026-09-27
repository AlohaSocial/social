<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\AccessBlocksRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\AccessBlock;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The instance-wide lists of blocked addresses and email domains. Values from
 * the documentation ranges, so nothing a real instance would block.
 */
class AccessBlocksRequestTest extends TestCase {
	private const IP = '203.0.113.7';
	private const RANGE = '203.0.113.0/28';
	private const DOMAIN = 'blocked.itest.example';

	private AccessBlocksRequest $blocks;

	protected function setUp(): void {
		parent::setUp();
		$this->blocks = Server::get(AccessBlocksRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (AccessBlock::TYPES as $type) {
			foreach ($this->blocks->getByType($type) as $block) {
				if (in_array($block->getValue(), [self::IP, self::RANGE, self::DOMAIN], true)) {
					$this->blocks->delete($block->getId(), $type);
				}
			}
		}
	}

	/** @return AccessBlock[] */
	private function mine(string $type): array {
		return array_values(array_filter(
			$this->blocks->getByType($type),
			static fn (AccessBlock $block): bool => in_array($block->getValue(), [self::IP, self::RANGE, self::DOMAIN], true)
		));
	}

	public function testABlockIsKeptWithItsSeverityCommentAndExpiry(): void {
		$expires = time() + 86400;
		$this->blocks->save(new AccessBlock(AccessBlock::TYPE_IP, self::IP, AccessBlock::SEVERITY_NO_ACCESS, 'scraper', $expires));

		[$block] = $this->mine(AccessBlock::TYPE_IP);
		$this->assertSame(AccessBlock::SEVERITY_NO_ACCESS, $block->getSeverity());
		$this->assertSame('scraper', $block->getComment());
		$this->assertSame($expires, $block->getExpires());
		$this->assertGreaterThan(0, $block->getCreation());
		$this->assertSame(self::IP, $this->blocks->getById($block->getId(), AccessBlock::TYPE_IP)->getValue());
	}

	/** the same value twice is a change to the block, not a second one */
	public function testSavingTheSameValueAgainUpdatesTheBlock(): void {
		$this->blocks->save(new AccessBlock(AccessBlock::TYPE_IP, self::RANGE, AccessBlock::SEVERITY_SIGN_UP_BLOCK, 'first', time() + 60));
		$this->blocks->save(new AccessBlock(AccessBlock::TYPE_IP, self::RANGE, AccessBlock::SEVERITY_NO_ACCESS, 'second'));

		$mine = $this->mine(AccessBlock::TYPE_IP);
		$this->assertCount(1, $mine);
		$this->assertSame(AccessBlock::SEVERITY_NO_ACCESS, $mine[0]->getSeverity());
		$this->assertSame('second', $mine[0]->getComment());
		$this->assertSame(0, $mine[0]->getExpires(), 'no expiry is kept as none');
	}

	public function testTheTwoListsAreSeparate(): void {
		$this->blocks->save(new AccessBlock(AccessBlock::TYPE_EMAIL_DOMAIN, self::DOMAIN, AccessBlock::SEVERITY_SIGN_UP_BLOCK));

		$this->assertCount(1, $this->mine(AccessBlock::TYPE_EMAIL_DOMAIN));
		$this->assertCount(0, $this->mine(AccessBlock::TYPE_IP));

		$id = $this->mine(AccessBlock::TYPE_EMAIL_DOMAIN)[0]->getId();
		$this->expectException(ItemNotFoundException::class);
		$this->blocks->getById($id, AccessBlock::TYPE_IP);
	}

	public function testDeletingRemovesTheBlockAndAnUnknownOneIsNotFound(): void {
		$this->blocks->save(new AccessBlock(AccessBlock::TYPE_IP, self::IP));
		$id = $this->mine(AccessBlock::TYPE_IP)[0]->getId();

		$this->blocks->delete($id, AccessBlock::TYPE_IP);
		$this->assertSame([], $this->mine(AccessBlock::TYPE_IP));

		$this->expectException(ItemNotFoundException::class);
		$this->blocks->delete($id, AccessBlock::TYPE_IP);
	}
}
