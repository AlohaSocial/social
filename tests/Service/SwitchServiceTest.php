<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use Exception;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\SwitchService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SwitchServiceTest extends TestCase {
	private CacheActorService|Stub $cacheActorService;
	private SwitchService $service;

	protected function setUp(): void {
		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->service = new SwitchService($this->cacheActorService);
	}

	/**
	 * Instagram's export, as Instagram writes it.
	 *
	 * The names are under `string_list_data[].value`, nested under a key that
	 * has changed between exports — so the archive is searched for the shape
	 * rather than for a path.
	 */
	public function testTheNamesAreFoundWhereverInstagramPutThem(): void {
		$archive = json_encode([
			'relationships_following' => [
				['string_list_data' => [['value' => 'alice', 'href' => 'https://instagram.com/alice']]],
				['string_list_data' => [['value' => 'bob']]],
			],
		]);

		$this->assertSame(
			['alice@threads.net', 'bob@threads.net'],
			$this->service->candidates((string)$archive)
		);
	}

	public function testTheSameNameTwiceIsOneHandle(): void {
		$archive = json_encode([
			'a' => [['string_list_data' => [['value' => 'alice']]]],
			'b' => [['string_list_data' => [['value' => 'Alice']]]],
		]);

		$this->assertSame(['alice@threads.net'], $this->service->candidates((string)$archive));
	}

	/** A display name or a URL is not a handle and is not looked up. */
	public function testOnlyWhatCouldBeAHandleIsTaken(): void {
		$archive = json_encode([
			'x' => [['string_list_data' => [
				['value' => 'a real name'],
				['value' => 'https://example.org/somebody'],
				['value' => 'goodname'],
			]]],
		]);

		$this->assertSame(['goodname@threads.net'], $this->service->candidates((string)$archive));
	}

	public function testSomethingThatIsNotAnArchiveNamesNobody(): void {
		$this->assertSame([], $this->service->candidates('not json'));
		$this->assertSame([], $this->service->candidates('{}'));
	}

	/**
	 * Each name is a webfinger against another server, so one request looks up
	 * a bounded number of them and the caller asks again for the rest.
	 */
	public function testOneRequestLooksUpABoundedNumber(): void {
		$handles = [];
		for ($i = 0; $i < SwitchService::PROBE_BATCH + 10; $i++) {
			$handles[] = 'person' . $i . '@threads.net';
		}

		$this->cacheActorService->method('getFromAccount')->willThrowException(new Exception('not there'));

		$this->assertSame(SwitchService::PROBE_BATCH, $this->service->probe($handles)['checked']);
	}

	/**
	 * `checked` is how many were looked at, not how many were there.
	 *
	 * The wizard's cursor moves on it, so a batch where nobody is on Threads —
	 * which is most batches — still advances. A cursor that moved by the
	 * number found would ask about the same names for ever.
	 */
	public function testABatchWhereNobodyIsThereStillCountsAsChecked(): void {
		$this->cacheActorService->method('getFromAccount')->willThrowException(new Exception('not there'));

		$read = $this->service->probe(['a@threads.net', 'b@threads.net']);

		$this->assertSame(2, $read['checked']);
		$this->assertSame([], $read['found']);
	}

	public function testSomebodyWhoIsThereComesBackWithTheirName(): void {
		$person = new Person();
		$person->setId('https://threads.net/users/alice')
			->setPreferredUsername('alice')
			->setName('Alice Example')
			->setAccount('alice@threads.net');

		$this->cacheActorService->method('getFromAccount')
			->willReturnCallback(function (string $handle) use ($person): Person {
				if ($handle !== 'alice@threads.net') {
					throw new Exception('not there');
				}

				return $person;
			});

		$read = $this->service->probe(['alice@threads.net', 'nobody@threads.net']);

		$this->assertSame(2, $read['checked']);
		$this->assertSame([[
			'handle' => 'alice@threads.net',
			'name' => 'Alice Example',
			'url' => 'https://threads.net/users/alice',
			'avatar' => '',
		]], $read['found']);
	}

	/**
	 * Looking somebody up is not following them.
	 *
	 * The wizard asks about the whole list at once and then offers a checkbox
	 * per person; a probe that followed as it went would turn one upload into
	 * several hundred follows nobody agreed to.
	 */
	public function testLookingSomebodyUpOnlyReadsAndReturns(): void {
		$person = new Person();
		$person->setId('https://threads.net/users/alice')
			->setPreferredUsername('alice')
			->setAccount('alice@threads.net');
		$this->cacheActorService->method('getFromAccount')->willReturn($person);

		$read = $this->service->probe(['alice@threads.net']);

		// it answers who is there and nothing else: no relationship is created,
		// which is what makes the checkbox that follows them meaningful
		$this->assertSame(['handle', 'name', 'url', 'avatar'], array_keys($read['found'][0]));
	}

	/**
	 * The handle the card carries is the one this server publishes, which is
	 * not the Nextcloud user id for any account whose fediverse name differs
	 * from it.
	 */
	public function testTheCardCarriesTheHandleThisServerPublishes(): void {
		$actor = new Person();
		$actor->setId('https://cloud.example.org/users/alice')
			->setPreferredUsername('alice')
			->setAccount('alice@cloud.example.org');

		$card = $this->service->announcement($actor);

		$this->assertSame('@alice@cloud.example.org', $card['handle']);
		$this->assertSame('https://cloud.example.org/users/alice', $card['url']);
		$this->assertStringContainsString('@alice@cloud.example.org', $card['text']);
		$this->assertStringContainsString('https://cloud.example.org/users/alice', $card['text']);
	}
}
