<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:open="open"
		:name="name"
		:buttons="buttons"
		@update:open="$emit('update:open', $event)">
		<p class="confirm-dialog__message">
			{{ message }}
		</p>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'

/**
 * The question asked before something that cannot be taken back.
 *
 * Suspending an account, taking a post down and removing an announcement each
 * delete something for everybody, and each used to ask with `window.confirm`,
 * which is a browser dialog in the middle of a Nextcloud page.
 */
export default {
	name: 'ConfirmDialog',

	components: {
		NcDialog,
	},

	props: {
		/** whether the dialog is showing */
		open: {
			type: Boolean,
			default: false,
		},

		/** the question, as the dialog's heading */
		name: {
			type: String,
			required: true,
		},

		/** what it will cost, in full */
		message: {
			type: String,
			required: true,
		},

		/** what the button that goes ahead says */
		confirmLabel: {
			type: String,
			required: true,
		},
	},

	emits: ['update:open', 'confirm'],

	computed: {
		/** @return {import('../../types/Nextcloud.js').DialogButton[]} */
		buttons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => this.$emit('update:open', false),
				},
				{
					label: this.confirmLabel,
					variant: 'error',
					callback: () => {
						this.$emit('update:open', false)
						this.$emit('confirm')
					},
				},
			]
		},
	},

	methods: {
		t,
	},
}
</script>
