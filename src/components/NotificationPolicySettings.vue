<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- the heading and the id other pages link to (#notification-policy)
	     are the settings section's -->
	<div class="policy-settings" :aria-busy="saving !== '' ? 'true' : undefined">
		<p class="policy-settings__hint">
			{{ t('social', 'Held people can still write to you. Their posts, likes and follows wait in Activities until you look; they do not ring, push or mail.') }}
		</p>

		<p v-if="loading" class="policy-settings__hint">
			{{ t('social', 'Loading…') }}
		</p>
		<template v-else>
			<fieldset
				v-for="question in questions"
				:key="question.key"
				class="policy-settings__row">
				<legend class="policy-settings__legend">
					{{ question.label }}
				</legend>
				<div class="policy-settings__choices">
					<!-- a click on the choice already shown still counts when
					     an app set `drop`, which is shown as holding: a checked
					     radio sends no change event of its own -->
					<NcCheckboxRadioSwitch
						v-for="choice in choices"
						:key="choice.value"
						type="radio"
						:name="'social-policy-' + question.key"
						:value="choice.value"
						:modelValue="shown(question.key)"
						:disabled="saving !== ''"
						@click="replaceDrop(question.key, choice.value)"
						@update:modelValue="decide(question.key, $event)">
						{{ choice.label }}
					</NcCheckboxRadioSwitch>
				</div>
				<p v-if="confirmed[question.key] === 'drop'" class="policy-settings__note">
					{{ t('social', 'An app set this to discard; choosing here replaces it') }}
				</p>
			</fieldset>
		</template>

		<h5 class="policy-settings__subheading">
			{{ t('social', 'Always allowed') }}
		</h5>
		<p v-if="allowedLoading" class="policy-settings__hint">
			{{ t('social', 'Loading…') }}
		</p>
		<p v-else-if="allowed.length === 0" class="policy-settings__hint policy-settings__empty">
			{{ t('social', 'Nobody yet. People you accept from your requests appear here.') }}
		</p>
		<ul v-else class="policy-settings__allowed">
			<li v-for="account in allowed" :key="account.id" class="policy-settings__person">
				<ActorAvatar :actor="account" :size="32" />
				<router-link
					class="policy-settings__who"
					:to="{ name: 'profile', params: { account: account.acct } }">
					<span class="policy-settings__name">{{ account.display_name || account.username || account.acct }}</span>
					<span class="policy-settings__acct">{{ account.acct }}</span>
				</router-link>
				<NcButton
					variant="tertiary"
					:disabled="stopping.includes(account.id)"
					@click="stopAllowing(account)">
					{{ t('social', 'Stop allowing') }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { mapStores } from 'pinia'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import ActorAvatar from './ActorAvatar.vue'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'
import { useNotificationsStore } from '../store/notifications.js'

/** The five keys, in the order Mastodon's own settings list them. */
const QUESTIONS = ['for_not_following', 'for_not_followers', 'for_new_accounts', 'for_private_mentions', 'for_limited_accounts']

/** What the server may answer for a key; `drop` is only ever set by an app. */
const VALUES = ['accept', 'filter', 'drop']

const POLICY_URL = 'apps/social/api/v2/notifications/policy'

/**
 * Who may reach the reader's notifications: the second part of Settings →
 * Notifications.
 *
 * Each key is answered **Allow** (`accept`) or **Hold for review**
 * (`filter`). `drop` is left to the API: a key an app set to it is shown as
 * holding, with a note, and any choice made here replaces it, so a save
 * from this page never writes `drop`. Every choice is saved as it is made,
 * one key at a time, so a key this page does not know is never touched.
 *
 * Under the rows, the senders accepted from requests — always allowed until
 * the reader stops allowing them, which hands them back to the policy.
 */
export default {
	name: 'NotificationPolicySettings',

	components: {
		ActorAvatar,
		NcButton,
		NcCheckboxRadioSwitch,
	},

	data() {
		return {
			loading: true,
			/** the key being saved, '' while nothing is */
			saving: '',
			/** @type {Record<string, string>} what the server last confirmed, `drop` included */
			confirmed: /** @type {Record<string, string>} */ (Object.fromEntries(QUESTIONS.map((key) => [key, 'accept']))),
			/** @type {Record<string, string>} a choice on its way to the server */
			pending: {},
			allowedLoading: true,
			/** @type {Array<object>} the always-allowed accounts */
			allowed: [],
			/** @type {string[]} ids of the accounts being removed from the list */
			stopping: [],
		}
	},

	computed: {
		...mapStores(useNotificationsStore),

		/** @return {Array<{key: string, label: string}>} */
		questions() {
			return [
				{ key: 'for_not_following', label: t('social', 'People you don\'t follow') },
				{ key: 'for_not_followers', label: t('social', 'People who don\'t follow you') },
				{ key: 'for_new_accounts', label: t('social', 'New accounts (less than 30 days old)') },
				{ key: 'for_private_mentions', label: t('social', 'Private mentions you didn\'t ask for') },
				{ key: 'for_limited_accounts', label: t('social', 'Accounts this server limited') },
			]
		},

		/** @return {Array<{value: string, label: string}>} */
		choices() {
			return [
				{ value: 'accept', label: t('social', 'Allow') },
				{ value: 'filter', label: t('social', 'Hold for review') },
			]
		},
	},

	mounted() {
		this.load()
		this.loadAllowed()
	},

	methods: {
		t,

		/**
		 * @param {string} key which question
		 * @return {string} the choice drawn as picked; `drop` is drawn as holding
		 */
		shown(key) {
			const value = this.pending[key] ?? this.confirmed[key]

			return value === 'drop' ? 'filter' : value
		},

		/** @return {Promise<void>} */
		async load() {
			try {
				const { data } = await axios.get(generateUrl(POLICY_URL))
				this.accept(data)
			} catch (error) {
				logger.debug('Could not read the notification policy', { error })
			} finally {
				this.loading = false
			}
		},

		/** @return {Promise<void>} */
		async loadAllowed() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/social/notifications/allowed'))
				this.allowed = Array.isArray(data) ? data : []
			} catch (error) {
				logger.debug('Could not read the always-allowed accounts', { error })
			} finally {
				this.allowedLoading = false
			}
		},

		/**
		 * Takes the server's answer as the confirmed state.
		 *
		 * @param {object} answer the policy entity
		 */
		accept(answer) {
			/** @type {Record<string, string>} */
			const next = {}
			for (const key of QUESTIONS) {
				next[key] = VALUES.includes(answer?.[key]) ? answer[key] : 'accept'
			}
			this.confirmed = next
		},

		/**
		 * A click on Hold for review where `drop` is shown as holding.
		 *
		 * @param {string} key which question
		 * @param {string} value the choice clicked
		 */
		replaceDrop(key, value) {
			if (value === 'filter' && this.confirmed[key] === 'drop') {
				this.decide(key, value)
			}
		},

		/**
		 * Saves one key. Only `accept` and `filter` are ever sent.
		 *
		 * @param {string} key which question
		 * @param {string} value the choice picked
		 * @return {Promise<void>}
		 */
		async decide(key, value) {
			if (!['accept', 'filter'].includes(value) || this.confirmed[key] === value || this.saving !== '') {
				return
			}
			this.pending = { ...this.pending, [key]: value }
			this.saving = key
			try {
				const { data } = await axios.patch(generateUrl(POLICY_URL), { [key]: value })
				this.accept(data)
				// the server retires the one-time notice with the first save
				this.notificationsStore.setPolicyNotice(false)
			} catch (error) {
				logger.error('Could not save the notification policy', { error })
				showError(error?.response?.data?.error || t('social', 'Could not save that setting'))
			} finally {
				const rest = { ...this.pending }
				delete rest[key]
				this.pending = rest
				this.saving = ''
			}
		},

		/**
		 * Hands an account back to the policy.
		 *
		 * @param {object} account the account entity
		 * @return {Promise<void>}
		 */
		async stopAllowing(account) {
			this.stopping = [...this.stopping, account.id]
			try {
				await axios.delete(generateUrl('apps/social/api/v1/social/notifications/allowed/{id}', { id: account.id }))
				this.allowed = this.allowed.filter((one) => one.id !== account.id)
			} catch (error) {
				logger.error('Could not stop allowing an account', { error })
				showError(error?.response?.data?.error || t('social', 'Could not save that setting'))
			} finally {
				this.stopping = this.stopping.filter((id) => id !== account.id)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.policy-settings {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);

	&__subheading {
		margin: calc(var(--default-grid-baseline) * 2) 0 0;
		font-size: 14px;
		font-weight: bold;
	}

	&__hint,
	&__note {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	&__row {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 1);
		min-width: 0;
		margin: 0;
		padding: 0;
		border: 0;
	}

	&__legend {
		margin: 0 0 4px;
		padding: 0;
		font-weight: 600;
	}

	&__choices {
		display: flex;
		flex-wrap: wrap;
		gap: 0 calc(var(--default-grid-baseline) * 4);
	}

	&__allowed {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 1);
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__person {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		min-height: 44px;
	}

	&__who {
		display: flex;
		flex: 1 1 auto;
		flex-direction: column;
		min-width: 0;
	}

	&__name,
	&__acct {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__acct {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}
}
</style>
