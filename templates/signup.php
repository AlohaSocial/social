<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// the shared framework first, then the entry that expects it
\OCP\Util::addScript('social', 'social-framework');
\OCP\Util::addScript('social', 'social-signup');
// as on core's login page: without it the server-wide `no-referrer` policy
// makes the browser send `Origin: null` with submitCoreLogin()'s POST, and
// core's login refuses a login it cannot tell came from this origin
\OCP\Util::addHeader('meta', ['name' => 'referrer', 'content' => 'same-origin']);
?>

<div id="social-signup"></div>
