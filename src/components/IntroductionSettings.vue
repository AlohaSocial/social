<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="introduction-settings">
		<NcButton :to="{ name: 'timeline', query: { replay: '1' } }">
			<template #icon>
				<IconIntroduction :size="20" />
			</template>
			{{ t('social', 'Show the introduction again') }}
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconIntroduction from 'vue-material-design-icons/HandWaveOutline.vue'

/**
 * The way back into the first-run introduction.
 *
 * It is shown once, after the account is set up, and an account made any
 * other way -- by an administrator, or before the setup screen existed --
 * never saw it; neither did anybody who left it half way. The home timeline
 * shows it for `?welcome=1`, so that is where this sends the reader.
 */
export default {
	name: 'IntroductionSettings',

	components: {
		IconIntroduction,
		NcButton,
	},

	methods: {
		t,
	},
}
</script>
