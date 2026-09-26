/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The follow suggestions as a small sky: the reader in the middle, the people
 * they follow who led somewhere around them, and the suggestions at the edge,
 * each tied to whoever it came through.
 *
 * A force layout, and a deliberately small one: every node pushes every other
 * away, every tie pulls its two ends to a resting length, and a weak pull to
 * the middle keeps the whole thing on the card. It runs in the browser for a
 * second or two and then stops; twenty suggestions and a handful of the
 * reader's follows is a few hundred pairs, which is nothing.
 *
 * Positions are in a unit square, 0 to 1 each way, so the same layout draws at
 * whatever size the card happens to be.
 */

/** how hard nodes push each other apart */
const REPULSION = 0.0001

/** how hard a tie pulls back towards its resting length */
const SPRING = 0.06

/** how hard everything is pulled to the middle */
const GRAVITY = 0.02

/** how much of its speed a node keeps from one step to the next */
const DAMPING = 0.82

/**
 * Added under the distance before the push is worked out, so two nodes that
 * start on almost the same spot push each other apart firmly rather than with
 * a force that flings them into the corners.
 */
const SOFTENING = 0.004

/** the furthest a node may travel in one step */
const MAX_SPEED = 0.02

/** the resting length of a tie, by what it connects */
const LENGTH = {
	via: 0.2,
	suggestion: 0.16,
	direct: 0.34,
}

/** how close to an edge a node may be drawn, so its picture is not cut off */
const MARGIN = 0.07

/**
 * The seeded source of numbers the first positions come from, so the same
 * suggestions come up in the same shape each time the card is opened.
 *
 * @param {number} seed where to start
 * @return {() => number} numbers in [0, 1)
 */
function seeded(seed) {
	let state = seed >>> 0 || 1

	return () => {
		state = (state * 1664525 + 1013904223) >>> 0

		return state / 4294967296
	}
}

/**
 * Turns the suggestions the server sent into nodes and ties.
 *
 * @param {Array<{ account: object, via?: string[] }>} suggestions as the follow-graph route answers
 * @param {object} [options] options
 * @param {number} [options.seed] where the first positions come from
 * @return {{ nodes: object[], links: object[] }} the sky, before it has settled
 */
export function buildGraph(suggestions, { seed = 7 } = {}) {
	const random = seeded(seed)
	const nodes = [{ id: 'you', kind: 'you', x: 0.5, y: 0.5, vx: 0, vy: 0, fixed: true }]
	const links = []
	const vias = new Map()

	const place = (radius) => {
		const angle = random() * Math.PI * 2

		return { x: 0.5 + Math.cos(angle) * radius, y: 0.5 + Math.sin(angle) * radius }
	}

	for (const suggestion of Array.isArray(suggestions) ? suggestions : []) {
		const acct = suggestion?.account?.acct
		if (typeof acct !== 'string' || acct === '') {
			continue
		}

		const node = { id: 's:' + acct, kind: 'suggestion', account: suggestion.account, ...place(0.4), vx: 0, vy: 0, fixed: false }
		nodes.push(node)

		const through = Array.isArray(suggestion.via) ? suggestion.via.filter((handle) => typeof handle === 'string' && handle !== '') : []
		if (through.length === 0) {
			// nobody named to go through: tied to the reader directly, further out
			links.push({ source: 'you', target: node.id, length: LENGTH.direct })
			continue
		}

		for (const handle of through) {
			if (!vias.has(handle)) {
				const via = { id: 'v:' + handle, kind: 'via', handle, ...place(0.2), vx: 0, vy: 0, fixed: false }
				vias.set(handle, via)
				nodes.push(via)
				links.push({ source: 'you', target: via.id, length: LENGTH.via })
			}
			links.push({ source: 'v:' + handle, target: node.id, length: LENGTH.suggestion })
		}
	}

	return { nodes, links }
}

/**
 * Moves every node one step along the forces on it.
 *
 * @param {object[]} nodes the nodes, changed in place
 * @param {object[]} links the ties between them
 * @return {number} how far the nodes moved in total, which falls to nothing as
 *                  the sky settles
 */
export function step(nodes, links) {
	const byId = new Map(nodes.map((node) => [node.id, node]))

	for (let i = 0; i < nodes.length; i++) {
		for (let j = i + 1; j < nodes.length; j++) {
			const a = nodes[i]
			const b = nodes[j]
			let dx = b.x - a.x
			let dy = b.y - a.y
			let distance2 = dx * dx + dy * dy
			if (distance2 < 1e-6) {
				// two nodes on the same spot: nudged apart so the push has a direction
				dx = 0.001 * (i - j)
				dy = 0.001
				distance2 = dx * dx + dy * dy
			}
			const distance = Math.sqrt(distance2)
			const push = REPULSION / (distance2 + SOFTENING)
			const fx = (dx / distance) * push
			const fy = (dy / distance) * push
			a.vx -= fx
			a.vy -= fy
			b.vx += fx
			b.vy += fy
		}
	}

	for (const link of links) {
		const a = byId.get(link.source)
		const b = byId.get(link.target)
		if (!a || !b) {
			continue
		}
		const dx = b.x - a.x
		const dy = b.y - a.y
		const distance = Math.sqrt(dx * dx + dy * dy) || 0.001
		const pull = (distance - link.length) * SPRING
		const fx = (dx / distance) * pull
		const fy = (dy / distance) * pull
		a.vx += fx
		a.vy += fy
		b.vx -= fx
		b.vy -= fy
	}

	let moved = 0
	for (const node of nodes) {
		if (node.fixed) {
			node.vx = 0
			node.vy = 0
			continue
		}

		node.vx = (node.vx + (0.5 - node.x) * GRAVITY) * DAMPING
		node.vy = (node.vy + (0.5 - node.y) * GRAVITY) * DAMPING
		const speed = Math.hypot(node.vx, node.vy)
		if (speed > MAX_SPEED) {
			node.vx *= MAX_SPEED / speed
			node.vy *= MAX_SPEED / speed
		}
		const x = node.x + node.vx
		const y = node.y + node.vy
		node.x = Math.min(1 - MARGIN, Math.max(MARGIN, x))
		node.y = Math.min(1 - MARGIN, Math.max(MARGIN, y))
		// a node that ran into the edge stops there, rather than pressing on
		// it for ever and keeping the whole sky from ever coming to rest
		if (node.x !== x) {
			node.vx = 0
		}
		if (node.y !== y) {
			node.vy = 0
		}
		moved += Math.abs(node.vx) + Math.abs(node.vy)
	}

	return moved
}

/**
 * Runs the layout until it has settled, all at once, for a reader who asked
 * for no motion and for the tests.
 *
 * @param {object[]} nodes the nodes, changed in place
 * @param {object[]} links the ties
 * @param {number} [limit] the most steps to take
 * @return {number} how many steps it took
 */
export function settle(nodes, links, limit = 400) {
	for (let count = 1; count <= limit; count++) {
		if (step(nodes, links) < 0.0005) {
			return count
		}
	}

	return limit
}
