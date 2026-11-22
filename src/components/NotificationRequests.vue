<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__requests">
		<NcLoadingIcon v-if="loading" class="social__requests-loading" :size="32" />

		<EmptyContent
			v-else-if="requests.length === 0"
			:item="{
				illustration: 'quiet-timeline',
				title: t('social', 'Nothing is waiting'),
				description: t('social', 'When your settings hold an activity back, the person behind it shows up here.'),
			}" />

		<template v-else>
			<div class="social__requests-all">
				<NcButton :disabled="busy.length > 0" @click="acceptAll">
					{{ t('social', 'Show all of them') }}
				</NcButton>
				<NcButton :disabled="busy.length > 0" @click="dismissAll">
					{{ t('social', 'Dismiss all') }}
				</NcButton>
			</div>

			<transition-group name="collapse" tag="div" class="social__requests-list">
				<div v-for="request in requests" :key="request.id" class="request">
					<div class="request__who">
						<ActorAvatar :actor="request.account" />
						<router-link
							class="request__link"
							:to="{ name: 'profile', params: { account: request.account.acct } }">
							<span class="request__name">
								{{ request.account.display_name || request.account.username }}
							</span>
							<span class="request__acct">{{ request.account.acct }}</span>
						</router-link>
					</div>

					<p class="request__count">
						{{ n('social', '%n notification', '%n notifications', Number(request.notifications_count)) }}
					</p>

					<p v-if="excerpt(request)" class="request__excerpt">
						{{ excerpt(request) }}
					</p>

					<div class="request__actions">
						<NcButton
							variant="primary"
							:disabled="busy.includes(request.id)"
							@click="accept(request)">
							{{ t('social', 'Show these') }}
						</NcButton>
						<NcButton :disabled="busy.includes(request.id)" @click="dismiss(request)">
							{{ t('social', 'Dismiss') }}
						</NcButton>
					</div>
				</div>
			</transition-group>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ActorAvatar from './ActorAvatar.vue'
import EmptyContent from './EmptyContent.vue'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'
import { htmlToPlainText } from '../utils/plainText.js'

/**
 * The senders whose notifications the policy is holding, unfolded at the top
 * of Activities. One row per sender, as the API answers: accepting settles
 * everything that account has sent and will send.
 */
export default {
	name: 'NotificationRequests',

	components: {
		ActorAvatar,
		EmptyContent,
		NcButton,
		NcLoadingIcon,
	},

	/** `changed` carries how many senders are left, whenever the list changes. */
	emits: ['changed'],

	data() {
		return {
			requests: [],
			busy: [],
			loading: true,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		/**
		 * @param {object} request one row
		 * @return {string} the first words of their most recent post, if there is one
		 */
		excerpt(request) {
			const content = request.last_status?.content ?? ''

			return htmlToPlainText(content).trim().slice(0, 140)
		},

		/** @return {Promise<void>} */
		async load() {
			this.loading = true
			try {
				const url = generateUrl('apps/social/api/v1/notifications/requests')
				const { data } = await axios.get(url)
				this.requests = Array.isArray(data) ? data : []
				this.$emit('changed', this.requests.length)
			} catch (error) {
				logger.error('could not load the held notifications', { error })
				showError(t('social', 'Could not load what is waiting'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} request the sender to show
		 * @return {Promise<void>}
		 */
		async accept(request) {
			await this.act(request, 'accept')
		},

		/**
		 * @param {object} request the sender to stop being asked about
		 * @return {Promise<void>}
		 */
		async dismiss(request) {
			await this.act(request, 'dismiss')
		},

		/**
		 * @param {object} request the row acted on
		 * @param {string} what `accept` or `dismiss`
		 * @return {Promise<void>}
		 */
		async act(request, what) {
			this.busy.push(request.id)
			try {
				const path = 'apps/social/api/v1/notifications/requests/{id}/{what}'
				const url = generateUrl(path, { id: request.id, what })
				await axios.post(url)
				this.requests = this.requests.filter((one) => one.id !== request.id)
				this.$emit('changed', this.requests.length)
			} catch (error) {
				logger.error('could not decide about a sender', { error })
				showError(t('social', 'Could not do that'))
			} finally {
				this.busy = this.busy.filter((id) => id !== request.id)
			}
		},

		/** @return {Promise<void>} */
		async acceptAll() {
			await this.actAll('accept')
		},

		/** @return {Promise<void>} */
		async dismissAll() {
			await this.actAll('dismiss')
		},

		/**
		 * @param {string} what `accept` or `dismiss`
		 * @return {Promise<void>}
		 */
		async actAll(what) {
			// one bulk request, so a failure halfway cannot leave the page
			// disagreeing with the server about what was decided
			const ids = this.requests.map((one) => one.id)
			this.busy = ids
			try {
				const url = generateUrl('apps/social/api/v1/notifications/requests/{what}', { what })
				await axios.post(url, { id: ids })
				this.requests = []
				this.$emit('changed', 0)
			} catch (error) {
				logger.error('could not decide about every sender', { error })
				showError(t('social', 'Could not do that'))
			} finally {
				this.busy = []
			}
		},
	},
}
</script>

<style scoped lang="scss">
.social__requests-loading {
	margin-block: 32px;
}

.social__requests-all {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-block-end: 12px;
}

.social__requests-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.request {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.request__who {
	display: flex;
	align-items: center;
	gap: 8px;
}

.request__link {
	display: flex;
	flex-direction: column;
	text-decoration: none;
	color: inherit;
}

.request__name {
	font-weight: bold;
}

.request__acct,
.request__count,
.request__excerpt {
	color: var(--color-text-maxcontrast);
}

.request__count {
	margin-block: 8px 0;
}

.request__excerpt {
	margin-block: 4px 0;
	overflow-wrap: anywhere;
}

.request__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-block-start: 8px;
}

.collapse-enter-active,
.collapse-leave-active {
	transition: opacity var(--social-motion-base) var(--social-ease);
}

.collapse-enter-from,
.collapse-leave-to {
	opacity: 0;
}

// a reader who has asked their system for less movement gets none
@media (prefers-reduced-motion: reduce) {
	.collapse-enter-active,
	.collapse-leave-active,
	.collapse-move {
		transition: none;
	}
}
</style>
