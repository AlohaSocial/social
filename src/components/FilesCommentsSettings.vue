<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="files-comments-settings">
		<NcCheckboxRadioSwitch
			type="switch"
			:modelValue="enabled"
			:disabled="loading"
			@update:modelValue="save">
			{{ t('social', 'Show replies as comments in Files') }}
		</NcCheckboxRadioSwitch>
		<p class="files-comments-settings__lede">
			{{ enabled
				? t('social', 'On: public and unlisted replies to a picture you posted from Files appear as comments on that file, and a comment you write there is posted as your reply. Comments from other people with access to the file stay on this server.')
				: t('social', 'Off: posts you make from Files are not linked to the files, and comments there stay on this server.') }}
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

/**
 * Whether replies to posts made from Files are shown as comments on the
 * files, and the author's comments there go out as replies. On unless turned
 * off, so anything but a plain no reads as on.
 */
export default {
	name: 'FilesCommentsSettings',

	components: {
		NcCheckboxRadioSwitch,
	},

	data() {
		return {
			enabled: true,
			loading: true,
		}
	},

	async mounted() {
		try {
			const { data } = await axios.get(generateUrl('/apps/social/api/v1/social/files_comments'))
			this.enabled = data?.enabled !== false
		} catch (error) {
			logger.debug('Could not read the Files comments setting', { error })
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
			const previous = this.enabled
			this.enabled = enabled
			this.loading = true

			try {
				await axios.patch(generateUrl('/apps/social/api/v1/social/files_comments'), { enabled })
			} catch (error) {
				logger.error('Could not save the Files comments setting', { error })
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
.files-comments-settings__lede {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}
</style>
