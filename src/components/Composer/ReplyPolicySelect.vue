<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcActions
		variant="tertiary"
		:menuName="selected.value === 'everyone' ? '' : selected.label"
		:aria-label="t('social', 'Who can reply')">
		<template #icon>
			<IconCommentOutline v-if="selected.value === 'everyone'" :size="20" />
			<IconCommentAccountOutline v-else :size="20" />
		</template>
		<NcActionCaption :name="t('social', 'Who can reply')" />
		<NcActionButton
			v-for="choice in choices"
			:key="choice.value"
			:modelValue="policy"
			:value="choice.value"
			type="radio"
			:closeAfterClick="true"
			@click="$emit('update:policy', choice.value)">
			{{ choice.label }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActions from '@nextcloud/vue/components/NcActions'
import IconCommentAccountOutline from 'vue-material-design-icons/CommentAccountOutline.vue'
import IconCommentOutline from 'vue-material-design-icons/CommentOutline.vue'
import { replyPolicies } from '../../utils/replyPolicy.js'

/**
 * Who may reply to the post being written; the button says so only when it
 * is anybody less than everybody.
 */
export default {
	name: 'ReplyPolicySelect',

	components: {
		IconCommentAccountOutline,
		IconCommentOutline,
		NcActionButton,
		NcActionCaption,
		NcActions,
	},

	props: {
		/** one of the server's `reply_policy` values */
		policy: {
			type: String,
			default: 'everyone',
		},
	},

	emits: ['update:policy'],

	computed: {
		/** @return {Array<{value: string, label: string}>} */
		choices() {
			return replyPolicies()
		},

		/** @return {{value: string, label: string}} */
		selected() {
			return this.choices.find(({ value }) => value === this.policy) ?? this.choices[0]
		},
	},

	methods: {
		t,
	},
}
</script>
