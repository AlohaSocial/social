<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use OCP\ICache;

/**
 * Stands in for `OC\Memcache\ArrayCache`, the server's in-process cache.
 *
 * The server hands it out for every `memcache.*` setting while PHPUnit runs
 * and before it is installed, and `DurableCache` has to tell it apart from the
 * configured cache. It lives in the server's private namespace, which the
 * `nextcloud/ocp` stubs do not carry, so it is aliased in `tests/bootstrap.php`
 * and mocked where a test needs one.
 */
abstract class ArrayCache implements ICache {
}
