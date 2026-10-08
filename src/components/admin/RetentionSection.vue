<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<div class="retention">
			<NcTextField
				v-model="value"
				class="retention__days"
				type="number"
				min="0"
				max="3650"
				:label="t('social', 'Keep posts from other servers for (days)')" />
			<NcButton variant="primary" :disabled="saving || !isDayCount" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Save') }}
			</NcButton>
		</div>
		<p class="social-admin__hint">
			{{ t('social', 'Kept anyway: direct messages, posts somebody here liked, boosted, bookmarked or replied under, and posts by people somebody here follows. Posts written here are never deleted.') }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { moderationUrl } from '../../services/adminApi.js'
import { showError, showSuccess } from '../../services/toast.js'

/** How long a remote status nobody here cares about is kept. */
export default {
	name: 'RetentionSection',

	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
	},

	props: {
		/** the days now configured */
		days: {
			type: Number,
			default: 0,
		},
	},

	data() {
		return {
			value: String(this.days),
			saving: false,
		}
	},

	computed: {
		/** Anything that is not a day count is not sent, as it never was. */
		isDayCount() {
			const days = parseInt(this.value, 10)

			return !isNaN(days) && days >= 0
		},
	},

	methods: {
		t,

		/**
		 * @return {Promise<void>}
		 */
		async save() {
			if (!this.isDayCount) {
				return
			}

			this.saving = true
			try {
				await axios.post(moderationUrl('/retention'), { days: parseInt(this.value, 10) })
				showSuccess(t('social', 'Saved'))
			} catch {
				showError(t('social', 'Could not change the retention period'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.retention {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: flex-end;

	&__days {
		max-width: 260px;
	}
}
</style>
