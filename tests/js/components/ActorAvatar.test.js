/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount, RouterLinkStub } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AccountHoverCard from '../../../src/components/AccountHoverCard.vue'
import ActorAvatar from '../../../src/components/ActorAvatar.vue'
import { createPinia } from 'pinia'

// NcAvatar loads images asynchronously and never renders an <img> in jsdom,
// so assert on what the component hands to it instead.
const NcAvatarStub = {
	name: 'NcAvatar',
	props: ['url', 'user', 'displayName', 'size', 'disableTooltip', 'hideStatus', 'disableMenu'],
	template: '<span class="nc-avatar-stub" />',
}

function mountAvatar(props) {
	return mount(ActorAvatar, {
		props,
		global: {
			plugins: [createPinia()],
			stubs: { NcAvatar: NcAvatarStub, RouterLink: RouterLinkStub },
			// the component asks whether there is a router before it decides
			// what to wrap the avatar in
			mocks: { $router: {} },
		},
	})
}

const local = { username: 'bob', acct: 'bob', nextcloud_user: 'bob', avatar: 'https://cloud.example.org/avatar/bob/128' }
const remote = {
	username: 'bob',
	acct: 'bob@remote.example',
	url: 'https://remote.example/users/bob',
	avatar: 'https://cloud.example.org/index.php/apps/social/document/get/abcdef',
}
const AVATAR_ENDPOINT = '/index.php/apps/social/api/v1/global/actor/avatar?id='

describe('ActorAvatar', () => {
	it('draws a remote face from its thumbnail at small sizes and from the original at large ones', () => {
		const actor = { ...remote, avatar_small: 'https://cloud.example.org/index.php/apps/social/media/small' }
		expect(mountAvatar({ actor }).findComponent(NcAvatarStub).props('url')).toBe(actor.avatar_small)
		expect(mountAvatar({ actor, size: 64 }).findComponent(NcAvatarStub).props('url')).toBe(actor.avatar_small)
		expect(mountAvatar({ actor, size: 128 }).findComponent(NcAvatarStub).props('url')).toBe(remote.avatar)
	})

	it('draws a local actor as its Nextcloud user', () => {
		const avatar = mountAvatar({ actor: local }).findComponent(NcAvatarStub)
		expect(avatar.props('user')).toBe('bob')
		expect(avatar.props('displayName')).toBe('bob')
		// the actor's own avatar URL is ignored for local users, the server one wins
		expect(avatar.props('url')).toBeUndefined()
	})

	it('never shows a tooltip or user status', () => {
		const avatar = mountAvatar({ actor: local }).findComponent(NcAvatarStub)
		expect(avatar.props('disableTooltip')).toBe(true)
		expect(avatar.props('hideStatus')).toBe(true)
	})

	it('opens the profile when the avatar is clicked', () => {
		// a face is the most obvious thing on screen to click, and it did
		// nothing at all
		const wrapper = mountAvatar({ actor: remote })
		const link = wrapper.findComponent(RouterLinkStub)

		expect(link.exists()).toBe(true)
		expect(link.props('to')).toEqual({ name: 'profile', params: { account: 'bob@remote.example' } })
		expect(link.attributes('aria-label')).toContain('bob@remote.example')
	})

	it('links nothing where the avatar already sits inside one', () => {
		// two nested anchors is invalid, and the browser resolves it by
		// dropping content
		const wrapper = mountAvatar({ actor: remote, link: false })

		expect(wrapper.findComponent(RouterLinkStub).exists()).toBe(false)
		expect(wrapper.find('a').exists()).toBe(false)
	})

	it('falls back to the account\'s own address where there is no router', () => {
		// the profile section on a Nextcloud user page is a custom element with
		// an app of its own and no router in it
		const wrapper = mount(ActorAvatar, {
			props: { actor: remote },
			global: { plugins: [createPinia()], stubs: { NcAvatar: NcAvatarStub } },
		})
		const anchor = wrapper.find('a')

		expect(anchor.attributes('href')).toBe('https://remote.example/users/bob')
		expect(anchor.attributes('rel')).toContain('noopener')
	})

	it('links nowhere for an actor with no handle and no address', () => {
		const wrapper = mountAvatar({ actor: { username: 'ghost', acct: '' } })

		expect(wrapper.find('a').exists()).toBe(false)
		expect(wrapper.findComponent(RouterLinkStub).exists()).toBe(false)
	})

	it('leaves the account preview to this app, for a local actor as much as a remote one', () => {
		// NcAvatar hangs Nextcloud's own profile card off a local account's
		// avatar on hover, and it opened on top of the one this component
		// wraps every avatar in — so which card a reader got depended on
		// which instance the account was on
		expect(mountAvatar({ actor: local }).findComponent(NcAvatarStub).props('disableMenu')).toBe(true)
		expect(mountAvatar({ actor: remote }).findComponent(NcAvatarStub).props('disableMenu')).toBe(true)
	})

	it('defaults to 32px and forwards a custom size', () => {
		expect(mountAvatar({ actor: local }).findComponent(NcAvatarStub).props('size')).toBe(32)
		expect(mountAvatar({ actor: local, size: 64 }).findComponent(NcAvatarStub).props('size')).toBe(64)
	})

	it('works for a local actor without any avatar field', () => {
		const avatar = mountAvatar({ actor: { username: 'bob', acct: 'bob', nextcloud_user: 'bob' } }).findComponent(NcAvatarStub)
		expect(avatar.props('user')).toBe('bob')
		expect(avatar.props('url')).toBeUndefined()
	})

	/**
	 * The username is chosen when the account is made and need not be the
	 * user id. The face was Nextcloud's avatar of the username: somebody
	 * else's, or nobody's; and the link went to that user's profile page.
	 */
	it('draws and links a local actor by its Nextcloud user, not by its username', () => {
		const wrapper = mountAvatar({ actor: { ...local, username: 'aloha_al', acct: 'aloha_al', nextcloud_user: 'alice' } })
		const avatar = wrapper.findComponent(NcAvatarStub)

		expect(avatar.props('user')).toBe('alice')
		expect(avatar.props('displayName')).toBe('aloha_al')
		expect(wrapper.find('a').attributes('href')).toBe('/index.php/u/alice')
	})

	/** a visitor is not told the login of an account named apart from it */
	it('draws a local actor whose Nextcloud user the page was not told by the picture it came with', () => {
		const wrapper = mountAvatar({ actor: { ...local, username: 'aloha_al', acct: 'aloha_al', nextcloud_user: null, url: 'https://cloud.example.org/@aloha_al' } })
		const avatar = wrapper.findComponent(NcAvatarStub)

		expect(avatar.props('user')).toBeUndefined()
		expect(avatar.props('url')).toBe(local.avatar)
		expect(wrapper.find('a').attributes('href')).toBe('https://cloud.example.org/@aloha_al')
	})

	it('shows a remote actor with the avatar URL that was delivered with it', () => {
		const avatar = mountAvatar({ actor: remote }).findComponent(NcAvatarStub)
		expect(avatar.props('url')).toBe(remote.avatar)
		expect(avatar.props('user')).toBeUndefined()
	})

	it('resolves a remote actor without an avatar through the proxy endpoint, by its escaped ActivityPub id', () => {
		const avatar = mountAvatar({ actor: { ...remote, avatar: undefined } }).findComponent(NcAvatarStub)
		expect(avatar.props('url')).toBe(`${AVATAR_ENDPOINT}https%3A%2F%2Fremote.example%2Fusers%2Fbob`)
	})

	it('previews the actor when the avatar is hovered', () => {
		const card = mountAvatar({ actor: remote }).findComponent(AccountHoverCard)
		expect(card.props('handle')).toBe('bob@remote.example')
		expect(card.props('fallback')).toStrictEqual(remote)
		expect(card.vm.shown).toBe(false)
		expect(card.findComponent(NcAvatarStub).props('url')).toBe(remote.avatar)
	})

	it('can be asked for a plain avatar with no preview', () => {
		const wrapper = mountAvatar({ actor: remote, hoverCard: false })
		expect(wrapper.findComponent(AccountHoverCard).exists()).toBe(false)
		expect(wrapper.findComponent(NcAvatarStub).props('url')).toBe(remote.avatar)
	})

	it('shows no preview for an actor with no handle to look up', () => {
		const wrapper = mountAvatar({ actor: { username: '', acct: '' } })
		expect(wrapper.findComponent(AccountHoverCard).exists()).toBe(false)
		expect(wrapper.findComponent(NcAvatarStub).exists()).toBe(true)
	})

	it('still shows a remote actor that has neither an avatar nor an ActivityPub id', () => {
		const avatar = mountAvatar({ actor: { username: 'bob', acct: 'bob@remote.example' } }).findComponent(NcAvatarStub)
		expect(avatar.exists()).toBe(true)
		expect(avatar.props('url')).toBe(AVATAR_ENDPOINT)
	})
})
