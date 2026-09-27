<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDashboardWidget
		:items="items"
		:showMoreUrl="showMoreUrl"
		:showMoreLabel="title"
		:loading="state === 'loading'">
		<template #empty-content>
			<NcEmptyContent
				v-if="emptyContentMessage"
				:name="emptyContentMessage">
				<template #icon>
					<div :class="emptyContentIcon" />
				</template>
				<template #description>
					<div v-if="state === 'error'" class="connect-button">
						<a class="button" :href="appUrl">
							{{ t('social', 'Go to Social app') }}
						</a>
					</div>
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '../services/toast.js'
import { listen } from '@nextcloud/notify_push'
import NcDashboardWidget from '@nextcloud/vue/components/NcDashboardWidget'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { notificationSummary } from '../services/notifications.js'
import logger from '../services/logger.js'

/** Without notify_push the widget has to ask; once a minute is enough for a tile. */
const POLL_MS = 60 * 1000

/**
 * The longest wait between two asks while they keep failing. Each failure
 * doubles the wait up to this, so a server that is down for a restart is
 * asked again soon and one that is down for the night is not asked every
 * minute of it.
 */
const MAX_BACKOFF_MS = 16 * POLL_MS

/** A tile shows a handful of rows, so there is no point in keeping more. */
const MAX_ITEMS = 10

export default {
	name: 'Dashboard',

	components: {
		NcDashboardWidget,
		NcEmptyContent,
	},

	props: {
		title: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			notifications: [],
			showMoreUrl: generateUrl('/apps/social/timeline/notifications'),
			showMoreText: t('social', 'Social notifications'),
			/** the timeout of the next ask, while polling */
			timer: null,
			/** whether the widget asks on a timer, for want of notify_push */
			polling: false,
			/** how many asks in a row have failed */
			failures: 0,
			stopListening: null,
			state: 'loading',
			appUrl: generateUrl('/apps/social'),
		}
	},

	computed: {
		/** @return {object[]} */
		items() {
			return this.notifications.map((n) => {
				return {
					id: n.id,
					targetUrl: this.getNotificationTarget(n),
					avatarUrl: this.getAvatarUrl(n),
					avatarUsername: this.getActorName(n),
					overlayIconUrl: this.getNotificationTypeImage(n),
					mainText: this.getMainText(n),
					subText: this.getSubline(n),
				}
			})
		},

		/** @return {string} */
		emptyContentMessage() {
			if (this.state === 'error') {
				return t('social', 'Error getting Social notifications')
			} else if (this.state === 'ok') {
				return t('social', 'No Social notifications!')
			}
			return ''
		},

		/** @return {string} */
		emptyContentIcon() {
			if (this.state === 'error') {
				return 'icon-close'
			} else if (this.state === 'ok') {
				return 'icon-checkmark'
			}
			return 'icon-checkmark'
		},
	},

	beforeMount() {
		this.fetchNotifications()
	},

	mounted() {
		// with notify_push the server says when something arrived; without it
		// a slow poll keeps the tile current without hammering the instance
		// the next ask is timed from the end of the one before, which is still
		// in flight here, so it is the answer to that one that schedules it
		this.stopListening = listen('social_timeline', () => this.fetchNotifications())
		this.polling = !this.stopListening
	},

	beforeUnmount() {
		if (typeof this.stopListening === 'function') {
			this.stopListening()
		}
		this.stopPolling()
	},

	methods: {
		stopPolling() {
			this.polling = false
			if (this.timer !== null) {
				clearTimeout(this.timer)
				this.timer = null
			}
		},

		/** Asks again after a minute, or later the more asks in a row have failed. */
		scheduleNext() {
			if (!this.polling || this.timer !== null) {
				return
			}
			const delay = Math.min(POLL_MS * 2 ** this.failures, MAX_BACKOFF_MS)
			this.timer = setTimeout(() => {
				this.timer = null
				this.fetchNotifications()
			}, delay)
		},

		async fetchNotifications() {
			const url = generateUrl('apps/social/api/v1/notifications')

			try {
				const response = await axios.get(url)
				this.failures = 0
				if (response.data) {
					this.processNotifications(response.data)
					this.state = 'ok'
				} else {
					this.state = 'error'
				}
			} catch (error) {
				// a failed ask is not the end of the widget: the next one may
				// well succeed, and says so by putting the state back. Only the
				// first failure of a run is worth a toast.
				const first = this.failures === 0
				this.failures += 1
				this.state = 'error'
				if (error.response?.status && error.response.status >= 400) {
					if (first) {
						showError(t('social', 'Failed to get Social notifications'))
					}
				} else {
					logger.error('Failed to get the Social notifications', { error })
				}
			} finally {
				this.scheduleNext()
			}
		},

		/** @param {import('../types/Mastodon.js').Notification[]} newNotifications the page just fetched */
		processNotifications(newNotifications) {
			if (this.notifications.length === 0) {
				// first time, we take everything the server sent
				this.notifications = newNotifications.slice(0, MAX_ITEMS)
				return
			}
			// the API returns notifications newest first; prepend only the ones
			// we have not seen yet, identified by their id. The cut keeps a tab
			// left open for days from accumulating every notification it saw.
			const knownIds = new Set(this.notifications.map((n) => n.id))
			const toAdd = newNotifications.filter((n) => !knownIds.has(n.id))
			if (toAdd.length > 0) {
				this.notifications = toAdd.concat(this.notifications).slice(0, MAX_ITEMS)
			}
		},

		/** @param {import('../types/Mastodon.js').Notification} n the notification */
		getMainText(n) {
			return notificationSummary(n)
		},

		/** @param {import('../types/Mastodon.js').Notification} n the notification */
		getAvatarUrl(n) {
			return n.account.avatar
		},

		/** @param {import('../types/Mastodon.js').Notification} n the notification */
		getActorName(n) {
			return n.account.display_name
		},

		/** @param {import('../types/Mastodon.js').Notification} n the notification */
		getActorAccountName(n) {
			return n.account.acct
		},

		/** @param {import('../types/Mastodon.js').Notification} n the notification */
		getNotificationTarget(n) {
			if (n.type === 'follow') {
				return generateUrl('/apps/social/@' + this.getActorAccountName(n) + '/')
			}
			return this.showMoreUrl
		},

		/** @param {import('../types/Mastodon.js').Notification} n the notification */
		getSubline(n) {
			if (n.type === 'follow') {
				return this.getActorAccountName(n)
			}
			if (n.type === 'favourite') {
				return this.getActorAccountName(n)
			}
			return ''
		},

		/** @param {import('../types/Mastodon.js').Notification} n the notification */
		getNotificationTypeImage(n) {
			if (n.type === 'follow') {
				return generateUrl('/svg/social/add_user')
			}
			return ''
		},
	},
}
</script>

<style scoped lang="scss">
:deep(.connect-button) {
	margin-top: 10px;
}
</style>
