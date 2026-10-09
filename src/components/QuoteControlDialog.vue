<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('social', 'Quotes of this post')"
		:open="true"
		size="normal"
		@update:open="$emit('close')">
		<h4 class="quotes__heading">
			{{ t('social', 'Who may quote it') }}
		</h4>
		<p class="quotes__hint">
			{{ t('social', 'This decides what happens from now on. It does not take back a quote somebody has already posted — use the list below for that.') }}
		</p>
		<NcCheckboxRadioSwitch
			v-for="choice in choices"
			:key="choice.value"
			:modelValue="policy"
			:value="choice.value"
			:disabled="saving"
			name="quote-policy"
			type="radio"
			@update:modelValue="setPolicy">
			{{ choice.label }}
		</NcCheckboxRadioSwitch>

		<h4 class="quotes__heading">
			{{ t('social', 'Who has quoted it') }}
		</h4>
		<p v-if="loading" class="quotes__hint">
			{{ t('social', 'Loading …') }}
		</p>
		<p v-else-if="quotes.length === 0" class="quotes__hint">
			{{ t('social', 'Nobody has quoted this post.') }}
		</p>
		<ul v-else class="quotes__list">
			<li v-for="quote in quotes" :key="quote.id" class="quotes__item">
				<span class="quotes__who">{{ quote.account?.acct }}</span>
				<p class="quotes__words">
					{{ excerpt(quote) }}
				</p>
				<NcButton :disabled="busy.includes(quote.nid)" @click="revoke(quote)">
					{{ t('social', 'Detach this quote') }}
				</NcButton>
			</li>
		</ul>
		<p class="quotes__hint">
			{{ t('social', 'Detaching tells the other server, which then shows the quote as withdrawn. The post itself is theirs and stays where it is.') }}
		</p>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { htmlToPlainText } from '../utils/plainText.js'

/**
 * An author's control over who quotes one of their posts.
 *
 * Two halves that are deliberately separate. The policy decides what happens
 * to requests that arrive **from now on**: a quote somebody has already posted
 * and other people have read is not undone by a switch being flipped. Taking
 * one back is the list, which says what it will do and tells the other server.
 */
export default {
	name: 'QuoteControlDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
	},

	props: {
		/** the post, by the id its own routes use */
		nid: {
			type: [Number, String],
			required: true,
		},

		/** what the post says its policy is, as `quote_approval.automatic` */
		approval: {
			type: Object,
			default: null,
		},
	},

	emits: ['close'],

	data() {
		return {
			policy: this.approval?.automatic?.[0] ?? 'public',
			quotes: [],
			loading: true,
			saving: false,
			busy: [],
		}
	},

	computed: {
		/** @return {Array<{value: string, label: string}>} */
		choices() {
			return [
				{ value: 'public', label: t('social', 'Anybody') },
				{ value: 'followers', label: t('social', 'People who follow me') },
				{ value: 'nobody', label: t('social', 'Nobody but me') },
			]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * @param {object} quote one quoting post
		 * @return {string} the first words of it
		 */
		excerpt(quote) {
			return htmlToPlainText(quote.content ?? '').trim().slice(0, 140)
		},

		/** @return {Promise<void>} */
		async load() {
			this.loading = true
			try {
				const url = generateUrl('apps/social/api/v1/statuses/{nid}/quotes', { nid: this.nid })
				const { data, headers } = await axios.get(url)
				this.quotes = Array.isArray(data) ? data : []
				// the quotes made elsewhere are being read: once more, a little later
				if (headers?.['x-social-filling'] === '1') {
					setTimeout(() => this.load(), 6000)
				}
			} catch (error) {
				logger.error('could not load the quotes of a post', { error })
				this.quotes = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} policy public, followers or nobody
		 * @return {Promise<void>}
		 */
		async setPolicy(policy) {
			const previous = this.policy
			this.policy = policy
			this.saving = true
			try {
				const url = generateUrl('apps/social/api/v1/statuses/{nid}/interaction_policy', { nid: this.nid })
				await axios.put(url, { quote_approval_policy: policy })
				showSuccess(t('social', 'Saved'))
			} catch (error) {
				logger.error('could not set a quote policy', { error })
				// back to what the server still holds: a radio showing a choice
				// that was not saved is worse than one that never moved
				this.policy = previous
				showError(t('social', 'Could not change who may quote this'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * @param {object} quote the quoting post to detach
		 * @return {Promise<void>}
		 */
		async revoke(quote) {
			this.busy.push(quote.nid)
			try {
				const path = 'apps/social/api/v1/statuses/{nid}/quotes/{quoting}/revoke'
				await axios.post(generateUrl(path, { nid: this.nid, quoting: quote.nid }))
				this.quotes = this.quotes.filter((one) => one.nid !== quote.nid)
			} catch (error) {
				logger.error('could not detach a quote', { error })
				showError(t('social', 'Could not detach that quote'))
			} finally {
				this.busy = this.busy.filter((nid) => nid !== quote.nid)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.quotes__heading {
	margin-block: 16px 4px;

	&:first-child {
		margin-block-start: 0;
	}
}

.quotes__hint {
	color: var(--color-text-maxcontrast);
	margin-block-end: 8px;
}

.quotes__list {
	display: flex;
	flex-direction: column;
	gap: 12px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.quotes__item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.quotes__who {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.quotes__words {
	color: var(--color-text-maxcontrast);
	margin-block: 4px 8px;
	overflow-wrap: anywhere;
}
</style>
