<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ImportsRequest;
use OCA\Social\Model\ImportJob;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The rows that say what an account asked to import and where it got to.
 */
class ImportsRequestTest extends TestCase {
	private const ALICE = 'itest-import-alice';
	private const BOB = 'itest-import-bob';

	private ImportsRequest $imports;

	protected function setUp(): void {
		parent::setUp();
		$this->imports = Server::get(ImportsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->imports->deleteByUser(self::ALICE);
		$this->imports->deleteByUser(self::BOB);
	}

	private function job(string $userId, string $kind, string $status = ImportJob::STATUS_QUEUED): ImportJob {
		$job = new ImportJob();
		$job->setUserId($userId)->setKind($kind)->setStatus($status)
			->setOptions(['fetch_media' => false])->setFile('abc.csv');
		$this->imports->create($job);

		return $job;
	}

	public function testARowComesBackAsItWasWritten(): void {
		$job = $this->job(self::ALICE, ImportJob::KIND_FOLLOWS);
		$this->assertGreaterThan(0, $job->getId());

		$read = $this->imports->getById($job->getId());

		$this->assertNotNull($read);
		$this->assertSame(self::ALICE, $read->getUserId());
		$this->assertSame(ImportJob::KIND_FOLLOWS, $read->getKind());
		$this->assertSame(ImportJob::STATUS_QUEUED, $read->getStatus());
		$this->assertSame(['fetch_media' => false], $read->getOptions());
		$this->assertSame('abc.csv', $read->getFile());
		$this->assertGreaterThan(0, $read->getCreation());
	}

	public function testProgressAndTheOutcomeAreUpdatedInPlace(): void {
		$job = $this->job(self::ALICE, ImportJob::KIND_POSTS);
		$job->setStatus(ImportJob::STATUS_DONE)->setTotal(40)->setDone(38)->setSkipped(1)->setFailed(1)
			->setReport(['failed' => ['x@y' => 'gone']])->setFile('');
		$this->imports->update($job);

		$read = $this->imports->getById($job->getId());

		$this->assertSame(ImportJob::STATUS_DONE, $read->getStatus());
		$this->assertSame([40, 38, 1, 1], [$read->getTotal(), $read->getDone(), $read->getSkipped(), $read->getFailed()]);
		$this->assertSame(['failed' => ['x@y' => 'gone']], $read->getReport());
		$this->assertSame('', $read->getFile());
	}

	public function testAnAccountSeesItsOwnImportsNewestFirst(): void {
		$first = $this->job(self::ALICE, ImportJob::KIND_FOLLOWS);
		$second = $this->job(self::ALICE, ImportJob::KIND_BLOCKS);
		$this->job(self::BOB, ImportJob::KIND_FOLLOWS);

		$ids = array_map(static fn (ImportJob $job): int => $job->getId(), $this->imports->getByUser(self::ALICE));

		$this->assertSame([$second->getId(), $first->getId()], $ids);
	}

	public function testOnlyAQueuedOrRunningImportCountsAsActive(): void {
		$this->job(self::ALICE, ImportJob::KIND_FOLLOWS, ImportJob::STATUS_DONE);
		$this->assertFalse($this->imports->hasActive(self::ALICE, ImportJob::KIND_FOLLOWS));

		$this->job(self::ALICE, ImportJob::KIND_FOLLOWS, ImportJob::STATUS_RUNNING);
		$this->assertTrue($this->imports->hasActive(self::ALICE, ImportJob::KIND_FOLLOWS));
		$this->assertFalse($this->imports->hasActive(self::ALICE, ImportJob::KIND_POSTS), 'another kind is not blocked');
		$this->assertFalse($this->imports->hasActive(self::BOB, ImportJob::KIND_FOLLOWS), 'another account is not blocked');
	}

	public function testDeletingOneLeavesTheRest(): void {
		$one = $this->job(self::ALICE, ImportJob::KIND_FOLLOWS);
		$two = $this->job(self::ALICE, ImportJob::KIND_MUTES);

		$this->assertTrue($this->imports->delete($one->getId()));
		$this->assertFalse($this->imports->delete($one->getId()));
		$this->assertNull($this->imports->getById($one->getId()));
		$this->assertNotNull($this->imports->getById($two->getId()));
	}
}
