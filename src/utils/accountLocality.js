/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Where an account lives, as its entity says.
 *
 * A handle without an `@` used to mean "on this server": every other account
 * came with its host after the `@`. A Bluesky account has no `@` either — its
 * handle is a domain, `alice.bsky.social` — so the handle alone no longer
 * says, and the entity's `bluesky` block does.
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
 * Whether a handle on its own, with no entity behind it yet, can only be a
 * Bluesky one: no host after an `@`, and a dot in it. A local user id can
 * carry a dot too, so this is what to *ask for*, not what an account is —
 * the server answers either way, and the entity then says which.
 *
 * @param {string} handle a handle, with or without its host
 * @return {boolean}
 */
export function isBlueskyHandle(handle) {
	const text = String(handle ?? '')

	return text !== '' && !text.includes('@') && text.includes('.')
}
