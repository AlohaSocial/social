/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import StoryBar from '../../../src/components/StoryBar.vue'
import { useAccountStore } from '../../../src/store/account.js'

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

function story(id, account, seen = false) {
	return { id, account, seen, duration: 5, caption: '', created_at: '2026-09-15T10:00:00Z', media: { type: 'image', url: `https://cloud.example.org/${id}.jpg` } }
}

const StoryViewerStub = { name: 'StoryViewer', props: ['groups', 'start'], emits: ['close', 'seen', 'deleted'], template: '<div class="viewer-stub" :data-start="start" />' }
const StoryComposerStub = { name: 'StoryComposerDialog', props: ['open', 'initialFile'], emits: ['update:open', 'posted'], template: '<div class="composer-stub" />' }
const ShortComposerStub = { name: 'ShortComposerDialog', props: ['open', 'lifetime'], emits: ['update:open', 'posted'], template: '<div class="short-stub" />' }

function mountBar(stories, { current = alice } = {}) {
	get.mockResolvedValue({ data: stories })
	const pinia = createPinia()
	setActivePinia(pinia)
	const store = useAccountStore()
	store.addAccount({ actorId: alice.url, data: alice })
	if (current) {
		store.setCurrentAccount('alice@cloud.example.org')
	}

	return mount(StoryBar, {
		global: {
			plugins: [pinia],
			stubs: { StoryViewer: StoryViewerStub, StoryComposerDialog: StoryComposerStub, ShortComposerDialog: ShortComposerStub, ActorAvatar: true },
		},
	})
}

describe('StoryBar', () => {
	beforeEach(() => {
		get.mockReset()
	})

	it('groups the carousel by account, the reader first, and rings the faces with something unseen', async () => {
		const wrapper = mountBar([story('1', alice, true), story('2', bob, true), story('3', bob, false), story('4', carol, true)])
		await flushPromises()

		expect(get.mock.calls[0][0]).toContain('/apps/social/api/v1/stories/carousel')
		const tiles = wrapper.findAll('.story-bar__tile')
		expect(tiles).toHaveLength(3)
		expect(tiles[0].text()).toBe('Your story')
		expect(tiles[1].text()).toBe('Bob')
		expect(tiles[1].classes()).toContain('story-bar__tile--unseen')
		expect(tiles[2].classes()).not.toContain('story-bar__tile--unseen')
	})

	it('keeps the reader\'s own place even with nothing in it, and opens the short composer from it', async () => {
		const wrapper = mountBar([story('2', bob)])
		await flushPromises()

		const own = wrapper.findAll('.story-bar__tile')[0]
		expect(own.classes()).toContain('story-bar__tile--empty')
		await own.trigger('click')

		const composer = wrapper.findComponent({ name: 'ShortComposerDialog' })
		expect(composer.exists()).toBe(true)
		expect(composer.props('lifetime')).toBe('day')
		expect(wrapper.findComponent({ name: 'StoryViewer' }).exists()).toBe(false)
	})

	it('plays the tapped account\'s stories and lets the viewer\'s "seen" take the ring off', async () => {
		const wrapper = mountBar([story('2', bob, false)])
		await flushPromises()

		await wrapper.findAll('.story-bar__tile')[1].trigger('click')
		const viewer = wrapper.findComponent({ name: 'StoryViewer' })
		expect(viewer.exists()).toBe(true)
		expect(viewer.props('start')).toBe(0)
		expect(viewer.props('groups')[0].account.acct).toBe('bob@remote.example')

		viewer.vm.$emit('seen', '2')
		await flushPromises()
		expect(wrapper.findAll('.story-bar__tile')[1].classes()).not.toContain('story-bar__tile--unseen')
	})

	it('puts a story the reader just posted into their own place', async () => {
		const wrapper = mountBar([])
		await flushPromises()

		await wrapper.find('.story-bar__add').trigger('click')
		wrapper.findComponent({ name: 'ShortComposerDialog' }).vm.$emit('posted', story('9', alice))
		await flushPromises()

		expect(wrapper.findAll('.story-bar__tile')[0].classes()).not.toContain('story-bar__tile--empty')
	})

	it('draws the reader\'s own place before the account store has caught up', async () => {
		get.mockResolvedValue({ data: [story('2', bob)] })
		const pinia = createPinia()
		setActivePinia(pinia)
		// no account in the store at all: the request that fills it may not
		// have come back, and the bar must not wait for it
		const wrapper = mount(StoryBar, {
			global: { plugins: [pinia], stubs: { StoryViewer: StoryViewerStub, StoryComposerDialog: StoryComposerStub, ShortComposerDialog: ShortComposerStub, ActorAvatar: true } },
		})
		await flushPromises()

		const tiles = wrapper.findAll('.story-bar__tile')
		expect(tiles).toHaveLength(2)
		expect(tiles[0].text()).toBe('Your story')
	})

	it('draws nothing when the carousel could not be loaded, rather than a toast over the feed', async () => {
		get.mockRejectedValue(new Error('nope'))
		const pinia = createPinia()
		setActivePinia(pinia)
		useAccountStore().addAccount({ actorId: alice.url, data: alice })
		useAccountStore().setCurrentAccount('alice@cloud.example.org')

		const wrapper = mount(StoryBar, { global: { plugins: [pinia], stubs: { StoryViewer: true, StoryComposerDialog: true, ShortComposerDialog: true, ActorAvatar: true } } })
		await flushPromises()

		expect(wrapper.findAll('.story-bar__tile')).toHaveLength(1)
	})
})
