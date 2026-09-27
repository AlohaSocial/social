<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AppInfo;

/**
 * Every route of this app is a `#[FrontpageRoute]` on the controller method it
 * belongs to, except the one below.
 *
 * `/api/v1/accounts/{id}` accepts slashes in `{id}` — a client may hold an
 * actor URI rather than a numeric id — so it also matches
 * `/api/v1/accounts/{account}/featured_tags` and `/api/v1/accounts/{account}/lists`,
 * and has to be offered to the matcher after them. Those two live in
 * `DiscoveryController` and `ListController`, and the server walks the
 * controller directory with a `DirectoryIterator`: the order in which two
 * *different* controllers contribute their attribute routes is whatever the
 * filesystem returns, so no arrangement of attributes can put this one last.
 * `appinfo/routes.php` is loaded after every attribute route of the app, which
 * is exactly the guarantee this route needs, so it stays here.
 *
 * `/@{username}/{token}` is here for the same reason. `{token}` matches any
 * single segment, so it also matches `/@{username}/portfolio` and
 * `/@{username}/collections`, which are pages of `SocialPubController` — and
 * on an install where the directory happened to hand `ActivityPubController`
 * back first, this route was offered to the matcher first and swallowed both.
 * `resolvePost()` then found no post called `portfolio`, and the reader got
 * "Post not found" (#2284). A signed-in reader did not: the 404 branch hands
 * them the app, whose own router then drew the page — so the bug looked like
 * "public visitors cannot see a portfolio", and it appeared or not depending
 * on the order a filesystem returns two files in.
 *
 * `ActivityPubController::displayPost()` also has `/@{username}/{token}/replies`
 * and `/@{username}/{token}/quote_authorizations/{stamp}` on its siblings;
 * those carry more segments and cannot match a one-segment path, so they stay
 * as attributes.
 *
 * Routes that only have to beat routes of their own controller need nothing
 * special: within one class the attributes are read in method-declaration
 * order.
 */
return [
	'routes' => [
		['name' => 'AccountApi#accountGet', 'url' => '/api/v1/accounts/{id}', 'verb' => 'GET', 'requirements' => ['id' => '.+']],
		['name' => 'ActivityPub#displayPost', 'url' => '/@{username}/{token}', 'verb' => 'GET'],
	]
];
