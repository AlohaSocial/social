<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ReportsRequest;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Model\Report;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The report table against the real database: the full round trip of a report
 * (fields, JSON status list, local flag), the open/resolved lifecycle the admin
 * panel drives, and the open-report counter.
 */
class ReportsLifecycleTest extends TestCase {
	private const REPORTER = 'https://cloud.example.org/rptest/@alice';
	private const TARGET = 'https://remote.example/rptest/@spammer';

	private ReportsRequest $request;
	/** @var int[] */
	private array $created = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request = Server::get(ReportsRequest::class);
	}

	protected function tearDown(): void {
		foreach ($this->created as $id) {
			$this->request->delete($id);
		}
		$this->created = [];
		parent::tearDown();
	}

	private function report(bool $local = true, bool $resolved = false): Report {
		$report = new Report();
		$report->setActorId(self::REPORTER)
			->setAccountId(self::TARGET)
			->setStatusIds(['https://remote.example/rptest/@spammer/1', '42'])
			->setComment('spam — please have a look')
			->setCategory(Report::CATEGORY_SPAM)
			->setLocal($local)
			->setResolved($resolved);
		$this->created[] = $this->request->save($report);

		return $report;
	}

	public function testAReportRoundTripsThroughTheDatabase(): void {
		$report = $this->report(false);
		$this->assertGreaterThan(0, $report->getId(), 'save() hands back the row id');

		$stored = $this->request->getById($report->getId());

		$this->assertSame(self::REPORTER, $stored->getActorId());
		$this->assertSame(self::TARGET, $stored->getAccountId());
		$this->assertSame(['https://remote.example/rptest/@spammer/1', '42'], $stored->getStatusIds());
		$this->assertSame('spam — please have a look', $stored->getComment());
		$this->assertSame(Report::CATEGORY_SPAM, $stored->getCategory());
		$this->assertFalse($stored->isLocal());
		$this->assertFalse($stored->isResolved());
		$this->assertGreaterThan(0, $stored->getCreation());
	}

	public function testTheRulesAViolationNamedRoundTrip(): void {
		$report = new Report();
		$report->setActorId(self::REPORTER)
			->setAccountId(self::TARGET)
			->setCategory(Report::CATEGORY_VIOLATION)
			->setRules([['id' => '2', 'text' => 'No spam — ever'], ['id' => '4', 'text' => 'Be kind']]);
		$this->created[] = $this->request->save($report);

		$this->assertSame(
			[['id' => '2', 'text' => 'No spam — ever'], ['id' => '4', 'text' => 'Be kind']],
			$this->request->getById($report->getId())->getRules()
		);
		$this->assertSame([], $this->request->getById($this->report()->getId())->getRules(), 'none stored is none read');
	}

	public function testResolveLifecycleAndOpenCount(): void {
		$before = $this->request->countOpen();
		$open = $this->report();
		$this->report(true, true);

		$this->assertSame($before + 1, $this->request->countOpen(), 'only unresolved reports count');

		$this->request->setResolved($open->getId(), true);
		$this->assertSame($before, $this->request->countOpen());
		$this->assertTrue($this->request->getById($open->getId())->isResolved());

		$this->request->setResolved($open->getId(), false);
		$this->assertFalse($this->request->getById($open->getId())->isResolved());
	}

	public function testGetAllFiltersResolvedUnlessAsked(): void {
		$open = $this->report();
		$resolved = $this->report(true, true);

		$openIds = array_map(fn (Report $r): int => $r->getId(), $this->request->getAll());
		$this->assertContains($open->getId(), $openIds);
		$this->assertNotContains($resolved->getId(), $openIds);

		$allIds = array_map(fn (Report $r): int => $r->getId(), $this->request->getAll(true));
		$this->assertContains($resolved->getId(), $allIds);
	}

	public function testAnUnknownReportThrows(): void {
		$this->expectException(ReportNotFoundException::class);

		$this->request->getById(0);
	}
}
