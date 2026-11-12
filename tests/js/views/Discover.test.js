/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import Discover from '../../../src/views/Discover.vue'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/${path}` }))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	n: (app, one, many, count) => String(count),
	// the view now renders ActorAvatar, and @nextcloud/vue reads the text
	// direction out of this module while its own module body runs
	isRTL: () => false,
	getLanguage: () => 'en',
	getCanonicalLocale: () => 'en',
	translate: (app, text) => text,
	translatePlural: (app, one, many, count) => String(count),
}))

/**
 * The methods are exercised directly against a plain state object: what is
 * worth pinning is which route each tab asks and how a pack behaves, none of
 * which needs a DOM.
 *
 * @param {object} overrides anything to change about the state
 * @return {object} a `this` for calling the component's methods against
 */
/**
 * A stand-in for the component's own `data()`, which is never called here.
 * Every property the methods touch has to be listed: one that is missing is
 * `undefined` rather than an error, and a counter compared against
 * `undefined + 1` is `NaN === NaN` — false — which silently turns off the
 * staleness guards instead of failing.
 */
function view(overrides = {}) {
	return {
		active: 'accounts',
		loading: false,
		error: null,
		asked: 0,
		posts: [],
		tags: [],
		accounts: [],
		packs: [],
		openPack: null,
		packLoading: false,
		failedPack: '',
		loaded: [],
		// a visitor, so that opening a pack asks for no relationships unless
		// a test says otherwise
		visitor: true,
		$refs: {},
		$nextTick: (callback) => callback?.(),
		...Discover.methods,
		...overrides,
	}
}

describe('Discover', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		get.mockReset()
		post.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
	})

	it('says who curated a pack read in from another server, and links to it there', async () => {
		get.mockResolvedValue({ data: [] })
		const wrapper = mount(Discover, { shallow: true, global: { plugins: [createPinia()] } })
		const fromElsewhere = { id: 'news-1a2b3c4d', name: 'News', description: '', size: 6, source: 'fediverse', curator: 'Gargron@mastodon.social', url: 'https://mastodon.social/collections/11', topic: 'news', accounts: [], unresolved: [] }
		const ours = { id: 'friends', name: 'Friends', description: '', size: 2, source: 'local', curator: null, url: null, topic: null, accounts: [], unresolved: [] }

		wrapper.vm.active = 'packs'
		await flushPromises()
		wrapper.vm.error = null
		wrapper.vm.packs = [ours, fromElsewhere]
		await wrapper.vm.$nextTick()

		const curators = wrapper.findAll('.discover__pack-card-curator')
		expect(curators).toHaveLength(1)
		expect(curators[0].text()).toContain('Curated by')

		wrapper.vm.openPack = fromElsewhere
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.packView).toMatchObject({ curator: '@Gargron@mastodon.social', topic: 'news', url: 'https://mastodon.social/collections/11' })
		expect(wrapper.findComponent({ name: 'StarterPackDetail' }).props('headingLevel')).toBe(2)

		wrapper.vm.openPack = ours
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.packView).toMatchObject({ curator: '', topic: '', url: '' })
	})

	it('is headed by its one h1, with its sections one level below', () => {
		const wrapper = mount(Discover, { shallow: true, global: { plugins: [createPinia()], stubs: { PageHeader: false } } })

		expect(wrapper.find('h1').text()).toBe('Discover')
		expect(wrapper.findAll('h1')).toHaveLength(1)
		expect(wrapper.findAll('h2').length).toBeGreaterThan(0)
		expect(wrapper.findAll('h3')).toHaveLength(0)
	})

	/** The directory search finds who is listed; it does not promise more. */
	it.each([
		[false, ['@someone@example.org'], ['@someone.example.com']],
		[true, ['@someone@example.org', '@someone.example.com'], []],
	])('points somebody who is not listed to the sidebar search (Bluesky %s)', (bluesky, named, unnamed) => {
		const pinia = createPinia()
		setActivePinia(pinia)
		useSettingsStore().setServerData({ bluesky: { enabled: bluesky, host: 'cloud.example.org' } })

		const text = mount(Discover, { shallow: true, global: { plugins: [pinia] } }).text()

		expect(text).toContain('search at the top of the sidebar')
		expect(text).not.toContain('finds a person anywhere')
		named.forEach((shape) => expect(text).toContain(shape))
		unnamed.forEach((shape) => expect(text).not.toContain(shape))
	})

	describe('an empty pack list', () => {
		it('tells a user plainly, without the settings', () => {
			useSettingsStore().setServerData({ isAdmin: false })

			const text = Discover.computed.emptyPacksDescription.call({})

			expect(text).not.toContain('starter_packs')
		})

		it('tells an administrator which settings add packs', () => {
			useSettingsStore().setServerData({ isAdmin: true })

			expect(Discover.computed.emptyPacksDescription.call({})).toContain('starter_packs')
		})
	})

	describe('tabs', () => {
		it('puts People first, because that is the question somebody has here', () => {
			const tabs = Discover.computed.tabs.call({})

			expect(tabs[0].value).toBe('accounts')
			expect(tabs[1].value).toBe('packs')
		})

		it('offers Videos beside Pictures, and no News tab', () => {
			const tabs = Discover.computed.tabs.call({})

			expect(tabs.map((tab) => tab.value))
				.toEqual(['accounts', 'packs', 'posts', 'videos', 'tags'])
		})

		it('lets the switcher hand the pick back rather than routing', () => {
			// the control pushes a route where an option carries one, and
			// which of these four lists is on screen is not a route
			const tabs = Discover.computed.tabs.call({})

			expect(tabs.every((tab) => tab.to === undefined)).toBe(true)
			expect(tabs.every((tab) => tab.icon !== undefined)).toBe(true)
		})

		/**
		 * A phone has room for the icons only, and those alone do not say
		 * which list is which: every tab brings a word for it instead.
		 */
		it('gives every tab a short word for a phone, and packs an icon of their own', () => {
			const tabs = Discover.computed.tabs.call({})

			expect(tabs.every((tab) => typeof tab.short === 'string' && tab.short !== '')).toBe(true)
			expect(tabs[1].icon).not.toBe(tabs[0].icon)
			expect(tabs[1].icon.name).not.toMatch(/^AccountMultiple/)
		})

		it('opens on People rather than on content', () => {
			expect(Discover.data().active).toBe('accounts')
		})

		/**
		 * Without `media` the route answers with every post that has an
		 * attachment, which is Pixelfed's meaning of it and would put the same
		 * video in both grids.
		 */
		it('asks for one kind of media per grid', async () => {
			get.mockResolvedValue({ data: [] })
			const self = view()

			await self.load.call(self, 'posts')
			await self.load.call(self, 'videos')

			expect(get).toHaveBeenNthCalledWith(1, '/apps/social/api/v2/discover/posts?media=image')
			expect(get).toHaveBeenNthCalledWith(2, '/apps/social/api/v2/discover/posts?media=video')
		})

		it('keeps the two grids apart', () => {
			const posts = [{ id: '1' }]
			const videos = [{ id: '2' }, { id: '3' }]

			expect(Discover.computed.media.call({ active: 'posts', posts, videos })).toBe(posts)
			expect(Discover.computed.media.call({ active: 'videos', posts, videos })).toBe(videos)
		})

		it('says which kind the empty grid is empty of', () => {
			expect(Discover.computed.emptyMediaDescription.call({ active: 'videos' }))
				.toContain('Videos')
			expect(Discover.computed.emptyMediaDescription.call({ active: 'posts' }))
				.toContain('Pictures')
		})

		it('keeps where a suggestion came from, so a colleague can be said to be one', async () => {
			get.mockResolvedValue({ data: [
				{ sources: ['featured'], account: { id: '1', acct: 'bob@cloud.example' } },
				{ sources: ['friends_of_friends'], account: { id: '2', acct: 'carol@remote.example' } },
			] })
			const self = view()

			await self.load.call(self, 'accounts')

			expect(self.accounts.map((a) => Discover.methods.isColleague(a))).toEqual([true, false])
		})

		it('asks the starter pack index for the packs tab', async () => {
			get.mockResolvedValue({ data: [{ id: 'a', name: 'A', size: 2 }] })
			const self = view()

			await self.load.call(self, 'packs')

			expect(get).toHaveBeenCalledWith('/apps/social/api/v1/starter_packs')
			expect(self.packs).toHaveLength(1)
		})

		it('does not ask twice for a tab already loaded', async () => {
			get.mockResolvedValue({ data: [] })
			const self = view({ loaded: ['packs'] })

			await self.load.call(self, 'packs')

			expect(get).not.toHaveBeenCalled()
		})

		it('reports a failure as its own state rather than an empty list', async () => {
			get.mockRejectedValue(new Error('boom'))
			const self = view()

			await self.load.call(self, 'packs')

			expect(self.error).toBeTruthy()
		})
	})

	describe('opening a pack', () => {
		it('shows the name at once and fills in the accounts after', async () => {
			const pack = { id: 'friends', name: 'Friends', size: 2 }
			get.mockResolvedValue({ data: { ...pack, accounts: [{ id: '1', acct: 'a@b.c' }] } })
			const self = view({ packs: [pack] })

			await self.loadPack.call(self, 'friends')

			expect(get).toHaveBeenCalledWith('/apps/social/api/v1/starter_packs/friends')
			expect(self.openPack.accounts).toHaveLength(1)
			expect(self.packLoading).toBe(false)
		})

		/**
		 * A handle that will not resolve is usually a server that is busy, and
		 * the next try often works — so the pack says which ones and offers
		 * the try. Asking again is the same call that opened it.
		 */
		it('can be asked again for the accounts it could not reach', async () => {
			const pack = { id: 'friends', name: 'Friends', size: 3 }
			get.mockResolvedValue({
				data: { ...pack, accounts: [{ id: '1', acct: 'a@b.c' }], unresolved: ['c@d.e', 'f@g.h'] },
			})
			const self = view({ packs: [pack] })

			await self.loadPack.call(self, 'friends')
			expect(self.openPack.unresolved).toEqual(['c@d.e', 'f@g.h'])

			get.mockResolvedValue({
				data: { ...pack, accounts: [{ id: '1', acct: 'a@b.c' }, { id: '2', acct: 'c@d.e' }], unresolved: [] },
			})
			await self.loadPack.call(self, 'friends')

			expect(get).toHaveBeenCalledTimes(2)
			expect(self.openPack.accounts).toHaveLength(2)
			expect(self.openPack.unresolved).toEqual([])
		})

		it('closes back to the list without leaving an error behind', () => {
			const self = view({ openPack: { id: 'x' }, error: 'something' })

			self.closePack.call(self)

			expect(self.openPack).toBeNull()
			expect(self.error).toBeNull()
		})

		it('puts the focus on the pack\'s name when it opens', async () => {
			const focusHeading = vi.fn()
			get.mockResolvedValue({ data: { id: 'x', name: 'X', accounts: [] } })
			const self = view({ packs: [{ id: 'x', name: 'X' }], $refs: { pack: { focusHeading } } })

			await self.loadPack.call(self, 'x')

			expect(focusHeading).toHaveBeenCalledTimes(1)
		})

		it('does not move the focus when the open pack is asked again', async () => {
			const focusHeading = vi.fn()
			get.mockResolvedValue({ data: { id: 'x', name: 'X', accounts: [] } })
			const self = view({ packs: [{ id: 'x', name: 'X' }], openPack: { id: 'x' }, $refs: { pack: { focusHeading } } })

			await self.loadPack.call(self, 'x')

			expect(focusHeading).not.toHaveBeenCalled()
		})

		it('gives the focus back to the card it was opened from', () => {
			const card = (id) => ({ dataset: { packId: id }, focus: vi.fn() })
			const cards = [card('a'), card('x')]
			const self = view({ openPack: { id: 'x' }, $refs: { cards } })

			self.closePack.call(self)

			expect(cards[1].focus).toHaveBeenCalled()
			expect(cards[0].focus).not.toHaveBeenCalled()
		})

		it('does not open again when the pack closed before it arrived', async () => {
			let answer
			get.mockImplementationOnce(() => new Promise((resolve) => {
				answer = () => resolve({ data: { id: 'x', name: 'X', accounts: [] } })
			}))
			const self = view({ packs: [{ id: 'x', name: 'X' }] })

			const opening = self.loadPack.call(self, 'x')
			self.closePack.call(self)
			answer()
			await opening

			expect(self.openPack).toBeNull()
			expect(self.packLoading).toBe(false)
		})

		it('asks whom the reader follows already in it', async () => {
			get.mockImplementation((url) => Promise.resolve(url.includes('relationships')
				? { data: [{ id: '1', following: true }] }
				: { data: { id: 'x', name: 'X', accounts: [{ id: '1', acct: 'a@b.c' }] } }))
			const self = view({ packs: [{ id: 'x', name: 'X' }], visitor: false })

			await self.loadPack.call(self, 'x')

			expect(get).toHaveBeenCalledWith('/apps/social/api/v1/accounts/relationships', { params: { id: ['1'] } })
			expect(useAccountStore().getRelationshipWith('1')).toEqual({ id: '1', following: true })
		})

		/**
		 * "Try again" sits over the failed pack, and asking for the list
		 * again would leave the reader where they started.
		 */
		it('tries the pack again, not the list, after a pack failed to open', async () => {
			get.mockRejectedValueOnce(new Error('busy'))
			const self = view({ active: 'packs', packs: [{ id: 'x', name: 'X' }], loaded: ['packs'] })

			await self.loadPack.call(self, 'x')
			expect(self.failedPack).toBe('x')

			get.mockResolvedValueOnce({ data: { id: 'x', name: 'X', accounts: [] } })
			await self.retry.call(self)

			expect(get).toHaveBeenLastCalledWith('/apps/social/api/v1/starter_packs/x')
			expect(self.openPack.id).toBe('x')
			expect(self.error).toBeNull()
			expect(self.failedPack).toBe('')
		})

		it('tries the tab again when it was the list that failed', async () => {
			get.mockResolvedValue({ data: [] })
			const self = view({ active: 'packs', error: 'busy' })

			await self.retry.call(self)

			expect(get).toHaveBeenCalledWith('/apps/social/api/v1/starter_packs')
		})

		it('does not leave half a pack on screen when the fetch fails', async () => {
			get.mockRejectedValue(new Error('gone'))
			const self = view({ packs: [{ id: 'x', name: 'X' }] })

			await self.loadPack.call(self, 'x')

			expect(self.openPack).toBeNull()
			expect(self.error).toBeTruthy()
			expect(self.packLoading).toBe(false)
		})
	})

	describe('following from a pack', () => {
		const remote = { id: '1', acct: 'a@b.c', username: 'a' }
		const local = { id: '2', acct: 'alice', username: 'alice' }
		const other = { id: '3', acct: 'd@e.f', username: 'd' }
		const asMember = (account) => ({ key: account.id, acct: account.acct, actor: account })

		/**
		 * The server skips whoever it cannot reach rather than failing the
		 * lot, and answers with the handles it followed, a local account's
		 * with its host.
		 */
		it('says which members were followed and which were not', async () => {
			post.mockResolvedValue({ data: { followed: ['a@b.c', 'alice@cloud.example'] } })
			const self = view({ openPack: { id: 'friends' } })
			const progress = vi.fn()

			const result = await self.followPackMembers.call(self, [remote, local, other].map(asMember), false, progress)

			expect(post).toHaveBeenCalledWith('/apps/social/api/v1/starter_packs/friends/follow')
			expect(result).toEqual({ followed: ['1', '2'], failed: ['3'] })
			expect(progress).toHaveBeenCalledWith(3)
		})

		it('counts an answer with no list as nobody followed', async () => {
			post.mockResolvedValue({ data: {} })
			const self = view({ openPack: { id: 'friends' } })

			const result = await self.followPackMembers.call(self, [asMember(remote)], false, vi.fn())

			expect(result).toEqual({ followed: [], failed: ['1'] })
		})

		it('lets a failed request reach the pack, which says so', async () => {
			post.mockRejectedValue(new Error('nope'))
			const self = view({ openPack: { id: 'friends' } })

			await expect(self.followPackMembers.call(self, [asMember(remote)], false, vi.fn())).rejects.toThrow('nope')
		})

		it('follows one member by handle, like every other list here', async () => {
			const follow = vi.fn()
			const isFollowed = vi.fn(() => true)
			const self = view({ follow, isFollowed })

			expect(await self.followPackMember.call(self, asMember(remote))).toBe(true)
			expect(follow).toHaveBeenCalledWith(remote)
		})

		it('marks the members the reader follows already, and the reader', () => {
			const accountStore = useAccountStore()
			accountStore.addRelationship({ actorId: '1', data: { id: '1', following: true } })
			accountStore.addRelationship({ actorId: '3', data: { id: '3', following: false, requested: true } })
			accountStore.setCurrentAccount('alice@cloud.example')
			accountStore.addAccount({ actorId: 'https://cloud.example/users/alice', data: { ...local, url: 'https://cloud.example/users/alice' } })

			const packView = Discover.computed.packView.call({ openPack: { name: 'Friends', accounts: [remote, local, other] } })

			expect(packView.members.map((member) => [member.key, member.following, member.self]))
				.toEqual([['1', true, false], ['2', false, true], ['3', true, false]])
		})
	})

	/**
	 * `loading` and `error` are one pair shared by every tab, so a request the
	 * reader has moved on from could put its spinner or its message over the
	 * tab they moved to.
	 */
	describe('answers that arrive after the reader has moved on', () => {
		// a whole turn of the event loop: a rejection handled inside an async
		// method needs more than one microtask to reach its catch, and a single
		// `Promise.resolve()` asserts before the state it is about to write
		const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

		it('does not report an old tab\'s failure against the tab on screen', async () => {
			const self = view()

			let failAccounts
			get.mockImplementationOnce(() => new Promise((resolve, reject) => {
				failAccounts = () => reject(new Error('busy'))
			}))
			self.load.call(self, 'accounts')
			await settle()

			get.mockResolvedValueOnce([{ id: '1', acct: 'a@b.c' }])
			await self.load.call(self, 'packs')
			await settle()

			failAccounts()
			await settle()

			expect(self.error).toBeNull()
			expect(self.loading).toBe(false)
		})

		it('does not let a pack the reader has left take the open one off screen', async () => {
			const self = view({ packs: [{ id: 'one', name: 'One' }, { id: 'two', name: 'Two' }] })

			let failOne
			get.mockImplementationOnce(() => new Promise((resolve, reject) => {
				failOne = () => reject(new Error('busy'))
			}))
			self.loadPack.call(self, 'one')
			await settle()

			get.mockResolvedValueOnce({ data: { id: 'two', name: 'Two', accounts: [] } })
			await self.loadPack.call(self, 'two')
			await settle()

			failOne()
			await settle()

			expect(self.openPack).not.toBeNull()
			expect(self.openPack.id).toBe('two')
			expect(self.error).toBeNull()
		})
	})
})
