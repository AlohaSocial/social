/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { buildGraph, settle, step } from '../../../src/utils/constellation.js'

const suggestion = (acct, via = []) => ({ account: { acct, display_name: acct }, via })

describe('buildGraph', () => {
	it('puts the reader in the middle and holds them there', () => {
		const { nodes } = buildGraph([])

		expect(nodes).toEqual([expect.objectContaining({ id: 'you', x: 0.5, y: 0.5, fixed: true })])
	})

	it('ties each suggestion to the people it came through, each of them once', () => {
		const { nodes, links } = buildGraph([
			suggestion('ana@a.example', ['bo', 'cy']),
			suggestion('dee@d.example', ['bo']),
		])

		expect(nodes.filter((node) => node.kind === 'via').map((node) => node.handle)).toEqual(['bo', 'cy'])
		expect(links).toEqual(expect.arrayContaining([
			expect.objectContaining({ source: 'you', target: 'v:bo' }),
			expect.objectContaining({ source: 'v:bo', target: 's:ana@a.example' }),
			expect.objectContaining({ source: 'v:cy', target: 's:ana@a.example' }),
			expect.objectContaining({ source: 'v:bo', target: 's:dee@d.example' }),
		]))
		expect(links.filter((link) => link.target === 'v:bo')).toHaveLength(1)
	})

	it('ties a suggestion that names nobody straight to the reader, further out', () => {
		const { links } = buildGraph([suggestion('ana@a.example')])

		expect(links).toEqual([expect.objectContaining({ source: 'you', target: 's:ana@a.example', length: 0.34 })])
	})

	it('skips a suggestion with no handle', () => {
		expect(buildGraph([{ account: {} }, null]).nodes).toHaveLength(1)
	})

	/** the same suggestions come up in the same shape each time */
	it('starts from the same places for the same seed', () => {
		const a = buildGraph([suggestion('ana', ['bo'])], { seed: 3 }).nodes.map(({ x, y }) => [x, y])
		const b = buildGraph([suggestion('ana', ['bo'])], { seed: 3 }).nodes.map(({ x, y }) => [x, y])

		expect(a).toEqual(b)
	})
})

describe('the layout', () => {
	const many = Array.from({ length: 12 }, (_, index) => suggestion(`p${index}@x.example`, [`via${index % 3}`]))

	it('settles, and stops moving', () => {
		const { nodes, links } = buildGraph(many)

		expect(settle(nodes, links)).toBeLessThan(400)
		expect(step(nodes, links)).toBeLessThan(0.001)
	})

	it('keeps every node on the card', () => {
		const { nodes, links } = buildGraph(many)
		settle(nodes, links)

		expect(nodes.every((node) => node.x > 0 && node.x < 1 && node.y > 0 && node.y < 1)).toBe(true)
	})

	it('never moves the reader', () => {
		const { nodes, links } = buildGraph(many)
		settle(nodes, links)

		expect(nodes[0]).toEqual(expect.objectContaining({ x: 0.5, y: 0.5 }))
	})

	/** a sky where everything lands on one spot is a pile, not a sky */
	it('spreads the nodes out rather than piling them up', () => {
		const { nodes, links } = buildGraph(many)
		settle(nodes, links)

		let closest = Infinity
		for (let i = 0; i < nodes.length; i++) {
			for (let j = i + 1; j < nodes.length; j++) {
				closest = Math.min(closest, Math.hypot(nodes[i].x - nodes[j].x, nodes[i].y - nodes[j].y))
			}
		}
		expect(closest).toBeGreaterThan(0.03)
	})
})
