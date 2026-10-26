<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="media-upload">
		<!-- the file picker opens from the press itself: a browser only lets
		     a page open one in answer to something the reader just did, and a
		     composer that has to load first is no longer that -->
		<input
			ref="input"
			type="file"
			:accept="accept"
			:multiple="multiple"
			class="hidden-visually"
			tabindex="-1"
			aria-hidden="true"
			@change="onPicked">
		<NcButton variant="secondary" @click="pick">
			<template #icon>
				<IconImagePlus v-if="multiple" :size="20" />
				<IconVideoPlusOutline v-else :size="20" />
			</template>
			{{ label || t('social', 'Upload a video') }}
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconImagePlus from 'vue-material-design-icons/ImagePlus.vue'
import IconVideoPlusOutline from 'vue-material-design-icons/VideoPlusOutline.vue'

import eventBus from '../services/eventBus.js'

/**
 * Shared media-first entry for Photos and Videos. The picker opens directly
 * from the click; selected files go to the existing modal composer and its
 * uploader, draft handling and permissions. No editor is mounted here.
 */
export default {
	name: 'MediaUploadButton',

	components: {
		IconVideoPlusOutline,
		IconImagePlus,
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
