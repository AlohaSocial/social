<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\IpBlocksList;
use OCA\Social\Command\IpBlocksRemove;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\AccessBlock;
use OCA\Social\Service\AccessBlockService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandCompletionTester;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ social:ip-blocks:list|remove`: the way back in when a block covers
 * the address every request to the app arrives from.
 */
#[AllowMockObjectsWithoutExpectations]
class IpBlocksTest extends TestCase {
	private AccessBlockService&MockObject $service;

	protected function setUp(): void {
		$this->service = $this->createMock(AccessBlockService::class);
		$this->service->method('ipBlocks')->willReturn([
			new AccessBlock(AccessBlock::TYPE_IP, '0.0.0.0/0', AccessBlock::SEVERITY_NO_ACCESS, 'oops', 0, 1, 12),
			new AccessBlock(AccessBlock::TYPE_IP, '2001:db8::/48', AccessBlock::SEVERITY_NO_ACCESS, '', 1767225600, 1, 13),
		]);
	}

	public function testListsEveryBlockWithItsId(): void {
		$tester = new CommandTester(new IpBlocksList($this->service));

		$this->assertSame(0, $tester->execute(['--output' => 'json']));
		$this->assertSame([
			['id' => 12, 'range' => '0.0.0.0/0', 'severity' => 'no_access', 'comment' => 'oops', 'expires' => 'never'],
			['id' => 13, 'range' => '2001:db8::/48', 'severity' => 'no_access', 'comment' => '', 'expires' => '2026-01-01 00:00 UTC'],
		], json_decode($tester->getDisplay(), true));
	}

	public function testSaysSoWhenNothingIsBlocked(): void {
		$service = $this->createStub(AccessBlockService::class);
		$service->method('ipBlocks')->willReturn([]);
		$tester = new CommandTester(new IpBlocksList($service));

		$tester->execute([]);
		$this->assertSame('No IP blocks.', trim($tester->getDisplay()));

		$tester->execute(['--output' => 'json']);
		$this->assertSame('[]', trim($tester->getDisplay()), 'JSON output stays JSON');
	}

	public function testRemovesABlockByItsId(): void {
		$this->service->expects($this->once())->method('unblockIp')->with(12);
		$tester = new CommandTester(new IpBlocksRemove($this->service));

		$this->assertSame(0, $tester->execute(['block' => '12']));
		$this->assertStringContainsString('0.0.0.0/0', $tester->getDisplay());
	}

	/** The range as listed, or as typed: `2001:DB8::/48` is the same block. */
	public function testRemovesABlockByItsRange(): void {
		$this->service->expects($this->once())->method('unblockIp')->with(13);

		$this->assertSame(0, (new CommandTester(new IpBlocksRemove($this->service)))->execute(['block' => '2001:DB8::/48']));
	}

	public function testAnUnknownBlockIsAnError(): void {
		$this->service->expects($this->never())->method('unblockIp');
		$tester = new CommandTester(new IpBlocksRemove($this->service));

		$this->assertSame(2, $tester->execute(['block' => '198.51.100.0/24']));
		$this->assertSame(2, $tester->execute(['block' => '99']));
	}

	public function testABlockGoneMeanwhileIsAnError(): void {
		$this->service->method('unblockIp')->willThrowException(new ItemNotFoundException());

		$this->assertSame(2, (new CommandTester(new IpBlocksRemove($this->service)))->execute(['block' => '12']));
	}

	/** The shell completes the argument from the ranges blocked now. */
	public function testTheBlockArgumentIsCompletedFromTheBlockedRanges(): void {
		$tester = new CommandCompletionTester(new IpBlocksRemove($this->service));

		$this->assertSame(['0.0.0.0/0', '2001:db8::/48'], $tester->complete(['']));
	}
}
