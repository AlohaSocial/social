<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\BlockedBy;

use OCA\Social\Atproto\Reader\BlueskyBlockedBy;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\BlockedByException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Service\BlockedBy\BlockedByService;
use OCA\Social\Service\BlockedBy\BlockedBySource;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\TimelineRevisionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlockedByServiceTest extends TestCase {
	private const ALICE = 'https://social.test/@alice';
	private const BOB = 'https://bsky.app/profile/did:plc:bob';
	private const ROOT = 'https://bsky.app/profile/did:plc:root';
	private const CAROL = 'https://remote.example/users/carol';

	/** @var array<string, array<string, true>> local id → the accounts recorded as blocking it */
	private array $rows = [];
	/** @var array<string, mixed> */
	private array $cached = [];
	/** @var list<list<string>> what the source was asked */
	private array $asked = [];
	/** @var array<string, bool> what the source answers */
	private array $network = [];
	private bool $networkDown = false;
	/** @var TimelineRevisionService&MockObject */
	private TimelineRevisionService $revisions;
	private BlockedByService $service;
	private Person $alice;

	protected function setUp(): void {
		$relations = $this->createMock(ActorRelationRequest::class);
		$relations->method('exists')->willReturnCallback(fn (string $local, string $other, string $type): bool => $type === ActorRelation::TYPE_BLOCKED_BY && isset($this->rows[$local][$other]));
		$relations->method('getBetweenMany')->willReturnCallback(function (string $local, array $others): array {
			$found = [];
			foreach ($others as $other) {
				if (isset($this->rows[$local][$other])) {
					$relation = new ActorRelation();
					$relation->setObjectId($other);
					$relation->setType(ActorRelation::TYPE_BLOCKED_BY);
					$found[$other] = [$relation];
				}
			}

			return $found;
		});
		$relations->method('save')->willReturnCallback(function (string $local, string $other): void {
			$this->rows[$local][$other] = true;
		});
		$relations->method('delete')->willReturnCallback(function (string $local, string $other): void {
			unset($this->rows[$local][$other]);
		});
		$relations->method('getLocalByObject')->willReturnCallback(fn (string $other): array => array_keys(array_filter($this->rows, static fn (array $blockers): bool => isset($blockers[$other]))));

		$cache = $this->createMock(DurableCache::class);
		$cache->method('getShared')->willReturnCallback(fn (string $ns, string $key): mixed => $this->cached[$ns . '/' . $key] ?? null);
		$cache->method('setShared')->willReturnCallback(function (string $ns, string $key, mixed $value): void {
			$this->cached[$ns . '/' . $key] = $value;
		});

		$source = $this->createMock(BlockedBySource::class);
		$source->method('supports')->willReturnCallback(static fn (string $id): bool => str_starts_with($id, 'https://bsky.app/profile/'));
		$source->method('ask')->willReturnCallback(function (string $local, array $ids): array {
			$this->asked[] = $ids;
			if ($this->networkDown) {
				throw new AtprotoException('AppView could not be reached');
			}

			return array_intersect_key($this->network, array_flip($ids));
		});
		$source->method('threadAuthors')->willReturn([self::ROOT]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): ?object => $id === BlueskyBlockedBy::class ? $source : null);

		$this->revisions = $this->createMock(TimelineRevisionService::class);
		$this->service = new BlockedByService($relations, $cache, $this->revisions, new NullLogger(), $container);
		$this->alice = new Person();
		$this->alice->setId(self::ALICE);
	}

	public function testABlueskyBlockIsAskedAboutRecordedAndBelievedForAWhile(): void {
		$this->network = [self::BOB => true];
		$this->revisions->expects($this->once())->method('bumpForActor')->with(self::ALICE);

		$this->assertSame([self::BOB => true], $this->service->blockedBy(self::ALICE, [self::BOB]));
		$this->assertTrue(isset($this->rows[self::ALICE][self::BOB]), 'the same relation a Fediverse block is');

		$this->network = [self::BOB => false];
		$this->assertSame([self::BOB => true], $this->service->blockedBy(self::ALICE, [self::BOB]), 'asked a moment ago');
		$this->assertCount(1, $this->asked);
	}

	public function testAnUnblockTakesTheRelationAway(): void {
		$this->rows[self::ALICE][self::BOB] = true;
		$this->network = [self::BOB => false];
		$this->revisions->expects($this->once())->method('bumpForActor');

		$this->assertSame([self::BOB => false], $this->service->blockedBy(self::ALICE, [self::BOB], true));
		$this->assertArrayNotHasKey(self::BOB, $this->rows[self::ALICE]);
	}

	public function testAFediverseBlockIsWhatTheInboxRecorded(): void {
		$this->rows[self::ALICE][self::CAROL] = true;

		$this->assertSame([self::CAROL => true], $this->service->blockedBy(self::ALICE, [self::CAROL]));
		$this->assertSame([], $this->asked, 'nobody to ask');
	}

	public function testANetworkThatCannotBeAskedLeavesWhatIsRecorded(): void {
		$this->rows[self::ALICE][self::BOB] = true;
		$this->networkDown = true;

		$this->assertSame([self::BOB => true], $this->service->blockedBy(self::ALICE, [self::BOB]));
		$this->assertTrue(isset($this->rows[self::ALICE][self::BOB]));
	}

	public function testAskingSaysOnlyWhatANetworkAnswered(): void {
		$this->rows[self::ALICE][self::CAROL] = true;
		$this->network = [self::BOB => false];

		$this->assertSame([self::BOB => false], $this->service->ask(self::ALICE, [self::BOB, self::CAROL, self::ROOT]));
		$this->assertSame([[self::BOB, self::ROOT]], $this->asked, 'only what a network can answer for');
	}

	public function testTheAccountItselfIsNeverAsked(): void {
		$this->assertSame([], $this->service->blockedBy(self::ALICE, [self::ALICE, '']));
		$this->assertSame([], $this->asked);
	}

	public function testAReplyIsRefusedWhenTheThreadsFirstAuthorHasBlockedTheReplier(): void {
		$parent = new Note();
		$parent->setAttributedTo(self::BOB);
		$this->network = [self::BOB => false, self::ROOT => true];

		try {
			$this->service->assertMayReply($this->alice, $parent);
			$this->fail('the reply went out');
		} catch (BlockedByException $e) {
			$this->assertSame('This account has blocked you', $e->getMessage());
		}
		$this->assertSame([[self::BOB, self::ROOT]], $this->asked, 'one question for both');
	}

	public function testAReplyToAFediverseAuthorWhoHasNotBlockedTheReplierGoesThrough(): void {
		$parent = new Note();
		$parent->setAttributedTo(self::CAROL);

		$this->service->assertMayReply($this->alice, $parent);
		$this->assertSame([], $this->asked, 'a Fediverse thread has no other authors to ask about');
	}

	public function testAFollowOfAnAccountThatHasBlockedTheFollowerIsRefused(): void {
		$this->rows[self::ALICE][self::CAROL] = true;

		$this->expectException(BlockedByException::class);
		$this->service->assertNotBlocked($this->alice, [self::CAROL]);
	}

	public function testWhatAReadSaysIsWrittenOnlyWhereItChangesAnything(): void {
		$this->rows[self::ALICE][self::BOB] = true;
		$this->revisions->expects($this->once())->method('bumpForActor');

		$this->service->record(self::ALICE, [self::BOB => true, self::ROOT => true, self::CAROL => false, self::ALICE => true]);

		$this->assertSame([self::BOB => true, self::ROOT => true], $this->rows[self::ALICE]);
		$this->assertCount(1, $this->cached, 'only the change is believed: an unchanged answer costs no write');
	}

	public function testAWithdrawnBlockIsAskedAboutAgainForEveryLocalAccountItNamed(): void {
		$this->rows[self::ALICE][self::BOB] = true;
		$this->rows['https://social.test/@dave'][self::BOB] = true;
		$this->service->record(self::ALICE, [self::BOB => true], true);
		$this->network = [self::BOB => false];

		$this->assertSame([], $this->service->recheck(self::BOB));
		$this->assertCount(2, $this->asked, 'asked afresh, whatever was believed');
		$this->assertArrayNotHasKey(self::BOB, $this->rows[self::ALICE]);
	}
}
