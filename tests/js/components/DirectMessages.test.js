/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import DirectMessages from '../../../src/components/DirectMessages.vue'

const bob = { id: 'https://remote.example/users/bob', acct: 'bob@remote.example', display_name: 'Bob' }
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
	NcListItem: {
		props: ['name', 'details', 'active', 'bold', 'linkAriaLabel'],
		template: '<button v-bind="$attrs" class="native-list-item-stub"><slot name="icon" /><span>{{ name }}</span><slot name="subname" /><slot name="indicator" /><span class="native-actions"><slot name="actions" /></span></button>',
	},
	NcTextField: {
		props: ['modelValue', 'label', 'placeholder', 'type'],
		emits: ['update:modelValue'],
		template: '<label><span>{{ label }}</span><input :value="modelValue" :placeholder="placeholder" :type="type" @input="$emit(\'update:modelValue\', $event.target.value)"></label>',
	},
	// shaped like the real one: the textarea is the element, and `label` draws
	// a floating one over it rather than a caption beside it
	NcTextArea: {
		name: 'NcTextArea',
		// `labelOutside` is typed on the real component, so a bare attribute
		// casts to true; untyped it would arrive as the empty string
		props: { modelValue: {}, label: {}, placeholder: {}, labelOutside: { type: Boolean } },
		emits: ['update:modelValue'],
		template: '<div class="textarea"><span v-if="label && !labelOutside" class="textarea__label">{{ label }}</span><textarea class="textarea__input" :value="modelValue" :placeholder="placeholder" @input="$emit(\'update:modelValue\', $event.target.value)" /></div>',
	},
	TimelineEntry: { props: ['item', 'type', 'hideAuthor', 'hideAvatar'], template: '<article class="message-stub" :data-id="item.id" :data-hide-author="hideAuthor" :data-hide-avatar="hideAvatar">{{ item.content }}</article>' },
}

function mountMessages(selectedConversationId = '') {
	return mount(DirectMessages, {
		props: { selectedConversationId },
		global: { stubs },
	})
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
	 * The composer is a chat box: the placeholder is the whole of its label.
	 *
	 * Given a `label`, `NcTextArea` draws a floating one absolutely positioned
	 * 11px from the top of the input — and this composer halves the padding
	 * the component reserves for it, so the label sat on top of whatever was
	 * being typed. `labelOutside` says there is no floating label to place,
	 * and the field is named for a screen reader the other way.
	 */
	it('names the message box without drawing a label over it', async () => {
		const wrapper = mountMessages('10')
		await flushPromises()

		const boxes = wrapper.findAllComponents({ name: 'NcTextArea' })

		expect(boxes.length).toBeGreaterThan(0)
		for (const box of boxes) {
			expect(box.props('labelOutside')).toBe(true)
			expect(box.props('label')).toBeFalsy()
			expect(box.attributes('aria-label')).toBe('Write a message…')
		}
		// and so nothing is drawn over the field
		expect(wrapper.find('.textarea__label').exists()).toBe(false)
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

	it('filters the inbox by the other participant and latest message', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.find('.direct-messages__search input').setValue('nobody')

		expect(wrapper.find('.direct-messages__conversation').exists()).toBe(false)
		expect(wrapper.find('.direct-messages__state').text()).toBe('No conversations match your search')

		await wrapper.find('.direct-messages__search input').setValue('latest')
		expect(wrapper.find('.direct-messages__conversation').exists()).toBe(true)
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

		expect(all.attributes('aria-pressed')).toBe('true')
		expect(unread.attributes('aria-pressed')).toBe('false')
		await unread.trigger('click')
		expect(all.attributes('aria-pressed')).toBe('false')
		expect(unread.attributes('aria-pressed')).toBe('true')
	})

	it('offers a clear empty inbox and a direct way to start a message', async () => {
		get.mockResolvedValue({ data: [] })
		const wrapper = mountMessages()
		await flushPromises()

		expect(wrapper.find('.direct-messages__welcome').text()).toContain('Start a private chat')
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
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/search', { params: { q: '@bob', limit: 8, resolve: true } })
		expect(wrapper.find('.direct-messages__recipient-results').text()).toContain('Bob')
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

		const names = (wrapper, group) => wrapper.findAll(`.direct-messages__recipient-results--${group} .native-list-item-stub > span:not(.native-actions):not(.avatar-stub)`)
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
	it('keeps a second conversation with the same person as its own row', async () => {
		get.mockResolvedValueOnce({ data: [
			structuredClone(conversation),
			{ ...structuredClone(conversation), id: '11' },
		] })
		const wrapper = mountMessages()
		await flushPromises()

		expect(wrapper.findAll('.direct-messages__conversation')).toHaveLength(2)
	})

	it('keeps an unread second thread with the same person reachable', async () => {
		get.mockResolvedValueOnce({ data: [
			{ ...structuredClone(conversation), unread: false },
			{ ...structuredClone(conversation), id: '11', unread: true },
		] })
		const wrapper = mountMessages()
		await flushPromises()

		await wrapper.findAll('.direct-messages__conversation')[1].trigger('click')
		expect(wrapper.emitted('select').at(-1)).toEqual(['11'])
	})

	// Group threads collided the same way, on whichever member happened to be
	// listed first.
	it('keeps group conversations sharing their first participant apart', async () => {
		const carol = { id: 'https://remote.example/users/carol', acct: 'carol@remote.example', display_name: 'Carol' }
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

		get.mockResolvedValueOnce({ data: [{ ...structuredClone(conversation), id: '7' }] })
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
		const emptyConversation = { id: '20', unread: false, accounts: [bob], last_status: null }
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
	// asking for it to be resolved the box finds nobody at all.
	it('resolves a pasted profile link', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.vm.searchAccounts('https://remote.example/@bob')

		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/social/api/v1/accounts/search',
			{ params: { q: 'https://remote.example/@bob', limit: 8, resolve: true } },
		)
		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/social/api/v1/accounts/search',
			{ params: { q: 'https://remote.example/@bob', limit: 8, resolve: true, following: true } },
		)
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
