<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="policy-settings" :aria-busy="saving ? 'true' : undefined">
		<p v-if="loading" class="policy-settings__hint">
			{{ t('social', 'Loading …') }}
		</p>
		<template v-else>
			<p class="policy-settings__lede">
				{{ t('social', 'Five questions, each about people you have no relationship with. Holding what they send puts the person in Requests, at the top of Activities, where you decide about them once rather than about every notification they send.') }}
			</p>
			<fieldset
				v-for="question in questions"
				:key="question.key"
				class="policy-settings__group">
				<legend class="policy-settings__legend">
					{{ question.label }}
				</legend>
				<NcCheckboxRadioSwitch
					v-for="choice in choices"
					:key="choice.value"
					type="radio"
					:name="'social-policy-' + question.key"
					:value="choice.value"
					:modelValue="policy[question.key]"
					:disabled="saving"
					@update:modelValue="decide(question.key, $event)">
					{{ choice.label }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<p class="policy-settings__note">
				{{ t('social', 'On this server "Do not notify me" works like holding: the notification is written before anybody decides about it, so the sender waits in Requests as well — which is also what lets a mistake be undone.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'

/** The five decisions, in the order Mastodon's own settings list them. */
const QUESTIONS = ['for_not_following', 'for_not_followers', 'for_new_accounts', 'for_private_mentions', 'for_limited_accounts']

/**
 * Who may reach the notifications at all: the policy the server has had
 * since the requests inbox existed, which until now only a phone client
 * could change. Every change is saved as it is made, one key at a time, so
 * a client from a newer Mastodon that knows a sixth question loses nothing.
 */
export default {
	name: 'NotificationPolicySettings',

	components: {
		NcCheckboxRadioSwitch,
	},

	data() {
		return {
			loading: true,
			saving: false,
			/** @type {Record<string, string>} what the page shows */
			policy: /** @type {Record<string, string>} */ (Object.fromEntries(QUESTIONS.map((key) => [key, 'accept']))),
			/** @type {Record<string, string>} what the server last confirmed */
			confirmed: /** @type {Record<string, string>} */ (Object.fromEntries(QUESTIONS.map((key) => [key, 'accept']))),
		}
	},

	computed: {
		/** @return {Array<{key: string, label: string}>} */
		questions() {
			return [
				{ key: 'for_not_following', label: t('social', 'People you do not follow') },
				{ key: 'for_not_followers', label: t('social', 'People who do not follow you') },
				{ key: 'for_new_accounts', label: t('social', 'Accounts less than a month old') },
				{ key: 'for_private_mentions', label: t('social', 'Private mentions you did not start') },
				{ key: 'for_limited_accounts', label: t('social', 'Accounts a moderator has limited') },
			]
		},

		/** @return {Array<{value: string, label: string}>} */
		choices() {
			return [
				{ value: 'accept', label: t('social', 'Notify me') },
				{ value: 'filter', label: t('social', 'Hold them for me to look at') },
				{ value: 'drop', label: t('social', 'Do not notify me') },
			]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/** @return {Promise<void>} */
		async load() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v2/notifications/policy'))
				this.accept(data)
			} catch (error) {
				logger.debug('Could not read the notification policy', { error })
			} finally {
				this.loading = false
			}
		},

		/**
		 * Takes the server's answer as both the page and the confirmed state.
		 *
		 * @param {object} answer the policy entity
		 */
		accept(answer) {
			/** @type {Record<string, string>} */
			const next = {}
			for (const key of QUESTIONS) {
				next[key] = ['accept', 'filter', 'drop'].includes(answer?.[key]) ? answer[key] : 'accept'
			}
			this.policy = next
			this.confirmed = { ...next }
		},

		/**
		 * @param {string} key which question
		 * @param {string} value the decision picked
		 * @return {Promise<void>}
		 */
		async decide(key, value) {
			if (this.policy[key] === value) {
				return
			}
			this.policy = { ...this.policy, [key]: value }
			this.saving = true
			try {
				const { data } = await axios.patch(generateUrl('apps/social/api/v2/notifications/policy'), { [key]: value })
				this.accept(data)
			} catch (error) {
				logger.error('Could not save the notification policy', { error })
				showError(error?.response?.data?.error || t('social', 'Could not save that setting'))
				this.policy = { ...this.confirmed }
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.policy-settings {
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__hint,
	&__lede,
	&__note {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__group {
		margin: 0;
		padding: 0;
		border: 0;
	}

	&__legend {
		margin-bottom: 4px;
		font-weight: 600;
	}
}
</style>
