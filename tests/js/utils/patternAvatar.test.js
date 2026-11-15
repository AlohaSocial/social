/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { hasPlaceholderAvatar, patternAvatarUrl, patternFor, patternSvg } from '../../../src/utils/patternAvatar.js'
import { tagHue } from '../../../src/utils/tagColour.js'

describe('the flower for an account without a picture', () => {
	it('is only for an account whose picture the server says is a placeholder', () => {
		expect(hasPlaceholderAvatar({ avatar_default: true })).toBe(true)
		expect(hasPlaceholderAvatar({ avatar_default: false })).toBe(false)
		// an older server that does not say: the picture it gave is drawn
		expect(hasPlaceholderAvatar({})).toBe(false)
		expect(hasPlaceholderAvatar(null)).toBe(false)

		expect(patternAvatarUrl({ acct: 'alice', avatar_default: false })).toBe('')
	})

	it('is the same flower for the same account, everywhere', () => {
		expect(patternSvg('alice@example.org')).toBe(patternSvg('alice@example.org'))
		expect(patternAvatarUrl({ acct: 'alice', avatar_default: true })).toBe(patternAvatarUrl({ acct: 'alice', avatar_default: true }))
	})

	it('is tinted with the hue the handle gives', () => {
		expect(patternFor('alice').hue).toBe(tagHue('alice'))
		expect(patternSvg('alice')).toContain(`hsl(${tagHue('alice')}, 62%, 91%)`)
	})

	it('differs between accounts', () => {
		expect(patternSvg('alice')).not.toBe(patternSvg('bob'))
	})

	it('has five to seven petals and is a picture with nothing to read in it', () => {
		for (const key of ['a', 'b', 'c', 'd', 'e', 'f', 'g']) {
			const { petals } = patternFor(key)
			expect(petals).toBeGreaterThanOrEqual(5)
			expect(petals).toBeLessThanOrEqual(7)
		}

		expect(patternSvg('alice')).not.toMatch(/<text/)
	})

	it('comes as an SVG data URL', () => {
		const url = patternAvatarUrl({ acct: 'alice', avatar_default: true })

		expect(url.startsWith('data:image/svg+xml;charset=utf-8,')).toBe(true)
		expect(decodeURIComponent(url.split(',')[1])).toBe(patternSvg('alice'))
	})
})
