/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { MAX_ENTRIES, MIN_ENTRIES, ROW_HEIGHT, capacityFrom, chooseEntries, entriesThatFit, shareOut } from '../../../src/utils/explore.js'

const tags = (n) => Array.from({ length: n }, (_, i) => ({ name: `tag${i}` }))
const lists = (n) => Array.from({ length: n }, (_, i) => ({ id: String(i), title: `list${i}` }))

describe('capacityFrom', () => {
	it('fits as many whole rows as the space holds', () => {
		expect(capacityFrom({ free: 440, rowHeight: 44 })).toBe(10)
		expect(capacityFrom({ free: 439, rowHeight: 44 })).toBe(9)
	})

	// more room, more entries: the whole point of measuring
	it('shows more as the space grows', () => {
		const small = capacityFrom({ free: 200, rowHeight: 44 })
		const larger = capacityFrom({ free: 350, rowHeight: 44 })

		expect(larger).toBeGreaterThan(small)
	})

	// a denser theme or a browser zoom changes what a row measures
	it('fits more of a shorter row into the same space', () => {
		const tall = capacityFrom({ free: 300, rowHeight: 50 })
		const short = capacityFrom({ free: 300, rowHeight: 30 })

		expect(short).toBeGreaterThan(tall)
	})

	it('keeps the ceiling and the floor', () => {
		expect(capacityFrom({ free: 9999, rowHeight: 44 })).toBe(MAX_ENTRIES)
		expect(capacityFrom({ free: 10, rowHeight: 44 })).toBe(MIN_ENTRIES)
		expect(capacityFrom({ free: -500, rowHeight: 44 })).toBe(MIN_ENTRIES)
	})

	it('falls back to a standard row when nothing could be measured', () => {
		expect(capacityFrom({ free: 5 * ROW_HEIGHT, rowHeight: 0 })).toBe(5)
		expect(capacityFrom({ free: 5 * ROW_HEIGHT, rowHeight: undefined })).toBe(5)
	})

	it('falls back to the ceiling when the space makes no sense', () => {
		expect(capacityFrom({ free: Number.NaN, rowHeight: 44 })).toBe(MAX_ENTRIES)
		expect(capacityFrom({ free: undefined, rowHeight: 44 })).toBe(MAX_ENTRIES)
	})
})

describe('entriesThatFit', () => {
	it('never shows more than the ceiling, however tall the screen', () => {
		expect(entriesThatFit(2000)).toBe(MAX_ENTRIES)
		expect(entriesThatFit(1080)).toBe(MAX_ENTRIES)
	})

	it('shows fewer as the window gets shorter', () => {
		const tall = entriesThatFit(1000)
		const middling = entriesThatFit(800)
		const short = entriesThatFit(650)

		expect(tall).toBeGreaterThanOrEqual(middling)
		expect(middling).toBeGreaterThan(short)
	})

	// below this the entry stops being a way to reach anything
	it('never goes below the floor, however short the screen', () => {
		expect(entriesThatFit(500)).toBe(MIN_ENTRIES)
		expect(entriesThatFit(200)).toBe(MIN_ENTRIES)
		expect(entriesThatFit(1)).toBe(MIN_ENTRIES)
	})

	it('falls back to the ceiling when the height makes no sense', () => {
		expect(entriesThatFit(0)).toBe(MAX_ENTRIES)
		expect(entriesThatFit(-10)).toBe(MAX_ENTRIES)
		expect(entriesThatFit(undefined)).toBe(MAX_ENTRIES)
		expect(entriesThatFit(Number.NaN)).toBe(MAX_ENTRIES)
	})
})

describe('shareOut', () => {
	it('shows everything when it all fits', () => {
		expect(shareOut(3, 2, 12)).toEqual({ tags: 3, lists: 2 })
		expect(shareOut(6, 6, 12)).toEqual({ tags: 6, lists: 6 })
	})

	it('fills the room exactly when it does not', () => {
		const share = shareOut(20, 20, 7)

		expect(share.tags + share.lists).toBe(7)
	})

	/**
	 * A truncation that drops a whole kind reads as the kind having gone
	 * missing rather than as there being too little room.
	 */
	it('keeps a place for each kind when both have entries', () => {
		const share = shareOut(30, 1, 6)

		expect(share.lists).toBeGreaterThanOrEqual(1)
		expect(share.tags).toBeGreaterThanOrEqual(1)
		expect(share.tags + share.lists).toBe(6)
	})

	it('keeps the one tag when the lists are many', () => {
		const share = shareOut(1, 30, 6)

		expect(share.tags).toBe(1)
		expect(share.lists).toBe(5)
	})

	it('divides the room in proportion to what there is', () => {
		const share = shareOut(9, 3, 8)

		// two thirds tags, one third lists, and every place used
		expect(share.tags + share.lists).toBe(8)
		expect(share.tags).toBeGreaterThan(share.lists)
	})

	it('gives one kind everything when the other has none', () => {
		expect(shareOut(20, 0, 5)).toEqual({ tags: 5, lists: 0 })
		expect(shareOut(0, 20, 5)).toEqual({ tags: 0, lists: 5 })
	})

	it('never asks for more of a kind than there is', () => {
		const share = shareOut(2, 40, 12)

		expect(share.tags).toBeLessThanOrEqual(2)
		expect(share.tags + share.lists).toBe(12)
	})

	it('copes with no room and with nothing to show', () => {
		expect(shareOut(5, 5, 0)).toEqual({ tags: 0, lists: 0 })
		expect(shareOut(0, 0, 12)).toEqual({ tags: 0, lists: 0 })
	})

	it('uses its one place on a tag when there is only one', () => {
		expect(shareOut(5, 5, 1)).toEqual({ tags: 1, lists: 0 })
	})
})

describe('chooseEntries', () => {
	const trends = (n) => Array.from({ length: n }, (_, i) => ({ name: `trend${i}` }))

	it('puts the hashtags first, then the lists, then what is trending', () => {
		const entries = chooseEntries(tags(2), lists(2), trends(2), 12)

		expect(entries.map((e) => e.kind)).toEqual(['tag', 'tag', 'list', 'list', 'trend', 'trend'])
	})

	it('carries the thing itself, so the sidebar can draw it', () => {
		const [first] = chooseEntries(tags(1), [], [], 12)

		expect(first.tag.name).toBe('tag0')
	})

	it('never returns more than the room allows', () => {
		expect(chooseEntries(tags(30), lists(30), trends(30), 12)).toHaveLength(12)
		expect(chooseEntries(tags(30), lists(30), trends(30), 4)).toHaveLength(4)
	})

	/**
	 * The reader chose the tags they follow and the lists they made; nobody
	 * chose what is trending. So trending fills what is left and never takes a
	 * place from either — otherwise a busy day on the instance would push
	 * somebody's own follows out of their own sidebar.
	 */
	it('gives trending only the room the chosen things leave', () => {
		const entries = chooseEntries(tags(6), lists(6), trends(10), 12)

		expect(entries.filter((e) => e.kind === 'trend')).toHaveLength(0)
	})

	it('fills the rest with trending when there is room', () => {
		const entries = chooseEntries(tags(1), lists(1), trends(10), 6)

		expect(entries.map((e) => e.kind)).toEqual(['tag', 'list', 'trend', 'trend', 'trend', 'trend'])
	})

	/** The same tag twice, once as a follow and once as a trend, reads as a bug. */
	it('never offers a trending tag the reader already follows', () => {
		const entries = chooseEntries([{ name: 'design' }], [], [{ name: 'Design' }, { name: 'berlin' }], 12)

		expect(entries.map((e) => `${e.kind}:${e.tag.name}`)).toEqual(['tag:design', 'trend:berlin'])
	})

	it('answers for nothing rather than throwing', () => {
		expect(chooseEntries(undefined, undefined, undefined, 12)).toEqual([])
		expect(chooseEntries([], [], [], 12)).toEqual([])
	})

	it('gives the Bluesky feeds the reader keeps a share of the lists\' room, after the lists', () => {
		const feeds = [{ uri: 'at://did:plc:bob/app.bsky.feed.generator/cats', name: 'Cats' }]

		expect(chooseEntries(tags(1), lists(1), trends(1), 12, feeds).map((e) => e.kind)).toEqual(['tag', 'list', 'feed', 'trend'])
		expect(chooseEntries(tags(1), lists(1), trends(1), 2, feeds).map((e) => e.kind)).toEqual(['tag', 'list'])
	})
})
