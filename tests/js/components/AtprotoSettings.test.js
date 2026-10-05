/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import AtprotoSettings from '../../../src/components/AtprotoSettings.vue'

describe('AtprotoSettings', () => {
	it('uses a literal @ handle for the public Bluesky profile URL', () => {
		const url = AtprotoSettings.methods.profileUrl.call({
			status: { account: { handle: 'bob.example' } },
		})

		expect(url).toBe('/index.php/apps/social/@bob.example')
		expect(url).not.toContain('atzeichen')
		expect(url).not.toContain('/atproto/')
	})
})
