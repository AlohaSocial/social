/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { generateUrl } from '@nextcloud/router'

/**
 * The native Nextcloud profile URL for an account hosted on this instance.
 * Remote actors have no corresponding `/u/{uid}` page and return an empty
 * string so their caller can keep the Social/Fediverse profile destination.
 *
 * @param {Partial<import('../types/Mastodon.js').Account>} account a Mastodon-compatible account
 * @param {string|number|null} [statusId] optional Social status to focus
 * @return {string}
 */
export function localProfileUrl(account, statusId = null) {
	const acct = String(account?.acct ?? '')
	const username = String(account?.username || acct)
	if (String(account?.id ?? '').includes('/ap/bluesky/')) {
		const url = `${generateUrl('/apps/social')}/${encodeURIComponent(username)}`
		return statusId === null || statusId === undefined
			? url
			: `${url}#social-profile-status-${encodeURIComponent(String(statusId))}`
	}
	if (!acct || acct.includes('@') || !username) {
		return ''
	}

	const url = generateUrl(`/u/${encodeURIComponent(username)}`)
	return statusId === null || statusId === undefined
		? url
		: `${url}#social-profile-status-${encodeURIComponent(String(statusId))}`
}

/**
 * Public local posts can be opened in the native profile's Social section.
 * Restricted posts retain Social's normal post URL.
 *
 * @param {import('../types/Mastodon.js').Status} status a Mastodon-compatible status
 * @return {string}
 */
export function publicLocalStatusUrl(status) {
	if (status?.visibility !== 'public') {
		return ''
	}
	return localProfileUrl(status.account, status.id)
}
