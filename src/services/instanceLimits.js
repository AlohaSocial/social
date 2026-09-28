/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateUrl } from '@nextcloud/router'

/**
 * What the server takes in one post.
 *
 * The composer, the inline editor and the Files action each carried their own
 * `500` and `10`, which were the server's numbers on the day they were typed
 * and nothing kept them so. The server publishes both in the instance entity
 * (`configuration.statuses.max_characters`, `max_media_attachments`), which
 * is where every other client reads them from; this reads them from the same
 * place and falls back to the old constants until it has.
 *
 * Framework-free on purpose: the Files action is loaded on every Files page
 * without Vue, Pinia or axios, and has to be able to ask too.
 */

/** the server's numbers as they stood when they were hard-coded here */
export const DEFAULT_LIMITS = Object.freeze({
	maxCharacters: 500,
	maxAttachments: 10,
	// `max_size`'s own default, 10 MB: what a picture upload is held to
	imageSizeLimit: 10 * 1048576,
	// off until the server says otherwise: a translate button that does
	// nothing is worse than no button
	translation: false,
})

/** the answer so far: the defaults until the server has said otherwise */
/** @type {{maxCharacters: number, maxAttachments: number, imageSizeLimit: number, translation: boolean}} */
let known = DEFAULT_LIMITS
/** the request in flight, or done; there is never more than one */
let pending = null

/**
 * @param {unknown} value what the entity said
 * @param {number} fallback what to use when it said nothing usable
 * @return {number} a positive whole number
 */
function positive(value, fallback) {
	const number = Number(value)

	return Number.isInteger(number) && number > 0 ? number : fallback
}

/**
 * The limits an instance entity carries.
 *
 * @param {{configuration?: {statuses?: {max_characters?: number, max_media_attachments?: number}, media_attachments?: {image_size_limit?: number}, polls?: object, translation?: {enabled?: boolean}}}|null} instance a `GET /api/v1/instance` answer
 * @return {{maxCharacters: number, maxAttachments: number, imageSizeLimit: number, translation: boolean}}
 */
export function limitsFrom(instance) {
	const statuses = instance?.configuration?.statuses ?? {}

	return {
		maxCharacters: positive(statuses.max_characters, DEFAULT_LIMITS.maxCharacters),
		maxAttachments: positive(statuses.max_media_attachments, DEFAULT_LIMITS.maxAttachments),
		// bytes; every upload that is not a video is held to it
		imageSizeLimit: positive(instance?.configuration?.media_attachments?.image_size_limit, DEFAULT_LIMITS.imageSizeLimit),
		// whether this Nextcloud has a translation provider at all
		translation: instance?.configuration?.translation?.enabled === true,
	}
}

/**
 * @return {{maxCharacters: number, maxAttachments: number, imageSizeLimit: number, translation: boolean}}
 *         the best answer available right now, without waiting for one
 */
export function knownLimits() {
	return known
}

/**
 * Asks the server once and remembers the answer for the page.
 *
 * A failure keeps the defaults: the server enforces its own limits either
 * way, and a composer that says 500 when the server takes 1000 is a smaller
 * wrong than one that never opens.
 *
 * @return {Promise<{maxCharacters: number, maxAttachments: number, imageSizeLimit: number, translation: boolean}>}
 */
export function loadLimits() {
	if (pending === null) {
		pending = window.fetch(generateUrl('apps/social/api/v1/instance/'), {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
		})
			.then((response) => (response.ok ? response.json() : null))
			.then((instance) => {
				known = limitsFrom(instance)

				return known
			})
			.catch(() => known)
	}

	return pending
}

/** Forgets the answer, for tests. */
export function resetLimitsForTests() {
	known = DEFAULT_LIMITS
	pending = null
}
