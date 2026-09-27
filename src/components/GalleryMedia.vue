<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<figure
		class="photo"
		:class="`photo--${fit}`"
		:style="frameStyle">
		<!-- video and audio carry their own controls where the media is the
		     subject, and nesting those inside a button is invalid. Where it is
		     not — a timeline, where the post is a link to itself — they carry
		     none, so everything here is pressable -->
		<button
			v-if="pressable || !interactive"
			type="button"
			class="photo__open"
			:aria-label="openLabel"
			@click="$emit('open')">
			<MediaAttachment :attachment="attachment" :interactive="interactive" />
		</button>
		<MediaAttachment v-else :attachment="attachment" />
		<!-- the description is what the picture actually is, and a reader who
		     cannot see the picture is not the only one who wants it -->
		<AltBadge v-if="hasDescription" :description="attachment.description" />
	</figure>
</template>

<script>
import { translate } from '@nextcloud/l10n'
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
		 * Whether the media is the thing the reader came to look at, which
		 * decides whether a video plays here or opens the post. See
		 * `MediaAttachment`, which the flag is for.
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
				? translate('social', 'Open attachment: {description}', { description: this.attachment.description })
				: translate('social', 'Open attachment {number} of {total}', {
						number: this.index + 1,
						total: this.total,
					})
		},
	},

	methods: {
		t: translate,
	},
}
</script>

<style scoped lang="scss">
.photo {
	position: relative;
	margin: 0;
	width: 100%;
	/* A frame is the shape its media is, so a 9:16 short in a 600px column
	   wants to be over a thousand pixels tall and pushes everything under it
	   off the screen. The height is capped instead of the shape: what will
	   not fit is drawn smaller, inside a frame that keeps its own proportions
	   -- the picture is never cut to make it shorter. */
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
