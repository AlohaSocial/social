<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="post-attachments">
		<!-- multiple pictures are a set, not a stack of thumbnails: they are
		     paged through one at a time instead of shrunk until nothing in them
		     can be made out -->
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
			<!-- a frame around each one, so the ALT badge has something
			     positioned to sit in the corner of and does not have to be
			     nested inside the button it would otherwise be part of -->
			<figure v-for="(item, index) in attachementsSlice" :key="index" class="attachment-frame">
				<!-- an image is opened by pressing it, so it gets a button.
				     Video and audio carry their own controls: nesting those
				     inside a button is invalid, and they need no viewer to be
				     watchable where they are -->
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
		<!-- the modal pages on the arrow keys in the reading direction, and on
		     a swipe for a video; a picture's swipe is ZoomableImage's, which
		     knows when the picture is zoomed and a drag is a pan -->
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
				<!-- `playsinline` here too: the lightbox *is* the full-screen
				     view, and iOS taking it into its own player on top of that
				     loses the description below and the paging either side -->
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
				<!-- `description` is null for an attachment whose author gave it
				     no alt text, and a null alt is no alt attribute at all.

				     A picture in the lightbox can be zoomed into and swiped
				     between; the modal's own controls still page it, and the
				     swipe is emitted here so both go through the same step. -->
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
import { nameForTransition, withViewTransition } from '../utils/viewTransition.js'

/** one name per document: only one lightbox is ever open */
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { useServerData } from '../composables/useServerData.js'

const MEDIA_TRANSITION = 'social-media'

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
		 * Whether the media leads the post. The thumbnail grid stays for the
		 * places where the text comes first, so nothing but a picture post
		 * changes shape.
		 */
		mediaFirst: {
			type: Boolean,
			default: false,
		},

		/**
		 * Where a press on the media goes, or `null` to open the viewer here.
		 *
		 * A post in a timeline is a link to itself: pressing its picture opens
		 * the post, with its replies, and the picture opens full size from
		 * there. Pressing it in the timeline used to open the viewer straight
		 * away, which put the reader in a lightbox over a conversation they
		 * had not seen.
		 *
		 * @type {import('vue').PropType<object|null>}
		 */
		/**
		 * What the post said about the video beyond its file: the captions,
		 * whether it is live. One `Video` object carries one set of those, so
		 * it belongs to the post rather than to any one attachment.
		 */
		video: {
			type: /** @type {import('vue').PropType<object|null>} */ (Object),
			default: null,
		},

		to: {
			type: Object,
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
		 * drawn or played: pictures, video, audio. Everything the mosaic, the
		 * carousel and the viewer work on.
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
		 * What opening this attachment will show. The author's own description
		 * when there is one, because that is the only thing that says what the
		 * picture actually is.
		 *
		 * @param {import('../types/Mastodon.js').MediaAttachment} attachment the media
		 * @param {number} index its place in the post
		 * @return {string} the button's accessible name
		 */
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
		 * The tapped thumbnail and the opened viewer share a name for the
		 * length of the transition, so the browser grows one into the other
		 * instead of the picture appearing from nowhere.
		 *
		 * @param {number} index which attachment was tapped
		 */
		/**
		 * A press on one of the attachments.
		 *
		 * Either it opens the post — in a timeline, where the picture is a
		 * link to the conversation it belongs to — or it opens the viewer,
		 * which is what the post's own page does.
		 *
		 * @param {number} index which attachment was pressed
		 */
		/**
		 * One picture back, and one on. The modal's own controls and a swipe
		 * across the picture both land here, so paging cannot run off either
		 * end however it was asked for — a swipe has no disabled state to stop
		 * it the way the modal's buttons do.
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

		openMedia(index) {
			if (this.to === null) {
				return this.showModal(index)
			}

			this.$router.push(this.to)
		},

		async showModal(index) {
			const thumbnail = this.frameAt(index)
			const release = nameForTransition(thumbnail, MEDIA_TRANSITION)

			await withViewTransition(async () => {
				this.current = index
				this.modal = true
				await this.$nextTick()
				nameForTransition(/** @type {HTMLElement|undefined} */ (this.$refs.viewer) ?? null, MEDIA_TRANSITION)
			})

			release()
		},

		async closeModal() {
			const release = nameForTransition(/** @type {HTMLElement|undefined} */ (this.$refs.viewer) ?? null, MEDIA_TRANSITION)
			const thumbnail = this.frameAt(this.current)

			await withViewTransition(async () => {
				this.modal = false
				await this.$nextTick()
				nameForTransition(thumbnail, MEDIA_TRANSITION)
			})

			release()
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
 * A picture post is read picture first, so the media gets the width of the
 * card and as much height as its own shape asks for, up to most of a screen.
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
