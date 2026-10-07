/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ShortsBar from '../../../src/components/ShortsBar.vue'
import eventBus, { SHORT_COMPOSE } from '../../../src/services/eventBus.js'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('../../../src/services/logger.js', () => ({ default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() } }))
// @nextcloud/auth reads the user from <head>, which the harness does not set
vi.mock('@nextcloud/auth', async (importOriginal) => ({
	...(await importOriginal()),
	getCurrentUser: () => ({ uid: 'alice', displayName: 'Alice', isAdmin: false }),
}))

const alice = { id: '7', url: 'https://cloud.example.org/@alice', acct: 'alice', username: 'alice', display_name: 'Alice', avatar: '' }
const bob = { id: '9', url: 'https://remote.example/users/bob', acct: 'bob@remote.example', username: 'bob', display_name: 'Bob', avatar: '' }
const carol = { id: '11', url: 'https://remote.example/users/carol', acct: 'carol@remote.example', username: 'carol', display_name: 'Carol', avatar: '' }

function short(id, account, seen = false) {
	return { id, account, seen, duration: 5, caption: '', created_at: '2026-09-15T10:00:00Z', media: { type: 'image', url: `https://cloud.example.org/${id}.jpg` } }
}

const ShortComposerStub = { name: 'ShortComposerDialog', props: ['open', 'lifetime'], emits: ['update:open', 'posted'], template: '<div class="short-stub" />' }
const stubs = { ShortComposerDialog: ShortComposerStub, ActorAvatar: true, RouterLink: RouterLinkStub }

function mountBar(shorts, { current = alice, serverData = null } = {}) {
	get.mockResolvedValue({ data: shorts })
	const pinia = createPinia()
	setActivePinia(pinia)
	const store = useAccountStore()
	store.addAccount({ actorId: alice.url, data: alice })
	if (current) {
		store.setCurrentAccount('alice@cloud.example.org')
	}
	if (serverData) {
		useSettingsStore().setServerData(serverData)
	}

	return mount(ShortsBar, { global: { plugins: [pinia], stubs } })
}

/**
 * @param {object[]} shorts what the carousel answers
 * @return {Promise<object>} the mounted bar, once it has read the carousel
 */
async function mountBarAnd(shorts) {
	const wrapper = mountBar(shorts)
	await flushPromises()

	return wrapper
}

const tiles = (wrapper) => wrapper.findAll('.shorts-bar__tile')

describe('ShortsBar', () => {
	beforeEach(() => {
		get.mockReset()
	})

	it('is headed Shorts and says nothing of stories', async () => {
		const wrapper = mountBar([short('2', bob)])
		await flushPromises()

		expect(wrapper.find('.shorts-bar__heading').text()).toBe('Shorts')
		expect(wrapper.text()).not.toMatch(/stor(y|ies)/i)
		expect(wrapper.html()).not.toMatch(/aria-label="[^"]*stor(y|ies)/i)
	})

	it('groups the carousel by account: the reader, then the unseen, then the seen, then All shorts', async () => {
		const wrapper = mountBar([short('1', alice, true), short('2', bob, true), short('3', carol, false), short('4', carol, true)])
		await flushPromises()

		expect(get.mock.calls[0][0]).toContain('/apps/social/api/v1/stories/carousel')
		expect(tiles(wrapper).map((tile) => tile.text())).toEqual(['Your shorts', 'Carol', 'Bob', 'All shorts'])
		expect(tiles(wrapper)[1].classes()).toContain('shorts-bar__tile--unseen')
		expect(tiles(wrapper)[2].classes()).not.toContain('shorts-bar__tile--unseen')
	})

	it('tells a screen reader how many shorts and whether they are new', async () => {
		const wrapper = mountBar([short('2', bob), short('3', bob), short('4', carol, true)])
		await flushPromises()

		expect(tiles(wrapper)[1].attributes('aria-label')).toBe('Bob: 2 shorts, new')
		expect(tiles(wrapper)[2].attributes('aria-label')).toBe('Carol: 1 short, seen')
	})

	it('opens the Shorts feed at the person whose face was tapped', async () => {
		const wrapper = mountBar([short('2', bob)])
		await flushPromises()

		const links = wrapper.findAllComponents(RouterLinkStub)
		expect(links[0].props('to')).toEqual({ name: 'shorts', query: { account: 'bob@remote.example' } })
	})

	it('ends with an All shorts tile that opens the whole feed', async () => {
		const wrapper = mountBar([short('2', bob)])
		await flushPromises()

		const all = wrapper.findAllComponents(RouterLinkStub).at(-1)
		expect(all.text()).toBe('All shorts')
		expect(all.props('to')).toEqual({ name: 'shorts' })
	})

	it('opens the feed at the reader\'s own shorts when they have some', async () => {
		const wrapper = mountBar([short('1', alice, true), short('2', bob)])
		await flushPromises()

		const own = wrapper.findAllComponents(RouterLinkStub)[0]
		expect(own.attributes('aria-label')).toBe('Your shorts')
		expect(own.props('to')).toEqual({ name: 'shorts', query: { account: 'alice' } })
	})

	it('keeps the reader\'s own place with nothing in it, and opens New short on 24 hours from it', async () => {
		const wrapper = mountBar([short('2', bob)])
		await flushPromises()

		const own = tiles(wrapper)[0]
		expect(own.classes()).toContain('shorts-bar__tile--empty')
		expect(own.attributes('aria-label')).toBe('New short')
		await own.trigger('click')

		const composer = wrapper.findComponent({ name: 'ShortComposerDialog' })
		expect(composer.exists()).toBe(true)
		expect(composer.props('lifetime')).toBe('day')
	})

	it('opens New short on 24 hours from the +', async () => {
		const wrapper = mountBar([short('1', alice, true), short('2', bob)])
		await flushPromises()

		expect(wrapper.find('.shorts-bar__add').attributes('aria-label')).toBe('New short')
		await wrapper.find('.shorts-bar__add').trigger('click')

		expect(wrapper.findComponent({ name: 'ShortComposerDialog' }).props('lifetime')).toBe('day')
	})

	it('puts a 24-hour short the reader just posted into their own place, and not a kept one', async () => {
		const wrapper = mountBar([short('2', bob)])
		await flushPromises()

		await wrapper.find('.shorts-bar__add').trigger('click')
		wrapper.findComponent({ name: 'ShortComposerDialog' }).vm.$emit('posted', { id: '8' }, 'kept')
		await flushPromises()
		expect(tiles(wrapper)[0].classes()).toContain('shorts-bar__tile--empty')

		wrapper.findComponent({ name: 'ShortComposerDialog' }).vm.$emit('posted', short('9', alice), 'day')
		await flushPromises()
		expect(tiles(wrapper)[0].classes()).not.toContain('shorts-bar__tile--empty')
	})

	it('draws the reader\'s own place before the account store has caught up', async () => {
		const wrapper = mountBar([short('2', bob)], { current: null })
		await flushPromises()

		expect(tiles(wrapper).map((tile) => tile.text())).toEqual(['Your shorts', 'Bob', 'All shorts'])
	})

	it('draws no row when the carousel could not be loaded', async () => {
		get.mockRejectedValue(new Error('nope'))
		const pinia = createPinia()
		setActivePinia(pinia)
		useAccountStore().addAccount({ actorId: alice.url, data: alice })
		useAccountStore().setCurrentAccount('alice@cloud.example.org')

		const wrapper = mount(ShortsBar, { global: { plugins: [pinia], stubs } })
		await flushPromises()

		// a carousel that did not load has nobody in it
		expect(wrapper.find('.shorts-bar__list').exists()).toBe(false)
	})

	/** The row is for watching: a lone "Your shorts" is a place to make one, which the composer's camera is. */
	it('draws no row until somebody the reader follows has a 24-hour short up', async () => {
		const empty = await mountBarAnd([])
		expect(empty.find('.shorts-bar__list').exists()).toBe(false)
		expect(empty.find('.shorts-bar__heading').exists()).toBe(false)
		expect(empty.find('.shorts-bar').classes()).toContain('shorts-bar--empty')
		const ownOnly = await mountBarAnd([short('1', alice)])
		expect(ownOnly.find('.shorts-bar__list').exists()).toBe(false)

		const withOthers = await mountBarAnd([short('1', alice), short('2', bob)])
		expect(withOthers.find('.shorts-bar__list').exists()).toBe(true)
		expect(withOthers.find('.shorts-bar__heading').text()).toBe('Shorts')
	})

	it('opens New short on 24 hours when the post composer\'s camera asks, with or without a row', async () => {
		const wrapper = await mountBarAnd([])

		eventBus.emit(SHORT_COMPOSE)
		await flushPromises()

		expect(wrapper.findComponent({ name: 'ShortComposerDialog' }).props('lifetime')).toBe('day')

		wrapper.unmount()
		eventBus.emit(SHORT_COMPOSE)
	})

	it('is not drawn where the admin turned 24-hour shorts off', async () => {
		const wrapper = mountBar([short('2', bob)], { serverData: { sections: { stories: false } } })
		await flushPromises()

		expect(wrapper.find('.shorts-bar').exists()).toBe(false)
	})
})
