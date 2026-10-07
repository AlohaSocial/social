/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { isSamePage, pageDirection } from '../../../src/services/pageOrder.js'

const feed = (type) => ({ name: 'timeline', params: type === undefined ? {} : { type } })

describe('isSamePage', () => {
	it.each([
		[undefined, 'interests'],
		[undefined, 'timeline'],
		['timeline', 'federated'],
		['interests', 'federated'],
	])('calls the feed\'s scopes %s and %s one page', (from, to) => {
		expect(isSamePage(feed(to), feed(from))).toBe(true)
		expect(pageDirection(feed(to), feed(from))).toBe('')
	})

	it('calls the same page with another query the same page', () => {
		expect(isSamePage({ ...feed('photos'), query: { scope: 'federated' } }, feed('photos'))).toBe(true)
	})

	it('tells the feed from the other sidebar entries', () => {
		expect(isSamePage(feed('photos'), feed('timeline'))).toBe(false)
		expect(isSamePage(feed('notifications'), feed(undefined))).toBe(false)
		expect(pageDirection(feed('photos'), feed('federated'))).toBe('forward')
		expect(pageDirection(feed(undefined), feed('notifications'))).toBe('back')
	})

	it('is not the same page as nothing at all', () => {
		expect(isSamePage(feed(undefined), null)).toBe(false)
		expect(isSamePage(feed(undefined), undefined)).toBe(false)
	})
})
