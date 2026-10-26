<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Details;
use OCA\Social\Tools\ActivityPubEvent;
use PHPUnit\Framework\TestCase;

class ActivityPubEventTest extends TestCase {
	public function testDateAndPlaceMetadataIsValidatedAndMadePlainText(): void {
		$metadata = ActivityPubEvent::metadata([
			'name' => "<b>Meetup</b>\nGroup",
			'startTime' => '2026-04-05T18:30:00+02:00',
			'endTime' => '2026-04-05T17:30:00+02:00',
			'location' => [
				'name' => '',
				'address' => [
					'streetAddress' => 'Main Street 12',
					'addressLocality' => 'Berlin',
					'addressCountry' => 'DE',
				],
			],
		]);

		self::assertSame('Meetup Group', $metadata['name']);
		self::assertSame('2026-04-05T16:30:00Z', $metadata['start_time']);
		self::assertNull($metadata['end_time'], 'an end before the start is discarded');
		self::assertSame('Main Street 12, Berlin, DE', $metadata['location']);
	}

	public function testImpossibleCalendarDatesAreRejectedInsteadOfNormalized(): void {
		$metadata = ActivityPubEvent::metadata([
			'name' => 'Meetup',
			'startTime' => '2026-02-31T18:30:00Z',
			'endTime' => '2026-04-31T20:00:00Z',
		]);

		self::assertNull($metadata['start_time'], 'an impossible start date must not silently move into March');
		self::assertNull($metadata['end_time'], 'an impossible end date must not silently move into May');
	}

	public function testPlainEventTextPreservesEmojiAndScriptJoiners(): void {
		$family = '👨‍👩‍👧‍👦';
		$name = "{$family} Community meetup";
		$location = 'می‌خواهم بیایم';

		$metadata = ActivityPubEvent::metadata([
			'name' => $name,
			'location' => $location,
			'startTime' => '2026-04-05T18:30:00Z',
		]);

		self::assertSame($name, $metadata['name'], 'the zero-width joiners that compose the family emoji remain intact');
		self::assertSame($location, $metadata['location'], 'the Persian zero-width non-joiner remains intact');
	}

	public function testCalendarEscapesTextUsesUtcAndFoldsLongLines(): void {
		$event = (new Note())
			->setId('https://mobilizon.example/events/9f')
			->setUrl('https://mobilizon.example/events/9f')
			->setContent("<p>Talk; about PHP, security\\\nToday</p>")
			->setSubType('Event');
		$event->setDetailArray(Details::EVENT, [
			'name' => 'A very long meetup title about ActivityPub events and interoperable calendar publishing across different Fediverse servers',
			'start_time' => '2026-04-05T16:30:00Z',
			'end_time' => '2026-04-05T19:00:00Z',
			'location' => 'Community hall, Berlin',
		]);

		$ics = ActivityPubEvent::calendar($event, 1_800_000_000);

		self::assertIsString($ics);
		self::assertStringContainsString('DTSTART:20260405T163000Z', $ics);
		self::assertStringContainsString('DTEND:20260405T190000Z', $ics);
		self::assertStringContainsString('LOCATION:Community hall\\, Berlin', $ics);
		self::assertStringContainsString('SUMMARY:A very long meetup', $ics);
		self::assertStringContainsString("\r\n ", $ics, 'long content lines are folded');
		self::assertStringContainsString('DESCRIPTION:Talk\\; about PHP\\, security\\\\ Today', $ics);
		self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
		foreach (explode("\r\n", trim($ics)) as $line) {
			self::assertLessThanOrEqual(75, strlen($line), 'iCalendar lines are at most 75 octets');
		}
	}

	public function testStoredEventWithEndBeforeStartDoesNotExportAnInvalidEnd(): void {
		$event = (new Note())
			->setId('https://mobilizon.example/events/invalid-range')
			->setSubType('Event');
		$event->setDetailArray(Details::EVENT, [
			'name' => 'Town hall',
			'start_time' => '2026-04-05T18:30:00Z',
			'end_time' => '2026-04-05T17:30:00Z',
			'location' => '',
		]);

		$metadata = ActivityPubEvent::fromStream($event);
		$calendar = ActivityPubEvent::calendar($event, 1_800_000_000);

		self::assertNotNull($metadata);
		self::assertNull($metadata['end_time']);
		self::assertIsString($calendar);
		self::assertStringNotContainsString('DTEND:', $calendar);
	}

	public function testCalendarIsUnavailableForNotesAndUndatedEvents(): void {
		$note = (new Note())->setId('https://example.org/notes/1');
		self::assertNull(ActivityPubEvent::calendar($note, 1_800_000_000));

		$event = (new Note())->setId('https://example.org/events/1')->setSubType('Event');
		self::assertNull(ActivityPubEvent::calendar($event, 1_800_000_000));
	}
	public function testCancellationAndUpdateSurviveCalendarExport(): void {
		$event = (new Note())->setId('https://events.example/cancelled')->setSubType('Event');
		$event->setUpdated('2026-10-26T09:00:00Z');
		$event->setDetailArray(Details::EVENT, ActivityPubEvent::metadata([
			'name' => 'Cancelled meetup',
			'startTime' => '2026-11-01T12:00:00Z',
			'ical:status' => 'CANCELLED',
		]));
		self::assertSame('cancelled', ActivityPubEvent::fromStream($event)['status']);
		$calendar = ActivityPubEvent::calendar($event, 1_800_000_000);
		self::assertStringContainsString('STATUS:CANCELLED', $calendar);
		self::assertStringContainsString('LAST-MODIFIED:20261026T090000Z', $calendar);
	}

	public function testUnknownEventStatusIsDiscarded(): void {
		foreach (['unknown', ['CANCELLED'], "CONFIRMED\r\nATTENDEE:bad"] as $status) {
			self::assertArrayNotHasKey('status', ActivityPubEvent::metadata(['ical:status' => $status]));
		}
		self::assertSame('cancelled', ActivityPubEvent::metadata(['eventStatus' => 'https://schema.org/EventCancelled'])['status']);
	}

}
