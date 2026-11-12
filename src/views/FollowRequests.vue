<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__follow-requests">
		<PageHeader :title="t('social', 'Follow requests')" clearNavigation />
		<!-- the empty state waits for the last row to finish collapsing (the
		     delay is on .empty-enter-active) so the two do not cross -->
		<transition name="empty">
			<NcEmptyContent
				v-if="!loading && !loadFailed && cursor === '' && requests.length === 0"
				:name="t('social', 'No pending follow requests')">
				<template #icon>
					<AccountClock />
				</template>
				<!-- the sentence names a switch, so it links to it: this page
				     is empty for two quite different reasons, and one of them
				     is that the switch is off -->
				<template #description>
					{{ t('social', 'When your account is locked, people asking to follow you show up here.') }}
					<router-link :to="{ name: 'settings', hash: '#account' }">
						{{ t('social', 'Change that in your account settings.') }}
					</router-link>
				</template>
			</NcEmptyContent>
		</transition>
		<!-- keyed on the account id, never on the index: on removal an index
		     key makes Vue patch the row above into the one below and drop the
		     last row, so the wrong row would collapse -->
		<transition-group name="collapse" tag="div" class="follow-request-list">
			<div v-for="account in requests" :key="account.id" class="follow-request">
				<div class="follow-request__user">
					<ActorAvatar :actor="account" />
					<router-link :to="{ name: 'profile', params: { account: account.acct } }">
						<span class="follow-request__name">{{ account.display_name || account.username }}</span>
						<span class="follow-request__acct">{{ account.acct }}</span>
					</router-link>
				</div>
				<div class="follow-request__actions">
					<NcButton
						:disabled="busy.includes(account.id)"
						variant="primary"
						:aria-label="t('social', 'Accept')"
						@click="decide(account, true)">
						<template #icon>
							<Check :size="20" />
						</template>
						{{ t('social', 'Accept') }}
					</NcButton>
					<NcButton
						:disabled="busy.includes(account.id)"
						:aria-label="t('social', 'Reject')"
						@click="decide(account, false)">
						<template #icon>
							<Close :size="20" />
						</template>
						{{ t('social', 'Reject') }}
					</NcButton>
				</div>
			</div>
		</transition-group>
		<div v-if="loadFailed" class="loading-indicator" role="alert">
			<p>{{ t('social', 'Failed to load follow requests') }}</p>
			<NcButton @click="fetchRequests(cursor !== '')">
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>
		<div v-else-if="loading" class="loading-indicator" role="status">
			{{ t('social', 'Loading…') }}
		</div>
		<p v-else-if="cursor !== ''" class="follow-requests__more">
			<NcButton :disabled="loadingMore" @click="fetchRequests(true)">
				{{ loadingMore ? t('social', 'Loading…') : t('social', 'Load more') }}
			</NcButton>
		</p>
	</div>
</template>

<script>
import PageHeader from '../components/PageHeader.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '../services/toast.js'
import ActorAvatar from '../components/ActorAvatar.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AccountClock from 'vue-material-design-icons/AccountClock.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import logger from '../services/logger.js'
import { nextCursor } from '../utils/linkHeader.js'

/** How many requests one page asks for, Mastodon's default. */
const PAGE_SIZE = 40

export default {
	name: 'FollowRequests',
	components: {
		PageHeader,
		ActorAvatar,
		NcButton,
		NcEmptyContent,
		AccountClock,
		Check,
		Close,
	},

	data() {
		return {
			/** @type {import('../types/Mastodon.js').Account[]} */
			requests: [],
			busy: [],
			loading: true,
			loadingMore: false,
			loadFailed: false,
			/** the `max_id` of the next page, '' once the server says there is none */
			cursor: '',
		}
	},

	async mounted() {
		await this.fetchRequests()
	},

	methods: {
		/**
		 * The pending requests, a page at a time.
		 *
		 * The next page is continued from the cursor in the server's `Link`
		 * header, not from the last row here: the cursor is the follow
		 * request's, not the account's, and the rows answered since the page
		 * arrived have left the list without moving it.
		 *
		 * @param {boolean} more whether this is the reader asking for the page
		 *                       after the one they have
		 */
		async fetchRequests(more = false) {
			if (more && (this.loadingMore || this.cursor === '')) {
				return
			}

			if (more) {
				this.loadingMore = true
			} else {
				this.loading = true
			}

			this.loadFailed = false
			const params = { limit: PAGE_SIZE }
			if (more) {
				params.max_id = this.cursor
			}

			try {
				const { data, headers } = await axios.get(
					generateUrl('apps/social/api/v1/follow_requests'),
					{ params },
				)
				const page = Array.isArray(data) ? data : []
				const known = new Set(this.requests.map((request) => request.id))
				this.requests = more
					? [...this.requests, ...page.filter((request) => !known.has(request.id))]
					: page
				this.cursor = nextCursor(headers)
			} catch (error) {
				logger.error('Failed to fetch follow requests', { error, more })
				this.loadFailed = true
			} finally {
				this.loading = false
				this.loadingMore = false
			}
		},

		/**
		 * @param {import('../types/Mastodon.js').Account} account the requester
		 * @param {boolean} accept accept or reject
		 */
		async decide(account, accept) {
			this.busy.push(account.id)
			const action = accept ? 'authorize' : 'reject'
			try {
				await axios.post(generateUrl(`apps/social/api/v1/follow_requests/${account.id}/${action}`))
				this.requests = this.requests.filter((request) => request.id !== account.id)
			} catch (error) {
				logger.error(`Failed to ${action} follow request`, { error })
				showError(accept
					? t('social', 'Failed to accept the follow request')
					: t('social', 'Failed to reject the follow request'))
			} finally {
				this.busy = this.busy.filter((id) => id !== account.id)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.social__follow-requests {
	max-width: var(--social-column);
	margin: 15px auto;
	padding: 0 10px;

}

.follow-request {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);

	&__user {
		display: flex;
		align-items: center;
		gap: 12px;
		min-width: 0;

		a {
			display: flex;
			flex-direction: column;
			min-width: 0;
		}
	}

	&__name {
		font-weight: bold;
	}

	&__acct {
		color: var(--color-text-maxcontrast);
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__actions {
		display: flex;
		gap: 8px;
		flex-shrink: 0;
	}
}

.loading-indicator {
	text-align: center;
	padding: 20px;
}

.follow-requests__more {
	display: flex;
	justify-content: center;
	padding: 20px 0;
}

/**
 * An answered request collapses out of the list rather than blinking away,
 * so the reader sees which row they just answered and the rows below close
 * the gap instead of jumping into it.
 *
 * Same motion as the shared `list` transition in App.vue — .2s ease for the
 * fade and the shift — with the height taken out over .3s ease-out, the way
 * the welcome banner in Timeline.vue collapses. The max-height is headroom
 * for one row, not a measurement: it only has to be larger than a row.
 */
.collapse-enter-active,
.collapse-leave-active {
	overflow: hidden;
	max-height: 120px;
	transition: opacity .2s ease, transform .2s ease, max-height .3s ease-out, padding .3s ease-out;
}

.collapse-enter-from,
.collapse-leave-to {
	opacity: 0;
	transform: translateY(-6px);
	max-height: 0;
	padding-top: 0;
	padding-bottom: 0;
}

/* an inserted row pushes the others down instead of displacing them */
.collapse-move {
	transition: transform .2s ease;
}

/* "nothing pending" arrives as the last row finishes collapsing, not on top of it */
.empty-enter-active {
	transition: opacity .2s ease .25s, transform .2s ease .25s;
}

.empty-leave-active {
	transition: opacity .15s ease;
}

.empty-enter-from,
.empty-leave-to {
	opacity: 0;
	transform: translateY(-6px);
}

@media (prefers-reduced-motion: reduce) {
	.collapse-enter-active,
	.collapse-leave-active,
	.collapse-move,
	.empty-enter-active,
	.empty-leave-active {
		transition: none;
	}
}
</style>
