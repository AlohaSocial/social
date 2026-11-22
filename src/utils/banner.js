/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** the picture the server sends as the header of an account that has none, as a PNG or an SVG */
const PLACEHOLDER = /\/img\/header-missing\.(svg|png)(\?.*)?$/

/**
 * The banner to draw for an account, or '' when it has none.
 *
 * The server always sends a `header` URL, as Mastodon does, with a placeholder
 * for accounts without one; the web client does not draw the placeholder.
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
