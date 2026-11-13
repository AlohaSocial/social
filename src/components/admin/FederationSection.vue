<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<p class="federation__counts">
			{{ n('social', '%n delivery waiting to be sent.', '%n deliveries waiting to be sent.', federation.waiting) }}
			<template v-if="federation.running > 0">
				{{ n('social', '%n is being sent right now.', '%n are being sent right now.', federation.running) }}
			</template>
		</p>

		<NcNoteCard v-if="federation.failing === 0" type="success">
			{{ t('social', 'Nothing is failing to deliver.') }}
		</NcNoteCard>
		<template v-else>
			<NcNoteCard :type="federation.atRisk > 0 ? 'warning' : 'info'">
				{{ n('social', '%n delivery has failed at least once.', '%n deliveries have failed at least once.', federation.failing) }}
				<template v-if="federation.truncated">
					{{ t('social', '(only the first few hundred were counted)') }}
				</template>
				<strong v-if="federation.atRisk > 0">
					{{ n('social', '%n of them is close to being given up on.', '%n of them are close to being given up on.', federation.atRisk) }}
				</strong>
				{{ t('social', 'A delivery is abandoned after {attempts} attempts.', { attempts: federation.maxTries }) }}
				<template v-if="stuckFor !== ''">
					{{ t('social', 'The longest-failing one was last tried {ago}.', { ago: stuckFor }) }}
				</template>
			</NcNoteCard>

			<div class="social-admin__scroll">
				<table class="social-admin__table">
					<thead>
						<tr>
							<th>{{ t('social', 'Instance') }}</th>
							<th>{{ t('social', 'Waiting deliveries') }}</th>
							<th>{{ t('social', 'Most attempts so far') }}</th>
							<th>{{ t('social', 'Last attempt') }}</th>
						</tr>
					</thead>
					<!-- an instance name is a string a peer chose; it is
					     interpolated, never `v-html` -->
					<tbody>
						<tr v-for="instance in federation.instances" :key="instance.host">
							<td>{{ instance.host }}</td>
							<td>{{ instance.requests }}</td>
							<td>{{ instance.tries }} / {{ federation.maxTries }}</td>
							<td>{{ lastAttempt(instance.last) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>

		<NcNoteCard v-if="federation.abandoned === 0" type="success">
			{{ n('social', 'Nothing has been given up on in the last %n day.', 'Nothing has been given up on in the last %n days.', federation.retentionDays) }}
		</NcNoteCard>
		<template v-else>
			<h4>{{ t('social', 'Given up on') }}</h4>
			<NcNoteCard type="error">
				<strong>
					{{ n('social', '%n delivery was given up on: that server never got it.', '%n deliveries were given up on: those servers never got them.', federation.abandoned) }}
				</strong>
				<template v-if="federation.abandonedTruncated">
					{{ t('social', '(only the first few hundred were counted)') }}
				</template>
				{{ n('social', 'Counted over the last %n day, which is how long a finished delivery is kept.', 'Counted over the last %n days, which is how long a finished delivery is kept.', federation.retentionDays) }}
			</NcNoteCard>

			<div class="social-admin__scroll">
				<table class="social-admin__table">
					<thead>
						<tr>
							<th>{{ t('social', 'Instance') }}</th>
							<th>{{ t('social', 'Deliveries given up on') }}</th>
							<th>{{ t('social', 'Most attempts so far') }}</th>
							<th>{{ t('social', 'Last attempt') }}</th>
							<th v-if="administrator">
								<span class="hidden-visually">{{ t('social', 'Actions') }}</span>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="instance in federation.givenUp" :key="instance.host">
							<td>{{ instance.host }}</td>
							<td>{{ instance.requests }}</td>
							<td>{{ instance.tries }} / {{ federation.maxTries }}</td>
							<td>{{ lastAttempt(instance.last) }}</td>
							<td v-if="administrator">
								<span v-if="instance.host in retried" class="federation__retried">
									{{ n('social', '%n delivery queued again', '%n deliveries queued again', retried[instance.host]) }}
								</span>
								<NcButton
									v-else
									size="small"
									:aria-label="t('social', 'Retry deliveries to {instance}', { instance: instance.host })"
									:disabled="retrying !== ''"
									@click="retry(instance.host)">
									<template v-if="retrying === instance.host" #icon>
										<NcLoadingIcon :size="20" />
									</template>
									{{ t('social', 'Retry deliveries') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<p class="social-admin__hint">
				{{ administrator ? t('social', 'Once the reason is fixed, "Retry deliveries" gives every failed delivery to that instance the full run of attempts again.') : t('social', 'Once the reason is fixed, an administrator can queue them again.') }}
			</p>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { federationRetryUrl } from '../../services/adminApi.js'
import { showError, showSuccess } from '../../services/toast.js'
import { errorOf, errorSentence } from '../../utils/serverError.js'

/**
 * What the outbound queue is doing.
 *
 * The summary arrives with the page: it is one pass over the queue. The one
 * thing it writes is the Retry deliveries button beside an instance given up
 * on, for an administrator, which does what `occ social:queue:retry
 * --instance` does.
 */
export default {
	name: 'FederationSection',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** `FederationHealthService::summary()`, as initial state */
		federation: {
			type: Object,
			required: true,
		},

		/** whether the viewer administers the server, which retrying needs */
		administrator: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			/** the host being retried, or '' */
			retrying: '',
			/** @type {Record<string, number>} how many deliveries each retried host had queued again */
			retried: {},
		}
	},

	computed: {
		/**
		 * How long the queue has been stuck, said the way somebody reads it.
		 *
		 * An hour is a peer rebooting; a week is a delivery that is never
		 * going to happen, and the two want different reactions out of an
		 * administrator. An exact timestamp answers neither question without
		 * arithmetic, so this is a span and the table below keeps the dates.
		 *
		 * @return {string} empty when nothing is failing yet
		 */
		stuckFor() {
			const since = Number(this.federation.stuckSince || 0)
			if (since <= 0) {
				return ''
			}

			const seconds = Math.max(0, Math.floor(Date.now() / 1000) - since)
			if (seconds < 3600) {
				return n('social', '%n minute ago', '%n minutes ago', Math.max(1, Math.floor(seconds / 60)))
			}

			if (seconds < 86400) {
				return n('social', '%n hour ago', '%n hours ago', Math.floor(seconds / 3600))
			}

			return n('social', '%n day ago', '%n days ago', Math.floor(seconds / 86400))
		},
	},

	methods: {
		t,
		n,

		/**
		 * Queues every failed delivery to one instance again.
		 *
		 * @param {string} host the instance
		 * @return {Promise<void>}
		 */
		async retry(host) {
			this.retrying = host
			try {
				const { data } = await axios.post(federationRetryUrl(), { instance: host })
				this.retried = { ...this.retried, [host]: data.queued }
				showSuccess(data.limited
					? n('social', '%n delivery queued again; press again for the rest.', '%n deliveries queued again; press again for the rest.', data.queued)
					: n('social', '%n delivery queued again.', '%n deliveries queued again.', data.queued))
			} catch (error) {
				showError(error?.response?.status === 429
					? errorSentence('rate-limited')
					: errorOf(error, t('social', 'Could not queue the deliveries again')))
			} finally {
				this.retrying = ''
			}
		},

		/**
		 * @param {number} last seconds since the epoch, or 0 for never
		 * @return {string} the moment, in UTC to the minute
		 */
		lastAttempt(last) {
			return last > 0
				? new Date(last * 1000).toISOString().slice(0, 16).replace('T', ' ')
				: t('social', 'never')
		},
	},
}
</script>

<style lang="scss" scoped>
.federation__counts {
	margin-block-end: calc(var(--default-grid-baseline) * 2);
}

.federation__retried {
	color: var(--color-text-maxcontrast);
}
</style>
