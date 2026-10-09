/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { statusPayload } from '../../../src/utils/statusPayload.js'

/**
 * @param {object} overrides what differs from a plain public post
 * @return {object}
 */
function post(overrides = {}) {
	return {
		text: 'hello',
		warning: '',
		mediaIds: [],
		inReplyToId: undefined,
		quoteId: undefined,
		visibility: 'public',
		postAs: '',
		language: 'en',
		video: null,
		place: null,
		scheduledAt: null,
		poll: null,
		...overrides,
	}
}

describe('statusPayload', () => {
	it('is the words, the audience and the language for a plain post', () => {
		expect(statusPayload(post())).toEqual({
			content_type: '',
			media_ids: [],
			sensitive: false,
			spoiler_text: '',
			status: 'hello',
			in_reply_to_id: undefined,
			quote_id: undefined,
			visibility: 'public',
			language: 'en',
		})
	})

	it('marks a post with a warning as sensitive', () => {
		const body = statusPayload(post({ warning: 'Spoiler' }))

		expect(body.sensitive).toBe(true)
		expect(body.spoiler_text).toBe('Spoiler')
	})

	it('names the team only when the post is written as one', () => {
		expect(statusPayload(post())).not.toHaveProperty('post_as')
		expect(statusPayload(post({ postAs: 'team@cloud.example' })).post_as).toBe('team@cloud.example')
	})

	it('carries only the video fields that were filled in, trimmed', () => {
		const body = statusPayload(post({ video: { title: ' A talk ', category: '', licence: 'CC-BY' } }))

		expect(body.video_title).toBe('A talk')
		expect(body.video_licence).toBe('CC-BY')
		expect(body).not.toHaveProperty('video_category')
	})

	it('names a known place by its id and a new one by its name and country', () => {
		expect(statusPayload(post({ place: { id: '7', name: 'Ignored' } }))).toMatchObject({ place_id: '7' })
		expect(statusPayload(post({ place: { id: '7' } }))).not.toHaveProperty('place_name')
		expect(statusPayload(post({ place: { name: 'Stuttgart', country: 'DE' } })))
			.toMatchObject({ place_name: 'Stuttgart', place_country: 'DE' })
		expect(statusPayload(post({ place: { name: 'Somewhere' } }))).not.toHaveProperty('place_country')
	})

	it('sends the schedule in UTC', () => {
		const when = new Date(Date.UTC(2026, 9, 1, 8, 30))

		expect(statusPayload(post({ scheduledAt: when })).scheduled_at).toBe('2026-10-01T08:30:00.000Z')
		expect(statusPayload(post())).not.toHaveProperty('scheduled_at')
	})

	it('sends a poll only with two options that say something', () => {
		expect(statusPayload(post({ poll: { options: [' Yes ', 'No', ' '], expiresIn: 3600, multiple: true } })).poll)
			.toEqual({ options: ['Yes', 'No'], expires_in: 3600, multiple: true })
		expect(statusPayload(post({ poll: { options: ['Yes', ''], expiresIn: 3600, multiple: false } })))
			.not.toHaveProperty('poll')
		expect(statusPayload(post())).not.toHaveProperty('poll')
	})

	it('says who may reply only when it is less than everybody', () => {
		expect(statusPayload(post())).not.toHaveProperty('reply_policy')
		expect(statusPayload(post({ replyPolicy: 'everyone' }))).not.toHaveProperty('reply_policy')
		expect(statusPayload(post({ replyPolicy: 'followers' })).reply_policy).toBe('followers')
	})
})
