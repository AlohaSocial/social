<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\DiscoveryRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Moderation;
use OCA\Social\Service\DirectoryService;
use OCA\Social\Service\ModerationService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * What the directory decides above the SQL: which order was asked for, how big
 * a page may be, and who is kept out of it.
 *
 * The `discoverable` predicate itself is part of the statement and is checked
 * in DiscoveryRequestTest; that it is the *only* source of rows the directory
 * has is what makes the opt-in enforceable at all.
 */
class DirectoryServiceTest extends TestCase {
	private DiscoveryRequest|Stub $discoveryRequest;
	private ModerationService|Stub $moderationService;
	private DirectoryService $service;

	/** @var array{order: string, limit: int, offset: int}|null what the store was asked */
	private ?array $asked = null;
	/** @var string[] the prims the store answers with */
	private array $prims = [];
	/** @var string[] the prims the entities were built from */
	private array $fetched = [];
	/** @var Moderation[] the decisions a moderator has taken */
	private array $decisions = [];

	protected function setUp(): void {
		$this->discoveryRequest = $this->createStub(DiscoveryRequest::class);
		$this->discoveryRequest->method('directoryPrims')
			->willReturnCallback(function (string $order, int $limit, int $offset): array {
				$this->asked = ['order' => $order, 'limit' => $limit, 'offset' => $offset];

				return $this->prims;
			});
		$this->discoveryRequest->method('actorsByPrims')
			->willReturnCallback(function (array $prims): array {
				$this->fetched = $prims;

				return array_map(static function (string $prim): Person {
					$person = new Person();
					$person->setId('https://cloud.example/users/' . $prim);

					return $person;
				}, $prims);
			});

		$this->moderationService = $this->createStub(ModerationService::class);
		$this->moderationService->method('decisions')
			->willReturnCallback(fn (): array => $this->decisions);

		$this->service = new DirectoryService($this->discoveryRequest, $this->moderationService);
	}

	private function decision(string $actorId, string $level): Moderation {
		return new Moderation($actorId, $level);
	}

	/** Mastodon's default, and the one a client that sends no `order` gets. */
	public function testTheDefaultOrderIsActive(): void {
		$this->service->page('', DirectoryService::LIMIT, 0);

		$this->assertSame(DiscoveryRequest::ORDER_ACTIVE, $this->asked['order']);
	}

	public function testTheNewOrderIsPassedThrough(): void {
		$this->service->page('new', DirectoryService::LIMIT, 0);

		$this->assertSame(DiscoveryRequest::ORDER_NEW, $this->asked['order']);
	}

	/** An order nobody defined is read as the default, not refused. */
	public function testAnUnknownOrderFallsBackToActive(): void {
		$this->service->page('alphabetical', DirectoryService::LIMIT, 0);

		$this->assertSame(DiscoveryRequest::ORDER_ACTIVE, $this->asked['order']);
	}

	public function testThePageSizeIsCapped(): void {
		$this->service->page('active', 5000, 0);

		$this->assertSame(DirectoryService::MAX_LIMIT, $this->asked['limit']);
	}

	public function testAPageSizeOfZeroStillAsksForARow(): void {
		$this->service->page('active', 0, 0);

		$this->assertSame(1, $this->asked['limit']);
	}

	public function testANegativeOffsetIsReadAsTheStart(): void {
		$this->service->page('active', DirectoryService::LIMIT, -20);

		$this->assertSame(0, $this->asked['offset']);
	}

	/**
	 * A silenced account has been taken out of the public timelines. Leaving
	 * it in the directory would be removing it from the room and leaving it in
	 * the shop window.
	 */
	public function testASilencedAccountIsNotListed(): void {
		$this->prims = [md5('a'), md5('b'), md5('c')];
		$this->decisions = [$this->decision('b', Moderation::SILENCE)];

		$this->service->page('active', DirectoryService::LIMIT, 0);

		$this->assertSame([md5('a'), md5('c')], $this->fetched);
	}

	public function testASuspendedAccountIsNotListed(): void {
		$this->prims = [md5('a'), md5('b')];
		$this->decisions = [$this->decision('a', Moderation::SUSPEND)];

		$this->service->page('active', DirectoryService::LIMIT, 0);

		$this->assertSame([md5('b')], $this->fetched);
	}

	public function testAnInstanceWithNoDecisionsListsEverybodyWhoOptedIn(): void {
		$this->prims = [md5('a'), md5('b')];

		$page = $this->service->page('active', DirectoryService::LIMIT, 0);

		$this->assertCount(2, $page);
	}

	/** The page order the store chose is the page order the client is given. */
	public function testTheStoreOrderSurvives(): void {
		$this->prims = [md5('c'), md5('a'), md5('b')];

		$this->service->page('active', DirectoryService::LIMIT, 0);

		$this->assertSame([md5('c'), md5('a'), md5('b')], $this->fetched);
	}
}
