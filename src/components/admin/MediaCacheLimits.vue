<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<h4 class="media-cache__heading">
			{{ t('social', 'Media from other servers') }}
		</h4>
		<p class="social-admin__hint">
			{{ t('social', 'Pictures and videos in posts from other servers are copied here so they load quickly. Once a day, copies past either limit are deleted, oldest first. A deleted copy is fetched again when somebody looks at it. 0 turns a limit off. Uploads from this server are never deleted.') }}
		</p>
		<div class="media-cache">
			<NcTextField
				v-model="days"
				class="media-cache__field"
				type="number"
				min="0"
				max="3650"
				:label="t('social', 'Keep copies for (days)')" />
			<NcTextField
				v-model="gigabytes"
				class="media-cache__field"
				type="number"
				min="0"
				step="0.5"
				:label="t('social', 'Keep at most (GiB)')" />
			<NcButton variant="primary" :disabled="saving || !isValid" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Save') }}
			</NcButton>
		</div>
		<p v-if="lastPurgeLine" class="social-admin__hint">
			{{ lastPurgeLine }}
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
import { humanSize } from '../../utils/humanSize.js'

const MB_PER_GIB = 1024

/**
 * The two limits of the cache of other servers' media, which the daily
 * background job applies (`MediaPurgeService::purgeCache()`), and what it
 * removed last time.
 */
export default {
	name: 'MediaCacheLimits',

	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
	},

	props: {
		/** {days, sizeMb, lastPurge}, as the server holds them */
		mediaCache: {
			type: Object,
			default: null,
		},
	},

	data() {
		const sizeMb = this.mediaCache?.sizeMb ?? 0

		return {
			days: String(this.mediaCache?.days ?? 0),
			gigabytes: String(Math.round((sizeMb / MB_PER_GIB) * 100) / 100),
			saving: false,
		}
	},

	computed: {
		dayCount() {
			const days = Number(this.days)

			return this.days !== '' && Number.isInteger(days) && days >= 0 && days <= 3650 ? days : null
		},

		megabytes() {
			const gigabytes = Number(this.gigabytes)

			return this.gigabytes !== '' && Number.isFinite(gigabytes) && gigabytes >= 0
				? Math.round(gigabytes * MB_PER_GIB)
				: null
		},

		isValid() {
			return this.dayCount !== null && this.megabytes !== null
		},

		/** @return {string} what the last purge removed, or '' before the first */
		lastPurgeLine() {
			const last = this.mediaCache?.lastPurge
			if (!last?.purged) {
				return ''
			}

			const when = new Date(last.purged * 1000).toLocaleString()

			return t('social', 'Last run {when}: {size} deleted.', { when, size: humanSize(last.bytes ?? 0) })
		},
	},

	methods: {
		t,

		/**
		 * @return {Promise<void>}
		 */
		async save() {
			if (!this.isValid) {
				return
			}

			this.saving = true
			try {
				await axios.post(moderationUrl('/media-cache'), { days: this.dayCount, sizeMb: this.megabytes })
				showSuccess(t('social', 'Saved'))
			} catch {
				showError(t('social', 'Could not change the media limits'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.media-cache {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: flex-end;

	&__field {
		max-width: 200px;
	}

	&__heading {
		margin-block: 16px 4px;
		font-weight: bold;
	}
}
</style>
