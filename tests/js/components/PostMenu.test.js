/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import PostMenu from '../../../src/components/PostMenu.vue'

const ME = { acct: 'alice' }

const NcActions = { name: 'NcActions', emits: ['update:open'], template: '<div><slot /></div>' }
const NcActionButton = {
	name: 'NcActionButton',
	props: ['disabled', 'icon'],
	emits: ['click'],
	template: '<button class="action" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
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
		global: { stubs: { NcActions, NcActionButton, NcActionLink } },
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

/** A post the reader wrote, on this server, with a picture in it. */
const MINE = { account: ME, local: true, media_attachments: [{ id: '1' }] }

describe('the post menu', () => {
	describe('somebody else post', () => {
		it('offers what can be done to it and nothing that cannot', () => {
			expect(items(mountMenu())).toEqual([
				'Quote',
				'Open on original instance',
				'Bookmark',
				'Add a reaction',
				'Mute conversation',
				'Mute jens@chaos.social',
				'Block jens@chaos.social',
				'Report',
			])
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

		it('offers to unmute the conversation it is in once it is muted, and says so to the post', async () => {
			const wrapper = mountMenu({ muted: true })
			expect(items(wrapper)).toContain('Unmute conversation')
			expect(items(wrapper)).not.toContain('Mute conversation')

			await itemFor(wrapper, 'Unmute conversation').trigger('click')

			expect(wrapper.emitted('muteConversation')).toHaveLength(1)
		})

		/** Every one of these is the reader acting on their own post. */
		it('never offers to edit, delete or archive it', () => {
			const offered = items(mountMenu())

			for (const own of ['Edit', 'Delete', 'Archive', 'Delete & re-draft', 'Delivery status']) {
				expect(offered).not.toContain(own)
			}
		})
	})

	describe('the reader own post', () => {
		it('offers everything an author can do with it', () => {
			expect(items(mountMenu(MINE))).toEqual([
				'Quote',
				'Edit',
				'Archive',
				'Quotes of this post',
				'Tag people',
				'Delete',
				'Delete & re-draft',
				'Delivery status',
				'Bookmark',
				'Add a reaction',
				'Mute conversation',
				'Add to a collection',
				'Pin to profile',
			])
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

			expect(offered).not.toContain('Delivery status')
			expect(offered).not.toContain('Quotes of this post')
			expect(offered).not.toContain('Pin to profile')
		})

		/** `local` is absent on a post from before the column existed. */
		it('treats a post that never said where it is from as local', () => {
			const withoutLocal = { ...MINE }
			delete withoutLocal.local

			expect(items(mountMenu(withoutLocal))).toContain('Delivery status')
		})
	})

	/**
	 * A quote and a boost both put the post in front of the reader's own
	 * audience, so both are only for a post that was already everybody's.
	 */
	describe('what may be passed on', () => {
		it('offers a quote only for a post anybody may see', () => {
			expect(items(mountMenu({ visibility: 'unlisted' }))).toContain('Quote')
			expect(items(mountMenu({ visibility: 'private' }))).not.toContain('Quote')
			expect(items(mountMenu({ visibility: 'direct' }))).not.toContain('Quote')
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
			expect(items(mountMenu({ bookmarked: true }))).toContain('Remove bookmark')
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
			['Bookmark', 'bookmark'],
			['Report', 'report'],
			['Mute jens@chaos.social', 'mute'],
			['Block jens@chaos.social', 'block'],
		])('asks for %s', (label, event) => {
			const wrapper = mountMenu()

			itemFor(wrapper, label).trigger('click')

			expect(wrapper.emitted(event)).toHaveLength(1)
		})

		it.each([
			['Edit', 'edit'],
			['Archive', 'archive'],
			['Quotes of this post', 'manageQuotes'],
			['Tag people', 'tagPeople'],
			['Delete', 'delete'],
			['Delete & re-draft', 'redraft'],
			['Delivery status', 'delivery'],
			['Add to a collection', 'collect'],
			['Pin to profile', 'pin'],
		])('asks for %s', (label, event) => {
			const wrapper = mountMenu(MINE)

			itemFor(wrapper, label).trigger('click')

			expect(wrapper.emitted(event)).toHaveLength(1)
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
