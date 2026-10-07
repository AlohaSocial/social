<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="counts-settings">
		<NcCheckboxRadioSwitch
			type="switch"
			:modelValue="!hidden"
			:disabled="saving"
			@update:modelValue="show">
			{{ t('social', 'Show the numbers') }}
		</NcCheckboxRadioSwitch>
		<p class="counts-settings__hint">
			{{ hidden
				? t('social', 'Hidden: no likes, dislikes, boosts, replies or followers are counted for you — on your posts, on anybody else\'s, on profiles, and in your phone apps. Who liked a post is still there to look at.')
				: t('social', 'Shown: posts and profiles say how many likes, dislikes, boosts, replies and followers they have, here and in your phone apps.') }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'
import { useSettingsStore } from '../store/settings.js'

/**
 * Whether the reader is shown how many likes, dislikes, boosts and followers
 * things have. Hidden unless turned on; the page knew the setting before it
 * drew anything (`serverData.hideCounts`), so this only writes it.
 */
export default {
	name: 'CountsSettings',

	components: {
		NcCheckboxRadioSwitch,
	},

	data() {
		return {
			saving: false,
		}
	},

	computed: {
		/** @return {boolean} */
		hidden() {
			return useSettingsStore().hidesCounts
		},
	},

	methods: {
		t,

		/**
		 * @param {boolean} visible whether the numbers are to be shown
		 * @return {Promise<void>}
		 */
		async show(visible) {
			const settingsStore = useSettingsStore()
			const before = settingsStore.hidesCounts
			settingsStore.setServerDataEntry({ key: 'hideCounts', value: !visible })
			this.saving = true
			try {
				const { data } = await axios.patch(generateUrl('apps/social/api/v1/social/counts'), { hide: !visible })
				settingsStore.setServerDataEntry({ key: 'hideCounts', value: data?.hide === true })
			} catch (error) {
				logger.error('Could not save whether the numbers are shown', { error })
				showError(t('social', 'Could not save that setting'))
				settingsStore.setServerDataEntry({ key: 'hideCounts', value: before })
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.counts-settings__hint {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}
</style>
