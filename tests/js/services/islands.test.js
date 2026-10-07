/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { MAP, islandPath, islandsFrom, placeIslands, serverOf } from '../../../src/services/islands.js'

const follow = (acct) => ({ acct })

describe('serverOf', () => {
	it('reads the server out of a handle', () => {
		expect(serverOf(follow('maya@mastodon.social'), 'cloud.example')).toBe('mastodon.social')
	})

	it('counts an account on the reader\'s own server as home, however it is spelled', () => {
		expect(serverOf(follow('bob'), 'cloud.example')).toBe('')
		expect(serverOf(follow('bob@Cloud.Example'), 'cloud.example')).toBe('')
	})

	it('treats an account without a handle as home rather than as a server called ""', () => {
		expect(serverOf({}, 'cloud.example')).toBe('')
		expect(serverOf(follow('@'), 'cloud.example')).toBe('')
	})
})

describe('islandsFrom', () => {
	const follows = [
		'a@mastodon.social',
		'b@mastodon.social',
		'c@mastodon.social',
		'd@pixelfed.social',
		'e@pixelfed.social',
		'f@peertube.tv',
		'local',
		'g@cloud.example',
	].map(follow)

	it('groups follows by server, the busiest first', () => {
		const { islands, local, more } = islandsFrom(follows, 'cloud.example')

		expect(islands).toEqual([
			{ host: 'mastodon.social', count: 3 },
			{ host: 'pixelfed.social', count: 2 },
			{ host: 'peertube.tv', count: 1 },
		])
		expect(local).toBe(2)
		expect(more).toBe(0)
	})

	it('keeps as many islands as the map has room for and counts the rest', () => {
		const { islands, more } = islandsFrom(follows, 'cloud.example', 2)

		expect(islands.map((island) => island.host)).toEqual(['mastodon.social', 'pixelfed.social'])
		expect(more).toBe(1)
	})

	it('puts servers with as many follows in a stable order', () => {
		const { islands } = islandsFrom(['x@b.example', 'y@a.example'].map(follow), 'cloud.example')

		expect(islands.map((island) => island.host)).toEqual(['a.example', 'b.example'])
	})
})

describe('placeIslands', () => {
	const islands = [
		{ host: 'mastodon.social', count: 9 },
		{ host: 'pixelfed.social', count: 4 },
		{ host: 'peertube.tv', count: 1 },
	]

	it('puts every island on the map, around home and not on it', () => {
		for (const island of placeIslands(islands)) {
			expect(island.x).toBeGreaterThan(island.r)
			expect(island.x).toBeLessThan(MAP.width - island.r)
			expect(island.y).toBeGreaterThan(island.r)
			expect(island.y).toBeLessThan(MAP.height - island.r)
			expect(Math.hypot(island.x - MAP.width / 2, island.y - MAP.height / 2)).toBeGreaterThan(90)
		}
	})

	it('draws the island where more of the reader\'s people live larger', () => {
		const [big, middle, small] = placeIslands(islands)

		expect(big.r).toBeGreaterThan(middle.r)
		expect(middle.r).toBeGreaterThan(small.r)
	})

	it('runs each route from its island to home, with the canoe on the way', () => {
		const [island] = placeIslands(islands)

		expect(island.route.startsWith(`M${island.x} ${island.y}`)).toBe(true)
		expect(island.route.endsWith(`${MAP.width / 2} ${MAP.height / 2}`)).toBe(true)
		const between = (value, a, b) => value >= Math.min(a, b) - 40 && value <= Math.max(a, b) + 40
		expect(between(island.canoe.x, island.x, MAP.width / 2)).toBe(true)
		expect(between(island.canoe.y, island.y, MAP.height / 2)).toBe(true)
	})

	it('draws nothing for nobody', () => {
		expect(placeIslands([])).toEqual([])
	})
})

describe('islandPath', () => {
	it('is a closed hump standing on the water line', () => {
		const path = islandPath(100, 50, 20)

		expect(path.startsWith('M80 50')).toBe(true)
		expect(path.endsWith('T120 50Z')).toBe(true)
	})
})
