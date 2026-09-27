<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use InvalidArgumentException;
use OCA\Social\Service\BlocklistImportService;
use OCA\Social\Service\FediverseService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class BlocklistImportServiceTest extends TestCase {
	private FediverseService|MockObject $fediverseService;
	private BlocklistImportService $service;

	protected function setUp(): void {
		$this->fediverseService = $this->createMock(FediverseService::class);
		$this->fediverseService->method('getAccessType')->willReturn('all_but');
		$this->service = new BlocklistImportService($this->fediverseService);
	}

	/**
	 * What a list says about a server is the whole of what it says.
	 *
	 * A block deletes everything this instance holds of that server; a silence
	 * keeps it out of the public timelines and leaves the rest alone. Reading
	 * the column and ignoring it erases what the source meant to limit —
	 * thirty of mastodon.social's two hundred and seventy-six entries.
	 */
	public function testTheSeverityColumnDecidesWhatHappens(): void {
		$read = $this->service->parse(
			"#domain,#severity,#public_comment\nblocked.example,suspend,spam\nlimited.example,silence,noise\n",
			BlocklistImportService::FORMAT_CSV
		);

		$this->assertSame([
			'blocked.example' => BlocklistImportService::SEVERITY_SUSPEND,
			'limited.example' => BlocklistImportService::SEVERITY_SILENCE,
		], $read['entries']);
	}

	/** A bare list of domains is a list of blocks, as it has always been. */
	public function testAListWithNoSeverityColumnIsAListOfBlocks(): void {
		$read = $this->service->parse("first.example\nsecond.example\n", BlocklistImportService::FORMAT_CSV);

		$this->assertSame([
			'first.example' => BlocklistImportService::SEVERITY_SUSPEND,
			'second.example' => BlocklistImportService::SEVERITY_SUSPEND,
		], $read['entries']);
	}

	/** A row that asks for nothing is counted rather than acted on. */
	public function testARowThatSaysToDoNothingDoesNothing(): void {
		$read = $this->service->parse(
			"#domain,#severity\nnothing.example,noop\nblocked.example,suspend\n",
			BlocklistImportService::FORMAT_CSV
		);

		$this->assertSame(['blocked.example' => BlocklistImportService::SEVERITY_SUSPEND], $read['entries']);
		$this->assertSame(1, $read['skipped']);
	}

	/** Mastodon's own published list, as its API answers with it. */
	public function testAMastodonListIsReadFromItsJson(): void {
		$read = $this->service->parse(
			'[{"domain":"blocked.example","severity":"suspend","comment":"spam"},'
			. '{"domain":"limited.example","severity":"silence"},'
			. '{"digest":"abc","severity":"suspend"}]',
			BlocklistImportService::FORMAT_MASTODON
		);

		// the third entry publishes a hash instead of a name; there is nothing
		// to import from that
		$this->assertSame([
			'blocked.example' => BlocklistImportService::SEVERITY_SUSPEND,
			'limited.example' => BlocklistImportService::SEVERITY_SILENCE,
		], $read['entries']);
	}

	public function testSomethingThatIsNotAListIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->parse('<html>not found</html>', BlocklistImportService::FORMAT_MASTODON);
	}

	/**
	 * An entry covers itself and everything under it, so a single-label row in
	 * a reviewed list would refuse a whole top-level domain and queue a purge
	 * for every server under it this instance has ever met.
	 *
	 * @param string $domain the row
	 */
	#[DataProvider('rowsThatNameNoInstance')]
	public function testARowThatNamesNoInstanceIsRejected(string $domain): void {
		$read = $this->service->parse("#domain\ngood.example\n" . $domain . "\n", BlocklistImportService::FORMAT_CSV);

		$this->assertSame(['good.example' => BlocklistImportService::SEVERITY_SUSPEND], $read['entries']);
		$this->assertSame([$domain], $read['rejected']);
	}

	public static function rowsThatNameNoInstance(): array {
		return [
			'a top-level domain' => ['com'],
			'no dot at all' => ['localhost'],
			'an intranet name' => ['intranet'],
			'a url' => ['https://blocked.example'],
		];
	}

	/** Blocking the server the list is being imported into is not a policy. */
	public function testThisInstanceIsRejected(): void {
		$this->fediverseService->method('isLocal')
			->willReturnCallback(fn (string $domain): bool => $domain === 'here.example');

		$read = $this->service->parse("#domain\nhere.example\naway.example\n", BlocklistImportService::FORMAT_CSV);

		$this->assertSame(['away.example' => BlocklistImportService::SEVERITY_SUSPEND], $read['entries']);
		$this->assertSame(['here.example'], $read['rejected']);
	}

	/**
	 * A published list is ordered, and a later duplicate is a second entry
	 * rather than a correction.
	 */
	public function testTheFirstDecisionAboutAServerStands(): void {
		$read = $this->service->parse(
			"#domain,#severity\nboth.example,silence\nBOTH.EXAMPLE.,suspend\n",
			BlocklistImportService::FORMAT_CSV
		);

		$this->assertSame(['both.example' => BlocklistImportService::SEVERITY_SILENCE], $read['entries']);
	}

	public function testAListLargerThanTheCeilingIsNotRead(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->parse(str_repeat('a', BlocklistImportService::MAX_BYTES + 1), BlocklistImportService::FORMAT_CSV);
	}

	/** Each half of a list goes where it belongs. */
	public function testApplyingBlocksAndSilencesSeparately(): void {
		$this->fediverseService->expects($this->once())->method('addAddresses')
			->with(['blocked.example'])->willReturn(1);
		$this->fediverseService->expects($this->once())->method('silenceAddresses')
			->with(['limited.example']);

		$applied = $this->service->apply([
			'blocked.example' => BlocklistImportService::SEVERITY_SUSPEND,
			'limited.example' => BlocklistImportService::SEVERITY_SILENCE,
		]);

		$this->assertSame(1, $applied['blocked']);
		$this->assertSame(1, $applied['silenced']);
	}

	/**
	 * The two lists are read once for the whole apply and written once, not
	 * asked about and rewritten per domain.
	 */
	public function testApplyingALargeListReadsAndWritesEachListOnce(): void {
		$entries = [];
		for ($i = 0; $i < 2000; $i++) {
			$entries['s' . $i . '.example'] = BlocklistImportService::SEVERITY_SILENCE;
			$entries['b' . $i . '.example'] = BlocklistImportService::SEVERITY_SUSPEND;
		}
		// covered by an entry earlier in the same list
		$entries['www.s1.example'] = BlocklistImportService::SEVERITY_SILENCE;

		$this->fediverseService->expects($this->once())->method('silencedHosts')->willReturn([]);
		$this->fediverseService->expects($this->once())->method('exactlyListedHosts')->willReturn([]);
		$this->fediverseService->expects($this->never())->method('isSilenced');
		$this->fediverseService->expects($this->never())->method('isExactlyListed');
		$this->fediverseService->expects($this->never())->method('silenceAddress');
		$this->fediverseService->expects($this->once())->method('silenceAddresses')
			->with($this->countOf(2000))->willReturn(2000);
		$this->fediverseService->expects($this->once())->method('addAddresses')
			->with($this->countOf(2000))->willReturn(2000);

		$applied = $this->service->apply($entries);

		$this->assertSame(2000, $applied['silenced']);
		$this->assertSame(1, $applied['alreadySilenced']);
		$this->assertSame(2000, $applied['blocked']);
	}

	/** A preview changes nothing, which is what makes it worth showing. */
	public function testADryRunWritesNothing(): void {
		$this->fediverseService->expects($this->never())->method('addAddresses');
		$this->fediverseService->expects($this->never())->method('silenceAddresses');

		$would = $this->service->apply([
			'blocked.example' => BlocklistImportService::SEVERITY_SUSPEND,
			'limited.example' => BlocklistImportService::SEVERITY_SILENCE,
		], true);

		$this->assertSame(1, $would['blocked']);
		$this->assertSame(1, $would['silenced']);
	}

	/** What is already decided is reported rather than decided again. */
	public function testWhatIsAlreadyListedIsCountedApart(): void {
		$this->fediverseService->method('exactlyListedHosts')->willReturn(['blocked.example' => true]);
		$this->fediverseService->method('silencedHosts')->willReturn(['example' => true]);
		$this->fediverseService->expects($this->never())->method('addAddresses');
		$this->fediverseService->expects($this->never())->method('silenceAddresses');

		$applied = $this->service->apply([
			'blocked.example' => BlocklistImportService::SEVERITY_SUSPEND,
			'limited.example' => BlocklistImportService::SEVERITY_SILENCE,
		]);

		$this->assertSame(0, $applied['blocked']);
		$this->assertSame(1, $applied['alreadyBlocked']);
		$this->assertSame(1, $applied['alreadySilenced']);
	}

	/**
	 * An allow list is the list of servers this one talks to. Importing
	 * somebody's block list into it would be the exact opposite of what it
	 * says.
	 */
	public function testABlockListCannotBeImportedIntoAnAllowList(): void {
		$fediverse = $this->createMock(FediverseService::class);
		$fediverse->method('getAccessType')->willReturn('none_but');
		$service = new BlocklistImportService($fediverse);
		$fediverse->expects($this->never())->method('addAddresses');

		$this->expectException(InvalidArgumentException::class);

		$service->apply(['blocked.example' => BlocklistImportService::SEVERITY_SUSPEND]);
	}
}
