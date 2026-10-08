<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Lexicon;

use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Lexicon\LexiconException;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Tests\Atproto\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * The validator against the interop lexicon vectors (a catalogue of every
 * record feature) and against the Bluesky lexicons this app writes.
 */
class LexiconTest extends TestCase {
	private Lexicon $lexicon;

	protected function setUp(): void {
		$this->lexicon = new Lexicon([__DIR__ . '/../fixtures/lexicon', __DIR__ . '/../../../lib/Atproto/lexicons']);
	}

	public function testEveryValidRecordVectorPasses(): void {
		foreach (Fixtures::json('lexicon/record-data-valid.json') as $vector) {
			$this->lexicon->validateRecord(DagCbor::fromLexJson($vector['data']));
			$this->addToAssertionCount(1);
		}
	}

	public function testEveryInvalidRecordVectorIsRefused(): void {
		$vectors = Fixtures::json('lexicon/record-data-invalid.json');
		$this->assertGreaterThan(40, count($vectors));
		foreach ($vectors as $vector) {
			try {
				$this->lexicon->validateRecord(DagCbor::fromLexJson($vector['data']));
				$this->fail('accepted: ' . $vector['name']);
			} catch (LexiconException|\InvalidArgumentException $e) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testABlueskyPostFits(): void {
		$this->lexicon->validateRecord([
			'$type' => 'app.bsky.feed.post',
			'text' => 'Hello from Nextcloud 👋 #social',
			'createdAt' => '2026-10-08T10:00:00.000Z',
			'langs' => ['en'],
			'facets' => [[
				'index' => ['byteStart' => 24, 'byteEnd' => 31],
				'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'social']],
			]],
			'embed' => [
				'$type' => 'app.bsky.embed.images',
				'images' => [[
					'image' => ['$type' => 'blob', 'ref' => Cid::forRaw('x'), 'mimeType' => 'image/jpeg', 'size' => 1000],
					'alt' => 'a picture',
					'aspectRatio' => ['width' => 4, 'height' => 3],
				]],
			],
			'labels' => ['$type' => 'com.atproto.label.defs#selfLabels', 'values' => [['val' => '!warn']]],
		]);
		$this->addToAssertionCount(1);
	}

	public function testAPostOverThreeHundredGraphemesDoesNotFit(): void {
		$this->expectException(LexiconException::class);
		$this->expectExceptionMessage('longer than 300 characters');
		$this->lexicon->validateRecord(['$type' => 'app.bsky.feed.post', 'text' => str_repeat('a', 301), 'createdAt' => '2026-10-08T10:00:00.000Z']);
	}

	public function testGraphemesNotCodePointsAreCounted(): void {
		$family = '👨‍👩‍👧‍👧';
		$this->assertSame(1, Lexicon::graphemes($family));
		// 100 of them are 100 characters, within the 3,000 bytes as well
		$this->lexicon->validateRecord(['$type' => 'app.bsky.feed.post', 'text' => str_repeat($family, 100), 'createdAt' => '2026-10-08T10:00:00.000Z']);
		$this->addToAssertionCount(1);
	}

	public function testAProfileFits(): void {
		$this->lexicon->validateRecord([
			'$type' => 'app.bsky.actor.profile',
			'displayName' => 'Alice',
			'description' => 'On Nextcloud',
			'avatar' => ['$type' => 'blob', 'ref' => Cid::forRaw('a'), 'mimeType' => 'image/png', 'size' => 10],
			'createdAt' => '2026-10-08T10:00:00.000Z',
		]);
		$this->addToAssertionCount(1);
	}

	public function testAnAvatarMustBeAPicture(): void {
		$this->expectException(LexiconException::class);
		$this->lexicon->validateRecord([
			'$type' => 'app.bsky.actor.profile',
			'avatar' => ['$type' => 'blob', 'ref' => Cid::forRaw('a'), 'mimeType' => 'application/pdf', 'size' => 10],
		]);
	}

	public function testAnUnknownTypeIsRefused(): void {
		$this->expectException(LexiconException::class);
		$this->lexicon->validateRecord(['$type' => 'com.example.nothing']);
	}

	public function testAFirehoseCommitFrameFits(): void {
		$this->lexicon->validate([
			'seq' => 1, 'rebase' => false, 'tooBig' => false, 'repo' => 'did:plc:ewvi7nxzyoun6zhxrhs64oiz',
			'commit' => Cid::forDagCbor('c'), 'rev' => '3kznmn7xqxl22', 'since' => null,
			'blocks' => new \OCA\Social\Atproto\Protocol\Bytes('car'), 'ops' => [['action' => 'create', 'path' => 'app.bsky.feed.post/3kznmn7xqxl22', 'cid' => Cid::forDagCbor('r')]],
			'blobs' => [], 'time' => '2026-10-08T10:00:00.000Z',
		], 'com.atproto.sync.subscribeRepos#commit');
		$this->addToAssertionCount(1);
	}
}
