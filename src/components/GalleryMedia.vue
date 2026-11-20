<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<figure
		class="photo"
		:class="`photo--${fit}`"
		:style="frameStyle">
		<!-- where the media is the subject, video and audio keep their own
		     controls, which cannot sit inside a button; elsewhere it is all pressable -->
		<button
			v-if="pressable || !interactive"
			type="button"
			class="photo__open"
			:aria-label="openLabel"
			@click="$emit('open')">
			<MediaAttachment :attachment="attachment" :interactive="interactive" />
		</button>
		<MediaAttachment v-else :attachment="attachment" />
		<!-- the description is useful to sighted readers too -->
		<AltBadge v-if="hasDescription" :description="attachment.description" />
	</figure>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import AltBadge from './AltBadge.vue'
import MediaAttachment from './MediaAttachment.vue'
import { ratioOf } from './GalleryRatio.js'

export default {
	name: 'GalleryMedia',
	components: {
		AltBadge,
		MediaAttachment,
	},

	props: {
		attachment: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').MediaAttachment>} */ (Object),
			required: true,
		},

		/**
		 * Whether the media is what the reader came to see: a video then plays
		 * here instead of opening the post. Passed on to `MediaAttachment`.
		 */
		interactive: {
			type: Boolean,
			default: true,
		},

		/** its place in the post, for the label of media without a description */
		index: {
			type: Number,
			default: 0,
		},

		total: {
			type: Number,
			default: 1,
		},

		/**
		 * `cover` fills a box shaped like the picture itself; `contain` fits it
		 * into a box shaped like something else, which is what a carousel of
		 * mixed portraits and landscapes needs.
		 */
		fit: {
			type: String,
			default: 'cover',
			validator: (value) => ['cover', 'contain'].includes(String(value)),
		},

		/** the box to reserve, overriding the picture's own shape; 0 to derive it */
		ratio: {
			type: Number,
			default: 0,
		},
	},

	emits: ['open'],
	computed: {
		/** @return {boolean} */
		hasDescription() {
			return typeof this.attachment.description === 'string'
				&& this.attachment.description.trim() !== ''
		},

		/** @return {boolean} whether pressing it should open the viewer */
		pressable() {
			return this.attachment.type !== 'video' && this.attachment.type !== 'audio'
		},

		/** @return {boolean} audio has no picture to reserve room for */
		framed() {
			return this.attachment.type !== 'audio'
		},

		/** @return {object} */
		frameStyle() {
			if (!this.framed) {
				return {}
			}

			return { aspectRatio: String(this.ratio > 0 ? this.ratio : ratioOf(this.attachment)) }
		},

		/** @return {string} what opening this attachment will show */
		openLabel() {
			return this.hasDescription
				? t('social', 'Open attachment: {description}', { description: this.attachment.description })
				: t('social', 'Open attachment {number} of {total}', {
						number: this.index + 1,
						total: this.total,
					})
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.photo {
	position: relative;
	margin: 0;
	width: 100%;
	/* A tall frame (a 9:16 short) would push everything off screen: the height
	   is capped and the media drawn smaller in its own proportions, never cropped. */
	max-height: 80vh;
	overflow: hidden;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-dark);

	&--contain {
		:deep(.attachment__preview) {
			object-fit: contain;
		}
	}

	&__open {
		display: block;
		padding: 0;
		margin: 0;
		border: none;
		width: 100%;
		height: 100%;
		background: transparent;
		cursor: zoom-in;
	}
}
</style>
