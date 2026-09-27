<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ExternalInvitesRequest;
use OCA\Social\Db\ExternalSignupsRequest;
use OCA\Social\Db\ExternalUsersRequest;
use OCP\DB\Exception as DBException;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The three tables of self-registered external users, against a real
 * database: what a unit test cannot say is whether the queries are right.
 */
class ExternalRequestsTest extends TestCase {
	private ExternalUsersRequest $users;
	private ExternalSignupsRequest $signups;
	private ExternalInvitesRequest $invites;

	protected function setUp(): void {
		parent::setUp();
		$this->users = Server::get(ExternalUsersRequest::class);
		$this->signups = Server::get(ExternalSignupsRequest::class);
		$this->invites = Server::get(ExternalInvitesRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$connection = Server::get(IDBConnection::class);
		foreach (['social_ext_user', 'social_ext_signup', 'social_ext_invite'] as $table) {
			$qb = $connection->getQueryBuilder();
			$qb->delete($table)->executeStatement();
		}
	}

	public function testALoginIsFoundWhateverTheCaseAndIsUniqueThatWay(): void {
		$this->users->create('Itest_Alice', 'HASH', 'Alice A', 'open', 1000);

		$this->assertSame('Itest_Alice', $this->users->get('itest_alice')['uid']);
		$this->assertSame('Alice A', $this->users->get('ITEST_ALICE')['displayname']);
		$this->assertNull($this->users->get('itest_bob'));

		$this->expectException(DBException::class);
		$this->users->create('itest_alice', 'HASH', '', 'open', 1000);
	}

	public function testSearchCountAndChanges(): void {
		$this->users->create('itest_alice', 'H1', 'Alice', 'open', 1);
		$this->users->create('itest_bob', 'H2', 'Roberta', 'approval', 2);
		$this->users->create('itest_carol', 'H3', '', 'occ', 3);

		$this->assertSame(3, $this->users->count());
		$this->assertSame(['itest_alice', 'itest_bob', 'itest_carol'], array_column($this->users->search(), 'uid'));
		$this->assertSame(['itest_bob'], array_column($this->users->search('robert'), 'uid'));
		$this->assertSame(['itest_bob'], array_column($this->users->search('', 1, 1), 'uid'));

		$this->assertTrue($this->users->setPasswordHash('ITEST_ALICE', 'H9'));
		$this->assertTrue($this->users->setDisplayName('itest_alice', 'Alice Z'));
		$this->assertSame('H9', $this->users->get('itest_alice')['password']);
		$this->assertSame('Alice Z', $this->users->get('itest_alice')['displayname']);

		$this->assertTrue($this->users->delete('itest_alice'));
		$this->assertFalse($this->users->delete('itest_alice'));
		$this->assertSame(2, $this->users->count());
	}

	public function testARegistrationReservesItsHandleAndIsFoundByItsToken(): void {
		$id = $this->signups->create('itest_dave', 'dave@example.org', 'H', hash('sha256', 't'), false, true, 0, 'ip1', 1000);

		$this->assertSame($id, $this->signups->getByHandle('ITEST_DAVE')['id']);
		$this->assertSame($id, $this->signups->getByEmail('Dave@Example.org')['id']);
		$row = $this->signups->getByTokenHash(hash('sha256', 't'));
		$this->assertFalse($row['verified']);
		$this->assertTrue($row['approval']);
		$this->assertNull($this->signups->getByTokenHash(''));

		$this->assertSame(0, $this->signups->countAwaitingApproval());
		$this->signups->markVerified($id);
		$this->assertTrue($this->signups->get($id)['verified']);
		$this->assertNull($this->signups->getByTokenHash(hash('sha256', 't')), 'a link works once');
		$this->assertSame(1, $this->signups->countAwaitingApproval());
		$this->assertSame([$id], array_column($this->signups->awaitingApproval(), 'id'));

		$this->expectException(DBException::class);
		$this->signups->create('itest_dave', 'other@example.org', 'H', 'x', false, false, 0, 'ip2', 1000);
	}

	public function testOnlyUnconfirmedRegistrationsExpire(): void {
		$this->signups->create('itest_old', 'old@example.org', 'H', 'a', false, false, 0, 'ip', 100);
		$waiting = $this->signups->create('itest_waiting', 'w@example.org', 'H', '', true, true, 0, 'ip', 100);
		$this->signups->create('itest_new', 'new@example.org', 'H', 'b', false, false, 0, 'ip', 5000);

		$this->assertSame(3, $this->signups->countFromIpSince('ip', 0));
		$this->assertSame(1, $this->signups->countFromIpSince('ip', 1000));
		$this->assertSame(1, $this->signups->purgeUnverifiedBefore(1000));
		$this->assertNotNull($this->signups->get($waiting));
		$this->assertSame(2, $this->signups->count());
	}

	public function testAnInvitationIsUsedUpAtItsLimitAndNotPastIt(): void {
		$once = $this->invites->create('tok-once', 'admin', 'note', 1, 0, 1000);
		$twice = $this->invites->create('tok-twice', 'carol', '', 2, 0, 1001);
		$any = $this->invites->create('tok-any', 'admin', '', 0, 0, 1002);

		$this->assertTrue($this->invites->consume($once));
		$this->assertFalse($this->invites->consume($once), 'the last use cannot be had twice');
		$this->assertTrue($this->invites->consume($twice));
		$this->assertTrue($this->invites->consume($twice));
		$this->assertFalse($this->invites->consume($twice));
		for ($i = 0; $i < 5; $i++) {
			$this->assertTrue($this->invites->consume($any));
		}

		$this->assertSame(5, $this->invites->getByToken('tok-any')['uses']);
		$this->assertSame(['tok-any', 'tok-twice', 'tok-once'], array_column($this->invites->list(), 'token'));
		$this->assertSame(['tok-twice'], array_column($this->invites->list('carol'), 'token'));
	}

	public function testSpentAndExpiredInvitationsAreForgotten(): void {
		$spent = $this->invites->create('tok-spent', 'admin', '', 1, 0, 1);
		$this->invites->consume($spent);
		$this->invites->create('tok-expired', 'admin', '', 0, 500, 1);
		$this->invites->create('tok-live', 'admin', '', 3, 5000, 1);
		$this->invites->create('tok-forever', 'admin', '', 0, 0, 1);

		$this->assertSame(2, $this->invites->purgeSpent(1000));
		$left = array_column($this->invites->list(), 'token');
		sort($left);
		$this->assertSame(['tok-forever', 'tok-live'], $left);
		$this->assertNull($this->invites->getByToken('tok-spent'));
		$this->assertNull($this->invites->getByToken('tok-expired'));
	}
}
