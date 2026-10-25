/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { uuid } from '../../../src/utils/uuid.js'

describe('UUID generation', () => {
	afterEach(() => vi.unstubAllGlobals())

	it('uses the platform UUID generator when available', () => {
		const randomUUID = vi.fn(() => '9d4e3f8e-bde7-4ab6-91d3-0ad990ff63a5')
		vi.stubGlobal('crypto', { randomUUID })

		expect(uuid()).toBe('9d4e3f8e-bde7-4ab6-91d3-0ad990ff63a5')
		expect(randomUUID).toHaveBeenCalledOnce()
	})

	it('builds a UUID v4 from secure random bytes when needed', () => {
		const getRandomValues = vi.fn((bytes) => {
			bytes.fill(0)
			return bytes
		})
		vi.stubGlobal('crypto', { getRandomValues })

		expect(uuid()).toBe('00000000-0000-4000-8000-000000000000')
		expect(getRandomValues).toHaveBeenCalledOnce()
	})

	it('fails closed when secure randomness is unavailable', () => {
		vi.stubGlobal('crypto', undefined)

		expect(() => uuid()).toThrow('Secure random values are unavailable')
	})
})
