/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { localProfileUrl, publicLocalStatusUrl } from '../../../src/utils/accountProfileLink.js'

describe('account profile links', () => {
	it('uses the native Nextcloud profile for a local account', () => {
		expect(localProfileUrl({ acct: 'alice', username: 'alice' })).toBe('/index.php/u/alice')
		expect(localProfileUrl({ acct: 'a/b', username: 'a/b' }, '123')).toBe('/index.php/u/a%2Fb#social-profile-status-123')
	})

	it('leaves remote accounts to Social or their ActivityPub address', () => {
		expect(localProfileUrl({ acct: 'alice@remote.example', username: 'alice' })).toBe('')
		expect(localProfileUrl({ acct: '', username: 'alice' })).toBe('')
	})

	it('links only public local statuses to the native profile anchor', () => {
		const status = { id: '456', visibility: 'public', account: { acct: 'alice', username: 'alice' } }
		expect(publicLocalStatusUrl(status)).toBe('/index.php/u/alice#social-profile-status-456')
		expect(publicLocalStatusUrl({ ...status, visibility: 'private' })).toBe('')
		expect(publicLocalStatusUrl({ ...status, account: { acct: 'alice@remote.example', username: 'alice' } })).toBe('')
	})
})
