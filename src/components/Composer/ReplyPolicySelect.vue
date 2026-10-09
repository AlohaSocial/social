<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcActions
		variant="tertiary"
		:menuName="policy === 'everyone' ? '' : label"
		:aria-label="t('social', 'Who can reply')"
		@open="loadLists">
		<template #icon>
			<IconCommentOutline v-if="policy === 'everyone'" :size="20" />
			<IconCommentAccountOutline v-else :size="20" />
		</template>
		<NcActionCaption :name="t('social', 'Who can reply')" />
		<NcActionButton
			:modelValue="policy"
			value="everyone"
			type="radio"
			@click="$emit('update:policy', 'everyone')">
			{{ t('social', 'Anybody') }}
		</NcActionButton>
		<NcActionButton
			:modelValue="policy"
			value="nobody"
			type="radio"
			@click="$emit('update:policy', 'nobody')">
			{{ t('social', 'Nobody') }}
		</NcActionButton>
		<NcActionCaption :name="t('social', 'Or only')" />
		<NcActionCheckbox
			v-for="part in parts"
			:key="part.value"
			:modelValue="chosen.includes(part.value)"
			@update:modelValue="(on) => $emit('update:policy', withPart(policy, part.value, on))">
			{{ part.label }}
		</NcActionCheckbox>
	</NcActions>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActionCheckbox from '@nextcloud/vue/components/NcActionCheckbox'
import NcActions from '@nextcloud/vue/components/NcActions'
import IconCommentAccountOutline from 'vue-material-design-icons/CommentAccountOutline.vue'
import IconCommentOutline from 'vue-material-design-icons/CommentOutline.vue'
import logger from '../../services/logger.js'
import { ownLists, partLabel, RULE_PARTS, ruleLabel, ruleParts, withPart } from '../../utils/replyPolicy.js'

/**
 * Who may reply to the post being written: anybody, nobody, or any of the
 * reader's followers, the people they follow, the people they mention and
 * the people on one of their lists. The button says so only when it is
 * anybody less than everybody.
 */
export default {
	name: 'ReplyPolicySelect',

	components: {
		IconCommentAccountOutline,
		IconCommentOutline,
		NcActionButton,
		NcActionCaption,
		NcActionCheckbox,
		NcActions,
	},

	props: {
		/** the server's `reply_policy`: `everyone`, `nobody` or a combination */
		policy: {
			type: String,
			default: 'everyone',
		},
	},

	emits: ['update:policy'],

	data() {
		return {
			/** the reader's lists, read when the menu is first opened */
			lists: [],
			listsLoaded: false,
		}
	},

	computed: {
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

		/** @return {string} what the rule says */
		label() {
			return ruleLabel(this.policy, this.lists)
		},
	},

	methods: {
		t,
		withPart,

		async loadLists() {
			if (this.listsLoaded) {
				return
			}
			this.listsLoaded = true
			try {
				this.lists = await ownLists()
			} catch (error) {
				logger.debug('Could not read the lists', { error })
			}
		},
	},
}
</script>
