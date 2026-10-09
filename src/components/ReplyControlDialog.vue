<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('social', 'Who can reply')"
		:open="true"
		size="small"
		@update:open="$emit('close')">
		<p class="replies__hint">
			{{ t('social', 'This decides what happens from now on. Replies already posted stay. You can always reply yourself.') }}
		</p>
		<NcCheckboxRadioSwitch
			v-for="choice in choices"
			:key="choice.value"
			:modelValue="policy"
			:value="choice.value"
			:disabled="saving"
			name="reply-policy"
			type="radio"
			@update:modelValue="setPolicy">
			{{ choice.label }}
		</NcCheckboxRadioSwitch>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { replyPolicies } from '../utils/replyPolicy.js'

/**
 * An author's control over who may reply to one of their posts, here and on
 * Bluesky alike, from now on.
 */
export default {
	name: 'ReplyControlDialog',

	components: {
		NcCheckboxRadioSwitch,
		NcDialog,
	},

	props: {
		/** the post, by the id its own routes use */
		nid: {
			type: [Number, String],
			required: true,
		},

		/** the post's `reply_policy` as it stands */
		replyPolicy: {
			type: String,
			default: 'everyone',
		},
	},

	emits: ['close', 'changed'],

	data() {
		return {
			policy: this.replyPolicy || 'everyone',
			saving: false,
		}
	},

	computed: {
		/** @return {Array<{value: string, label: string}>} */
		choices() {
			return replyPolicies()
		},
	},

	methods: {
		t,

		/**
		 * @param {string} policy one of the choices
		 * @return {Promise<void>}
		 */
		async setPolicy(policy) {
			const previous = this.policy
			this.policy = policy
			this.saving = true
			try {
				const url = generateUrl('apps/social/api/v1/statuses/{nid}/interaction_policy', { nid: this.nid })
				await axios.put(url, { reply_policy: policy })
				this.$emit('changed', policy)
				showSuccess(t('social', 'Saved'))
			} catch (error) {
				logger.error('could not set who may reply', { error })
				this.policy = previous
				showError(t('social', 'Could not change who can reply'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.replies__hint {
	color: var(--color-text-maxcontrast);
	margin-block-end: 8px;
}
</style>
