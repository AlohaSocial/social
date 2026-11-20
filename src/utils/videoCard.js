/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale } from '@nextcloud/l10n'

/** How long a video stays "recently uploaded", in milliseconds. */
export const RECENT_FOR = 7 * 24 * 3600 * 1000

/**
 * The post a timeline entry is about: the boosted post for a boost, which is
 * the one that carries the video.
 *
 * @param {import('../types/Mastodon.js').Status} entry a timeline entry
 * @return {import('../types/Mastodon.js').Status}
 */
export function videoPostOf(entry) {
	return entry?.reblog ?? entry
}

/**
 * @param {import('../types/Mastodon.js').Status} entry a timeline entry
 * @return {import('../types/Mastodon.js').VideoCard|null} its card, null when it is not a video
 */
export function videoCardOf(entry) {
	return videoPostOf(entry)?.video_card ?? null
}

/**
 * The attachment a card plays as its preview: the first video.
 *
 * @param {import('../types/Mastodon.js').Status} post the video post
 * @return {import('../types/Mastodon.js').MediaAttachment|null}
 */
export function videoAttachmentOf(post) {
	return (post?.media_attachments ?? []).find((media) => media.type === 'video' || media.type === 'gifv') ?? null
}

/**
 * A count the way a video site writes it: 1.2K, 3.4M — in the reader's own
 * locale, which writes it its own way.
 *
 * @param {number} count the count
 * @return {string}
 */
export function compactCount(count) {
	return new Intl.NumberFormat(getCanonicalLocale(), { notation: 'compact', maximumFractionDigits: 1 }).format(count)
}

/**
 * Whether a card may play a moving preview under the pointer: only with a
 * mouse or a pen, not to somebody who asked for less motion, and not on a
 * connection the reader asked to spare.
 *
 * @param {Window} [win] the window to ask, for tests
 * @return {boolean}
 */
export function mayPreview(win = window) {
	const matches = (query) => typeof win.matchMedia === 'function' && win.matchMedia(query).matches
	const connection = /** @type {{connection?: {saveData?: boolean}}} */ (/** @type {unknown} */ (win.navigator))?.connection

	return matches('(hover: hover) and (pointer: fine)')
		&& !matches('(prefers-reduced-motion: reduce)')
		&& !matches('(prefers-reduced-data: reduce)')
		&& connection?.saveData !== true
}

/**
 * The chips over the Videos page, from the videos it holds: All, Live when
 * any of them is live, every category that occurs, most frequent first, and
 * Recently uploaded.
 *
 * @param {import('../types/Mastodon.js').Status[]} entries the timeline
 * @return {Array<{value: string, kind: 'all'|'live'|'category'|'recent', label: string}>} label is '' for the three fixed chips, which the page names
 */
export function chipsOf(entries) {
	const counts = new Map()
	let live = false
	for (const entry of entries) {
		const card = videoCardOf(entry)
		if (card === null) {
			continue
		}
		live = live || card.live === true
		if (card.category) {
			counts.set(card.category, (counts.get(card.category) ?? 0) + 1)
		}
	}

	const categories = [...counts.entries()]
		.sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
		.map(([category]) => ({ value: 'category:' + category, kind: /** @type {'category'} */ ('category'), label: category }))

	return [
		{ value: 'all', kind: 'all', label: '' },
		...(live ? [{ value: 'live', kind: /** @type {'live'} */ ('live'), label: '' }] : []),
		...categories,
		{ value: 'recent', kind: 'recent', label: '' },
	]
}

/**
 * The videos a chip keeps, in the order it shows them. Recently uploaded goes
 * by when the post was made, not `published` (which can be a film's release
 * date), newest first. A video in several entries is one card, the first.
 *
 * @param {import('../types/Mastodon.js').Status[]} entries the timeline, entries that are not videos included
 * @param {string} chip a chip's `value`
 * @param {number} [now] the time to measure from, for tests
 * @return {import('../types/Mastodon.js').Status[]} only video entries
 */
export function filterByChip(entries, chip, now = Date.now()) {
	const seen = new Set()
	const videos = entries.filter((entry) => {
		const id = videoPostOf(entry)?.id
		if (videoCardOf(entry) === null || seen.has(id)) {
			return false
		}
		seen.add(id)

		return true
	})

	if (chip === 'live') {
		return videos.filter((entry) => videoCardOf(entry).live === true)
	}
	if (chip.startsWith('category:')) {
		const category = chip.slice('category:'.length)

		return videos.filter((entry) => videoCardOf(entry).category === category)
	}
	if (chip === 'recent') {
		return videos
			.map((entry) => ({ entry, at: Date.parse(videoPostOf(entry).created_at) }))
			.filter(({ at }) => Number.isFinite(at) && now - at <= RECENT_FOR)
			.sort((a, b) => b.at - a.at)
			.map(({ entry }) => entry)
	}

	return videos
}
