<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\AdminAccount;
use OCA\Social\Model\Client\AdminDomainBlock;
use OCA\Social\Model\Client\AdminReport;
use OCA\Social\Model\Moderation;
use OCA\Social\Model\Report;
use PHPUnit\Framework\TestCase;

/**
 * The three entities `/api/v1/admin/*` answers with.
 *
 * What is asserted here is mostly the *presence* of keys, which is not
 * pedantry: a client that declares a field non-optional cannot decode an
 * entity a key is missing from, so a field this app has nothing behind has to
 * be sent as the empty value of its type rather than left out. Every such key
 * is named below, so removing one is a failing test rather than a client that
 * stops decoding.
 */
class AdminEntitiesTest extends TestCase {
	/** Every key Mastodon's `Admin::Account` documents. */
	private const ACCOUNT_KEYS = [
		'id', 'username', 'domain', 'created_at', 'email', 'ip', 'ips', 'locale', 'invite_request',
		'role', 'confirmed', 'approved', 'disabled', 'silenced', 'suspended', 'sensitized',
		'created_by_application_id', 'invited_by_account_id', 'account',
	];

	/** Every key Mastodon's `Admin::Report` documents. */
	private const REPORT_KEYS = [
		'id', 'action_taken', 'action_taken_at', 'category', 'comment', 'forwarded', 'created_at',
		'updated_at', 'account', 'target_account', 'assigned_account', 'action_taken_by_account',
		'statuses', 'rules',
	];

	/** Every key Mastodon's `Admin::DomainBlock` documents. */
	private const DOMAIN_BLOCK_KEYS = [
		'id', 'domain', 'created_at', 'severity', 'reject_media', 'reject_reports',
		'private_comment', 'public_comment', 'obfuscate',
	];

	private function person(string $id, int $nid, bool $local, string $account = ''): Person {
		$person = new Person();
		$person->setPreferredUsername('alice')
			->setAccount($account)
			->setCreation(1757548800);
		$person->setId($id);
		$person->setNid($nid);
		$person->setLocal($local);

		return $person;
	}

	public function testTheAccountCarriesEveryKeyMastodonDocuments(): void {
		$entity = AdminAccount::fromPerson($this->person('https://cloud.example/users/alice', 7, true))
			->jsonSerialize();

		foreach (self::ACCOUNT_KEYS as $key) {
			$this->assertArrayHasKey($key, $entity, 'Admin::Account must carry ' . $key);
		}
		$this->assertSame(self::ACCOUNT_KEYS, array_keys($entity));
	}

	public function testTheFieldsWithNothingBehindThemAreEmptyAndNotAbsent(): void {
		$entity = AdminAccount::fromPerson($this->person('https://cloud.example/users/alice', 7, true))
			->jsonSerialize();

		// this instance holds no address, no address history and no roles for
		// a fediverse account; each is the empty value of its own type
		$this->assertSame('', $entity['email']);
		$this->assertNull($entity['ip']);
		$this->assertSame([], $entity['ips']);
		$this->assertSame('', $entity['locale']);
		$this->assertNull($entity['invite_request']);
		$this->assertNull($entity['role']);
		$this->assertNull($entity['created_by_application_id']);
		$this->assertNull($entity['invited_by_account_id']);

		// no state in this app corresponds to either, so neither can be true
		$this->assertFalse($entity['disabled']);
		$this->assertFalse($entity['sensitized']);
	}

	public function testALocalAccountHasNoDomainAndIsConfirmed(): void {
		$entity = AdminAccount::fromPerson($this->person('https://cloud.example/users/alice', 7, true))
			->jsonSerialize();

		$this->assertNull($entity['domain']);
		$this->assertTrue($entity['confirmed']);
		$this->assertTrue($entity['approved']);
		$this->assertSame('7', $entity['id']);
	}

	public function testARemoteAccountReportsTheInstanceItIsOn(): void {
		$entity = AdminAccount::fromPerson(
			$this->person('https://remote.example/users/bob', 9, false, 'bob@Remote.example')
		)->jsonSerialize();

		$this->assertSame('remote.example', $entity['domain']);
		// both describe a *user* of this instance, and a cached remote actor
		// has none — which is Mastodon's own answer
		$this->assertFalse($entity['confirmed']);
		$this->assertFalse($entity['approved']);
	}

	public function testTheDecisionIsWhatSaysSilencedOrSuspended(): void {
		$actor = $this->person('https://remote.example/users/bob', 9, false, 'bob@remote.example');

		$silenced = AdminAccount::fromPerson($actor, Moderation::SILENCE)->jsonSerialize();
		$this->assertTrue($silenced['silenced']);
		$this->assertFalse($silenced['suspended']);

		$suspended = AdminAccount::fromPerson($actor, Moderation::SUSPEND)->jsonSerialize();
		$this->assertFalse($suspended['silenced']);
		$this->assertTrue($suspended['suspended']);

		$neither = AdminAccount::fromPerson($actor)->jsonSerialize();
		$this->assertFalse($neither['silenced']);
		$this->assertFalse($neither['suspended']);
	}

	public function testAnAccountKnownOnlyByItsSuspensionIsStillAddressable(): void {
		// the suspension deleted the cached actor, so there is no numeric id
		// left; without the actor id in its place the suspension could never
		// be lifted over this API
		$decision = new Moderation('https://remote.example/users/carol', Moderation::SUSPEND, '', 1757548800);
		$account = AdminAccount::fromDecision($decision);
		$entity = $account->jsonSerialize();

		$this->assertSame('https://remote.example/users/carol', $entity['id']);
		$this->assertSame('carol', $entity['username']);
		$this->assertSame('remote.example', $entity['domain']);
		$this->assertTrue($entity['suspended']);
		$this->assertNotNull($entity['account']);
	}

	public function testAnAccountOfThisInstanceKnownOnlyByItsSuspensionHasNoDomain(): void {
		$decision = new Moderation('https://cloud.example/users/dave', Moderation::SUSPEND, '', 1757548800);
		$entity = AdminAccount::fromDecision($decision, true)->jsonSerialize();

		$this->assertNull($entity['domain']);
		$this->assertTrue($entity['confirmed']);
	}

	public function testTheAccountEntityIsSerialisableWithoutAServer(): void {
		$json = json_encode(
			AdminAccount::fromPerson($this->person('https://cloud.example/users/alice', 7, true)),
			JSON_THROW_ON_ERROR
		);

		$decoded = json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame('alice', $decoded['account']['username']);
		$this->assertSame('7', $decoded['account']['id']);
	}

	public function testTheReportCarriesEveryKeyMastodonDocuments(): void {
		$report = new Report();
		$report->setId(3)->setCreation(1757548800);

		$entity = (new AdminReport($report))->jsonSerialize();

		foreach (self::REPORT_KEYS as $key) {
			$this->assertArrayHasKey($key, $entity, 'Admin::Report must carry ' . $key);
		}
		$this->assertSame(self::REPORT_KEYS, array_keys($entity));
	}

	public function testAnUntouchedReportNamesNobodyAndWasNeverEdited(): void {
		$report = new Report();
		$report->setId(3)->setCreation(1757548800);

		$entity = (new AdminReport($report))->jsonSerialize();

		$this->assertSame('3', $entity['id']);
		$this->assertNull($entity['assigned_account']);
		$this->assertNull($entity['action_taken_by_account']);
		$this->assertNull($entity['action_taken_at']);
		$this->assertFalse($entity['action_taken']);
		$this->assertFalse($entity['forwarded'], 'a report nobody asked to forward was not forwarded');
		$this->assertSame([], $entity['rules']);
		$this->assertSame($entity['created_at'], $entity['updated_at']);
	}

	public function testTheRulesAReporterNamedAreMastodonRules(): void {
		$report = new Report();
		$report->setId(3)->setCreation(1757548800)->setCategory(Report::CATEGORY_VIOLATION)
			->setRules(Report::decodeRules('[{"id":"2","text":"No spam"},{"id":"x"},"junk"]'));

		$this->assertSame(
			[['id' => '2', 'text' => 'No spam', 'hint' => '']],
			(new AdminReport($report))->jsonSerialize()['rules']
		);
		$this->assertSame(['No spam'], $report->moderationRow([])['rules']);
		$this->assertSame([], Report::decodeRules(null));
		$this->assertSame([], Report::decodeRules('not json'));
	}

	public function testAForwardedReportSaysSo(): void {
		$report = new Report();
		$report->setId(3)->setCreation(1757548800)->setForwarded(true);

		$this->assertTrue((new AdminReport($report))->jsonSerialize()['forwarded']);
	}

	public function testADecidedReportIsUpdatedWhenTheDecisionWasTaken(): void {
		$report = new Report();
		$report->setId(3)->setCreation(1757548800)->setResolved(true);

		$entity = (new AdminReport($report))->setActionTakenAt(1757635200)->jsonSerialize();

		$this->assertTrue($entity['action_taken']);
		$this->assertSame('2025-09-12T00:00:00.000Z', $entity['action_taken_at']);
		$this->assertSame('2025-09-12T00:00:00.000Z', $entity['updated_at']);
		$this->assertSame('2025-09-11T00:00:00.000Z', $entity['created_at']);
	}

	public function testTheDomainBlockCarriesEveryKeyMastodonDocuments(): void {
		$entity = (new AdminDomainBlock('evil.example'))->jsonSerialize();

		foreach (self::DOMAIN_BLOCK_KEYS as $key) {
			$this->assertArrayHasKey($key, $entity, 'Admin::DomainBlock must carry ' . $key);
		}
		$this->assertSame(self::DOMAIN_BLOCK_KEYS, array_keys($entity));
	}

	public function testABlockedDomainIsRefusedOutright(): void {
		$entity = (new AdminDomainBlock('evil.example'))->jsonSerialize();

		// an entry on the access list is not a partial measure: nothing from
		// the domain is read or delivered, media and reports included
		$this->assertSame('suspend', $entity['severity']);
		$this->assertTrue($entity['reject_media']);
		$this->assertTrue($entity['reject_reports']);
		$this->assertNull($entity['private_comment']);
		$this->assertNull($entity['public_comment']);
		$this->assertFalse($entity['obfuscate']);
		// the list stores no timestamps; the epoch is how this API says so
		$this->assertSame('1970-01-01T00:00:00.000Z', $entity['created_at']);
	}

	public function testADomainBlockIdIsNumericAndFollowsTheDomain(): void {
		$block = new AdminDomainBlock('evil.example');

		$this->assertMatchesRegularExpression('/^[0-9]+$/', $block->getId());
		$this->assertSame($block->getId(), AdminDomainBlock::idOf('EVIL.example'));
		$this->assertNotSame($block->getId(), AdminDomainBlock::idOf('other.example'));
	}

	public function testADomainBlockIsNamedByItsIdOrItsDomain(): void {
		$block = new AdminDomainBlock('evil.example');

		$this->assertTrue($block->isNamedBy($block->getId()));
		$this->assertTrue($block->isNamedBy('evil.example'));
		$this->assertTrue($block->isNamedBy('EVIL.EXAMPLE'));
		$this->assertFalse($block->isNamedBy('other.example'));
		$this->assertFalse($block->isNamedBy(''));
	}
}
