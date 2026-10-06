/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** the picture the server sends as the header of an account that has none, as a PNG or an SVG */
const PLACEHOLDER = /\/img\/header-missing\.(svg|png)(\?.*)?$/

/**
 * The banner to draw for an account, or '' when it has none.
 *
 * The server always answers a URL in `header`, as Mastodon does; an account
 * without a banner gets a placeholder picture, and `header_default: true`
 * says so. The web client has its own way of showing "no banner" and does
 * not draw that picture.
 *
 * @param {{header?: string, header_default?: boolean}|null|undefined} account the account entity
 * @return {string} the banner URL, or ''
 */
export function bannerOf(account) {
	const header = account?.header ?? ''
	if (account?.header_default === true) {
		return ''
	}
	return header !== '' && !PLACEHOLDER.test(header) ? header : ''
}
