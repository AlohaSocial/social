/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useSettingsStore } from '../store/settings.js'
import { mayHave, setHint } from './startupHints.js'

describe('startup hints', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('asks wherever the page said nothing', () => {
		useSettingsStore().setServerData({ public: false })

		expect(mayHave('has_stories')).toBe(true)
	})

	it('skips only what the page said is certainly empty', () => {
		useSettingsStore().setServerData({ public: false, startup: { has_stories: false, has_teams: true } })

		expect(mayHave('has_stories')).toBe(false)
		expect(mayHave('has_teams')).toBe(true)
		expect(mayHave('has_announcements')).toBe(true)
	})

	it('follows what changed in the session', () => {
		useSettingsStore().setServerData({ public: false, startup: { recap_enabled: false } })

		setHint('recap_enabled', true)

		expect(mayHave('recap_enabled')).toBe(true)
	})

	it('has nothing to correct on a page that carried no hints', () => {
		useSettingsStore().setServerData({ public: true })

		setHint('recap_enabled', false)

		expect(mayHave('recap_enabled')).toBe(true)
	})
})
