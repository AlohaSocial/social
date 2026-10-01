<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\SetupChecks;

use OCA\Social\Service\ConfigService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Whether PHP will accept the uploads this app promises to accept.
 *
 * Social tells every client a `max_size` — it is in `/api/v1/instance`, in
 * Pixelfed's `/api/v2/config`, and in the composer's own refusal message — and
 * PHP enforces `upload_max_filesize` and `post_max_size` regardless. When the
 * app's number is the larger one, a phone photograph of four megabytes is
 * offered, accepted by the client, and refused by the server with nothing in
 * the log: the request never reaches PHP's code at all, so this app cannot
 * even say what happened.
 *
 * It is the single most common "it does not work" an admin of a photo server
 * meets, it is invisible from inside the app, and it is two lines of `php.ini`
 * to fix. Nothing here changes anything: an admin might genuinely want a low
 * ceiling, in which case the app's own number is what should come down.
 */
class UploadLimitsAgree implements ISetupCheck {
	public const DOC = Docs::ADMIN_GUIDE . '#the-setup-checks';

	public function __construct(
		private IL10N $l10n,
		private ConfigService $configService,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		return 'config';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social: upload size');
	}

	#[\Override]
	public function run(): SetupResult {
		$promised = $this->configService->getAppValueInt(ConfigService::SOCIAL_MAX_SIZE) * 1024 * 1024;
		if ($promised <= 0) {
			return SetupResult::success($this->l10n->t('Aloha Social has no upload ceiling of its own.'));
		}

		$upload = $this->bytes((string)ini_get('upload_max_filesize'));
		$post = $this->bytes((string)ini_get('post_max_size'));

		// 0 means "no limit" for post_max_size and is not a failure
		$limits = array_filter([$upload, $post], static fn (int $value): bool => $value > 0);
		if ($limits === []) {
			return SetupResult::success(
				$this->l10n->t('PHP sets no upload limit, so Aloha Social\'s own ceiling of %1$s is what applies.', [$this->human($promised)])
			);
		}

		$lowest = min($limits);
		if ($lowest >= $promised) {
			return SetupResult::success(
				$this->l10n->t('Aloha Social accepts uploads up to %1$s, and PHP allows at least that much.', [$this->human($promised)])
			);
		}

		return SetupResult::warning(
			$this->l10n->t(
				'Aloha Social offers its users uploads up to %1$s, but PHP refuses anything over %2$s (upload_max_filesize %3$s, post_max_size %4$s). An upload between the two is refused before this app can say why, and the person is told nothing useful. Raise both PHP values, or lower Aloha Social\'s own limit in Administration → Aloha Social → Server.',
				[
					$this->human($promised), $this->human($lowest),
					(string)ini_get('upload_max_filesize'), (string)ini_get('post_max_size'),
				]
			),
			self::DOC
		);
	}

	/** `8M`, `512K`, `1G` as PHP writes them, in bytes. */
	private function bytes(string $value): int {
		$value = trim($value);
		if ($value === '') {
			return 0;
		}

		$number = (int)$value;

		return match (strtolower(substr($value, -1))) {
			'g' => $number * 1024 * 1024 * 1024,
			'm' => $number * 1024 * 1024,
			'k' => $number * 1024,
			default => $number,
		};
	}

	private function human(int $bytes): string {
		// the division is written in floats and the result cast back: `round()`
		// gives 2.0, and "2 MB" rather than "2.0 MB" is what somebody reading a
		// setup check wants. Mixing an int and a float in one expression is
		// what strict operand mode refuses, so neither side is left implicit.
		$mb = 1024.0 * 1024.0;

		return ($bytes >= 1024 * 1024)
			? (int)round((float)$bytes / $mb) . ' MB'
			: (int)round((float)$bytes / 1024.0) . ' KB';
	}
}
