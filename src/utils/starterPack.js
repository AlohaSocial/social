/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Where a Bluesky starter pack opens in this app: the path bsky.app gives
 * it, under this app. Kept free of imports, because the card of a post is
 * drawn under `TimelinePost`, which is in the dashboard's bundle too.
 *
 * @param {string} url a bsky.app address
 * @return {{name: string, params: {actor: string, rkey: string}}|null} the route, or null for any other address
 */
export function starterPackRoute(url) {
	const match = /^https:\/\/bsky\.app\/starter-pack\/([^/?#]+)\/([^/?#]+)\/?(?:[?#].*)?$/.exec(String(url ?? '').trim())
	if (match === null) {
		return null
	}

	return { name: 'starter-pack', params: { actor: decodeURIComponent(match[1]), rkey: decodeURIComponent(match[2]) } }
}

/**
 * The bsky.app address of the starter pack a route names.
 *
 * @param {{actor?: string|string[], rkey?: string|string[]}} params the route's
 * @return {string}
 */
export function starterPackUrl(params) {
	return `https://bsky.app/starter-pack/${encodeURIComponent(String(params?.actor ?? ''))}/${encodeURIComponent(String(params?.rkey ?? ''))}`
}
