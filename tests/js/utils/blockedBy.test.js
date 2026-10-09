/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { BLOCKED_BY, isBlockedBy } from '../../../src/utils/blockedBy.js'

describe('a refusal because the account has blocked the person', () => {
	it('is told by the follow route\'s flag or by the client API\'s reason', () => {
		expect(isBlockedBy({ response: { status: 403, data: { status: -1, error: BLOCKED_BY, blocked_by: true } } })).toBe(true)
		expect(isBlockedBy({ response: { status: 403, data: { error: 'This account has blocked you' } } })).toBe(true)
	})

	it('is not any other failure', () => {
		expect(isBlockedBy({ response: { status: 422, data: { error: 'The author of this post allows no replies' } } })).toBe(false)
		expect(isBlockedBy({ response: { status: 500, data: { status: -1, error: 'request failed' } } })).toBe(false)
		expect(isBlockedBy(new Error('Network Error'))).toBe(false)
		expect(isBlockedBy(undefined)).toBe(false)
	})
})
