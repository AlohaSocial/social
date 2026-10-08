<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<p class="rules__readers">
			{{ t('social', 'Every app shows them to somebody deciding whether to join, and other servers read them from this server\'s public description.') }}
		</p>

		<NcTextArea
			v-model="rules"
			class="rules__field"
			:label="t('social', 'One rule per line')"
			:placeholder="placeholder"
			rows="8"
			:disabled="busy" />

		<p v-if="message" class="social-admin__hint" role="status">
			{{ message }}
		</p>

		<NcButton variant="primary" :disabled="busy" @click="save">
			<template v-if="busy" #icon>
				<NcLoadingIcon :size="20" />
			</template>
			{{ t('social', 'Save') }}
		</NcButton>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import { moderationUrl } from '../../services/adminApi.js'
import { showError, showSuccess } from '../../services/toast.js'

/**
 * The rules an instance asks people to follow.
 *
 * Stored exactly as they are stored today — one per line in an app value, the
 * shape `occ config:app:set social rules` writes and `/api/v1/instance/rules`
 * reads — so this is a place to edit them and not a second way of keeping them.
 */
export default {
	name: 'RulesSection',

	components: {
		NcButton,
		NcLoadingIcon,
		NcTextArea,
	},

	data() {
		return {
			rules: '',
			busy: false,
			message: '',
		}
	},

	computed: {
		/** @return {string} */
		placeholder() {
			return [
				t('social', 'Be kind to the people you are talking to.'),
				t('social', 'No harassment, and no encouraging it.'),
			].join('\n')
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
				const { data } = await axios.get(moderationUrl('/rules'))
				this.rules = data.rules ?? ''
			} catch {
				showError(t('social', 'Could not load the rules'))
			}
		},

		/** @return {Promise<boolean>} whether it was taken */
		async save() {
			this.busy = true
			this.message = ''
			try {
				const { data } = await axios.post(moderationUrl('/rules'), { rules: this.rules })
				this.rules = data.rules ?? ''
				this.message = t('social', 'Saved')
				showSuccess(t('social', 'The rules were saved'))

				return true
			} catch (error) {
				this.message = error.response?.data?.error ?? t('social', 'Could not save the rules')
				showError(this.message)

				return false
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.rules__readers {
	margin-block: 0 8px;
	color: var(--color-text-maxcontrast);
}

.rules__field {
	max-width: 640px;
	margin-block-end: 8px;
}
</style>
