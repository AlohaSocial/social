/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What goes inside the sidebar's Explore entry, and how much of it fits.
 *
 * The rail holds three things: the hashtags the reader follows, the lists they
 * have, and what this instance is talking about. The first two grow without
 * limit — somebody who follows forty tags would otherwise push their own feed
 * off a laptop screen — so the entry shows as much as there is room for.
 *
 * The three are not equal. A followed tag and a list were *chosen*; a trending
 * tag is merely popular, and it fills what is left after the chosen ones have
 * their places. What somebody follows can therefore never be pushed out of
 * their own sidebar by what happens to be busy today.
 */

/** A navigation row, as Nextcloud sizes one, when nothing has been measured. */
export const ROW_HEIGHT = 44

/**
 * What the rail needs for everything that is not an Explore child, used only
 * when the rail cannot be measured.
 *
 * The real figure is taken from the DOM — see `capacityFrom()` — because the
 * parts are not all rows and they come and go: an error entry appears when
 * something breaks, and browser zoom changes every height at once. This is the
 * answer for the first paint, before there is anything to measure, and for
 * jsdom.
 */
const RESERVED_HEIGHT = 544

/** Never more than this, however tall the screen. */
export const MAX_ENTRIES = 12

/**
 * And never fewer than this.
 *
 * Zero: when there is no room left above the account footer the entries go
 * rather than run underneath it. The Explore row itself stays, so the reader
 * can still collapse it or make the window bigger, and nothing is drawn where
 * it cannot be read.
 */
export const MIN_ENTRIES = 0

/**
 * How many entries fit, from a measurement of the rail itself.
 *
 * `free` is the height left over once everything that is *not* an Explore
 * child has been accounted for, which is why this cannot simply divide the
 * rail's height: the children are in the rail too, and a figure that counted
 * them would grow every time it was applied and shrink every time it was
 * re-measured.
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
 * How many of each kind to show, when there is not room for all of them.
 *
 * Proportional to how many there are, so somebody with twelve tags and one
 * list does not lose the list, and somebody with one tag and twelve lists does
 * not lose the tag. Both kinds keep at least one place while there is room for
 * one each — a truncation that silently drops a whole kind reads as the kind
 * having gone missing rather than as there being too little room.
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
 * The entries the Explore item shows: what the reader follows, then their
 * lists and the Bluesky feeds they keep, then what the instance is talking
 * about. A kept feed was chosen as a list was, and shares the lists' room.
 *
 * Trending takes only the room the first two leave, and never a tag the reader
 * already follows — that tag is in the list above under the same name, and two
 * identical rows one after the other read as a bug rather than as two
 * meanings.
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
