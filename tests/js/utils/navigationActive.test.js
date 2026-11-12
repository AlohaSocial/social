/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { isEntryActive, MY_FEED_COVERS } from '../../../src/utils/navigationActive.js'

const feed = { to: { name: 'timeline' }, covers: MY_FEED_COVERS }
const activities = { to: { name: 'timeline', params: { type: 'notifications' } } }
const profile = { to: { name: 'profile', params: { account: 'alice' } } }

describe('isEntryActive', () => {
	it('lights My Feed for each of its scopes and nothing else on the page', () => {
		for (const type of ['', 'timeline', 'federated']) {
			expect(isEntryActive({ name: 'timeline', params: { type } }, feed)).toBe(true)
		}
		expect(isEntryActive({ name: 'timeline', params: { type: 'notifications' } }, feed)).toBe(false)
	})

	it('compares params value by value, an empty one as absent', () => {
		expect(isEntryActive({ name: 'timeline', params: { type: 'notifications' } }, activities)).toBe(true)
		expect(isEntryActive({ name: 'timeline', params: { type: 'direct' } }, activities)).toBe(false)
		expect(isEntryActive({ name: 'discover', params: { type: '' } }, { to: { name: 'discover' } })).toBe(true)
	})

	it('keeps an entry lit on its dot-namespaced children', () => {
		expect(isEntryActive({ name: 'profile.followers', params: { account: 'alice' } }, profile)).toBe(true)
		expect(isEntryActive({ name: 'profiles', params: { account: 'alice' } }, profile)).toBe(false)
	})

	it('never lights an entry that leaves the app', () => {
		expect(isEntryActive({ name: 'timeline', params: {} }, { to: '/apps/files/' })).toBe(false)
	})
})
