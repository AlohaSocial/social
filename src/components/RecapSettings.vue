<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="recap-settings">
		<NcCheckboxRadioSwitch
			type="switch"
			:modelValue="enabled"
			:disabled="loading"
			@update:modelValue="save">
			{{ t('social', 'Show me how my week went') }}
		</NcCheckboxRadioSwitch>
		<p class="recap-settings__lede">
			{{ t('social', 'A line at the top of your feed, once a week, saying how many times you posted. Nobody else sees it, and there is no streak to keep up.') }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import logger from '../services/logger.js'
import { setHint } from '../services/startupHints.js'
import { showError } from '../services/toast.js'

/**
 * The switch that turns the weekly recap on.
 *
 * It exists because the recap is opt-in and opt-in needs somewhere to opt in.
 * The wording under it is the whole promise: it is private, it is once a week,
 * and there is nothing to keep up — see WeeklyRecap for why there is no streak.
 */
export default {
	name: 'RecapSettings',

	components: {
		NcCheckboxRadioSwitch,
	},

	data() {
		return {
			enabled: false,
			loading: true,
		}
	},

	async mounted() {
		try {
			const { data } = await axios.get(generateUrl('/apps/social/api/v1/memories/recap'))
			this.enabled = data?.enabled === true
		} catch (error) {
			logger.debug('Could not read the weekly recap setting', { error })
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,

		/**
		 * @param {boolean} enabled what the switch was moved to
		 */
		async save(enabled) {
			// moved at once rather than on the way back: a switch that waits
			// for a round-trip before it moves reads as one that did not work
			const previous = this.enabled
			this.enabled = enabled
			this.loading = true

			try {
				await axios.post(generateUrl('/apps/social/api/v1/memories/recap'), { enabled })
				setHint('recap_enabled', enabled)
			} catch (error) {
				logger.error('Could not save the weekly recap setting', { error })
				showError(t('social', 'Could not save that setting'))
				this.enabled = previous
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.recap-settings__lede {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
