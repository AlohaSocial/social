<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Command\AtprotoIdentities;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ social:atproto:identities`: an identity is made for an account that
 * has none, and one its owner switched off stays off, shown with its state.
 */
#[AllowMockObjectsWithoutExpectations]
class AtprotoIdentitiesTest extends TestCase {
	private IdentityService&MockObject $identities;
	/** @var array<string, Identity> by actor id */
	private array $existing = [];

	private static function actor(string $name): Person {
		$actor = new Person();
		$actor->setId('https://social.test/@' . $name);
		$actor->setPreferredUsername($name);
		$actor->setLocal(true);

		return $actor;
	}

	private function tester(): CommandTester {
		$config = $this->createStub(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$this->identities = $this->createMock(IdentityService::class);
		$this->identities->method('forActor')->willReturnCallback(fn (Person $actor, bool $create = true): ?Identity => $this->existing[$actor->getId()] ?? null);
		$this->identities->method('getAll')->willReturnCallback(fn (): array => array_values($this->existing));
		$actors = $this->createStub(ActorsRequest::class);
		$actors->method('getAll')->willReturn([self::actor('alice'), self::actor('bob')]);

		return new CommandTester(new AtprotoIdentities($config, $this->identities, $this->createStub(Publisher::class), $actors));
	}

	public function testASwitchedOffIdentityIsNeitherMadeAgainNorSwitchedOn(): void {
		$this->existing['https://social.test/@alice'] = new Identity(1, 'https://social.test/@alice', 'did:plc:alice', 'alice.social.test', '', '', '', Identity::STATE_DEACTIVATED, '', 0);
		$tester = $this->tester();
		$this->identities->expects($this->once())->method('create')->with($this->callback(static fn (Person $actor): bool => $actor->getPreferredUsername() === 'bob'))
			->willReturn(new Identity(2, 'https://social.test/@bob', 'did:plc:bob', 'bob.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0));
		$this->identities->expects($this->never())->method('activate');

		$this->assertSame(0, $tester->execute([]));

		$display = $tester->getDisplay();
		$this->assertStringContainsString('= alice  alice.social.test  did:plc:alice  (deactivated)', $display);
		$this->assertStringContainsString('+ bob  bob.social.test  did:plc:bob' . "\n", $display);
		$this->assertStringContainsString('1 identities made', $display);
	}

	public function testTheListShowsTheState(): void {
		$this->existing['https://social.test/@alice'] = new Identity(1, 'https://social.test/@alice', 'did:plc:alice', 'alice.social.test', '', '', '', Identity::STATE_DEACTIVATED, '', 0);
		$tester = $this->tester();

		$tester->execute(['--list' => true, '--output' => 'json']);

		$this->assertSame('deactivated', json_decode($tester->getDisplay(), true)[0]['state']);
	}
}
