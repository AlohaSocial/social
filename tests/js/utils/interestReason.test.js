/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { interestReason } from '../../../src/utils/interestReason.js'

describe('why a post is in For you', () => {
	it.each([
		['interest', 'Because you\'re interested in #photography', 'Why you\'re seeing this: interested in #photography'],
		['followed', 'Because you follow #photography', 'Why you\'re seeing this: you follow #photography'],
		['related', 'Related to #photography', 'Why you\'re seeing this: related to #photography'],
		['trending', 'Trending here: #photography', 'Why you\'re seeing this: trending here, #photography'],
	])('says %s in its own words', (reason, text, label) => {
		expect(interestReason({ tags: ['photography'], reason })).toEqual({ tag: 'photography', text, label })
	})

	it('lists two tags', () => {
		expect(interestReason({ tags: ['photography', 'analog'], reason: 'interest' })).toMatchObject({
			tag: 'photography',
			text: '#photography, #analog',
			label: 'Why you\'re seeing this: interested in #photography, #analog',
		})
	})

	it('lists two and counts the rest', () => {
		expect(interestReason({ tags: ['photography', 'analog', 'film', 'darkroom'], reason: 'followed' })).toMatchObject({
			tag: 'photography',
			text: '#photography, #analog +2',
			label: 'Why you\'re seeing this: you follow #photography, #analog and 2 more',
		})
	})

	it('takes a tag written with its #, and an unknown reason as interest', () => {
		expect(interestReason({ tags: ['#film'], reason: 'mystery' }).text).toBe('Because you\'re interested in #film')
	})

	it('says a post no hashtag brought is popular, and links nowhere', () => {
		expect(interestReason({ tags: [], reason: 'popular' })).toEqual({
			tag: null,
			text: 'Popular right now',
			label: 'Why you\'re seeing this: popular right now',
		})
	})

	it('has nothing to say without a tag', () => {
		expect(interestReason(null)).toBeNull()
		expect(interestReason({ tags: [], reason: 'interest' })).toBeNull()
	})
})
