<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- the whole gallery is one stop on the way through the post: arriving on
	     it, the arrow keys page through the pictures without having to tab past
	     one control per image -->
	<div
		class="gallery"
		role="group"
		:aria-roledescription="t('social', 'Image gallery')"
		:aria-label="galleryLabel"
		tabindex="0"
		@keydown.left.prevent="previous"
		@keydown.right.prevent="next"
		@keydown.home.prevent="show(0)"
		@keydown.end.prevent="show(attachments.length - 1)">
		<div class="gallery__stage" :style="{ aspectRatio: String(stageRatio) }">
			<div class="gallery__track" :style="{ transform: `translateX(${-current * 100}%)` }">
				<!-- every picture stays mounted, so paging to one does not start
				     its download; the ones off stage are taken out of the tab
				     order and off the accessibility tree -->
				<div
					v-for="(attachment, index) in attachments"
					:key="attachment.id ?? index"
					class="gallery__slide"
					:inert="index === current ? null : true"
					:aria-hidden="index === current ? 'false' : 'true'">
					<GalleryMedia
						ref="frames"
						:attachment="attachment"
						:interactive="interactive"
						:index="index"
						:total="attachments.length"
						:ratio="stageRatio"
						fit="contain"
						@open="$emit('open', index)" />
				</div>
			</div>
			<!-- paging wraps: a disabled control drops the focus of a reader who
			     is holding the arrow key, mid-gallery -->
			<button
				type="button"
				class="gallery__step gallery__step--previous"
				:aria-label="t('social', 'Previous image')"
				@click="previous">
				<ChevronLeft :size="24" />
			</button>
			<button
				type="button"
				class="gallery__step gallery__step--next"
				:aria-label="t('social', 'Next image')"
				@click="next">
				<ChevronRight :size="24" />
			</button>
		</div>
		<div class="gallery__pager">
			<!-- the position is spelled out, not left to the colour of a dot -->
			<p class="gallery__counter" aria-live="polite">
				{{ counterLabel }}
			</p>
			<div class="gallery__dots">
				<button
					v-for="(attachment, index) in attachments"
					:key="attachment.id ?? index"
					type="button"
					class="gallery__dot"
					:class="{ 'gallery__dot--current': index === current }"
					:aria-current="index === current ? 'true' : undefined"
					:aria-label="t('social', 'Show image {number}', { number: index + 1 })"
					@click="show(index)" />
			</div>
		</div>
	</div>
</template>

<script>
import { translate, translatePlural } from '@nextcloud/l10n'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import GalleryMedia from './GalleryMedia.vue'
import { ratioOf } from './GalleryRatio.js'

export default {
	name: 'GalleryCarousel',
	components: {
		ChevronLeft,
		ChevronRight,
		GalleryMedia,
	},

	props: {
		/** Whether the media is the subject here; see `MediaAttachment` */
		interactive: {
			type: Boolean,
			default: true,
		},

		attachments: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').MediaAttachment[]>} */ (Array),
			required: true,
		},
	},

	emits: ['open'],
	data() {
		return {
			current: 0,
		}
	},

	computed: {
		/**
		 * @return {number} one shape for every slide. The pictures of a set
		 * rarely share a ratio, and a stage that resized per slide would move
		 * the rest of the timeline on every step, so they are fitted into the
		 * shape of the first one instead of cropped to it.
		 */
		stageRatio() {
			return ratioOf(this.attachments[0] ?? null)
		},

		/** @return {string} */
		galleryLabel() {
			return translatePlural('social', 'Gallery of %n image', 'Gallery of %n images', this.attachments.length)
		},

		/** @return {string} */
		counterLabel() {
			return translate('social', '{number} of {total}', {
				number: this.current + 1,
				total: this.attachments.length,
			})
		},
	},

	watch: {
		attachments() {
			this.current = 0
		},
	},

	methods: {
		t: translate,
		/**
		 * @param {number} index which picture to bring on stage
		 */
		show(index) {
			const total = this.attachments.length
			if (total === 0) {
				return
			}

			this.current = ((index % total) + total) % total
		},

		previous() {
			this.show(this.current - 1)
		},

		next() {
			this.show(this.current + 1)
		},

		/**
		 * The element the viewer should grow out of, for the caller's transition.
		 *
		 * @param {number} index which picture is being opened
		 * @return {?HTMLElement}
		 */
		frameAt(index) {
			return this.$refs.frames?.[index]?.$el ?? null
		},
	},
}
</script>

<style scoped lang="scss">
.gallery {
	margin-block: 12px 10px;
	border-radius: var(--border-radius-large, 12px);

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}

	&__stage {
		position: relative;
		width: 100%;
		overflow: hidden;
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-background-dark);
	}

	&__track {
		display: flex;
		height: 100%;
		width: 100%;
		transition: transform .3s ease;
	}

	&__slide {
		flex: 0 0 100%;
		height: 100%;
	}

	&__step {
		position: absolute;
		inset-block-start: 50%;
		transform: translateY(-50%);
		z-index: 5;
		display: flex;
		align-items: center;
		justify-content: center;
		width: 38px;
		height: 38px;
		padding: 0;
		border: 1px solid var(--color-border-dark);
		border-radius: 50%;
		color: var(--color-main-text);
		background: var(--color-main-background);
		opacity: .85;

		&:hover,
		&:focus-visible {
			opacity: 1;
			border-color: var(--color-primary-element);
		}

		&--previous {
			inset-inline-start: 8px;
		}

		&--next {
			inset-inline-end: 8px;
		}
	}

	&__pager {
		display: flex;
		align-items: center;
		gap: 10px;
		margin-block-start: 6px;
	}

	&__counter {
		margin: 0;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	&__dots {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	/* the current dot is a longer bar, not only a differently coloured one */
	&__dot {
		width: 8px;
		height: 8px;
		padding: 0;
		border: none;
		border-radius: 4px;
		background: var(--color-border-dark);
		transition: width .2s ease, background-color .2s ease;

		&--current {
			width: 20px;
			background: var(--color-primary-element);
		}

		&:hover,
		&:focus-visible {
			background: var(--color-primary-element-hover);
		}
	}
}

@media (prefers-reduced-motion: reduce) {
	.gallery__track,
	.gallery__dot {
		transition: none;
	}
}
</style>
