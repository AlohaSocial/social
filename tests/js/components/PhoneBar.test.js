/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import PhoneBar, { NEAR_TOP, ROOT_CLASS, SCROLL_SLACK } from '../../../src/components/PhoneBar.vue'
import eventBus from '../../../src/services/eventBus.js'
import { useAccountStore } from '../../../src/store/account.js'
import { useNotificationsStore } from '../../../src/store/notifications.js'
import { useSettingsStore } from '../../../src/store/settings.js'

// the width is the test's to set, and to change under a mounted bar
const screen = vi.hoisted(() => ({ phone: true, listeners: new Set() }))
vi.mock('../../../src/services/phone.js', () => ({
	isPhone: () => screen.phone,
	onPhoneChange: (callback) => {
		screen.listeners.add(callback)
		return () => screen.listeners.delete(callback)
	},
}))

/** @param {boolean} phone the new answer */
function resize(phone) {
	screen.phone = phone
	screen.listeners.forEach((callback) => callback(phone))
}

const empty = { template: '<div />' }

function makeRouter() {
	return createRouter({
		history: createMemoryHistory(),
		routes: [
			{ path: '/timeline/:type?', name: 'timeline', component: empty },
			{ path: '/discover', name: 'discover', component: empty },
			{ path: '/settings', name: 'settings', component: empty },
		],
	})
}

let wrapper = null
let column = null

async function mountBar({ at = '/timeline', serverData = { public: false }, account = null } = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData(serverData)
	if (account !== null) {
		useAccountStore().setOwnAccount(account)
	}
	const router = makeRouter()
	await router.push(at)
	await router.isReady()

	wrapper = mount(PhoneBar, { attachTo: document.body, global: { plugins: [pinia, router] } })
	await flushPromises()

	return { router, wrapper }
}

/**
 * Moves the app's column and tells the page, the way a finger does.
 *
 * @param {number} top the new offset
 */
async function scrollColumnTo(top) {
	column.scrollTop = top
	column.dispatchEvent(new Event('scroll'))
	await nextTick()
}

const isAway = () => wrapper.find('.phone-bar').classes().includes('phone-bar--away')

describe('PhoneBar', () => {
	beforeEach(() => {
		screen.phone = true
		screen.listeners.clear()
		column = document.createElement('div')
		column.id = 'app-content-vue'
		document.body.appendChild(column)
	})

	afterEach(() => {
		wrapper?.unmount()
		wrapper = null
		column.remove()
	})

	describe('when it is drawn', () => {
		it('is a labelled navigation landmark on a phone', async () => {
			await mountBar()

			const nav = wrapper.find('nav.phone-bar')
			expect(nav.exists()).toBe(true)
			expect(nav.attributes('aria-label')).toBe('Main sections')
			expect(document.documentElement.classList.contains(ROOT_CLASS)).toBe(true)
		})

		it('is not there on a wider screen, where the sidebar is open', async () => {
			screen.phone = false
			await mountBar()

			expect(wrapper.find('nav').exists()).toBe(false)
			expect(document.documentElement.classList.contains(ROOT_CLASS)).toBe(false)
		})

		it('comes and goes as the window changes width', async () => {
			screen.phone = false
			await mountBar()

			resize(true)
			await nextTick()
			expect(wrapper.find('nav').exists()).toBe(true)
			expect(document.documentElement.classList.contains(ROOT_CLASS)).toBe(true)

			resize(false)
			await nextTick()
			expect(wrapper.find('nav').exists()).toBe(false)
			expect(document.documentElement.classList.contains(ROOT_CLASS)).toBe(false)
		})

		it('gives a visitor nothing: every entry is somebody\'s own', async () => {
			await mountBar({ serverData: { public: true } })

			expect(wrapper.find('nav').exists()).toBe(false)
			expect(document.documentElement.classList.contains(ROOT_CLASS)).toBe(false)
		})

		it('gives the room back when it goes', async () => {
			await mountBar()
			wrapper.unmount()
			wrapper = null

			expect(document.documentElement.classList.contains(ROOT_CLASS)).toBe(false)
		})
	})

	describe('what it holds', () => {
		it('has the four places with the compose button in the middle', async () => {
			await mountBar()

			const slots = wrapper.findAll('.phone-bar__slot')
			expect(slots).toHaveLength(5)
			expect(slots.map((slot) => slot.text())).toEqual(['My Feed', 'Discover', '', 'Activities', 'Messages'])
			expect(slots[2].find('button.phone-bar__compose').attributes('aria-label')).toBe('New post')
			expect(wrapper.findAll('a').map((link) => link.attributes('href'))).toEqual([
				'/timeline',
				'/discover',
				'/timeline/notifications',
				'/timeline/direct',
			])
		})

		it('offers no compose button to a moved account, which posts to nobody', async () => {
			await mountBar({ account: { url: 'https://cloud.example/apps/social/@alice', acct: 'alice@cloud.example', moved: { acct: 'alice@new.example' } } })

			expect(wrapper.find('.phone-bar__compose').exists()).toBe(false)
			expect(wrapper.findAll('.phone-bar__slot')).toHaveLength(4)
		})

		it('opens the common composer, handing it the button to return to', async () => {
			await mountBar()
			const heard = vi.fn()
			eventBus.on('composer-open', heard)

			const button = wrapper.find('.phone-bar__compose')
			await button.trigger('click')

			expect(heard).toHaveBeenCalledWith({ opener: button.element })
			eventBus.off('composer-open', heard)
		})
	})

	describe('badges', () => {
		it('shows the same counts as the sidebar, in words for a screen reader', async () => {
			await mountBar()
			const notifications = useNotificationsStore()
			notifications.setUnreadNotifications(2)
			notifications.pendingRequests = 1
			notifications.setUnreadDirectMessages(4)
			await nextTick()

			const links = wrapper.findAll('a')
			expect(links[2].find('.phone-bar__badge').text()).toBe('3')
			expect(links[2].find('.phone-bar__badge').attributes('aria-hidden')).toBe('true')
			expect(links[2].find('.hidden-visually').text()).toBe('3 unread')
			expect(links[3].find('.phone-bar__badge').text()).toBe('4')
			expect(links[0].find('.phone-bar__badge').exists()).toBe(false)
		})

		it('draws no badge for nothing and caps a large count', async () => {
			await mountBar()
			const notifications = useNotificationsStore()

			expect(wrapper.findAll('.phone-bar__badge')).toHaveLength(0)
			expect(wrapper.findAll('.hidden-visually')).toHaveLength(0)

			notifications.setUnreadNotifications(250)
			await nextTick()
			expect(wrapper.find('.phone-bar__badge').text()).toBe('99+')
			expect(wrapper.find('.hidden-visually').text()).toBe('250 unread')
		})
	})

	describe('the current page', () => {
		const current = () => wrapper.findAll('a')
			.filter((link) => link.attributes('aria-current') === 'page')
			.map((link) => link.text())

		it.each([
			['/timeline', 'My Feed'],
			['/timeline/timeline', 'My Feed'],
			['/timeline/federated', 'My Feed'],
			['/discover', 'Discover'],
			['/timeline/notifications', 'Activities'],
			['/timeline/direct', 'Messages'],
		])('marks %s as %s', async (at, name) => {
			await mountBar({ at })

			expect(current()).toEqual([name])
			expect(wrapper.find('[aria-current="page"]').classes()).toContain('phone-bar__link--active')
		})

		it('marks nothing on a page the bar does not hold', async () => {
			await mountBar({ at: '/settings' })

			expect(current()).toEqual([])
		})

		it('follows the reader to another page', async () => {
			const { router } = await mountBar()

			await wrapper.findAll('a')[1].trigger('click')
			await flushPromises()

			expect(router.currentRoute.value.name).toBe('discover')
			expect(current()).toEqual(['Discover'])
		})
	})

	describe('hiding on scroll', () => {
		it('slides away going down and comes back going up', async () => {
			await mountBar()

			await scrollColumnTo(NEAR_TOP)
			await scrollColumnTo(NEAR_TOP + SCROLL_SLACK)
			expect(isAway()).toBe(false)
			await scrollColumnTo(NEAR_TOP + SCROLL_SLACK + 1)
			expect(isAway()).toBe(true)

			await scrollColumnTo(NEAR_TOP + 300)
			expect(isAway()).toBe(true)
			await scrollColumnTo(NEAR_TOP + 300 - SCROLL_SLACK - 1)
			expect(isAway()).toBe(false)
		})

		it('ignores a finger resting on the screen', async () => {
			await mountBar()
			await scrollColumnTo(400)
			await scrollColumnTo(800)
			expect(isAway()).toBe(true)

			await scrollColumnTo(795)
			await scrollColumnTo(800)
			await scrollColumnTo(790)
			expect(isAway()).toBe(true)
		})

		it('always shows near the top of the page', async () => {
			await mountBar()
			await scrollColumnTo(400)
			await scrollColumnTo(800)
			expect(isAway()).toBe(true)

			await scrollColumnTo(NEAR_TOP)
			expect(isAway()).toBe(false)
		})

		it('does not answer a scroll inside something else', async () => {
			await mountBar()
			const strip = document.createElement('div')
			document.body.appendChild(strip)

			strip.scrollTop = 900
			strip.dispatchEvent(new Event('scroll'))
			await nextTick()

			expect(isAway()).toBe(false)
			strip.remove()
		})

		it('stays for a keyboard user inside it', async () => {
			await mountBar()
			await wrapper.find('nav').trigger('focusin')

			await scrollColumnTo(400)
			await scrollColumnTo(800)
			expect(isAway()).toBe(false)

			await wrapper.find('nav').trigger('focusout', { relatedTarget: null })
			await scrollColumnTo(1200)
			expect(isAway()).toBe(true)
		})

		it('comes back when focus reaches it', async () => {
			await mountBar()
			await scrollColumnTo(400)
			await scrollColumnTo(800)
			expect(isAway()).toBe(true)

			await wrapper.find('nav').trigger('focusin')
			expect(isAway()).toBe(false)
		})

		it('starts every page on screen', async () => {
			const { router } = await mountBar()
			await scrollColumnTo(400)
			await scrollColumnTo(800)
			expect(isAway()).toBe(true)

			await router.push('/discover')
			await flushPromises()
			expect(isAway()).toBe(false)
		})

		it('slides without motion for a reader who asked for less', () => {
			const source = readFileSync(resolve(__dirname, '../../../src/components/PhoneBar.vue'), 'utf8')
			const reduced = source.slice(source.indexOf('@media (prefers-reduced-motion: reduce)'))

			expect(reduced).toMatch(/\.phone-bar\s*\{\s*transition: none;/)
		})
	})

	describe('the room it takes', () => {
		const source = readFileSync(resolve(__dirname, '../../../src/components/PhoneBar.vue'), 'utf8')

		it('pads itself for the home indicator and reserves the same for the page', () => {
			expect(source).toContain('padding-block-end: env(safe-area-inset-bottom, 0px)')
			expect(source).toMatch(/--social-phone-bar-space: calc\(56px \+ env\(safe-area-inset-bottom, 0px\)\)/)
			expect(source).toContain('--toast-offset-block-end: var(--social-phone-bar-space)')
		})

		it.each([
			['src/App.vue', 'padding-block-end: var(--social-phone-bar-space, 0px)'],
			['src/components/MiniPlayer.vue', 'calc(16px + var(--social-phone-bar-space, 0px))'],
			['src/components/DirectMessages.vue', '- var(--social-phone-bar-space, 0px) - var(--social-toggle-clearance, 0px));'],
			['src/views/VideoShorts.scss', '- var(--social-phone-bar-space, 0px)'],
		])('keeps %s clear of it', (file, rule) => {
			expect(readFileSync(resolve(__dirname, '../../..', file), 'utf8')).toContain(rule)
		})
	})
})
