/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useSettingsStore } from '../../../src/store/settings.js'

describe('settings store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSettingsStore()
	})

	it('starts with an empty serverData object', () => {
		expect(store.serverData).toEqual({})
		expect(store.getServerData).toEqual({})
	})

	it('setServerData replaces the whole server data object', () => {
		const serverData = { cloudAddress: 'https://cloud.example.org', firstrun: true, isAdmin: false, public: false, setup: false }

		store.setServerData(serverData)

		expect(store.getServerData).toEqual(serverData)

		store.setServerData({ public: true })

		expect(store.getServerData).toEqual({ public: true })
	})

	it('setServerDataEntry sets a single key from an object payload, including falsy values', () => {
		store.setServerData({ setup: true, cloudAddress: 'https://cloud.example.org' })

		store.setServerDataEntry({ key: 'setup', value: false })

		expect(store.getServerData.setup).toBe(false)
		expect(store.getServerData.cloudAddress).toBe('https://cloud.example.org')

		store.setServerDataEntry({ key: 'cloudAddress', value: 'https://social.example.org' })

		expect(store.getServerData.cloudAddress).toBe('https://social.example.org')
	})

	it('exposes the getter reactively so components see the data loaded later', () => {
		const before = store.getServerData

		store.setServerData({ firstrun: false })

		expect(before).toEqual({})
		expect(store.getServerData).toEqual({ firstrun: false })
	})

	it('says the numbers are hidden only when the page says so', () => {
		const store = useSettingsStore()
		expect(store.hidesCounts).toBe(false)

		store.setServerData({ hideCounts: true })
		expect(store.hidesCounts).toBe(true)

		store.setServerDataEntry({ key: 'hideCounts', value: false })
		expect(store.hidesCounts).toBe(false)
	})
})
