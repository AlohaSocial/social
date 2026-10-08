<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<p class="social-admin__hint">
			{{ t('social', 'What is shared is public posts and nothing else: a followers-only post has an audience that was chosen, and a relay is the opposite of a chosen audience. Nothing a person on this server writes privately ever reaches one.') }}
		</p>

		<div class="relays__add">
			<NcTextField
				v-model="address"
				class="relays__field"
				:label="t('social', 'The relay\'s address')"
				placeholder="https://relay.example/actor"
				:disabled="busy"
				@keydown.enter="subscribe" />
			<NcButton
				variant="primary"
				:disabled="busy || address.trim() === ''"
				@click="subscribe">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Subscribe') }}
			</NcButton>
		</div>
		<p class="social-admin__hint">
			{{ t('social', 'Relays publish this address on their own front page; it usually ends in /actor or /inbox.') }}
		</p>

		<p v-if="loading" class="social-admin__hint">
			{{ t('social', 'Loading …') }}
		</p>
		<p v-else-if="relays.length === 0" class="social-admin__hint">
			{{ t('social', 'This server is not subscribed to any relay.') }}
		</p>
		<ul v-else class="relays__list">
			<li v-for="relay in relays" :key="relay.id" class="relays__item">
				<div class="relays__who">
					<span class="relays__host">{{ relay.host || relay.actor_id }}</span>
					<span class="relays__status" :class="'relays__status--' + relay.status">
						{{ statusOf(relay) }}
					</span>
				</div>
				<p v-if="relay.error" class="relays__error">
					{{ relay.error }}
				</p>
				<NcButton :disabled="busy" @click="unsubscribe(relay)">
					{{ t('social', 'Unsubscribe') }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { relaysUrl } from '../../services/adminApi.js'
import { showError, showSuccess } from '../../services/toast.js'

/**
 * The relays this instance subscribes to.
 *
 * A decision about the server rather than about a report, which is why it sits
 * with the Server card and is not something a moderation delegate reaches: a
 * relay changes what everybody's federated timeline holds and where every
 * public post written here is sent.
 *
 * A fresh subscription reads `pending` — the Follow has gone out and the relay
 * answers in its own time, which for some relays means when a human has looked
 * at it — so the list is what says whether it worked, and the page does not
 * pretend otherwise.
 */
export default {
	name: 'RelaysSection',

	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
	},

	data() {
		return {
			/** @type {object[]} one row per subscription */
			relays: [],
			address: '',
			loading: true,
			busy: false,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * @param {object} relay one row
		 * @return {string} what its status means, in words
		 */
		statusOf(relay) {
			if (relay.status === 'accepted') {
				return t('social', 'Subscribed')
			}
			if (relay.status === 'rejected') {
				return t('social', 'Refused')
			}

			return t('social', 'Waiting for an answer')
		},

		/** @return {Promise<void>} */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(relaysUrl())
				this.relays = Array.isArray(data) ? data : []
			} catch {
				showError(t('social', 'Could not load the relays'))
			} finally {
				this.loading = false
			}
		},

		/** @return {Promise<void>} */
		async subscribe() {
			const address = this.address.trim()
			if (address === '' || this.busy) {
				return
			}

			this.busy = true
			try {
				await axios.post(relaysUrl(), { address })
				this.address = ''
				// re-read rather than push the answer: a relay that accepted
				// while the request was in flight is already `accepted` here,
				// and a row showing `pending` for ever is the one thing this
				// list must not do
				await this.load()
				showSuccess(t('social', 'The relay has been asked'))
			} catch (error) {
				showError(error?.response?.data?.error || t('social', 'Could not subscribe to that relay'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} relay the subscription to end
		 * @return {Promise<void>}
		 */
		async unsubscribe(relay) {
			this.busy = true
			try {
				await axios.delete(relaysUrl('/' + relay.id))
				this.relays = this.relays.filter((one) => one.id !== relay.id)
			} catch {
				showError(t('social', 'Could not unsubscribe from that relay'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.relays__add {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	align-items: flex-end;
	margin-block-end: 4px;
}

.relays__field {
	max-width: 420px;
}

.relays__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-block-start: 8px;
}

.relays__item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.relays__who {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	align-items: baseline;
}

.relays__host {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.relays__status {
	color: var(--color-text-maxcontrast);
}

.relays__status--accepted {
	color: var(--color-success);
}

.relays__status--rejected {
	color: var(--color-error);
}

.relays__error {
	color: var(--color-text-maxcontrast);
	margin-block: 4px 0;
	overflow-wrap: anywhere;
}
</style>
