<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<fieldset
		class="post-edit-form"
		:disabled="saving"
		:aria-busy="saving"
		:aria-label="t('social', 'Edit post')">
		<Composer
			ref="composer"
			startExpanded
			compactOptions
			:listenForIntents="false"
			:editItem="item"
			:editSaving="saving"
			:editMaxLength="maxLength"
			emojiPickerContainer=".modal-wrapper"
			@save="$emit('save', $event)"
			@cancel="$emit('cancel')" />
	</fieldset>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Composer from './Composer/Composer.vue'

/** Adapts the shared composer to Navigation's edit/save and close guards. */
export default {
	name: 'PostEditForm',
	components: { Composer },
	props: {
		item: { type: Object, required: true },
		maxLength: { type: Number, required: true },
		saving: { type: Boolean, default: false },
	},

	emits: ['save', 'cancel'],
	computed: {
		isAutocompleteActive() {
			const editor = /** @type {{tribute?: {isActive: boolean}}|undefined} */ (this.$refs.composer)
			return editor?.tribute?.isActive === true
		},

		isDirty() {
			const editor = /** @type {{isEditDirty: boolean}|undefined} */ (this.$refs.composer)
			return editor?.isEditDirty ?? false
		},

		editMediaUploading() {
			const editor = /** @type {{hasPendingUploads: boolean}|undefined} */ (this.$refs.composer)
			return editor?.hasPendingUploads ?? false
		},
	},

	methods: { t },
}
</script>

<style scoped lang="scss">
.post-edit-form {
	border: 0;
	padding: 0;
	margin: 0;
	min-width: 0;
}
</style>
