/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { lessLikeThisFromPost } from '../../../src/services/interestFeedback.js'
import { lessLikeThis, undoLessLikeThis } from '../../../src/services/interests.js'
import { showError, showSuccess, showUndo } from '../../../src/services/toast.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.mock('../../../src/services/interests.js', () => ({
	lessLikeThis: vi.fn(),
	undoLessLikeThis: vi.fn(),
}))
vi.mock('../../../src/services/toast.js', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
	showUndo: vi.fn(),
}))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const post = (id, day) => ({ id, created_at: `2026-09-${day}T10:00:00Z`, reblog: null, tags: [{ name: 'film' }], account: { acct: 'bob' } })

let store

function feed(type = 'interests') {
	setActivePinia(createPinia())
	store = useTimelineStore()
	// ranked, so the oldest post is in the middle
	const posts = [post('3', '10'), post('1', '12'), post('2', '11')]
	store.$patch({
		type,
		statuses: Object.fromEntries(posts.map((one) => [one.id, one])),
		timeline: posts.map((one) => one.id),
	})

	return posts
}

describe('less like this', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		lessLikeThis.mockResolvedValue(undefined)
		undoLessLikeThis.mockResolvedValue(undefined)
	})

	it('keeps the server\'s ranking on For you', () => {
		feed()

		expect(store.getTimeline.map((one) => one.id)).toEqual(['3', '1', '2'])
	})

	it('takes the post out of For you at once and offers an Undo', async () => {
		const [, second] = feed()

		const done = lessLikeThisFromPost(second, 'interests')
		expect(store.timeline).toEqual(['3', '2'])
		await done

		expect(lessLikeThis).toHaveBeenCalledWith('1')
		expect(showUndo).toHaveBeenCalledTimes(1)
	})

	it('puts it back where it was when Undo is pressed', async () => {
		const [, second] = feed()
		await lessLikeThisFromPost(second, 'interests')

		await showUndo.mock.calls[0][1]()
		await flushPromises()

		expect(undoLessLikeThis).toHaveBeenCalledWith('1')
		expect(store.getTimeline.map((one) => one.id)).toEqual(['3', '1', '2'])
	})

	it('puts it back and says so when the server refuses', async () => {
		lessLikeThis.mockRejectedValue(new Error('500'))
		const [, second] = feed()
		await lessLikeThisFromPost(second, 'interests')

		expect(store.timeline).toEqual(['3', '1', '2'])
		expect(showError).toHaveBeenCalled()
		expect(showUndo).not.toHaveBeenCalled()
	})

	it('finds the entry that boosts the post', async () => {
		const [first] = feed()
		store.$patch({ statuses: { b1: { id: 'b1', created_at: '2026-09-13T10:00:00Z', reblog: first, account: { acct: 'carol' } } } })
		store.timeline = ['b1', '1', '2']
		await lessLikeThisFromPost(first, 'interests')

		expect(store.timeline).toEqual(['1', '2'])
	})

	it('leaves the post where it is on any other timeline', async () => {
		const [, second] = feed('home')
		await lessLikeThisFromPost(second, 'home')

		expect(lessLikeThis).toHaveBeenCalledWith('1')
		expect(store.timeline).toEqual(['3', '1', '2'])
		expect(showSuccess).toHaveBeenCalled()
		expect(showUndo).not.toHaveBeenCalled()
	})
})
