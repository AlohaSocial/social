/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import ProfileMosaic from '../../../src/components/ProfileMosaic.vue'
import { useTimelineStore } from '../../../src/store/timeline.js'

const PHOTOS = { name: 'profile', params: { account: 'bob' }, query: { media: 'image' } }

const GridStub = {
	name: 'ProfileMediaGrid',
	props: ['posts', 'account', 'columns'],
	template: '<ul class="grid-stub"><li v-for="post in posts" :key="post.id" class="tile-stub">{{ post.id }}</li></ul>',
}

/**
 * @param {number} count how many posts with a picture the account has
 * @return {object[]} its newest posts, newest first
 */
function photoPosts(count) {
	return Array.from({ length: count }, (_, index) => ({
		id: String(100 - index),
		created_at: '2026-11-01T10:00:00Z',
		account: { acct: 'bob' },
		media_attachments: [{ type: 'image', url: `https://example.org/${index}.jpg` }],
	}))
}

let pinia

function mountMosaic(account = 'bob') {
	return mount(ProfileMosaic, {
		props: { account, to: PHOTOS },
		global: {
			plugins: [pinia],
			stubs: { ProfileMediaGrid: GridStub, RouterLink: RouterLinkStub },
		},
	})
}

const tiles = (wrapper) => wrapper.findAll('.tile-stub').map((tile) => tile.text())

describe('ProfileMosaic', () => {
	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('asks for the first page of the Photos tab, the way the tab would', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: photoPosts(3) })
		mountMosaic()
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(1)
		expect(get.mock.calls[0][0]).toContain('/api/v1/accounts/bob/statuses')
		expect(get.mock.calls[0][1].params).toMatchObject({ only_media: true, media_type: 'image' })
	})

	it.each([
		[0, 0],
		[2, 0],
		[3, 3],
		[5, 3],
		[8, 6],
		[15, 9],
	])('draws %i pictures as %i squares: whole rows, three at most', async (count, shown) => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: photoPosts(count) })
		const wrapper = mountMosaic()
		await flushPromises()

		expect(tiles(wrapper)).toHaveLength(shown)
		expect(wrapper.find('section').exists()).toBe(shown > 0)
	})

	it('lays the squares out three across, newest first', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: photoPosts(4) })
		const wrapper = mountMosaic()
		await flushPromises()

		expect(wrapper.findComponent(GridStub).props('columns')).toBe(3)
		expect(tiles(wrapper)).toEqual(['100', '99', '98'])
	})

	it('leads to the Photos tab from its heading', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: photoPosts(3) })
		const wrapper = mountMosaic()
		await flushPromises()

		const heading = wrapper.find('h2')
		expect(heading.text()).toBe('Photos')
		expect(heading.findComponent(RouterLinkStub).props('to')).toEqual(PHOTOS)
	})

	it('does not ask again for pictures it already holds', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: photoPosts(3) })
		mountMosaic().unmount()
		await flushPromises()

		const again = mountMosaic()
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(1)
		expect(tiles(again)).toHaveLength(3)
	})

	it('hands the Photos tab the same page, so opening it asks nothing', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: photoPosts(3) })
		mountMosaic()
		await flushPromises()

		useTimelineStore().changeTimelineTypeAccount('bob', 'image')

		expect(useTimelineStore().timeline).toEqual(['100', '99', '98'])
		expect(get).toHaveBeenCalledTimes(1)
	})

	it('draws nothing when the server would not say', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('down'))
		const wrapper = mountMosaic()
		await flushPromises()

		expect(wrapper.find('section').exists()).toBe(false)
	})
})
