<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * A post body or a cached bio stored sanitized under the current stamp is
 * handed out as it is; anything else goes through the sanitizer on read.
 *
 * The markup below is what the sanitizer would strip, so whether it survives
 * a read says whether the read sanitized.
 */
class StoredSanitizedTextTest extends TestCase {
	private const ID = 'https://remote.example/users/alice/statuses/1';
	private const ACTOR = 'https://remote.example/users/alice';
	private const MARKED = '<p onclick="x()">hello</p>';

	public function testAStampedPostBodyIsNotSanitizedAgain(): void {
		$note = new Note();
		$note->importFromDatabase(['id' => self::ID, 'type' => 'Note', 'content' => self::MARKED, 'sanitized' => (string)HtmlSanitizer::version()]);

		$this->assertSame(self::MARKED, $note->getContent());
	}

	/** @return list<array{0: array<string, mixed>}> */
	public static function unstamped(): array {
		return [
			[[]],
			[['sanitized' => 0]],
			[['sanitized' => HtmlSanitizer::version() + 2]],
			// the same revision under the other parser
			[['sanitized' => HtmlSanitizer::version() ^ 1]],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('unstamped')]
	public function testAnyOtherPostBodyIsSanitizedOnRead(array $stamp): void {
		$note = new Note();
		$note->importFromDatabase(array_merge(['id' => self::ID, 'type' => 'Note', 'content' => self::MARKED], $stamp));

		$this->assertSame('<p>hello</p>', $note->getContent());
	}

	public function testAStampedCachedBioIsHandedOutAsStored(): void {
		$person = new Person();
		$person->importFromDatabase([
			'id' => self::ACTOR, 'type' => 'Person', 'summary' => '<p>raw</p><script>x</script>',
			'description' => self::MARKED, 'sanitized' => HtmlSanitizer::version(),
		]);

		$this->assertSame(self::MARKED, $person->getDescription());
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('unstamped')]
	public function testAnyOtherBioIsSanitizedFromTheSummary(array $stamp): void {
		$person = new Person();
		$person->importFromDatabase(array_merge(
			['id' => self::ACTOR, 'type' => 'Person', 'summary' => self::MARKED, 'description' => 'stale'],
			$stamp
		));

		$this->assertSame('<p>hello</p>', $person->getDescription());
	}

	/** A row of a table without the column, such as a local account's. */
	public function testAStampWithoutADescriptionFallsBackToTheSummary(): void {
		$person = new Person();
		$person->importFromDatabase(['id' => self::ACTOR, 'type' => 'Person', 'summary' => self::MARKED, 'sanitized' => HtmlSanitizer::version()]);

		$this->assertSame('<p>hello</p>', $person->getDescription());
	}
}
