/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import BlueskyTrends from '../../../src/components/BlueskyTrends.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('../../../src/services/logger.js', () => ({ default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() } }))

const RouterLink = { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' }

describe('BlueskyTrends', () => {
	it('opens a trending feed as a feed and any other topic as a search', async () => {
		get.mockResolvedValue({ data: { trends: [
			{ topic: 'eclipse', label: 'Eclipse', feed: 'at://did:plc:trending/app.bsky.feed.generator/665', search: '' },
			{ topic: 'cats', label: 'Cats', feed: '', search: '#cats' },
		] } })

		const wrapper = mount(BlueskyTrends, { global: { stubs: { RouterLink } } })
		await flushPromises()
		const links = wrapper.findAllComponents(RouterLink)

		expect(links.map((link) => link.text())).toEqual(['Eclipse', 'Cats'])
		expect(links[0].props('to')).toEqual({ name: 'bluesky-feed', params: { did: 'did:plc:trending', kind: 'feed', rkey: '665' } })
		expect(links[1].props('to')).toEqual({ name: 'search', params: { term: '#cats' } })
	})

	it('shows nothing when Bluesky has no trends to tell', async () => {
		get.mockResolvedValue({ data: { trends: [] } })

		const wrapper = mount(BlueskyTrends, { global: { stubs: { RouterLink } } })
		await flushPromises()

		expect(wrapper.find('.bluesky-trends').exists()).toBe(false)
	})
})
