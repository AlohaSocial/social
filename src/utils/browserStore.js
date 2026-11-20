/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCurrentUser } from '@nextcloud/auth'

/**
 * Keys for what this app keeps in the browser, scoped to the user:
 * `localStorage` survives a logout and is shared by every account in the
 * browser profile. Signed out, the key is the bare name.
 *
 * @param {string} name what is being kept
 * @return {string} the key to keep it under
 */
export function userKey(name) {
	const uid = getCurrentUser()?.uid ?? ''

	return uid === '' ? name : `${name}::${uid}`
}

/**
 * Removes an unscoped key. It is deleted, not adopted: whoever wrote it may be
 * another account on the same computer.
 *
 * @param {string} name the old, unscoped key
 * @return {boolean} whether the store could be reached
 */
export function forgetUnscoped(name) {
	try {
		window.localStorage.removeItem(name)

		return true
	} catch {
		return false
	}
}
