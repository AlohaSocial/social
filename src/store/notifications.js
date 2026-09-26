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
 */

/**
 * How many notifications have arrived since the reader last looked, and where
 * "last looked" is.
 *
 * Both come from the server, which keeps a marker — the same marker every
 * other Fediverse client keeps, so a badge cleared on a phone is cleared here
 * too. Nothing is stored in the browser: a per-device idea of "unread" is
 * worse than none.
 */
export const useNotificationsStore = defineStore('notifications', {
	state: () => ({
		unread: 0,
		/**
		 * How many conversations have something unread in them.
		 *
		 * A conversation and not a message: five messages in one exchange are
		 * one thing to go and read, and a badge saying five would send
		 * somebody looking for four conversations that are not there.
		 */
		unreadDirect: 0,
		/** whether the count has been read once, so a rise can be told from a first answer */
		directCounted: false,
		/**
		 * The row id the reader had read up to, the last time the server was
		 * asked; '0' while unknown or when nothing has ever been read. What
		 * the notifications page draws its "New" line from.
		 *
		 * A string: it is a twenty-digit snowflake, and a Number holds
		 * seventeen of them. See `newestIdOf()`.
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
		 * Reads how many conversations are unread. Silent on failure, for the
		 * reason the notification count is: a badge is not worth a toast.
		 */
		async fetchUnreadDirectMessages() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/conversations/unread_count'))
				const count = Number(data?.count) || 0
				// a chime for a conversation that became unread since the last
				// look -- never for the count the page opened with, which is
				// news to nobody
				if (this.directCounted && count > this.unreadDirect) {
					feel('dm')
				}
				this.directCounted = true
				this.setUnreadDirectMessages(count)
			} catch (error) {
				logger.error('Failed to read the unread direct message count', { error })
			}
		},

		/**
		 * Says the reader has seen the Direct messages page, and clears the
		 * badge without waiting for the server to agree -- they have seen
		 * them, so the badge is already wrong.
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

		/** Reads the count. Failure is silent: a badge is not worth a toast. */
		async fetchUnreadNotifications() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/notifications/unread_count'))
				this.setUnreadNotifications(Number(data?.count) || 0)
			} catch (error) {
				logger.error('Failed to read the unread notification count', { error })
			}
		},

		/**
		 * Reads where the reader had got to, as the server remembers it.
		 *
		 * Asked when the notifications page opens, so that the line between
		 * what is new and what was already seen is drawn where every client
		 * agrees it is. A failure answers 0 — no line at all — rather than
		 * guessing, and says nothing: the list itself is still readable.
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
		 * Marks everything up to `lastReadId` as read, and clears the badge
		 * straight away rather than waiting for the server to agree — the
		 * reader has seen the notifications, so the badge is already wrong.
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
				showError(t('social', 'Could not save your place in the notifications'))
				logger.error('Failed to move the notifications marker', { error })
				// put back whatever the server actually thinks
				this.fetchUnreadNotifications()
			}
		},

		/**
		 * Marks everything read, whatever the page is filtered to.
		 *
		 * The marker is "read up to", so what it needs is the id of the newest
		 * notification there is -- not the newest of the kind the reader
		 * happens to be looking at. Filtered to Mentions, the newest mention
		 * can be older than a dozen favourites, and moving the marker there
		 * would leave the badge up over the very activities the reader had
		 * just said they were done with. So the newest is asked for
		 * unfiltered, and only then committed.
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
				// a badge over an empty list: there is nothing to mark, and the
				// count was wrong rather than the marker
				this.setUnreadNotifications(0)

				return '0'
			}

			await this.markNotificationsRead(newest)

			return this.lastReadId
		},
	},
})
