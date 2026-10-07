<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use stdClass;

/**
 * Whether a reader is shown how many likes, dislikes, boosts and followers
 * things have — and, when not, the numbers taken out of what they are sent.
 *
 * Hidden unless the reader turned them back on: a number next to a post is
 * a score, and a score is pressure. The switch is stored the other way round
 * (`show_counts`), so every account starts hidden without a migration.
 *
 * Replies, following and post counts are not hidden: a reply count says
 * there is a conversation, the other two describe the account rather than
 * rank it.
 */
class CountsService {
	/** The per-user switch: `'1'` shows the numbers, anything else hides them. */
	public const USER_KEY = 'show_counts';

	/**
	 * On a status: the numbers that rank it, the reply count among them — a
	 * reader who hides the numbers asked not to be shown how much attention a
	 * post got, and a busy thread is that too. `dislikes_count` is null on
	 * anything but a video.
	 */
	private const STATUS_COUNTS = ['favourites_count', 'reblogs_count', 'replies_count', 'dislikes_count'];

	/** @var array<string, bool> user id => hides, read once per request */
	private array $hides = [];

	public function __construct(
		private ConfigService $configService,
	) {
	}

	/** Whether this reader has the numbers hidden; an anonymous reader does not. */
	public function hides(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		$this->hides[$userId] ??= $this->configService->getUserValue(self::USER_KEY, $userId) !== '1';

		return $this->hides[$userId];
	}

	public function setHides(string $userId, bool $hide): void {
		$this->configService->setValueForUser($userId, self::USER_KEY, $hide ? '0' : '1');
		$this->hides[$userId] = $hide;
	}

	/** The switch as the client API answers it. */
	public function export(string $userId): array {
		return ['hide' => $this->hides($userId)];
	}

	/**
	 * A response body with the numbers taken out, wherever they are: a status
	 * at any depth (a boost's `reblog`, a quote, a notification's `status`),
	 * an account at any depth (a status's author, a follow notification).
	 *
	 * Set to 0 rather than removed: the Mastodon entities declare them, and a
	 * client that decodes into a typed struct fails on a missing field. A
	 * `dislikes_count` of null stays null, which is what says "not a video".
	 *
	 * Takes and answers the decoded form of the body — objects as `stdClass` —
	 * so that an empty object stays `{}` when it is encoded again.
	 */
	public function strip(mixed $data): mixed {
		if (is_array($data)) {
			foreach ($data as $key => $value) {
				$data[$key] = $this->strip($value);
			}

			return $data;
		}
		if (!$data instanceof stdClass) {
			return $data;
		}

		if (property_exists($data, 'favourites_count') && property_exists($data, 'reblogs_count')) {
			foreach (self::STATUS_COUNTS as $field) {
				if (property_exists($data, $field) && $data->{$field} !== null) {
					$data->{$field} = 0;
				}
			}
		}
		if (property_exists($data, 'followers_count') && property_exists($data, 'acct')) {
			$data->followers_count = 0;
		}

		foreach (get_object_vars($data) as $key => $value) {
			if (is_array($value) || $value instanceof stdClass) {
				$data->{$key} = $this->strip($value);
			}
		}

		return $data;
	}
}
