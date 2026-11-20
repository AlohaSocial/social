/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { reactive } from 'vue'
import logger from './logger.js'

/**
 * How far the reader got through a video, for the red bar under a card and
 * the Continue watching shelf. The server keeps it (`WatchService`); the
 * unfinished list is read once per page (`loadUnfinished()`) and kept here by
 * post, so every card for the same video draws the same bar.
 *
 * @typedef {object} VideoProgress
 * @property {number} position - where the reader stopped, in seconds
 * @property {number} duration - how long the video runs, in seconds
 * @property {number} fraction - `position / duration`, from 0 to 1
 * @property {number} updatedAt - when it was last moved, as a timestamp in milliseconds; 0 when not known
 */

/**
 * What is known, by post id. `null` progress is a video the reader finished
 * here, which no older answer of the server's may bring back.
 *
 * @type {Map<string, {progress: VideoProgress|null, updatedAt: number}>}
 */
const known = reactive(new Map())

/** Past this share of a video it is watched, as the server counts it too. */
export const FINISHED = 0.95

/**
 * @param {{position?: number, duration?: number, updated_at?: ?string}|null|undefined} watch a status's `watch_progress`
 * @return {VideoProgress|null}
 */
function fromWatch(watch) {
	const position = Number(watch?.position ?? 0)
	const duration = Number(watch?.duration ?? 0)
	if (!(duration > 0) || !(position > 0)) {
		return null
	}

	return {
		position,
		duration,
		fraction: Math.min(1, position / duration),
		updatedAt: Date.parse(watch.updated_at ?? '') || 0,
	}
}

/**
 * @param {import('../types/Mastodon.js').Status} status the video post (not the boost of it)
 * @return {VideoProgress|null} how far the reader got, or null when they never started it or finished it
 */
export function progressOf(status) {
	const told = fromWatch(status?.watch_progress)
	const here = known.get(String(status?.id ?? ''))
	if (here === undefined || (told !== null && told.updatedAt > here.updatedAt)) {
		return told
	}

	return here.progress
}

/**
 * Remembers where the reader is in a video as a player reports it, so every
 * card for it on the page draws the same bar without asking the server.
 *
 * @param {string} id the post
 * @param {number} position where the reader is, in seconds
 * @param {number} duration how long it runs, in seconds
 */
export function rememberProgress(id, position, duration) {
	const progress = fromWatch({ position, duration, updated_at: new Date().toISOString() })
	known.set(String(id), {
		progress: (progress !== null && progress.fraction < FINISHED) ? progress : null,
		updatedAt: Date.now(),
	})
}

/**
 * Whether a video counts as begun and not finished: what the Continue
 * watching shelf holds. The server forgets a video watched past 95%.
 *
 * @param {VideoProgress|null} progress what `progressOf()` answered
 * @return {boolean}
 */
export function isUnfinished(progress) {
	return progress !== null && progress.fraction > 0 && progress.fraction < FINISHED
}

/**
 * The videos the reader is in the middle of, most recently watched first,
 * remembering where they stopped in each.
 *
 * @param {number} [limit] how many
 * @return {Promise<import('../types/Mastodon.js').Status[]>} what the server said, which may be none
 * @throws {Error} when the server would not say, so that a page can offer to ask again
 */
export async function loadUnfinished(limit = 12) {
	try {
		const { data } = await axios.get(generateUrl('apps/social/api/v1/videos/continue'), { params: { limit } })
		const statuses = Array.isArray(data) ? data : []
		for (const status of statuses) {
			const progress = fromWatch(status.watch_progress)
			if (progress !== null) {
				known.set(String(status.id), { progress, updatedAt: progress.updatedAt })
			}
		}

		return statuses
	} catch (error) {
		logger.debug('Could not read what the reader was watching', { error })
		throw error
	}
}

/** Forgets what is known; for tests, which share one module. */
export function forgetProgress() {
	known.clear()
}
