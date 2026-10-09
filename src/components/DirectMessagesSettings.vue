<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<fieldset class="direct-messages-settings">
		<legend class="hidden-visually">
			{{ t('social', 'Who may send you direct messages') }}
		</legend>
		<NcCheckboxRadioSwitch
			v-for="choice in choices"
			:key="choice.value"
			class="direct-messages-settings__choice"
			type="radio"
			name="social-direct-messages-from"
			:value="choice.value"
			:modelValue="from"
			:disabled="loading"
			@update:modelValue="save">
			{{ choice.label }}
			<span class="direct-messages-settings__hint">{{ choice.hint }}</span>
		</NcCheckboxRadioSwitch>
	</fieldset>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'

const URL = '/apps/social/api/v1/social/direct_messages'
const VALUES = ['all', 'following', 'none']

/**
 * Who may send the reader direct messages: everybody, the people they
 * follow, or nobody. A message from anybody else does not notify them and
 * waits among their requests; the same setting is "Private mentions you
 * didn't ask for" under who may reach them, seen from this side.
 */
export default {
	name: 'DirectMessagesSettings',

	components: {
		NcCheckboxRadioSwitch,
	},

	data() {
		return {
			from: 'following',
			loading: true,
		}
	},

	computed: {
		/** @return {{value: string, label: string, hint: string}[]} */
		choices() {
			return [
				{ value: 'all', label: t('social', 'Everybody'), hint: t('social', 'Anybody can start a conversation with you.') },
				{ value: 'following', label: t('social', 'People you follow'), hint: t('social', 'Messages from anybody else wait among your requests.') },
				{ value: 'none', label: t('social', 'Nobody'), hint: t('social', 'Every message waits among your requests, except from people you allowed there.') },
			]
		},
	},

	async mounted() {
		try {
			const { data } = await axios.get(generateUrl(URL))
			if (VALUES.includes(data?.from)) {
				this.from = data.from
			}
		} catch (error) {
			logger.debug('Could not read who may send direct messages', { error })
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,

		/**
		 * @param {string} from the choice made
		 */
		async save(from) {
			if (!VALUES.includes(from) || from === this.from) {
				return
			}
			const previous = this.from
			this.from = from
			this.loading = true

			try {
				const { data } = await axios.patch(generateUrl(URL), { from })
				if (VALUES.includes(data?.from)) {
					this.from = data.from
				}
			} catch (error) {
				logger.error('Could not save who may send direct messages', { error })
				showError(t('social', 'Could not save that setting'))
				this.from = previous
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.direct-messages-settings {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.direct-messages-settings__hint {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
