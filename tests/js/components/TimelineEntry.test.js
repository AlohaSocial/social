/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount, RouterLinkStub } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import TimelineEntry from '../../../src/components/TimelineEntry.vue'
import AccountHoverCard from '../../../src/components/AccountHoverCard.vue'
import ActorAvatar from '../../../src/components/ActorAvatar.vue'
import { createPinia, setActivePinia } from 'pinia'
import { useTimelineStore } from '../../../src/store/timeline.js'

// the phone query, under the test's control: what it answers and who listens
const phone = vi.hoisted(() => ({ matches: false, listeners: new Set() }))
vi.mock('../../../src/services/phone.js', () => ({
	isPhone: () => phone.matches,
	onPhoneChange: (callback) => {
		phone.listeners.add(callback)
		return () => phone.listeners.delete(callback)
	},
}))

const alice = {
	id: '1',
	acct: 'alice',
	username: 'alice',
	display_name: 'Alice',
	url: 'https://cloud.example.org/@alice',
	avatar: 'https://cloud.example.org/avatar/alice/64',
}

const bob = {
	id: '2',
	acct: 'bob@remote.example',
	username: 'bob',
	display_name: 'Bob',
	url: 'https://remote.example/@bob',
	avatar: 'https://remote.example/avatar.png',
}

const post = {
	id: 'p1',
	created_at: '2026-09-01T10:00:00Z',
	content: '<p>Original post</p>',
	visibility: 'public',
	reblog: null,
	account: alice,
}

const storedPost = { ...post, content: '<p>Original post, as kept in the store</p>' }

const boost = {
	id: 'b1',
	created_at: '2026-09-02T10:00:00Z',
	content: '',
	visibility: 'public',
	reblog: post,
	account: bob,
}

function notification(type, extra = {}) {
	return {
		id: `n-${type}`,
		type,
		created_at: '2026-09-03T10:00:00Z',
		account: bob,
		status: post,
		...extra,
	}
}

const TimelinePostStub = {
	name: 'TimelinePost',
	props: ['item', 'type'],
	template: '<div class="timeline-post-stub" />',
}
const TimelineAvatarStub = {
	name: 'TimelineAvatar',
	props: ['item', 'size'],
	template: '<div class="timeline-avatar-stub" />',
}
const UserEntryStub = {
	name: 'UserEntry',
	props: ['item', 'displayFollowButton'],
	template: '<div class="user-entry-stub" />',
}

describe('a reply in a thread', () => {
	it('steps in by its depth, capped where the column would run out', () => {
		expect(mountEntry(storedPost).wrapper.classes()).not.toContain('timeline-entry--reply')

		const nested = mountEntry(storedPost, { depth: 2 }).wrapper
		expect(nested.classes()).toContain('timeline-entry--reply')
		expect(nested.attributes('style')).toContain('--thread-depth: 2')

		expect(mountEntry(storedPost, { depth: 9 }).wrapper.attributes('style')).toContain('--thread-depth: 4')
	})
})

function mountEntry(item, props = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	const timelineStore = useTimelineStore()
	timelineStore.addToStatuses(storedPost)
	const wrapper = mount(TimelineEntry, {
		props: { item, type: 'home', ...props },
		global: {
			plugins: [pinia],
			mocks: { $route: { name: 'timeline', params: { type: 'home' } } },
			stubs: {
				TimelinePost: TimelinePostStub,
				TimelineAvatar: TimelineAvatarStub,
				UserEntry: UserEntryStub,
				RouterLink: RouterLinkStub,
			},
		},
	})
	return { wrapper, timelineStore }
}

describe('TimelineEntry', () => {
	describe('a plain post', () => {
		it('renders the avatar and the post itself without any header', () => {
			const { wrapper } = mountEntry(post)

			expect(wrapper.element.tagName).toBe('LI')
			expect(wrapper.classes()).toContain('timeline-entry')
			expect(wrapper.classes()).not.toContain('with-header')
			expect(wrapper.classes()).not.toContain('notification')
			expect(wrapper.find('.boost').exists()).toBe(false)
			expect(wrapper.find('.notification__header').exists()).toBe(false)

			expect(wrapper.findComponent(TimelineAvatarStub).props('item')).toEqual(post)
			expect(wrapper.findComponent(TimelinePostStub).props()).toEqual({ item: post, type: 'home' })
			expect(wrapper.findComponent(UserEntryStub).exists()).toBe(false)
		})

		it('can be rendered as another element', () => {
			const { wrapper } = mountEntry(post, { element: 'div' })
			expect(wrapper.element.tagName).toBe('DIV')
		})
	})

	/**
	 * Every entry in the list has to have the same edges. A notification is a
	 * card because it is a thing that happened; a boost and a plain post are
	 * both just a post, so neither takes one.
	 */
	it('gives a card to notifications and to nothing else', () => {
		expect(mountEntry(post).wrapper.classes()).not.toContain('with-header')
		expect(mountEntry(boost).wrapper.classes()).not.toContain('with-header')
		expect(mountEntry(notification('favourite'), { type: 'notifications' }).wrapper.classes())
			.toContain('with-header')
	})

	describe('a boost', () => {
		it('shows who boosted and links to their profile', () => {
			const { wrapper } = mountEntry(boost)

			// a line above the post, not a box around it: a card here nested a
			// card and left boosted posts narrower than every post around them
			expect(wrapper.classes()).not.toContain('with-header')
			const header = wrapper.find('.boost')
			expect(header.find('.repeat-icon').exists()).toBe(true)
			expect(header.findComponent(ActorAvatar).props('actor')).toEqual(bob)
			expect(header.find('.post-author').text()).toBe('Bob')
			expect(header.text()).toMatch(/Bob\s+boosted$/)
			expect(header.findComponent(RouterLinkStub).props('to')).toEqual({ name: 'profile', params: { account: 'bob@remote.example' } })
		})

		it('previews the booster when their avatar is hovered', () => {
			// the small avatars were plain <img>: the reader could hover the
			// author of a post and see who they are, and hovering whoever
			// boosted it gave them nothing
			const { wrapper } = mountEntry(boost)

			const card = wrapper.find('.boost').findComponent(AccountHoverCard)

			expect(card.exists()).toBe(true)
			expect(card.props('handle')).toBe('bob@remote.example')
		})

		it('renders the boosted post from the store so later actions are reflected', () => {
			// the boost carries its own copy of the post; what is rendered is
			// the store's, which is the one a like or an edit changes
			const { wrapper } = mountEntry(boost)

			expect(wrapper.findComponent(TimelinePostStub).props('item')).toEqual(storedPost)
			expect(wrapper.findComponent(TimelineAvatarStub).props('item')).toEqual(storedPost)
		})
	})

	describe('a notification about a post', () => {
		it.each([
			['favourite', 'heart-icon', 'bob@remote.example liked your post'],
			['reblog', 'repeat-icon', 'bob@remote.example boosted your post'],
			['mention', 'at-icon', 'bob@remote.example mentioned you'],
			['status', 'message-outline-icon', 'bob@remote.example posted a status'],
			['update', 'message-plus-outline-icon', 'bob@remote.example edited a status'],
			['poll', 'poll-icon', 'bob@remote.example ended the poll'],
		])('describes a %s with its icon and summary', (type, iconClass, summary) => {
			const { wrapper } = mountEntry(notification(type), { type: 'notifications' })

			expect(wrapper.classes()).toContain('notification')
			expect(wrapper.classes()).toContain('with-header')
			const header = wrapper.find('.notification__header')
			expect(header.findComponent(ActorAvatar).props('actor')).toEqual(bob)
			expect(header.findAll('.material-design-icon')).toHaveLength(1)
			expect(header.find('.material-design-icon').classes()).toContain(iconClass)
			expect(header.find('.notification__summary').text()).toBe(summary)
		})

		it('shows the faces of everyone a grouped card stands for, capped', () => {
			// nine faces would push the words off the card; five and a count
			// say the same thing
			const accounts = Array.from({ length: 9 }, (_, index) => ({ ...bob, id: 'a' + index, acct: 'a' + index }))
			const { wrapper } = mountEntry(
				notification('favourite', { accounts }),
				{ type: 'notifications' },
			)

			expect(wrapper.findAll('.notification__faces > *')).toHaveLength(5)
			// and the post it is about is quoted once, not nine times
			expect(wrapper.findAllComponents(TimelinePostStub)).toHaveLength(1)
		})

		it('draws a single face for a card that stands for one thing', () => {
			const { wrapper } = mountEntry(notification('favourite'), { type: 'notifications' })

			expect(wrapper.find('.notification__faces').exists()).toBe(false)
		})

		it('marks what arrived since the reader last looked', () => {
			expect(mountEntry(notification('favourite'), { type: 'notifications', unread: true }).wrapper.classes())
				.toContain('timeline-entry--unread')
			expect(mountEntry(notification('favourite'), { type: 'notifications' }).wrapper.classes())
				.not.toContain('timeline-entry--unread')
		})

		it('says "New" on the card itself, where the badge can be answered', () => {
			// the tint behind an unread card is a pale frame around an opaque
			// post, so the word is what actually carries this
			const { wrapper } = mountEntry(notification('favourite'), { type: 'notifications', unread: true })

			expect(wrapper.find('.notification__new').text()).toBe('New')
		})

		it('says nothing on a card that was already there', () => {
			const { wrapper } = mountEntry(notification('favourite'), { type: 'notifications' })

			expect(wrapper.find('.notification__new').exists()).toBe(false)
		})

		it('previews the account a notification is about when their avatar is hovered', () => {
			const { wrapper } = mountEntry(notification('favourite'), { type: 'notifications' })

			const card = wrapper.find('.notification__header').findComponent(AccountHoverCard)

			expect(card.exists()).toBe(true)
			expect(card.props('handle')).toBe('bob@remote.example')
		})

		it('renders the post concerned without an avatar and links to it', () => {
			const { wrapper } = mountEntry(notification('favourite'), { type: 'notifications' })

			expect(wrapper.findComponent(TimelinePostStub).props()).toEqual({ item: storedPost, type: 'notifications' })
			expect(wrapper.findComponent(TimelineAvatarStub).exists()).toBe(false)
			expect(wrapper.findComponent(UserEntryStub).exists()).toBe(false)

			const link = wrapper.find('.notification__details').findComponent(RouterLinkStub)
			// the profile part of the link is the actor handle, not their display name
			expect(link.props('to')).toMatchObject({ name: 'single-post', params: { account: 'bob@remote.example', id: 'p1', type: 'single-post' } })
			expect(link.attributes('data-timestamp')).toBe('2026-09-03T10:00:00Z')
		})

		it('renders the post from the store, so a like on the card is seen on the card', async () => {
			// the notification carries its own copy of the post; the like
			// lands in the store, which is where the card has to read from
			const { wrapper, timelineStore } = mountEntry(notification('favourite'), { type: 'notifications' })

			timelineStore.likeStatus({ status: post })
			await wrapper.vm.$nextTick()

			expect(wrapper.findComponent(TimelinePostStub).props('item')).toMatchObject({ id: 'p1', favourited: true, favourites_count: 1 })
		})

		it('falls back to its own copy of a post the store does not hold', () => {
			const elsewhere = { ...post, id: 'p9', content: '<p>Only on the card</p>' }
			const { wrapper } = mountEntry(notification('favourite', { status: elsewhere }), { type: 'notifications' })

			expect(wrapper.findComponent(TimelinePostStub).props('item')).toEqual(elsewhere)
		})

		it('renders a post notification whose status has gone without crashing and without a link', () => {
			const { wrapper } = mountEntry(notification('favourite', { status: null }), { type: 'notifications' })

			const details = wrapper.find('.notification__details')
			expect(details.findComponent(RouterLinkStub).exists()).toBe(false)
			expect(details.find('.post-timestamp').attributes('data-timestamp')).toBe('2026-09-03T10:00:00Z')
			expect(wrapper.findComponent(TimelinePostStub).exists()).toBe(false)
		})
	})

	describe('a notification about an account', () => {
		it.each([
			['follow', 'account-plus-outline-icon', 'bob@remote.example started to follow you'],
			['follow_request', 'account-question-icon', 'bob@remote.example requested to follow you'],
		])('shows the %s as an account entry without a follow button', (type, iconClass, summary) => {
			const { wrapper } = mountEntry(notification(type, { status: undefined }), { type: 'notifications' })

			const header = wrapper.find('.notification__header')
			expect(header.find('.material-design-icon').classes()).toContain(iconClass)
			expect(header.find('.notification__summary').text()).toBe(summary)

			expect(wrapper.findComponent(UserEntryStub).props()).toEqual({ item: bob, displayFollowButton: false })
			expect(wrapper.findComponent(TimelinePostStub).exists()).toBe(false)
			expect(wrapper.findComponent(TimelineAvatarStub).exists()).toBe(false)

			// nothing to open, so the timestamp is not a link
			expect(wrapper.find('.notification__details').findComponent(RouterLinkStub).exists()).toBe(false)
			expect(wrapper.find('.notification__details .post-timestamp').attributes('data-timestamp')).toBe('2026-09-03T10:00:00Z')
		})
	})

	describe('an unknown notification type', () => {
		it('still shows the actor and the post, with an empty summary and no icon', () => {
			const { wrapper } = mountEntry(notification('something.new'), { type: 'notifications' })

			const header = wrapper.find('.notification__header')
			expect(header.findComponent(ActorAvatar).props('actor')).toEqual(bob)
			expect(header.find('.material-design-icon').exists()).toBe(false)
			expect(header.find('.notification__summary').text()).toBe('')
			expect(wrapper.findComponent(TimelinePostStub).props('item')).toEqual(storedPost)
		})
	})

	describe('on a phone', () => {
		afterEach(() => {
			phone.matches = false
			phone.listeners.clear()
		})

		/** a set size and no padding of its own, so it lines up with the name beside it */
		it('asks for a 40-pixel face on a wide screen', () => {
			const { wrapper } = mountEntry(post)

			expect(wrapper.findComponent(TimelineAvatarStub).props('size')).toBe(40)
		})

		it('asks for a face small enough to sit inside the card', () => {
			phone.matches = true
			const { wrapper } = mountEntry(post)

			expect(wrapper.findComponent(TimelineAvatarStub).props('size')).toBe(36)
		})

		it('follows a rotation, and stops listening when it is gone', async () => {
			const { wrapper } = mountEntry(post)
			expect(phone.listeners.size).toBe(1)

			phone.listeners.forEach((fn) => fn(true))
			await wrapper.vm.$nextTick()
			expect(wrapper.findComponent(TimelineAvatarStub).props('size')).toBe(36)

			wrapper.unmount()
			expect(phone.listeners.size).toBe(0)
		})
	})
})

describe('why For you shows a post', () => {
	it('names the reason above the post, as a link to the tag', () => {
		const { wrapper } = mountEntry({ ...post, interest: { tags: ['photography'], reason: 'followed' } }, { type: 'interests' })

		const chip = wrapper.findComponent(RouterLinkStub)
		expect(chip.classes()).toContain('interest-reason')
		expect(chip.props('to')).toEqual({ name: 'tags', params: { tag: 'photography' } })
		expect(chip.text()).toBe('Because you follow #photography')
		expect(chip.attributes('aria-label')).toBe('Why you\'re seeing this: you follow #photography')
		// before the post, not inside it
		expect(wrapper.element.firstElementChild).toBe(chip.element)
	})

	it('says a popular post is popular, with no tag to link to', () => {
		const { wrapper } = mountEntry({ ...post, interest: { tags: [], reason: 'popular' } }, { type: 'interests' })

		const chip = wrapper.find('.interest-reason')
		expect(chip.element.tagName).toBe('SPAN')
		expect(chip.classes()).toContain('interest-reason--popular')
		expect(chip.text()).toBe('Popular right now')
		expect(chip.attributes('aria-label')).toBe('Why you\'re seeing this: popular right now')
		expect(wrapper.findAllComponents(RouterLinkStub).some((link) => link.classes().includes('interest-reason'))).toBe(false)
	})

	it('draws nothing on a post the feed did not choose', () => {
		const { wrapper } = mountEntry({ ...post, interest: null })

		expect(wrapper.find('.interest-reason').exists()).toBe(false)
	})

	it('marks the entry with the post the reader sees, for the tracker', () => {
		expect(mountEntry(post).wrapper.attributes('data-status-id')).toBe('p1')
		// a boost is tracked as the post it boosts
		expect(mountEntry(boost).wrapper.attributes('data-status-id')).toBe('p1')
	})
})

describe('the stagger on the way in', () => {
	it('delays the first few entries by their place in the list', () => {
		expect(mountEntry(storedPost, { index: 2 }).wrapper.attributes('style')).toContain('--stagger-delay: 90ms')
	})

	it('is skipped for an entry that replaces one already on screen', () => {
		expect(mountEntry(storedPost, { index: 2, immediate: true }).wrapper.attributes('style') ?? '').not.toContain('--stagger-delay')
	})

	it('is read once: the delay is not put back on a rising entry', async () => {
		const { wrapper } = mountEntry(storedPost, { index: 2, immediate: true })
		await wrapper.setProps({ immediate: false })
		expect(wrapper.attributes('style') ?? '').not.toContain('--stagger-delay')
	})
})
