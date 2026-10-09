/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { routeFor, uriOf } from './blueskyFeeds.js'

const FEED = 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.generator/whats-hot'
const LIST = 'at://did:web:lists.example/app.bsky.graph.list/3kfriends'

describe('routeFor and uriOf', () => {
	it('spells a feed and a list as path segments and reads them back', () => {
		expect(routeFor(FEED)).toEqual({ name: 'bluesky-feed', params: { did: 'did:plc:z72i7hdynmk6r22z27h6tvur', kind: 'feed', rkey: 'whats-hot' } })
		expect(uriOf(routeFor(FEED).params)).toBe(FEED)
		expect(uriOf(routeFor(LIST).params)).toBe(LIST)
	})

	it('names nothing for what is neither', () => {
		expect(routeFor('at://did:plc:bob/app.bsky.feed.post/3kpost')).toBeNull()
		expect(routeFor('https://bsky.app/profile/bob.test/feed/cats')).toBeNull()
		expect(uriOf({ did: 'did:plc:bob', kind: 'post', rkey: '3k' })).toBe('')
		expect(uriOf({})).toBe('')
	})
})
