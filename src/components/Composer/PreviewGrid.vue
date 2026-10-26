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
		<div class="preview-grid">
			<div
				v-if="keys.length > 1"
				class="preview-grid__navigation"
				role="group"
				:aria-label="t('social', 'Attachment preview')">
				<NcButton :disabled="activeIndex <= 0" :aria-label="t('social', 'Previous attachment')" @click="select(activeIndex - 1)">
					<template #icon>
						<ChevronLeft :size="20" />
					</template>
				</NcButton>
				<span role="status">{{ t('social', '{current} of {total}', { current: activeIndex + 1, total: keys.length }) }}</span>
				<NcButton :disabled="activeIndex >= keys.length - 1" :aria-label="t('social', 'Next attachment')" @click="select(activeIndex + 1)">
					<template #icon>
						<ChevronRight :size="20" />
					</template>
				</NcButton>
			</div>
			<PreviewGridItem
				v-if="activeKey"
				:key="activeKey"
				:preview="miniatures[activeKey]"
				:randomKey="activeKey"
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
import NcButton from '@nextcloud/vue/components/NcButton'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import FileUpload from 'vue-material-design-icons/FileUpload.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'PreviewGrid',
	components: {
		PreviewGridItem,
		NcButton,
		ChevronLeft,
		ChevronRight,
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

	data() {
		return { selectedKey: '' }
	},

	computed: {
		keys() {
			return Object.keys(this.miniatures)
		},

		activeKey() {
			return this.keys.includes(this.selectedKey) ? this.selectedKey : this.keys[0] || ''
		},

		activeIndex() {
			return this.keys.indexOf(this.activeKey)
		},

		/** @return {string} */
		label() {
			return this.progressLabel || t('social', 'Uploading…')
		},
	},

	methods: {
		select(index) {
			if (this.keys[index]) {
				this.selectedKey = this.keys[index]
			}
		},

		deletePreview(randomKey) {
			this.$emit('deleted', randomKey)
		},

		t,
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
	display: block;

	&__navigation {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 12px;
		margin-block: 8px;
		font-variant-numeric: tabular-nums;
	}
}
</style>
