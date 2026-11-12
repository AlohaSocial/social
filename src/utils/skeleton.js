/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { photoLayout } from '../services/photoLayout.js'

/**
 * @typedef {object} SkeletonChoice
 * @property {'rows'|'grid'|'page'} variant which TimelineSkeleton to draw
 * @property {'square'|'video'} shape the grid's cells, for `grid`
 */

const VARIANTS = ['rows', 'grid', 'page']

/**
 * The placeholder that stands in for a route's page while its code loads.
 *
 * A route names it as `meta.skeleton`: a variant, `{ variant, shape }`, or a
 * function of the route returning either, for a route whose page takes more
 * than one shape. A route that names none gets the neutral `page`.
 *
 * @param {{meta?: {skeleton?: unknown}}|undefined} route the route being opened
 * @return {SkeletonChoice}
 */
export function skeletonFor(route) {
	/** @type {any} */
	let chosen = route?.meta?.skeleton
	if (typeof chosen === 'function') {
		chosen = chosen(route)
	}
	if (typeof chosen === 'string') {
		chosen = { variant: chosen }
	}

	const variant = VARIANTS.includes(chosen?.variant) ? chosen.variant : 'page'

	return { variant, shape: variant === 'grid' && chosen.shape === 'video' ? 'video' : 'square' }
}

/**
 * The timeline route's placeholder, by the timeline it opens: the Videos
 * page's cards, the photo grid where the reader chose tiles, the neutral page
 * for Direct messages (a chat, not rows), and rows for every other feed.
 *
 * @param {{params?: {type?: string}}} route the timeline route being opened
 * @return {SkeletonChoice|string}
 */
export function timelineSkeleton(route) {
	const type = route?.params?.type
	if (type === 'videos') {
		return { variant: 'grid', shape: 'video' }
	}
	if (type === 'photos' && photoLayout() === 'grid') {
		return 'grid'
	}

	return type === 'direct' ? 'page' : 'rows'
}
