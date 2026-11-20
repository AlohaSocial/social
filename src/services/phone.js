/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Whether the app is on a phone-sized screen: one width shared by every
 * component and by the stylesheets' `@media (max-width: …)` rules. Not
 * Nextcloud's 1024px breakpoint, where a portrait tablet still has room for
 * the avatar column beside a post.
 */
export const PHONE_WIDTH = 600

const query = (typeof window !== 'undefined' && typeof window.matchMedia === 'function')
	? window.matchMedia(`(max-width: ${PHONE_WIDTH}px)`)
	: null

/**
 * @return {boolean} whether the viewport is phone-sized right now
 */
export function isPhone() {
	return query?.matches ?? false
}

/**
 * Calls back whenever the answer changes — a rotation, a resized window.
 *
 * @param {(phone: boolean) => void} callback told the new answer
 * @return {() => void} stops the calls
 */
export function onPhoneChange(callback) {
	if (query === null) {
		return () => {}
	}
	const handler = (event) => callback(event.matches)
	query.addEventListener('change', handler)
	return () => query.removeEventListener('change', handler)
}
