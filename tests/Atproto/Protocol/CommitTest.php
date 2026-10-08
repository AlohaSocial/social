<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Protocol;

use InvalidArgumentException;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Protocol\Bytes;
use OCA\Social\Atproto\Protocol\Commit;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Mst;
use OCA\Social\Atproto\Protocol\Tid;
use PHPUnit\Framework\TestCase;

class CommitTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	public function testACommitIsSignedReadBackAndVerified(): void {
		$key = PrivateKey::generate(Curve::K256);
		$root = (new Mst([]))->build()->root;
		$rev = Tid::next();

		$commit = Commit::sign(self::DID, $root, $rev, $key);
		$bytes = $commit->toBytes();
		$decoded = DagCbor::decode($bytes);

		$this->assertSame(['did', 'rev', 'sig', 'data', 'prev', 'version'], array_keys($decoded), 'canonical key order');
		$this->assertSame(3, $decoded['version']);
		$this->assertNull($decoded['prev']);
		$this->assertSame(64, strlen($decoded['sig']->value));

		$again = Commit::fromBytes($bytes);
		$this->assertSame($rev, $again->rev);
		$this->assertTrue($again->data->equals($root));
		$this->assertTrue($again->verify($key->publicKey()));
		$this->assertFalse($again->verify(PrivateKey::generate(Curve::K256)->publicKey()));
		$this->assertTrue($again->cid()->equals($commit->cid()));
	}

	public function testATamperedCommitDoesNotVerify(): void {
		$key = PrivateKey::generate(Curve::K256);
		$commit = Commit::sign(self::DID, (new Mst([]))->build()->root, Tid::next(), $key);
		$value = DagCbor::decode($commit->toBytes());
		$value['rev'] = Tid::next();

		$this->assertFalse(Commit::fromBytes(DagCbor::encode($value))->verify($key->publicKey()));
	}

	public function testAnOlderCommitShapeIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		Commit::fromBytes(DagCbor::encode([
			'did' => self::DID, 'version' => 2, 'data' => (new Mst([]))->build()->root, 'rev' => Tid::next(), 'prev' => null, 'sig' => new Bytes(str_repeat("\0", 64)),
		]));
	}
}
