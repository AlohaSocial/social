<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\AccessBlocksRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\AccessBlock;
use OCA\Social\Service\AccessBlockService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The two blocks that are about an address rather than an account.
 *
 * What is worth asserting here is what each one actually refuses, because
 * Mastodon's versions of both police a sign-up that does not exist on this
 * app — and a rule stored and never read is worse than one refused, since an
 * admin told it was stored believes it is being enforced.
 */
class AccessBlockServiceTest extends TestCase {
	private AccessBlocksRequest|Stub $accessBlocksRequest;
	private AccessBlockService $service;

	/** @var array<string, AccessBlock> "type value" => the block */
	private array $stored = [];
	private int $nextId = 1;

	protected function setUp(): void {
		$this->accessBlocksRequest = $this->createStub(AccessBlocksRequest::class);

		$this->accessBlocksRequest->method('save')->willReturnCallback(
			function (AccessBlock $block): void {
				$key = $block->getType() . ' ' . $block->getValue();
				$this->stored[$key] = AccessBlock::fromRow([
					'id' => isset($this->stored[$key]) ? $this->stored[$key]->getId() : $this->nextId++,
					'type' => $block->getType(),
					'value' => $block->getValue(),
					'severity' => $block->getSeverity(),
					'comment' => $block->getComment(),
					'expires' => $block->getExpires() === 0
						? null : gmdate('Y-m-d H:i:s', $block->getExpires()),
					'creation' => gmdate('Y-m-d H:i:s'),
				]);
			}
		);

		$this->accessBlocksRequest->method('getByType')->willReturnCallback(
			fn (string $type): array => array_values(array_filter(
				$this->stored, static fn (AccessBlock $b): bool => $b->getType() === $type
			))
		);

		$this->accessBlocksRequest->method('getById')->willReturnCallback(
			function (int $id, string $type): AccessBlock {
				foreach ($this->stored as $block) {
					if ($block->getId() === $id && $block->getType() === $type) {
						return $block;
					}
				}

				throw new ItemNotFoundException('Record not found');
			}
		);

		$this->accessBlocksRequest->method('delete')->willReturnCallback(
			function (int $id, string $type): void {
				foreach ($this->stored as $key => $block) {
					if ($block->getId() === $id && $block->getType() === $type) {
						unset($this->stored[$key]);

						return;
					}
				}

				throw new ItemNotFoundException('Record not found');
			}
		);

		$this->service = new AccessBlockService($this->accessBlocksRequest);
	}

	// which addresses a range covers

	#[DataProvider('provideAddressesAndRanges')]
	public function testWhetherAnAddressFallsInsideARange(
		string $ip, string $range, bool $inside,
	): void {
		$this->assertSame($inside, AccessBlockService::inRange($ip, $range), $ip . ' in ' . $range);
	}

	public static function provideAddressesAndRanges(): iterable {
		yield 'the address itself' => ['1.2.3.4', '1.2.3.4/32', true];
		yield 'inside a /24' => ['1.2.3.4', '1.2.3.0/24', true];
		yield 'outside a /24' => ['1.2.4.4', '1.2.3.0/24', false];
		// the boundary is what a mask is for, and the byte it falls inside is
		// the one an implementation that only compared whole bytes gets wrong
		yield 'above a /25 boundary' => ['1.2.3.129', '1.2.3.128/25', true];
		yield 'below a /25 boundary' => ['1.2.3.127', '1.2.3.128/25', false];
		yield 'everything v4' => ['9.9.9.9', '0.0.0.0/0', true];
		yield 'inside a v6 range' => ['2001:db8::5', '2001:db8::/32', true];
		yield 'outside a v6 range' => ['2001:dc8::5', '2001:db8::/32', false];
		// compared on the bytes, which is the only way these are the same
		yield 'the same v6 address written two ways' => ['0:0:0:0:0:0:0:1', '::1/128', true];
		// a v4 address is not inside a v6 range, whatever the prefix says
		yield 'v4 against a v6 range' => ['1.2.3.4', '::/0', false];
		yield 'v6 against a v4 range' => ['::1', '0.0.0.0/0', false];
		yield 'not an address at all' => ['nonsense', '1.2.3.0/24', false];
		yield 'not a range at all' => ['1.2.3.4', 'nonsense', false];
	}

	#[DataProvider('provideRangesAsTyped')]
	public function testARangeIsStoredInOneShape(string $typed, string $stored): void {
		$this->assertSame($stored, AccessBlockService::normaliseRange($typed));
	}

	public static function provideRangesAsTyped(): iterable {
		// a bare address is the range holding only itself, so there is one
		// shape to match against rather than two
		yield 'a bare v4 address' => ['1.2.3.4', '1.2.3.4/32'];
		yield 'a bare v6 address' => ['::1', '::1/128'];
		yield 'a v4 range' => ['1.2.3.0/24', '1.2.3.0/24'];
		yield 'a v6 range' => ['2001:DB8::/32', '2001:db8::/32'];
		yield 'padded' => ['  1.2.3.4  ', '1.2.3.4/32'];
		yield 'a prefix past the end' => ['1.2.3.4/33', ''];
		yield 'a prefix that is not a number' => ['1.2.3.4/x', ''];
		yield 'a hostname' => ['evil.example', ''];
		yield 'nothing' => ['', ''];
	}

	// IP blocks

	public function testABlockedRangeIsRefusedAndEverythingElseIsNot(): void {
		$this->service->blockIp('1.2.3.0/24', AccessBlock::SEVERITY_NO_ACCESS, 'scraping');

		$this->assertTrue($this->service->isBlockedIp('1.2.3.4'));
		$this->assertFalse($this->service->isBlockedIp('1.2.4.4'));
		$this->assertFalse($this->service->isBlockedIp(''));
	}

	public function testAnInstanceWithNoBlocksRefusesNobody(): void {
		$this->assertFalse($this->service->isBlockedIp('1.2.3.4'));
	}

	/**
	 * The block lifts itself where it is read, so an instance whose cron is
	 * broken does not go on refusing an address the admin gave an end date to.
	 */
	public function testABlockWithAnEndDateStopsRefusingWhenItPasses(): void {
		$this->service->blockIp('1.2.3.4', AccessBlock::SEVERITY_NO_ACCESS, '', 2_000);

		$this->assertTrue($this->service->isBlockedIp('1.2.3.4', 1_000));
		$this->assertFalse($this->service->isBlockedIp('1.2.3.4', 3_000));
	}

	public function testABlockWithNoEndDateNeverLifts(): void {
		$this->service->blockIp('1.2.3.4');

		$this->assertTrue($this->service->isBlockedIp('1.2.3.4', PHP_INT_MAX - 1));
	}

	/**
	 * Storing a rule nothing will ever read is worse than saying it cannot be
	 * honoured: an admin told it was stored believes sign-ups from that range
	 * are being turned away.
	 */
	#[DataProvider('provideSeveritiesThisInstanceHasNoSignUpFor')]
	public function testASeverityThatPolicesASignUpIsRefused(string $severity): void {
		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessage('no sign-up');

		try {
			$this->service->blockIp('1.2.3.4', $severity);
		} finally {
			$this->assertSame([], $this->stored, 'the rule was stored anyway');
		}
	}

	public static function provideSeveritiesThisInstanceHasNoSignUpFor(): iterable {
		yield 'sign-up block' => [AccessBlock::SEVERITY_SIGN_UP_BLOCK];
		yield 'sign-up requires approval' => [AccessBlock::SEVERITY_SIGN_UP_REQUIRES_APPROVAL];
		yield 'something that is not a severity at all' => ['banish'];
	}

	public function testSomethingThatIsNotAnAddressIsRefused(): void {
		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessage('not an IP address or range');

		$this->service->blockIp('evil.example');
	}

	/** Blocking the same range twice changes it rather than adding a second. */
	public function testBlockingTheSameRangeAgainReplacesIt(): void {
		$this->service->blockIp('1.2.3.0/24', AccessBlock::SEVERITY_NO_ACCESS, 'first');
		$this->service->blockIp('1.2.3.0/24', AccessBlock::SEVERITY_NO_ACCESS, 'second');

		$blocks = $this->service->ipBlocks();
		$this->assertCount(1, $blocks);
		$this->assertSame('second', $blocks[0]->getComment());
	}

	public function testLiftingABlockLetsTheAddressBackIn(): void {
		$block = $this->service->blockIp('1.2.3.4');
		$id = $this->service->ipBlocks()[0]->getId();

		$this->service->unblockIp($id);

		$this->assertFalse($this->service->isBlockedIp('1.2.3.4'));
		$this->assertSame('1.2.3.4/32', $block->getValue());
	}

	public function testLiftingOneThatIsNotThereIsARecordNotFound(): void {
		$this->expectException(ItemNotFoundException::class);

		$this->service->unblockIp(404);
	}

	/** A change through the service is seen by the next read in the request. */
	public function testTheListIsReReadAfterItChanges(): void {
		$this->assertFalse($this->service->isBlockedIp('1.2.3.4'));

		$this->service->blockIp('1.2.3.4');

		$this->assertTrue($this->service->isBlockedIp('1.2.3.4'), 'the cached list outlived the change');
	}

	// email-domain blocks

	public function testABlockedDomainCoversTheAddressesAtIt(): void {
		$this->service->blockEmailDomain('throwaway.example');

		$this->assertTrue($this->service->isBlockedEmail('bob@throwaway.example'));
		$this->assertFalse($this->service->isBlockedEmail('bob@example.test'));
	}

	/**
	 * Blocking `mail.example` while `a.mail.example` walks straight back in is
	 * not a block, and whoever runs a throwaway-address service runs the
	 * subdomains too.
	 */
	public function testABlockedDomainCoversWhatIsUnderIt(): void {
		$this->service->blockEmailDomain('throwaway.example');

		$this->assertTrue($this->service->isBlockedEmail('bob@mail.throwaway.example'));
		$this->assertFalse(
			$this->service->isBlockedEmail('bob@notthrowaway.example'), 'not a suffix match'
		);
	}

	public function testADomainIsReadTheWayItIsWritten(): void {
		$this->service->blockEmailDomain('  Throwaway.Example.  ');

		$this->assertTrue($this->service->isBlockedEmail('bob@THROWAWAY.example'));
	}

	#[DataProvider('provideThingsThatAreNotAddresses')]
	public function testSomethingWithNoDomainInItIsNotBlocked(string $email): void {
		$this->service->blockEmailDomain('throwaway.example');

		$this->assertFalse($this->service->isBlockedEmail($email));
	}

	public static function provideThingsThatAreNotAddresses(): iterable {
		yield 'no at sign' => ['bob'];
		yield 'nothing after the at sign' => ['bob@'];
		yield 'nothing at all' => [''];
	}

	public function testSomethingThatIsNotADomainIsRefused(): void {
		$this->expectException(InvalidResourceException::class);

		$this->service->blockEmailDomain('not a domain');
	}

	public function testLiftingADomainBlockLetsTheAddressesThrough(): void {
		$this->service->blockEmailDomain('throwaway.example');
		$id = $this->service->emailDomainBlocks()[0]->getId();

		$this->service->unblockEmailDomain($id);

		$this->assertFalse($this->service->isBlockedEmail('bob@throwaway.example'));
	}

	/** Two lists in one table, and neither is the other. */
	public function testTheTwoListsAreKeptApart(): void {
		$this->service->blockIp('1.2.3.4');
		$this->service->blockEmailDomain('throwaway.example');

		$this->assertCount(1, $this->service->ipBlocks());
		$this->assertCount(1, $this->service->emailDomainBlocks());
		$this->assertSame('1.2.3.4/32', $this->service->ipBlocks()[0]->getValue());
		$this->assertSame('throwaway.example', $this->service->emailDomainBlocks()[0]->getValue());
	}

	// what each one looks like to a client

	public function testAnIpBlockIsMastodonsEntity(): void {
		$this->service->blockIp('1.2.3.0/24', AccessBlock::SEVERITY_NO_ACCESS, 'scraping', 2_000);

		$entity = $this->service->ipBlocks()[0]->jsonSerialize();

		$this->assertSame('1.2.3.0/24', $entity['ip']);
		$this->assertSame('no_access', $entity['severity']);
		$this->assertSame('scraping', $entity['comment']);
		$this->assertSame('1970-01-01T00:33:20.000Z', $entity['expires_at']);
	}

	public function testABlockWithNoEndDateSaysNullRatherThan1970(): void {
		$this->service->blockIp('1.2.3.4');

		$this->assertNull($this->service->ipBlocks()[0]->jsonSerialize()['expires_at']);
	}

	/**
	 * `history` is Mastodon's count of sign-up attempts it turned away. There
	 * is no sign-up here, so it is empty — and sent rather than omitted,
	 * because a client that declares it non-optional cannot decode the entity.
	 */
	public function testAnEmailDomainBlockIsMastodonsEntity(): void {
		$this->service->blockEmailDomain('throwaway.example');

		$entity = $this->service->emailDomainBlocks()[0]->jsonSerialize();

		$this->assertSame('throwaway.example', $entity['domain']);
		$this->assertSame([], $entity['history']);
		$this->assertArrayNotHasKey('severity', $entity);
	}
}
