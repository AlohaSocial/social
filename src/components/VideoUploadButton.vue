<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="video-upload">
		<!-- the file picker opens from the press itself: a browser only lets
		     a page open one in answer to something the reader just did, and a
		     composer that has to load first is no longer that -->
		<input
			ref="input"
			type="file"
			accept="video/*"
			class="hidden-visually"
			tabindex="-1"
			aria-hidden="true"
			@change="onPicked">
		<NcButton variant="secondary" @click="pick">
			<template #icon>
				<IconVideoPlusOutline :size="20" />
			</template>
			{{ t('social', 'Upload a video') }}
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconVideoPlusOutline from 'vue-material-design-icons/VideoPlusOutline.vue'

import eventBus from '../services/eventBus.js'

/**
 * The Videos page's way to post one: the file first, then the composer with
 * it attached, where the title, category and licence of a video are asked.
 * The page has no composer box of its own; a page for watching is not a page
 * for writing, and the box took the place of the first row of videos.
 */
export default {
	name: 'VideoUploadButton',

	components: {
		IconVideoPlusOutline,
		NcButton,
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
.video-upload {
	flex: 0 0 auto;

}
</style>
