/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { idempotencyKeeper } from '../../../src/utils/idempotency.js'

describe('the Idempotency-Key of a post being sent', () => {
	it('stays the same when the same post is sent again', () => {
		const keeper = idempotencyKeeper()
		const first = keeper.keyFor({ status: 'hello' })
		expect(first).toMatch(/^[0-9a-f-]{36}$/)
		expect(keeper.keyFor({ status: 'hello' })).toBe(first)
	})

	it('changes when the post changes', () => {
		const keeper = idempotencyKeeper()
		const first = keeper.keyFor({ status: 'hello' })
		expect(keeper.keyFor({ status: 'hello, world' })).not.toBe(first)
	})

	it('starts afresh once the post went out', () => {
		const keeper = idempotencyKeeper()
		const first = keeper.keyFor({ status: 'hello' })
		keeper.sent()
		expect(keeper.keyFor({ status: 'hello' })).not.toBe(first)
	})
})
