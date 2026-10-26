/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { uuid } from '../utils/uuid.js'

const LEGACY_SIGNAL_KINDS = new Set(['dwell', 'skip', 'open', 'media', 'link', 'mute'])
const signalDelivery = new WeakMap()

/** @param {object} event a buffered observation @return {{legacy: boolean, learning: boolean}} */
function deliveryState(event) {
	let state = signalDelivery.get(event)
	if (!state) {
		state = { legacy: false, learning: false }
		signalDelivery.set(event, state)
	}
	return state
}

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

/** @param {string} path what follows `api/v1/recommendations` */
function recommendationsUrl(path = '') {
	return generateUrl('apps/social/api/v1/recommendations' + path)
}

/**
 * Whether signals are collected for this reader right now.
 *
 * All three have to agree: the administrators have the feature on, the reader
 * has not opted out, and learning is not paused. Absent — an older server, or
 * a page with nobody signed in — is off.
 *
 * @param {{enabled?: boolean, learning?: boolean, paused?: boolean, instancePaused?: boolean}|null|undefined} interests `serverData.interests`
 * @return {boolean}
 */
export function isTracking(interests) {
	return interests?.enabled === true && interests?.learning === true && interests?.paused !== true && interests?.instancePaused !== true
}

/**
 * Whether the server currently permits activity-learning signals.
 * Older servers expose only the reader's learning mode.
 *
 * @param {object|null|undefined} preferences the versioned recommendation preferences
 * @return {boolean}
 */
export function isRecommendationLearningActive(preferences) {
	if (typeof preferences?.learning_active === 'boolean') {
		return preferences.learning_active
	}

	return preferences?.learning_mode === 'on'
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

/** @return {Promise<object>} the versioned profile generation and consent state */
export async function fetchRecommendationPreferences() {
	return (await axios.get(recommendationsUrl('/preferences'))).data
}

/** @param {string} profileId the profile's protocol identity */
export async function recordRecommendationProfileVisit(profileId) {
	if (typeof profileId !== 'string' || profileId.trim() === '' || profileId.length > 2048) {
		return
	}
	const preferences = await fetchRecommendationPreferences()
	if (!isRecommendationLearningActive(preferences) || !Number.isInteger(preferences.generation)) {
		return
	}
	await axios.post(recommendationsUrl('/signals'), { events: [{
		event_id: uuid(),
		generation: preferences.generation,
		kind: 'profile_visit',
		context: 'profile',
		profile_id: profileId,
	}] })
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
 * @param {boolean} [options.legacy] whether to send events to legacy hashtag learning
 * @return {Promise<void>}
 */
export async function sendSignals(events, { beacon = false, legacy = true } = {}) {
	if (events.length === 0) {
		return
	}
	const canBeacon = beacon && typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function'
	const legacyCandidates = legacy
		? events.filter((event) => !deliveryState(event).legacy
			&& LEGACY_SIGNAL_KINDS.has(event.kind)
			&& event.legacy_eligible !== false)
		: []
	const legacyEvents = legacyCandidates.map((event) => {
		const legacyEvent = /** @type {Record<string, unknown>} */ ({ status_id: event.status_id, kind: event.kind, context: event.context })
		if (event.kind === 'dwell' && Number.isFinite(event.ms)) {
			legacyEvent.ms = event.ms
		}
		return legacyEvent
	})

	let learningCandidates = events
		.filter((event) => !deliveryState(event).learning
			&& typeof event.event_id === 'string'
			&& Number.isInteger(event.generation))
	let preferenceError = null
	if (!beacon && learningCandidates.length > 0) {
		try {
			const preferences = await fetchRecommendationPreferences()
			if (!isRecommendationLearningActive(preferences) || !Number.isInteger(preferences.generation)) {
				for (const event of learningCandidates) {
					deliveryState(event).learning = true
				}
				learningCandidates = []
			} else {
				learningCandidates = learningCandidates.filter((event) => {
					if (event.generation !== preferences.generation) {
						deliveryState(event).learning = true
						return false
					}
					return true
				})
			}
		} catch (error) {
			preferenceError = error
			learningCandidates = []
		}
	}
	const learningEvents = learningCandidates.map((event) => {
		const learningEvent = { ...event }
		delete learningEvent.legacy_eligible
		return learningEvent
	})
	const requests = []
	const queue = (channel, target, body, sourceEvents) => {
		if (sourceEvents.length === 0) {
			return
		}
		const markDelivered = () => {
			for (const event of sourceEvents) {
				deliveryState(event)[channel] = true
			}
		}
		if (canBeacon) {
			try {
				if (navigator.sendBeacon(target, new Blob([JSON.stringify(body)], { type: 'application/json' }))) {
					markDelivered()
					return
				}
			} catch {
				// Fall back to a normal request when the browser cannot queue a beacon.
			}
		}
		requests.push(axios.post(target, body).then(markDelivered))
	}

	const token = canBeacon ? '?requesttoken=' + encodeURIComponent(getRequestToken() ?? '') : ''
	queue('legacy', url('/signals') + token, { events: legacyEvents }, legacyCandidates)
	queue('learning', recommendationsUrl('/signals') + token, { events: learningEvents }, learningCandidates)

	const results = await Promise.allSettled(requests)
	const failed = results.find((result) => result.status === 'rejected')
	if (failed?.status === 'rejected') {
		throw failed.reason
	}
	if (preferenceError !== null) {
		throw preferenceError
	}
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

/** @return {Promise<object>} the state, empty */
export async function resetInterests() {
	return (await axios.post(url('/reset'))).data
}
