/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** The largest size, in CSS pixels, that an account's `avatar_small` is drawn at. */
export const SMALL_AVATAR_SIZE = 64

/**
 * Where to draw an account's picture from at a given size.
 *
 * `avatar_small` is this server's thumbnail of a remote picture, 128 px
 * across: enough for 64 px on a high-density screen, at a fraction of the
 * original, which is what `avatar` stays for Mastodon apps. An entity without
 * one (a Bluesky profile read live, a directory result) is drawn from `avatar`.
 *
 * @param {{avatar?: string, avatar_small?: string}|null|undefined} account an Account entity
 * @param {number|null} size the size it is drawn at, in CSS pixels; null for a component's default
 * @return {string} the address, or an empty string where there is none
 */
export function accountAvatarUrl(account, size = SMALL_AVATAR_SIZE) {
	if (!account) {
		return ''
	}

	if ((size === null || size <= SMALL_AVATAR_SIZE) && account.avatar_small) {
		return account.avatar_small
	}

	return account.avatar ?? ''
}
