/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/* global setInitialState */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'
import OAuth2Authorize from '../../../src/views/OAuth2Authorize.vue'

function setState(key, value) {
	setInitialState('social', key, value)
	window._nc_initial_state?.clear()
}

describe('OAuth2Authorize', () => {
	let wrapper

	beforeEach(() => {
		setState('appName', 'Tusky')
		setState('appWebsite', '')
		setState('account', null)
		setState('scopes', [])
		setState('protocol', '')
		wrapper = mount(OAuth2Authorize)
	})

	it('names the third party application asking for access', () => {
		expect(wrapper.find('h1').text()).toBe('Authorization required')
		const text = wrapper.find('.oauth__lead').text()
		expect(text).toContain('Tusky would like permission to access your account. It is a third party application.')
		expect(wrapper.text()).toContain('If you do not trust it, then you should not authorize it.')
	})

	/**
	 * An application registers no logo, so the mark above the heading is the
	 * letter its name starts with.
	 */
	it('marks the request with the letter the application starts with', () => {
		expect(wrapper.find('.oauth__seal--app').text()).toBe('T')
	})

	it('links the website the application registered, by host', () => {
		setState('appWebsite', 'https://tusky.app/download?ref=nc')
		const link = mount(OAuth2Authorize).find('.oauth__website')

		expect(link.text()).toContain('tusky.app')
		expect(link.attributes('href')).toBe('https://tusky.app/download?ref=nc')
		expect(link.attributes('rel')).toContain('noopener')
	})

	/** A `javascript:` website is a link nobody should be offered. */
	it('shows no website link for a scheme a browser should not follow', () => {
		setState('appWebsite', 'javascript:alert(1)')

		expect(mount(OAuth2Authorize).find('.oauth__website').exists()).toBe(false)
	})

	it('shows no website link when the application registered none', () => {
		expect(wrapper.find('.oauth__website').exists()).toBe(false)
	})

	/**
	 * Which account is being handed over is half of what is being asked, and
	 * the application's name alone does not answer it.
	 */
	it('names the account the code would be granted for', () => {
		setState('account', { uid: 'aiko', displayName: 'Aiko Tanaka', handle: '@aiko@example.com' })
		const account = mount(OAuth2Authorize).find('.account')

		expect(account.text()).toContain('Aiko Tanaka')
		expect(account.text()).toContain('@aiko@example.com')
	})

	it('leaves the account row out when the server sent no account', () => {
		expect(wrapper.find('.account').exists()).toBe(false)
	})

	it('explains every scope it knows, and shows the rest as asked for', () => {
		setState('scopes', ['read:statuses', 'write:media', 'read:invented'])
		const items = mount(OAuth2Authorize).findAll('.scopes__item')

		expect(items).toHaveLength(3)
		expect(items[0].text()).toContain('Read your posts and your timelines')
		expect(items[0].text()).toContain('read:statuses')
		expect(items[1].text()).toContain('Upload files as you')
		expect(items[2].text()).toContain('read:invented')
	})

	it('explains the stories scopes as 24-hour shorts, keeping the scope names', () => {
		setState('scopes', ['read:stories', 'write:stories'])
		const items = mount(OAuth2Authorize).findAll('.scopes__item')

		expect(items[0].text()).toContain('See your 24-hour shorts')
		expect(items[0].text()).toContain('read:stories')
		expect(items[1].text()).toContain('Publish and delete 24-hour shorts as you')
	})

	/**
	 * A scope that only reads and a scope that acts as you are not the same
	 * grant, and a list where every line looks alike hides that.
	 */
	it('marks the scopes that let the application act, not only read', () => {
		setState('scopes', ['read:statuses', 'write:statuses', 'follow', 'read'])
		const items = mount(OAuth2Authorize).findAll('.scopes__icon')

		expect(items.map((icon) => icon.classes('scopes__icon--write')))
			.toEqual([false, true, true, false])
	})

	it('names where the code is about to be sent', () => {
		setState('redirectUri', 'https://ivory.app/oauth/callback')

		expect(mount(OAuth2Authorize).find('.target').text())
			.toContain('The authorization code will be sent to ivory.app.')
	})

	it('says so when the code is shown rather than sent', () => {
		setState('redirectUri', 'urn:ietf:wg:oauth:2.0:oob')

		expect(mount(OAuth2Authorize).find('.target').text())
			.toContain('The authorization code will be shown to you')
	})

	it('posts the decision back to the authorize endpoint that rendered the page', () => {
		const form = wrapper.find('form.guest-box')
		expect(form.attributes('method')).toBe('post')
		expect(form.attributes('action')).toBeUndefined()
	})

	it('sends the CSRF request token as a hidden field', () => {
		const token = wrapper.find('form input[name="requesttoken"]')
		expect(token.attributes('type')).toBe('hidden')
		expect(token.element.value).toBe('test-request-token')
	})

	it('submits the form through the authorize button', () => {
		const submit = wrapper.find('form button[type="submit"]')
		expect(submit.text()).toBe('Authorize')
		expect(wrapper.findAll('form button[type="submit"]')).toHaveLength(1)
	})

	it('lets the user deny by leaving for the app home page', () => {
		const deny = wrapper.find('form .button-row a')
		expect(deny.text()).toBe('Deny')
		expect(deny.attributes('href')).toBe('/index.php/apps/social/')
	})

	/**
	 * Where refusing sends the browser is the server's decision: it hands over
	 * the client's registered URI with `error=access_denied`, or its own page
	 * for a URI it will not link. The view follows it as it is, which is why
	 * the filtering has to happen before it gets here.
	 */
	it('sends a refusal where the server said to', () => {
		setState('denyUrl', 'tusky://oauth?error=access_denied&state=s1')
		const deny = mount(OAuth2Authorize).find('form .button-row a')

		expect(deny.text()).toBe('Deny')
		expect(deny.attributes('href')).toBe('tusky://oauth?error=access_denied&state=s1')
	})

	it('picks up a different app name from the initial state', () => {
		setState('appName', 'Ivory')
		expect(mount(OAuth2Authorize).find('p').text()).toContain('Ivory would like permission')
	})

	describe('a Bluesky app', () => {
		const CLIENT_ID = 'https://bsky.app/oauth-client-metadata.json'

		beforeEach(() => {
			setState('protocol', 'atproto')
			setState('appName', 'Bluesky')
			setState('appWebsite', CLIENT_ID)
		})

		it('explains the atproto scopes, and marks the one that acts as you', () => {
			setState('scopes', ['atproto', 'transition:generic', 'transition:email'])
			const view = mount(OAuth2Authorize)
			const items = view.findAll('.scopes__item')

			expect(items).toHaveLength(3)
			expect(items[0].text()).toContain('Know which account you are')
			expect(items[1].text()).toContain('Post, like, follow, upload and read as you, everywhere on Bluesky')
			expect(items[2].text()).toContain('See your e-mail address')
			expect(view.findAll('.scopes__icon').map((icon) => icon.classes('scopes__icon--write')))
				.toEqual([false, true, false])
		})

		/** The client_id is what the app is known by; its name is not checked. */
		it('shows the client_id as plain text, with a note, and no link', () => {
			const view = mount(OAuth2Authorize)

			expect(view.find('.oauth__client-id').text()).toBe(CLIENT_ID)
			expect(view.find('.oauth__client-id').element.tagName).not.toBe('A')
			expect(view.find('.oauth__client-note').text())
				.toBe('This address is what the app is known by; its name is not checked.')
			expect(view.find('.oauth__website').exists()).toBe(false)
			expect(view.find(`a[href="${CLIENT_ID}"]`).exists()).toBe(false)
		})
	})

	it('shows neither the client_id nor the note for a Mastodon app', () => {
		setState('appWebsite', 'https://tusky.app/')
		const view = mount(OAuth2Authorize)

		expect(view.find('.oauth__client-id').exists()).toBe(false)
		expect(view.text()).not.toContain('its name is not checked')
		expect(view.find('.oauth__website').text()).toContain('tusky.app')
	})

	describe('after the code has been granted', () => {
		beforeEach(() => {
			setState('appName', 'Tusky')
			setState('code', 'abc123def456')
			wrapper = mount(OAuth2Authorize)
		})

		/**
		 * The out-of-band flow used to answer the consent form with a JSON
		 * body, so a browser drew `{"code":"..."}` on a blank document one
		 * click after being promised the code would be shown. That reads as
		 * the button having done nothing.
		 */
		it('shows the code instead of the consent form', () => {
			expect(wrapper.find('form').exists()).toBe(false)
			expect(wrapper.find('h1').text()).toBe('Authorized')
			expect(wrapper.find('.code').element.value).toBe('abc123def456')
		})

		it('says what to do with it, and names the application', () => {
			expect(wrapper.text()).toContain('Paste this code into Tusky to finish signing in.')
			expect(wrapper.text()).toContain('It can be used once')
		})

		it('offers to copy it', async () => {
			const copied = []
			Object.defineProperty(navigator, 'clipboard', {
				value: {
					writeText: (text) => {
						copied.push(text)

						return Promise.resolve()
					},
				},
				configurable: true,
			})

			await wrapper.find('button').trigger('click')
			await wrapper.vm.$nextTick()

			expect(copied).toEqual(['abc123def456'])
			expect(wrapper.text()).toContain('Copied')
		})

		/**
		 * No clipboard permission, or a page served over plain HTTP. The code
		 * is on screen and selectable either way, so the failure must not be
		 * silent breakage.
		 */
		it('falls back to selecting the code when the clipboard refuses', async () => {
			Object.defineProperty(navigator, 'clipboard', {
				value: { writeText: () => Promise.reject(new Error('denied')) },
				configurable: true,
			})
			let selected = false
			wrapper.vm.$refs.code.select = () => {
				selected = true
			}

			await wrapper.find('button').trigger('click')
			await wrapper.vm.$nextTick()

			expect(selected).toBe(true)
			expect(wrapper.text()).not.toContain('Copied')
		})
	})
})
