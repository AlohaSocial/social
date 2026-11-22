/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { showError } from '../services/toast.js'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

import logger from '../services/logger.js'
import { feel } from '../services/senses.js'
import { isNewerId, newerId } from '../utils/snowflake.js'

/**
 * What this store holds, for the getters that are handed it.
 *
 * @typedef {object} NotificationState
 * @property {number} unread how many notifications have not been read
 * @property {number} unreadDirect how many conversations have something unread in them
 * @property {boolean} directCounted whether the direct message count has been read once
 * @property {string} lastReadId the row the reader had read up to, '0' while unknown
 * @property {number} pendingRequests how many senders the notification policy is holding back
 */

/**
 * How many notifications arrived since the reader last looked, and where
 * that is. Both come from the server's read marker, shared with every other
 * client, so nothing is kept in the browser.
 */
export const useNotificationsStore = defineStore('notifications', {
	state: () => ({
		unread: 0,
		/**
		 * How many conversations, not messages, have something unread: five
		 * messages in one exchange are one thing to read.
		 */
		unreadDirect: 0,
		/** whether the count has been read once, so a rise can be told from a first answer */
		directCounted: false,
		/** how many senders the notification policy is holding back, from the policy's summary */
		pendingRequests: 0,
		/** whether the one-time notice about choosing who may reach you is due, as the policy says */
		policyNotice: false,
		/**
		 * The row id the reader had read up to, as last fetched; '0' while
		 * unknown or never read. Activities draws its "New" heading from it.
		 *
		 * A string, as a twenty-digit snowflake does not fit a Number.
		 * See `newestIdOf()`.
		 */
		lastReadId: '0',
	}),

	getters: {
		/**
		 * @param {NotificationState} state the store state
		 * @return {number} what the badge shows
		 */
		unreadNotifications(state) {
			return state.unread
		},

		/**
		 * The count on Activities in the sidebar: unread notifications plus
		 * the held senders waiting for a decision, who never ring the bell.
		 *
		 * @param {NotificationState} state the store state
		 * @return {number} what the Activities badge shows
		 */
		activitiesCount(state) {
			return state.unread + state.pendingRequests
		},

		/**
		 * @param {NotificationState} state the store state
		 * @return {number} what the Direct messages badge shows
		 */
		unreadDirectMessages(state) {
			return state.unreadDirect
		},
	},

	actions: {
		setUnreadNotifications(count) {
			this.unread = count
		},

		setUnreadDirectMessages(count) {
			this.unreadDirect = count
		},

		/**
		 * Reads how many conversations are unread. Silent on failure: a badge
		 * is not worth a toast.
		 *
		 * @return {Promise<boolean>} whether the server answered
		 */
		async fetchUnreadDirectMessages() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/conversations/unread_count'))
				this.countDirectMessages(data?.count)

				return true
			} catch (error) {
				logger.error('Failed to read the unread direct message count', { error })

				return false
			}
		},

		/**
		 * Takes a new unread conversation count, chiming for a conversation
		 * that became unread since the last count, never for the first one.
		 *
		 * @param {unknown} answered the count as the server sent it
		 */
		countDirectMessages(answered) {
			const count = Number(answered) || 0
			if (this.directCounted && count > this.unreadDirect) {
				feel('dm')
			}
			this.directCounted = true
			this.setUnreadDirectMessages(count)
		},

		/**
		 * Reads all three sidebar counts in one request: unread notifications,
		 * held senders (and whether the policy notice is due) and unread
		 * conversations. The server answers `304` when none changed. Silent on
		 * failure.
		 *
		 * @return {Promise<boolean>} whether the server answered
		 */
		async fetchBadges() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/social/badges'))
				this.setUnreadNotifications(Number(data?.unread_notifications) || 0)
				this.setPendingRequests(data?.pending_requests)
				this.setPolicyNotice(data?.notice === true)
				this.countDirectMessages(data?.unread_conversations)

				return true
			} catch (error) {
				logger.error('Failed to read the sidebar counts', { error })

				return false
			}
		},

		/**
		 * Marks the Direct messages page as seen and clears the badge without
		 * waiting for the server.
		 */
		async markDirectMessagesRead() {
			if (this.unreadDirect === 0) {
				return
			}

			this.setUnreadDirectMessages(0)
			try {
				await axios.post(generateUrl('apps/social/api/v1/conversations/read_all'))
			} catch (error) {
				logger.error('Failed to mark the direct messages read', { error })
				// put back whatever the server actually thinks
				this.fetchUnreadDirectMessages()
			}
		},

		setPendingRequests(count) {
			this.pendingRequests = Math.max(0, Number(count) || 0)
		},

		/** @param {boolean} due whether the one-time notice is to be shown */
		setPolicyNotice(due) {
			this.policyNotice = due === true
		},

		/**
		 * Reads how many senders the policy is holding back, from the policy's
		 * summary as Mastodon clients do, and whether the one-time notice is
		 * due. Silent on failure.
		 *
		 * @return {Promise<boolean>} whether the server answered
		 */
		async fetchPendingRequests() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v2/notifications/policy'))
				this.setPendingRequests(data?.summary?.pending_requests_count)
				this.setPolicyNotice(data?.notice === true)

				return true
			} catch (error) {
				logger.error('Failed to read how many senders are held back', { error })

				return false
			}
		},

		/**
		 * Puts the one-time notice away for good. It goes at once; if the
		 * request fails it comes back on a later visit.
		 */
		async dismissPolicyNotice() {
			this.setPolicyNotice(false)
			try {
				await axios.post(generateUrl('apps/social/api/v1/social/notifications/policy/notice/dismiss'))
			} catch (error) {
				logger.error('Failed to dismiss the notification policy notice', { error })
			}
		},

		/**
		 * Reads the count. Failure is silent: a badge is not worth a toast.
		 *
		 * @return {Promise<boolean>} whether the server answered
		 */
		async fetchUnreadNotifications() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/notifications/unread_count'))
				this.setUnreadNotifications(Number(data?.count) || 0)

				return true
			} catch (error) {
				logger.error('Failed to read the unread notification count', { error })

				return false
			}
		},

		/**
		 * Reads the server's read marker, where Activities draws the line
		 * between new and seen. A failure answers '0' (no line), silently.
		 *
		 * @return {Promise<string>} the marker; '0' when unknown
		 */
		async fetchLastRead() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/markers'), {
					params: { timeline: ['notifications'] },
				})
				const marker = newerId(data?.notifications?.last_read_id, '0')
				this.lastReadId = newerId(marker, this.lastReadId)

				return marker
			} catch (error) {
				logger.error('Failed to read the notifications marker', { error })

				return '0'
			}
		},

		/**
		 * Marks everything up to `lastReadId` as read, clearing the badge
		 * without waiting for the server.
		 *
		 * @param {string|number} lastReadId the newest notification now seen
		 */
		async markNotificationsRead(lastReadId) {
			if (!isNewerId(lastReadId, '0')) {
				return
			}

			this.setUnreadNotifications(0)
			// the server never moves a marker backwards, and neither does this
			this.lastReadId = newerId(lastReadId, this.lastReadId)

			try {
				await axios.post(generateUrl('apps/social/api/v1/markers'), {
					notifications: { last_read_id: String(lastReadId) },
				})
			} catch (error) {
				showError(t('social', 'Could not save your place in Activities'))
				logger.error('Failed to move the notifications marker', { error })
				// put back whatever the server actually thinks
				this.fetchUnreadNotifications()
			}
		},

		/**
		 * Marks everything read, whatever Activities is filtered to: the marker
		 * is "read up to", so it is set from the newest notification fetched
		 * unfiltered, not the newest of the filtered kind.
		 *
		 * @return {Promise<string>} the id it marked up to; '0' when it could not
		 */
		async markAllRead() {
			let newest
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/notifications'), {
					params: { limit: 1 },
				})
				newest = newerId(data?.[0]?.id, '0')
			} catch (error) {
				showError(t('social', 'Could not mark your activities as read'))
				logger.error('Failed to read the newest notification', { error })

				return '0'
			}

			if (newest === '0') {
				// nothing to mark: the count was wrong, not the marker
				this.setUnreadNotifications(0)

				return '0'
			}

			await this.markNotificationsRead(newest)

			return this.lastReadId
		},
	},
})
