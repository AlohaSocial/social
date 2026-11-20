/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { cloneVNode, Fragment, h } from 'vue'

vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

import PostMenu from '../../../src/components/PostMenu.vue'
import { showError, showSuccess } from '../../../src/services/toast.js'

const ME = { acct: 'alice' }

const NcActions = {
	name: 'NcActions',
	emits: ['update:open'],
	render() {
		const actions = []
		const collect = (vnodes) => vnodes.forEach((vnode) => {
			if (vnode.type === Fragment) {
				collect(vnode.children)
			} else if (vnode.type?.name?.startsWith('NcAction')) {
				actions.push(cloneVNode(vnode))
			}
		})
		collect(this.$slots.default?.() ?? [])
		// a fresh element each time: the menu swaps every row when a submenu opens
		return h('div', { key: Symbol('render') }, actions)
	},
}
const NcActionButton = {
	name: 'NcActionButton',
	// `closeAfterClick` is written as a bare attribute, which is only true for a Boolean prop
	props: { disabled: Boolean, icon: String, closeAfterClick: Boolean, isMenu: Boolean, title: String },
	emits: ['click'],
	template: '<button class="action" :disabled="disabled" :data-closes="closeAfterClick ? \'yes\' : \'no\'" :data-menu="isMenu ? \'yes\' : \'no\'" @click="$emit(\'click\')"><slot /></button>',
}
const NcActionCaption = {
	name: 'NcActionCaption',
	props: ['name'],
	template: '<p class="caption">{{ name }}</p>',
}
const NcActionSeparator = {
	name: 'NcActionSeparator',
	template: '<hr>',
}
const NcActionLink = {
	name: 'NcActionLink',
	props: ['href', 'target'],
	template: '<a class="action action--link" :href="href"><slot /></a>',
}

/**
 * @param {object} item what the post says about itself
 * @param {object} props the rest of what the menu is told
 * @return {object} the mounted menu
 */
function mountMenu(item = {}, props = {}) {
	return mount(PostMenu, {
		props: {
			item: {
				id: '1',
				account: { acct: 'jens@chaos.social' },
				visibility: 'public',
				url: 'https://chaos.social/@jens/1',
				media_attachments: [],
				...item,
			},
			currentAccount: ME,
			...props,
		},
		global: { stubs: { NcActions, NcActionButton, NcActionLink, NcActionCaption, NcActionSeparator } },
	})
}

/** @param {object} wrapper the mounted menu @return {string[]} what it offers */
const items = (wrapper) => wrapper.findAll('.action').map((one) => one.text())

/**
 * @param {object} wrapper the mounted menu
 * @param {string} label what the item says
 * @return {object|undefined} that item
 */
function itemFor(wrapper, label) {
	return wrapper.findAll('.action').find((one) => one.text() === label)
}

/**
 * Opens one of the menu's submenus.
 *
 * @param {object} wrapper the mounted menu
 * @param {string} label what the submenu entry says
 */
async function openSubmenu(wrapper, label) {
	await itemFor(wrapper, label).trigger('click')
}

/** @param {object} wrapper the mounted menu @return {string[]} its section captions */
const captions = (wrapper) => wrapper.findAll('.caption').map((one) => one.text())

/** A post the reader wrote, on this server, with a photo in it. */
const MINE = { account: ME, local: true, media_attachments: [{ id: '1' }] }

describe('the post menu', () => {
	describe('somebody else post', () => {
		it('offers what can be done to it and nothing that cannot', () => {
			const wrapper = mountMenu()
			expect(items(wrapper)).toEqual([
				'Quote',
				'Open on original instance',
				'Add a reaction',
				'Mute conversation',
				'Copy embed code',
				'Mute',
				'Block',
				'Report',
			])
			expect(captions(wrapper)).toEqual(['About jens@chaos.social'])
		})

		it('offers a reaction to a keyboard, which has no hold or right-click', async () => {
			const wrapper = mountMenu()
			await itemFor(wrapper, 'Add a reaction').trigger('click')

			expect(wrapper.emitted('react')).toHaveLength(1)
			expect(items(mountMenu({}, { isPublic: true }))).not.toContain('Add a reaction')
		})

		it('offers no conversation mute on a public page', () => {
			const offered = items(mountMenu({}, { isPublic: true }))
			expect(offered).not.toContain('Mute conversation')
			expect(offered).not.toContain('Unmute conversation')
		})

		it('offers recommendation feedback without letting readers choose topics', async () => {
			const post = { id: '2', nid: '123', account: { acct: 'bob@remote.example' } }
			const wrapper = mountMenu(post, {
				timelineType: 'interests',
				recommendationFeedbackEnabled: true,
				feedbackContext: { similarity_available: true, format: 'image' },
			})

			expect(items(wrapper)).toContain('Tune For you')
			await openSubmenu(wrapper, 'Tune For you')
			expect(items(wrapper)).toEqual([
				'Back',
				'More like this',
				'Less like this',
				'More from this author',
				'More photo posts',
				'Hide from For you',
			])

			await itemFor(wrapper, 'More like this').trigger('click')
			expect(wrapper.emitted('recommendationFeedback')).toEqual([['MORE_SIMILAR']])
			expect(items(mountMenu(post, { timelineType: 'home', recommendationFeedbackEnabled: true })))
				.toContain('Tune For you')

			await itemFor(wrapper, 'Back').trigger('click')
			expect(items(wrapper)).toContain('Tune For you')
		})

		it('keeps context-dependent actions present and disabled when no automatic cluster is available', async () => {
			const post = { id: '2', nid: '123', account: { acct: 'bob@remote.example' } }
			const wrapper = mountMenu(post, {
				timelineType: 'interests',
				recommendationFeedbackEnabled: true,
				feedbackContext: { similarity_available: false, format: 'text' },
			})

			await openSubmenu(wrapper, 'Tune For you')
			expect(itemFor(wrapper, 'More like this').element.disabled).toBe(true)
			expect(itemFor(wrapper, 'Less like this').element.disabled).toBe(true)
			expect(itemFor(wrapper, 'More text posts').element.disabled).toBe(false)
		})

		it('keeps recommendation menu rows stable while their context loads', async () => {
			const post = { id: '2', nid: '123', account: { acct: 'bob@remote.example' } }
			const wrapper = mountMenu(post, {
				timelineType: 'interests',
				recommendationFeedbackEnabled: true,
				recommendationFeedbackLoading: true,
			})
			await openSubmenu(wrapper, 'Tune For you')
			const labels = [
				'More like this',
				'Less like this',
				'Hide from For you',
				'More from this author',
				'More posts like this',
			]

			const before = items(wrapper)
			const beforeCount = before.length
			expect(before).toContain(labels[0])
			expect(before).toContain(labels[1])
			expect(before).toContain(labels[4])
			for (const label of [labels[0], labels[1], labels[4]]) {
				expect(itemFor(wrapper, label).element.disabled).toBe(true)
			}

			await wrapper.setProps({
				recommendationFeedbackLoading: false,
				feedbackContext: { similarity_available: true, format: 'image' },
			})

			const after = items(wrapper)
			expect(after).toHaveLength(beforeCount)
			expect(after.indexOf(labels[0])).toBe(before.indexOf(labels[0]))
			expect(after.indexOf(labels[1])).toBe(before.indexOf(labels[1]))
			expect(after.indexOf('More photo posts')).toBe(before.indexOf(labels[4]))
			expect(itemFor(wrapper, labels[0]).element.disabled).toBe(false)
			expect(itemFor(wrapper, labels[1]).element.disabled).toBe(false)
			expect(itemFor(wrapper, 'More photo posts').element.disabled).toBe(false)
		})

		it('offers to unmute the conversation it is in once it is muted, and says so to the post', async () => {
			const wrapper = mountMenu({ muted: true })
			expect(items(wrapper)).toContain('Unmute conversation')
			expect(items(wrapper)).not.toContain('Mute conversation')

			await itemFor(wrapper, 'Unmute conversation').trigger('click')

			expect(wrapper.emitted('muteConversation')).toHaveLength(1)
		})

		/** A Bluesky post has no "instance"; the link says where it really goes. */
		it('offers to open a Bluesky post on Bluesky', () => {
			const author = { acct: 'dana.bsky.social', bluesky: { handle: 'dana.bsky.social', did: 'did:plc:d', url: 'https://bsky.app/profile/dana.bsky.social', native: true } }
			const wrapper = mountMenu({ account: author, url: 'https://bsky.app/profile/dana.bsky.social/post/3k2a' })

			const link = wrapper.find('.action--link')
			expect(link.text()).toBe('Open on Bluesky')
			expect(link.attributes('href')).toBe('https://bsky.app/profile/dana.bsky.social/post/3k2a')
			expect(items(wrapper)).not.toContain('Open on original instance')
		})

		it('reads the post address when the author entity does not say', () => {
			const wrapper = mountMenu({ account: { acct: 'dana.bsky.social' }, url: 'https://bsky.app/profile/did:plc:d/post/3k2a' })
			expect(wrapper.find('.action--link').text()).toBe('Open on Bluesky')
			expect(mountMenu().find('.action--link').text()).toBe('Open on original instance')
		})

		/** Every one of these is the reader acting on their own post. */
		it('never offers to edit, delete or archive it', () => {
			const offered = items(mountMenu())

			for (const own of ['Edit', 'Delete', 'Archive', 'Details', 'Delivery status']) {
				expect(offered).not.toContain(own)
			}
		})
	})

	describe('the reader own post', () => {
		it('offers everything an author can do with it', async () => {
			const wrapper = mountMenu(MINE)
			expect(items(wrapper)).toEqual([
				'Quote',
				'Add a reaction',
				'Mute conversation',
				'Edit',
				'Who can reply',
				'Quotes of this post',
				'Tag people',
				'Pin to profile',
				'Add to a collection',
				'Archive',
				'Details',
				'Delete',
			])
			expect(captions(wrapper)).toEqual(['Your post'])

			await openSubmenu(wrapper, 'Details')
			expect(items(wrapper)).toEqual(['Back', 'Delivery status', 'Copy embed code'])
		})

		it('no longer offers to delete and write it again: Edit does that', () => {
			expect(items(mountMenu(MINE))).not.toContain('Delete & re-draft')
		})

		/**
		 * Muting, blocking and reporting yourself are all nonsense. Quoting
		 * yourself is not — it is how somebody adds to their own post without
		 * editing what people have already read — so that one stays.
		 */
		it('does not offer the things that are about somebody else', () => {
			const offered = items(mountMenu(MINE))

			expect(offered).toContain('Quote')
			expect(offered).not.toContain('Report')
			// the conversation is the reader's to mute, their own post or not
			expect(offered.filter((one) => one.startsWith('Mute'))).toEqual(['Mute conversation'])
			expect(offered.some((one) => one.startsWith('Block'))).toBe(false)
		})

		it('has nothing to say about a post with no picture in it', () => {
			const offered = items(mountMenu({ ...MINE, media_attachments: [] }))

			expect(offered).not.toContain('Tag people')
			expect(offered).not.toContain('Add to a collection')
		})

		/**
		 * The queue and the quote list are this server's records of a post it
		 * sent. A boost of somebody else's post has neither.
		 */
		it('does not offer this server records for a post it did not write', () => {
			const offered = items(mountMenu({ ...MINE, local: false }))

			expect(offered).not.toContain('Details')
			expect(offered).not.toContain('Quotes of this post')
			expect(offered).not.toContain('Who can reply')
			expect(offered).not.toContain('Pin to profile')
		})

		/** `local` is absent on a post from before the column existed. */
		it('treats a post that never said where it is from as local', async () => {
			const withoutLocal = { ...MINE }
			delete withoutLocal.local
			const wrapper = mountMenu(withoutLocal)

			await openSubmenu(wrapper, 'Details')
			expect(items(wrapper)).toContain('Delivery status')
		})
	})

	/**
	 * A quote and a boost both put the post in front of the reader's own
	 * audience, so both are only for a post that was already everybody's.
	 */
	/**
	 * Every item either opens something else -- a dialog, the edit form, the
	 * composer -- or acts at once. A menu left floating over the edit form it
	 * just opened covered Save and Cancel, and one left open behind the delete
	 * confirmation was still there when the dialog closed.
	 */
	it('closes itself after any item is chosen', async () => {
		const tuned = { recommendationFeedbackEnabled: true, feedbackContext: { similarity_available: true, format: 'image' } }
		const menus = [mountMenu(), mountMenu(MINE), mountMenu({ nid: '5', in_reply_to_id: '9', can_hide: true, tags: [{ name: 'x' }] }, { canTranslate: true, ...tuned })]
		const stayingOpen = (wrapper) => wrapper.findAll('button.action')
			.filter((one) => one.attributes('data-closes') !== 'yes' && one.attributes('data-menu') !== 'yes' && one.text() !== 'Back')
			.map((one) => one.text())
		for (const wrapper of menus) {
			// the link to the original instance closes the menu by leaving the page
			expect(stayingOpen(wrapper)).toEqual([])
		}

		await openSubmenu(menus[1], 'Details')
		expect(stayingOpen(menus[1])).toEqual([])
		await openSubmenu(menus[2], 'Tune For you')
		expect(stayingOpen(menus[2])).toEqual([])
	})

	it('opens on its main level again after a submenu was left open', async () => {
		const wrapper = mountMenu(MINE)
		await openSubmenu(wrapper, 'Details')

		wrapper.findComponent(NcActions).vm.$emit('update:open', false)
		await wrapper.vm.$nextTick()

		expect(items(wrapper)).toContain('Edit')
	})

	describe('what may be passed on', () => {
		it('offers a quote only for a post anybody may see', () => {
			expect(items(mountMenu({ visibility: 'unlisted' }))).toContain('Quote')
			expect(items(mountMenu({ visibility: 'private' }))).not.toContain('Quote')
			expect(items(mountMenu({ visibility: 'direct' }))).not.toContain('Quote')
		})

		it('offers embed code only for a local public post', () => {
			const local = { account: { acct: 'bob' }, local: true }
			expect(items(mountMenu({ ...local, visibility: 'public' }))).toContain('Copy embed code')
			expect(items(mountMenu({ ...local, visibility: 'unlisted' }))).not.toContain('Copy embed code')
			expect(items(mountMenu({ visibility: 'public', local: false }))).not.toContain('Copy embed code')
		})

		it('copies the dedicated embed endpoint rather than the app page', async () => {
			const writeText = vi.fn().mockResolvedValue(undefined)
			Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
			const wrapper = mountMenu({ account: { acct: 'bob' }, local: true, visibility: 'public', url: 'https://cloud.example/apps/social/@bob/1?view=post#top' })

			await itemFor(wrapper, 'Copy embed code').trigger('click')

			expect(writeText).toHaveBeenCalledWith('<iframe src="https://cloud.example/apps/social/@bob/1/embed" width="550" height="480" loading="lazy" frameborder="0" style="max-width:100%;border:0;border-radius:12px" title="Aloha Social post"></iframe>')
		})

		it('says through the lazily loaded toasts whether the code was copied', async () => {
			Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: vi.fn().mockResolvedValue(undefined) } })
			const local = { account: { acct: 'bob' }, local: true, visibility: 'public' }
			await itemFor(mountMenu(local), 'Copy embed code').trigger('click')
			await vi.waitFor(() => expect(showSuccess).toHaveBeenCalledWith('Copied'))

			Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } })
			await itemFor(mountMenu(local), 'Copy embed code').trigger('click')
			await vi.waitFor(() => expect(showError).toHaveBeenCalled())
		})

		it('does not offer a quote the author said no to', () => {
			expect(items(mountMenu({ interaction_policy: { quote: false } })))
				.not.toContain('Quote')
		})

		/**
		 * A pinned followers-only post was served in full to the anonymous
		 * internet through the featured collection.
		 */
		it('offers to pin only what anybody may already see', () => {
			expect(items(mountMenu({ ...MINE, visibility: 'private' }))).not.toContain('Pin to profile')
			expect(items(mountMenu({ ...MINE, visibility: 'unlisted' }))).toContain('Pin to profile')
		})
	})

	describe('the items that change what they say', () => {
		it('offers to undo whatever already stands', () => {
			expect(items(mountMenu({ ...MINE, pinned: true }))).toContain('Unpin from profile')
			expect(items(mountMenu({ ...MINE, archived: true }))).toContain('Put back on my profile')
		})

		it('offers the original once a translation is showing', () => {
			expect(items(mountMenu({}, { canTranslate: true }))).toContain('Translate')
			expect(items(mountMenu({}, { canTranslate: true, translated: true })))
				.toContain('Show original')
		})
	})

	describe('what cannot be pressed twice', () => {
		it('will not archive again while it is archiving', () => {
			expect(itemFor(mountMenu(MINE, { archiving: true }), 'Archive').attributes('disabled'))
				.toBeDefined()
		})

		it('will not translate again while it is translating', () => {
			const wrapper = mountMenu({}, { canTranslate: true, translating: true })

			expect(itemFor(wrapper, 'Translate').attributes('disabled')).toBeDefined()
		})
	})

	describe('opening the post where it was written', () => {
		it('links to it for a post from another server', () => {
			expect(itemFor(mountMenu(), 'Open on original instance').attributes('href'))
				.toBe('https://chaos.social/@jens/1')
		})

		/** This is where it was written; there is nowhere else to go. */
		it('does not offer it for a post written here', () => {
			expect(items(mountMenu({ account: { acct: 'bob' } })))
				.not.toContain('Open on original instance')
		})

		it('does not offer it for a post with no address at all', () => {
			expect(items(mountMenu({ url: '' }))).not.toContain('Open on original instance')
		})
	})

	/** There is nobody signed in to do the muting. */
	it('offers nothing about the author on a page with no reader', () => {
		const offered = items(mountMenu({}, { isPublic: true, currentAccount: null }))

		expect(offered.some((one) => one.startsWith('Mute'))).toBe(false)
		expect(offered.some((one) => one.startsWith('Block'))).toBe(false)
	})

	/**
	 * The menu decides what to offer; it never carries anything out. Each item
	 * says what was asked for and the post does the rest, which is where the
	 * dialogs and the store are.
	 */
	describe('what it asks for', () => {
		it.each([
			['Quote', 'quote'],
			['Report', 'report'],
			['Mute', 'mute'],
			['Block', 'block'],
		])('asks for %s', (label, event) => {
			const wrapper = mountMenu()

			itemFor(wrapper, label).trigger('click')

			expect(wrapper.emitted(event)).toHaveLength(1)
		})

		it.each([
			['Edit', 'edit'],
			['Archive', 'archive'],
			['Quotes of this post', 'manageQuotes'],
			['Who can reply', 'manageReplies'],
			['Tag people', 'tagPeople'],
			['Delete', 'delete'],
			['Add to a collection', 'collect'],
			['Pin to profile', 'pin'],
		])('asks for %s', (label, event) => {
			const wrapper = mountMenu(MINE)

			itemFor(wrapper, label).trigger('click')

			expect(wrapper.emitted(event)).toHaveLength(1)
		})

		it('asks for the delivery status from the details', async () => {
			const wrapper = mountMenu(MINE)
			await openSubmenu(wrapper, 'Details')

			itemFor(wrapper, 'Delivery status').trigger('click')

			expect(wrapper.emitted('delivery')).toHaveLength(1)
		})

		it('lets the author of a conversation hide a reply in it, and show it again', () => {
			const visible = mountMenu({ in_reply_to_id: '9', can_hide: true, hidden_by_author: false })
			itemFor(visible, 'Hide reply').trigger('click')
			expect(visible.emitted('hideReply')).toEqual([[true]])

			const hidden = mountMenu({ in_reply_to_id: '9', can_hide: true, hidden_by_author: true })
			itemFor(hidden, 'Show reply').trigger('click')
			expect(hidden.emitted('hideReply')).toEqual([[false]])

			expect(items(mountMenu({ in_reply_to_id: '9', can_hide: false }))).not.toContain('Hide reply')
		})

		it('asks for a translation', () => {
			const wrapper = mountMenu({}, { canTranslate: true })

			itemFor(wrapper, 'Translate').trigger('click')

			expect(wrapper.emitted('translate')).toHaveLength(1)
		})

		/**
		 * The menu opens in a portal, so the card it belongs to has to know it
		 * is open: without that, the pointer leaving the card takes the row the
		 * menu is in away while the menu is still showing.
		 */
		it('says when it is open', () => {
			const wrapper = mountMenu()

			wrapper.findComponent(NcActions).vm.$emit('update:open', true)

			expect(wrapper.emitted('update:open')).toEqual([[true]])
		})
	})
})

describe('less like this', () => {
	const ON = { enabled: true, learning: true, paused: false }
	const tagged = { tags: [{ name: 'film' }] }
	const offers = (wrapper) => items(wrapper).includes('Less like this')

	it('is offered on somebody else\'s tagged post while For you is on', () => {
		const wrapper = mountMenu(tagged, { interests: ON })

		expect(offers(wrapper)).toBe(true)
		itemFor(wrapper, 'Less like this').trigger('click')
		expect(wrapper.emitted('lessLikeThis')).toHaveLength(1)
	})

	it('is still offered while learning is paused: the feed is still there', () => {
		expect(offers(mountMenu(tagged, { interests: { ...ON, paused: true } }))).toBe(true)
	})

	it.each([
		['the feature is off', tagged, { interests: { ...ON, enabled: false } }],
		['the reader opted out', tagged, { interests: { ...ON, learning: false } }],
		['the server says nothing', tagged, {}],
		['the post has no hashtag', { tags: [] }, { interests: ON }],
		['the reader wrote it', { ...tagged, account: ME }, { interests: ON }],
		['nobody is signed in', tagged, { interests: ON, isPublic: true }],
	])('is not offered when %s', (_, item, props) => {
		expect(offers(mountMenu(item, props))).toBe(false)
	})
})
