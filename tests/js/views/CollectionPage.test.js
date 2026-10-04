/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import CollectionPage from '../../../src/views/CollectionPage.vue'
import { useAccountStore } from '../../../src/store/account.js'

const { get, put, del } = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), del: vi.fn() }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, put, delete: del } }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess }))

const alice = { id: '7', url: 'https://cloud.example.org/@alice', acct: 'alice', username: 'alice', display_name: 'Alice', avatar: '' }
const bob = { id: '9', url: 'https://remote.example/users/bob', acct: 'bob@remote.example', username: 'bob', display_name: 'Bob', avatar: '' }

const NcDialogStub = {
	name: 'NcDialog',
	props: ['open', 'buttons', 'name'],
	emits: ['update:open'],
	template: '<div v-if="open" class="nc-dialog"><span class="nc-dialog__name">{{ name }}</span>'
		+ '<button v-for="(button, index) in buttons" :key="index" :class="\'nc-dialog__button--\' + index" @click="button.callback()">{{ button.label }}</button><slot /></div>',
}

function post(id) {
	return { id, media_attachments: [{ type: 'image', preview_url: `https://cloud.example.org/${id}-small.jpg`, url: `https://cloud.example.org/${id}.jpg`, description: '' }], account: alice }
}

function collection(overrides = {}) {
	return { id: '3', title: 'Coast', description: 'grey days', visibility: 'public', size: 2, account: alice, posts: [], ...overrides }
}

function mountPage({ current = alice, id = '3' } = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	const store = useAccountStore()
	for (const one of [alice, bob]) {
		store.addAccount({ actorId: one.url, data: one })
	}
	if (current) {
		store.setCurrentAccount(current.acct.includes('@') ? current.acct : `${current.acct}@cloud.example.org`)
	}
	const $router = { push: vi.fn() }

	const wrapper = mount(CollectionPage, {
		props: { id },
		global: {
			plugins: [pinia],
			mocks: { $router },
			stubs: {
				RouterLink: RouterLinkStub,
				NcDialog: NcDialogStub,
				ActorAvatar: true,
				ProfileMediaGrid: { name: 'ProfileMediaGrid', props: ['posts', 'account', 'loading'], template: '<div class="grid-stub">{{ posts.length }}</div>' },
				NcEmptyContent: { template: '<div class="empty-stub" />', props: ['name', 'description'] },
			},
		},
	})

	return { wrapper, $router }
}

function answering(collectionData, posts) {
	get.mockImplementation((url) => Promise.resolve({ data: url.endsWith('/items') ? posts : collectionData }))
}

describe('CollectionPage', () => {
	beforeEach(() => {
		get.mockReset()
		put.mockReset()
		del.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
	})

	it('heads the page with the collection and draws its posts as the profile grid', async () => {
		answering(collection(), [post('101'), post('102')])

		const { wrapper } = mountPage()
		await flushPromises()

		expect(wrapper.find('.collection__title').text()).toContain('Coast')
		expect(wrapper.find('.collection__description').text()).toBe('grey days')
		const grid = wrapper.findComponent({ name: 'ProfileMediaGrid' })
		expect(grid.props('posts')).toHaveLength(2)
		expect(grid.props('account')).toBe('alice')
		// back to where the other albums are
		expect(wrapper.find('.collection__back').exists()).toBe(true)
	})

	it('shows the owner their three controls and nobody else any', async () => {
		answering(collection(), [post('101')])

		const { wrapper: mine } = mountPage()
		await flushPromises()
		expect(mine.find('.collection__actions').exists()).toBe(true)

		const { wrapper: theirs } = mountPage({ current: bob })
		await flushPromises()
		expect(theirs.find('.collection__actions').exists()).toBe(false)
	})

	it('saves an edit through the API and keeps the owner the answer left out', async () => {
		answering(collection(), [post('101')])
		put.mockResolvedValue({ data: { id: '3', title: 'Coastline', description: 'grey days', visibility: 'followers', size: 2 } })

		const { wrapper } = mountPage()
		await flushPromises()

		await wrapper.findAll('.collection__actions button')[0].trigger('click')
		await wrapper.find('.collection__form input').setValue('Coastline')
		await wrapper.find('.nc-dialog__button--1').trigger('click')
		await flushPromises()

		expect(put).toHaveBeenCalledWith(expect.stringContaining('/apps/social/api/v1/collections/3'), { title: 'Coastline', description: 'grey days', visibility: 'public' })
		expect(wrapper.find('.collection__title').text()).toContain('Coastline')
		expect(wrapper.find('.collection__actions').exists()).toBe(true)
	})

	it('takes a post out from its own square and counts it off', async () => {
		answering(collection(), [post('101'), post('102')])
		del.mockResolvedValue({ data: {} })

		const { wrapper } = mountPage()
		await flushPromises()

		await wrapper.findAll('.collection__actions button')[1].trigger('click')
		expect(wrapper.findAll('.collection__manage-cell')).toHaveLength(2)
		// a grid of squares, fetched as they scroll into view
		expect(wrapper.find('.collection__manage-image').attributes()).toMatchObject({ loading: 'lazy', decoding: 'async' })

		await wrapper.find('.collection__manage-remove').trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith(expect.stringContaining('/apps/social/api/v1/collections/3/items/101'))
		expect(wrapper.findAll('.collection__manage-cell')).toHaveLength(1)
		expect(wrapper.find('.collection__meta').text()).toContain('1')
	})

	it('deletes the collection and goes back to the shelf', async () => {
		answering(collection(), [])
		del.mockResolvedValue({ data: {} })

		const { wrapper, $router } = mountPage()
		await flushPromises()

		await wrapper.findAll('.collection__actions button')[2].trigger('click')
		await wrapper.find('.nc-dialog__button--1').trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith(expect.stringContaining('/apps/social/api/v1/collections/3'))
		expect($router.push).toHaveBeenCalledWith({ name: 'profile.collections', params: { account: 'alice' } })
	})

	it('tells a 404 apart from a failure', async () => {
		get.mockRejectedValue({ response: { status: 404 } })

		const { wrapper } = mountPage({ id: '99' })
		await flushPromises()

		expect(wrapper.find('.collection__error').text()).toContain('no such collection')
	})
})
