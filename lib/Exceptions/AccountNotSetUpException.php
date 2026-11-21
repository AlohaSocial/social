<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use OCP\IURLGenerator;
use OCP\Server;
use Throwable;

/**
 * A signed-in Nextcloud user who has not set up their account in this app
 * yet, with the address of the page where they do (CodedRefusal). A
 * `ClientNotFoundException`, so routes hand it to `error()`, which answers
 * 403 with `toArray()` rather than a 401 a client would read as signed out.
 */
class AccountNotSetUpException extends ClientNotFoundException {
	use CodedRefusal;

	public const CODE = 'account-not-set-up';

	public static function withSetupUrl(?Throwable $previous = null): self {
		return new self(
			'Your account is not set up yet. Open Aloha Social in a web browser and choose your username, then try again.',
			self::CODE,
			$previous,
			['setup_url' => Server::get(IURLGenerator::class)->linkToRouteAbsolute('social.Navigation.navigate')],
		);
	}
}
