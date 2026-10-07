/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import FederationIslands from '../../../src/components/FederationIslands.vue'
import { useAccountStore } from '../../../src/store/account.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const ME = { id: '7', acct: 'alice', url: 'https://cloud.example/@alice', avatar: '/avatar/alice' }
const FOLLOWING = '/index.php/apps/social/api/v1/accounts/7/following'

/**
 * @param {Array<object>|Error} answer what the following route answers
 * @return {Promise<object>} the mounted map, once it has read the follows
 */
async function mountMap(answer) {
	const pinia = createPinia()
	setActivePinia(pinia)
	const store = useAccountStore()
	store.addAccount({ actorId: ME.url, data: ME })
	store.setCurrentAccount('alice@cloud.example')
	axios.get.mockImplementation(() => (answer instanceof Error ? Promise.reject(answer) : Promise.resolve({ data: answer })))

	const wrapper = mount(FederationIslands, { global: { plugins: [pinia] } })
	await flushPromises()
	return wrapper
}

describe('FederationIslands', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('draws an island for every server the reader follows people on, with a route home', async () => {
		const wrapper = await mountMap([
			{ id: '1', acct: 'maya@mastodon.social' },
			{ id: '2', acct: 'kai@mastodon.social' },
			{ id: '3', acct: 'lena@pixelfed.social' },
			{ id: '4', acct: 'bob' },
		])

		expect(axios.get).toHaveBeenCalledWith(FOLLOWING, { params: { limit: 50 } })
		const labels = wrapper.findAll('.islands__island .islands__label').map((label) => label.text())
		expect(labels).toEqual(['mastodon.social', 'pixelfed.social'])
		expect(wrapper.findAll('.islands__route')).toHaveLength(2)
		expect(wrapper.find('.islands__home .islands__label').text()).toBe('cloud.example')
		expect(wrapper.find('.islands__summary').text()).toBe('You follow people on 2 other islands.')
	})

	it('says the same thing to a screen reader as it shows', async () => {
		const wrapper = await mountMap([{ id: '1', acct: 'maya@mastodon.social' }])
		const map = wrapper.find('svg.islands__map')

		expect(map.attributes('role')).toBe('img')
		expect(map.attributes('aria-label')).toBe(wrapper.find('.islands__summary').text())
	})

	it('reads the next page from where the last one ended', async () => {
		const page = Array.from({ length: 50 }, (_, i) => ({ id: String(100 - i), acct: `p${i}@far.example` }))
		const pinia = createPinia()
		setActivePinia(pinia)
		const store = useAccountStore()
		store.addAccount({ actorId: ME.url, data: ME })
		store.setCurrentAccount('alice@cloud.example')
		axios.get.mockResolvedValueOnce({ data: page }).mockResolvedValueOnce({ data: [] })

		mount(FederationIslands, { global: { plugins: [pinia] } })
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(FOLLOWING, { params: { limit: 50, max_id: '51' } })
	})

	it('invites a first route when everybody followed is at home', async () => {
		const wrapper = await mountMap([{ id: '4', acct: 'bob' }])

		expect(wrapper.findAll('.islands__island')).toHaveLength(0)
		expect(wrapper.find('.islands__home').exists()).toBe(true)
		expect(wrapper.find('.islands__summary').text()).toContain('Nobody you follow lives on another island yet')
	})

	it('draws the home island alone and says why when the follows cannot be read', async () => {
		const wrapper = await mountMap(new Error('network'))

		expect(wrapper.findAll('.islands__island')).toHaveLength(0)
		expect(wrapper.find('.islands__summary').text()).toContain('Could not read who you follow')
	})
})
