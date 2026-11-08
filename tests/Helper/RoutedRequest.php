<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use OCP\IRequest;

/**
 * A request to stub that carries the router's parameters, which the real
 * request exposes as the magic `urlParams` property and an `IRequest` stub
 * cannot.
 */
abstract class RoutedRequest implements IRequest {
	/** @var array<string, mixed> */
	public array $urlParams = [];
}
