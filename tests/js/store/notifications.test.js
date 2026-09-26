/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import { showError } from '../../../src/services/toast.js'

import { useNotificationsStore } from '../../../src/store/notifications.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
const { feel } = vi.hoisted(() => ({ feel: vi.fn() }))
vi.mock('../../../src/services/senses.js', () => ({ feel }))

let store

describe('notifications store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		store = useNotificationsStore()
		vi.clearAllMocks()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	describe('fetchLastRead', () => {
		it('reads the marker every other client shares', async () => {
			axios.get.mockResolvedValue({ data: { notifications: { last_read_id: '1788875057712399' } } })

			const marker = await store.fetchLastRead()

			expect(axios.get).toHaveBeenCalledWith(
				expect.stringContaining('/api/v1/markers'),
				{ params: { timeline: ['notifications'] } },
			)
			expect(marker).toBe('1788875057712399')
			expect(store.lastReadId).toBe('1788875057712399')
		})

		it('answers 0 for an account that has never read anything', async () => {
			axios.get.mockResolvedValue({ data: {} })

			expect(await store.fetchLastRead()).toBe('0')
		})

		it('answers 0 rather than guessing when the server cannot be asked', async () => {
			axios.get.mockRejectedValue(new Error('nope'))

			expect(await store.fetchLastRead()).toBe('0')
			expect(store.lastReadId).toBe('0')
		})

		it('keeps every digit of a twenty-digit marker', () => {
			// a marker rounded to a Number sits behind the notification it was
			// meant to cover, and the badge comes back
			axios.get.mockResolvedValue({
				data: { notifications: { last_read_id: '1789553297940456473' } },
			})

			return expect(store.fetchLastRead()).resolves.toBe('1789553297940456473')
		})
	})

	describe('fetchUnreadNotifications', () => {
		it('reads the count the server keeps', async () => {
			axios.get.mockResolvedValue({ data: { count: 5 } })

			await store.fetchUnreadNotifications()

			expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('/api/v1/notifications/unread_count'))
			expect(store.unreadNotifications).toBe(5)
		})

		it('starts at nothing waiting', () => {
			expect(store.unreadNotifications).toBe(0)
		})

		it('says nothing when the count cannot be read', async () => {
			axios.get.mockRejectedValue(new Error('offline'))

			await store.fetchUnreadNotifications()

			// a badge is not worth interrupting anyone about
			expect(showError).not.toHaveBeenCalled()
			expect(store.unreadNotifications).toBe(0)
		})

		it('treats a missing or unreadable count as none', async () => {
			axios.get.mockResolvedValue({ data: {} })
			await store.fetchUnreadNotifications()
			expect(store.unreadNotifications).toBe(0)

			axios.get.mockResolvedValue({ data: { count: 'lots' } })
			await store.fetchUnreadNotifications()
			expect(store.unreadNotifications).toBe(0)
		})
	})

	describe('markNotificationsRead', () => {
		it('sends the marker and clears the badge', async () => {
			store.setUnreadNotifications(5)
			axios.post.mockResolvedValue({ data: {} })

			await store.markNotificationsRead(42)

			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/api/v1/markers'),
				{ notifications: { last_read_id: '42' } },
			)
			expect(store.unreadNotifications).toBe(0)
		})

		it('clears the badge before the server answers', async () => {
			store.setUnreadNotifications(5)
			let resolve
			axios.post.mockReturnValue(new Promise((_resolve) => {
				resolve = _resolve
			}))

			const pending = store.markNotificationsRead(42)

			// the reader is looking at the notifications; the badge is already wrong
			expect(store.unreadNotifications).toBe(0)
			resolve({ data: {} })
			await pending
		})

		it('asks the server what it really thinks when the marker will not save', async () => {
			store.setUnreadNotifications(5)
			axios.post.mockRejectedValue(new Error('nope'))
			axios.get.mockResolvedValue({ data: { count: 5 } })

			await store.markNotificationsRead(42)

			expect(showError).toHaveBeenCalled()
			expect(store.unreadNotifications).toBe(5)
		})

		it('sends nothing when there is no position to record', async () => {
			await store.markNotificationsRead(0)

			expect(axios.post).not.toHaveBeenCalled()
		})
	})

	describe('markAllRead', () => {
		it('asks for the newest activity of any kind, not of the filter on screen', async () => {
			store.setUnreadNotifications(9)
			axios.get.mockResolvedValue({ data: [{ id: '90', type: 'favourite' }] })
			axios.post.mockResolvedValue({ data: {} })

			const marker = await store.markAllRead()

			// no exclude_types: filtered to Mentions, the newest mention can be
			// older than a dozen favourites, and marking up to it would leave
			// the badge up over what the reader had just dismissed
			expect(axios.get).toHaveBeenCalledWith(
				expect.stringContaining('/api/v1/notifications'),
				{ params: { limit: 1 } },
			)
			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/api/v1/markers'),
				{ notifications: { last_read_id: '90' } },
			)
			expect(store.unreadNotifications).toBe(0)
			expect(marker).toBe('90')
		})

		it('clears a badge that was counting notifications no longer there', async () => {
			store.setUnreadNotifications(3)
			axios.get.mockResolvedValue({ data: [] })

			const marker = await store.markAllRead()

			expect(axios.post).not.toHaveBeenCalled()
			expect(store.unreadNotifications).toBe(0)
			expect(marker).toBe('0')
		})

		it('moves nothing and says so when the newest cannot be read', async () => {
			store.setUnreadNotifications(3)
			axios.get.mockRejectedValue(new Error('nope'))

			const marker = await store.markAllRead()

			expect(showError).toHaveBeenCalled()
			expect(axios.post).not.toHaveBeenCalled()
			// the badge is left alone: nothing was marked, so it is still right
			expect(store.unreadNotifications).toBe(3)
			expect(marker).toBe('0')
		})
	})

	describe('the direct messages badge', () => {
		it('counts conversations, from the route that counts them', async () => {
			axios.get.mockResolvedValue({ data: { count: 3 } })
			const store = useNotificationsStore()

			await store.fetchUnreadDirectMessages()

			expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations/unread_count')
			expect(store.unreadDirectMessages).toBe(3)
		})

		it('says nothing when the count cannot be read', async () => {
			axios.get.mockRejectedValue(new Error('network'))
			const store = useNotificationsStore()

			await store.fetchUnreadDirectMessages()

			expect(store.unreadDirectMessages).toBe(0)
		})

		/**
		 * A chime for a conversation that became unread since the last look,
		 * and never for the count the page opened with: that one is news to
		 * nobody.
		 */
		it('chimes when a conversation becomes unread, not for the first count', async () => {
			const store = useNotificationsStore()

			axios.get.mockResolvedValue({ data: { count: 2 } })
			await store.fetchUnreadDirectMessages()
			expect(feel).not.toHaveBeenCalled()

			await store.fetchUnreadDirectMessages()
			expect(feel).not.toHaveBeenCalled()

			axios.get.mockResolvedValue({ data: { count: 3 } })
			await store.fetchUnreadDirectMessages()
			expect(feel).toHaveBeenCalledWith('dm')
		})

		/**
		 * The reader has seen them, so the badge is already wrong: it comes
		 * down without waiting for the server to agree.
		 */
		it('clears the badge before the server answers', async () => {
			const store = useNotificationsStore()
			store.setUnreadDirectMessages(2)
			let resolve
			axios.post.mockReturnValue(new Promise((r) => {
				resolve = r
			}))

			const pending = store.markDirectMessagesRead()
			expect(store.unreadDirectMessages).toBe(0)

			resolve({ data: { count: 2 } })
			await pending
		})

		it('asks for nothing when there was nothing unread', async () => {
			const store = useNotificationsStore()

			await store.markDirectMessagesRead()

			expect(axios.post).not.toHaveBeenCalled()
		})

		it('puts the badge back when the server refuses', async () => {
			const store = useNotificationsStore()
			store.setUnreadDirectMessages(2)
			axios.post.mockRejectedValue(new Error('nope'))
			axios.get.mockResolvedValue({ data: { count: 2 } })

			await store.markDirectMessagesRead()

			expect(store.unreadDirectMessages).toBe(2)
		})
	})
})
