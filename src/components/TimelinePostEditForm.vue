<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="post-edit-inline">
		<div v-if="isEvent" class="post-edit-event">
			<label>
				{{ t('social', 'Event title') }}
				<input
					:value="event.name"
					type="text"
					maxlength="300"
					required
					@input="updateEvent('name', inputValue($event))">
			</label>
			<label>
				{{ t('social', 'Starts') }}
				<input
					:value="event.start_time"
					type="datetime-local"
					required
					@input="updateEvent('start_time', inputValue($event))">
			</label>
			<label>
				{{ t('social', 'Ends (optional)') }}
				<input
					:value="event.end_time"
					type="datetime-local"
					@input="updateEvent('end_time', inputValue($event))">
			</label>
			<label>
				{{ t('social', 'Event location (optional)') }}
				<input
					:value="event.location"
					type="text"
					maxlength="500"
					@input="updateEvent('location', inputValue($event))">
			</label>
		</div>
		<input
			:value="spoiler"
			type="text"
			class="post-edit-warning"
			dir="auto"
			maxlength="200"
			:aria-label="t('social', 'Content warning')"
			:placeholder="t('social', 'Content warning, e.g. what the post is about')"
			@input="$emit('update:spoiler', inputValue($event))">
		<label class="post-edit-sensitive">
			<input
				:checked="sensitive"
				type="checkbox"
				@change="$emit('update:sensitive', inputChecked($event))">
			{{ t('social', 'Mark media as sensitive') }}
		</label>
		<div class="post-edit-attachments" :aria-label="t('social', 'Attachments')">
			<div v-for="attachment in attachments" :key="attachment.id" class="post-edit-attachment">
				<img
					v-if="attachment.type === 'image'"
					:src="attachment.preview_url || attachment.url"
					:alt="attachment.description || ''">
				<span>{{ attachment.description || attachment.type }}</span>
				<NcButton
					variant="tertiary-no-background"
					:disabled="uploading"
					:aria-label="t('social', 'Remove attachment')"
					@click="$emit('removeMedia', attachment.id)">
					{{ t('social', 'Remove') }}
				</NcButton>
			</div>
			<label class="post-edit-add-attachment" :class="{ 'post-edit-add-attachment--disabled': uploading || attachments.length >= 4 }">
				<span aria-hidden="true">＋</span>
				{{ t('social', 'Add media') }}
				<input
					type="file"
					accept="image/*,video/*"
					multiple
					:disabled="uploading || attachments.length >= 4"
					@change="addMedia">
			</label>
			<span v-if="uploading" role="status">{{ t('social', 'Uploading attachments…') }}</span>
		</div>
		<textarea
			ref="editInput"
			:value="content"
			class="post-edit-textarea"
			dir="auto"
			:maxlength="maxLength"
			:aria-describedby="isTooLong ? `post-edit-count-${statusId}` : undefined"
			:placeholder="t('social', 'Edit your post')"
			@input="$emit('update:content', inputValue($event))"
			@keydown.ctrl.enter="$emit('save')" />
		<div class="post-edit-actions">
			<span
				:id="`post-edit-count-${statusId}`"
				class="post-edit-count"
				:class="{ 'post-edit-count--over': isTooLong }"
				role="status">
				{{ charactersLeftLabel }}
			</span>
			<NcButton
				variant="primary"
				:disabled="!canSave"
				:aria-label="t('social', 'Save')"
				@click="$emit('save')">
				{{ t('social', 'Save') }}
			</NcButton>
			<NcButton :aria-label="t('social', 'Cancel')" @click="$emit('cancel')">
				{{ t('social', 'Cancel') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'

export default {
	name: 'TimelinePostEditForm',
	components: { NcButton },
	props: {
		content: { type: String, required: true },
		spoiler: { type: String, required: true },
		sensitive: { type: Boolean, required: true },
		event: { type: Object, required: true },
		isEvent: { type: Boolean, default: false },
		maxLength: { type: Number, required: true },
		statusId: { type: String, required: true },
		attachments: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').MediaAttachment[]>} */ (Array),
			required: true,
		},

		uploading: { type: Boolean, default: false },
		isTooLong: { type: Boolean, required: true },
		charactersLeftLabel: { type: String, required: true },
		canSave: { type: Boolean, required: true },
	},

	emits: ['update:content', 'update:spoiler', 'update:sensitive', 'update:event', 'addMedia', 'removeMedia', 'save', 'cancel'],
	methods: {
		addMedia(event) {
			const input = /** @type {HTMLInputElement} */ (event.target)
			if (input.files) {
				this.$emit('addMedia', Array.from(input.files))
			}
			input.value = ''
		},

		inputValue(event) {
			return /** @type {HTMLInputElement | HTMLTextAreaElement} */ (event.target).value
		},

		inputChecked(event) {
			return /** @type {HTMLInputElement} */ (event.target).checked
		},

		updateEvent(field, value) {
			this.$emit('update:event', { ...this.event, [field]: value })
		},

		focus() {
			/** @type {HTMLTextAreaElement | undefined} */ (this.$refs.editInput)?.focus()
		},
	},
}
</script>

<style scoped lang="scss">
.post-edit-inline {
	margin-bottom: 10px;

	.post-edit-event {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 8px;
		margin-bottom: 10px;

		label { display: grid; gap: 4px; font-size: .9rem; }
		input { min-width: 0; width: 100%; }
	}

	.post-edit-textarea {
		box-sizing: border-box;
		width: 100%;
		min-height: 100px;
		padding: 8px;
		border: 1px solid var(--color-border);
		border-radius: 8px;
		background: var(--color-main-background);
		color: var(--color-main-text);
		font: inherit;
		resize: vertical;
	}

	.post-edit-warning {
		box-sizing: border-box;
		width: 100%;
		margin-bottom: 6px;
		padding: 8px 10px;
		border: 1px solid var(--color-border);
		border-radius: 8px;
		background: var(--color-main-background);
		color: var(--color-main-text);
	}

	.post-edit-sensitive { display: flex; align-items: center; gap: 8px; margin: 8px 0; }
	.post-edit-attachments { display: grid; gap: 8px; margin: 8px 0; }
	.post-edit-attachment { display: flex; align-items: center; gap: 8px; min-width: 0; padding: 6px; border: 1px solid var(--color-border); border-radius: 8px; }
	.post-edit-attachment img { width: 48px; height: 48px; flex: 0 0 auto; border-radius: 4px; object-fit: cover; }
	.post-edit-attachment > span { min-width: 0; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
	.post-edit-add-attachment { display: inline-flex; align-items: center; gap: 6px; width: fit-content; padding: 7px 10px; border: 1px dashed var(--color-border); border-radius: 8px; cursor: pointer; }
	.post-edit-add-attachment input { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
	.post-edit-add-attachment--disabled { opacity: .6; cursor: not-allowed; }
	.post-edit-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; margin-top: 8px; }
	.post-edit-count { margin-inline-end: auto; color: var(--color-text-lighter); font-size: 12px; }
	.post-edit-count--over { color: var(--color-error); font-weight: 600; }
}

@media (max-width: 600px) {
	.post-edit-inline .post-edit-event { grid-template-columns: 1fr; }
}
</style>
