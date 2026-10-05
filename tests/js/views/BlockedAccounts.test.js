/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { showError } from '../../../src/services/toast.js'

import BlockedAccounts from '../../../src/views/BlockedAccounts.vue'
import { createPinia, setActivePinia } from 'pinia'
import { useAccountStore } from '../../../src/store/account.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

const bob = { id: '22', acct: 'bob@remote.tld', username: 'bob', display_name: 'Bob', avatar: 'https://remote.tld/bob.png' }
const carol = { id: '33', acct: 'carol@remote.tld', username: 'carol', display_name: 'Carol', avatar: 'https://remote.tld/carol.png' }
const dave = { id: '44', acct: 'dave', username: 'dave', display_name: '', avatar: 'https://cloud.example.org/dave.png' }

/** Answers /blocks, /mutes and /domain_blocks, whichever order they come in. */
function serve(blocked, muted, domains = []) {
	axios.get.mockImplementation((url) => {
		if (url.endsWith('/blocks')) {
			return Promise.resolve({ data: blocked })
		}
		if (url.endsWith('/mutes')) {
			return Promise.resolve({ data: muted })
		}
		if (url.endsWith('/domain_blocks')) {
			return Promise.resolve({ data: domains })
		}
		return Promise.reject(new Error(`unexpected ${url}`))
	})
}

async function mountView({ blocked = [bob], muted = [carol], domains = [], dispatch } = {}) {
	serve(blocked, muted, domains)
	const pinia = createPinia()
	setActivePinia(pinia)
	const accountStore = useAccountStore()
	const act = dispatch ?? vi.fn().mockResolvedValue({ id: '22' })
	vi.spyOn(accountStore, 'unblockAccount').mockImplementation(act)
	vi.spyOn(accountStore, 'unmuteAccount').mockImplementation(act)
	const wrapper = mount(BlockedAccounts, {
		global: {
			plugins: [pinia],
			stubs: {
				ActorAvatar: true,
				NcEmptyContent: { props: ['name'], template: '<div class="empty-content">{{ name }}</div>' },
				// it reads the reader's filters on mount, and its own suite covers
				// what it does with them
				FiltersSettings: { template: '<div class="filters-settings-stub" />' },
				// likewise: it asks the server what is being held, and its own
				// suite covers what it does with the answer
				NotificationRequests: { template: '<div class="notification-requests-stub" />' },
				RouterLink: RouterLinkStub,
			},
		},
	})
	await flushPromises()

	return { wrapper, accountStore }
}

const rows = (wrapper) => wrapper.findAll('.blocked-account')
const rowNames = (wrapper) => rows(wrapper).map((row) => row.find('.blocked-account__name').text())
const buttonByText = (root, text) => root.findAll('button').find((button) => button.text().includes(text))

describe('BlockedAccounts', () => {
	afterEach(() => {
		vi.clearAllMocks()
	})

	it('loads both lists from the endpoints that already exist', async () => {
		const { wrapper } = await mountView()

		expect(axios.get).toHaveBeenCalledWith(`${API}/blocks`)
		expect(axios.get).toHaveBeenCalledWith(`${API}/mutes`)
		expect(rowNames(wrapper)).toEqual(['Bob', 'Carol'])
	})

	it('falls back to the username when an account has no display name', async () => {
		const { wrapper } = await mountView({ blocked: [dave], muted: [] })

		expect(rowNames(wrapper)).toEqual(['dave'])
	})

	/**
	 * A reader who wants to stop reading something looks in one place, whether
	 * the something is a person, a server or a word. The keyword filters used
	 * to be in Settings, which is where a reader goes to change how the app
	 * behaves rather than to silence anything.
	 */
	it('holds the keyword filters, at an id another page can link to', async () => {
		const { wrapper } = await mountView()

		expect(wrapper.find('#filters .filters-settings-stub').exists()).toBe(true)
		expect(wrapper.findAll('h3').map((heading) => heading.text()))
			.toEqual(['Blocked', 'Muted', 'Hidden servers', 'Filtered words', 'Filtered notifications'])
	})

	/**
	 * The senders a notification policy is holding are one more thing the
	 * reader is not being shown, so they belong with the rest of it rather
	 * than behind a sidebar entry that is empty for most people.
	 */
	it('points at Activities for the senders a notification policy is keeping back, where they are now', async () => {
		const { wrapper } = await mountView()

		expect(wrapper.find('#filtered-notifications .notification-requests-stub').exists()).toBe(false)
		expect(wrapper.find('#filtered-notifications').text()).toContain('top of Activities')
		expect(wrapper.find('#filtered-notifications').text()).toContain('Who may reach you')
	})

	it('links each account to its profile', async () => {
		const { wrapper } = await mountView({ blocked: [bob], muted: [] })

		expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({
			name: 'profile',
			params: { account: 'bob@remote.tld' },
		})
	})

	it('shows an empty state per list', async () => {
		const { wrapper } = await mountView({ blocked: [], muted: [] })
		const empty = wrapper.findAll('.block-card__empty').map((el) => el.text())

		// a line apiece rather than an illustration apiece: two of the four
		// are empty on almost every instance, and a full empty state each
		// pushed the lists that do hold something off the screen
		expect(empty.map((line) => line.split(' — ')[0]))
			.toEqual(['No blocked accounts', 'No muted accounts', 'No hidden servers'])
	})

	it('shows the muted empty state while blocked accounts exist', async () => {
		const { wrapper } = await mountView({ blocked: [bob], muted: [] })

		expect(wrapper.findAll('.block-card__empty').map((line) => line.text().split(' — ')[0]))
			.toEqual(['No muted accounts', 'No hidden servers'])
		expect(rowNames(wrapper)).toEqual(['Bob'])
	})

	/** How many, where the eye already is; and nothing at all at zero. */
	it('counts each list in its own heading', async () => {
		const { wrapper } = await mountView({ blocked: [bob], muted: [] })

		expect(wrapper.findAll('.block-card__count').map((el) => el.text())).toEqual(['1'])
	})

	it('unblocks through the store and takes the row off the list', async () => {
		const dispatch = vi.fn().mockResolvedValue({ id: '22' })
		const { wrapper, accountStore } = await mountView({ blocked: [bob], muted: [], dispatch })

		await buttonByText(wrapper, 'Unblock').trigger('click')
		await flushPromises()

		// the store action is what keeps the profile page's relationship in step
		expect(accountStore.unblockAccount).toHaveBeenCalledWith({ id: '22' })
		expect(rowNames(wrapper)).toEqual([])
	})

	it('unmutes through the store and takes the row off the list', async () => {
		const dispatch = vi.fn().mockResolvedValue({ id: '33' })
		const { wrapper, accountStore } = await mountView({ blocked: [], muted: [carol], dispatch })

		await buttonByText(wrapper, 'Unmute').trigger('click')
		await flushPromises()

		expect(accountStore.unmuteAccount).toHaveBeenCalledWith({ id: '33' })
		expect(rowNames(wrapper)).toEqual([])
	})

	it('keeps the row when the server refuses, so nothing claims a success', async () => {
		// the store action reports its own error and resolves undefined
		const dispatch = vi.fn().mockResolvedValue(undefined)
		const { wrapper } = await mountView({ blocked: [bob], muted: [], dispatch })

		await buttonByText(wrapper, 'Unblock').trigger('click')
		await flushPromises()

		expect(rowNames(wrapper)).toEqual(['Bob'])
		expect(buttonByText(wrapper, 'Unblock').attributes('disabled')).toBeUndefined()
	})

	it('reports a failure to load and stops the spinner', async () => {
		axios.get.mockRejectedValue(new Error('boom'))
		const pinia = createPinia()
		setActivePinia(pinia)
		const wrapper = mount(BlockedAccounts, {
			global: {
				plugins: [pinia],
				stubs: {
					ActorAvatar: true,
					NcEmptyContent: { props: ['name'], template: '<div class="empty-content">{{ name }}</div>' },
					// it reads the reader's filters on mount, and its own suite covers
					// what it does with them
					FiltersSettings: { template: '<div class="filters-settings-stub" />' },
					// likewise: it asks the server what is being held, and its own
					// suite covers what it does with the answer
					NotificationRequests: { template: '<div class="notification-requests-stub" />' },
					RouterLink: RouterLinkStub,
				},
			},
		})
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Failed to load the blocked and muted accounts')
		expect(wrapper.find('.loading-indicator').exists()).toBe(false)
	})

	it('survives an answer that is not a list', async () => {
		const { wrapper } = await mountView({ blocked: null, muted: { error: 'not a list' } })

		expect(rows(wrapper)).toHaveLength(0)
		expect(showError).not.toHaveBeenCalled()
	})

	describe('taking an account off a list', () => {
		it('renders both lists through a transition group, so a row can collapse out of it', async () => {
			// the rows were plain siblings: an unblocked account blinked away
			// and the ones below jumped into the space it left
			const { wrapper } = await mountView({ blocked: [bob, dave], muted: [carol] })
			const groups = wrapper.findAll('transition-group-stub')

			expect(groups).toHaveLength(3)
			expect(groups.map((group) => group.attributes('name')))
				.toEqual(['collapse', 'collapse', 'collapse'])
			expect(groups[0].findAll('.blocked-account')).toHaveLength(2)
			expect(groups[1].findAll('.blocked-account')).toHaveLength(1)
		})

		it('keys the rows on the account, so the rows that stay are the very same rows', async () => {
			// keyed on the index, Vue answers a removal by patching Bob's row
			// into Dave and dropping the last one: the wrong row collapses and
			// Dave's row is rebuilt underneath the reader
			const { wrapper } = await mountView({ blocked: [bob, dave], muted: [] })
			const before = rows(wrapper).map((row) => row.element)

			await buttonByText(rows(wrapper)[0], 'Unblock').trigger('click')
			await flushPromises()

			const after = rows(wrapper).map((row) => row.element)
			expect(after).toHaveLength(1)
			expect(after[0]).toBe(before[1])
			expect(rowNames(wrapper)).toEqual(['dave'])
		})

		it('says the list is empty once the last one is gone, and only that list', async () => {
			const { wrapper } = await mountView({
				blocked: [bob],
				muted: [carol],
				domains: ['noisy.example'],
			})
			expect(wrapper.findAll('.block-card__empty')).toHaveLength(0)

			await buttonByText(rows(wrapper)[0], 'Unblock').trigger('click')
			await flushPromises()

			// the muted list still has Carol and the servers still have one, so
			// only the list that emptied says anything
			const empty = wrapper.findAll('.block-card__empty')
			expect(empty).toHaveLength(1)
			expect(empty[0].text()).toContain('No blocked accounts')
		})
	})

	describe('hiding a whole server', () => {
		it('lists the servers this account has hidden', async () => {
			const { wrapper } = await mountView({ domains: ['noisy.example', 'worse.example'] })

			expect(wrapper.text()).toContain('noisy.example')
			expect(wrapper.text()).toContain('worse.example')
		})

		/**
		 * The server decides what a domain normalises to, so the list is read
		 * again rather than guessed at — a row saying something else would be a
		 * row the unhide button could not act on.
		 */
		it('sends the server to hide and reads the list back', async () => {
			axios.post.mockResolvedValue({ data: [] })
			const { wrapper } = await mountView({ domains: [] })

			await wrapper.find('.blocked-domain__field input').setValue('  Noisy.Example  ')
			await wrapper.find('.blocked-domain__add').trigger('submit')
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/api/v1/domain_blocks'),
				{ domain: 'noisy.example' },
			)
			expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('/domain_blocks'))
		})

		it('shows one again and takes it off the list', async () => {
			axios.delete.mockResolvedValue({ data: [] })
			const { wrapper } = await mountView({ domains: ['noisy.example'] })

			const row = wrapper.findAll('.blocked-account').at(-1)
			await buttonByText(row, 'Show again').trigger('click')
			await flushPromises()

			expect(axios.delete).toHaveBeenCalledWith(
				expect.stringContaining('/api/v1/domain_blocks'),
				{ data: { domain: 'noisy.example' } },
			)
			expect(wrapper.text()).toContain('No hidden servers')
		})
	})
})
