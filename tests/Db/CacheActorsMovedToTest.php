<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CacheActorsRequestBuilder;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Tools\Exceptions\RowNotFoundException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A cached actor that moved is read together with the account it moved to, so
 * the client entity's `moved` is the real account and not a stub derived from
 * an id.
 *
 * The query for the target cannot run here — SocialQueryBuilder extends a
 * server class this suite has no copy of — so the one method that runs it is
 * the seam: everything around it is exercised for real.
 */
#[AllowMockObjectsWithoutExpectations]
class CacheActorsMovedToTest extends TestCase {
	private const ALICE = 'https://mastodon.social/users/alice';
	private const NEW_ALICE = 'https://new.example/users/alice';

	/** @var CacheActorsRequestBuilder&MockObject */
	private $builder;

	protected function setUp(): void {
		$this->builder = $this->getMockBuilder(CacheActorsRequestBuilder::class)
			->disableOriginalConstructor()
			->onlyMethods(['cachedMovedTarget'])
			->getMock();
	}

	private function actor(string $id, string $movedTo = ''): Person {
		$actor = new Person();
		$actor->setId($id)
			->setPreferredUsername('alice')
			->setAccount('alice@' . parse_url($id, PHP_URL_HOST))
			->setMovedTo($movedTo);

		return $actor;
	}

	public function testAnActorThatMovedCarriesTheCachedTarget(): void {
		$target = $this->actor(self::NEW_ALICE);
		$this->builder->expects($this->once())->method('cachedMovedTarget')
			->with(self::NEW_ALICE)->willReturn($target);

		$actor = $this->actor(self::ALICE, self::NEW_ALICE);
		$this->builder->attachMovedTarget($actor);

		$this->assertSame($target, $actor->getMovedToActor());
		$this->assertSame('alice@new.example', $actor->exportAsLocal()['moved']['acct']);
	}

	public function testAnActorThatDidNotMoveCostsNoSecondQuery(): void {
		$this->builder->expects($this->never())->method('cachedMovedTarget');

		$actor = $this->actor(self::ALICE);
		$this->builder->attachMovedTarget($actor);

		$this->assertNull($actor->getMovedToActor());
	}

	public function testAnUncachedTargetLeavesTheStubToTheModel(): void {
		$this->builder->method('cachedMovedTarget')->willThrowException(new RowNotFoundException());

		$actor = $this->actor(self::ALICE, self::NEW_ALICE);
		$this->builder->attachMovedTarget($actor);

		$this->assertNull($actor->getMovedToActor());
		$this->assertSame(self::NEW_ALICE, $actor->exportAsLocal()['moved']['url']);
	}

	/**
	 * The target row is parsed without attaching *its* target: one hop, so two
	 * accounts that point at each other cannot send the parser round in
	 * circles.
	 */
	public function testTheTargetIsParsedWithoutResolvingItsOwnTarget(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../lib/Db/CacheActorsRequestBuilder.php');
		$body = substr($source, strpos($source, 'function cachedMovedTarget'));

		$this->assertStringContainsString('parseCacheActorRow(', $body);
		$this->assertStringNotContainsString('parseCacheActorsSelectSql(', $body);
		$this->assertStringNotContainsString('attachMovedTarget(', $body);
	}
}
