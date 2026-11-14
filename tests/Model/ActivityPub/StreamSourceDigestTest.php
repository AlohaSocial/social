<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use PHPUnit\Framework\TestCase;

/**
 * `social_stream.source_digest`: what a read takes out of the stored wire
 * object beside the columns, so that a timeline row is read without
 * decoding — or even selecting — `source`.
 *
 * The digest is only worth having if a row read through it is the row read
 * through `source`: the same emoji, and the same counts, including the reply
 * total the origin states, which a read lays over the counter column.
 */
class StreamSourceDigestTest extends TestCase {
	private function wire(): array {
		return [
			'id' => 'https://remote.example/users/bob/statuses/1',
			'type' => 'Note',
			'tag' => [
				['type' => 'Emoji', 'name' => ':blob:', 'icon' => ['url' => 'https://remote.example/emoji/blob.png']],
				['type' => 'Emoji', 'name' => ':evil:', 'icon' => ['url' => 'javascript:alert(1)']],
				['type' => 'Hashtag', 'href' => 'https://remote.example/tags/x', 'name' => '#x'],
			],
			'likes' => ['totalItems' => 12],
			'shares' => ['totalItems' => '3'],
			'replies' => ['type' => 'Collection', 'totalItems' => 7],
		];
	}

	/** A row stored after the five post columns existed, without its wire object's digest. */
	private function row(array $overrides = []): array {
		return $overrides + [
			'nid' => '100',
			'id' => 'https://remote.example/users/bob/statuses/1',
			'type' => 'Note',
			'tags' => '[]',
			'details' => json_encode(['replies' => 2]),
			'count_replies' => 9,
			'source' => json_encode($this->wire()),
		];
	}

	private function read(array $row): Note {
		$note = new Note();
		$note->importFromDatabase($row);

		return $note;
	}

	public function testARowReadThroughItsDigestIsTheRowReadThroughItsSource(): void {
		$fromSource = $this->read($this->row());
		$digest = Stream::sourceDigestOf(json_encode($this->wire()));
		$fromDigest = $this->read($this->row(['source' => '', 'source_digest' => $digest]));

		$this->assertSame($fromSource->getEmojis(), $fromDigest->getEmojis());
		$this->assertSame($fromSource->getDetailsAll(), $fromDigest->getDetailsAll());

		$this->assertSame(['blob'], array_column($fromDigest->getEmojis(), 'shortcode'), 'the guard on the emoji URL holds');
		// the origin's reply total wins over the counter column, read either way
		$this->assertSame(7, $fromDigest->getDetailInt(Details::REPLIES));
		$this->assertSame(12, $fromDigest->getDetailInt(Details::REMOTE_LIKES));
		$this->assertSame(3, $fromDigest->getDetailInt(Details::REMOTE_BOOSTS));
	}

	public function testTheDigestIsWhatIsReadWhereBothAreThere(): void {
		$other = $this->wire();
		$other['tag'] = [['type' => 'Emoji', 'name' => ':other:', 'icon' => ['url' => 'https://remote.example/o.png']]];

		$note = $this->read($this->row(['source_digest' => Stream::sourceDigestOf(json_encode($other))]));

		$this->assertSame(['other'], array_column($note->getEmojis(), 'shortcode'));
	}

	public function testAnEmptyOrUnreadableSourceDigestsToNothing(): void {
		$this->assertSame('{}', Stream::sourceDigestOf(''));
		$this->assertSame('{}', Stream::sourceDigestOf('not json'));
		$this->assertSame('{}', Stream::sourceDigestOf('"a string"'));

		$note = $this->read($this->row(['source' => '', 'source_digest' => '{}']));
		$this->assertSame([], $note->getEmojis());
		$this->assertSame(9, $note->getDetailInt(Details::REPLIES), 'nothing stated, so the counter column stands');
	}

	public function testARowWithoutADigestStillFallsBackToItsSourceForThePostColumns(): void {
		$wire = $this->wire() + ['language' => 'de'];
		$note = $this->read($this->row(['source' => json_encode($wire)]));

		$this->assertSame('de', $note->getLanguage());
	}

	public function testAPollIsStillReadOutOfItsSource(): void {
		$wire = [
			'id' => 'https://remote.example/users/bob/statuses/2',
			'type' => 'Question',
			'oneOf' => [
				['type' => 'Note', 'name' => 'yes', 'replies' => ['type' => 'Collection', 'totalItems' => 2]],
				['type' => 'Note', 'name' => 'no', 'replies' => ['type' => 'Collection', 'totalItems' => 1]],
			],
		];
		$poll = new Question();
		$poll->importFromDatabase([
			'nid' => '101',
			'id' => $wire['id'],
			'type' => 'Question',
			'source' => json_encode($wire),
			'source_digest' => Stream::sourceDigestOf(json_encode($wire)),
		]);

		$this->assertSame(['yes', 'no'], array_column($poll->getOptions(), 'title'));
		$this->assertContains(Question::TYPE, Stream::TYPES_READING_SOURCE, 'a narrow read keeps the source of a poll');
	}
}
