<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="external-storage">
		<template v-if="usage.quota > 0">
			<NcProgressBar
				class="external-storage__bar"
				:value="percent"
				:error="percent >= 90"
				size="medium" />
			<p class="external-storage__line">
				{{ t('social', '{used} of {quota} used', { used: humanSize(usage.used), quota: humanSize(usage.quota * 1048576) }) }}
			</p>
		</template>
		<p v-else class="external-storage__line">
			{{ t('social', '{used} used. This server sets no limit on your uploads.', { used: humanSize(usage.used) }) }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import { useServerData } from '../composables/useServerData.js'
import { humanSize } from '../utils/humanSize.js'

/**
 * How much of their media quota a self-registered external user has used.
 *
 * The figures are the page's own (`serverData.externalMedia`), as they stood
 * when it was opened: the quota is counted on upload, and a page that
 * refetched it on every visit would be asking a question nobody is waiting on.
 */
export default {
	name: 'ExternalStorage',

	components: {
		NcProgressBar,
	},

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	computed: {
		/** @return {{quota: number, used: number}} quota in MB, used in bytes */
		usage() {
			return this.serverData?.externalMedia ?? { quota: 0, used: 0 }
		},

		/** @return {number} 0 to 100 */
		percent() {
			if (this.usage.quota <= 0) {
				return 0
			}

			return Math.min(100, Math.round(this.usage.used / (this.usage.quota * 1048576) * 100))
		},
	},

	methods: {
		t,
		humanSize,
	},
}
</script>

<style scoped lang="scss">
.external-storage {
	max-width: 560px;

	&__line {
		margin-block-start: calc(var(--default-grid-baseline) * 2);
		color: var(--color-text-maxcontrast);
	}
}
</style>
