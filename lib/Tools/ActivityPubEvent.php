<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;

/** Validates the event facts ActivityStreams supplies and writes calendar files. */
final class ActivityPubEvent {
	private const MAX_TEXT = 500;

	/** @return array{name: string, start_time: ?string, end_time: ?string, location: string} */
	public static function metadata(array $data): array {
		return self::facts(
			$data['name'] ?? '',
			$data['startTime'] ?? null,
			$data['endTime'] ?? null,
			$data['location'] ?? null,
		);
	}

	/** @return array{name: string, start_time: ?string, end_time: ?string, location: string}|null */
	public static function fromStream(Stream $stream): ?array {
		if ($stream->getSubType() !== 'Event') {
			return null;
		}
		$event = $stream->getDetails(Details::EVENT);

		return self::facts(
			$event['name'] ?? '',
			$event['start_time'] ?? null,
			$event['end_time'] ?? null,
			$event['location'] ?? null,
		);
	}

	public static function calendar(Stream $stream, int $now): ?string {
		$event = self::fromStream($stream);
		if ($event === null || $event['start_time'] === null || $stream->getId() === '') {
			return null;
		}
		$name = $event['name'] !== '' ? $event['name'] : 'Fediverse event';
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Aloha Social//Fediverse Event//EN',
			'CALSCALE:GREGORIAN',
			'BEGIN:VEVENT',
			'UID:' . hash('sha256', $stream->getId()) . '@social',
			'DTSTAMP:' . gmdate('Ymd\THis\Z', $now),
			'DTSTART:' . self::calendarDate($event['start_time']),
		];
		if ($event['end_time'] !== null) {
			$lines[] = 'DTEND:' . self::calendarDate($event['end_time']);
		}
		$lines[] = 'SUMMARY:' . self::escape($name);
		if ($event['location'] !== '') {
			$lines[] = 'LOCATION:' . self::escape($event['location']);
		}
		$description = self::plainText($stream->getContent(), 4000);
		if ($description !== '' && $description !== $name) {
			$lines[] = 'DESCRIPTION:' . self::escape($description);
		}
		$url = $stream->getUrl() !== '' ? $stream->getUrl() : $stream->getId();
		if (preg_match('#^https?://[^\s\r\n]+$#i', $url) === 1) {
			$lines[] = 'URL:' . $url;
		}
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode("\r\n", array_map(self::fold(...), $lines)) . "\r\n";
	}

	private static function date(mixed $value): ?string {
		if (!is_string($value) || strlen($value) > 64
			|| preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
			return null;
		}
		try {
			$date = new DateTimeImmutable($value);
		} catch (\Throwable) {
			return null;
		}
		$errors = DateTimeImmutable::getLastErrors();
		if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
			return null;
		}

		return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}

	/** @return array{name: string, start_time: ?string, end_time: ?string, location: string} */
	private static function facts(mixed $name, mixed $start, mixed $end, mixed $location): array {
		$start = self::date($start);
		$end = self::date($end);
		if ($start !== null && $end !== null && $end <= $start) {
			$end = null;
		}

		return [
			'name' => self::plainText($name, 300),
			'start_time' => $start,
			'end_time' => $end,
			'location' => self::location($location),
		];
	}

	private static function location(mixed $value): string {
		if (is_string($value)) {
			return self::plainText($value, self::MAX_TEXT);
		}
		if (!is_array($value)) {
			return '';
		}
		$name = self::plainText($value['name'] ?? '', self::MAX_TEXT);
		if ($name !== '') {
			return $name;
		}
		$address = $value['address'] ?? null;
		if (is_string($address)) {
			return self::plainText($address, self::MAX_TEXT);
		}
		if (!is_array($address)) {
			return '';
		}
		$parts = [];
		foreach (['streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry'] as $field) {
			$part = self::plainText($address[$field] ?? '', self::MAX_TEXT);
			if ($part !== '' && !in_array($part, $parts, true)) {
				$parts[] = $part;
			}
		}

		return self::plainText(implode(', ', $parts), self::MAX_TEXT);
	}

	private static function plainText(mixed $value, int $limit): string {
		if (!is_string($value)) {
			return '';
		}
		$value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
		// Keep the joiners used by emoji and scripts such as Persian and Hindi;
		// removing every Unicode format character breaks otherwise valid names.
		$value = preg_replace_callback('/[\p{Cc}\p{Cf}]/u', static function (array $match): string {
			return in_array($match[0], ["\u{200C}", "\u{200D}"], true) ? $match[0] : ' ';
		}, $value) ?? '';
		$value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

		return mb_substr($value, 0, $limit, 'UTF-8');
	}

	private static function calendarDate(string $date): string {
		return (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
	}

	private static function escape(string $value): string {
		return str_replace(['\\', ';', ',', "\r\n", "\r", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $value);
	}

	/** Fold at 75 UTF-8 octets without splitting a multi-byte character. */
	private static function fold(string $line): string {
		$characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$lines = [];
		$current = '';
		foreach ($characters as $character) {
			if (strlen($current . $character) > 75) {
				$lines[] = $current;
				$current = ' ';
			}
			$current .= $character;
		}
		$lines[] = $current;

		return implode("\r\n", $lines);
	}
}
