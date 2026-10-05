<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\MoveInService;
use OCA\Social\Service\PostImportService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Moving here from nothing but the old handle: what the old server lets us
 * read, and what is done with it.
 */
#[AllowMockObjectsWithoutExpectations]
class MoveInServiceTest extends TestCase {
	private const OLD = 'https://old.example/users/alice';
	private const NEW = 'https://cloud.example/@alice';

	private CacheActorService|MockObject $cacheActorService;
	private CurlService|MockObject $curlService;
	private FollowService|MockObject $followService;
	private PostImportService|MockObject $postImportService;
	private MigrationService|MockObject $migrationService;
	private MoveInService $service;

	/** @var array<string, array<string, mixed>> what each URL answers */
	private array $documents = [];

	protected function setUp(): void {
		parent::setUp();
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->curlService = $this->createMock(CurlService::class);
		$this->followService = $this->createMock(FollowService::class);
		$this->postImportService = $this->createMock(PostImportService::class);
		$this->migrationService = $this->createMock(MigrationService::class);

		$this->curlService->method('retrieveObject')->willReturnCallback(function (string $url): array {
			if (!isset($this->documents[$url])) {
				throw new \RuntimeException('404 ' . $url);
			}

			return $this->documents[$url];
		});

		$this->service = new MoveInService(
			$this->cacheActorService,
			$this->curlService,
			$this->followService,
			$this->postImportService,
			$this->migrationService,
			new NullLogger(),
		);
	}

	private function old(): Person {
		$actor = new Person();
		$actor->setId(self::OLD)->setAccount('alice@old.example')->setPreferredUsername('alice')->setName('Alice')
			->setUrl('https://old.example/@alice')->setFollowing(self::OLD . '/following')->setOutbox(self::OLD . '/outbox');

		return $actor;
	}

	private function new(): Person {
		$actor = new Person();
		$actor->setId(self::NEW)->setAccount('alice@cloud.example')->setLocal(true);

		return $actor;
	}

	private function remote(string $id): Person {
		$actor = new Person();
		$actor->setId($id)->setAccount(basename($id) . '@remote.example');

		return $actor;
	}

	// inspect()

	public function testInspectSaysWhatTheOldServerPublishesAndWhetherItCanBeRead(): void {
		$this->migrationService->method('resolveActor')->with('@alice@old.example')->willReturn($this->old());
		$this->documents[self::OLD . '/following'] = ['type' => 'OrderedCollection', 'totalItems' => 120, 'first' => self::OLD . '/following?page=1'];
		// a hidden collection: a count and no page
		$this->documents[self::OLD . '/outbox'] = ['type' => 'OrderedCollection', 'totalItems' => 900];

		$account = $this->service->inspect('@alice@old.example');

		$this->assertSame('alice@old.example', $account['acct']);
		$this->assertSame('Alice', $account['name']);
		$this->assertSame(['total' => 120, 'readable' => true], $account['following']);
		$this->assertSame(['total' => 900, 'readable' => false], $account['posts']);
	}

	public function testInspectOfAServerThatDoesNotAnswerIsNotReadable(): void {
		$this->migrationService->method('resolveActor')->willReturn($this->old());

		$account = $this->service->inspect('alice@old.example');

		$this->assertSame(['total' => 0, 'readable' => false], $account['following']);
	}

	public function testInspectRefusesAHandleNobodyAnswersTo(): void {
		$this->migrationService->method('resolveActor')->willThrowException(new InvalidResourceException('no account answers to x'));

		$this->expectException(InvalidResourceException::class);
		$this->service->inspect('x@gone.example');
	}

	// prepare()

	/** The alias is the half that federates nothing and that the old server will ask for, so it is set at once. */
	public function testPrepareSetsTheAliasAndHandsBackWhatTheRunNeeds(): void {
		$this->migrationService->method('resolveActor')->with('alice@old.example')->willReturn($this->old());
		$this->migrationService->expects($this->once())->method('addAlias')->with('alice', self::OLD);

		$options = $this->service->prepare('alice', 'alice@old.example', true, false, true);

		$this->assertSame(self::OLD, $options['source']);
		$this->assertSame('alice@old.example', $options['acct']);
		$this->assertTrue($options['follows']);
		$this->assertFalse($options['posts']);
	}

	// run()

	public function testRunFollowsEveryPageOfTheOldFollowingListAndCountsEachOutcome(): void {
		$this->cacheActorService->method('getFromId')->willReturnCallback(
			fn (string $id): Person => ($id === self::OLD) ? $this->old() : $this->remote($id)
		);
		$this->documents[self::OLD . '/following'] = ['type' => 'OrderedCollection', 'totalItems' => 3, 'first' => self::OLD . '/following?page=1'];
		$this->documents[self::OLD . '/following?page=1'] = [
			'type' => 'OrderedCollectionPage', 'orderedItems' => ['https://remote.example/users/bob', 'https://remote.example/users/carol'],
			'next' => self::OLD . '/following?page=2',
		];
		$this->documents[self::OLD . '/following?page=2'] = [
			'type' => 'OrderedCollectionPage', 'orderedItems' => [['id' => 'https://remote.example/users/dave'], self::NEW],
		];
		$this->followService->method('followActor')->willReturnCallback(function (Person $actor, Person $target): bool {
			return match ($target->getId()) {
				'https://remote.example/users/bob' => true,
				'https://remote.example/users/carol' => false,
				default => throw new \RuntimeException('unreachable'),
			};
		});
		$this->postImportService->expects($this->never())->method('importItems');
		$steps = [];

		$report = $this->service->run($this->new(), ['source' => self::OLD, 'follows' => true, 'posts' => false], function (int $done, int $total) use (&$steps): void {
			$steps[] = [$done, $total];
		});

		$this->assertSame(1, $report['followed']);
		$this->assertSame(2, $report['skipped'], 'already followed, and the account itself');
		$this->assertSame(1, $report['failed']);
		$this->assertSame(['https://remote.example/users/dave' => 'unreachable'], $report['failures']);
		$this->assertTrue($report['following_readable']);
		$this->assertSame([0, 4], $steps[0]);
		$this->assertSame([4, 4], end($steps));
	}

	public function testRunHandsTheOutboxPagesToThePostImporterAndReportsItsTally(): void {
		$this->cacheActorService->method('getFromId')->willReturn($this->old());
		$create = ['type' => 'Create', 'object' => ['type' => 'Note', 'id' => 'https://old.example/users/alice/statuses/1']];
		$announce = ['type' => 'Announce', 'object' => 'https://elsewhere.example/statuses/9'];
		$this->documents[self::OLD . '/outbox'] = ['type' => 'OrderedCollection', 'totalItems' => 2, 'first' => self::OLD . '/outbox?page=true'];
		$this->documents[self::OLD . '/outbox?page=true'] = ['type' => 'OrderedCollectionPage', 'orderedItems' => [$create, $announce]];
		$this->postImportService->expects($this->once())->method('importItems')
			->with($this->isInstanceOf(Person::class), [$create, $announce], false, $this->isCallable())
			->willReturn(['imported' => 1, 'skipped' => 1, 'already' => 0, 'media' => 0, 'failed' => 0, 'total' => 2, 'capped' => false]);

		$report = $this->service->run($this->new(), ['source' => self::OLD, 'follows' => false, 'posts' => true, 'fetch_media' => false], static function (): void {
		});

		$this->assertSame(1, $report['imported']);
		$this->assertSame(1, $report['posts_skipped']);
		$this->assertTrue($report['posts_readable']);
		$this->assertFalse($report['following_readable'], 'not asked for');
	}

	/** A server that hides a collection answers a count and no page: the report says so instead of a run that came back empty by accident. */
	public function testAHiddenCollectionIsReportedAsUnreadable(): void {
		$this->cacheActorService->method('getFromId')->willReturn($this->old());
		$this->documents[self::OLD . '/following'] = ['type' => 'OrderedCollection', 'totalItems' => 120];
		$this->followService->expects($this->never())->method('followActor');

		$report = $this->service->run($this->new(), ['source' => self::OLD, 'follows' => true, 'posts' => false], static function (): void {
		});

		$this->assertFalse($report['following_readable']);
		$this->assertSame(0, $report['followed']);
	}

	/** A collection read halfway is kept halfway: half of a following list beats none of it. */
	public function testAPageThatStopsAnsweringKeepsWhatWasReadBeforeIt(): void {
		$this->cacheActorService->method('getFromId')->willReturnCallback(
			fn (string $id): Person => ($id === self::OLD) ? $this->old() : $this->remote($id)
		);
		$this->documents[self::OLD . '/following'] = ['type' => 'OrderedCollection', 'first' => self::OLD . '/following?page=1'];
		$this->documents[self::OLD . '/following?page=1'] = [
			'type' => 'OrderedCollectionPage', 'orderedItems' => ['https://remote.example/users/bob'], 'next' => self::OLD . '/following?page=2',
		];
		$this->followService->method('followActor')->willReturn(true);

		$report = $this->service->run($this->new(), ['source' => self::OLD, 'follows' => true], static function (): void {
		});

		$this->assertSame(1, $report['followed']);
	}

	public function testFollowingTheAccountItselfIsSkippedNotFailed(): void {
		$this->cacheActorService->method('getFromId')->willReturn($this->old());
		$this->documents[self::OLD . '/following'] = ['type' => 'OrderedCollection', 'orderedItems' => [self::OLD]];
		$this->followService->method('followActor')->willThrowException(new FollowSameAccountException());

		$report = $this->service->run($this->new(), ['source' => self::OLD, 'follows' => true], static function (): void {
		});

		$this->assertSame(['followed' => 0, 'skipped' => 1, 'failed' => 0], array_intersect_key($report, ['followed' => 1, 'skipped' => 1, 'failed' => 1]));
	}
}
