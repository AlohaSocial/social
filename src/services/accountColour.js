/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A colour for an account, the same one everywhere. It is returned as a hue and
 * set as a custom property, so the CSS can make a colour that suits the theme.
 */

/** Hues that read as an error, kept for errors. */
const RESERVED = [[350, 370], [0, 12]]

/**
 * A stable hue for a handle: FNV-1a, so every browser and page agrees. The
 * reds are stepped over so an account's colour never reads as a warning.
 *
 * @param {string} acct the handle, with or without a leading @
 * @return {number} a hue in degrees
 */
export function accountHue(acct) {
	const handle = String(acct ?? '').replace(/^@/, '').toLowerCase()
	if (handle === '') {
		return 210
	}

	let hash = 0x811c9dc5
	for (let i = 0; i < handle.length; i++) {
		hash ^= handle.charCodeAt(i)
		// multiplies by FNV's 32-bit prime, as shifts and additions
		hash = (hash + (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24)) >>> 0
	}

	let hue = hash % 360
	for (const [from, to] of RESERVED) {
		const start = from % 360
		if ((start <= to && hue >= start && hue < to) || (start > to && (hue >= start || hue < to))) {
			hue = (to + 8) % 360
		}
	}

	return hue
}

/**
 * The style an element carries so that everything inside it can be that
 * account's colour.
 *
 * @param {import('../types/Mastodon.js').Account|string} account an account, or a handle
 * @return {Record<string, string>} a style binding
 */
export function accountStyle(account) {
	const acct = typeof account === 'string'
		? account
		: (account?.acct ?? account?.username ?? account?.id ?? '')

	return { '--account-hue': String(accountHue(acct)) }
}
