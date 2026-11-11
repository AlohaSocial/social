/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import OnThisDay from '../../../src/components/OnThisDay.vue'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

function memory(overrides = {}) {
	return {
		id: '9',
		created_at: '2023-03-04T10:00:00.000Z',
		content: '<p>We shipped the thing</p>',
		spoiler_text: '',
		account: { acct: 'alice' },
		...overrides,
	}
}

/**
 * @param {Array} memories what the server answers
 * @return {Promise<object>} the mounted card, once its request has settled
 */
async function mountCard(memories, serverData = { public: false }) {
	axios.get.mockResolvedValue({ data: memories })
	setActivePinia(createPinia())
	useSettingsStore().setServerData(serverData)

	const wrapper = mount(OnThisDay, { global: { stubs: { RouterLink: RouterLinkStub } } })
	await flushPromises()

	return wrapper
}

describe('OnThisDay', () => {
	beforeEach(() => {
		axios.get.mockReset()
		window.localStorage.clear()
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-03-04T12:00:00.000Z'))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('does not ask when the page says the reader has nothing a year old', async () => {
		const wrapper = await mountCard([memory()], { public: false, startup: { has_memories: false } })

		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.find('.on-this-day').exists()).toBe(false)
	})

	it('shows nothing at all when there is nothing to remember', async () => {
		const wrapper = await mountCard([])

		expect(wrapper.find('.on-this-day').exists()).toBe(false)
	})

	it('lists one line per memory', async () => {
		const wrapper = await mountCard([memory(), memory({ id: '10' })])

		expect(wrapper.findAll('.on-this-day__memory')).toHaveLength(2)
	})

	it('says how long ago each one was', async () => {
		const wrapper = await mountCard([memory()])

		expect(wrapper.find('.on-this-day__when').text()).toBe('3 years ago')
	})

	it('links a memory to the post itself', async () => {
		const wrapper = await mountCard([memory()])

		expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({
			name: 'single-post',
			params: { account: 'alice', id: '9' },
		})
	})

	/**
	 * The excerpt is a label inside a link, so the post's HTML is parsed for
	 * its text and never rendered: nothing somebody wrote can become markup.
	 */
	it('shows the text of a post, not its markup', async () => {
		const wrapper = await mountCard([memory({ content: '<p>Hello <strong>there</strong></p>' })])

		expect(wrapper.find('.on-this-day__excerpt').text()).toBe('Hello there')
		expect(wrapper.find('.on-this-day__excerpt').html()).not.toContain('<strong>')
	})

	it('cannot be made to render an injected tag', async () => {
		const wrapper = await mountCard([memory({ content: '<p>&lt;img src=x onerror=1&gt;</p>' })])

		expect(wrapper.find('.on-this-day__excerpt img').exists()).toBe(false)
		expect(wrapper.find('.on-this-day__excerpt').text()).toContain('<img src=x onerror=1>')
	})

	it('shows the content warning in place of a hidden post', async () => {
		const wrapper = await mountCard([memory({ spoiler_text: 'Food', content: '<p>a long lunch</p>' })])

		expect(wrapper.find('.on-this-day__excerpt').text()).toBe('Food')
	})

	it('shortens a long post rather than filling the timeline with it', async () => {
		const wrapper = await mountCard([memory({ content: `<p>${'a'.repeat(400)}</p>` })])
		const text = wrapper.find('.on-this-day__excerpt').text()

		expect(text.length).toBeLessThan(200)
		expect(text.endsWith('…')).toBe(true)
	})

	it('says so rather than showing an empty line for a post with only a picture', async () => {
		const wrapper = await mountCard([memory({ content: '' })])

		expect(wrapper.find('.on-this-day__excerpt').text()).toBe('(no text)')
	})

	describe('dismissing', () => {
		it('goes away when it is put away', async () => {
			const wrapper = await mountCard([memory()])

			await wrapper.find('[aria-label="Hide until tomorrow"]').trigger('click')

			expect(wrapper.find('.on-this-day').exists()).toBe(false)
		})

		it('stays away for the rest of the day', async () => {
			const wrapper = await mountCard([memory()])
			await wrapper.find('[aria-label="Hide until tomorrow"]').trigger('click')

			axios.get.mockClear()
			const second = await mountCard([memory()])

			expect(axios.get).not.toHaveBeenCalled()
			expect(second.find('.on-this-day').exists()).toBe(false)
		})

		it('comes back the next day', async () => {
			const wrapper = await mountCard([memory()])
			await wrapper.find('[aria-label="Hide until tomorrow"]').trigger('click')

			vi.setSystemTime(new Date('2026-03-05T09:00:00.000Z'))
			const second = await mountCard([memory()])

			expect(second.find('.on-this-day').exists()).toBe(true)
		})
	})

	it('shows nothing when the request fails', async () => {
		axios.get.mockRejectedValue(new Error('nope'))

		const wrapper = mount(OnThisDay, { global: { stubs: { RouterLink: RouterLinkStub } } })
		await flushPromises()

		expect(wrapper.find('.on-this-day').exists()).toBe(false)
	})
})
