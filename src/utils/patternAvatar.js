/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { tagHue } from './tagColour.js'

/**
 * A face for an account that has not chosen one.
 *
 * The server says when an account's picture is a placeholder
 * (`avatar_default`, see docs/API.md): Nextcloud's initials for a local
 * account without an uploaded picture, the app icon for a remote one whose
 * picture is missing. In their place goes a soft flower, the app's own motif,
 * tinted with a hue taken from the handle — the same account gets the same
 * flower on every page and every device, and nothing is stored.
 *
 * It is decoration: the name beside it is what says who this is, so the
 * picture carries no text of its own and needs none read out.
 */

/**
 * @param {{avatar_default?: boolean}|null|undefined} account an Account entity
 * @return {boolean} whether its picture is a placeholder rather than one it set
 */
export function hasPlaceholderAvatar(account) {
	return account?.avatar_default === true
}

/**
 * The flower's shape and colour for an account.
 *
 * @param {string} key what identifies the account
 * @return {{hue: number, petals: number, turn: number}} the hue in degrees, how
 *         many petals, and how far the flower is turned
 */
export function patternFor(key) {
	return {
		hue: tagHue(key),
		petals: 5 + tagHue(key + ':petals') % 3,
		turn: tagHue(key + ':turn') % 72,
	}
}

/**
 * The flower as an SVG document.
 *
 * Pastel on purpose: an avatar is a picture with its own background, the way
 * a photograph is, so it has to sit on the light and the dark theme alike
 * without knowing which one is behind it.
 *
 * @param {string} key what identifies the account
 * @return {string} the SVG markup
 */
export function patternSvg(key) {
	const { hue, petals, turn } = patternFor(key)
	const second = (hue + 40) % 360
	const petal = (index, length, width, opacity) => {
		const angle = (360 / petals) * index
		return `<ellipse cx="0" cy="${-length}" rx="${width}" ry="${length}" transform="rotate(${angle.toFixed(1)})" fill-opacity="${opacity}"/>`
	}
	const ring = (length, width, opacity) => Array.from({ length: petals }, (unused, index) => petal(index, length, width, opacity)).join('')

	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
		+ '<defs><linearGradient id="b" x1="0" y1="0" x2="1" y2="1">'
		+ `<stop offset="0" stop-color="hsl(${hue}, 62%, 91%)"/>`
		+ `<stop offset="1" stop-color="hsl(${second}, 55%, 81%)"/>`
		+ '</linearGradient></defs>'
		+ '<rect width="64" height="64" fill="url(#b)"/>'
		+ `<g transform="translate(32 32) rotate(${turn + 180 / petals})" fill="hsl(${second}, 55%, 66%)">${ring(17, 8, 0.28)}</g>`
		+ `<g transform="translate(32 32) rotate(${turn})" fill="hsl(${hue}, 52%, 56%)">${ring(12.5, 6.5, 0.55)}</g>`
		+ `<circle cx="32" cy="32" r="4.5" fill="hsl(${hue}, 70%, 96%)"/>`
		+ '</svg>'
}

/**
 * Where to draw an account's placeholder from, or nothing for an account
 * with a picture of its own.
 *
 * @param {{avatar_default?: boolean, acct?: string, username?: string, id?: string}|null|undefined} account an Account entity
 * @return {string} a data: URL, or an empty string
 */
export function patternAvatarUrl(account) {
	if (!hasPlaceholderAvatar(account)) {
		return ''
	}

	const key = String(account.acct || account.username || account.id || '')

	return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(patternSvg(key))
}
