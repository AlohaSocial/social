<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- at the top of Activities while somebody is waiting; nothing at all otherwise -->
	<section v-if="requests.length > 0 || loadFailed" class="follow-requests" :aria-labelledby="headingId">
		<h2 :id="headingId" class="follow-requests__heading">
			{{ n('social', '%n person asks to follow you', '%n people ask to follow you', requests.length) }}
		</h2>
		<!-- keyed on the account id, never on the index: on removal an index
		     key makes Vue patch the row above into the one below -->
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
						@click="decide(account, true)">
						<template #icon>
							<Check :size="20" />
						</template>
						{{ t('social', 'Accept') }}
					</NcButton>
					<NcButton
						:disabled="busy.includes(account.id)"
						@click="decide(account, false)">
						<template #icon>
							<Close :size="20" />
						</template>
						{{ t('social', 'Reject') }}
					</NcButton>
				</div>
			</div>
		</transition-group>
		<div v-if="loadFailed" class="follow-requests__failed" role="alert">
			<p>{{ t('social', 'Failed to load follow requests') }}</p>
			<NcButton @click="fetchRequests(cursor !== '')">
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>
		<p v-else-if="cursor !== ''" class="follow-requests__more">
			<NcButton :disabled="loadingMore" @click="fetchRequests(true)">
				{{ loadingMore ? t('social', 'Loading…') : t('social', 'Load more') }}
			</NcButton>
		</p>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError } from '../services/toast.js'
import ActorAvatar from './ActorAvatar.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import logger from '../services/logger.js'
import { nextCursor } from '../utils/linkHeader.js'

/** How many requests one page asks for, Mastodon's default. */
const PAGE_SIZE = 40

export default {
	name: 'FollowRequests',
	components: {
		ActorAvatar,
		NcButton,
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

	computed: {
		/** @return {string} the id that names the section for screen readers */
		headingId() {
			return `follow-requests-${this.$.uid}`
		},
	},

	async mounted() {
		await this.fetchRequests()
	},

	methods: {
		t,
		n,

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
.follow-requests {
	max-width: var(--social-column);
	margin: 0 auto 16px;
	padding: 0 10px 8px;
	border-bottom: 1px solid var(--color-border);

	&__heading {
		margin: 8px 0 4px;
		font-size: 1.1em;
	}
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

.follow-requests__failed {
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
	transition: opacity var(--social-motion-base) var(--social-ease), transform var(--social-motion-base) var(--social-ease), max-height var(--social-motion-slow) var(--social-ease), padding var(--social-motion-slow) var(--social-ease);
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
	transition: transform var(--social-motion-base) var(--social-ease);
}

/* "nothing pending" arrives as the last row finishes collapsing, not on top of it */
.empty-enter-active {
	transition: opacity var(--social-motion-base) var(--social-ease) .25s, transform var(--social-motion-base) var(--social-ease) .25s;
}

.empty-leave-active {
	transition: opacity var(--social-motion-fast) var(--social-ease);
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
