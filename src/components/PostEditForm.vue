<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="post-edit-inline" @click.stop>
		<div v-if="item.event" class="post-edit-event">
			<label>
				{{ t('social', 'Event title') }}
				<input
					v-model="editEvent.name"
					type="text"
					maxlength="300"
					required>
			</label>
			<label>
				{{ t('social', 'Starts') }}
				<input v-model="editEvent.start_time" type="datetime-local" required>
			</label>
			<label>
				{{ t('social', 'Ends (optional)') }}
				<input v-model="editEvent.end_time" type="datetime-local">
			</label>
			<label>
				{{ t('social', 'Event location (optional)') }}
				<input v-model="editEvent.location" type="text" maxlength="500">
			</label>
		</div>
		<input
			v-model="editSpoiler"
			type="text"
			class="post-edit-warning"
			dir="auto"
			maxlength="200"
			:aria-label="t('social', 'Content warning')"
			:placeholder="t('social', 'Content warning, e.g. what the post is about')">
		<label class="post-edit-sensitive">
			<input v-model="editSensitive" type="checkbox">
			{{ t('social', 'Mark media as sensitive') }}
		</label>
		<div class="post-edit-media">
			<div v-for="(media, index) in editMedia" :key="media.id" class="post-edit-media__item">
				<label>
					<span>{{ t('social', 'Description for attachment {number}', { number: index + 1 }) }}</span>
					<div class="post-edit-media__attachment">
						<img
							v-if="media.previewUrl"
							class="post-edit-media__thumbnail"
							:src="media.previewUrl"
							:alt="t('social', 'Preview of attachment {number}', { number: index + 1 })">
						<textarea
							v-model="media.description"
							maxlength="1500"
							:aria-label="t('social', 'Description for attachment {number}', { number: index + 1 })"
							:placeholder="t('social', 'Describe this media for people who cannot see it')" />
					</div>
				</label>
				<NcButton
					variant="tertiary"
					:aria-label="t('social', 'Remove attachment {number}', { number: index + 1 })"
					@click.stop="removeEditMedia(index)">
					{{ t('social', 'Remove attachment') }}
				</NcButton>
			</div>
			<label class="post-edit-media__add">
				<span>{{ t('social', 'Add media') }}</span>
				<input
					type="file"
					accept="image/*,video/*,audio/*"
					multiple
					:disabled="editMedia.length >= 10 || editMediaUploading"
					@change="addEditMedia">
			</label>
		</div>
		<textarea
			ref="editInput"
			v-model="editContent"
			class="post-edit-textarea"
			dir="auto"
			:maxlength="maxLength"
			:aria-describedby="editIsTooLong ? `post-edit-count-${item.id}` : undefined"
			:placeholder="t('social', 'Edit your post')"
			@keydown.ctrl.enter="save" />
		<div class="post-edit-actions">
			<span
				:id="`post-edit-count-${item.id}`"
				class="post-edit-count"
				:class="{ 'post-edit-count--over': editIsTooLong }"
				role="status">
				{{ editCharactersLeftLabel }}
			</span>
			<NcButton
				variant="primary"
				:disabled="!editCanSave || editMediaUploading"
				:aria-label="t('social', 'Save')"
				@click.stop="save">
				{{ t('social', 'Save') }}
			</NcButton>
			<NcButton :aria-label="t('social', 'Cancel')" @click.stop="$emit('cancel')">
				{{ t('social', 'Cancel') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { mapStores } from 'pinia'
import { htmlToPlainText } from '../utils/plainText.js'
import { useTimelineStore } from '../store/timeline.js'

export default {
	name: 'PostEditForm',
	components: { NcButton },
	props: {
		item: { type: Object, required: true },
		maxLength: { type: Number, required: true },
	},

	emits: ['save', 'cancel'],
	data() {
		const media = (this.item.media_attachments || []).map((attachment) => ({
			id: attachment.id,
			description: attachment.description || '',
			previewUrl: attachment.preview_url || (attachment.type === 'image' ? attachment.url : ''),
		}))
		return {
			editContent: htmlToPlainText(this.item.content || ''),
			editSpoiler: this.item.spoiler_text || '',
			editSensitive: this.item.sensitive === true,
			editMedia: media,
			editOriginalMediaIds: media.map(({ id }) => String(id)),
			editMediaUploading: false,
			editEvent: {
				name: this.item.event?.name || '',
				start_time: this.toLocalDateTime(this.item.event?.start_time),
				end_time: this.toLocalDateTime(this.item.event?.end_time),
				location: this.item.event?.location || '',
			},
		}
	},

	computed: {
		...mapStores(useTimelineStore),
		editCharsLeft() {
			return this.maxLength - this.editContent.length
		},

		editIsTooLong() {
			return this.editCharsLeft < 0
		},

		editCanSave() {
			return (this.editContent.trim() !== '' || this.editMedia.length > 0 || Boolean(this.item.event))
				&& !this.editIsTooLong && this.editEventIsValid
		},

		editMediaChanged() {
			return JSON.stringify(this.editMedia.map(({ id }) => String(id))) !== JSON.stringify(this.editOriginalMediaIds)
		},

		editEventIsValid() {
			if (!this.item.event) {
				return true
			}
			return this.editEvent.name.trim() !== ''
				&& this.editEvent.start_time !== ''
				&& (!this.editEvent.end_time || this.editEvent.end_time > this.editEvent.start_time)
		},

		editCharactersLeftLabel() {
			return this.editIsTooLong
				? n('social', '%n character too many', '%n characters too many', -this.editCharsLeft)
				: n('social', '%n character left', '%n characters left', this.editCharsLeft)
		},
	},

	mounted() {
		this.$nextTick(() => {
			const input = this.$refs.editInput
			if (input) {
				input.focus()
			}
		})
	},

	methods: {
		t,

		toLocalDateTime(value) {
			if (!value) {
				return ''
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime())
				? ''
				: new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
		},

		fromLocalDateTime(value) {
			if (!value) {
				return null
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? null : date.toISOString()
		},

		removeEditMedia(index) {
			this.editMedia.splice(index, 1)
		},

		async addEditMedia(event) {
			const files = Array.from(event.target.files || []).slice(0, Math.max(0, 10 - this.editMedia.length))
			event.target.value = ''
			if (files.length === 0) {
				return
			}

			this.editMediaUploading = true
			try {
				for (const file of files) {
					const uploaded = await this.timelineStore.createMedia(file)
					if (uploaded) {
						this.editMedia.push({
							id: uploaded.id,
							description: uploaded.description || '',
							previewUrl: uploaded.preview_url || uploaded.url || '',
						})
					}
				}
			} finally {
				this.editMediaUploading = false
			}
		},

		save() {
			if (!this.editCanSave || this.editMediaUploading) {
				return
			}
			const event = this.item.event
				? {
						name: this.editEvent.name.trim(),
						start_time: this.fromLocalDateTime(this.editEvent.start_time),
						end_time: this.fromLocalDateTime(this.editEvent.end_time),
						location: this.editEvent.location.trim(),
					}
				: undefined
			this.$emit('save', {
				content: this.editContent.trim(),
				spoiler_text: this.editSpoiler.trim(),
				sensitive: this.editSensitive,
				media_attributes: this.editMedia.map(({ id, description }) => ({ id, description })),
				...(this.editMediaChanged ? { media_ids: this.editMedia.map(({ id }) => id) } : {}),
				...(event === undefined ? {} : { event }),
			})
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
	.post-edit-media { display: grid; gap: 8px; margin: 8px 0; }
	.post-edit-media label { display: grid; gap: 4px; font-size: .9rem; }
	.post-edit-media__item { display: grid; gap: 4px; padding: 8px; border: 1px solid var(--color-border); border-radius: 8px; }
	.post-edit-media__attachment { display: flex; align-items: flex-start; gap: 8px; }
	.post-edit-media__thumbnail { width: 72px; height: 72px; flex: 0 0 72px; object-fit: cover; border-radius: 6px; }
	.post-edit-media__attachment textarea { flex: 1; min-width: 0; }
	.post-edit-media textarea { box-sizing: border-box; width: 100%; min-height: 64px; padding: 8px; border: 1px solid var(--color-border); border-radius: 8px; background: var(--color-main-background); color: var(--color-main-text); font: inherit; resize: vertical; }
	.post-edit-media__add { padding: 8px; border: 1px dashed var(--color-border); border-radius: 8px; cursor: pointer; }
	.post-edit-media__add input { display: block; max-width: 100%; margin-top: 4px; }
	.post-edit-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; margin-top: 8px; }
	.post-edit-count { margin-inline-end: auto; color: var(--color-text-lighter); font-size: 12px; }
	.post-edit-count--over { color: var(--color-error); font-weight: 600; }
}

@media (max-width: 600px) {
	.post-edit-inline .post-edit-event { grid-template-columns: minmax(0, 1fr); }
}
</style>
