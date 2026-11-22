<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="media-upload">
		<!-- opened from the click itself: browsers only open a file picker in
		     direct response to a user action -->
		<input
			ref="input"
			type="file"
			:accept="accept"
			:multiple="multiple"
			class="hidden-visually"
			tabindex="-1"
			aria-hidden="true"
			@change="onPicked">
		<!-- drawn as "New short" is on Shorts, so every page starts its kind of post alike -->
		<NcButton variant="primary" @click="pick">
			<template #icon>
				<IconPlus :size="20" />
			</template>
			{{ label || t('social', 'New video') }}
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconPlus from 'vue-material-design-icons/Plus.vue'

import eventBus from '../services/eventBus.js'

/**
 * Shared media-first entry for Photos and Videos: picked files are handed to
 * the modal composer. No editor is mounted here.
 */
export default {
	name: 'MediaUploadButton',

	components: {
		IconPlus,
		NcButton,
	},

	props: {
		accept: { type: String, default: 'video/*' },
		multiple: { type: Boolean, default: false },
		label: { type: String, default: '' },
	},

	methods: {
		t,

		pick() {
			/** @type {HTMLInputElement} */ (this.$refs.input).click()
		},

		/** @param {Event} event the picker's change */
		onPicked(event) {
			const input = /** @type {HTMLInputElement} */ (event.target)
			const files = Array.from(input.files ?? [])
			// picking the same file again has to be a change too
			input.value = ''
			if (files.length > 0) {
				eventBus.emit('composer-open', { files })
			}
		},
	},
}
</script>

<style scoped lang="scss">
.media-upload {
	flex: 0 0 auto;

}
</style>
