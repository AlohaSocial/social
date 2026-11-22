/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Where each page sits in the sidebar (`Navigation.vue`), so moving between two
 * of them has a direction. Anything not listed (a post, a tag, a profile) sits
 * past the end.
 */

/** The sidebar, top to bottom, as route identities. */
const ORDER = [
	'timeline:',
	'timeline:photos',
	'timeline:videos',
	'timeline:news',
	'timeline:notifications',
	'timeline:direct',
	'discover',
	'profile',
	'settings',
]

/** Anything not on the list is below it, in the order it is reached. */
const BEYOND = ORDER.length

/**
 * The feed's scopes: My Feed, For you, Local and Global are one sidebar entry
 * read at different distances, and the switcher above the posts moves between
 * them. They are one page here, so changing scope is not a page change.
 */
const FEED_SCOPES = ['', 'home', 'interests', 'timeline', 'federated']

/**
 * What a route is called for the purpose of ordering: its name, plus
 * `params.type` for the timeline, which is several sidebar entries under one
 * route name. The feed's scopes are all the first entry.
 *
 * @param {{name?: string|symbol|null, params?: Record<string, string|string[]>}} route a route, or anything with `name` and `params`
 * @return {string} the identity
 */
export function pageIdentity(route) {
	const name = String(route?.name ?? '')
	if (name === 'timeline') {
		const type = String(route?.params?.type ?? '')

		return 'timeline:' + (FEED_SCOPES.includes(type) ? '' : type)
	}

	return name
}

/**
 * Whether two routes are the same page, read differently: another scope of the
 * feed or of Photos, or another query. Nothing is animated between them.
 *
 * @param {{name?: string|symbol|null, params?: Record<string, string|string[]>}} to where they are going
 * @param {{name?: string|symbol|null, params?: Record<string, string|string[]>}|null|undefined} from where they were
 * @return {boolean}
 */
export function isSamePage(to, from) {
	return from !== undefined && from !== null && pageIdentity(to) === pageIdentity(from)
}

/**
 * How far down the sidebar a page is.
 *
 * @param {{name?: string|symbol|null, params?: Record<string, string|string[]>}} route the route to place
 * @return {number} its rank, or one past the end for somewhere the sidebar does not list
 */
export function pageRank(route) {
	const at = ORDER.indexOf(pageIdentity(route))

	return at === -1 ? BEYOND : at
}

/**
 * Which way the reader went: `''` for the same page, or when neither page is
 * on the sidebar (a post to another post, say).
 *
 * @param {{name?: string|symbol|null, params?: Record<string, string|string[]>}} to where they are going
 * @param {{name?: string|symbol|null, params?: Record<string, string|string[]>}} from where they were
 * @return {'forward'|'back'|''} the direction
 */
export function pageDirection(to, from) {
	if (from === undefined || from === null || pageIdentity(to) === pageIdentity(from)) {
		return ''
	}

	const there = pageRank(to)
	const here = pageRank(from)
	if (there === here) {
		return ''
	}

	return there > here ? 'forward' : 'back'
}
