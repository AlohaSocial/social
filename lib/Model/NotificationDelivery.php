<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use OCA\Social\Exceptions\InvalidResourceException;

/**
 * When a Nextcloud user is told about what happened to their Social account.
 *
 * `instant` is what every account had before this existed: a bell entry the
 * moment something happens. `digest` holds the bell back and rings it once at
 * each of up to four local times a day with a count of what came in. The
 * in-app notification rows are untouched either way — they are the hold, and
 * `/api/v1/notifications` reads them as it always did.
 *
 * Two kinds of news may skip the hold, each on its own switch: a direct
 * message, and a mention from an account the reader follows. Quiet hours are
 * the third knob and apply in both modes: inside the window nothing but a
 * pass-through is raised, and a digest is raised when the window ends.
 */
class NotificationDelivery implements JsonSerializable {
	public const MODE_INSTANT = 'instant';
	public const MODE_DIGEST = 'digest';
	public const MODES = [self::MODE_INSTANT, self::MODE_DIGEST];

	/**
	 * The subject a direct message is held or passed under. It is not one of
	 * `NotificationService::SUBJECTS`: a DM reaches the bell as a `mention`
	 * whose post is addressed to the reader alone, and the caller names it
	 * this so the two switches can tell the two cases apart.
	 */
	public const SUBJECT_DIRECT = 'direct';
	public const SUBJECT_MENTION = 'mention';

	public const DEFAULT_TIMES = ['08:00', '18:00'];
	public const MAX_TIMES = 4;

	public const KEY_MODE = 'mode';
	public const KEY_TIMES = 'times';
	public const KEY_PASSTHROUGH = 'passthrough';
	public const KEY_QUIET = 'quiet';
	public const KEYS = [self::KEY_MODE, self::KEY_TIMES, self::KEY_PASSTHROUGH, self::KEY_QUIET];

	public const PASS_DIRECT = 'direct';
	public const PASS_MENTIONS_FROM_FOLLOWED = 'mentions_from_followed';

	private string $mode = self::MODE_INSTANT;
	/** @var string[] `HH:MM`, sorted */
	private array $times = self::DEFAULT_TIMES;
	private bool $passDirect = true;
	private bool $passMentionsFromFollowed = true;
	private string $quietFrom = '';
	private string $quietTo = '';

	/**
	 * The setting as it was stored. A key the stored copy lacks keeps its
	 * default, and a key that no longer validates — a hand-edited value, or
	 * one written by an older version — is reset rather than refused, because
	 * nobody is there to answer a 422 to.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self {
		$delivery = new self();
		foreach (self::KEYS as $key) {
			if (!array_key_exists($key, $data)) {
				continue;
			}
			try {
				$delivery->apply([$key => $data[$key]]);
			} catch (InvalidResourceException) {
				// the default for this key stands
			}
		}

		return $delivery;
	}

	/**
	 * Changes what was named and leaves the rest as it is. Refuses the whole
	 * change rather than half of it: nothing is written before every key has
	 * been checked.
	 *
	 * @param array<string, mixed> $changes
	 *
	 * @throws InvalidResourceException what a 422 says
	 */
	public function apply(array $changes): self {
		$mode = $this->mode;
		$times = $this->times;
		$passDirect = $this->passDirect;
		$passMentions = $this->passMentionsFromFollowed;
		$quietFrom = $this->quietFrom;
		$quietTo = $this->quietTo;

		if (array_key_exists(self::KEY_MODE, $changes)) {
			$mode = $changes[self::KEY_MODE];
			if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
				throw new InvalidResourceException('mode must be "instant" or "digest"');
			}
		}

		if (array_key_exists(self::KEY_TIMES, $changes)) {
			$times = self::validTimes($changes[self::KEY_TIMES]);
		}

		if (array_key_exists(self::KEY_PASSTHROUGH, $changes)) {
			$passthrough = $changes[self::KEY_PASSTHROUGH];
			if (!is_array($passthrough)) {
				throw new InvalidResourceException('passthrough must be an object');
			}
			foreach ([self::PASS_DIRECT, self::PASS_MENTIONS_FROM_FOLLOWED] as $switch) {
				if (!array_key_exists($switch, $passthrough)) {
					continue;
				}
				$value = self::bool($passthrough[$switch]);
				if ($value === null) {
					throw new InvalidResourceException('passthrough.' . $switch . ' must be a boolean');
				}
				if ($switch === self::PASS_DIRECT) {
					$passDirect = $value;
				} else {
					$passMentions = $value;
				}
			}
		}

		if (array_key_exists(self::KEY_QUIET, $changes)) {
			[$quietFrom, $quietTo] = self::validQuiet($changes[self::KEY_QUIET]);
		}

		$this->mode = $mode;
		$this->times = $times;
		$this->passDirect = $passDirect;
		$this->passMentionsFromFollowed = $passMentions;
		$this->quietFrom = $quietFrom;
		$this->quietTo = $quietTo;

		return $this;
	}

	/** @return array{mode: string, times: string[], passthrough: array{direct: bool, mentions_from_followed: bool}, quiet: array{from: string, to: string}} */
	public function toArray(): array {
		return [
			self::KEY_MODE => $this->mode,
			self::KEY_TIMES => $this->times,
			self::KEY_PASSTHROUGH => [
				self::PASS_DIRECT => $this->passDirect,
				self::PASS_MENTIONS_FROM_FOLLOWED => $this->passMentionsFromFollowed,
			],
			self::KEY_QUIET => [
				'from' => $this->quietFrom,
				'to' => $this->quietTo,
			],
		];
	}

	#[\Override]
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	public function getMode(): string {
		return $this->mode;
	}

	/** @return string[] */
	public function getTimes(): array {
		return $this->times;
	}

	public function isDigest(): bool {
		return $this->mode === self::MODE_DIGEST;
	}

	public function hasQuietHours(): bool {
		return $this->quietFrom !== '';
	}

	/**
	 * Whether anything about this setting needs the digest job: a digest mode,
	 * or quiet hours that end in one.
	 */
	public function isScheduled(): bool {
		return $this->isDigest() || $this->hasQuietHours();
	}

	/**
	 * Whether news of this kind skips a hold.
	 *
	 * @param string $subject `SUBJECT_DIRECT` for a direct message, else the
	 *                        subject `NotificationService` raises
	 * @param bool $actorFollowed whether the reader follows whoever acted
	 */
	public function passesThrough(string $subject, bool $actorFollowed): bool {
		if ($subject === self::SUBJECT_DIRECT) {
			return $this->passDirect;
		}
		if ($subject === self::SUBJECT_MENTION) {
			return $this->passMentionsFromFollowed && $actorFollowed;
		}

		return false;
	}

	/** Whether a moment falls inside the quiet window, in the reader's zone. */
	public function isQuietAt(DateTimeImmutable $at, DateTimeZone $zone): bool {
		if (!$this->hasQuietHours()) {
			return false;
		}

		$local = $at->setTimezone($zone);
		$minute = ((int)$local->format('G')) * 60 + (int)$local->format('i');

		return self::inWindow($minute, self::minutes($this->quietFrom), self::minutes($this->quietTo));
	}

	/**
	 * The one decision the bell asks: is this notification held for a digest,
	 * or raised now?
	 *
	 * Held when the mode is digest or the moment is inside the quiet window,
	 * unless the news is of a kind the reader lets through. Instant mode
	 * outside quiet hours holds nothing, whatever the switches say.
	 */
	public function holds(string $subject, bool $actorFollowed, DateTimeImmutable $now, DateTimeZone $zone): bool {
		if (!$this->isDigest() && !$this->isQuietAt($now, $zone)) {
			return false;
		}

		return !$this->passesThrough($subject, $actorFollowed);
	}

	/**
	 * The next moment a digest is due, strictly after `$after`, in the
	 * reader's zone: the earliest of the digest times (in digest mode, and
	 * leaving out any that fall inside the quiet window — nothing is raised
	 * in there) and the end of the quiet window (whenever there is one).
	 *
	 * Each candidate is built on the local date of `$after` and on the day
	 * after it, so a time earlier in the day than `$after` rolls over to
	 * tomorrow and a clock change in between is the zone's business: the
	 * candidate is "08:00 local on that date", however many hours away that
	 * is. Null when nothing is scheduled.
	 */
	public function nextDigestAfter(DateTimeImmutable $after, DateTimeZone $zone): ?DateTimeImmutable {
		$candidates = [];
		if ($this->isDigest()) {
			foreach ($this->times as $time) {
				if ($this->hasQuietHours() && self::inWindow(
					self::minutes($time), self::minutes($this->quietFrom), self::minutes($this->quietTo)
				)) {
					continue;
				}
				$candidates[] = $time;
			}
		}
		if ($this->hasQuietHours()) {
			$candidates[] = $this->quietTo;
		}
		if ($candidates === []) {
			return null;
		}

		$local = $after->setTimezone($zone);
		$next = null;
		foreach ($candidates as $time) {
			[$hour, $minute] = array_map('intval', explode(':', $time));
			foreach ([0, 1] as $days) {
				$candidate = $local->modify('+' . $days . ' day')->setTime($hour, $minute);
				if ($candidate <= $after) {
					continue;
				}
				if ($next === null || $candidate < $next) {
					$next = $candidate;
				}
			}
		}

		return $next;
	}

	/**
	 * @param mixed $value
	 *
	 * @return string[] distinct, sorted
	 *
	 * @throws InvalidResourceException
	 */
	private static function validTimes(mixed $value): array {
		if (!is_array($value) || $value === [] || count($value) > self::MAX_TIMES) {
			throw new InvalidResourceException('times must list between 1 and ' . self::MAX_TIMES . ' times');
		}

		$times = [];
		foreach ($value as $time) {
			if (!is_string($time) || !self::isTime($time)) {
				throw new InvalidResourceException('times must be HH:MM, 24-hour and zero-padded');
			}
			if (in_array($time, $times, true)) {
				throw new InvalidResourceException('times must be distinct');
			}
			$times[] = $time;
		}
		sort($times, SORT_STRING);

		return $times;
	}

	/**
	 * @param mixed $value
	 *
	 * @return array{string, string} from and to, both `HH:MM` or both empty
	 *
	 * @throws InvalidResourceException
	 */
	private static function validQuiet(mixed $value): array {
		if (!is_array($value)) {
			throw new InvalidResourceException('quiet must be an object with "from" and "to"');
		}
		$from = $value['from'] ?? '';
		$to = $value['to'] ?? '';
		if (!is_string($from) || !is_string($to)) {
			throw new InvalidResourceException('quiet.from and quiet.to must be strings');
		}
		if ($from === '' && $to === '') {
			return ['', ''];
		}
		if (!self::isTime($from) || !self::isTime($to)) {
			throw new InvalidResourceException('quiet.from and quiet.to must both be HH:MM, or both empty');
		}
		if ($from === $to) {
			throw new InvalidResourceException('quiet hours must start and end at different times');
		}

		return [$from, $to];
	}

	private static function isTime(string $value): bool {
		return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value) === 1;
	}

	/** Minutes since midnight of an `HH:MM`. */
	private static function minutes(string $time): int {
		[$hour, $minute] = array_map('intval', explode(':', $time));

		return $hour * 60 + $minute;
	}

	/**
	 * Whether a minute of the day lies in `[from, to)`, with a window that
	 * crosses midnight read as "from `from` to the end of the day, and from
	 * midnight to `to`".
	 */
	private static function inWindow(int $minute, int $from, int $to): bool {
		if ($from < $to) {
			return $minute >= $from && $minute < $to;
		}

		return $minute >= $from || $minute < $to;
	}

	/** A boolean as JSON or a form sends it; null for anything else. */
	private static function bool(mixed $value): ?bool {
		if (is_bool($value)) {
			return $value;
		}
		if (in_array($value, [1, '1', 'true'], true)) {
			return true;
		}
		if (in_array($value, [0, '0', 'false'], true)) {
			return false;
		}

		return null;
	}
}
