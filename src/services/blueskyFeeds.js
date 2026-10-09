/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Bluesky's custom feeds and lists the reader keeps: the same ones a Bluesky
 * app signed in to this account shows, since both read the account's Bluesky
 * preferences.
 *
 * A feed is named by its `at://` URI. In the address bar it is spelled as
 * three path segments — whose it is, which kind, and its key — because the
 * URI's own slashes would have to travel encoded, and web servers refuse an
 * encoded slash in a path.
 */

/** the collection behind each kind, as the URI names it */
const COLLECTIONS = {
	feed: 'app.bsky.feed.generator',
	list: 'app.bsky.graph.list',
}

/**
 * @param {string} path what follows `api/v1/social/bluesky/feeds`
 * @return {string} the URL
 */
function url(path = '') {
	return generateUrl('apps/social/api/v1/social/bluesky/feeds' + path)
}

/**
 * Where a feed or list is read.
 *
 * @param {string} uri its `at://` URI
 * @return {object|null} the route, or null for a URI that is neither
 */
export function routeFor(uri) {
	const match = /^at:\/\/(did:[a-z]+:[^/]+)\/([^/]+)\/([^/]+)$/.exec(String(uri))
	const kind = match === null ? undefined : Object.keys(COLLECTIONS).find((key) => COLLECTIONS[key] === match[2])
	if (kind === undefined) {
		return null
	}

	return { name: 'bluesky-feed', params: { did: match[1], kind, rkey: match[3] } }
}

/**
 * The feed or list a route reads.
 *
 * @param {{did?: string|string[], kind?: string|string[], rkey?: string|string[]}} params the route's
 * @return {string} its `at://` URI, or '' when the route names none
 */
export function uriOf(params) {
	const collection = COLLECTIONS[String(params?.kind ?? '')]
	if (collection === undefined || !params.did || !params.rkey) {
		return ''
	}

	return `at://${params.did}/${collection}/${params.rkey}`
}

/** @return {Promise<object[]>} the feeds and lists the reader keeps, pinned first */
export async function fetchSavedFeeds() {
	const { data } = await axios.get(url())

	return Array.isArray(data?.feeds) ? data.feeds : []
}

/** @return {Promise<object[]>} the feeds Bluesky suggests to the reader */
export async function fetchSuggestedFeeds() {
	const { data } = await axios.get(url('/suggested'))

	return Array.isArray(data?.feeds) ? data.feeds : []
}

/**
 * @param {string} feed an `at://` URI, or a bsky.app address of a feed or list
 * @return {Promise<object>} the feed as kept
 */
export async function saveFeed(feed) {
	const { data } = await axios.post(url(), { feed })

	return data.feed
}

/**
 * @param {string} uri the feed's or list's `at://` URI
 */
export async function forgetFeed(uri) {
	await axios.delete(url(), { params: { uri } })
}
