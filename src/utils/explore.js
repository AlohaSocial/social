/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What goes inside the sidebar's Explore entry, and how much of it fits: the
 * hashtags the reader follows, their lists, and what this instance is talking
 * about. Trending only fills what the chosen ones leave, so it never pushes
 * them out.
 */

/** A navigation row, as Nextcloud sizes one, when nothing has been measured. */
export const ROW_HEIGHT = 44

/**
 * What the rail needs for everything that is not an Explore child, used only
 * before the rail can be measured (first paint, jsdom); see `capacityFrom()`.
 */
const RESERVED_HEIGHT = 544

/** Never more than this, however tall the screen. */
export const MAX_ENTRIES = 12

/**
 * And never fewer than this: with no room above the account footer the
 * entries go rather than run underneath it; the Explore row itself stays.
 */
export const MIN_ENTRIES = 0

/**
 * How many entries fit, from a measurement of the rail. `free` already
 * excludes the Explore children, or the figure would change each time it was
 * applied and measured again.
 *
 * @param {object} measured what the rail reports
 * @param {number} measured.free pixels available for children
 * @param {number} measured.rowHeight what one child measures
 * @return {number} between MIN_ENTRIES and MAX_ENTRIES
 */
export function capacityFrom({ free, rowHeight }) {
	const row = Number(rowHeight) > 0 ? Number(rowHeight) : ROW_HEIGHT
	const space = Number(free)

	if (!Number.isFinite(space)) {
		return MAX_ENTRIES
	}

	return Math.max(MIN_ENTRIES, Math.min(MAX_ENTRIES, Math.floor(space / row)))
}

/**
 * How many entries the rail has room for, judged from the window alone.
 *
 * The fallback for the first paint, before the rail has been laid out.
 *
 * @param {number} viewportHeight the window's inner height, in pixels
 * @return {number} between MIN_ENTRIES and MAX_ENTRIES
 */
export function entriesThatFit(viewportHeight) {
	const height = Number(viewportHeight)
	if (!Number.isFinite(height) || height <= 0) {
		return MAX_ENTRIES
	}

	return capacityFrom({ free: height - RESERVED_HEIGHT, rowHeight: ROW_HEIGHT })
}

/**
 * How many of each kind to show when there is not room for all, in
 * proportion to how many there are; both keep at least one place while there
 * is room for one each.
 *
 * @param {number} tags how many hashtags there are
 * @param {number} lists how many lists there are
 * @param {number} cap how many entries there is room for
 * @return {{tags: number, lists: number}} how many of each to show
 */
export function shareOut(tags, lists, cap) {
	const room = Math.max(0, Math.floor(cap))
	const wanted = tags + lists

	if (wanted <= room) {
		return { tags, lists }
	}

	if (room === 0) {
		return { tags: 0, lists: 0 }
	}

	// one kind only: it takes what there is
	if (tags === 0 || lists === 0) {
		return { tags: Math.min(tags, room), lists: Math.min(lists, room) }
	}

	// one place each, then the rest in proportion to what is left over
	if (room === 1) {
		return { tags: 1, lists: 0 }
	}

	const spare = room - 2
	const remainingTags = tags - 1
	const remainingLists = lists - 1
	const forTags = Math.min(
		remainingTags,
		Math.round((remainingTags / (remainingTags + remainingLists)) * spare),
	)

	const chosenTags = 1 + forTags
	const chosenLists = Math.min(lists, room - chosenTags)

	// rounding can leave a place unused when one kind runs out first
	const unused = room - chosenTags - chosenLists

	return {
		tags: Math.min(tags, chosenTags + unused),
		lists: chosenLists,
	}
}

/**
 * The entries the Explore item shows: followed tags, then lists and kept
 * Bluesky feeds (which share the lists' room), then trending in the room left,
 * never repeating a tag the reader follows.
 *
 * @param {Array<{name: string}>} tags the hashtags the reader follows
 * @param {Array<{id: string, title: string}>} lists the reader's lists
 * @param {Array<{name: string}>} trending what the instance is talking about
 * @param {number} cap how many there is room for
 * @param {Array<{uri: string, name: string}>} feeds the Bluesky feeds and lists the reader keeps
 * @return {Array<object>} what to draw, each carrying the `kind` it came from
 */
export function chooseEntries(tags, lists, trending, cap, feeds = []) {
	const safeTags = Array.isArray(tags) ? tags : []
	const safeLists = [
		...(Array.isArray(lists) ? lists : []).map((list) => ({ kind: 'list', list })),
		...(Array.isArray(feeds) ? feeds : []).map((feed) => ({ kind: 'feed', feed })),
	]
	const safeTrending = Array.isArray(trending) ? trending : []
	const room = Math.max(0, Math.floor(Number(cap) || 0))
	const share = shareOut(safeTags.length, safeLists.length, room)

	const chosen = [
		...safeTags.slice(0, share.tags).map((tag) => ({ kind: 'tag', tag })),
		...safeLists.slice(0, share.lists),
	]

	const followed = new Set(safeTags.map((tag) => String(tag.name).toLowerCase()))

	return [
		...chosen,
		...safeTrending
			.filter((tag) => !followed.has(String(tag.name).toLowerCase()))
			.slice(0, Math.max(0, room - chosen.length))
			.map((tag) => ({ kind: 'trend', tag })),
	]
}
