/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import UserEntry from '../../../src/components/UserEntry.vue'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const NcAvatarStub = {
	name: 'NcAvatar',
	props: ['url', 'user', 'size', 'disableTooltip'],
	template: '<span class="nc-avatar-stub" />',
}
const FollowButtonStub = {
	name: 'FollowButton',
	props: ['uid'],
	template: '<button class="follow-button-stub" />',
}
const RouterLinkStub = {
	name: 'RouterLink',
	props: ['to'],
	template: '<a class="router-link-stub"><slot /></a>',
}

const local = {
	id: '1789553297940456400',
	url: 'https://cloud.example.org/users/carol',
	acct: 'carol',
	username: 'carol',
	display_name: 'Carol',
	avatar: 'https://cloud.example.org/avatar/carol/128',
	note: '<p>Local <strong>bio</strong></p>',
}
const remote = {
	// an Account's `id` is the numeric row id; its profile is `url`
	id: '1789553297940456473',
	url: 'https://remote.example/users/bob',
	acct: 'bob@remote.example',
	username: 'bob',
	display_name: 'Bob',
	avatar: 'https://remote.example/media/bob.png',
	note: '<p>Hi <script>alert(1)</script><a href="javascript:alert(2)" onclick="x()">link</a> <a href="https://remote.example/bob" target="_self" rel="opener">site</a></p>',
}

let pinia
let accountStore

function makeStore(serverData = {}) {
	pinia = createPinia()
	setActivePinia(pinia)
	accountStore = useAccountStore()
	useSettingsStore().setServerData({ public: false, cloudAddress: 'https://cloud.example.org', ...serverData })
	vi.spyOn(accountStore, 'fetchRelationship').mockResolvedValue([])

	return pinia
}

/**
 * @param {object} item the account
 * @param {object} props anything else to pass
 * @param {boolean} routed whether the app's router is installed — it is not on
 *                         the public profile section, which runs an app of its
 *                         own, and the avatar's link follows that
 * @return {object} the mounted entry
 */
function mountEntry(item, props = {}, routed = true) {
	return mount(UserEntry, {
		props: { item, ...props },
		global: {
			plugins: [pinia],
			stubs: { NcAvatar: NcAvatarStub, FollowButton: FollowButtonStub, RouterLink: RouterLinkStub },
			mocks: routed ? { $router: {} } : {},
		},
	})
}

/** The link on the name, as opposed to the one the avatar is now wrapped in. */
const nameLink = (wrapper) => wrapper.find('.user-details a')

describe('UserEntry', () => {
	beforeEach(() => {
		makeStore()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('shows the names and links a local account to its profile', () => {
		const wrapper = mountEntry(local)
		expect(wrapper.find('.post-author').text()).toBe('Carol')
		expect(wrapper.find('.user-description').text()).toBe('carol')
		expect(wrapper.find('.user-details a').attributes('href')).toBe('/index.php/u/carol')
		expect(wrapper.find('a[target="_blank"]').exists()).toBe(false)
	})

	it('links the avatar at the profile as well as the name', () => {
		// the face is the most obvious thing on the row to click
		const links = mountEntry(local).findAll('a')

		expect(links.length).toBeGreaterThanOrEqual(2)
		for (const link of links) {
			expect(link.attributes('href')).toBe('/index.php/u/carol')
		}
	})

	it('uses the server avatar for a local account', () => {
		const avatar = mountEntry(local).findComponent(NcAvatarStub)
		expect(avatar.props('user')).toBe('carol')
		expect(avatar.props('size')).toBe(32)
		expect(avatar.props('disableTooltip')).toBe(true)
		expect(avatar.props('url')).toBeUndefined()
	})

	it('uses the delivered avatar URL for a remote account and links to its full handle', () => {
		const wrapper = mountEntry(remote)
		expect(wrapper.findComponent(NcAvatarStub).props('url')).toBe('https://remote.example/media/bob.png')
		expect(wrapper.findComponent(NcAvatarStub).props('user')).toBeUndefined()
		expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({ name: 'profile', params: { account: 'bob@remote.example' } })
	})

	/** A Bluesky handle has no @ either, so the entity has to say where it lives. */
	it('marks a Bluesky account with the butterfly and treats it as remote', () => {
		const bluesky = {
			id: '1789553297940456499',
			url: 'https://bsky.app/profile/dana.bsky.social',
			acct: 'dana.bsky.social',
			username: 'dana.bsky.social',
			display_name: 'Dana',
			avatar: 'https://cdn.bsky.app/img/avatar/plain/did:plc:d/abc@jpeg',
			bluesky: { handle: 'dana.bsky.social', did: 'did:plc:d', url: 'https://bsky.app/profile/dana.bsky.social', native: true },
		}
		const wrapper = mountEntry(bluesky)

		const badge = wrapper.find('.bluesky-badge')
		expect(badge.exists()).toBe(true)
		expect(badge.attributes('title')).toBe('On Bluesky')
		expect(badge.find('svg').attributes('aria-label')).toBe('On Bluesky')
		expect(wrapper.find('.post-author').text()).toBe('Dana')
		expect(wrapper.findComponent(NcAvatarStub).props('url')).toBe(bluesky.avatar)
		expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({ name: 'profile', params: { account: 'dana.bsky.social' } })
		expect(mountEntry(local).find('.bluesky-badge').exists()).toBe(false)
	})

	it('injects the bio only after sanitising the remote HTML', () => {
		const bio = mountEntry(remote).find('.user-details p')
		expect(bio.text()).toBe('Hi link site')
		expect(bio.html()).not.toContain('<script')
		expect(bio.html()).not.toContain('javascript:')
		expect(bio.html()).not.toContain('onclick')
		const [dangerous, safe] = bio.findAll('a')
		// the javascript: target is dropped, the text is kept
		expect(dangerous.attributes('href')).toBeUndefined()
		// a real link is forced to open safely in a new tab
		expect(safe.attributes('href')).toBe('https://remote.example/bob')
		expect(safe.attributes('rel')).toBe('nofollow noopener noreferrer')
		expect(safe.attributes('target')).toBe('_blank')
	})

	it('keeps the formatting of a harmless bio', () => {
		expect(mountEntry(local).find('.user-details p').html()).toContain('<p>Local <strong>bio</strong></p>')
	})

	it('renders an empty bio paragraph when the account has no note', () => {
		expect(mountEntry({ ...local, note: undefined }).find('.user-details p').text()).toBe('')
	})

	it('asks for the relationship through the batching action, not one request per entry', () => {
		mountEntry(remote)
		// a page of twenty followers used to be twenty round-trips, because
		// the guard tested a `relationship` this component never defined
		expect(accountStore.fetchRelationship).toHaveBeenCalledWith(remote.id)
	})

	it('does not ask again for a relationship the store already knows', () => {
		accountStore.addRelationship({
			actorId: remote.id,
			data: { id: remote.id, following: true, requested: false },
		})
		mountEntry(remote)
		expect(accountStore.fetchRelationship).not.toHaveBeenCalledWith(remote.id)
	})

	it('shows the follow button by default and hides it on request', () => {
		expect(mountEntry(remote).findComponent(FollowButtonStub).props('uid')).toBe('bob@remote.example')
		expect(mountEntry(remote, { displayFollowButton: false }).findComponent(FollowButtonStub).exists()).toBe(false)
	})

	describe('on the public page', () => {
		beforeEach(() => {
			makeStore({ public: true })
		})

		it('links straight to the remote profile in a new tab instead of the app route', () => {
			// the numeric id was linked to instead, which 404s under the
			// followers route it is rendered on
			const wrapper = mountEntry(remote, {}, false)
			const link = nameLink(wrapper)
			expect(wrapper.findComponent(RouterLinkStub).exists()).toBe(false)
			expect(link.attributes('target')).toBe('_blank')
			expect(link.attributes('href')).toBe('https://remote.example/users/bob')
			expect(link.attributes('href')).not.toBe(remote.id)
			expect(link.attributes('rel')).toBe('noreferrer')
			expect(link.find('.post-author').text()).toBe('Bob')
		})

		it('sends the avatar to the same place, since there is no router to route with', () => {
			const wrapper = mountEntry(remote, {}, false)
			const avatarLink = wrapper.find('.user-avatar a')

			expect(avatarLink.attributes('href')).toBe('https://remote.example/users/bob')
			expect(avatarLink.attributes('rel')).toContain('noopener')
		})

		it('does not ask the server for a relationship', () => {
			mountEntry(remote)
			expect(accountStore.fetchRelationship).not.toHaveBeenCalled()
		})
	})
})
