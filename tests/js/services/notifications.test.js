/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

import {
	FILTER_KEY,
	NOTIFICATION_TYPES,
	excludeTypesFor,
	groupNotifications,
	newestIdOf,
	notificationSummary,
	rememberFilter,
	rememberedFilter,
} from '../../../src/services/notifications.js'

function notification(type, acct = 'bob@remote.tld') {
	return {
		id: '1',
		type,
		created_at: '2026-09-07T10:00:00.000Z',
		account: { id: '22', acct, username: acct.split('@')[0] },
	}
}

/**
 * @param {string} id the row id
 * @param {string} type what happened
 * @param {string} who the display name of the account it happened by
 * @param {string|null} statusId the post it happened to, when there is one
 * @return {object} a notification as the server sends it
 */
function entry(id, type, who, statusId = null) {
	return {
		id,
		type,
		created_at: '2026-09-07T10:00:00.000Z',
		account: { id: who, acct: who + '@remote.tld', display_name: who },
		status: statusId === null ? null : { id: statusId, content: 'a post' },
	}
}

describe('notificationSummary', () => {
	it.each([
		['mention', 'bob@remote.tld mentioned you'],
		['status', 'bob@remote.tld posted a status'],
		['reblog', 'bob@remote.tld boosted your post'],
		['follow', 'bob@remote.tld started to follow you'],
		['follow_request', 'bob@remote.tld requested to follow you'],
		['favourite', 'bob@remote.tld liked your post'],
		['poll', 'bob@remote.tld ended the poll'],
		['update', 'bob@remote.tld edited a status'],
		['admin.sign_up', 'bob@remote.tld signed up'],
		['admin.report', 'bob@remote.tld filed a report'],
	])('describes a %s notification with the acting account', (type, expected) => {
		expect(notificationSummary(notification(type))).toBe(expected)
	})

	it('uses the full handle of the acting account, local or remote', () => {
		expect(notificationSummary(notification('mention', 'alice'))).toBe('alice mentioned you')
	})

	it('returns an empty summary for unknown notification types', () => {
		expect(notificationSummary(notification('something.new'))).toBe('')
		expect(notificationSummary({ type: undefined, account: { acct: 'x' } })).toBe('')
	})

	it('does not let a handle inject markup into the summary', () => {
		expect(notificationSummary(notification('mention', '<b>evil</b>@remote.tld'))).toBe('&lt;b&gt;evil&lt;/b&gt;@remote.tld mentioned you')
	})
})

describe('the notifications filter', () => {
	it('sends nothing at all for "everything"', () => {
		expect(excludeTypesFor('all')).toEqual([])
	})

	it('excludes every type but the one a filter keeps', () => {
		const excluded = excludeTypesFor('mentions')

		expect(excluded).not.toContain('mention')
		expect(excluded).toHaveLength(NOTIFICATION_TYPES.length - 1)
	})

	it('counts a follow request as a follow, which is what it looks like', () => {
		const excluded = excludeTypesFor('follows')

		expect(excluded).not.toContain('follow')
		expect(excluded).not.toContain('follow_request')
	})

	it('filters nothing for a name it does not know', () => {
		expect(excludeTypesFor('nonsense')).toEqual([])
	})

	describe('what the browser remembers', () => {
		afterEach(() => {
			window.localStorage.removeItem(FILTER_KEY)
			vi.restoreAllMocks()
		})

		it('opens where the reader left it', () => {
			rememberFilter('boosts')

			expect(window.localStorage.getItem(FILTER_KEY)).toBe('boosts')
			expect(rememberedFilter()).toBe('boosts')
		})

		it('falls back to everything when what was stored is not a filter', () => {
			window.localStorage.setItem(FILTER_KEY, 'whatever')

			expect(rememberedFilter()).toBe('all')
		})

		it('survives a browser that refuses to remember anything', () => {
			vi.spyOn(window.localStorage, 'getItem').mockImplementation(() => {
				throw new Error('site data is blocked')
			})
			vi.spyOn(window.localStorage, 'setItem').mockImplementation(() => {
				throw new Error('site data is blocked')
			})

			expect(() => rememberFilter('polls')).not.toThrow()
			expect(rememberedFilter()).toBe('all')
		})
	})
})

describe('grouping the notifications', () => {
	it('folds a run of favourites of one post into a single card', () => {
		const cards = groupNotifications([
			entry('30', 'favourite', 'Anna', '7'),
			entry('29', 'favourite', 'Bob', '7'),
			entry('28', 'favourite', 'Cara', '7'),
		])

		expect(cards).toHaveLength(1)
		expect(cards[0].id).toBe('30')
		expect(cards[0].accounts.map((account) => account.display_name)).toEqual(['Anna', 'Bob', 'Cara'])
		expect(cards[0].ids).toEqual(['30', '29', '28'])
	})

	it('names the first two and counts the rest', () => {
		const cards = groupNotifications([
			entry('30', 'favourite', 'Anna', '7'),
			entry('29', 'favourite', 'Bob', '7'),
			entry('28', 'favourite', 'Cara', '7'),
			entry('27', 'favourite', 'Dan', '7'),
			entry('26', 'favourite', 'Eve', '7'),
		])

		expect(notificationSummary(cards[0])).toBe('Anna, Bob and 3 others liked your post')
	})

	it('says both names when there are two', () => {
		const cards = groupNotifications([
			entry('30', 'reblog', 'Anna', '7'),
			entry('29', 'reblog', 'Bob', '7'),
		])

		expect(notificationSummary(cards[0])).toBe('Anna and Bob boosted your post')
	})

	it('groups consecutive follows, which are about nobody in particular', () => {
		const cards = groupNotifications([
			entry('30', 'follow', 'Anna'),
			entry('29', 'follow', 'Bob'),
		])

		expect(cards).toHaveLength(1)
		expect(notificationSummary(cards[0])).toBe('Anna and Bob started to follow you')
	})

	it('keeps favourites of different posts apart', () => {
		const cards = groupNotifications([
			entry('30', 'favourite', 'Anna', '7'),
			entry('29', 'favourite', 'Bob', '8'),
		])

		expect(cards).toHaveLength(2)
	})

	/**
	 * Folding used to stop at the first notification of another kind, which on
	 * a real page is immediately: twelve likes of one post arrive interleaved
	 * with everything else that happened, so the page showed twelve cards
	 * anyway and the folding was for a case that does not occur.
	 */
	it('folds across something that happened in between', () => {
		const cards = groupNotifications([
			entry('30', 'favourite', 'Anna', '7'),
			entry('29', 'mention', 'Bob', '9'),
			entry('28', 'favourite', 'Cara', '7'),
		])

		expect(cards.map((card) => card.id)).toEqual(['30', '29'])
		expect(cards[0].accounts.map((account) => account.display_name)).toEqual(['Anna', 'Cara'])
		expect(cards[0].ids).toEqual(['30', '28'])
	})

	/**
	 * The card takes the place of its newest member, so nothing is folded
	 * upwards past something that happened after it.
	 */
	it('keeps a folded card where its newest member was', () => {
		const cards = groupNotifications([
			entry('30', 'mention', 'Anna', '9'),
			entry('29', 'favourite', 'Bob', '7'),
			entry('28', 'favourite', 'Cara', '7'),
			entry('27', 'favourite', 'Dan', '7'),
		])

		expect(cards.map((card) => card.id)).toEqual(['30', '29'])
		expect(cards[1].accounts).toHaveLength(3)
	})

	it('keeps the favourites of one post apart from the favourites of another', () => {
		const cards = groupNotifications([
			entry('30', 'favourite', 'Anna', '7'),
			entry('29', 'favourite', 'Bob', '8'),
			entry('28', 'favourite', 'Cara', '7'),
		])

		expect(cards).toHaveLength(2)
		expect(cards[0].ids).toEqual(['30', '28'])
		expect(cards[1].id).toBe('29')
	})

	it('leaves a mention alone however many there are in a row', () => {
		const cards = groupNotifications([
			entry('30', 'mention', 'Anna', '7'),
			entry('29', 'mention', 'Bob', '8'),
		])

		expect(cards).toHaveLength(2)
		expect(cards[0].accounts).toBeUndefined()
	})

	it('hands a lone notification back exactly as it came', () => {
		const one = entry('30', 'favourite', 'Anna', '7')

		expect(groupNotifications([one])[0]).toBe(one)
	})

	it('counts one person who liked the same post twice once', () => {
		const cards = groupNotifications([
			entry('30', 'favourite', 'Anna', '7'),
			entry('29', 'favourite', 'Anna', '7'),
		])

		expect(cards[0].accounts).toHaveLength(1)
		expect(cards[0].ids).toEqual(['30', '29'])
	})
})

describe('newestIdOf', () => {
	it('takes the id of a notification', () => {
		expect(newestIdOf({ id: '1788875057712399' })).toBe('1788875057712399')
	})

	it('takes the newest of the ids a grouped card stands for', () => {
		// the read marker is "up to", so anything less leaves the rest unread
		expect(newestIdOf({ id: '5', ids: ['5', '9', '3'] })).toBe('9')
	})

	it('falls back to the nid a status carries', () => {
		expect(newestIdOf({ nid: 42 })).toBe('42')
	})

	it('answers 0 for something with no id at all', () => {
		expect(newestIdOf({})).toBe('0')
	})

	it('keeps every digit of a twenty-digit id', () => {
		// `Number('1789553297940456473')` is 1789553297940456400: a marker set
		// from it lands *behind* the notification it was meant to cover, so
		// the badge came back however often the reader cleared it
		expect(newestIdOf({ id: '1789553297940456473' })).toBe('1789553297940456473')
		expect(newestIdOf({ ids: ['1789553297940456400', '1789553297940456473'] }))
			.toBe('1789553297940456473')
	})

	it('reads something that was never an id as no id at all', () => {
		expect(newestIdOf({ id: 'not-a-number' })).toBe('0')
	})
})

describe('what only Bluesky tells', () => {
	it('says each in words', () => {
		const said = (type) => notificationSummary({ type, account: { acct: 'bob.test' } })

		expect(said('bluesky:repost_liked')).toBe('bob.test liked your boost')
		expect(said('bluesky:repost_reposted')).toBe('bob.test boosted your boost')
		expect(said('bluesky:verified')).toBe('bob.test verified your account on Bluesky')
		expect(said('bluesky:unverified')).toBe('bob.test no longer verifies your account on Bluesky')
		expect(said('bluesky:starterpack_joined')).toBe('bob.test joined Bluesky with your starter pack')
	})

	it('leaves them out of a filter that keeps something else', () => {
		expect(excludeTypesFor('mentions')).toEqual(expect.arrayContaining(['bluesky:repost_liked', 'bluesky:verified']))
	})
})
