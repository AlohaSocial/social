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
			:modelValue="mode"
			value="everyone"
			:disabled="saving"
			name="reply-policy"
			type="radio"
			@update:modelValue="setPolicy('everyone')">
			{{ t('social', 'Anybody') }}
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch
			:modelValue="mode"
			value="nobody"
			:disabled="saving"
			name="reply-policy"
			type="radio"
			@update:modelValue="setPolicy('nobody')">
			{{ t('social', 'Nobody') }}
		</NcCheckboxRadioSwitch>
		<h4 class="replies__heading">
			{{ t('social', 'Or only') }}
		</h4>
		<NcCheckboxRadioSwitch
			v-for="part in parts"
			:key="part.value"
			:modelValue="chosen.includes(part.value)"
			:disabled="saving"
			@update:modelValue="(on) => setPolicy(withPart(policy, part.value, on))">
			{{ part.label }}
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
import { ownLists, partLabel, RULE_PARTS, ruleParts, withPart } from '../utils/replyPolicy.js'

/**
 * An author's control over who may reply to one of their posts, here and on
 * Bluesky alike, from now on: anybody, nobody, or any of their followers,
 * the people they follow, the people the post mentions and the people on
 * one of their lists.
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
			lists: [],
		}
	},

	computed: {
		/** @return {string} `everyone`, `nobody` or `some` */
		mode() {
			return ['everyone', 'nobody'].includes(this.policy) ? this.policy : 'some'
		},

		/** @return {string[]} the parts the rule has */
		chosen() {
			return ruleParts(this.policy)
		},

		/** @return {Array<{value: string, label: string}>} every part that can be chosen */
		parts() {
			return [
				...RULE_PARTS.map((value) => ({ value, label: partLabel(value) })),
				...this.lists.map((list) => ({ value: `list:${list.id}`, label: partLabel(`list:${list.id}`, this.lists) })),
			]
		},
	},

	async mounted() {
		try {
			this.lists = await ownLists()
		} catch (error) {
			logger.debug('Could not read the lists', { error })
		}
	},

	methods: {
		t,
		withPart,

		/**
		 * @param {string} policy the new rule
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

.replies__heading {
	margin-block: 12px 4px;
}
</style>
