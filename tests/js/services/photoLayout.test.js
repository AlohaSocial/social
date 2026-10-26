/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { photoLayout, rememberPhotoLayout } from '../../../src/services/photoLayout.js'

const { reader } = vi.hoisted(() => ({ reader: { uid: 'alice' } }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => reader }))

afterEach(() => {
	vi.restoreAllMocks()
	localStorage.clear()
	reader.uid = 'alice'
})

describe('photo feed presentation', () => {
	it('defaults to the media list and remembers a valid selection per account', () => {
		expect(photoLayout()).toBe('list')
		rememberPhotoLayout('grid')
		expect(photoLayout()).toBe('grid')
		reader.uid = 'bob'
		expect(photoLayout()).toBe('list')
	})

	it('rejects unknown values and works without browser storage', () => {
		rememberPhotoLayout('unknown')
		expect(photoLayout()).toBe('list')
		vi.spyOn(window.localStorage, 'getItem').mockImplementation(() => { throw new Error('Storage disabled') })
		vi.spyOn(window.localStorage, 'setItem').mockImplementation(() => { throw new Error('Storage disabled') })
		expect(() => rememberPhotoLayout('grid')).not.toThrow()
		expect(photoLayout()).toBe('list')
	})
})
