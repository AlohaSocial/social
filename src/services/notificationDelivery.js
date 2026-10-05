/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * When Aloha Social is allowed to interrupt: at once, or in a digest at the
 * times the reader picked.
 *
 * The server holds one object per account and answers the whole of it to every
 * read and every change, so a caller replaces what it has with the answer
 * rather than working out what changed.
 */

/** The most digest times one account can have. */
export const MAX_TIMES = 4

/** What a fresh account has, and what an answer is filled up to. */
export const DEFAULT_DELIVERY = Object.freeze({
	mode: 'instant',
	times: Object.freeze(['08:00', '18:00']),
	passthrough: Object.freeze({ direct: true, mentions_from_followed: true }),
	quiet: Object.freeze({ from: '', to: '' }),
})

/** @return {string} the one URL everything here talks to */
function url() {
	return generateUrl('/apps/social/api/v1/social/notification_delivery')
}

/**
 * Whether a value is a wall-clock time the server accepts: `HH:MM`, 24 hours.
 *
 * @param {unknown} value what was typed or answered
 * @return {value is string}
 */
export function isValidTime(value) {
	return typeof value === 'string' && /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value)
}

/**
 * The times of a day in the order they come, each once.
 *
 * `HH:MM` orders the same way as text does, so no parsing is needed. Anything
 * that is not a time is dropped rather than sorted somewhere arbitrary.
 *
 * @param {unknown[]} times in any order
 * @return {string[]} ascending, without repeats
 */
export function sortTimes(times) {
	return [...new Set((Array.isArray(times) ? times : []).filter(isValidTime))].sort()
}

/**
 * An answer, filled up to the full shape so nothing downstream has to ask
 * whether a key is there: an older server, or a thin test fixture, answers
 * less than the contract.
 *
 * @param {object|null|undefined} raw what the server answered
 * @return {{mode: 'instant'|'digest', times: string[], passthrough: {direct: boolean, mentions_from_followed: boolean}, quiet: {from: string, to: string}}}
 */
export function normaliseDelivery(raw) {
	const times = sortTimes(raw?.times)

	return {
		mode: raw?.mode === 'digest' ? 'digest' : 'instant',
		times: times.length > 0 ? times : [...DEFAULT_DELIVERY.times],
		passthrough: {
			direct: raw?.passthrough?.direct !== false,
			mentions_from_followed: raw?.passthrough?.mentions_from_followed !== false,
		},
		quiet: {
			from: isValidTime(raw?.quiet?.from) ? raw.quiet.from : '',
			to: isValidTime(raw?.quiet?.to) ? raw.quiet.to : '',
		},
	}
}

/** @return {Promise<ReturnType<typeof normaliseDelivery>>} the whole state */
export async function fetchNotificationDelivery() {
	const { data } = await axios.get(url())

	return normaliseDelivery(data)
}

/**
 * @param {object} changes any subset of `mode`, `times`, `passthrough`, `quiet`
 * @return {Promise<ReturnType<typeof normaliseDelivery>>} the whole state, as saved
 */
export async function saveNotificationDelivery(changes) {
	const { data } = await axios.patch(url(), changes)

	return normaliseDelivery(data)
}
