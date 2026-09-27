<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\DomainBlocksRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DomainBlockService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Blocking a whole instance, for one account and nobody else.
 *
 * Two things decide whether this works at all: the form a domain is stored in,
 * because the timeline join compares it as it was stored, and whose rows a read
 * can reach — a block list that leaked between accounts would hide one user's
 * timeline from another.
 */
class DomainBlockServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/users/alice';
	private const BOB = 'https://cloud.example/users/bob';

	private DomainBlocksRequest|Stub $domainBlocksRequest;
	private ConfigService|Stub $configService;

	/** @var array<string, string[]> actor id => domains, newest first */
	private array $blocks = [];
	/** @var array<int, array{string, string, string}> every write, in order */
	private array $writes = [];
	/** @var int how often the store was read */
	private int $reads = 0;

	protected function setUp(): void {
		$this->domainBlocksRequest = $this->createStub(DomainBlocksRequest::class);
		$this->domainBlocksRequest->method('getByActor')
			->willReturnCallback(function (string $actorId, int $limit): array {
				$this->reads++;

				return array_slice($this->blocks[$actorId] ?? [], 0, $limit);
			});
		$this->domainBlocksRequest->method('save')
			->willReturnCallback(function (string $actorId, string $domain): void {
				$this->writes[] = ['save', $actorId, $domain];
				$this->blocks[$actorId] ??= [];
				if (!in_array($domain, $this->blocks[$actorId], true)) {
					array_unshift($this->blocks[$actorId], $domain);
				}
			});
		$this->domainBlocksRequest->method('delete')
			->willReturnCallback(function (string $actorId, string $domain): void {
				$this->writes[] = ['delete', $actorId, $domain];
				$this->blocks[$actorId] = array_values(
					array_diff($this->blocks[$actorId] ?? [], [$domain])
				);
			});

		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getSocialAddress')->willReturn('cloud.example');
		$this->configService->method('getCloudHost')->willReturn('cloud.example');
	}

	private function service(): DomainBlockService {
		return new DomainBlockService($this->domainBlocksRequest, $this->configService);
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);

		return $person;
	}

	public function testADomainIsStoredInTheOneFormTheTimelineCompares(): void {
		// the join matches it against the host of an actor id, which is
		// lowercase and carries no scheme, port or path
		$this->assertSame('example.com', DomainBlockService::normalise('Example.COM'));
		$this->assertSame('example.com', DomainBlockService::normalise('  example.com  '));
		$this->assertSame('example.com', DomainBlockService::normalise('example.com.'));
		$this->assertSame('example.com', DomainBlockService::normalise('https://example.com/@user'));
		$this->assertSame('example.com', DomainBlockService::normalise('example.com:8080'));
	}

	public function testTheInstanceInAHandleIsWhatIsBlocked(): void {
		// a user types what they see, and what they see is the account
		$this->assertSame('example.com', DomainBlockService::normalise('@user@example.com'));
		$this->assertSame('example.com', DomainBlockService::normalise('user@example.com'));
	}

	public function testADomainThatCouldWidenItsOwnPatternIsRefused(): void {
		// the timeline join builds a LIKE pattern out of this value: a stored
		// `%` would be a block on one instance that quietly matched others
		foreach (['ex%mple.com', 'exa_ple.com', 'exa mple.com', '*.example.com'] as $wrong) {
			try {
				DomainBlockService::normalise($wrong);
				$this->fail('accepted ' . json_encode($wrong));
			} catch (InvalidResourceException $e) {
				$this->assertStringContainsString('domain', $e->getMessage());
			}
		}
	}

	public function testSomethingThatIsNotADomainAtAllIsRefused(): void {
		foreach (['', '   ', '@', 'https://', '-example.com', 'example-.com'] as $wrong) {
			try {
				DomainBlockService::normalise($wrong);
				$this->fail('accepted ' . json_encode($wrong));
			} catch (InvalidResourceException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}

	public function testBlockingYourOwnInstanceIsRefused(): void {
		// it would hide the account's own posts from itself, and every other
		// local account with them
		$this->expectException(InvalidResourceException::class);
		$this->service()->block($this->person(self::ALICE), 'cloud.example');
	}

	public function testWhatIsBlockedIsWhatIsStored(): void {
		$service = $this->service();

		$this->assertSame('example.com', $service->block($this->person(self::ALICE), '@user@Example.com'));
		$this->assertSame([['save', self::ALICE, 'example.com']], $this->writes);
	}

	public function testUnblockingNamesTheSameStoredForm(): void {
		$service = $this->service();
		$service->block($this->person(self::ALICE), 'example.com');
		$service->unblock($this->person(self::ALICE), 'HTTPS://Example.com/users/bob');

		$this->assertSame(['save', 'delete'], array_column($this->writes, 0));
		$this->assertSame('example.com', $this->writes[1][2]);
		$this->assertSame([], $this->blocks[self::ALICE]);
	}

	public function testTheInstanceOfAnAccountIsTheHostOfItsActorId(): void {
		$this->assertSame('remote.example', DomainBlockService::domainOf('https://remote.example/users/carol'));
		$this->assertSame('remote.example', DomainBlockService::domainOf('https://Remote.Example/users/carol'));
		$this->assertSame('', DomainBlockService::domainOf('carol@remote.example'));
	}

	public function testAnAccountOnABlockedInstanceIsBlocked(): void {
		// the whole point of the feature at the relationship level: without it
		// `domain_blocking` is false for an account the viewer cannot see
		$this->blocks[self::ALICE] = ['remote.example'];

		$this->assertTrue(
			$this->service()->isBlocking(self::ALICE, 'https://remote.example/users/carol')
		);
	}

	public function testABlockOnOneInstanceIsNotABlockOnAnotherThatEndsWithIt(): void {
		$this->blocks[self::ALICE] = ['example.com'];
		$service = $this->service();

		$this->assertFalse($service->isBlocking(self::ALICE, 'https://evil.example.com/users/carol'));
		$this->assertFalse($service->isBlocking(self::ALICE, 'https://example.com.evil.test/users/carol'));
	}

	public function testOneAccountsBlockIsNeverAnothersBusiness(): void {
		// two accounts on the same instance, one of them blocking: the other
		// must see nothing of it
		$this->blocks[self::ALICE] = ['remote.example'];
		$service = $this->service();

		$this->assertTrue($service->isBlocking(self::ALICE, 'https://remote.example/users/carol'));
		$this->assertFalse($service->isBlocking(self::BOB, 'https://remote.example/users/carol'));
		$this->assertSame([], $service->getBlocked($this->person(self::BOB)));
	}

	public function testOneAccountsUnblockDoesNotUnblockAnothers(): void {
		$this->blocks[self::ALICE] = ['remote.example'];
		$this->blocks[self::BOB] = ['remote.example'];
		$service = $this->service();

		$service->unblock($this->person(self::BOB), 'remote.example');

		$this->assertSame(['remote.example'], $service->getBlocked($this->person(self::ALICE)));
		$this->assertSame([], $service->getBlocked($this->person(self::BOB)));
	}

	public function testAPageOfAccountsReadsTheBlockListOnce(): void {
		// a relationship is built one account at a time; without this a client
		// asking about forty of them would read the same short list forty times
		$this->blocks[self::ALICE] = ['remote.example'];
		$service = $this->service();

		for ($i = 0; $i < 40; $i++) {
			$service->isBlocking(self::ALICE, 'https://remote.example/users/carol' . $i);
		}

		$this->assertSame(1, $this->reads);
	}

	public function testTheListIsReadAgainAfterTheAccountChangesIt(): void {
		$service = $this->service();
		$this->assertFalse($service->isBlocking(self::ALICE, 'https://remote.example/users/carol'));

		$service->block($this->person(self::ALICE), 'remote.example');

		$this->assertTrue(
			$service->isBlocking(self::ALICE, 'https://remote.example/users/carol'),
			'a block taken in this request has to apply in it'
		);
	}

	public function testAnAccountWithNoInstanceIsNotBlocked(): void {
		$this->blocks[self::ALICE] = ['remote.example'];

		$this->assertFalse($this->service()->isBlocking(self::ALICE, ''));
	}
}
