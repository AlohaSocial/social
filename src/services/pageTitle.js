/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * The title the server rendered the page with — the app's name and the
 * instance's, "Aloha - Nextcloud" — kept as the tail of every page's title
 * the way Nextcloud's own apps keep it.
 *
 * @type {string|null}
 */
let base = null

/**
 * Names the page in the browser tab, the history and to a screen reader.
 *
 * @param {string} page what the page is: the feed's name, an account's
 *                      display name, "Settings"
 */
export function setPageTitle(page) {
	if (base === null) {
		base = document.title
	}
	document.title = page === '' ? base : `${page} - ${base}`
}

/**
 * The pages whose name is known from the route alone. The views that show
 * something loaded — a feed, an account, a post — name themselves once it has
 * arrived, so they are not listed here.
 */
const ROUTE_TITLES = {
	settings: () => t('social', 'Settings'),
	discover: () => t('social', 'Discover'),
	statistics: () => t('social', 'Statistics'),
	about: () => t('social', 'About this server'),
	'blocked-accounts': () => t('social', 'Blocking'),
	'follow-requests': () => t('social', 'Follow requests'),
	migration: () => t('social', 'Migration'),
	switch: () => t('social', 'Move in from somewhere else'),
	shorts: () => t('social', 'Shorts'),
	search: () => t('social', 'Search'),
	'not-found': () => t('social', 'Page not found'),
}

/**
 * @param {{name?: string|symbol|null}} route the route being entered
 * @return {string|null} the page's name, or null when the view names itself
 */
export function titleForRoute(route) {
	const title = ROUTE_TITLES[String(route.name ?? '')]

	return title === undefined ? null : title()
}
