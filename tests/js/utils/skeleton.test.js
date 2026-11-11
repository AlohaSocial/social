/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

import { skeletonFor, timelineSkeleton } from '../../../src/utils/skeleton.js'

const layout = vi.hoisted(() => ({ value: 'list' }))
vi.mock('../../../src/services/photoLayout.js', () => ({ photoLayout: () => layout.value }))

describe('skeletonFor', () => {
	it('is the neutral page for a route that names nothing', () => {
		expect(skeletonFor({ meta: {} })).toEqual({ variant: 'page', shape: 'square' })
		expect(skeletonFor({})).toEqual({ variant: 'page', shape: 'square' })
		expect(skeletonFor(undefined)).toEqual({ variant: 'page', shape: 'square' })
	})

	it('takes a variant by name', () => {
		expect(skeletonFor({ meta: { skeleton: 'rows' } })).toEqual({ variant: 'rows', shape: 'square' })
		expect(skeletonFor({ meta: { skeleton: 'grid' } })).toEqual({ variant: 'grid', shape: 'square' })
	})

	it('takes a variant and a shape', () => {
		expect(skeletonFor({ meta: { skeleton: { variant: 'grid', shape: 'video' } } })).toEqual({ variant: 'grid', shape: 'video' })
	})

	it('asks a function, with the route', () => {
		const route = { params: { type: 'x' }, meta: { skeleton: (to) => (to.params.type === 'x' ? 'rows' : 'page') } }

		expect(skeletonFor(route)).toEqual({ variant: 'rows', shape: 'square' })
	})

	it('falls back to the page for a name it does not know', () => {
		expect(skeletonFor({ meta: { skeleton: 'cards' } })).toEqual({ variant: 'page', shape: 'square' })
		expect(skeletonFor({ meta: { skeleton: { variant: 'rows', shape: 'video' } } })).toEqual({ variant: 'rows', shape: 'square' })
	})
})

describe('timelineSkeleton', () => {
	afterEach(() => {
		layout.value = 'list'
	})

	it('draws rows for the feeds', () => {
		for (const type of [undefined, 'home', 'timeline', 'federated', 'notifications', 'interests']) {
			expect(timelineSkeleton({ params: { type } })).toBe('rows')
		}
	})

	it('draws video cards for Videos', () => {
		expect(timelineSkeleton({ params: { type: 'videos' } })).toEqual({ variant: 'grid', shape: 'video' })
	})

	it('draws tiles for Photos only where the reader chose the grid', () => {
		expect(timelineSkeleton({ params: { type: 'photos' } })).toBe('rows')
		layout.value = 'grid'
		expect(timelineSkeleton({ params: { type: 'photos' } })).toBe('grid')
	})

	it('draws the neutral page for Direct messages, which is a chat', () => {
		expect(timelineSkeleton({ params: { type: 'direct' } })).toBe('page')
	})
})
