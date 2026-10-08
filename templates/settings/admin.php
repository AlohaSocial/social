<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * The mount point of the administration page, and nothing else.
 *
 * The page is one Vue application (`src/adminSettings.js`), built out of the
 * same components the rest of the administration settings are, and what the
 * server knows when the page is rendered reaches it as initial state
 * — see `OCA\Social\Settings\AdminSettings::getForm()`.
 */
?>
<div id="social-admin-settings"></div>
