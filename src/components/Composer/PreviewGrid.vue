<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="upload-form">
		<!-- v-if="false" meant the bar never appeared at all, and the progress
		     it would have shown was the hard-coded 0.4 the composer passed -->
		<div v-if="uploading" class="upload-progress">
			<div class="upload-progress__icon">
				<FileUpload :size="32" />
			</div>

			<!-- a moving bar says nothing to a screen reader: the same fraction
			     is on the element itself, so it is read rather than watched -->
			<div
				class="upload-progress__message"
				role="progressbar"
				:aria-label="label"
				aria-valuemin="0"
				aria-valuemax="100"
				:aria-valuenow="Math.round(uploadProgress * 100)">
				{{ label }}

				<div class="upload-progress__backdrop">
					<div class="upload-progress__tracker" :style="`width: ${uploadProgress * 100}%`" />
				</div>
			</div>
		</div>
		<div class="preview-grid" :class="{ 'preview-grid--single': count === 1 }">
			<PreviewGridItem
				v-for="(item, randomKey) in miniatures"
				:key="randomKey"
				:preview="item"
				:randomKey="randomKey"
				@delete="deletePreview"
				@describe="$emit('describe', $event)"
				@commitDescription="$emit('commitDescription', $event)"
				@focus="$emit('focus', $event)"
				@commitFocus="$emit('commitFocus', $event)"
				@filter="$emit('filter', $event)" />
		</div>
	</div>
</template>

<script>
import PreviewGridItem from './PreviewGridItem.vue'
import FileUpload from 'vue-material-design-icons/FileUpload.vue'
import { translate } from '@nextcloud/l10n'

export default {
	name: 'PreviewGrid',
	components: {
		PreviewGridItem,
		FileUpload,
	},

	props: {
		uploadProgress: {
			type: Number,
			required: true,
		},

		/** what the bar is working on; attaching from Files is not uploading */
		progressLabel: {
			type: String,
			default: '',
		},

		uploading: {
			type: Boolean,
			required: true,
		},

		miniatures: {
			type: /** @type {import('vue').PropType<Object<string, import('../../types/Composer.js').LocalAttachment>>} */ (Object),
			required: true,
		},
	},

	emits: ['deleted', 'describe', 'commitDescription', 'focus', 'commitFocus', 'filter'],

	computed: {
		/** @return {number} how many pictures the post is carrying */
		count() {
			return Object.keys(this.miniatures).length
		},

		/** @return {string} */
		label() {
			return this.progressLabel || translate('social', 'Uploading…')
		},
	},

	methods: {
		deletePreview(randomKey) {
			this.$emit('deleted', randomKey)
		},

		t: translate,
	},
}
</script>

<style scoped lang="scss">
.upload-progress {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 8px;

	&__message {
		flex-grow: 1;
		font-size: 13px;
		color: var(--color-text-lighter);
	}

	&__backdrop {
		margin-top: 4px;
		height: 4px;
		border-radius: 2px;
		background: var(--color-background-dark);
		overflow: hidden;
	}

	&__tracker {
		height: 100%;
		background: var(--color-primary-element);
		transition: width .2s ease;
	}
}

@media (prefers-reduced-motion: reduce) {
	.upload-progress__tracker {
		transition: none;
	}
}

.preview-grid {
	display: flex;
	flex-wrap: wrap;
	flex-direction: row;
	margin-inline: -5px;
}

// one picture is the post, not a thumbnail of it
.preview-grid--single :deep(.preview-item) {
	height: 260px;
}
</style>
