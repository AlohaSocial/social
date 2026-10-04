/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { getCurrentUser } from '@nextcloud/auth'
import { clearDraft, loadDraft, saveDraft } from '../../../src/services/draft.js'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => null) }))

/** The key used when nobody is signed in, which is also the key this app used
 * to use for everybody. */
const KEY = 'social.composer.draft'

/**
 * Signs somebody in for the rest of the test.
 *
 * @param {string} uid who
 */
function signedInAs(uid) {
	getCurrentUser.mockReturnValue({ uid, displayName: uid, isAdmin: false })
}

describe('the composer draft', () => {
	beforeEach(() => {
		localStorage.clear()
		getCurrentUser.mockReturnValue(null)
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('keeps what was typed and gives it back', () => {
		expect(saveDraft({ text: 'half a thought', spoilerText: 'spoilers', visibility: 'followers' })).toBe(true)

		expect(loadDraft()).toMatchObject({
			text: 'half a thought',
			spoilerText: 'spoilers',
			visibility: 'followers',
		})
	})

	/**
	 * The composer has always sent this and the store has always dropped it,
	 * so the line that reads it back — "a reload does not quietly turn a team
	 * post back into a personal one" — could never be true.
	 */
	it('remembers which account the post was being written as', () => {
		saveDraft({ text: 'from the team', postAs: 'design@cloud.example.org' })

		expect(loadDraft()).toMatchObject({ postAs: 'design@cloud.example.org' })
	})

	it('says "the account itself" when the draft names no team', () => {
		saveDraft({ text: 'from me' })

		expect(loadDraft().postAs).toBe('')
	})

	it('has nothing to give back when nothing was kept', () => {
		expect(loadDraft()).toBeNull()
	})

	it('replaces the previous draft rather than adding to it', () => {
		saveDraft({ text: 'first' })
		saveDraft({ text: 'second' })

		expect(loadDraft().text).toBe('second')
	})

	it('treats an empty composer as nothing to keep', () => {
		saveDraft({ text: 'something' })
		saveDraft({ text: '   ' })

		expect(loadDraft()).toBeNull()
	})

	it('keeps a warning even when the body is still empty', () => {
		saveDraft({ text: '', spoilerText: 'spoilers' })

		expect(loadDraft()).toMatchObject({ text: '', spoilerText: 'spoilers' })
	})

	it('forgets the draft on request', () => {
		saveDraft({ text: 'off it goes' })
		clearDraft()

		expect(loadDraft()).toBeNull()
	})

	it('drops a draft nobody came back for in a week', () => {
		localStorage.setItem(KEY, JSON.stringify({
			text: 'last month',
			savedAt: Date.now() - 8 * 24 * 3600 * 1000,
		}))

		expect(loadDraft()).toBeNull()
		expect(localStorage.getItem(KEY)).toBeNull()
	})

	it('discards anything that is not a draft', () => {
		localStorage.setItem(KEY, 'not json at all')
		expect(loadDraft()).toBeNull()

		localStorage.setItem(KEY, JSON.stringify({ nothing: 'useful' }))
		expect(loadDraft()).toBeNull()
	})

	it('says so rather than throwing when the store cannot be written', () => {
		vi.spyOn(localStorage, 'setItem').mockImplementation(() => {
			throw new Error('denied')
		})

		expect(saveDraft({ text: 'a private window' })).toBe(false)
	})

	it('reads as empty rather than throwing when the store cannot be read', () => {
		vi.spyOn(localStorage, 'getItem').mockImplementation(() => {
			throw new Error('denied')
		})

		expect(loadDraft()).toBeNull()
	})

	// A reply and a post of its own are two drafts: with one slot, a reply
	// opened over a half-written post took its place, and sending either
	// threw the other away.
	describe('and what it belongs to', () => {
		it('keeps a reply apart from the post being written', () => {
			saveDraft({ text: 'a post of my own' })
			saveDraft({ text: 'an answer' }, 'reply.42')

			expect(loadDraft()).toMatchObject({ text: 'a post of my own' })
			expect(loadDraft('reply.42')).toMatchObject({ text: 'an answer' })
			expect(loadDraft('reply.43')).toBeNull()
		})

		it('forgets only the draft that went out', () => {
			saveDraft({ text: 'a post of my own' })
			saveDraft({ text: 'an answer' }, 'reply.42')

			clearDraft('reply.42')

			expect(loadDraft('reply.42')).toBeNull()
			expect(loadDraft()).toMatchObject({ text: 'a post of my own' })
		})

		it('treats an emptied reply as nothing to keep', () => {
			saveDraft({ text: 'an answer' }, 'reply.42')
			saveDraft({ text: '' }, 'reply.42')

			expect(loadDraft('reply.42')).toBeNull()
		})

		it('drops a reply nobody came back for in a week', () => {
			localStorage.setItem(`${KEY}.reply.42`, JSON.stringify({
				text: 'last month',
				savedAt: Date.now() - 8 * 24 * 3600 * 1000,
			}))

			expect(loadDraft('reply.42')).toBeNull()
			expect(localStorage.getItem(`${KEY}.reply.42`)).toBeNull()
		})

		it('still throws away the draft from before drafts were scoped when a reply is read', () => {
			localStorage.setItem(KEY, JSON.stringify({ text: 'from before', savedAt: Date.now() }))
			signedInAs('alice')

			loadDraft('reply.42')

			expect(localStorage.getItem(KEY)).toBeNull()
		})
	})

	// `localStorage` belongs to the origin, not to the session: it survives a
	// logout, and every account signing in to this Nextcloud in this browser
	// profile reads the same keys. An unsent draft is somebody's words.
	describe('and whose it is', () => {
		it('does not give one account the draft another one left behind', () => {
			signedInAs('alice')
			saveDraft({ text: 'the thing I have not said yet' })

			signedInAs('bob')

			expect(loadDraft()).toBeNull()
		})

		it('gives it back to the account that wrote it', () => {
			signedInAs('alice')
			saveDraft({ text: 'the thing I have not said yet' })

			signedInAs('bob')
			loadDraft()
			signedInAs('alice')

			expect(loadDraft()).toMatchObject({ text: 'the thing I have not said yet' })
		})

		it('does not clear one account\'s draft when another one posts', () => {
			signedInAs('alice')
			saveDraft({ text: 'alice is still writing' })

			signedInAs('bob')
			saveDraft({ text: 'bob is still writing' })
			clearDraft()

			signedInAs('alice')
			expect(loadDraft()).toMatchObject({ text: 'alice is still writing' })
		})

		// Removed rather than adopted: there is no way to tell whose it was,
		// and the account reading it is not necessarily the one that wrote it.
		it('throws away a draft written before drafts were scoped', () => {
			localStorage.setItem(KEY, JSON.stringify({ text: 'from before', savedAt: Date.now() }))
			signedInAs('alice')

			expect(loadDraft()).toBeNull()
			expect(localStorage.getItem(KEY)).toBeNull()
		})

		// Signed out the two keys are one key, and forgetting the old one
		// would delete what is about to be read.
		it('still reads its own draft when nobody is signed in', () => {
			saveDraft({ text: 'no session here' })

			expect(loadDraft()).toMatchObject({ text: 'no session here' })
		})
	})
})
