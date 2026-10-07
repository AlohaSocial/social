/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The fediverse drawn as islands: every server is one, and following
 * somebody on another opens a canoe route to theirs. This groups the
 * accounts somebody follows by server and places those servers around their
 * own on a map.
 */

/** the map's size in SVG units; the home island sits in the middle */
export const MAP = { width: 640, height: 300 }

/** how many neighbouring islands the map has room for */
export const MAX_ISLANDS = 8

/**
 * The server an account lives on, or '' when it is the reader's own.
 *
 * @param {{acct?: string}} account a Mastodon account
 * @param {string} homeHost the reader's own server
 * @return {string}
 */
export function serverOf(account, homeHost) {
	const acct = String(account?.acct ?? '')
	const at = acct.lastIndexOf('@')
	if (at <= 0) {
		return ''
	}

	const host = acct.slice(at + 1).toLowerCase()
	return host === String(homeHost).toLowerCase() ? '' : host
}

/**
 * The servers the reader follows people on, the busiest first.
 *
 * @param {Array<{acct?: string}>} accounts who the reader follows
 * @param {string} homeHost the reader's own server
 * @param {number} max how many islands to keep
 * @return {{islands: Array<{host: string, count: number}>, more: number, local: number}}
 *   the islands kept, how many other servers were left off, and how many of
 *   the follows live on the reader's own server
 */
export function islandsFrom(accounts, homeHost, max = MAX_ISLANDS) {
	const counts = new Map()
	let local = 0
	for (const account of accounts) {
		const host = serverOf(account, homeHost)
		if (host === '') {
			local += 1
			continue
		}
		counts.set(host, (counts.get(host) ?? 0) + 1)
	}

	const all = [...counts.entries()]
		.map(([host, count]) => ({ host, count }))
		.sort((a, b) => b.count - a.count || a.host.localeCompare(b.host))

	return { islands: all.slice(0, max), more: Math.max(0, all.length - max), local }
}

/**
 * Where each island goes: in a ring around the home island, sized by how
 * many people the reader follows there, each with a curved route home.
 *
 * @param {Array<{host: string, count: number}>} islands from islandsFrom()
 * @return {Array<{host: string, count: number, x: number, y: number, r: number, route: string, canoe: {x: number, y: number}}>}
 */
export function placeIslands(islands) {
	const cx = MAP.width / 2
	const cy = MAP.height / 2
	const busiest = Math.max(1, ...islands.map((island) => island.count))
	// a turn's worth, starting a little off the top so no island sits right
	// above home where its label would run into the home island's
	const step = (2 * Math.PI) / Math.max(islands.length, 1)
	const start = -Math.PI / 2 + step / 2

	return islands.map((island, index) => {
		const angle = start + index * step
		const x = round(cx + Math.cos(angle) * 240)
		const y = round(cy + Math.sin(angle) * 100)
		const r = round(14 + 12 * Math.sqrt(island.count / busiest))

		// a gentle bend, always to the same side, so the routes swirl
		const mx = (x + cx) / 2
		const my = (y + cy) / 2
		const bend = 0.18
		const qx = round(mx - (cy - y) * bend)
		const qy = round(my + (cx - x) * bend)

		return {
			...island,
			x,
			y,
			r,
			// drawn from the island towards home: posts travel that way
			route: `M${x} ${y}Q${qx} ${qy} ${cx} ${cy}`,
			canoe: { x: round(along(x, qx, cx, CANOE_AT)), y: round(along(y, qy, cy, CANOE_AT)) },
		}
	})
}

/**
 * An island seen from the side: a hump of sand on the water.
 *
 * @param {number} x the middle
 * @param {number} y where it meets the water
 * @param {number} r half its width
 * @return {string} path data
 */
export function islandPath(x, y, r) {
	const h = round(r * 0.8)
	return `M${round(x - r)} ${y}Q${round(x - r * 0.55)} ${round(y - h)} ${x} ${round(y - h)}T${round(x + r)} ${y}Z`
}

/** how far along its route, from the island, the canoe is: clear of the home island's palm */
const CANOE_AT = 0.35

/**
 * One coordinate of a point on a quadratic curve.
 *
 * @param {number} from where the curve starts
 * @param {number} control its control point
 * @param {number} to where it ends
 * @param {number} t how far along, 0 to 1
 * @return {number}
 */
function along(from, control, to, t) {
	return (1 - t) ** 2 * from + 2 * (1 - t) * t * control + t ** 2 * to
}

/**
 * @param {number} value a coordinate
 * @return {number} to a tenth, which is finer than a pixel and keeps the markup short
 */
function round(value) {
	return Math.round(value * 10) / 10
}
