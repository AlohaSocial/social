/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { h } from 'vue'

import { useAccount } from '../../../src/composables/useAccount.js'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'

const alice = { id: '11', acct: 'alice', username: 'alice', display_name: 'Alice', url: 'https://cloud.example.org/@alice' }
const bob = { id: '22', acct: 'bob@remote.tld', username: 'bob', display_name: 'Bob', url: 'https://remote.tld/@bob' }

const Probe = {
	props: { uid: { type: String, default: '' } },
	setup(props) {
		return useAccount(() => props.uid)
	},
	render: () => h('div'),
}

describe('useAccount', () => {
	let pinia
	let accountStore
	let settingsStore
	let wrapper

	const mountWith = (uid) => {
		wrapper = mount(Probe, { props: { uid }, global: { plugins: [pinia] } })
		return wrapper.vm
	}

	beforeEach(() => {
		vi.spyOn(console, 'debug').mockImplementation(() => {})
		pinia = createPinia()
		setActivePinia(pinia)
		accountStore = useAccountStore()
		settingsStore = useSettingsStore()
		settingsStore.setServerData({ cloudAddress: 'https://cloud.example.org' })
	})

	afterEach(() => {
		wrapper?.unmount()
		vi.restoreAllMocks()
	})

	it('profileAccount qualifies a local user id with the cloud hostname', () => {
		expect(mountWith('alice').profileAccount).toBe('alice@cloud.example.org')
	})

	it('profileAccount keeps a remote handle as it is', () => {
		expect(mountWith('bob@remote.tld').profileAccount).toBe('bob@remote.tld')
	})

	it('profileAccount keeps a Bluesky handle bare once the store knows it, and isLocal is false', async () => {
		const dana = { id: '33', acct: 'dana.bsky.social', username: 'dana.bsky.social', url: 'https://bsky.app/profile/dana.bsky.social', bluesky: { handle: 'dana.bsky.social', did: 'did:plc:d', url: 'https://bsky.app/profile/dana.bsky.social', native: true } }
		const vm = mountWith('dana.bsky.social')
		expect(vm.profileAccount).toBe('dana.bsky.social@cloud.example.org')

		accountStore.addAccount({ actorId: dana.url, data: dana })
		await wrapper.vm.$nextTick()

		expect(vm.profileAccount).toBe('dana.bsky.social')
		expect(vm.accountInfo).toEqual(dana)
		expect(vm.isLocal).toBe(false)
	})

	it('profileAccount is empty without a uid', () => {
		expect(mountWith('').profileAccount).toBe('')
	})

	// the two were a store getter each, and the second one's comment called it
	// "somewhat duplicate with accountInfo(), but needed (for some reason)"
	it('accountInfo and accountLoaded are the same lookup, asked once', async () => {
		const vm = mountWith('alice')
		expect(vm.accountLoaded).toBe(false)
		expect(vm.accountInfo).toBeUndefined()

		accountStore.addAccount({ actorId: alice.url, data: alice })
		await wrapper.vm.$nextTick()

		expect(vm.accountLoaded).toBe(true)
		expect(vm.accountInfo).toEqual(alice)
	})

	it('isLocal is true for accounts of this server and false for remote ones', () => {
		accountStore.addAccount({ actorId: alice.url, data: alice })
		accountStore.addAccount({ actorId: bob.url, data: bob })

		expect(mountWith('alice').isLocal).toBe(true)
		wrapper.unmount()
		expect(mountWith('bob@remote.tld').isLocal).toBe(false)
	})

	it('isLocal is falsy while the account is not loaded', () => {
		expect(mountWith('alice').isLocal).toBeFalsy()
	})

	it('relationship looks up the relationship by the account id once both are loaded', async () => {
		accountStore.addAccount({ actorId: bob.url, data: bob })
		const vm = mountWith('bob@remote.tld')
		expect(vm.relationship).toBeUndefined()

		const relationship = { id: bob.id, following: true, requested: false }
		accountStore.addRelationship({ actorId: bob.id, data: relationship })
		await wrapper.vm.$nextTick()

		expect(vm.relationship).toEqual(relationship)
	})

	it('relationship is falsy when the account itself is unknown', () => {
		accountStore.addRelationship({ actorId: bob.id, data: { id: bob.id, following: true } })

		expect(mountWith('bob@remote.tld').relationship).toBeFalsy()
	})
})
