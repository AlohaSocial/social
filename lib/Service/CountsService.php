<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use stdClass;

/**
 * Whether a reader is shown how many likes, dislikes, boosts and views things
 * have, and stripping the status numbers when not.
 *
 * Hidden unless the reader turned them on; stored inverted (`show_counts`) so
 * every account starts hidden. Follower, following and post counts are sent
 * as they are, since they describe the account; the web app hides other
 * people's follower count itself (`serverData.hideCounts`).
 */
class CountsService {
	/** The per-user switch: `'1'` shows the numbers, anything else hides them. */
	public const USER_KEY = 'show_counts';

	/**
	 * The status numbers that rank it, replies included. `dislikes_count` is null
	 * on anything but a video.
	 */
	private const STATUS_COUNTS = ['favourites_count', 'reblogs_count', 'replies_count', 'dislikes_count', 'quotes_count'];

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
	 * A response body with the status numbers set to 0 at any depth (a boost's
	 * `reblog`, a quote, a notification's `status`); account numbers are left.
	 * Zeroed rather than removed, as typed clients need the declared fields; a
	 * null `dislikes_count` stays null. Works on the decoded body (`stdClass`
	 * objects) so `{}` stays `{}`.
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

			// View counts rank a video too. Removed, not zeroed: the video blocks are
			// this app's own, and a missing number means "not counted".
			if (($data->video_card ?? null) instanceof stdClass) {
				$data->video_card->views = null;
			}
			if (($data->video ?? null) instanceof stdClass) {
				unset($data->video->views);
			}
		}
		foreach (get_object_vars($data) as $key => $value) {
			if (is_array($value) || $value instanceof stdClass) {
				$data->{$key} = $this->strip($value);
			}
		}

		return $data;
	}
}
