/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { starterPackRoute, starterPackUrl } from './starterPack.js'

describe('starterPackRoute', () => {
	it('opens a bsky.app starter pack here, and reads it back', () => {
		const route = starterPackRoute('https://bsky.app/starter-pack/bob.test/3kstart?utm=x')

		expect(route).toEqual({ name: 'starter-pack', params: { actor: 'bob.test', rkey: '3kstart' } })
		expect(starterPackUrl(route.params)).toBe('https://bsky.app/starter-pack/bob.test/3kstart')
	})

	it('leaves every other address alone', () => {
		expect(starterPackRoute('https://bsky.app/profile/bob.test/feed/cats')).toBeNull()
		expect(starterPackRoute('https://evil.example/starter-pack/a/b')).toBeNull()
		expect(starterPackRoute(undefined)).toBeNull()
	})
})
