<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\StreamDestRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A post names the same account more than once as a matter of course:
 * `Item::getToAll()` returns `to` alongside `toArray`, the author is appended
 * to the `to` side, and an account addressed in both `to` and `cc` appears in
 * each. The unique index `sat` is on (stream_id, actor_id, type) *without* the
 * subtype, so every one of those is the same row.
 *
 * They used to be sent to the database one at a time and refused there.
 * `insertIgnoreConflict()` keeps a refusal from failing the surrounding
 * transaction, but InnoDB still allocates the auto-increment value before it
 * notices the conflict: on devel `oc_social_stream_dest` had reached 882,837
 * ids for 4,976 live rows. Naming each recipient once is what stops that, so
 * these tests pin the de-duplication rather than the insert.
 */
class StreamDestRecipientsTest extends TestCase {
	/**
	 * @param array<string, string[]> $recipients
	 *
	 * @return array<string, string>
	 */
	private function unique(array $recipients): array {
		$method = new ReflectionMethod(StreamDestRequest::class, 'uniqueRecipients');

		/** @var array<string, string> $result */
		$result = $method->invoke(null, $recipients);

		return $result;
	}

	public function testAnAccountNamedTwiceInOneSubtypeIsOneRecipient(): void {
		// what getToAll() hands over: `toArray` and then `to`, which is one of them
		$recipients = $this->unique(
			['to' => ['https://example.net/users/ada', 'https://example.net/users/ada']]
		);

		$this->assertSame(['https://example.net/users/ada' => 'to'], $recipients);
	}

	public function testAnAccountInBothToAndCcStaysAToRecipient(): void {
		// the row the database kept when both were offered: `to` is sent first
		$recipients = $this->unique(
			[
				'to' => ['https://example.net/users/ada'],
				'cc' => ['https://example.net/users/ada', 'https://example.net/users/grace'],
			]
		);

		$this->assertSame(
			[
				'https://example.net/users/ada' => 'to',
				'https://example.net/users/grace' => 'cc',
			],
			$recipients
		);
	}

	public function testTheEmptyRecipientIsNotOne(): void {
		$recipients = $this->unique(['to' => ['', 'https://example.net/users/ada', '']]);

		$this->assertSame(['https://example.net/users/ada' => 'to'], $recipients);
	}

	public function testTheOrderThePostNamedThemIsKept(): void {
		$recipients = $this->unique(
			[
				'to' => ['https://example.net/users/ada', 'https://example.net/users/grace'],
				'cc' => ['https://example.net/users/alan'],
			]
		);

		$this->assertSame(
			['https://example.net/users/ada', 'https://example.net/users/grace', 'https://example.net/users/alan'],
			array_keys($recipients)
		);
	}

	/**
	 * A direct message and a notification address one list under one type, so
	 * de-duplication has to hold for a single-subtype call as well — that is
	 * the call `generateStreamDirect()` and `generateStreamNotification()` make.
	 */
	public function testASingleSubtypeListIsDeduplicated(): void {
		$recipients = $this->unique(
			[
				'dm' => [
					'https://example.net/users/ada',
					'https://example.net/users/grace',
					'https://example.net/users/ada',
				],
			]
		);

		$this->assertSame(
			['https://example.net/users/ada' => 'dm', 'https://example.net/users/grace' => 'dm'],
			$recipients
		);
	}

	/**
	 * The three generators must all go through it: a recipient list that
	 * reaches `createRecipients()` unfiltered is the churn coming back.
	 */
	public function testEveryGeneratorNamesRecipientsThroughTheFilter(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../lib/Db/StreamDestRequest.php');

		foreach (['generateStreamHome', 'generateStreamDirect', 'generateStreamNotification'] as $generator) {
			$start = strpos($source, 'private function ' . $generator . '(');
			$this->assertNotFalse($start, $generator . ' is gone');

			$next = strpos($source, "\n\tprivate function ", $start + 1);
			$body = substr($source, $start, $next === false ? null : $next - $start);

			$this->assertStringContainsString(
				'self::uniqueRecipients(',
				$body,
				$generator . ' names recipients without de-duplicating them first'
			);
		}
	}
}
