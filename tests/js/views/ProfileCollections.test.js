/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ProfileCollections from '../../../src/views/ProfileCollections.vue'
import TimelineSwitcher from '../../../src/components/TimelineSwitcher.vue'
import { useAccountStore } from '../../../src/store/account.js'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess }))

const alice = { id: '7', url: 'https://cloud.example.org/@alice', acct: 'alice', username: 'alice', display_name: 'Alice' }
const bob = { id: '9', url: 'https://remote.example/users/bob', acct: 'bob@remote.example', username: 'bob', display_name: 'Bob' }

function collection(overrides = {}) {
	return {
		id: '3',
		title: 'Coast',
		description: '',
		visibility: 'public',
		size: 4,
		posts: [{ id: '101', media_attachments: [{ preview_url: 'https://cloud.example.org/small.jpg', url: 'https://cloud.example.org/full.jpg' }] }],
		...overrides,
	}
}

function mountView(account, { current = alice } = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	const store = useAccountStore()
	for (const one of [alice, bob]) {
		store.addAccount({ actorId: one.url, data: one })
	}
	if (current) {
		store.setCurrentAccount(current.acct.includes('@') ? current.acct : `${current.acct}@cloud.example.org`)
	}

	return mount(ProfileCollections, {
		global: {
			plugins: [pinia],
			mocks: { $route: { name: 'profile.collections', params: { account } } },
			stubs: { RouterLink: RouterLinkStub, NcEmptyContent: { template: '<div class="empty-stub"><slot /></div>', props: ['name', 'description'] } },
		},
	})
}

describe('ProfileCollections', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
	})

	it('asks the server for the collections of the account on screen and draws them as cards', async () => {
		get.mockResolvedValue({ data: [collection(), collection({ id: '4', title: 'Nights', visibility: 'followers', size: 1, posts: [] })] })

		const wrapper = mountView('bob@remote.example')
		await flushPromises()

		// by the handle the route carries: the server resolves an account by
		// its id, its handle or its actor URL, so the page waits for nothing
		expect(get.mock.calls[0][0]).toContain('/apps/social/api/v1/accounts/bob%40remote.example/collections')
		const cards = wrapper.findAll('.collections__card')
		expect(cards).toHaveLength(2)
		expect(cards[0].text()).toContain('Coast')
		expect(cards[0].find('img').attributes('src')).toBe('https://cloud.example.org/small.jpg')
		// a followers-only album is marked, and one without a picture gets a placeholder
		expect(cards[1].find('.collections__lock').exists()).toBe(true)
		expect(cards[1].find('.collections__cover-empty').exists()).toBe(true)
		expect(cards[0].findComponent(RouterLinkStub).props('to')).toEqual({ name: 'collection', params: { id: '3' } })
	})

	it('puts Collections on the profile switcher as the last tab', async () => {
		get.mockResolvedValue({ data: [] })

		const wrapper = mountView('bob@remote.example')
		await flushPromises()

		const switcher = wrapper.findComponent(TimelineSwitcher)
		expect(switcher.props('value')).toBe('collections')
		expect(switcher.props('options').map((option) => option.label)).toEqual(['Posts', 'Photos', 'Videos', 'Tagged', 'Collections'])
	})

	it('lets the owner make a new one where the others are, and nobody else', async () => {
		get.mockResolvedValue({ data: [] })

		const theirs = mountView('bob@remote.example')
		await flushPromises()
		expect(theirs.find('.collections__create').exists()).toBe(false)

		const mine = mountView('alice')
		await flushPromises()
		expect(mine.find('.collections__create').exists()).toBe(true)

		post.mockResolvedValue({ data: collection({ id: '5', title: 'Mountains', size: 0, posts: [] }) })
		await mine.find('.collections__create input').setValue('Mountains')
		await mine.find('.collections__create').trigger('submit')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(expect.stringContaining('/apps/social/api/v1/collections'), { title: 'Mountains', visibility: 'public' })
		expect(mine.findAll('.collections__card')[0].text()).toContain('Mountains')
		expect(showSuccess).toHaveBeenCalled()
	})

	it('asks by the handle while the account store is still empty, rather than sitting on a spinner', async () => {
		get.mockResolvedValue({ data: [] })
		const pinia = createPinia()
		setActivePinia(pinia)
		const wrapper = mount(ProfileCollections, {
			global: {
				plugins: [pinia],
				mocks: { $route: { name: 'profile.collections', params: { account: 'bob@remote.example' } } },
				stubs: { RouterLink: RouterLinkStub, NcEmptyContent: { template: '<div class="empty-stub" />', props: ['name', 'description'] } },
			},
		})
		await flushPromises()

		expect(get.mock.calls[0][0]).toContain('/accounts/bob%40remote.example/collections')
		expect(wrapper.find('.collections__loading').exists()).toBe(false)
	})

	it('draws an empty shelf the way every empty list is drawn, and tells the owner how to fill it', async () => {
		get.mockResolvedValue({ data: [] })

		const theirs = mountView('bob@remote.example')
		await flushPromises()
		const empty = theirs.findComponent({ name: 'EmptyContent' }).props('item')
		expect(empty).toMatchObject({ illustration: 'quiet-timeline', title: 'No collections yet' })
		expect(empty.description).toContain('Albums this account makes')

		const mine = mountView('alice')
		await flushPromises()
		expect(mine.findComponent({ name: 'EmptyContent' }).props('item').description).toContain('name one above')
	})

	it('says so when the list could not be loaded, rather than showing an empty shelf', async () => {
		get.mockRejectedValue(new Error('nope'))

		const wrapper = mountView('bob@remote.example')
		await flushPromises()

		expect(wrapper.find('.collections__error').exists()).toBe(true)
		expect(wrapper.find('.empty-stub').exists()).toBe(false)
	})
})
