/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import PostReactedBy, { REFILL_MS } from '../../../src/components/PostReactedBy.vue'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

const account = (acct) => ({ id: `https://cloud.example.org/users/${acct}`, acct, username: acct, display_name: acct, avatar: '' })
const post = (overrides = {}) => ({ id: '42', reblogs_count: 0, favourites_count: 0, ...overrides })

/**
 * Answers each of the two endpoints with the accounts given for it.
 *
 * @param {object} lists `{ reblogged_by, favourited_by }`, either one optional
 */
function serve({ reblogged_by: boosted = [], favourited_by: favourited = [] } = {}) {
	axios.get.mockImplementation(async (url) => ({
		data: url.endsWith('/reblogged_by') ? boosted : favourited,
	}))
}

function mountReactions(status, serverData = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData({ public: false, ...serverData })

	return mount(PostReactedBy, {
		props: { status },
		global: { plugins: [pinia], stubs: { RouterLink: RouterLinkStub } },
	})
}

describe('PostReactedBy', () => {
	beforeEach(() => {
		axios.get.mockReset()
		serve()
	})

	afterEach(() => {
		vi.clearAllMocks()
	})

	it('asks for nothing when nobody has reacted', async () => {
		const wrapper = mountReactions(post())
		await flushPromises()

		// most posts are this post: the page should not cost two requests to
		// find out that both counts are zero
		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.find('.reacted-by').exists()).toBe(false)
	})

	/** The server sends zero for every post then, so a zero cannot mean "nobody". */
	it('asks who reacted even at zero, and names the rows without a number, for a reader who hides the numbers', async () => {
		serve({ reblogged_by: [account('bob')], favourited_by: [account('carol')] })
		const wrapper = mountReactions(post(), { hideCounts: true })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${API}/statuses/42/reblogged_by`, expect.anything())
		expect(axios.get).toHaveBeenCalledWith(`${API}/statuses/42/favourited_by`, expect.anything())
		expect(wrapper.text()).toContain('Boosted by')
		expect(wrapper.text()).toContain('Favourited by')
		expect(wrapper.text()).not.toMatch(/by \d/)
	})

	it('names the two counts and shows the faces behind them', async () => {
		serve({ reblogged_by: [account('bob')], favourited_by: [account('carol'), account('dave')] })
		const wrapper = mountReactions(post({ reblogs_count: 1, favourites_count: 2 }))
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${API}/statuses/42/reblogged_by`, { params: { limit: 12 } })
		expect(axios.get).toHaveBeenCalledWith(`${API}/statuses/42/favourited_by`, { params: { limit: 12 } })

		const rows = wrapper.findAll('.reacted-by__group')
		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('Boosted by 1 person')
		expect(rows[1].text()).toContain('Favourited by 2 people')
		expect(rows[1].findAll('.reacted-by__face')).toHaveLength(2)
	})

	it('draws only the row it has accounts for', async () => {
		serve({ favourited_by: [account('carol')] })
		const wrapper = mountReactions(post({ favourites_count: 1 }))
		await flushPromises()

		expect(wrapper.findAll('.reacted-by__group')).toHaveLength(1)
		expect(wrapper.text()).toContain('Favourited by 1 person')
	})

	it('draws nothing rather than an error when the server refuses', async () => {
		axios.get.mockRejectedValue(new Error('nope'))
		const wrapper = mountReactions(post({ favourites_count: 1 }))
		await flushPromises()

		// the count is still on the post itself; this row is the elaboration
		expect(wrapper.find('.reacted-by').exists()).toBe(false)
	})

	it('asks again for the row whose count changed, and only that one', async () => {
		serve({ favourited_by: [account('carol')] })
		const wrapper = mountReactions(post({ favourites_count: 1 }))
		await flushPromises()
		axios.get.mockClear()

		// the reader favourites it themselves while looking at it
		await wrapper.setProps({ status: post({ favourites_count: 2 }) })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith(`${API}/statuses/42/favourited_by`, { params: { limit: 12 } })
	})

	it('starts over for another post', async () => {
		serve({ favourited_by: [account('carol')] })
		const wrapper = mountReactions(post({ favourites_count: 1 }))
		await flushPromises()
		axios.get.mockClear()

		await wrapper.setProps({ status: post({ id: '99', favourites_count: 1, reblogs_count: 1 }) })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${API}/statuses/99/favourited_by`, { params: { limit: 12 } })
		expect(axios.get).toHaveBeenCalledWith(`${API}/statuses/99/reblogged_by`, { params: { limit: 12 } })
	})

	it('shows at most a dozen faces however many the server sends', async () => {
		serve({ favourited_by: Array.from({ length: 30 }, (_, i) => account(`user${i}`)) })
		const wrapper = mountReactions(post({ favourites_count: 30 }))
		await flushPromises()

		expect(wrapper.findAll('.reacted-by__face')).toHaveLength(12)
		// and the count, not the faces, is what says how many there are
		expect(wrapper.text()).toContain('Favourited by 30 people')
	})

	it('fills the faces in again once, when the server is reading who reacted elsewhere', async () => {
		vi.useFakeTimers()
		let calls = 0
		axios.get.mockImplementation(async (url) => {
			if (!url.endsWith('/favourited_by')) {
				return { data: [] }
			}
			calls++

			return calls === 1
				? { data: [account('bob')], headers: { 'x-social-filling': '1' } }
				: { data: [account('bob'), account('carol@bsky.example')], headers: {} }
		})
		const wrapper = mountReactions(post({ favourites_count: 2 }))
		await flushPromises()
		expect(wrapper.findAll('.reacted-by__face')).toHaveLength(1)

		await vi.advanceTimersByTimeAsync(REFILL_MS)
		await flushPromises()
		expect(calls).toBe(2)
		expect(wrapper.findAll('.reacted-by__face')).toHaveLength(2)
		vi.useRealTimers()
	})
})
