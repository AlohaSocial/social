/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import FollowButton from '../../../src/components/FollowButton.vue'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'
import logger from '../../../src/services/logger.js'

vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

// @nextcloud/auth reads the current user from <head> once and caches it.
vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const bob = { id: 'https://remote.example/users/bob', url: 'https://remote.example/users/bob', acct: 'bob@remote.example', username: 'bob', display_name: 'Bob' }
const carol = { id: 'https://cloud.example.org/users/carol', url: 'https://cloud.example.org/users/carol', acct: 'carol', username: 'carol', display_name: 'Carol' }

let pinia
let accountStore

function makeStore(serverData = {}) {
	pinia = createPinia()
	setActivePinia(pinia)
	accountStore = useAccountStore()
	useSettingsStore().setServerData({ public: false, cloudAddress: 'https://cloud.example.org', ...serverData })
	accountStore.addAccount({ actorId: bob.url, data: bob })
	accountStore.addAccount({ actorId: carol.url, data: carol })

	return pinia
}

function setRelationship(target, data = {}) {
	return accountStore.addRelationship({
		actorId: target.id,
		data: { id: target.id, following: false, requested: false, ...data },
	})
}

/**
 * Watches both halves of the follow, which used to be one `dispatch` spy.
 *
 * @param {Function} install applies the behaviour to each spy
 * @return {{follow: object, unfollow: object}} the two spies
 */
function spyOnFollows(install = (spy) => spy.mockResolvedValue(undefined)) {
	const follow = vi.spyOn(accountStore, 'followAccount')
	const unfollow = vi.spyOn(accountStore, 'unfollowAccount')
	install(follow)
	install(unfollow)

	return { follow, unfollow }
}

/**
 * NcDialog teleports into <body> and renders its buttons itself, which vitest
 * cannot drive; the stub renders them where the component is so the
 * confirmation can be clicked.
 */
const NcDialogStub = {
	name: 'NcDialog',
	props: ['open', 'name', 'buttons'],
	emits: ['update:open'],
	template: `<div v-if="open" class="dialog-stub">
		<span class="dialog-name">{{ name }}</span>
		<button v-for="(button, index) in buttons"
			:key="index"
			:class="'dialog-button dialog-button--' + index"
			@click="button.callback()">{{ button.label }}</button>
	</div>`,
}

function mountButton(uid = bob.acct, errorHandler) {
	return mount(FollowButton, {
		props: { uid },
		global: {
			plugins: [pinia],
			config: { errorHandler },
			stubs: { NcDialog: NcDialogStub },
		},
	})
}

const buttonTexts = (wrapper) => wrapper.findAll('button').map((button) => button.text())

describe('FollowButton', () => {
	beforeEach(() => {
		makeStore()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('renders nothing until the relationship with the account is known', () => {
		expect(mountButton().find('button').exists()).toBe(false)
	})

	it('renders nothing for unauthenticated visitors of the public page', () => {
		makeStore({ public: true })
		setRelationship(bob)
		expect(mountButton().find('button').exists()).toBe(false)
	})

	it('offers to follow an account the user does not follow yet', () => {
		setRelationship(bob)
		const wrapper = mountButton()
		expect(buttonTexts(wrapper)).toEqual(['Follow'])
		expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
	})

	it('offers unfollowing through one real button, reachable without a pointer', async () => {
		setRelationship(bob, { following: true })
		const wrapper = mountButton()

		// one control, not a hover swap of a label and a display:none button
		expect(buttonTexts(wrapper)).toEqual(['Following'])
		const button = wrapper.find('button')
		expect(button.attributes('aria-hidden')).toBeUndefined()
		expect(button.attributes('tabindex')).toBeUndefined()
		expect(button.attributes('aria-label')).toBe('Unfollow bob@remote.example')

		// the keyboard reaches it and it says what pressing it will do
		await button.trigger('focus')
		expect(button.text()).toBe('Unfollow')
		await button.trigger('blur')
		expect(button.text()).toBe('Following')
	})

	it('does not hide the unfollow control from the tab order in CSS', () => {
		// `display: none` until :hover was what locked out keyboards and
		// every touch device; the styles must not put it back
		const source = readFileSync(resolve(process.cwd(), 'src/components/FollowButton.vue'), 'utf8')
		const styles = source.slice(source.indexOf('<style'))
		expect(styles).not.toMatch(/display:\s*none/)
		expect(styles).not.toMatch(/:hover/)
	})

	it('asks before unfollowing', async () => {
		setRelationship(bob, { following: true })
		const { unfollow } = spyOnFollows()
		const wrapper = mountButton()

		await wrapper.find('button').trigger('click')
		expect(unfollow).not.toHaveBeenCalled()
		expect(wrapper.find('.dialog-stub').exists()).toBe(true)
		expect(wrapper.find('.dialog-name').text()).toBe('Unfollow bob@remote.example?')

		// cancelling leaves the follow alone
		await wrapper.find('.dialog-button--0').trigger('click')
		expect(unfollow).not.toHaveBeenCalled()
		expect(wrapper.find('.dialog-stub').exists()).toBe(false)
	})

	it('shows a disabled "Requested" state while a follow request is pending', () => {
		setRelationship(bob, { requested: true })
		const button = mountButton().find('button')
		expect(button.text()).toBe('Requested')
		expect(button.attributes('disabled')).toBeDefined()
	})

	it('calls followAccount with both handles and blocks the button until it settles', async () => {
		setRelationship(bob)
		let settle
		const { follow } = spyOnFollows((spy) => spy.mockImplementation(() => new Promise((resolve) => {
			settle = resolve
		})))
		const wrapper = mountButton()

		await wrapper.find('button').trigger('click')
		expect(follow).toHaveBeenCalledWith({
			accountToFollow: 'bob@remote.example',
		})
		expect(wrapper.find('button').attributes('disabled')).toBeDefined()

		settle()
		await flushPromises()
		expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
	})

	it('calls unfollowAccount once the confirmation is accepted', async () => {
		setRelationship(bob, { following: true })
		const { follow, unfollow } = spyOnFollows()
		const wrapper = mountButton()

		await wrapper.find('button').trigger('click')
		await wrapper.find('.dialog-button--1').trigger('click')
		await flushPromises()
		expect(unfollow).toHaveBeenCalledWith({
			accountToUnfollow: 'bob@remote.example',
		})
		expect(unfollow).toHaveBeenCalledTimes(1)
		expect(follow).not.toHaveBeenCalled()
	})

	it('re-enables the button on a follow that could not be carried out, and keeps the rejection in', async () => {
		setRelationship(bob)
		spyOnFollows((spy) => spy.mockRejectedValue(new Error('status -1')))
		const errorHandler = vi.fn()
		const wrapper = mountButton(bob.acct, errorHandler)

		await wrapper.find('button').trigger('click')
		await flushPromises()

		// the await had a `finally` and no `catch`, so a refusal left the
		// component as an unhandled rejection on its way out
		expect(errorHandler).not.toHaveBeenCalled()
		expect(logger.error).toHaveBeenCalledWith('Failed to follow an account', { error: expect.any(Error) })
		expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
	})

	it('completes a bare local uid with the instance hostname', async () => {
		setRelationship(carol)
		const { follow } = spyOnFollows()
		await mountButton('carol').find('button').trigger('click')
		expect(follow).toHaveBeenCalledWith({
			accountToFollow: 'carol@cloud.example.org',
		})
	})

	it('celebrates a follow the server took, with the ring the like button throws', async () => {
		setRelationship(bob)
		spyOnFollows((spy) => spy.mockImplementation(async () => {
			// what the store does once the server has answered: the
			// relationship is read back rather than assumed
			setRelationship(bob, { following: true })
			return { data: {} }
		}))
		const wrapper = mountButton()

		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(buttonTexts(wrapper)).toEqual(['Following'])
		expect(wrapper.find('button').classes()).toContain('follow-button--confirmed')
		expect(wrapper.find('.follow-button__burst').exists()).toBe(true)
		expect(wrapper.find('.follow-button__burst').attributes('aria-hidden')).toBe('true')
	})

	it('takes the optimistic label back when the server would not have the follow', async () => {
		setRelationship(bob)
		let settle
		spyOnFollows((spy) => spy.mockImplementation(() => new Promise((resolve) => {
			settle = resolve
		})))
		const wrapper = mountButton()

		await wrapper.find('button').trigger('click')
		// the label is ahead of the server while the request is in flight
		expect(buttonTexts(wrapper)).toEqual(['Following'])
		expect(wrapper.find('button').classes()).toContain('follow-button--pending')

		// the store reports the refusal itself and commits nothing, which is
		// the only signal the button gets
		settle(undefined)
		await flushPromises()

		expect(buttonTexts(wrapper)).toEqual(['Follow'])
		expect(wrapper.find('button').classes()).toContain('follow-button--refused')
		expect(wrapper.find('button').classes()).not.toContain('follow-button--pending')
		expect(wrapper.find('.follow-button__burst').exists()).toBe(false)
	})

	it('takes the optimistic label back when the follow is rejected outright', async () => {
		setRelationship(bob)
		spyOnFollows((spy) => spy.mockRejectedValue(new Error('status -1')))
		const errorHandler = vi.fn()
		const wrapper = mountButton(bob.acct, errorHandler)

		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(buttonTexts(wrapper)).toEqual(['Follow'])
		expect(wrapper.find('button').classes()).toContain('follow-button--refused')
	})

	it('says so on the button when an unfollow does not take', async () => {
		setRelationship(bob, { following: true })
		// the store swallows the failure and leaves the relationship alone
		spyOnFollows()
		const wrapper = mountButton()

		await wrapper.find('button').trigger('click')
		await wrapper.find('.dialog-button--1').trigger('click')
		await flushPromises()

		expect(buttonTexts(wrapper)).toEqual(['Following'])
		expect(wrapper.find('button').classes()).toContain('follow-button--refused')
	})

	it('confirms the follow without the celebration for a reader who asked for reduced motion', async () => {
		setRelationship(bob)
		const matchMedia = vi.spyOn(window, 'matchMedia').mockReturnValue({ matches: true })
		spyOnFollows((spy) => spy.mockImplementation(async () => {
			// what the store does once the server has answered: the
			// relationship is read back rather than assumed
			setRelationship(bob, { following: true })
			return { data: {} }
		}))
		const wrapper = mountButton()

		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(matchMedia).toHaveBeenCalledWith('(prefers-reduced-motion: reduce)')
		// the state still changes, it just does not perform
		expect(buttonTexts(wrapper)).toEqual(['Following'])
		expect(wrapper.find('.follow-button__burst').exists()).toBe(false)
		expect(wrapper.find('button').classes()).not.toContain('follow-button--confirmed')
	})

	it('follows the relationship the store holds', async () => {
		setRelationship(bob)
		const wrapper = mountButton()
		expect(buttonTexts(wrapper)).toEqual(['Follow'])

		setRelationship(bob, { following: true })
		await nextTick()
		expect(buttonTexts(wrapper)).toEqual(['Following'])

		setRelationship(bob, { requested: true })
		await nextTick()
		expect(buttonTexts(wrapper)).toEqual(['Requested'])

		setRelationship(bob)
		await nextTick()
		expect(buttonTexts(wrapper)).toEqual(['Follow'])
	})
})
