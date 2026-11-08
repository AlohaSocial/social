/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import DirectMessages from '../../../src/components/DirectMessages.vue'
import { useNotificationsStore } from '../../../src/store/notifications.js'
import { useSettingsStore } from '../../../src/store/settings.js'
import { offTimelinePush, onTimelinePush } from '../../../src/services/timelinePush.js'
import { shortAgo } from '../../../src/utils/relativeTime.js'

vi.mock('../../../src/services/timelinePush.js', () => ({
	onTimelinePush: vi.fn(() => false),
	offTimelinePush: vi.fn(),
	PUSH_HOME: 'home',
	PUSH_DIRECT: 'direct',
	PUSH_NOTIFICATION: 'notification',
	PUSH_KINDS: ['home', 'direct', 'notification'],
}))

const bob = { id: 'https://remote.example/users/bob', acct: 'bob@remote.example', display_name: 'Bob' }
const carol = { id: 'https://remote.example/users/carol', acct: 'carol@remote.example', display_name: 'Carol' }
const latest = { id: '11', content: '<p>Latest reply</p>', created_at: '2026-09-23T12:00:00Z', account: bob }
const conversation = { id: '10', unread: true, accounts: [bob], last_status: latest }
const context = {
	ancestors: [{ id: '9', content: '<p>Earlier message</p>', created_at: '2026-09-22T11:00:00Z', account: bob }],
	descendants: [{ id: '12', content: '<p>Next message</p>', created_at: '2026-09-23T12:10:00Z', account: { acct: 'alice', username: 'alice' } }],
}

const stubs = {
	ActorAvatar: { props: ['actor', 'size', 'link'], template: '<span class="avatar-stub">{{ actor.display_name }}</span>' },
	NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
	NcActionButton: { template: '<button v-bind="$attrs"><slot name="icon" /><slot /></button>' },
	NcActions: { template: '<div class="native-actions"><slot /></div>' },
	TimelineEntry: { props: ['item', 'type', 'hideAuthor', 'hideAvatar'], template: '<article class="message-stub" :data-id="item.id" :data-hide-author="hideAuthor" :data-hide-avatar="hideAvatar">{{ item.content }}</article>' },
}

/** every pane mounted by a test, taken down after it so none keeps polling */
const mounted = []

function mountMessages(selectedConversationId = '') {
	const wrapper = mount(DirectMessages, {
		props: { selectedConversationId },
		global: { stubs },
	})
	mounted.push(wrapper)

	return wrapper
}

/** Run the recipient search now, cancelling the debounced watcher request. */
async function searchImmediately(wrapper, query) {
	clearTimeout(wrapper.vm.accountSearchTimer)
	await wrapper.vm.searchAccounts(query)
}

describe('DirectMessages', () => {
	let get
	let post
	let remove

	beforeEach(() => {
		localStorage.clear()
		setActivePinia(createPinia())
		useSettingsStore().setServerData({ viewer: { username: 'alice', address: `alice@${window.location.hostname}` } })
		onTimelinePush.mockClear()
		onTimelinePush.mockReturnValue(false)
		offTimelinePush.mockClear()
		get = vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url.endsWith('/conversations')) {
				return { data: [structuredClone(conversation)] }
			}
			return { data: context }
		})
		post = vi.spyOn(axios, 'post').mockResolvedValue({ data: conversation })
		remove = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
	})

	afterEach(() => {
		mounted.splice(0).forEach((wrapper) => wrapper.unmount())
		vi.restoreAllMocks()
	})

	it('loads and previews conversations from the Mastodon-compatible endpoint', async () => {
		const wrapper = mountMessages()
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations', { params: { limit: 40 } })
		expect(wrapper.find('.direct-messages__list').text()).toContain('Bob')
		expect(wrapper.find('.direct-messages__preview').text()).toBe('Latest reply')
		expect(wrapper.find('.direct-messages__unread-dot').exists()).toBe(true)
	})

	it('emits the selected conversation id for the route to remember', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.find('.direct-messages__conversation').trigger('click')

		expect(wrapper.emitted('select')).toEqual([['10']])
	})

	/**
	 * The message box is the feed composer's pill: no label drawn over the
	 * field, the placeholder says what it is for, and a screen reader is told
	 * the same through `aria-label`.
	 */
	it('names the message box without drawing a label over it', async () => {
		const wrapper = mountMessages('10')
		await flushPromises()

		const box = wrapper.find('.direct-messages__message-form textarea')
		expect(box.attributes('aria-label')).toBe('Write a message…')
		expect(box.attributes('placeholder')).toBe('Write a message…')
		expect(wrapper.find('.direct-messages__message-form label').exists()).toBe(false)
	})

	describe('what is being written', () => {
		const box = (wrapper) => wrapper.find('.direct-messages__message-form textarea')

		it('is kept per conversation when switching between them, and when leaving', async () => {
			const wrapper = mountMessages('10')
			await flushPromises()
			await box(wrapper).setValue('Half a message to Bob')

			await wrapper.setProps({ selectedConversationId: '20' })
			await flushPromises()
			expect(wrapper.vm.messageText).toBe('')
			wrapper.vm.newMessageOpen = true
			await flushPromises()
			wrapper.vm.newMessageOpen = false
			await wrapper.setProps({ selectedConversationId: '10' })
			await flushPromises()
			expect(box(wrapper).element.value).toBe('Half a message to Bob')

			wrapper.unmount()
			const again = mountMessages('10')
			await flushPromises()
			expect(box(again).element.value).toBe('Half a message to Bob')
		})

		it('keeps a new chat apart from the conversations', async () => {
			const wrapper = mountMessages('10')
			await flushPromises()
			wrapper.vm.newMessageOpen = true
			await flushPromises()
			expect(wrapper.vm.messageText).toBe('')
			wrapper.vm.onMessageInput({ target: { value: 'For somebody new' } })

			wrapper.vm.newMessageOpen = false
			await flushPromises()
			expect(wrapper.vm.messageText).toBe('')
			wrapper.vm.newMessageOpen = true
			await flushPromises()
			expect(wrapper.vm.messageText).toBe('For somebody new')
		})

		it('is forgotten once it is sent', async () => {
			const wrapper = mountMessages('10')
			await flushPromises()
			await box(wrapper).setValue('Sent soon')
			await box(wrapper).trigger('keydown.enter', { ctrlKey: true })
			await flushPromises()

			wrapper.unmount()
			const again = mountMessages('10')
			await flushPromises()
			expect(box(again).element.value).toBe('')
		})
	})

	it('offers Send only once something is written, and sends with Ctrl+Enter or Cmd+Enter', async () => {
		const wrapper = mountMessages('10')
		await flushPromises()
		const send = () => wrapper.find('.direct-messages__send')

		expect(send().attributes('style')).toContain('display: none')
		await wrapper.find('.direct-messages__message-form textarea').setValue('Hello')
		expect(send().attributes('style') ?? '').not.toContain('display: none')

		await wrapper.find('.direct-messages__message-form textarea').trigger('keydown.enter')
		expect(post).not.toHaveBeenCalledWith('/index.php/apps/social/api/v1/statuses', expect.anything())
		await wrapper.find('.direct-messages__message-form textarea').trigger('keydown.enter', { ctrlKey: true })
		await flushPromises()
		expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/statuses', {
			status: '@bob@remote.example Hello',
			visibility: 'direct',
			in_reply_to_id: '11',
		})

		// the Mac's own shortcut for "send"
		post.mockClear()
		await wrapper.find('.direct-messages__message-form textarea').setValue('Again')
		await wrapper.find('.direct-messages__message-form textarea').trigger('keydown.enter', { metaKey: true })
		await flushPromises()
		expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/statuses', expect.objectContaining({ status: '@bob@remote.example Again' }))
	})

	it('opens a recipient search with no social visibility controls', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.find('.direct-messages__list-heading button').trigger('click')

		expect(wrapper.find('.direct-messages__new-message-panel').exists()).toBe(true)
		expect(wrapper.find('.direct-messages__recipient-search input').exists()).toBe(true)
		expect(wrapper.find('.direct-messages__new-message-panel').text()).not.toContain('Public')
		expect(wrapper.find('.direct-messages__new-message-panel .composer-stub').exists()).toBe(false)
		expect(wrapper.find('.direct-messages__list-panel .composer-stub').exists()).toBe(false)
	})

	it('offers a search only once the inbox is long enough to need one', async () => {
		const wrapper = mountMessages()
		await flushPromises()

		expect(wrapper.find('.direct-messages__search').exists()).toBe(false)
	})

	it('filters the inbox by the other participant and latest message', async () => {
		get.mockResolvedValueOnce({ data: [
			structuredClone(conversation),
			...Array.from({ length: 8 }, (_, index) => ({ id: `3${index}`, unread: false, accounts: [{ ...bob, id: `other-${index}`, display_name: `Other ${index}` }], last_status: { id: `4${index}`, content: '<p>Hi</p>', created_at: '2026-09-20T09:00:00Z' } })),
		] })
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.find('.direct-messages__search input').setValue('nobody')

		expect(wrapper.find('.direct-messages__conversation').exists()).toBe(false)
		expect(wrapper.find('.direct-messages__state').text()).toBe('No conversations match your search')

		await wrapper.find('.direct-messages__search input').setValue('latest')
		expect(wrapper.findAll('.direct-messages__conversation')).toHaveLength(1)
	})

	it('filters unread conversations and updates the unread badge as they are read', async () => {
		get.mockResolvedValueOnce({ data: [
			structuredClone(conversation),
			{ id: '20', unread: false, accounts: [{ ...bob, id: 'friend', display_name: 'Charlie' }], last_status: { id: '21', content: '<p>Older</p>', created_at: '2026-09-22T09:00:00Z' } },
		] })
		const wrapper = mountMessages()
		await flushPromises()
		expect(wrapper.find('.direct-messages__filters').text()).toContain('Unread (1)')
		await wrapper.findAll('.direct-messages__filters button')[1].trigger('click')
		expect(wrapper.findAll('.direct-messages__conversation')).toHaveLength(1)
		expect(wrapper.find('.direct-messages__conversation').text()).toContain('Bob')
	})

	it('uses accessible selected state for the all and unread filters', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		const [all, unread] = wrapper.findAll('.direct-messages__filters button')

		expect(all.attributes('aria-checked')).toBe('true')
		expect(unread.attributes('aria-checked')).toBe('false')
		await unread.trigger('click')
		expect(all.attributes('aria-checked')).toBe('false')
		expect(unread.attributes('aria-checked')).toBe('true')
	})

	it('offers a clear empty inbox and a direct way to start a message', async () => {
		get.mockResolvedValue({ data: [] })
		const wrapper = mountMessages()
		await flushPromises()

		expect(wrapper.find('.direct-messages__welcome').text()).toContain('Start a private chat')
		// a message in a bottle, the same drawing the empty Direct timeline has
		expect(wrapper.findComponent({ name: 'AlohaScene' }).props('scene')).toBe('bottle')
		await wrapper.find('.direct-messages__welcome button').trigger('click')
		expect(wrapper.find('.direct-messages__new-message-panel').exists()).toBe(true)
	})

	it('shows the whole thread and marks it read', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.setProps({ selectedConversationId: '10' })
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/statuses/11/context')
		expect(wrapper.findAll('.message-stub').map((entry) => entry.attributes('data-id'))).toEqual(['9', '11', '12'])
		expect(wrapper.findAll('.message-stub').map((entry) => entry.attributes('data-hide-author'))).toEqual(['true', 'true', 'true'])
		expect(wrapper.findAll('.direct-messages__day')).toHaveLength(2)
		expect(wrapper.findAll('.direct-messages__message-time')).toHaveLength(3)
		expect(wrapper.findAll('.direct-messages__message--outgoing')).toHaveLength(1)
		expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations/10/read')
		expect(wrapper.find('.direct-messages__unread-dot').exists()).toBe(false)
		expect(wrapper.find('.direct-messages__message-form textarea').exists()).toBe(true)
	})

	/**
	 * The username is chosen on the setup screen and need not be the user id
	 * (`alice` here). The reader's own messages were told apart by the user
	 * id, so theirs looked incoming and those of an account called `alice`
	 * looked like theirs.
	 */
	it('knows the reader\'s messages by the account\'s username, not by the user id', async () => {
		useSettingsStore().setServerData({ viewer: { username: 'aloha_al', address: `aloha_al@${window.location.hostname}` } })
		const wrapper = mountMessages('10')
		await flushPromises()
		const local = { url: `${window.location.origin}/index.php/apps/social/@x` }

		expect(wrapper.vm.isOutgoing({ account: { ...local, acct: 'aloha_al', username: 'aloha_al' } })).toBe(true)
		expect(wrapper.vm.isOutgoing({ account: { ...local, acct: 'alice', username: 'alice' } })).toBe(false)
		expect(wrapper.vm.conversationPeer({ accounts: [{ acct: 'aloha_al', username: 'aloha_al' }, bob] })).toEqual(bob)
	})

	/**
	 * The badge in the sidebar is the notifications store's count. Opening
	 * the one unread chat marked it read on the server and left the store
	 * alone, so the "1" stayed until the next poll.
	 */
	it('brings the sidebar badge down once the conversation is read', async () => {
		const notificationsStore = useNotificationsStore()
		notificationsStore.setUnreadDirectMessages(1)
		get.mockImplementation(async (url) => {
			if (url.endsWith('/conversations')) {
				return { data: [structuredClone(conversation)] }
			}
			if (url.endsWith('/conversations/unread_count')) {
				return { data: { count: 0 } }
			}
			return { data: context }
		})
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.setProps({ selectedConversationId: '10' })
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations/10/read')
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations/unread_count')
		expect(notificationsStore.unreadDirectMessages).toBe(0)
	})

	it('leaves the badge where it was when the read marker could not be written', async () => {
		const notificationsStore = useNotificationsStore()
		notificationsStore.setUnreadDirectMessages(1)
		post.mockRejectedValue(new Error('offline'))
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.setProps({ selectedConversationId: '10' })
		await flushPromises()

		expect(get).not.toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations/unread_count')
		expect(notificationsStore.unreadDirectMessages).toBe(1)
	})

	it('dates a conversation the way the feed dates a post', async () => {
		const wrapper = mountMessages()
		await flushPromises()

		expect(wrapper.find('.direct-messages__age').text()).toBe(shortAgo(latest.created_at))
		expect(wrapper.find('.direct-messages__age').attributes('datetime')).toBe(latest.created_at)
	})

	it('names the other person by their handle above the thread', async () => {
		const wrapper = mountMessages('10')
		await flushPromises()

		expect(wrapper.find('.direct-messages__thread-person p').text()).toBe('@bob@remote.example')
	})

	/**
	 * A run of messages from one person on one day carries one time, under
	 * its last message; the others keep theirs beside the bubble, shown when
	 * the message is pointed at.
	 */
	it('shows a time under the last message of a run only', async () => {
		get.mockImplementation(async (url) => url.endsWith('/conversations')
			? { data: [structuredClone(conversation)] }
			: { data: { ...context, ancestors: [{ id: '9', content: '<p>Earlier today</p>', created_at: '2026-09-23T11:50:00Z', account: bob }] } })
		const wrapper = mountMessages('10')
		await flushPromises()

		expect(wrapper.findAll('.direct-messages__message-time').map((time) => time.classes().includes('direct-messages__message-time--aside')))
			.toEqual([true, false, false])
	})

	it('draws an outgoing bubble in the reader\'s own colour', () => {
		const source = readFileSync(resolve(process.cwd(), 'src/components/DirectMessages.vue'), 'utf8')

		expect(source).toMatch(/\.direct-messages__message--outgoing :deep\(\.post-content\) \{[^}]*background: color-mix\(in srgb, hsl\(var\(--account-hue/)
	})

	it('searches for one recipient and starts an existing chat instead of duplicating it', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		get.mockImplementation(async (url) => ({ data: url.endsWith('/accounts/search') ? [bob] : [] }))
		await wrapper.vm.searchAccounts('bob')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/search', { params: { q: 'bob', limit: 8, resolve: false } })
		expect(wrapper.vm.accountResults).toEqual([bob])
		await wrapper.vm.startConversation(bob)
		expect(wrapper.emitted('select')).toEqual([['10']])
	})

	it('shows followed people before typing and searches Mastodon account results', async () => {
		get.mockImplementation(async (url) => {
			if (url.endsWith('/conversations')) {
				return { data: [] }
			}
			if (url.endsWith('/verify_credentials')) {
				return { data: { id: '42' } }
			}
			if (url.endsWith('/42/following')) {
				return { data: [bob] }
			}
			return { data: [{ ...bob, acct: 'bob@remote.example' }] }
		})
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.find('.direct-messages__list-heading button').trigger('click')
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/42/following', { params: { limit: 20 } })
		expect(wrapper.find('.direct-messages__recipient-results').text()).toContain('Bob')
		await wrapper.find('.direct-messages__recipient-search input').setValue('bob')
		await searchImmediately(wrapper, 'bob')
		await flushPromises()

		expect(wrapper.find('.direct-messages__recipient-results').text()).toContain('Bob')
		expect(wrapper.find('.direct-messages__people-heading').text()).toContain('People you know')
		await wrapper.find('.direct-messages__recipient-search input').setValue('@bob')
		await searchImmediately(wrapper, '@bob')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/search', { params: { q: '@bob', limit: 8, resolve: false } })
		expect(wrapper.find('.direct-messages__recipient-results').text()).toContain('Bob')
	})

	/**
	 * Typing never asks other servers; a whole address the answer does not
	 * hold is offered, and looked up with Enter or the button.
	 */
	it('looks an address up elsewhere only when asked, and says when nobody is there', async () => {
		get.mockImplementation(async (url) => ({ data: url.endsWith('/conversations') ? [] : [] }))
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.find('.direct-messages__list-heading button').trigger('click')
		await flushPromises()
		const input = wrapper.find('.direct-messages__recipient-search input')
		await input.setValue('carol.bsky.social')
		await searchImmediately(wrapper, 'carol.bsky.social')
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/search', { params: { q: 'carol.bsky.social', limit: 8, resolve: false } })
		expect(get).not.toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/search', { params: { q: 'carol.bsky.social', limit: 8, resolve: true } })
		expect(wrapper.text()).toContain('@carol.bsky.social is not known on this server yet.')
		expect(wrapper.text()).not.toContain('No people found')

		await wrapper.find('.direct-messages__recipient-lookup-button').trigger('click')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/search', { params: { q: 'carol.bsky.social', limit: 8, resolve: true } })
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/search', { params: { q: 'carol.bsky.social', limit: 8, resolve: true, following: true } })
		expect(wrapper.find('.direct-messages__recipient-lookup-button').exists()).toBe(false)
		expect(wrapper.text()).toContain('Nobody was found at @carol.bsky.social.')

		get.mockClear()
		await input.setValue('dave@remote.example')
		await input.trigger('keydown', { key: 'Enter' })
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/search', { params: { q: 'dave@remote.example', limit: 8, resolve: true } })
	})

	describe('recipient search', () => {
		const carol = { id: '31', acct: 'carol@remote.example', display_name: 'Carol' }
		const caroline = { id: '32', acct: 'caroline@elsewhere.example', display_name: 'Caroline' }

		async function openSearch(followed, all, query = 'caro') {
			get.mockImplementation(async (url, config) => {
				if (url.endsWith('/conversations')) {
					return { data: [] }
				}
				if (url.endsWith('/accounts/search')) {
					return { data: config.params.following ? followed : all }
				}
				return { data: [] }
			})
			const wrapper = mountMessages()
			await flushPromises()
			await wrapper.find('.direct-messages__list-heading button').trigger('click')
			await flushPromises()
			await wrapper.find('.direct-messages__recipient-search input').setValue(query)
			await searchImmediately(wrapper, query)
			await flushPromises()

			return wrapper
		}

		const names = (wrapper, group) => wrapper.findAll(`.direct-messages__recipient-results--${group} .direct-messages__name`)
			.map((item) => item.text())

		it('lists the people the reader follows first and every other account under its own heading', async () => {
			const wrapper = await openSearch([carol], [caroline, carol])

			expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/search', { params: { q: 'caro', limit: 8, resolve: false, following: true } })
			expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/search', { params: { q: 'caro', limit: 8, resolve: false } })
			expect(wrapper.findAll('.direct-messages__people-heading').map((heading) => heading.text()))
				.toEqual(['People you know', 'Other accounts'])
			expect(names(wrapper, 'known')).toEqual(['Carol'])
			// Carol was in both answers and is listed once, among the people known
			expect(names(wrapper, 'others')).toEqual(['Caroline'])
		})

		it('asks the server twice per search, never once per account', async () => {
			await openSearch([carol], [caroline])

			expect(get.mock.calls.filter(([url]) => url.endsWith('/accounts/search'))).toHaveLength(2)
		})

		it('shows only other accounts when the reader follows nobody who matches', async () => {
			const wrapper = await openSearch([], [caroline])

			expect(wrapper.findAll('.direct-messages__people-heading').map((heading) => heading.text()))
				.toEqual(['Other accounts'])
			expect(names(wrapper, 'others')).toEqual(['Caroline'])
		})

		it('treats one account under two spellings of its handle as one', async () => {
			const wrapper = await openSearch([carol], [{ ...carol, id: undefined, acct: 'Carol@Remote.Example' }])

			expect(names(wrapper, 'known')).toEqual(['Carol'])
			expect(wrapper.find('.direct-messages__recipient-results--others').exists()).toBe(false)
		})

		// the server matched the link; neither the name nor the handle contains it
		it('lists what a pasted profile link resolved to', async () => {
			const wrapper = await openSearch([], [carol], 'https://remote.example/@carol')

			expect(names(wrapper, 'others')).toEqual(['Carol'])
			expect(wrapper.text()).not.toContain('No people found')
		})

		it('says nobody was found only when both groups are empty', async () => {
			const wrapper = await openSearch([], [])

			expect(wrapper.findAll('.direct-messages__people-heading')).toHaveLength(0)
			expect(wrapper.text()).toContain('No people found')
		})
	})

	it('hides only the leading protocol recipient mention in direct message bubbles and previews', async () => {
		const wrapper = mountMessages('10')
		await flushPromises()
		const message = {
			visibility: 'direct',
			content: '<p><span class="h-card"><a href="https://remote.example/@bob">@<span>bob</span></a></span> hello <span class="h-card"><a>@carol</a></span></p>',
		}

		const rendered = wrapper.vm.messageForDisplay(message)
		expect(rendered.content).toBe('<p>hello <span class="h-card"><a>@carol</a></span></p>')
		expect(message.content).toContain('bob')
		expect(wrapper.vm.preview(message)).toBe('hello @carol')
		expect(wrapper.vm.messageForDisplay({ ...message, visibility: 'public' }).content).toContain('@bob')
	})

	/**
	 * A message from the other side opens with the reader's own handle, so
	 * every preview in the inbox began "@alice …". The row already says whose
	 * chat it is; the handle is routing, and the preview starts at the words.
	 * The bubble keeps it: there it is part of what was said.
	 */
	it('leaves the reader\'s own handle out of a preview, not out of the message', async () => {
		const toAlice = (content) => ({ id: '30', visibility: 'direct', content, created_at: '2026-09-23T12:00:00Z', account: bob })
		get.mockResolvedValueOnce({ data: [{ ...structuredClone(conversation), last_status: toAlice('<p><span class="h-card"><a href="http://localhost/index.php/apps/social/@alice">@<span>alice</span></a></span> hey, private</p>') }] })
		const wrapper = mountMessages()
		await flushPromises()

		expect(wrapper.find('.direct-messages__preview').text()).toBe('hey, private')
		expect(wrapper.vm.preview(toAlice('<p>@alice hey, private</p>'), conversation)).toBe('hey, private')
		expect(wrapper.vm.preview(toAlice(`<p>@alice@${window.location.host} hey, private</p>`), conversation)).toBe('hey, private')
		expect(wrapper.vm.preview(toAlice('<p>@bob@remote.example hey</p>'), conversation)).toBe('hey')
		// somebody else's handle is what the message is about
		expect(wrapper.vm.preview(toAlice('<p>@carol hey</p>'), conversation)).toBe('@carol hey')
		expect(wrapper.vm.messageForDisplay(toAlice('<p>@alice hey, private</p>')).content).toBe('<p>@alice hey, private</p>')
	})

	it('removes recipient routing mentions returned as ordinary links or plain text', async () => {
		const wrapper = mountMessages('10')
		await flushPromises()
		expect(wrapper.vm.messageForDisplay({ visibility: 'direct', content: '<p><a class="mention" href="https://remote.example/@bob">@bob@remote.example</a> hello</p>' }).content).toBe('<p>hello</p>')
		expect(wrapper.vm.messageForDisplay({ visibility: 'direct', content: '<p>@bob@remote.example hello</p>' }).content).toBe('<p>hello</p>')
		expect(wrapper.vm.messageForDisplay({ visibility: 'direct', content: '<p><a class="mention" href="https://elsewhere.example/@carol">@carol</a> hello</p>' }).content).toContain('@carol')
	})

	// `\w` is ASCII whatever else is set, so a handle on an internationalised
	// domain — or with a non-ASCII local part — was not recognised as the
	// routing mention and stayed on screen in every message.
	it('hides a routing mention whose handle is not ASCII', async () => {
		const mueller = { id: 'https://müller.example/users/jan', acct: 'jan@müller.example', display_name: 'Jan' }
		get.mockResolvedValueOnce({ data: [{ ...structuredClone(conversation), accounts: [mueller] }] })
		const wrapper = mountMessages('10')
		await flushPromises()

		const rendered = wrapper.vm.messageForDisplay({
			visibility: 'direct',
			content: '<p>@jan@müller.example guten Morgen</p>',
		})

		expect(rendered.content).toBe('<p>guten Morgen</p>')
	})

	// An `.h-card` was stripped whoever it named, so a message opening with a
	// mention of somebody else lost the name it was about.
	it('keeps a leading mention of somebody who is not the peer', async () => {
		const wrapper = mountMessages('10')
		await flushPromises()

		const rendered = wrapper.vm.messageForDisplay({
			visibility: 'direct',
			content: '<p><span class="h-card"><a href="https://remote.example/@carol">@carol</a></span> look at this</p>',
		})

		expect(rendered.content).toContain('@carol')
	})

	// The href was matched with `includes()`, so the peer `bob` matched a link
	// to `/@bobby` and a message to bobby lost its mention of bob.
	it('does not take one handle for another that starts the same way', async () => {
		const bobby = { id: 'https://remote.example/users/bobby', acct: 'bobby@remote.example', display_name: 'Bobby' }
		get.mockResolvedValueOnce({ data: [{ ...structuredClone(conversation), accounts: [bobby] }] })
		const wrapper = mountMessages('10')
		await flushPromises()

		const rendered = wrapper.vm.messageForDisplay({
			visibility: 'direct',
			content: '<p><span class="h-card"><a href="https://remote.example/@bob">@bob</a></span> said so</p>',
		})

		expect(rendered.content).toContain('@bob')
	})

	// With no peer to compare against it hid the first mention whoever it
	// named, which is worse than leaving a routing handle on screen.
	it('hides nothing when there is no peer to compare against', async () => {
		const wrapper = mountMessages()
		await flushPromises()

		const rendered = wrapper.vm.withoutProtocolRecipient({
			visibility: 'direct',
			content: '<p><span class="h-card"><a href="https://remote.example/@bob">@bob</a></span> hello</p>',
		}, null)

		expect(rendered).toContain('@bob')
	})

	// A conversation of three was answered with one mention — the first
	// account that was not the reader — so the third person dropped out of the
	// exchange at the first reply, without anybody being told.
	it('answers everybody a group conversation is between', async () => {
		const carol = { id: 'https://remote.example/users/carol', acct: 'carol@remote.example', display_name: 'Carol' }
		get.mockResolvedValueOnce({ data: [{ ...structuredClone(conversation), accounts: [bob, carol] }] })
		const wrapper = mountMessages('10')
		await flushPromises()

		wrapper.vm.messageText = 'both of you'
		await wrapper.vm.sendMessage()

		expect(post).toHaveBeenCalledWith(
			'/index.php/apps/social/api/v1/statuses',
			expect.objectContaining({ status: '@bob@remote.example @carol@remote.example both of you' }),
		)
	})

	// A handle is not case-sensitive, and the filtering beside this lowercases
	// one — so the same person could be opened as a second chat.
	it('opens the conversation that is already there whatever case was typed', async () => {
		const wrapper = mountMessages()
		await flushPromises()

		wrapper.vm.startConversation({ acct: 'Bob@Remote.Example', display_name: 'Bob' })

		expect(wrapper.emitted('select').at(-1)).toEqual(['10'])
		expect(post).not.toHaveBeenCalled()
	})

	it('dismisses a conversation from the inbox using the native row action', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.find('.native-actions button').trigger('click')

		expect(remove).toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations/10')
		expect(wrapper.find('.direct-messages__conversation').exists()).toBe(false)
		expect(wrapper.find('.direct-messages__inbox-empty').exists()).toBe(true)
	})

	it('shows a search failure instead of claiming nobody exists', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.find('.direct-messages__list-heading button').trigger('click')
		await wrapper.find('.direct-messages__recipient-search input').setValue('alice')
		get.mockRejectedValueOnce(new Error('offline'))
		await searchImmediately(wrapper, 'alice')
		await flushPromises()

		expect(wrapper.find('.direct-messages__recipient-feedback[role="alert"]').text()).toContain('Could not search for people')
	})

	it('sends a private message with the selected recipient attached automatically', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		wrapper.vm.newMessageOpen = true
		wrapper.vm.newRecipient = bob
		wrapper.vm.messageText = 'Hello there'
		await wrapper.vm.sendMessage()

		expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/statuses', {
			status: '@bob@remote.example Hello there',
			visibility: 'direct',
		})
		expect(wrapper.vm.messageText).toBe('')
	})

	it('does not route a new message through the conversation that was open before composing', async () => {
		const alice = { id: 'https://remote.example/users/alice', acct: 'alice@remote.example', display_name: 'Alice' }
		const carol = { id: 'https://elsewhere.example/users/carol', acct: 'carol@elsewhere.example', display_name: 'Carol' }
		get.mockResolvedValueOnce({ data: [{ ...structuredClone(conversation), accounts: [alice] }] })
		const wrapper = mountMessages('10')
		await flushPromises()
		wrapper.vm.newMessageOpen = true
		wrapper.vm.newRecipient = carol
		wrapper.vm.messageText = 'A private note'

		await wrapper.vm.sendMessage()

		expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/statuses', {
			status: '@carol@elsewhere.example A private note',
			visibility: 'direct',
		})
	})

	it('ignores a recipient left over after leaving the new-message panel', async () => {
		const wrapper = mountMessages('10')
		await flushPromises()
		wrapper.vm.newRecipient = { id: 'https://elsewhere.example/users/carol', acct: 'carol@elsewhere.example' }
		wrapper.vm.newMessageOpen = false
		wrapper.vm.messageText = 'A reply to Bob'

		await wrapper.vm.sendMessage()

		expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/statuses', {
			status: '@bob@remote.example A reply to Bob',
			visibility: 'direct',
			in_reply_to_id: '11',
		})
	})

	// A conversation is a thread, and two people can have several: the server
	// defines one by its thread root and hands that root's id back. Rows used
	// to be keyed by the other participant instead, so a second exchange with
	// somebody was dropped on the floor — unreachable, unread and all.
	/**
	 * The server answers one conversation per thread, as Mastodon does, so a
	 * message that was not a reply started a second row "Bob" under the
	 * first. The inbox folds the threads between the same people into one
	 * chat, which is the newest of them and unread while any of them is.
	 */
	it('folds two threads with the same person into one chat', async () => {
		const second = { id: '21', content: '<p>Second thread</p>', created_at: '2026-09-24T09:00:00Z', account: bob }
		get.mockResolvedValueOnce({ data: [
			{ ...structuredClone(conversation), id: '11', unread: true, last_status: second },
			{ ...structuredClone(conversation), unread: false },
		] })
		const wrapper = mountMessages()
		await flushPromises()

		expect(wrapper.findAll('.direct-messages__conversation')).toHaveLength(1)
		expect(wrapper.find('.direct-messages__preview').text()).toBe('Second thread')
		expect(wrapper.find('.direct-messages__unread-dot').exists()).toBe(true)
		expect(wrapper.find('.direct-messages__filters').text()).toContain('Unread (1)')
		await wrapper.find('.direct-messages__conversation').trigger('click')
		expect(wrapper.emitted('select').at(-1)).toEqual(['11'])
	})

	it('shows every thread of a chat as one lane and reads each unread one', async () => {
		const second = { id: '21', content: '<p>Second thread</p>', created_at: '2026-09-24T09:00:00Z', account: bob }
		const rows = [
			{ ...structuredClone(conversation), id: '11', unread: true, last_status: second },
			{ ...structuredClone(conversation), unread: false },
		]
		get.mockImplementation(async (url) => {
			if (url.endsWith('/conversations')) {
				return { data: structuredClone(rows) }
			}
			if (url.includes('/statuses/21/')) {
				return { data: { ancestors: [], descendants: [{ id: '22', content: '<p>Reply in the second</p>', created_at: '2026-09-24T09:05:00Z', account: { acct: 'alice', username: 'alice' } }] } }
			}
			return { data: context }
		})
		const wrapper = mountMessages('11')
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/statuses/21/context')
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/statuses/11/context')
		expect(wrapper.findAll('.message-stub').map((entry) => entry.attributes('data-id'))).toEqual(['9', '11', '12', '21', '22'])
		expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations/11/read')
		expect(post).not.toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations/10/read')
		expect(wrapper.find('.direct-messages__unread-dot').exists()).toBe(false)
	})

	it('removes every thread of a chat with the row action', async () => {
		get.mockResolvedValueOnce({ data: [
			{ ...structuredClone(conversation), id: '11' },
			structuredClone(conversation),
		] })
		const wrapper = mountMessages('10')
		await flushPromises()
		await wrapper.find('.direct-messages__row-menu button').trigger('click')
		await flushPromises()

		expect(remove).toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations/11')
		expect(remove).toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations/10')
		expect(wrapper.find('.direct-messages__conversation').exists()).toBe(false)
		expect(wrapper.emitted('select').at(-1)).toEqual([''])
	})

	// Group threads collided the same way, on whichever member happened to be
	// listed first.
	it('keeps group conversations sharing their first participant apart', async () => {
		get.mockResolvedValueOnce({ data: [
			{ ...structuredClone(conversation), id: '20', accounts: [bob, carol] },
			{ ...structuredClone(conversation), id: '21', accounts: [bob] },
		] })
		const wrapper = mountMessages()
		await flushPromises()

		expect(wrapper.findAll('.direct-messages__conversation')).toHaveLength(2)
	})

	// The reason the collapsing existed: paging is by message, so a thread
	// with messages either side of the cursor is built twice. That is the same
	// id twice, and merging it is still right.
	it('merges the same conversation returned by two overlapping pages', async () => {
		get.mockResolvedValueOnce({ data: [
			structuredClone(conversation),
			{ ...structuredClone(conversation), unread: false },
		] })
		const wrapper = mountMessages()
		await flushPromises()

		expect(wrapper.findAll('.direct-messages__conversation')).toHaveLength(1)
		expect(wrapper.find('.direct-messages__unread-dot').exists()).toBe(true)
	})

	it('shows an empty state when there are no conversations', async () => {
		get.mockResolvedValue({ data: [] })
		const wrapper = mountMessages()
		await flushPromises()

		expect(wrapper.find('.direct-messages__inbox-empty').text()).toContain('No conversations yet')
		expect(wrapper.find('.direct-messages__thread-panel--empty').text()).toContain('Choose a conversation or find someone to message')
	})

	// The four inbox states are one chain, and a failed removal is not one of
	// them: it is something that happened to a conversation, so it belongs
	// above the chain rather than inside it. Put inside, it broke the chain in
	// two and each half decided on its own.
	it('keeps the inbox on screen when removing a conversation fails', async () => {
		remove.mockRejectedValueOnce(new Error('offline'))
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.find('.native-actions button').trigger('click')
		await flushPromises()

		expect(wrapper.find('.direct-messages__state[role="alert"]').text()).toContain('Could not remove conversation')
		expect(wrapper.find('.direct-messages__conversation').exists()).toBe(true)
	})

	it('does not offer to start a first conversation while the inbox is still loading', async () => {
		get.mockImplementation(() => new Promise(() => {}))
		const wrapper = mountMessages()
		await flushPromises()

		expect(wrapper.find('.direct-messages__state[role="status"]').text()).toContain('Loading conversations…')
		expect(wrapper.find('.direct-messages__inbox-empty').exists()).toBe(false)
	})

	// Forty conversations and no way to reach the forty-first. The cursor has
	// to come from the `Link` header: it is a message nid, and a conversation
	// id is its thread root, which does not move when a message arrives.
	it('loads older conversations using the cursor the server sent', async () => {
		get.mockResolvedValueOnce({
			data: [structuredClone(conversation)],
			headers: { link: '</index.php/apps/social/api/v1/conversations?limit=40&max_id=99>; rel="next"' },
		})
		const wrapper = mountMessages()
		await flushPromises()

		const older = wrapper.find('.direct-messages__more button')
		expect(older.text()).toContain('Load older conversations')

		get.mockResolvedValueOnce({ data: [{ ...structuredClone(conversation), id: '7', accounts: [carol] }] })
		await older.trigger('click')
		await flushPromises()

		// not "last": the recipient suggestions load on their own schedule
		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/social/api/v1/conversations',
			{ params: { limit: 40, max_id: '99' } },
		)
		expect(wrapper.findAll('.direct-messages__conversation')).toHaveLength(2)
		expect(wrapper.find('.direct-messages__more').exists()).toBe(false)
	})

	it('can load older unread conversations when the current page has no matches', async () => {
		get.mockResolvedValueOnce({
			data: [{ ...structuredClone(conversation), unread: false }],
			headers: { link: '</index.php/apps/social/api/v1/conversations?limit=40&max_id=99>; rel="next"' },
		})
		const wrapper = mountMessages()
		await flushPromises()
		wrapper.vm.filterMode = 'unread'
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.direct-messages__no-matches').text()).toContain('No conversations match')
		const older = wrapper.find('.direct-messages__no-matches button')
		expect(older.exists()).toBe(true)

		get.mockResolvedValueOnce({ data: [{ ...structuredClone(conversation), id: '7', unread: true }] })
		await older.trigger('click')
		await flushPromises()

		expect(wrapper.findAll('.direct-messages__conversation')).toHaveLength(1)
		expect(wrapper.find('.direct-messages__unread-dot').exists()).toBe(true)
	})

	it('offers nothing older when the server sent no next cursor', async () => {
		const wrapper = mountMessages()
		await flushPromises()

		expect(wrapper.find('.direct-messages__more').exists()).toBe(false)
	})

	it('can retry loading the conversation list after a failed request', async () => {
		get.mockRejectedValueOnce(new Error('offline'))
		const wrapper = mountMessages()
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toContain('Could not load conversations')
		await wrapper.find('.direct-messages__retry-state button').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
		expect(wrapper.find('.direct-messages__conversation').exists()).toBe(true)
	})

	it('can retry loading a conversation after a failed request', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		get.mockRejectedValueOnce(new Error('offline'))
		await wrapper.setProps({ selectedConversationId: '10' })
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toContain('Could not load this conversation')
		await wrapper.find('.direct-messages__retry-state button').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
		expect(wrapper.findAll('.message-stub')).toHaveLength(3)
	})

	it('clears an old thread error when opening an empty conversation', async () => {
		const emptyConversation = { id: '20', unread: false, accounts: [carol], last_status: null }
		get.mockImplementation(async (url) => {
			if (url.endsWith('/conversations')) {
				return { data: [structuredClone(conversation), emptyConversation] }
			}
			throw new Error('offline')
		})
		const wrapper = mountMessages('10')
		await flushPromises()
		expect(wrapper.find('[role="alert"]').exists()).toBe(true)

		await wrapper.setProps({ selectedConversationId: '20' })
		await flushPromises()

		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
		expect(wrapper.find('.direct-messages__thread').text()).toContain('No messages in this conversation')
	})

	// Pasting a profile link is how one person sends another a profile, and
	// the account search looks at the account column, not at URLs — so without
	// asking for it to be resolved the box finds nobody at all. It is offered
	// for a lookup like any address, and resolved when the reader asks.
	it('resolves a pasted profile link when asked to', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		wrapper.vm.recipientQuery = 'https://remote.example/@bob'
		await flushPromises()
		expect(wrapper.vm.offerRecipientLookup).toBe(false)
		await searchImmediately(wrapper, 'https://remote.example/@bob')
		await flushPromises()
		expect(wrapper.vm.offerRecipientLookup).toBe(true)
		wrapper.vm.lookUpRecipient()
		await flushPromises()

		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/social/api/v1/accounts/search',
			{ params: { q: 'https://remote.example/@bob', limit: 8, resolve: true } },
		)
		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/social/api/v1/accounts/search',
			{ params: { q: 'https://remote.example/@bob', limit: 8, resolve: true, following: true } },
		)
	})

	/**
	 * Nothing re-read the inbox or the open chat on its own: the sidebar
	 * badge went up while the conversation on screen stayed as it was, and a
	 * reply appeared only after re-selecting the chat or reloading the page.
	 */
	describe('keeping up', () => {
		const listCalls = () => get.mock.calls.filter(([url]) => url.endsWith('/conversations'))
		const contextCalls = () => get.mock.calls.filter(([url]) => url.includes('/context'))
		const setVisibility = (state) => {
			Object.defineProperty(document, 'visibilityState', { value: state, configurable: true })
			document.dispatchEvent(new Event('visibilitychange'))
		}

		beforeEach(() => {
			// only the poll: the recipient search debounces with a timeout
			vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
		})

		afterEach(() => {
			setVisibility('visible')
			vi.useRealTimers()
		})

		it('reads the inbox and the open chat again every 30 seconds without push', async () => {
			const wrapper = mountMessages('10')
			await flushPromises()
			get.mockClear()

			vi.advanceTimersByTime(30 * 1000)
			// a refresh, not an opening: nothing on screen is replaced by "Loading…"
			expect(wrapper.vm.loadingList).toBe(false)
			expect(wrapper.vm.loadingThread).toBe(false)
			await flushPromises()

			expect(listCalls()).toHaveLength(1)
			expect(contextCalls()).toHaveLength(1)
		})

		it('shows a reply that arrived in the open chat and marks it read', async () => {
			const wrapper = mountMessages('10')
			await flushPromises()
			post.mockClear()
			const reply = { id: '13', content: '<p>And another</p>', created_at: '2026-09-23T12:20:00Z', account: bob }
			get.mockImplementation(async (url) => {
				if (url.endsWith('/conversations')) {
					return { data: [{ ...structuredClone(conversation), unread: true, last_status: reply }] }
				}
				return { data: { ancestors: [...context.ancestors, latest, ...context.descendants], descendants: [] } }
			})

			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()

			expect(wrapper.findAll('.message-stub').map((entry) => entry.attributes('data-id'))).toEqual(['9', '11', '12', '13'])
			expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations/10/read')
			expect(wrapper.find('.direct-messages__unread-dot').exists()).toBe(false)
		})

		it('listens for pushed direct messages and slows the poll down to five minutes', async () => {
			onTimelinePush.mockReturnValueOnce(true)
			const wrapper = mountMessages()
			await flushPromises()
			get.mockClear()
			// a post for the home timeline or a notification is no reason to read the inbox again
			expect(onTimelinePush).toHaveBeenCalledWith(expect.any(Function), ['direct'])

			onTimelinePush.mock.calls.at(-1)[0]()
			await flushPromises()
			expect(listCalls()).toHaveLength(1)

			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()
			expect(listCalls()).toHaveLength(1)

			vi.advanceTimersByTime(270 * 1000)
			await flushPromises()
			expect(listCalls()).toHaveLength(2)

			wrapper.unmount()
			expect(offTimelinePush).toHaveBeenCalledWith(onTimelinePush.mock.calls.at(-1)[0])
		})

		it('does not ask while the tab is hidden, and catches up on the way back', async () => {
			mountMessages()
			await flushPromises()
			get.mockClear()

			setVisibility('hidden')
			vi.advanceTimersByTime(31 * 1000)
			await flushPromises()
			expect(listCalls()).toHaveLength(0)

			setVisibility('visible')
			await flushPromises()
			expect(listCalls()).toHaveLength(1)
		})

		it('reads a message pushed to a hidden tab as soon as the tab is back', async () => {
			onTimelinePush.mockReturnValueOnce(true)
			mountMessages()
			await flushPromises()
			get.mockClear()

			setVisibility('hidden')
			onTimelinePush.mock.calls.at(-1)[0]()
			await flushPromises()
			expect(listCalls()).toHaveLength(0)

			// back well inside the five-minute poll
			vi.advanceTimersByTime(10 * 1000)
			setVisibility('visible')
			await flushPromises()
			expect(listCalls()).toHaveLength(1)
		})

		// sending used to read the first page afresh, so a reader who had
		// paged lost the older rows with every message they wrote
		it('keeps the older rows and their cursor when the inbox is read again', async () => {
			const older = { ...structuredClone(conversation), id: '7', accounts: [{ ...bob, id: 'friend', acct: 'carol@remote.example', display_name: 'Carol' }] }
			get.mockImplementation(async (url, { params } = {}) => {
				if (url.endsWith('/conversations')) {
					return params?.max_id === '99'
						? { data: [older], headers: { link: '</index.php/apps/social/api/v1/conversations?limit=40&max_id=50>; rel="next"' } }
						: { data: [structuredClone(conversation)], headers: { link: '</index.php/apps/social/api/v1/conversations?limit=40&max_id=99>; rel="next"' } }
				}
				return { data: context }
			})
			const wrapper = mountMessages('10')
			await flushPromises()
			await wrapper.find('.direct-messages__more button').trigger('click')
			await flushPromises()
			expect(wrapper.findAll('.direct-messages__conversation')).toHaveLength(2)

			await wrapper.find('.direct-messages__message-form textarea').setValue('Hello')
			await wrapper.find('.direct-messages__message-form').trigger('submit')
			await flushPromises()
			expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/statuses', expect.objectContaining({ visibility: 'direct' }))

			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()

			expect(wrapper.findAll('.direct-messages__name').map((name) => name.text())).toEqual(['Bob', 'Carol'])
			expect(wrapper.vm.cursor).toBe('50')
			expect(wrapper.find('.direct-messages__more').exists()).toBe(true)
		})

		it('keeps the open chat open when a new thread with the same person arrives', async () => {
			const wrapper = mountMessages('10')
			await flushPromises()
			const second = { id: '21', content: '<p>Second thread</p>', created_at: '2026-09-24T09:00:00Z', account: bob }
			get.mockImplementation(async (url) => {
				if (url.endsWith('/conversations')) {
					return { data: [{ ...structuredClone(conversation), id: '11', unread: true, last_status: second }, { ...structuredClone(conversation), unread: false }] }
				}
				if (url.includes('/statuses/21/')) {
					return { data: { ancestors: [], descendants: [] } }
				}
				return { data: context }
			})

			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()

			expect(wrapper.findAll('.direct-messages__conversation')).toHaveLength(1)
			expect(wrapper.find('.direct-messages__thread-heading h2').text()).toBe('Bob')
			expect(wrapper.findAll('.message-stub').map((entry) => entry.attributes('data-id'))).toEqual(['9', '11', '12', '21'])
			expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations/11/read')
		})

		it('does not pull a reader who scrolled up back to the bottom when nothing arrived', async () => {
			const wrapper = mountMessages('10')
			await flushPromises()
			const thread = wrapper.find('.direct-messages__thread').element
			let scrollTop = 0
			Object.defineProperty(thread, 'scrollTop', {
				get: () => scrollTop,
				set: (value) => {
					scrollTop = value
				},
				configurable: true,
			})
			Object.defineProperty(thread, 'scrollHeight', { value: 900, configurable: true })
			scrollTop = 120

			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()
			await wrapper.vm.$nextTick()

			expect(scrollTop).toBe(120)
		})
	})

	it('keeps failed read markers from hiding a loaded thread', async () => {
		post.mockRejectedValue(new Error('offline'))
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.setProps({ selectedConversationId: '10' })
		await flushPromises()

		expect(wrapper.findAll('.message-stub')).toHaveLength(3)
		expect(wrapper.find('.direct-messages__state[role="alert"]').exists()).toBe(false)
		expect(wrapper.find('.direct-messages__unread-dot').exists()).toBe(true)
	})
})
