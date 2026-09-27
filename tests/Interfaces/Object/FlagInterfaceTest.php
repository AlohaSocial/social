<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Object;

use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\Object\FlagInterface;
use OCA\Social\Model\ActivityPub\Object\Flag;
use OCA\Social\Model\Report;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../ActivityPubTestCase.php';

#[AllowMockObjectsWithoutExpectations]
class FlagInterfaceTest extends ActivityPubTestCase {
	/** @var ReportService&MockObject */
	private $reportService;
	private FlagInterface $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->reportService = $this->createMock(ReportService::class);
		$this->handler = new FlagInterface($this->reportService, new NullLogger());
	}

	private function incomingFlag(string $origin = self::REMOTE_HOST): Flag {
		$flag = new Flag();
		$flag->import([
			'id' => self::REMOTE_URL . '/reports/1',
			'type' => 'Flag',
			'actor' => self::REMOTE_URL . '/actor',
			'content' => 'spam',
			'object' => [self::LOCAL_URL . '/users/alice', self::LOCAL_URL . '/users/alice/1'],
		]);
		$flag->setOrigin($origin, SignatureService::ORIGIN_HEADER, time());

		return $flag;
	}

	public function testAnIncomingFlagIsStoredAsAReport(): void {
		$flag = $this->incomingFlag();
		$this->reportService->expects($this->once())
			->method('reportFromFlag')->with($this->identicalTo($flag))
			->willReturn(new Report());

		$this->handler->processIncomingRequest($flag);
	}

	public function testAFlagNotSignedByTheReportersServerIsRefused(): void {
		$this->reportService->expects($this->never())->method('reportFromFlag');

		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest($this->incomingFlag('evil.example'));
	}

	public function testAFlagWithoutObjectsIsIgnored(): void {
		$flag = new Flag();
		$flag->import([
			'id' => self::REMOTE_URL . '/reports/2',
			'type' => 'Flag',
			'actor' => self::REMOTE_URL . '/actor',
		]);
		$flag->setOrigin(self::REMOTE_HOST, SignatureService::ORIGIN_HEADER, time());

		$this->reportService->expects($this->never())->method('reportFromFlag');

		$this->handler->processIncomingRequest($flag);
	}
}
