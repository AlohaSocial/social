/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { pageDirection, pageIdentity, pageRank } from './pageOrder.js'

const at = (name, type) => ({ name, params: type === undefined ? {} : { type } })

describe('pageIdentity', () => {
	it('tells the six timelines apart by their type', () => {
		expect(pageIdentity(at('timeline'))).toBe('timeline:')
		expect(pageIdentity(at('timeline', 'photos'))).toBe('timeline:photos')
		expect(pageIdentity(at('timeline', 'direct'))).toBe('timeline:direct')
	})

	it('is the route name for everything else', () => {
		expect(pageIdentity(at('settings'))).toBe('settings')
		expect(pageIdentity({})).toBe('')
	})
})

describe('pageRank', () => {
	it('puts the pages in the order the sidebar lists them', () => {
		expect(pageRank(at('timeline'))).toBeLessThan(pageRank(at('timeline', 'photos')))
		expect(pageRank(at('timeline', 'photos'))).toBeLessThan(pageRank(at('discover')))
		expect(pageRank(at('discover'))).toBeLessThan(pageRank(at('settings')))
	})

	/**
	 * A post, a tag or a profile is somewhere you go from the sidebar, so it sits
	 * past the end and moving to one reads as going forward.
	 */
	it('puts what the sidebar does not list past the end', () => {
		expect(pageRank(at('single-post'))).toBeGreaterThan(pageRank(at('settings')))
		expect(pageRank(at('tags'))).toBe(pageRank(at('single-post')))
	})
})

describe('pageDirection', () => {
	it('goes forward down the sidebar and back up it', () => {
		expect(pageDirection(at('settings'), at('timeline'))).toBe('forward')
		expect(pageDirection(at('timeline'), at('settings'))).toBe('back')
		expect(pageDirection(at('timeline', 'videos'), at('timeline', 'photos'))).toBe('forward')
		expect(pageDirection(at('timeline', 'photos'), at('timeline', 'videos'))).toBe('back')
	})

	it('gives no direction for the same page', () => {
		expect(pageDirection(at('settings'), at('settings'))).toBe('')
		expect(pageDirection(at('timeline', 'photos'), at('timeline', 'photos'))).toBe('')
	})

	/** Two pages the sidebar does not list share a rank, so there is no direction between them. */
	it('gives no direction between two pages the sidebar does not list', () => {
		expect(pageDirection(at('single-post'), at('tags'))).toBe('')
	})

	it('gives no direction when there is nowhere to have come from', () => {
		expect(pageDirection(at('settings'), undefined)).toBe('')
		expect(pageDirection(at('settings'), null)).toBe('')
	})

	it('reads a move out to a post as forward, and back again as back', () => {
		expect(pageDirection(at('single-post'), at('timeline'))).toBe('forward')
		expect(pageDirection(at('timeline'), at('single-post'))).toBe('back')
	})
})
