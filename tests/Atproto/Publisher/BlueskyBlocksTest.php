<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\Publisher\BlueskyBlocks;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActorRelation;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyBlocksTest extends TestCase {
	private const BOB = 'https://bsky.app/profile/did:plc:bob';
	private const CAROL = 'https://mastodon.example/users/carol';

	private array $prefs = [];
	private array $written = [];
	private array $removed = [];

	private function blocks(): BlueskyBlocks {
		$publisher = $this->createMock(Publisher::class);
		$publisher->method('writeRecord')->willReturnCallback(function (Person $actor, string $collection, array $record, string $localId): bool {
			$this->written[] = [$collection, $record['subject'], $localId];

			return true;
		});
		$publisher->method('removeRecord')->willReturnCallback(function (string $collection, string $localId): bool {
			$this->removed[] = [$collection, $localId];

			return true;
		});
		$relations = $this->createMock(ActorRelationRequest::class);
		$relations->method('getByActor')->with('https://social.test/@alice', ActorRelation::TYPE_BLOCK)->willReturn(array_map(static function (string $id): ActorRelation {
			$relation = new ActorRelation();
			$relation->setObjectId($id);

			return $relation;
		}, [self::BOB, self::CAROL]));
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(fn (string $user, string $app, string $key, $default = ''): string => $this->prefs[$key] ?? $default);
		$config->method('setUserValue')->willReturnCallback(function (string $user, string $app, string $key, $value): void {
			$this->prefs[$key] = $value;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);

		return new BlueskyBlocks($publisher, $relations, $config, $time, new NullLogger());
	}

	private static function alice(): Person {
		return (new Person())->setId('https://social.test/@alice')->setUserId('alice');
	}

	public function testABlockStaysHereUntilThePersonPublishesTheirs(): void {
		$this->blocks()->blocked(self::alice(), (new Person())->setId(self::BOB));
		$this->assertFalse($this->blocks()->isPublished('alice'));
		$this->assertSame([], $this->written);
	}

	public function testPublishingSendsTheBlocksOfBlueskyAccountsHeldAndEachOneAfter(): void {
		$this->blocks()->setPublished(self::alice(), true);
		$this->assertTrue($this->blocks()->isPublished('alice'));
		$this->assertSame([[BlueskyBlocks::COLLECTION, 'did:plc:bob', BlueskyBlocks::localId('https://social.test/@alice', self::BOB)]], $this->written, 'a Fediverse account\'s block is not Bluesky\'s');

		$this->blocks()->blocked(self::alice(), (new Person())->setId('https://bsky.app/profile/did:plc:dave'));
		$this->assertSame('did:plc:dave', $this->written[1][1]);
	}

	public function testWithdrawingTakesEveryPublishedBlockAwayAndAnUnblockItsOwn(): void {
		$this->blocks()->setPublished(self::alice(), false);
		$this->assertSame([[BlueskyBlocks::COLLECTION, BlueskyBlocks::localId('https://social.test/@alice', self::BOB)]], $this->removed);

		$this->blocks()->unblocked(self::alice(), (new Person())->setId(self::BOB));
		$this->assertCount(2, $this->removed, 'an unblock withdraws, published or not');
	}
}
