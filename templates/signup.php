<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// the shared framework first, then the entry that expects it
\OCP\Util::addScript('social', 'social-framework');
\OCP\Util::addScript('social', 'social-signup');
?>

<div id="social-signup"></div>
