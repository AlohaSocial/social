/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import ListsSettings from '../../../src/components/ListsSettings.vue'
import eventBus, { LISTS_CHANGED } from '../../../src/services/eventBus.js'
import { showError } from '../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

// NcActions only renders its entries in a popover once it is opened; these
// stand-ins put them inline, where a row's own buttons already are
const NcActionsStub = { name: 'NcActions', template: '<div class="list-menu"><slot /></div>' }
const NcActionButtonStub = {
	name: 'NcActionButton',
	emits: ['click'],
	template: '<button class="list-menu__item" @click="$emit(\'click\')"><slot /></button>',
}

const list = (id, title, extra = {}) => ({ id, title, replies_policy: 'list', ...extra })
const bob = {
	id: 'https://remote.example/users/bob',
	acct: 'bob@remote.example',
	username: 'bob',
	display_name: 'Bob',
}

/** @param {object[]} lists what `GET /lists` answers with */
async function mountLists(lists = []) {
	axios.get.mockImplementation((url) => {
		if (url.endsWith('/lists')) {
			return Promise.resolve({ data: lists })
		}

		return Promise.resolve({ data: [] })
	})
	const pinia = createPinia()
	setActivePinia(pinia)
	const wrapper = mount(ListsSettings, {
		global: {
			plugins: [pinia],
			// the avatar opens an account card of its own, which is a page's
			// worth of component for a row in a settings list
			stubs: {
				RouterLink: RouterLinkStub,
				NcDialog: true,
				ActorAvatar: true,
				NcActions: NcActionsStub,
				NcActionButton: NcActionButtonStub,
			},
		},
	})
	await flushPromises()

	return wrapper
}

const rows = (wrapper) => wrapper.findAll('.lists-settings__item')
const rowFor = (wrapper, title) => rows(wrapper).find((row) => row.text().includes(title))
const actionLabels = (row) => row.findAll('button').map((button) => button.text()).filter((text) => text !== '')

describe('ListsSettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('asks for the lists as it opens', async () => {
		await mountLists()

		expect(axios.get).toHaveBeenCalledWith(`${API}/lists`)
	})

	it('says what a list is when there are none', async () => {
		const wrapper = await mountLists()

		expect(wrapper.text()).toContain('You have no lists yet.')
	})

	it('lists the group ones first, since nobody made those', async () => {
		const wrapper = await mountLists([
			list('1', 'Book club'),
			list('2', 'Design', { nextcloud_group: 'design' }),
		])

		expect(rows(wrapper).map((row) => row.find('.lists-settings__title').text()))
			.toEqual(['Design', 'Book club'])
	})

	it('creates a list under the name it was given', async () => {
		const wrapper = await mountLists()
		axios.post.mockResolvedValue({ data: list('3', 'Book club') })

		await wrapper.find('.lists-settings__create-title input').setValue('Book club')
		await wrapper.find('.lists-settings__create').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/lists`, { title: 'Book club' })
		expect(rowFor(wrapper, 'Book club')).toBeDefined()
	})

	it('will not create a list with no name', async () => {
		const wrapper = await mountLists()

		await wrapper.find('.lists-settings__create-title input').setValue('   ')
		await wrapper.find('.lists-settings__create').trigger('submit')
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('renames a list, and carries its reply policy so nothing is reset', async () => {
		const wrapper = await mountLists([list('1', 'Book club')])
		axios.put.mockResolvedValue({ data: list('1', 'Reading group') })

		await rowFor(wrapper, 'Book club').findAll('button').find((b) => b.text() === 'Rename').trigger('click')
		await wrapper.find('.lists-settings__rename input').setValue('Reading group')
		await wrapper.find('.lists-settings__rename').trigger('submit')
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith(`${API}/lists/1`, {
			title: 'Reading group',
			replies_policy: 'list',
		})
	})

	it('deletes a list once the confirmation has agreed', async () => {
		const wrapper = await mountLists([list('1', 'Book club')])
		axios.delete.mockResolvedValue({ data: {} })

		await rowFor(wrapper, 'Book club').findAll('button').find((b) => b.text() === 'Delete').trigger('click')
		// the dialog is stubbed; its button is a prop rather than markup
		const confirm = wrapper.findComponent({ name: 'NcDialog' }).props('buttons')
			.find((button) => button.label === 'Delete')
		await confirm.callback()
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith(`${API}/lists/1`)
		expect(rows(wrapper)).toHaveLength(0)
	})

	it('asks for the members of a list when it is opened, and as many as it may', async () => {
		const wrapper = await mountLists([list('1', 'Book club')])
		axios.get.mockResolvedValue({ data: [bob] })

		await rowFor(wrapper, 'Book club').findAll('button').find((b) => b.text() === 'Members').trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(`${API}/lists/1/accounts`, { params: { limit: 500 } })
		expect(wrapper.find('.lists-settings__member-acct').text()).toBe('@bob@remote.example')
	})

	/**
	 * This asked for `limit=0` — Mastodon's "all accounts without pagination" —
	 * and the panel only ever showed "Could not load who is in the list".
	 * Nextcloud's dispatcher applies a range of 1–500 to every parameter named
	 * `limit` and throws before the route is reached, so 0 came back as a 500
	 * and an HTML error page. The assertion above used to pin the 0, which is
	 * why no test caught it.
	 */
	it('never asks for a limit the platform refuses', async () => {
		const wrapper = await mountLists([list('1', 'Book club')])
		axios.get.mockResolvedValue({ data: [bob] })

		await rowFor(wrapper, 'Book club').findAll('button').find((b) => b.text() === 'Members').trigger('click')
		await flushPromises()

		const { limit } = axios.get.mock.calls.at(-1)[1].params

		expect(limit).toBeGreaterThanOrEqual(1)
		expect(limit).toBeLessThanOrEqual(500)
	})

	it('adds somebody the search found, by their actor id', async () => {
		const wrapper = await mountLists([list('1', 'Book club')])
		axios.get.mockResolvedValue({ data: [] })
		await rowFor(wrapper, 'Book club').findAll('button').find((b) => b.text() === 'Members').trigger('click')
		await flushPromises()

		// the search answers as the global account search does: actors, whose
		// `id` is the URL the list route wants
		axios.get.mockResolvedValue({ data: { result: { accounts: [{ id: bob.id, account: bob.acct, name: 'Bob' }] } } })
		await wrapper.findAll('input').at(-1).setValue('bob')
		await vi.waitUntil(() => wrapper.find('.lists-settings__result').exists(), { timeout: 5000 })

		axios.post.mockResolvedValue({ data: {} })
		axios.get.mockResolvedValue({ data: [bob] })
		await wrapper.find('.lists-settings__result').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/lists/1/accounts`, { account_ids: [bob.id] })
	})

	describe('when searches overlap (#2335)', () => {
		/**
		 * @param {object} wrapper the mounted settings
		 * @return {Promise<Function>} asks the search route, answering each call only when told
		 */
		async function openSearch(wrapper) {
			await rowFor(wrapper, 'Book club').findAll('button').find((b) => b.text() === 'Members').trigger('click')
			await flushPromises()
			const calls = []
			axios.get.mockImplementation(() => new Promise((resolve, reject) => calls.push({ resolve, reject })))

			return (term) => {
				wrapper.vm.search = term
				wrapper.vm.runSearch(term)

				return calls.at(-1)
			}
		}
		const found = (name) => ({ data: { result: { accounts: [{ id: `https://remote.example/users/${name}`, account: `${name}@remote.example`, name }] } } })

		it('keeps the newer answer when an older search fails last', async () => {
			const wrapper = await mountLists([list('1', 'Book club')])
			const ask = await openSearch(wrapper)

			const older = ask('al')
			const newer = ask('bob')
			newer.resolve(found('bob'))
			await flushPromises()
			older.reject(new Error('timed out'))
			await flushPromises()

			expect(wrapper.findAll('.lists-settings__result').map((hit) => hit.text())).toEqual([expect.stringContaining('bob')])
			expect(wrapper.text()).not.toContain('Nobody by that name.')
		})

		it('lets only the newest of two asks for the same words own the answer', async () => {
			const wrapper = await mountLists([list('1', 'Book club')])
			const ask = await openSearch(wrapper)

			const first = ask('bob')
			const retry = ask('bob')
			retry.resolve(found('bob'))
			await flushPromises()
			first.reject(new Error('timed out'))
			await flushPromises()

			expect(wrapper.findAll('.lists-settings__result')).toHaveLength(1)

			const again = ask('bob')
			const last = ask('bob')
			last.resolve(found('robert'))
			await flushPromises()
			again.resolve(found('bob'))
			await flushPromises()

			expect(wrapper.find('.lists-settings__result').text()).toContain('robert')
		})

		it('still says so when the newest search itself fails', async () => {
			const wrapper = await mountLists([list('1', 'Book club')])
			const ask = await openSearch(wrapper)

			ask('bob').reject(new Error('timed out'))
			await flushPromises()

			expect(wrapper.findAll('.lists-settings__result')).toHaveLength(0)
			expect(wrapper.text()).toContain('Nobody by that name.')
		})
	})

	it('takes somebody out of a list with the ids on the address', async () => {
		const wrapper = await mountLists([list('1', 'Book club')])
		axios.get.mockResolvedValue({ data: [bob] })
		await rowFor(wrapper, 'Book club').findAll('button').find((b) => b.text() === 'Members').trigger('click')
		await flushPromises()

		axios.delete.mockResolvedValue({ data: {} })
		await wrapper.find('.lists-settings__member button').trigger('click')
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith(`${API}/lists/1/accounts`, {
			params: { account_ids: [bob.id] },
		})
		expect(wrapper.find('.lists-settings__member').exists()).toBe(false)
	})

	describe('a list longer than one page', () => {
		const member = (n) => ({ id: `https://remote.example/users/u${n}`, acct: `u${n}@remote.example`, username: `u${n}`, display_name: `U${n}` })
		const page = (from, count) => Array.from({ length: count }, (_, i) => member(from + i))
		const next = (maxId) => ({ link: `</index.php/apps/social/api/v1/lists/1/accounts?limit=500&max_id=${maxId}>; rel="next"` })

		async function openMembers(wrapper, title = 'Book club') {
			await rowFor(wrapper, title).findAll('button').find((b) => b.text() === 'Members').trigger('click')
			await flushPromises()
		}
		const showMore = (wrapper) => wrapper.find('.lists-settings__more button')

		// whether there is more is the Link header's to say, not the page's
		// length, so a first page of five with a next link stands for 500

		it('offers the next page when the server says there is one, and asks for it by the Link cursor', async () => {
			const wrapper = await mountLists([list('1', 'Book club')])
			axios.get.mockResolvedValueOnce({ data: page(0, 5), headers: next('7001') })
			await openMembers(wrapper)

			expect(wrapper.findAll('.lists-settings__member')).toHaveLength(5)
			expect(showMore(wrapper).exists()).toBe(true)

			axios.get.mockResolvedValueOnce({ data: page(5, 3), headers: {} })
			await showMore(wrapper).trigger('click')
			await flushPromises()

			// the cursor is the membership row the header names, not an account id
			expect(axios.get).toHaveBeenLastCalledWith(`${API}/lists/1/accounts`, { params: { limit: 500, max_id: '7001' } })
			expect(wrapper.findAll('.lists-settings__member')).toHaveLength(8)
			expect(showMore(wrapper).exists()).toBe(false)
		})

		it('draws nobody twice when the second page repeats somebody from the first', async () => {
			const wrapper = await mountLists([list('1', 'Book club')])
			axios.get.mockResolvedValueOnce({ data: page(0, 5), headers: next('7001') })
			await openMembers(wrapper)

			axios.get.mockResolvedValueOnce({ data: page(3, 4), headers: {} })
			await showMore(wrapper).trigger('click')
			await flushPromises()

			const accts = wrapper.findAll('.lists-settings__member-acct').map((el) => el.text())
			expect(accts).toHaveLength(7)
			expect(new Set(accts).size).toBe(7)
		})

		it('takes out somebody who was only on the second page', async () => {
			const wrapper = await mountLists([list('1', 'Book club')])
			axios.get.mockResolvedValueOnce({ data: page(0, 5), headers: next('7001') })
			await openMembers(wrapper)
			axios.get.mockResolvedValueOnce({ data: [bob], headers: {} })
			await showMore(wrapper).trigger('click')
			await flushPromises()

			axios.delete.mockResolvedValue({ data: {} })
			const bobRow = wrapper.findAll('.lists-settings__member').find((row) => row.text().includes('@bob@remote.example'))
			await bobRow.find('button').trigger('click')
			await flushPromises()

			expect(axios.delete).toHaveBeenCalledWith(`${API}/lists/1/accounts`, { params: { account_ids: [bob.id] } })
			expect(wrapper.text()).not.toContain('@bob@remote.example')
			expect(wrapper.findAll('.lists-settings__member')).toHaveLength(5)
		})

		it('offers no more when the first page is the whole list', async () => {
			const wrapper = await mountLists([list('1', 'Book club')])
			axios.get.mockResolvedValueOnce({ data: page(0, 12), headers: {} })
			await openMembers(wrapper)

			expect(wrapper.findAll('.lists-settings__member')).toHaveLength(12)
			expect(showMore(wrapper).exists()).toBe(false)
			expect(axios.get).toHaveBeenCalledTimes(2)
		})

		it('pages a list a Nextcloud group makes too', async () => {
			const wrapper = await mountLists([list('2', 'Design', { nextcloud_group: 'design' })])
			axios.get.mockResolvedValueOnce({ data: page(0, 5), headers: next('9') })
			await openMembers(wrapper, 'Design')

			expect(showMore(wrapper).exists()).toBe(true)
		})
	})

	/** The group decides all three, and the server answers 422 to anybody else. */
	it('offers no rename, no delete and no adding for a list a Nextcloud group makes', async () => {
		const wrapper = await mountLists([list('2', 'Design', { nextcloud_group: 'design' })])
		const row = rowFor(wrapper, 'Design')

		expect(row.text()).toContain('Nextcloud group')
		expect(actionLabels(row)).toEqual(['Members'])

		axios.get.mockResolvedValue({ data: [bob] })
		await row.findAll('button').find((b) => b.text() === 'Members').trigger('click')
		await flushPromises()

		expect(wrapper.find('.lists-settings__add').exists()).toBe(false)
		expect(wrapper.find('.lists-settings__member button').exists()).toBe(false)
	})

	/**
	 * Without this the panel is a list of people with no way to add one and no
	 * reason given, which reads as a broken autocomplete rather than as a list
	 * the group owns.
	 */
	it('says where the members of a group list come from, since they cannot be edited here', async () => {
		const wrapper = await mountLists([list('2', 'Design', { nextcloud_group: 'design' })])
		const row = rowFor(wrapper, 'Design')

		axios.get.mockResolvedValue({ data: [bob] })
		await row.findAll('button').find((b) => b.text() === 'Members').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('“design” group in Nextcloud')
	})

	it('offers the box to type in on a list the reader made themselves', async () => {
		const wrapper = await mountLists([list('1', 'Book club')])
		axios.get.mockResolvedValue({ data: [] })

		await rowFor(wrapper, 'Book club').findAll('button').find((b) => b.text() === 'Members').trigger('click')
		await flushPromises()

		expect(wrapper.find('.lists-settings__add').exists()).toBe(true)
		expect(wrapper.text()).not.toContain('group in Nextcloud')
	})

	describe('public lists', () => {
		const publicSwitch = (wrapper) => wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
		const openMembers = async (wrapper, title) => {
			await rowFor(wrapper, title).findAll('button').find((b) => b.text() === 'Members').trigger('click')
			await flushPromises()
		}

		it('makes a list public with the switch, and says so on its row', async () => {
			const wrapper = await mountLists([list('1', 'Book club')])
			await openMembers(wrapper, 'Book club')

			expect(publicSwitch(wrapper).text()).toBe('Public list — anyone can see it and who is on it')
			expect(publicSwitch(wrapper).props('modelValue')).toBe(false)
			expect(wrapper.text()).toContain('under “Who can reply” on a post can be seen from that post, even while it is not public')

			axios.put.mockResolvedValue({ data: list('1', 'Book club', { public: true }) })
			publicSwitch(wrapper).vm.$emit('update:modelValue', true)
			await flushPromises()

			expect(axios.put).toHaveBeenCalledWith(`${API}/lists/1`, { title: 'Book club', replies_policy: 'list', public: true })
			expect(publicSwitch(wrapper).props('modelValue')).toBe(true)
			expect(rowFor(wrapper, 'Book club').find('.lists-settings__badge').text()).toBe('Public')
		})

		it('makes a public list private again', async () => {
			const wrapper = await mountLists([list('1', 'Book club', { public: true })])
			await openMembers(wrapper, 'Book club')
			axios.put.mockResolvedValue({ data: list('1', 'Book club', { public: false }) })

			publicSwitch(wrapper).vm.$emit('update:modelValue', false)
			await flushPromises()

			expect(axios.put).toHaveBeenCalledWith(`${API}/lists/1`, { title: 'Book club', replies_policy: 'list', public: false })
			expect(rowFor(wrapper, 'Book club').find('.lists-settings__badge').exists()).toBe(false)
		})

		it('keeps the list as it was and says so when the server refuses', async () => {
			const wrapper = await mountLists([list('1', 'Book club')])
			await openMembers(wrapper, 'Book club')
			axios.put.mockRejectedValue(new Error('nope'))

			publicSwitch(wrapper).vm.$emit('update:modelValue', true)
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not change who can see the list')
			expect(publicSwitch(wrapper).props('modelValue')).toBe(false)
		})

		it('offers no switch on a list a Nextcloud group makes', async () => {
			const wrapper = await mountLists([list('2', 'Design', { nextcloud_group: 'design' })])
			axios.get.mockResolvedValue({ data: [bob] })
			await openMembers(wrapper, 'Design')

			expect(publicSwitch(wrapper).exists()).toBe(false)
		})
	})

	it('tells the sidebar whenever the lists have changed', async () => {
		const heard = vi.fn()
		eventBus.on(LISTS_CHANGED, heard)
		const wrapper = await mountLists()
		axios.post.mockResolvedValue({ data: list('3', 'Book club') })

		await wrapper.find('.lists-settings__create-title input').setValue('Book club')
		await wrapper.find('.lists-settings__create').trigger('submit')
		await flushPromises()

		expect(heard).toHaveBeenCalled()
		eventBus.off(LISTS_CHANGED, heard)
	})
})
