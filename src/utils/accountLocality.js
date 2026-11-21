/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Where an account lives, as its entity says. A Bluesky handle is a domain
 * without an `@`, so the handle alone cannot tell; the `bluesky` block does.
 */

/**
 * @param {Partial<import('../types/Mastodon.js').Account>|null|undefined} account an Account entity
 * @return {boolean} whether this is a Bluesky account seen from here, rather
 * than a local one with a Bluesky presence
 */
export function isBlueskyAccount(account) {
	return account?.bluesky?.native === true
}

/**
 * @param {Partial<import('../types/Mastodon.js').Account>|null|undefined} account an Account entity
 * @return {boolean} whether the account is hosted on this instance
 */
export function isLocalAccount(account) {
	if (!account) {
		return false
	}

	return !String(account.acct ?? '').includes('@') && !isBlueskyAccount(account)
}

/**
 * The Nextcloud user a local account belongs to, which its avatar and
 * profile page go by. The username need not match it.
 *
 * @param {Partial<import('../types/Mastodon.js').Account>|null|undefined} account an Account entity
 * @return {string} the user id, or '' where the account has none or the page was not told it
 */
export function nextcloudUserOf(account) {
	return isLocalAccount(account) && typeof account?.nextcloud_user === 'string' ? account.nextcloud_user : ''
}

/**
 * Whether a bare handle looks like a Bluesky one: no `@` and a dot in it.
 * A local user id can carry a dot too, so this only says what to ask for.
 *
 * @param {string} handle a handle, with or without its host
 * @return {boolean}
 */
export function isBlueskyHandle(handle) {
	const text = String(handle ?? '')

	return text !== '' && !text.includes('@') && text.includes('.')
}

/**
 * The handle as somebody on another server would write it: `@user@host` for
 * a local account (whose `acct` is the bare username), `@acct` otherwise.
 *
 * @param {Partial<import('../types/Mastodon.js').Account>|null|undefined} account an Account entity
 * @param {string} hostname this server's host
 * @return {string}
 */
export function qualifiedHandle(account, hostname) {
	if (!account) {
		return ''
	}
	const acct = String(account.acct ?? '')
	if (isLocalAccount(account)) {
		return '@' + (account.username || acct) + '@' + hostname
	}

	return '@' + acct
}

/**
 * Whether a `user@host` handle from the server names this account, whose
 * `acct` is the bare username when it is local.
 *
 * @param {string} handle a handle, with or without a leading `@`
 * @param {Partial<import('../types/Mastodon.js').Account>|null|undefined} account an Account entity
 * @return {boolean}
 */
export function isHandleOf(handle, account) {
	const text = String(handle ?? '').replace(/^@/, '').toLowerCase()
	const acct = String(account?.acct ?? '').toLowerCase()
	if (text === '' || acct === '') {
		return false
	}

	return text === acct || (isLocalAccount(account) && text.startsWith(acct + '@'))
}

/**
 * What was typed, when it is a whole address another server can be asked
 * about (`user@host.example`, a Bluesky handle or a link), else ''.
 *
 * @param {string} text what was typed
 * @return {string} the address, without a leading `@`, or ''
 */
export function completeAddress(text) {
	const typed = String(text ?? '').trim()
	if (/^https?:\/\/[^\s/]+\.[^\s/]+/i.test(typed)) {
		return typed
	}
	const handle = typed.replace(/^@/, '')
	if (/^[^@\s/]+@[^@\s/]+\.[^@\s/.]{2,}$/.test(handle)) {
		return handle
	}
	if (/^[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z][a-z0-9-]+$/i.test(handle)) {
		return handle
	}

	return ''
}

/**
 * Whether the accounts found include the one an address names, by its
 * handle or by its link.
 *
 * @param {Array<Partial<import('../types/Mastodon.js').Account>>} accounts Account entities
 * @param {string} address what `completeAddress()` answered
 * @return {boolean}
 */
export function addressFoundIn(accounts, address) {
	return (accounts ?? []).some((account) => isHandleOf(address, account) || (address !== '' && account?.url === address))
}
