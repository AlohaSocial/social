/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { folded, matchesQuery } from '../../../src/services/settingsSearch.js'

describe('the settings search', () => {
	it('compares without case or accents', () => {
		expect(folded('Écrans À Part')).toBe('ecrans a part')
		expect(matchesQuery('benachrichtigung', 'Benachrichtigungen')).toBe(true)
		expect(matchesQuery('e', 'é')).toBe(true)
	})

	it('wants every word typed, in any order', () => {
		expect(matchesQuery('jobs cron', 'Background jobs: cron schedule')).toBe(true)
		expect(matchesQuery('jobs disk', 'Background jobs: cron schedule')).toBe(false)
	})

	it('finds everything for a query with no words in it', () => {
		expect(matchesQuery('   ', 'anything')).toBe(true)
	})
})
