/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import pinia, {
	useAccountStore,
	useErrorsStore,
	useNotificationsStore,
	useSettingsStore,
	useTimelineStore,
} from '../../../src/store/index.js'

describe('root store', () => {
	beforeEach(() => {
		setActivePinia(pinia)
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('is the one Pinia the entry points install', () => {
		expect(typeof pinia.install).toBe('function')
	})

	it('registers a store per id the first time it is asked for', () => {
		useTimelineStore()
		useAccountStore()
		useSettingsStore()
		useErrorsStore()
		useNotificationsStore()

		expect(Object.keys(pinia.state.value).sort())
			.toEqual(['account', 'errors', 'notifications', 'settings', 'timeline'])
	})

	it('exposes the same surface the modules did', () => {
		expect(useTimelineStore().getTimeline).toEqual([])
		expect(typeof useAccountStore().getAccount).toBe('function')
		expect(useSettingsStore().getServerData).toEqual({})
		expect(useErrorsStore().hasErrors).toBe(false)
	})

	it('lets one store call another', async () => {
		const errors = useErrorsStore()
		vi.spyOn(errors, 'addAppError')

		// the account store reports a lookup failure through the errors store
		await useAccountStore().fetchAccountInfo('bob@remote.tld')

		expect(errors.addAppError).toHaveBeenCalled()
	})

	it('gives every Pinia its own state, so one test cannot leak into the next', () => {
		useTimelineStore().setSearchQuery('fediverse')
		expect(useTimelineStore().getSearchQuery).toBe('fediverse')

		setActivePinia(createPinia())

		// the four modules that shared one object literal used to answer
		// 'fediverse' here, which was invisible with a single store
		expect(useTimelineStore().getSearchQuery).toBe('')
	})
})
