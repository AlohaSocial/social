<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Protocol;

use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Mst;
use OCA\Social\Tests\Atproto\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The tree against the interop vectors and the reference implementation's
 * known roots: the same records must give the same root CID everywhere, or
 * no relay could verify a commit.
 */
class MstTest extends TestCase {
	private const LEAF = 'bafyreie5cvv4h45feadgeuwhbcutmh6t2ceseocckahdoe6uat64zmz454';

	public function testKeyHeights(): void {
		foreach (Fixtures::json('key_heights.json') as $vector) {
			$this->assertSame($vector['height'], Mst::layer($vector['key']), $vector['key']);
		}
	}

	public function testCommonPrefixes(): void {
		foreach (Fixtures::json('common_prefix.json') as $vector) {
			$this->assertSame($vector['len'], Mst::commonPrefixLength($vector['left'], $vector['right']));
		}
	}

	public function testAnEmptyTreeIsOneEmptyNode(): void {
		$tree = (new Mst([]))->build();

		$this->assertSame('bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm', $tree->root->toString());
		$this->assertCount(1, $tree->blocks);
		$this->assertSame(['e' => [], 'l' => null], DagCbor::decode($tree->blocks[$tree->root->toString()]), 'keys in canonical order');
	}

	/**
	 * @return iterable<string, array{0: string[], 1: string}>
	 */
	public static function knownTrees(): iterable {
		// the "known maps" and "edge cases" of the reference implementation's
		// test-suite, which every implementation reproduces
		yield 'trivial' => [['com.example.record/3jqfcqzm3fo2j'], 'bafyreibj4lsc3aqnrvphp5xmrnfoorvru4wynt6lwidqbm2623a6tatzdu'];
		yield 'singlelayer2' => [['com.example.record/3jqfcqzm3fx2j'], 'bafyreih7wfei65pxzhauoibu3ls7jgmkju4bspy4t2ha2qdjnzqvoy33ai'];
		yield 'simple' => [[
			'com.example.record/3jqfcqzm3fp2j', 'com.example.record/3jqfcqzm3fr2j', 'com.example.record/3jqfcqzm3fs2j',
			'com.example.record/3jqfcqzm3ft2j', 'com.example.record/3jqfcqzm4fc2j',
		], 'bafyreicmahysq4n6wfuxo522m6dpiy7z7qzym3dzs756t5n7nfdgccwq7m'];
		yield 'trim top, before' => [[
			'com.example.record/3jqfcqzm3fn2j', 'com.example.record/3jqfcqzm3fo2j', 'com.example.record/3jqfcqzm3fp2j',
			'com.example.record/3jqfcqzm3fs2j', 'com.example.record/3jqfcqzm3ft2j', 'com.example.record/3jqfcqzm3fu2j',
		], 'bafyreifnqrwbk6ffmyaz5qtujqrzf5qmxf7cbxvgzktl4e3gabuxbtatv4'];
		yield 'trim top, after' => [[
			'com.example.record/3jqfcqzm3fn2j', 'com.example.record/3jqfcqzm3fo2j', 'com.example.record/3jqfcqzm3fp2j',
			'com.example.record/3jqfcqzm3ft2j', 'com.example.record/3jqfcqzm3fu2j',
		], 'bafyreie4kjuxbwkhzg2i5dljaswcroeih4dgiqq6pazcmunwt2byd725vi'];
		$split = [
			'com.example.record/3jqfcqzm3fo2j', 'com.example.record/3jqfcqzm3fp2j', 'com.example.record/3jqfcqzm3fr2j',
			'com.example.record/3jqfcqzm3fs2j', 'com.example.record/3jqfcqzm3ft2j', 'com.example.record/3jqfcqzm3fz2j',
			'com.example.record/3jqfcqzm4fc2j', 'com.example.record/3jqfcqzm4fd2j', 'com.example.record/3jqfcqzm4ff2j',
			'com.example.record/3jqfcqzm4fg2j', 'com.example.record/3jqfcqzm4fh2j',
		];
		yield 'split two layers down, before' => [$split, 'bafyreiettyludka6fpgp33stwxfuwhkzlur6chs4d2v4nkmq2j3ogpdjem'];
		yield 'split two layers down, after' => [[...$split, 'com.example.record/3jqfcqzm3fx2j'], 'bafyreid2x5eqs4w4qxvc5jiwda4cien3gw2q6cshofxwnvv7iucrmfohpm'];
		yield 'two layers higher, before' => [['com.example.record/3jqfcqzm3ft2j', 'com.example.record/3jqfcqzm3fz2j'], 'bafyreidfcktqnfmykz2ps3dbul35pepleq7kvv526g47xahuz3rqtptmky'];
		yield 'two layers higher, after' => [['com.example.record/3jqfcqzm3ft2j', 'com.example.record/3jqfcqzm3fz2j', 'com.example.record/3jqfcqzm3fx2j'], 'bafyreiavxaxdz7o7rbvr3zg2liox2yww46t7g6hkehx4i4h3lwudly7dhy'];
	}

	/**
	 * @param string[] $keys
	 */
	#[DataProvider('knownTrees')]
	public function testKnownRoots(array $keys, string $root): void {
		$this->assertSame($root, self::rootOf($keys)->toString());
	}

	public function testTheOrderTheKeysAreGivenInDoesNotMatter(): void {
		$keys = ['com.example.record/3jqfcqzm3fp2j', 'com.example.record/3jqfcqzm3fr2j', 'com.example.record/3jqfcqzm3fs2j'];
		$this->assertTrue(self::rootOf($keys)->equals(self::rootOf(array_reverse($keys))));
	}

	public function testCommitProofFixtures(): void {
		foreach (Fixtures::json('commit-proof-fixtures.json') as $vector) {
			$leaf = Cid::parse($vector['leafValue']);
			$before = array_fill_keys($vector['keys'], $leaf);
			$after = array_diff_key($before + array_fill_keys($vector['adds'], $leaf), array_fill_keys($vector['dels'], true));

			$this->assertSame($vector['rootBeforeCommit'], (new Mst($before))->build()->root->toString(), $vector['comment']);
			$this->assertSame($vector['rootAfterCommit'], (new Mst($after))->build()->root->toString(), $vector['comment']);
		}
	}

	public function testTheNodesACommitAddsAreTheOnesTheOldTreeDidNotHave(): void {
		$leaf = Cid::parse(self::LEAF);
		$before = (new Mst(['com.example.record/3jqfcqzm3fp2j' => $leaf]))->build();
		$after = (new Mst(['com.example.record/3jqfcqzm3fp2j' => $leaf, 'com.example.record/3jqfcqzm3fr2j' => $leaf]))->build();

		$added = $after->blocksNotIn($before);
		$dropped = $after->cidsDroppedFrom($before);
		$this->assertSame(array_keys($after->blocks), array_keys($added), 'one node, rewritten, is new');
		$this->assertSame(array_keys($before->blocks), $dropped);
		$this->assertSame([], $after->blocksNotIn($after));
	}

	public function testWhatARepositoryKeyMayBe(): void {
		$this->assertTrue(Mst::isValidKey('app.bsky.feed.post/3jqfcqzm3fo2j'));
		$this->assertTrue(Mst::isValidKey('app.bsky.actor.profile/self'));
		$this->assertFalse(Mst::isValidKey('no-slash'));
		$this->assertFalse(Mst::isValidKey('notansid/3jqfcqzm3fo2j'));
		$this->assertFalse(Mst::isValidKey('app.bsky.feed.post/..'));
		$this->assertFalse(Mst::isValidKey('app.bsky.feed.post/a/b'));
	}

	/**
	 * @param string[] $keys
	 */
	private static function rootOf(array $keys): Cid {
		return (new Mst(array_fill_keys($keys, Cid::parse(self::LEAF))))->build()->root;
	}
}
