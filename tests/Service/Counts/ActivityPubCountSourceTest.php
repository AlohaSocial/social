<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Counts;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\Counts\ActivityPubCountSource;
use OCA\Social\Service\Counts\CountWriter;
use OCA\Social\Service\CurlService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/** The counts of a fediverse post, as the document its server serves states them. */
#[AllowMockObjectsWithoutExpectations]
class ActivityPubCountSourceTest extends TestCase {
	private function note(string $id, bool $local = false): Note {
		$note = new Note();
		$note->setId($id);
		$note->setLocal($local);

		return $note;
	}

	public function testOnlyARemoteFediversePostIsAskedAbout(): void {
		$source = new ActivityPubCountSource($this->createStub(CurlService::class), $this->createStub(CountWriter::class));

		$this->assertTrue($source->supports($this->note('https://remote.example/notes/1')));
		$this->assertFalse($source->supports($this->note('https://here.example/notes/1', true)), 'this server counts its own');
		$this->assertFalse($source->supports($this->note('https://bsky.app/profile/did:plc:a/post/1')), 'Bluesky is asked through the AppView');
		$this->assertFalse($source->supports($this->note('https://bsky.app/profile/did:plc:a/convo/c/m')));
		$this->assertFalse($source->supports($this->note('urn:x:1')));
	}

	public function testTheStatedTotalsAreWrittenAndAnAnswerAboutSomethingElseOnlyStamped(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->expects($this->once())->method('retrieveObjectsMany')
			->with(['https://remote.example/notes/1', 'https://remote.example/notes/2', 'https://remote.example/notes/3'])
			->willReturn([
				'https://remote.example/notes/1' => [
					'id' => 'https://remote.example/notes/1',
					'likes' => ['totalItems' => 10],
					'shares' => ['totalItems' => 4],
				],
				'https://remote.example/notes/2' => ['id' => 'https://remote.example/@x/2'],
				'https://remote.example/notes/3' => null,
			]);
		$writer = $this->createMock(CountWriter::class);
		$writer->expects($this->once())->method('write')->with('https://remote.example/notes/1', 10, 4, null)->willReturn(true);
		$stamped = [];
		$writer->method('stamp')->willReturnCallback(function (string $id) use (&$stamped): void {
			$stamped[] = $id;
		});

		$answered = (new ActivityPubCountSource($curl, $writer))->refresh([
			$this->note('https://remote.example/notes/1'),
			$this->note('https://remote.example/notes/2'),
			$this->note('https://remote.example/notes/3'),
		]);

		$this->assertSame(1, $answered);
		$this->assertSame(['https://remote.example/notes/2', 'https://remote.example/notes/3'], $stamped);
	}

	public function testDocumentsAreAskedForInRounds(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->expects($this->exactly(2))->method('retrieveObjectsMany')->willReturn([]);
		$posts = [];
		for ($i = 0; $i < ActivityPubCountSource::PARALLEL + 1; $i++) {
			$posts[] = $this->note('https://remote.example/notes/' . $i);
		}

		$this->assertSame(0, (new ActivityPubCountSource($curl, $this->createStub(CountWriter::class)))->refresh($posts));
	}
}
