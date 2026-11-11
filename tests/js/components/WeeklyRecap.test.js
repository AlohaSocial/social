/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import WeeklyRecap from '../../../src/components/WeeklyRecap.vue'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

/**
 * @param {object} data what the server answers
 * @return {Promise<object>} the mounted card, once its request has settled
 */
async function mountRecap(data, serverData = { public: false }) {
	axios.get.mockResolvedValue({ data })
	setActivePinia(createPinia())
	useSettingsStore().setServerData(serverData)

	const wrapper = mount(WeeklyRecap, { global: { stubs: { RouterLink: RouterLinkStub } } })
	await flushPromises()

	return wrapper
}

describe('WeeklyRecap', () => {
	beforeEach(() => {
		axios.get.mockReset()
		window.localStorage.clear()
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-03-04T12:00:00.000Z'))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('does not ask when the page says the reader never turned it on', async () => {
		const wrapper = await mountRecap({ enabled: true, this_week: 3, last_week: 1 }, { public: false, startup: { recap_enabled: false } })

		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.find('.weekly-recap').exists()).toBe(false)
	})

	it('asks when the page says it is on', async () => {
		await mountRecap({ enabled: true, this_week: 3, last_week: 1 }, { public: false, startup: { recap_enabled: true } })

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/memories/recap')
	})

	// it is opt-in: a card commenting on how much somebody has posted is
	// something to ask for, not something to be given
	it('shows nothing until the reader has asked for it', async () => {
		const wrapper = await mountRecap({ enabled: false, this_week: 0, last_week: 0 })

		expect(wrapper.find('.weekly-recap').exists()).toBe(false)
	})

	it('says how many times the reader posted', async () => {
		const wrapper = await mountRecap({ enabled: true, this_week: 3, last_week: 0 })

		expect(wrapper.find('.weekly-recap__text').text()).toContain('You posted 3 times this week.')
	})

	it('gives the week before for scale', async () => {
		const wrapper = await mountRecap({ enabled: true, this_week: 3, last_week: 5 })

		expect(wrapper.find('.weekly-recap__aside').text()).toBe('5 the week before.')
	})

	// "0 last week" beside "0 this week" is a sentence about nothing
	it('leaves the comparison out when there is nothing to compare with', async () => {
		const wrapper = await mountRecap({ enabled: true, this_week: 3, last_week: 0 })

		expect(wrapper.find('.weekly-recap__aside').exists()).toBe(false)
	})

	describe('a quiet week', () => {
		it('says so without making it a failure', async () => {
			const wrapper = await mountRecap({ enabled: true, this_week: 0, last_week: 4 })
			const text = wrapper.find('.weekly-recap__text').text()

			expect(text).toContain('You have not posted this week.')
			expect(text).not.toMatch(/streak|missed|broke/i)
		})

		// what other people wrote is the useful answer to "you have not
		// posted", and it asks nothing of the reader
		it('offers a way into the local timeline instead', async () => {
			const wrapper = await mountRecap({ enabled: true, this_week: 0, last_week: 4 })

			expect(wrapper.text()).toContain('See what your colleagues shared')
		})

		it('offers no such thing when the reader did post', async () => {
			const wrapper = await mountRecap({ enabled: true, this_week: 2, last_week: 4 })

			expect(wrapper.text()).not.toContain('See what your colleagues shared')
		})
	})

	describe('dismissing', () => {
		it('goes away when it is put away', async () => {
			const wrapper = await mountRecap({ enabled: true, this_week: 3, last_week: 0 })

			await wrapper.find('[aria-label="Hide this week"]').trigger('click')

			expect(wrapper.find('.weekly-recap').exists()).toBe(false)
		})

		it('stays away for the rest of the week', async () => {
			const wrapper = await mountRecap({ enabled: true, this_week: 3, last_week: 0 })
			await wrapper.find('[aria-label="Hide this week"]').trigger('click')

			axios.get.mockClear()
			const second = await mountRecap({ enabled: true, this_week: 3, last_week: 0 })

			expect(axios.get).not.toHaveBeenCalled()
			expect(second.find('.weekly-recap').exists()).toBe(false)
		})

		it('comes back the week after', async () => {
			const wrapper = await mountRecap({ enabled: true, this_week: 3, last_week: 0 })
			await wrapper.find('[aria-label="Hide this week"]').trigger('click')

			vi.setSystemTime(new Date('2026-03-12T12:00:00.000Z'))
			const second = await mountRecap({ enabled: true, this_week: 1, last_week: 3 })

			expect(second.find('.weekly-recap').exists()).toBe(true)
		})
	})

	it('shows nothing when the request fails', async () => {
		axios.get.mockRejectedValue(new Error('nope'))

		const wrapper = mount(WeeklyRecap, { global: { stubs: { RouterLink: RouterLinkStub } } })
		await flushPromises()

		expect(wrapper.find('.weekly-recap').exists()).toBe(false)
	})
})
