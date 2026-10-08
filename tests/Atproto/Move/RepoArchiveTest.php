<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Move\RepoArchive;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Commit;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Mst;
use OCA\Social\Atproto\Protocol\MstReader;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Exceptions\AtprotoException;
use PHPUnit\Framework\TestCase;

class RepoArchiveTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private PrivateKey $key;

	protected function setUp(): void {
		$this->key = PrivateKey::generate(Curve::K256);
	}

	/**
	 * A repository as another PDS would export it, with this many posts and
	 * one record of an application this server knows nothing of.
	 *
	 * @return array{car: string, records: array<string, string>, rev: string}
	 */
	private function repository(int $posts, string $did = self::DID, ?PrivateKey $key = null, bool $dropARecord = false): array {
		$records = [];
		for ($i = 0; $i < $posts; $i++) {
			$records['app.bsky.feed.post/' . Tid::next()] = DagCbor::encode(['$type' => 'app.bsky.feed.post', 'text' => 'post ' . $i, 'createdAt' => '2026-01-01T00:00:00.000Z']);
		}
		$records['com.whtwnd.blog.entry/3kblog'] = DagCbor::encode(['$type' => 'com.whtwnd.blog.entry', 'content' => 'long form', 'visibility' => 'public']);
		$leaves = array_map(static fn (string $bytes): Cid => Cid::forDagCbor($bytes), $records);
		$tree = (new Mst($leaves))->build();
		$rev = Tid::next();
		$commit = Commit::sign($did, $tree->root, $rev, $key ?? $this->key);
		$blocks = [$commit->cid()->toString() => $commit->toBytes()] + $tree->blocks;
		foreach ($records as $path => $bytes) {
			if ($dropARecord && str_starts_with($path, 'com.whtwnd')) {
				continue;
			}
			$blocks[Cid::forDagCbor($bytes)->toString()] = $bytes;
		}

		return ['car' => Car::encode([$commit->cid()], $blocks), 'records' => $records, 'rev' => $rev];
	}

	public function testEveryRecordComesOutAsItWasEvenOfAnUnknownApplication(): void {
		$repository = $this->repository(300);

		$archive = RepoArchive::read($repository['car'], self::DID, $this->key->publicKey());

		$this->assertSame($repository['rev'], $archive['rev']);
		ksort($repository['records']);
		$this->assertSame($repository['records'], $archive['records'], 'byte for byte, so every CID stays');
	}

	public function testTheTreeIsReadInKeyOrderAndRoundTrips(): void {
		$leaves = [];
		foreach (['app.bsky.feed.like/3kz', 'app.bsky.feed.post/3ka', 'app.bsky.graph.follow/3km', 'app.bsky.actor.profile/self'] as $path) {
			$leaves[$path] = Cid::forRaw($path);
		}
		$tree = (new Mst($leaves))->build();

		$read = MstReader::leaves($tree->root, $tree->blocks);

		ksort($leaves);
		$this->assertSame(array_keys($leaves), array_keys($read));
		$this->assertTrue($read['app.bsky.graph.follow/3km']->equals($leaves['app.bsky.graph.follow/3km']));
	}

	public function testAnArchiveThatIsNotTheAccountsIsRefused(): void {
		foreach ([
			'signed with another key' => $this->repository(3, self::DID, PrivateKey::generate(Curve::K256)),
			'of another account' => $this->repository(3, 'did:plc:z72i7hdynmk6r22z27h6tvur'),
			'missing a record' => $this->repository(3, self::DID, null, true),
		] as $what => $repository) {
			try {
				RepoArchive::read($repository['car'], self::DID, $this->key->publicKey());
				$this->fail('read: ' . $what);
			} catch (AtprotoException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->expectException(AtprotoException::class);
		RepoArchive::read('not a car', self::DID, $this->key->publicKey());
	}
}
