<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\SetupChecks;

/**
 * Where a setup check sends the administrator to read more.
 *
 * One place for the address, so that a moved guide is one edit and not four.
 */
final class Docs {
	public const ADMIN_GUIDE = 'https://github.com/alohasocial/social/blob/master/docs/Admin.md';
}
