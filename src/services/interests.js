/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'

/**
 * The server's side of For you: the reader's list, the settings that go
 * with it, and the signals the web UI reports about what was read.
 *
 * Every mutation answers the whole state — settings, the ranked list, the
 * candidates — so a caller replaces what it holds with the answer rather than
 * working out what changed.
 */

/**
 * @param {string} path what follows `api/v1/interests`
 * @return {string} the URL
 */
function url(path = '') {
	return generateUrl('apps/social/api/v1/interests' + path)
}

/**
 * Whether signals are collected for this reader right now.
 *
 * All three have to agree: the administrators have the feature on, the reader
 * has not opted out, and learning is not paused. Absent — an older server, or
 * a page with nobody signed in — is off.
 *
 * @param {{enabled?: boolean, learning?: boolean, paused?: boolean}|null|undefined} interests `serverData.interests`
 * @return {boolean}
 */
export function isTracking(interests) {
	return interests?.enabled === true && interests?.learning === true && interests?.paused !== true
}

/**
 * Whether the reader has the feed at all: the tab, the post menu item. Pausing
 * stops the learning, not the feed.
 *
 * @param {{enabled?: boolean, learning?: boolean, paused?: boolean}|null|undefined} interests `serverData.interests`
 * @return {boolean}
 */
export function hasInterestsFeed(interests) {
	return interests?.enabled === true && interests?.learning === true
}

/** @return {Promise<object>} the whole state */
export async function fetchInterests() {
	const { data } = await axios.get(url())

	return data
}

/**
 * @param {object} settings any of `learning`, `paused`, `languages`, `noticeAcknowledged`
 * @return {Promise<object>} the whole state, as saved
 */
export async function saveInterestSettings(settings) {
	const { data } = await axios.put(url('/settings'), settings)

	return data
}

/**
 * Reports what the reader did, in one request.
 *
 * On the way out of the page there is no time for a request to be answered, so
 * the beacon carries it instead. A beacon cannot set a header, which is where
 * the CSRF token normally travels, so it rides in the query; and it has to be
 * a Blob to be sent as JSON rather than as text.
 *
 * @param {object[]} events at most 100, as the contract describes them
 * @param {object} [options] how to send it
 * @param {boolean} [options.beacon] the page is going away
 * @return {Promise<void>}
 */
export async function sendSignals(events, { beacon = false } = {}) {
	if (events.length === 0) {
		return
	}

	const body = { events }
	if (beacon && typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
		const target = url('/signals') + '?requesttoken=' + encodeURIComponent(getRequestToken() ?? '')
		const blob = new Blob([JSON.stringify(body)], { type: 'application/json' })
		// false is the browser refusing to queue it: too large, or too many
		// beacons already. A request may still make it
		if (navigator.sendBeacon(target, blob)) {
			return
		}
	}

	await axios.post(url('/signals'), body)
}

/**
 * "Less like this": lowers the post's hashtags and keeps it out of the feed.
 *
 * @param {string} statusId the post
 * @return {Promise<void>}
 */
export async function lessLikeThis(statusId) {
	await axios.post(url('/less/' + encodeURIComponent(statusId)))
}

/**
 * Takes a "less like this" back.
 *
 * @param {string} statusId the post
 * @return {Promise<void>}
 */
export async function undoLessLikeThis(statusId) {
	await axios.delete(url('/less/' + encodeURIComponent(statusId)))
}

/**
 * @param {string} tag a hashtag, with or without its `#`
 * @return {string} the path segment naming it
 */
function tagPath(tag) {
	return '/' + encodeURIComponent(String(tag).replace(/^#/, ''))
}

/**
 * @param {string} tag the hashtag to add as a manual interest
 * @return {Promise<object>} the state
 */
export async function addInterest(tag) {
	return (await axios.post(url(), { tag: String(tag).replace(/^#/, '') })).data
}

/**
 * Pins the tag at a rank. Higher, lower, move to top and a drop all end here.
 *
 * @param {string} tag the hashtag to move
 * @param {number} position the 0-based rank it should hold
 * @return {Promise<object>} the state
 */
export async function moveInterest(tag, position) {
	return (await axios.post(url(tagPath(tag) + '/move'), { position })).data
}

/**
 * @param {string} tag the hashtag to hold at its current rank
 * @return {Promise<object>} the state
 */
export async function pinInterest(tag) {
	return (await axios.post(url(tagPath(tag) + '/pin'))).data
}

/**
 * @param {string} tag the hashtag to let float by its score again
 * @return {Promise<object>} the state
 */
export async function unpinInterest(tag) {
	return (await axios.post(url(tagPath(tag) + '/unpin'))).data
}

/**
 * A learned tag's score goes back to nought; a manual one is gone.
 *
 * @param {string} tag the hashtag to remove
 * @return {Promise<object>} the state
 */
export async function removeInterest(tag) {
	return (await axios.delete(url(tagPath(tag)))).data
}

/** @return {Promise<object>} the state, empty */
export async function resetInterests() {
	return (await axios.post(url('/reset'))).data
}
