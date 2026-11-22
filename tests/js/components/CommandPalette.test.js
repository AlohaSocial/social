/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CommandPalette, { SEARCH_DEBOUNCE_MS } from '../../../src/components/CommandPalette.vue'
import eventBus from '../../../src/services/eventBus.js'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

const empty = { template: '<div />' }
const NcModal = {
	name: 'NcModal',
	props: ['labelId'],
	emits: ['close'],
	template: '<div role="dialog" aria-modal="true" :aria-labelledby="labelId"><slot /></div>',
}

function makeRouter() {
	return createRouter({
		history: createMemoryHistory(),
		routes: [
			{ path: '/timeline/:type?', name: 'timeline', component: empty },
			{ path: '/shorts', name: 'shorts', component: empty },
			{ path: '/discover', name: 'discover', component: empty },
			{ path: '/@:account', name: 'profile', component: empty },
			{ path: '/follow_requests', name: 'follow-requests', component: empty },
			{ path: '/statistics', name: 'statistics', component: empty },
			{ path: '/blocked', name: 'blocked-accounts', component: empty },
			{ path: '/migration', name: 'migration', component: empty },
			{ path: '/about', name: 'about', component: empty },
			{ path: '/settings', name: 'settings', component: empty },
		],
	})
}

let wrapper = null

async function mountPalette({ at = '/timeline', serverData = { public: false }, account = { url: 'https://cloud.example/@alice', acct: 'alice' } } = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData(serverData)
	if (account !== null) {
		useAccountStore().setOwnAccount(account)
	}
	const router = makeRouter()
	await router.push(at)
	await router.isReady()

	wrapper = mount(CommandPalette, { attachTo: document.body, global: { plugins: [pinia, router], stubs: { NcModal } } })
	await flushPromises()

	return { router, wrapper }
}

const input = () => wrapper.find('input')
const titles = () => wrapper.findAll('[role="option"] .command-palette__title').map((title) => title.text())

describe('CommandPalette', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.get.mockResolvedValue({ data: { result: { accounts: [] } } })
	})

	afterEach(() => {
		wrapper?.unmount()
		wrapper = null
		vi.useRealTimers()
		eventBus.all.clear()
	})

	describe('as a combobox', () => {
		it('is a labelled field that controls a listbox of options', async () => {
			await mountPalette()

			const field = input()
			expect(field.attributes('role')).toBe('combobox')
			expect(field.attributes('aria-autocomplete')).toBe('list')
			expect(field.attributes('aria-expanded')).toBe('true')
			expect(field.attributes('aria-controls')).toBe(wrapper.find('[role="listbox"]').attributes('id'))
			expect(wrapper.find(`label[for="${field.attributes('id')}"]`).text()).toBe('Jump to a page, action or account')
			expect(wrapper.find('[role="dialog"]').attributes('aria-labelledby')).toBe(wrapper.find('label').attributes('id'))
		})

		it('takes the focus as it opens', async () => {
			await mountPalette()
			await new Promise((resolve) => window.requestAnimationFrame(resolve))

			expect(document.activeElement).toBe(input().element)
		})

		it('moves the highlight with the arrow keys, wrapping at the ends, and says which is highlighted', async () => {
			await mountPalette()
			const options = () => wrapper.findAll('[role="option"]')
			expect(input().attributes('aria-activedescendant')).toBe(options()[0].attributes('id'))
			expect(options()[0].attributes('aria-selected')).toBe('true')

			await input().trigger('keydown', { key: 'ArrowDown' })
			expect(input().attributes('aria-activedescendant')).toBe(options()[1].attributes('id'))
			expect(options()[0].attributes('aria-selected')).toBe('false')

			await input().trigger('keydown', { key: 'ArrowUp' })
			await input().trigger('keydown', { key: 'ArrowUp' })
			expect(input().attributes('aria-activedescendant')).toBe(options().at(-1).attributes('id'))
		})

		it('closes on Escape and on the chord pressed again', async () => {
			await mountPalette()

			await input().trigger('keydown', { key: 'Escape' })
			await input().trigger('keydown', { key: 'k', ctrlKey: true })

			expect(wrapper.emitted('close')).toHaveLength(2)
		})

		it('says so when nothing matches', async () => {
			await mountPalette()

			await input().setValue('qqqqzz')

			expect(titles()).toEqual([])
			expect(input().attributes('aria-expanded')).toBe('false')
			expect(wrapper.find('[role="status"]').text()).toBe('No results found')
		})
	})

	describe('what it offers', () => {
		it('lists New post, the sidebar\'s places, the account menu and settings sections', async () => {
			await mountPalette()

			expect(titles()).toEqual(expect.arrayContaining([
				'New post',
				'My Feed',
				'Photos',
				'Videos',
				'Shorts',
				'Discover',
				'Activities',
				'Direct messages',
				'My profile',
				'Bookmarks',
				'Favorites',
				'Statistics',
				'Settings',
				'Lists',
				'Keyboard shortcuts',
			]))
			expect(titles()[0]).toBe('New post')
		})

		it('leaves out what the instance does not offer, and New post for a moved account', async () => {
			await mountPalette({
				serverData: { public: false, sections: { section_photos: false, section_videos: false } },
				account: { url: 'https://cloud.example/@alice', acct: 'alice', moved: { acct: 'alice@new.example' } },
			})

			expect(titles()).not.toContain('Photos')
			expect(titles()).not.toContain('Videos')
			expect(titles()).not.toContain('Shorts')
			expect(titles()).not.toContain('New post')
		})

		it('narrows the list as the reader types, best match first', async () => {
			await mountPalette()

			await input().setValue('bookm')

			expect(titles()).toEqual(['Bookmarks'])
		})
	})

	describe('doing what is chosen', () => {
		it('goes to the page on Enter and closes', async () => {
			const { router } = await mountPalette()

			await input().setValue('discover')
			await input().trigger('keydown', { key: 'Enter' })
			await flushPromises()

			expect(router.currentRoute.value.name).toBe('discover')
			expect(wrapper.emitted('close')).toHaveLength(1)
		})

		it('opens a settings section by its address', async () => {
			const { router } = await mountPalette()

			await input().setValue('who can contact')
			await wrapper.findAll('[role="option"]')[0].trigger('click')
			await flushPromises()

			expect(router.currentRoute.value.fullPath).toBe('/settings#contact')
		})

		it('opens the lists where they are edited, without leaving the page', async () => {
			const { router } = await mountPalette()
			const managed = vi.fn()
			eventBus.on('lists:manage', managed)

			await input().setValue('lists')
			await wrapper.findAll('[role="option"]')[0].trigger('click')
			await flushPromises()

			expect(managed).toHaveBeenCalled()
			expect(router.currentRoute.value.name).not.toBe('settings')
			eventBus.off('lists:manage', managed)
		})

		it('takes the page back to its top when it is the page already open', async () => {
			const { router } = await mountPalette({ at: '/discover' })
			const push = vi.spyOn(router, 'push')
			const column = document.createElement('div')
			column.id = 'app-content-vue'
			column.scrollTo = vi.fn()
			document.body.appendChild(column)

			await input().setValue('discover')
			await input().trigger('keydown', { key: 'Enter' })
			column.remove()

			expect(column.scrollTo).toHaveBeenCalledWith(expect.objectContaining({ top: 0 }))
			expect(push).not.toHaveBeenCalled()
		})

		it('opens the composer once it has closed, handing it what had the focus before', async () => {
			const before = document.createElement('button')
			document.body.appendChild(before)
			before.focus()
			await mountPalette()
			const heard = vi.fn()
			eventBus.on('composer-open', heard)

			await input().setValue('new post')
			await input().trigger('keydown', { key: 'Enter' })
			await flushPromises()

			expect(wrapper.emitted('close')).toHaveLength(1)
			expect(heard).toHaveBeenCalledWith({ opener: before })
			before.remove()
		})
	})

	describe('accounts', () => {
		it('asks for accounts once the typing settles, and lists them after the commands', async () => {
			vi.useFakeTimers()
			axios.get.mockResolvedValue({ data: { result: { accounts: [{ acct: 'bob@social.example', username: 'bob', display_name: 'Bob', avatar: 'https://social.example/bob.png' }] } } })
			const { router } = await mountPalette()

			await input().setValue('@bo')
			expect(axios.get).not.toHaveBeenCalled()
			await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS)
			await flushPromises()

			expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('/global/accounts/search'), { params: { search: 'bo' } })
			const options = wrapper.findAll('[role="option"]')
			expect(options.at(-1).find('.command-palette__title').text()).toBe('Bob')
			expect(options.at(-1).find('.command-palette__hint').text()).toBe('@bob@social.example')
			expect(options.at(-1).find('img').attributes('alt')).toBe('')

			await options.at(-1).trigger('click')
			await vi.runAllTimersAsync()
			expect(router.currentRoute.value.fullPath).toBe('/@bob@social.example')
		})

		it('does not ask for one letter, and lists only the newest answer', async () => {
			vi.useFakeTimers()
			await mountPalette()

			await input().setValue('b')
			await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS)
			expect(axios.get).not.toHaveBeenCalled()

			let answerOld
			axios.get.mockImplementationOnce(() => new Promise((resolve) => {
				answerOld = resolve
			}))
			await input().setValue('bo')
			await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS)
			axios.get.mockResolvedValueOnce({ data: { result: { accounts: [{ acct: 'bob', username: 'bob', display_name: 'Bob' }] } } })
			await input().setValue('bob')
			await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS)
			answerOld({ data: { result: { accounts: [{ acct: 'boris', username: 'boris', display_name: 'Boris' }] } } })
			await flushPromises()

			expect(titles()).toContain('Bob')
			expect(titles()).not.toContain('Boris')
		})
	})
})
