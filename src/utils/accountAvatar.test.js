/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { accountAvatarUrl, SMALL_AVATAR_SIZE } from './accountAvatar.js'

const account = {
	avatar: 'https://cloud.example/apps/social/media/original',
	avatar_small: 'https://cloud.example/apps/social/media/small',
}

describe('accountAvatarUrl', () => {
	it('draws a small avatar from the thumbnail', () => {
		expect(accountAvatarUrl(account, 16)).toBe(account.avatar_small)
		expect(accountAvatarUrl(account, SMALL_AVATAR_SIZE)).toBe(account.avatar_small)
		expect(accountAvatarUrl(account)).toBe(account.avatar_small)
		expect(accountAvatarUrl(account, null)).toBe(account.avatar_small)
	})

	it('draws a large avatar from the original', () => {
		expect(accountAvatarUrl(account, 65)).toBe(account.avatar)
		expect(accountAvatarUrl(account, 128)).toBe(account.avatar)
	})

	it('falls back to the avatar where the entity has no thumbnail', () => {
		expect(accountAvatarUrl({ avatar: 'https://cdn.bsky.app/a.jpg' }, 32)).toBe('https://cdn.bsky.app/a.jpg')
		expect(accountAvatarUrl({ avatar: 'x', avatar_small: '' }, 32)).toBe('x')
		expect(accountAvatarUrl({}, 32)).toBe('')
		expect(accountAvatarUrl(null)).toBe('')
	})
})
