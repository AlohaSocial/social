/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A timeline here is a mix of accounts from many servers, and the only trace
 * of that in the UI used to be the part of the handle after the `@`. Giving
 * every instance a colour of its own makes the spread visible: you learn to
 * recognise where a post came from before reading the handle.
 *
 * The colour is derived from the hostname, so it is the same on every client,
 * every session and every instance — no storage, no coordination.
 */

import { isBlueskyAccount } from './accountLocality.js'

/**
 * Spread hues around the wheel; the same host always lands on the same one.
 *
 * @param {string} value a hostname
 * @return {number} a hash, reduced to a multiple-friendly range
 */
function hash(value) {
	let h = 0
	for (let i = 0; i < value.length; i++) {
		h = (h * 31 + value.charCodeAt(i)) % 360360
	}
	return h
}

/**
 * The instance an account belongs to, or '' for a local one.
 *
 * @param {string} acct a handle: `alice` locally, `alice@example.org` remotely
 * @return {string} the hostname, lowercased, or '' when the account is local
 */
export function instanceOf(acct) {
	if (typeof acct !== 'string') {
		return ''
	}

	const at = acct.lastIndexOf('@')
	if (at <= 0) {
		return ''
	}

	return acct.slice(at + 1).toLowerCase()
}

/**
 * How light a colour may be and still carry white text at 4.5:1.
 *
 * A fixed lightness cannot do this: at the same 52%, blue is dark enough for
 * white text and yellow is nowhere near — `hsl(60, 62%, 52%)` against white is
 * about 1.5:1, which is an unreadable badge for whoever happens to be on that
 * instance. So the hue is kept and the lightness is brought down until the
 * contrast is real.
 */
const TARGET_CONTRAST = 4.5

/**
 * One channel of an hsl() colour, 0..1.
 *
 * @param {number} hue 0 to 360
 * @param {number} saturation 0 to 1
 * @param {number} lightness 0 to 1
 * @param {number} n which channel: 0 red, 8 green, 4 blue
 * @return {number}
 */
function channel(hue, saturation, lightness, n) {
	const a = saturation * Math.min(lightness, 1 - lightness)
	const k = (n + hue / 30) % 12

	return lightness - a * Math.max(-1, Math.min(k - 3, 9 - k, 1))
}

/**
 * Relative luminance per WCAG 2, from an hsl() triple.
 *
 * @param {number} hue 0 to 360
 * @param {number} saturation 0 to 1
 * @param {number} lightness 0 to 1
 * @return {number} 0 for black to 1 for white
 */
function luminance(hue, saturation, lightness) {
	const linear = (value) => (value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4)

	return 0.2126 * linear(channel(hue, saturation, lightness, 0))
		+ 0.7152 * linear(channel(hue, saturation, lightness, 8))
		+ 0.0722 * linear(channel(hue, saturation, lightness, 4))
}

/**
 * Contrast of a colour against white, per WCAG 2.
 *
 * @param {number} hue 0 to 360
 * @param {number} saturation 0 to 1
 * @param {number} lightness 0 to 1
 * @return {number} the ratio, 1 to 21
 */
function contrastWithWhite(hue, saturation, lightness) {
	return 1.05 / (luminance(hue, saturation, lightness) + 0.05)
}

/**
 * A stable colour for one instance, as an `hsl()` string.
 *
 * The hue is the instance's own, so two servers stay tellable apart. The
 * lightness is whatever that hue needs in order to carry white text at 4.5:1,
 * which is not the same number for yellow as it is for blue.
 *
 * @param {string} host a hostname
 * @return {string} an hsl() colour, or '' for no host
 */
export function instanceColour(host) {
	if (!host) {
		return ''
	}

	const hue = hash(host) % 360
	const saturation = 0.62

	let lightness = 0.52
	while (lightness > 0.2 && contrastWithWhite(hue, saturation, lightness) < TARGET_CONTRAST) {
		lightness -= 0.01
	}

	return `hsl(${hue}, 62%, ${Math.round(lightness * 100)}%)`
}

/**
 * The domain a Bluesky handle is under: `bsky.social` for `alice.bsky.social`,
 * the way the server files such an account's host.
 *
 * @param {string} handle a Bluesky handle
 * @return {string} the domain, lowercased, or '' when there is none
 */
function blueskyInstanceOf(handle) {
	const dot = handle.indexOf('.')

	return dot === -1 ? '' : handle.slice(dot + 1).toLowerCase()
}

/**
 * Everything the UI needs to show where an account lives.
 *
 * @param {string|Partial<import('../types/Mastodon.js').Account>} account a handle, or the account itself — which is the only thing that can tell a Bluesky handle from a local user id
 * @return {{instance: string, colour: string, local: boolean}} the account's origin
 */
export function originOf(account) {
	const entity = typeof account === 'string' ? null : account
	const acct = typeof account === 'string' ? account : String(account?.acct ?? '')
	const bluesky = isBlueskyAccount(entity)
	const instance = bluesky ? blueskyInstanceOf(acct) : instanceOf(acct)

	return {
		instance,
		colour: instanceColour(instance),
		local: instance === '' && !bluesky,
	}
}
