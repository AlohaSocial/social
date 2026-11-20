/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { isOwnProfile, profileKinds } from '../../../src/composables/useProfileKinds.js'
import { useAccountStore } from '../../../src/store/account.js'

describe('the profile tabs', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		useAccountStore().setOwnAccount({ id: '1', acct: 'alice', username: 'alice', url: 'https://cloud.example/apps/social/@alice' })
	})

	it('gives the owner Saved, Drafts and Statistics after the public tabs', () => {
		expect(profileKinds('alice').map((tab) => tab.label))
			.toEqual(['Posts', 'Photos', 'Videos', 'Tagged', 'Collections', 'Saved', 'Drafts', 'Statistics'])
		expect(profileKinds('alice').slice(-3).map((tab) => tab.to))
			.toEqual([
				{ name: 'profile.saved', params: { account: 'alice' } },
				{ name: 'profile.drafts', params: { account: 'alice' } },
				{ name: 'profile.statistics', params: { account: 'alice' } },
			])
	})

	it('shows nobody else the owner tabs', () => {
		expect(profileKinds('bob').map((tab) => tab.value)).not.toContain('saved')
		// the same name on another server is somebody else
		expect(profileKinds('alice@elsewhere.example').map((tab) => tab.value)).not.toContain('saved')
		expect(profileKinds('alice@cloud.example').map((tab) => tab.value)).toContain('saved')
		useAccountStore().setCurrentAccount('')
		expect(isOwnProfile('alice')).toBe(false)
	})
})
