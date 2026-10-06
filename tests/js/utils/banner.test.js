/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { bannerOf } from '../../../src/utils/banner.js'

describe('the banner of an account', () => {
	it('is the header when the account has one', () => {
		expect(bannerOf({ header: 'https://cloud.example.org/apps/social/media/abc' })).toBe('https://cloud.example.org/apps/social/media/abc')
	})

	it('is nothing for the placeholder the server sends instead of a banner', () => {
		expect(bannerOf({ header: 'https://cloud.example.org/apps/social/img/header-missing.svg' })).toBe('')
		expect(bannerOf({ header: 'https://cloud.example.org/apps/social/img/header-missing.svg?v=2' })).toBe('')
		expect(bannerOf({ header: 'https://cloud.example.org/apps/social/img/header-missing.png' })).toBe('')
		expect(bannerOf({ header: 'https://cloud.example.org/custom_apps/social/img/header-missing.png?v=3' })).toBe('')
	})

	it('is nothing when the server says the header is its placeholder', () => {
		expect(bannerOf({ header: 'https://cloud.example.org/index.php/avatar/alice/128', header_default: true })).toBe('')
		expect(bannerOf({ header: 'https://cloud.example.org/apps/social/media/abc', header_default: false })).toBe('https://cloud.example.org/apps/social/media/abc')
	})

	it('is nothing without a header at all', () => {
		expect(bannerOf({ header: '' })).toBe('')
		expect(bannerOf(null)).toBe('')
		expect(bannerOf(undefined)).toBe('')
	})
})
