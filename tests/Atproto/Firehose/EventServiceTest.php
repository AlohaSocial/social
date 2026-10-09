<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Firehose;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Model\Event;
use OCA\Social\Atproto\Protocol\Bytes;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Commit;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Mst;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Db\AtprotoEventRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * The frames the firehose is written in, as the subscribeRepos lexicon
 * has them.
 */
class EventServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	/** @var list<array{string, string, string}> did, kind and body of each frame appended */
	private array $appended = [];

	private function events(): EventService {
		$request = $this->createStub(AtprotoEventRequest::class);
		$request->method('append')->willReturnCallback(function (string $did, string $kind, string $body): int {
			$this->appended[] = [$did, $kind, $body];

			return count($this->appended);
		});
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);

		return new EventService($request, $time);
	}

	/**
	 * A `#sync` carries the head commit alone, as the CAR's root, with its
	 * rev: what a relay needs to take the repository as it is now.
	 */
	public function testASyncFrameCarriesTheHeadCommitAndItsRev(): void {
		$commit = Commit::sign(self::DID, (new Mst([]))->build()->root, Tid::next(), PrivateKey::generate(Curve::K256));

		$this->assertSame(1, $this->events()->sync(self::DID, $commit));

		[$did, $kind, $body] = $this->appended[0];
		$this->assertSame([self::DID, Event::KIND_SYNC], [$did, $kind]);
		$decoded = DagCbor::decode($body);
		$keys = array_keys($decoded);
		sort($keys);
		$this->assertSame(['blocks', 'did', 'rev', 'time'], $keys);
		$this->assertSame([self::DID, $commit->rev, '2025-10-09T08:53:20.000Z'], [$decoded['did'], $decoded['rev'], $decoded['time']]);
		$this->assertInstanceOf(Bytes::class, $decoded['blocks']);
		$car = Car::decode($decoded['blocks']->value);
		$this->assertSame([$commit->cid()->toString()], array_map(static fn ($cid): string => $cid->toString(), $car['roots']));
		$this->assertSame([$commit->cid()->toString() => $commit->toBytes()], $car['blocks']);
	}
}
