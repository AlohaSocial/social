<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:open="open"
		:name="t('social', 'Report {account}', { account: handle })"
		:buttons="buttons"
		class="report-dialog"
		@update:open="$emit('update:open', $event)">
		<form class="report-dialog__form" @submit.prevent="submit">
			<p :id="hintId" class="report-dialog__hint">
				{{ t('social', 'The report goes to the moderators of this server. The account you report is not told who reported it.') }}
			</p>

			<fieldset class="report-dialog__group" :aria-describedby="hintId">
				<legend>{{ t('social', 'What is wrong?') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="option in categories"
					:key="option.value"
					v-model="category"
					type="radio"
					:name="radioName"
					:value="option.value">
					{{ option.label }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<fieldset v-if="category === 'violation'" class="report-dialog__group">
				<legend>{{ t('social', 'Which rules does it break?') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="rule in rules"
					:key="rule.id"
					v-model="ruleIds"
					type="checkbox"
					:value="rule.id">
					{{ rule.text }}
				</NcCheckboxRadioSwitch>
				<p v-if="ruleIds.length === 0" class="report-dialog__hint">
					{{ t('social', 'Choose at least one rule.') }}
				</p>
			</fieldset>

			<NcTextArea
				v-model="comment"
				class="report-dialog__comment"
				:label="t('social', 'Anything the moderators should know (optional)')"
				:maxlength="1000"
				resize="vertical"
				rows="3" />

			<template v-if="canForward">
				<NcCheckboxRadioSwitch v-model="forward" :aria-describedby="forwardHintId">
					{{ t('social', 'Also send to the moderators of their server') }}
				</NcCheckboxRadioSwitch>
				<p :id="forwardHintId" class="report-dialog__hint">
					{{ t('social', 'Only their server can act on their account everywhere. This server passes the report on in its own name, not yours, with what you wrote above.') }}
				</p>
			</template>
		</form>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { isLocalAccount } from '../utils/accountLocality.js'
import { focusedElement, returnFocusTo } from '../utils/focus.js'

/** Gives each open dialog ids of its own for its labels. */
let instances = 0

/**
 * Reporting an account, or one of its posts, to the moderators.
 *
 * The categories are the server's (Mastodon's four); "breaks the server
 * rules" asks which rules, and is offered only where the server has rules.
 * An account on another server can be reported there as well, whichever
 * network it is on: the server forwards the report in its own name.
 */
export default {
	name: 'ReportDialog',

	components: {
		NcCheckboxRadioSwitch,
		NcDialog,
		NcTextArea,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		account: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Account>} */ (Object),
			required: true,
		},

		/** The post being reported, or null for the account itself. */
		status: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status|null>} */ (Object),
			default: null,
		},
	},

	emits: ['update:open', 'reported'],

	data() {
		instances += 1

		return {
			uid: instances,
			category: 'spam',
			/** @type {string[]} */
			ruleIds: [],
			comment: '',
			forward: true,
			/** @type {Array<{id: string, text: string}>} */
			rules: [],
			sending: false,
			/** what had the focus when the dialog opened, to give it back to */
			opener: null,
		}
	},

	computed: {
		/** @return {string} the account as it is written, with its @ */
		handle() {
			return '@' + (this.account.acct ?? this.account.username ?? '')
		},

		hintId() {
			return 'report-dialog-hint-' + this.uid
		},

		forwardHintId() {
			return 'report-dialog-forward-' + this.uid
		},

		radioName() {
			return 'report-category-' + this.uid
		},

		/** @return {Array<{value: string, label: string}>} */
		categories() {
			return [
				{ value: 'spam', label: t('social', 'It is spam') },
				...(this.rules.length > 0
					? [{ value: 'violation', label: t('social', 'It breaks the rules of this server') }]
					: []),
				{ value: 'legal', label: t('social', 'It is illegal') },
				{ value: 'other', label: t('social', 'Something else') },
			]
		},

		/** @return {boolean} whether the account lives on another server */
		canForward() {
			return !isLocalAccount(this.account)
		},

		/** @return {boolean} whether the report says enough to be sent */
		complete() {
			return this.category !== 'violation' || this.ruleIds.length > 0
		},

		/** @return {import('../types/Nextcloud.js').DialogButton[]} */
		buttons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => this.$emit('update:open', false),
				},
				{
					label: t('social', 'Report'),
					variant: 'error',
					disabled: this.sending || !this.complete,
					// false keeps the dialog open for another try
					callback: () => this.send(),
				},
			]
		},
	},

	watch: {
		open(open) {
			if (open) {
				this.reset()
				this.opener = focusedElement()
				this.loadRules()
			} else {
				this.$nextTick(() => returnFocusTo(this.opener))
			}
		},

		category(category) {
			if (category !== 'violation') {
				this.ruleIds = []
			}
		},
	},

	created() {
		if (this.open) {
			this.opener = focusedElement()
			this.loadRules()
		}
	},

	methods: {
		t,

		reset() {
			this.category = 'spam'
			this.ruleIds = []
			this.comment = ''
			this.forward = true
		},

		/** The server's rules; none is an answer too, and hides the choice. */
		async loadRules() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/instance/rules'))
				this.rules = Array.isArray(data)
					? data.filter((rule) => rule && typeof rule.text === 'string').map((rule) => ({ id: String(rule.id), text: rule.text }))
					: []
			} catch (error) {
				logger.debug('Could not read the server rules', { error })
				this.rules = []
			}
		},

		async submit() {
			if (await this.send()) {
				this.$emit('update:open', false)
			}
		},

		/**
		 * Files the report.
		 *
		 * @return {Promise<boolean>} whether it was filed
		 */
		async send() {
			if (this.sending || !this.complete) {
				return false
			}
			this.sending = true
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/reports'), {
					account_id: this.account.id,
					status_ids: this.status ? [this.status.id] : [],
					comment: this.comment,
					category: this.category,
					...(this.category === 'violation' ? { rule_ids: this.ruleIds } : {}),
					...(this.canForward ? { forward: this.forward } : {}),
				})
				showSuccess(this.status
					? t('social', 'Post reported to the moderators')
					: t('social', 'Account reported to the moderators'))
				this.$emit('reported', data)

				return true
			} catch (error) {
				logger.error('Failed to send a report', { error })
				showError(t('social', 'The report could not be sent'))

				return false
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.report-dialog {
	&__form {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__group {
		border: 0;
		padding: 0;
		margin: 0;

		legend {
			font-weight: bold;
			margin-bottom: var(--default-grid-baseline);
		}
	}
}
</style>
