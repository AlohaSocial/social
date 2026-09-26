/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import FollowGraphSuggestions from '../../../src/components/FollowGraphSuggestions.vue'
import { useAccountStore } from '../../../src/store/account.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const STATUS = '/index.php/apps/social/api/v1/follow_graph/status'
const WALK = '/index.php/apps/social/api/v1/follow_graph'

/**
 * @param {string} acct the handle
 * @param {number} followedBy how many of the reader's follows follow them
 * @param {string[]} via which of them
 * @return {object} one suggestion as the server sends it
 */
function suggestion(acct, followedBy = 3, via = ['bea@example.org']) {
	return {
		account: { acct, username: acct.split('@')[0], display_name: acct.split('@')[0], url: `https://${acct.split('@')[1]}/@${acct.split('@')[0]}` },
		followed_by: followedBy,
		via,
	}
}

/**
 * @param {object} status what the status route answers
 * @return {object} the mounted component
 */
function mountGraph(status = { needs: 0 }) {
	axios.get.mockImplementation((url) => {
		if (url === STATUS) {
			return Promise.resolve({ data: status })
		}

		return Promise.resolve({ data: { needs: 0, suggestions: [] } })
	})

	return mount(FollowGraphSuggestions, {
		global: { stubs: { RouterLink: RouterLinkStub } },
	})
}

describe('FollowGraphSuggestions', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
	})

	/**
	 * The walk costs one request per server the reader follows somebody on,
	 * and the page must not spend that on somebody who came to read hashtags.
	 */
	it('asks only the cheap question until the button is pressed', async () => {
		const wrapper = mountGraph()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith(STATUS)
		expect(wrapper.find('.graph__list').exists()).toBe(false)
	})

	it('says how many more follows it would take, rather than "no suggestions"', async () => {
		const wrapper = mountGraph({ needs: 2 })
		await flushPromises()

		expect(wrapper.find('.graph__hint').text()).toContain('Follow 2 more accounts')
		expect(wrapper.findComponent({ name: 'NcButton' }).exists()).toBe(false)
	})

	it('walks the graph when asked and lists what came back', async () => {
		const wrapper = mountGraph()
		await flushPromises()

		axios.get.mockResolvedValue({
			data: { needs: 0, suggestions: [suggestion('jens@chaos.social'), suggestion('mara@example.org')] },
		})
		await wrapper.findComponent({ name: 'NcButton' }).trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(WALK, { params: { limit: 20 } })
		expect(wrapper.findAll('.person')).toHaveLength(2)
		expect(wrapper.find('.person__reason').text()).toContain('bea@example.org')
	})

	/** Nobody new is a description of the graph, not a failure. */
	it('explains an empty walk instead of showing nothing', async () => {
		const wrapper = mountGraph()
		await flushPromises()

		await wrapper.findComponent({ name: 'NcButton' }).trigger('click')
		await flushPromises()

		expect(wrapper.find('.graph__list').exists()).toBe(false)
		expect(wrapper.text()).toContain('Nobody new came out of it')
	})

	/**
	 * The page is served to readers with no session too, and a "follow more
	 * people" hint shown to somebody who cannot follow anybody is noise.
	 */
	it('disappears where there is nobody to suggest for', async () => {
		axios.get.mockRejectedValue({ response: { status: 401 } })

		const wrapper = mount(FollowGraphSuggestions, {
			global: { stubs: { RouterLink: RouterLinkStub } },
		})
		await flushPromises()

		expect(wrapper.find('.graph').exists()).toBe(false)
	})

	it('follows a stranger by handle, which is all the row carries', async () => {
		const follow = vi.spyOn(useAccountStore(), 'followAccount').mockResolvedValue(true)
		const wrapper = mountGraph()
		await flushPromises()

		axios.get.mockResolvedValue({ data: { needs: 0, suggestions: [suggestion('jens@chaos.social')] } })
		await wrapper.findComponent({ name: 'NcButton' }).trigger('click')
		await flushPromises()

		await wrapper.find('.person__follow').trigger('click')
		await flushPromises()

		expect(follow).toHaveBeenCalledWith({ accountToFollow: 'jens@chaos.social' })
		expect(wrapper.find('.person__follow').text()).toContain('Following')
	})

	/**
	 * The same people, as a sky to play with. The list stays the default:
	 * it is the view to read, and the one every existing reader knows.
	 */
	it('offers the suggestions as a constellation as well as a list', async () => {
		window.matchMedia = vi.fn((query) => ({ matches: query.includes('reduce') }))
		const wrapper = mountGraph()
		await flushPromises()
		axios.get.mockResolvedValue({
			data: { needs: 0, suggestions: [suggestion('jens@chaos.social'), suggestion('mara@example.org')] },
		})
		await wrapper.findComponent({ name: 'NcButton' }).trigger('click')
		await flushPromises()

		const views = wrapper.findAll('.graph__view')
		expect(views.map((view) => view.attributes('aria-checked'))).toEqual(['true', 'false'])
		expect(wrapper.find('.graph__list').exists()).toBe(true)

		await views[1].trigger('click')
		await vi.waitUntil(() => wrapper.find('.constellation').exists(), { timeout: 5000 })
		await flushPromises()

		expect(wrapper.find('.graph__list').exists()).toBe(false)
		expect(wrapper.findAll('.constellation__star')).toHaveLength(2)
	})

	it('shows no view switch before there is anything to show', async () => {
		const wrapper = mountGraph()
		await flushPromises()

		expect(wrapper.find('.graph__views').exists()).toBe(false)
	})
})
