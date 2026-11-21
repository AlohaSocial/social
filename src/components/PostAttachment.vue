<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="post-attachments">
		<!-- several photos are paged through one at a time rather than shrunk -->
		<GalleryCarousel
			v-if="isCarousel"
			ref="carousel"
			:attachments="media"
			:interactive="to === null"
			@open="openMedia" />
		<div v-else-if="mediaFirst && media.length" class="gallery-mosaic" :class="`gallery-mosaic--${media.length}`">
			<GalleryMedia
				v-for="(item, index) in media"
				:key="item.id ?? index"
				ref="frames"
				:attachment="item"
				:index="index"
				:total="media.length"
				:ratio="mosaicRatio"
				fit="contain"
				:interactive="to === null"
				@open="openMedia(index)" />
		</div>
		<div v-else class="attachments-container">
			<!-- a frame around each one, so the ALT badge has something positioned to
			     sit in without being nested inside the button -->
			<figure v-for="(item, index) in attachementsSlice" :key="index" class="attachment-frame">
				<!-- an image is opened by pressing it, so it gets a button; video and
				     audio carry their own controls, which a button may not contain -->
				<button
					v-if="isPressable(item)"
					ref="thumbnails"
					type="button"
					class="attachment"
					:aria-label="openLabel(item, index)"
					@click="openMedia(index)">
					<MediaAttachment :attachment="item" :interactive="to === null" :video="video" />
				</button>
				<!-- video and audio carry their own controls where the media is
				     the subject, and nesting those in a button is invalid. In a
				     timeline they carry none, so this one is pressable too -->
				<button
					v-else-if="to !== null"
					ref="thumbnails"
					type="button"
					class="attachment"
					:aria-label="openLabel(item, index)"
					@click="openMedia(index)">
					<MediaAttachment :attachment="item" :interactive="false" :video="video" />
				</button>
				<MediaAttachment
					v-else
					ref="thumbnails"
					:video="video"
					class="attachment"
					:attachment="item" />
				<AltBadge v-if="hasDescription(item)" :description="item.description" />
			</figure>
			<button
				v-if="media.length > 4"
				type="button"
				class="attachment more-attachments"
				:aria-label="n('social', 'Show %n more attachment', 'Show %n more attachments', media.length - 4)"
				@click="openMedia(3)">
				<span aria-hidden="true">+</span>
			</button>
		</div>
		<!-- files are named, not shown: one row each, the whole row a download -->
		<ul v-if="documents.length" class="post-attachments__files">
			<li v-for="(item, index) in documents" :key="item.id ?? `file-${index}`">
				<MediaAttachment :attachment="item" :interactive="true" :video="video" />
			</li>
		</ul>
		<!-- the modal pages on the arrow keys in the reading direction, and on a
		     swipe for a video; a photo's swipe is ZoomableImage's, which knows
		     when a drag is a pan -->
		<NcModal
			v-if="modal"
			:name="currentLabel"
			:hasPrevious="current > 0"
			:hasNext="current < (media.length - 1)"
			size="full"
			:disableSwipe="!['video', 'audio'].includes(attachments[current].type)"
			@close="closeModal"
			@previous="toPrevious"
			@next="toNext">
			<div ref="viewer" class="attachment__viewer">
				<!-- `playsinline` here too, or iOS takes the video into its own player
				     and loses the description and the paging -->
				<video
					v-if="attachments[current].type === 'video'"
					:src="attachments[current].url"
					:aria-label="attachments[current].description || ''"
					controls
					autoplay
					playsinline />
				<audio
					v-else-if="attachments[current].type === 'audio'"
					:src="attachments[current].url"
					:aria-label="attachments[current].description || ''"
					controls />
				<!-- a null `description` leaves the alt attribute off. A photo's swipe is
				     emitted here, so it pages through the same step as the modal's own
				     controls. -->
				<ZoomableImage
					v-else
					:src="attachments[current].url"
					:alt="attachments[current].description || ''"
					@previous="toPrevious"
					@next="toNext" />
			</div>
			<p v-if="attachments[current].description" class="attachment__viewer-description">
				{{ attachments[current].description }}
			</p>
			<div v-if="viewerMetadata.length" class="attachment__viewer-metadata">
				<span v-for="item in viewerMetadata" :key="item">{{ item }}</span>
			</div>
		</NcModal>
	</div>
</template>

<script>
import NcModal from '@nextcloud/vue/components/NcModal'
import AltBadge from './AltBadge.vue'
import MediaAttachment from './MediaAttachment.vue'
import GalleryCarousel from './GalleryCarousel.vue'
import ZoomableImage from './ZoomableImage.vue'
import GalleryMedia from './GalleryMedia.vue'
import { DEFAULT_RATIO, ratioOf } from './GalleryRatio.js'
import { morph } from '../utils/viewTransition.js'

import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { useServerData } from '../composables/useServerData.js'

/** from here on the set is paged through rather than laid out side by side */
const CAROUSEL_FROM = 2

export default {
	name: 'PostAttachment',
	components: {
		NcModal,
		AltBadge,
		MediaAttachment,
		GalleryCarousel,
		ZoomableImage,
		GalleryMedia,
	},

	props: {
		attachments: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').MediaAttachment[]>} */ (Array),
			default: Array,
		},

		/**
		 * Whether the media leads the post; elsewhere the text comes first and
		 * the thumbnail grid stays.
		 */
		mediaFirst: {
			type: Boolean,
			default: false,
		},

		/** What the post said about the video beyond its file: its captions and whether it is live. */
		video: {
			type: /** @type {import('vue').PropType<object|null>} */ (Object),
			default: null,
		},

		/**
		 * Where a press on the media goes, or `null` to open the viewer here. In
		 * a timeline the post is a link to itself, so its photo opens the post.
		 */
		to: {
			type: /** @type {import('vue').PropType<object|null>} */ (Object),
			default: null,
		},
	},

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	data() {
		return {
			modal: false,
			current: 0,
		}
	},

	computed: {
		/** Only metadata retained by Social is shown; camera EXIF is stripped before storage. */
		viewerMetadata() {
			const attachment = this.media[this.current]
			if (!attachment || attachment.type !== 'image') {
				return []
			}

			const metadata = []
			const original = attachment.meta?.original
			const dimensions = original?.size
				|| (original?.width && original?.height ? `${original.width} × ${original.height}` : '')
			if (dimensions) {
				metadata.push(dimensions)
			}
			if (attachment.ai_generated === true) {
				metadata.push(t('social', 'Made with AI'))
			}

			return metadata
		},

		/**
		 * @return {import('../types/Mastodon.js').MediaAttachment[]} what can be
		 * drawn or played: photos, video, audio.
		 */
		media() {
			return this.attachments.filter((item) => item?.type !== 'unknown')
		},

		/** @return {import('../types/Mastodon.js').MediaAttachment[]} the files: named and linked, never tiled */
		documents() {
			return this.attachments.filter((item) => item?.type === 'unknown')
		},

		/** What the open viewer is showing, so the dialog has a name. */
		currentLabel() {
			const attachment = this.media[this.current] ?? null

			return attachment?.description
				? attachment.description
				: t('social', 'Attachment {number} of {total}', {
						number: this.current + 1,
						total: this.media.length,
					})
		},

		/** @return {boolean} */
		isCarousel() {
			return this.mediaFirst && this.media.length >= CAROUSEL_FROM
		},

		/** @return {number} the lone attachment's natural ratio, with a safe fallback */
		mosaicRatio() {
			return ratioOf(this.media[0], DEFAULT_RATIO)
		},

		/** @return {import('../types/Mastodon.js').MediaAttachment[]} */
		attachementsSlice() {
			if (this.media.length <= 4) {
				return this.media
			} else {
				return this.media.slice(0, 3)
			}
		},
	},

	methods: {
		t,
		n,
		/**
		 * @param {import('../types/Mastodon.js').MediaAttachment} attachment the media
		 * @return {boolean} whether pressing it should open the viewer
		 */
		isPressable(attachment) {
			return attachment?.type !== 'video' && attachment?.type !== 'audio'
		},

		/**
		 * @param {import('../types/Mastodon').MediaAttachment} attachment one
		 * @return {boolean} whether it carries a description worth a badge
		 */
		hasDescription(attachment) {
			return typeof attachment?.description === 'string'
				&& attachment.description.trim() !== ''
		},

		/**
		 * What opening this attachment will show: the author's own description
		 * when there is one.
		 *
		 * @param {import('../types/Mastodon.js').MediaAttachment} attachment the media
		 * @param {number} index its place in the post
		 * @return {string} the button's accessible name
		 */
		openLabel(attachment, index) {
			return attachment.description
				? t('social', 'Open attachment: {description}', { description: attachment.description })
				: t('social', 'Open attachment {number}', { number: index + 1 })
		},

		/**
		 * The element the tapped picture is showing in, whichever layout it is.
		 *
		 * @param {number} index which attachment was tapped
		 * @return {?HTMLElement}
		 */
		frameAt(index) {
			if (this.isCarousel) {
				return /** @type {{frameAt: (index: number) => HTMLElement|null}|undefined} */ (this.$refs.carousel)?.frameAt(index) ?? null
			}

			const frame = this.$refs.frames?.[index] ?? this.$refs.thumbnails?.[index] ?? null

			return frame?.$el ?? frame ?? null
		},

		/**
		 * One photo back, and one on. The modal's controls and a swipe both land
		 * here, so paging cannot run off either end.
		 */
		toPrevious() {
			if (this.current > 0) {
				this.current--
			}
		},

		toNext() {
			if (this.current < this.media.length - 1) {
				this.current++
			}
		},

		/**
		 * A press on one of the attachments: opens the post in a timeline, or
		 * the viewer on the post's own page.
		 *
		 * @param {number} index which attachment was pressed
		 */
		openMedia(index) {
			if (this.to === null) {
				return this.showModal(index)
			}

			this.$router.push(this.to)
		},

		/**
		 * The tapped thumbnail and the opened viewer share a name for the
		 * transition, so the browser grows one into the other.
		 *
		 * @param {number} index which attachment was tapped
		 */
		async showModal(index) {
			await morph(this.frameAt(index), async () => {
				this.current = index
				this.modal = true
				await this.$nextTick()
			}, () => /** @type {HTMLElement|undefined} */ (this.$refs.viewer) ?? null)
		},

		async closeModal() {
			await morph(/** @type {HTMLElement|undefined} */ (this.$refs.viewer) ?? null, async () => {
				this.modal = false
				await this.$nextTick()
			}, () => this.frameAt(this.current))
		},
	},
}
</script>

<style lang="scss" scoped>
.post-attachments {
	.attachments-container {
		display: flex;
		flex-wrap: wrap;
		gap: 3px;
		margin-top: 14px;
		width: 100%;
		border-radius: 12px;
		overflow: hidden;
		background: var(--color-background-dark);

		.attachment-frame {
			position: relative;
			flex-grow: 1;
			flex-shrink: 1;
			flex-basis: calc(50% - 3px);
			height: 22vh;
			margin: 0;

			.attachment {
				width: 100%;
				height: 100%;
			}
		}

		.attachment {
			flex-grow: 1;
			flex-shrink: 1;
			flex-basis: calc(50% - 3px);
			cursor: pointer;
			height: 22vh;
		}

		.more-attachments {
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 42px;
			line-height: 0px;
			color: var(--color-text-lighter);

			&:hover {
				background: var(--color-background-darker);
			}
		}
	}
}

/**
 * A photo post is read photo first: the media gets the card's width and the
 * height its shape asks for, up to most of a screen.
 */
.gallery-mosaic {
	display: grid;
	gap: 4px;
	margin-block: 12px 10px;
	grid-template-columns: 1fr;

	:deep(.photo) {
		max-height: 70vh;
	}
}

.attachment__viewer {
	display: flex;
	height: 100%;
	width: 100%;
	align-content: center;
	justify-items: center;
	padding: clamp(12px, 3vh, 32px) clamp(12px, 3vw, 40px);
	box-sizing: border-box;
	background: #000;

	img {
		height: 100%;
		width: 100%;
		object-fit: contain;
	}

	&-description {
		position: absolute;
		inset-block-end: max(0px, env(safe-area-inset-bottom));
		inset-inline: 0;
		max-height: 22%;
		overflow-y: auto;
		padding: 12px 20px max(12px, env(safe-area-inset-bottom));
		text-align: center;
		color: #fff;
		background: rgba(20, 20, 20, .82);
		backdrop-filter: blur(12px);
	}

	&-metadata {
		position: absolute;
		inset-block-start: max(12px, env(safe-area-inset-top));
		inset-inline-start: 50%;
		z-index: 2;
		display: flex;
		flex-wrap: wrap;
		justify-content: center;
		gap: 6px;
		transform: translateX(-50%);

		span {
			padding: 4px 10px;
			border: 1px solid rgba(255, 255, 255, .25);
			border-radius: 999px;
			background: rgba(20, 20, 20, .82);
			color: #fff;
			font-size: 12px;
			font-weight: 600;
			white-space: nowrap;
		}
	}
}

.post-attachments__files {
	list-style: none;
	margin: 8px 0 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}
</style>
