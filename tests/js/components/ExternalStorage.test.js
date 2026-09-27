/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it } from 'vitest'
import ExternalStorage from '../../../src/components/ExternalStorage.vue'
import { useSettingsStore } from '../../../src/store/settings.js'

function card(externalMedia) {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData({ externalMedia })

	return mount(ExternalStorage, { global: { plugins: [pinia] } })
}

describe('an external user\'s storage', () => {
	it('shows how much of the quota is used', () => {
		const wrapper = card({ quota: 100, used: 25 * 1048576 })

		expect(wrapper.vm.percent).toBe(25)
		expect(wrapper.text()).toContain('25 MiB of 100 MiB used')
	})

	it('never draws past full', () => {
		expect(card({ quota: 1, used: 5 * 1048576 }).vm.percent).toBe(100)
	})

	it('says so when there is no limit', () => {
		expect(card({ quota: 0, used: 2048 }).text()).toContain('This server sets no limit on your uploads.')
	})
})
