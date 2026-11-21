<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- not focusable on purpose: an image is already inside the parent's
	     button, and video and audio carry their own controls -->
	<div
		class="attachment"
		role="presentation"
		@click="$emit('click')">
		<!-- `playsinline`, or iOS takes every video full screen as it starts.
		     `controls` only where the media is the thing on screen: in a timeline
		     the post is a link to itself, and a player would swallow that click. -->
		<video
			v-if="hasSource && attachment.type === 'video'"
			ref="video"
			class="attachment__preview"
			:src="plainSource"
			:poster="poster"
			:aria-label="attachment.description || ''"
			:controls="interactive"
			:preload="interactive ? preload : 'none'"
			playsinline
			@click="onMediaClick"
			@loadedmetadata="previewLoaded = true">
			<!-- the subtitle tracks the video published (PeerTube's `url` list) -->
			<track
				v-for="caption in captions"
				:key="caption.url"
				kind="subtitles"
				:src="caption.url"
				:srclang="caption.language"
				:label="caption.language || t('social', 'Subtitles')">
		</video>
		<audio
			v-else-if="hasSource && attachment.type === 'audio'"
			class="attachment__audio"
			:src="attachment.url"
			:aria-label="attachment.description || ''"
			:controls="interactive"
			preload="metadata"
			@click="onMediaClick"
			@loadedmetadata="previewLoaded = true" />
		<!-- a file has nothing to draw, so it is named; the whole card is the link -->
		<a
			v-else-if="hasSource && attachment.type === 'unknown'"
			class="attachment__file"
			:href="attachment.url"
			target="_blank"
			rel="noopener"
			download
			@click.stop>
			<FileDocumentOutline :size="28" class="attachment__file-icon" />
			<span class="attachment__file-text">
				<span class="attachment__file-name">{{ fileName }}</span>
				<span v-if="fileKind" class="attachment__file-kind">{{ fileKind }}</span>
			</span>
		</a>
		<template v-else>
			<canvas
				ref="canvas"
				class="attachment__blurhash"
				:class="{ 'attachment__blurhash--hidden': previewLoaded }" />
			<!-- fetched as it comes into view and decoded off the main thread, with the
			     blurhash holding the frame; a dense screen picks the original -->
			<img
				v-if="hasPreview && !previewFailed"
				class="attachment__preview attachment__preview--fading"
				:class="{ 'attachment__preview--shown': previewLoaded }"
				:src="attachment.preview_url"
				:srcset="srcset"
				:sizes="srcsetSizes"
				:alt="attachment.description || ''"
				loading="lazy"
				decoding="async"
				@load="previewLoaded = true"
				@error="onPreviewError">
			<!-- for media that has gone away, and media with no preview -->
			<span
				v-if="showsPlaceholder"
				class="attachment__failed"
				role="img"
				:aria-label="placeholderLabel"
				:title="placeholderLabel">
				<ImageOff :size="32" />
			</span>
		</template>
		<NcLoadingIcon v-if="attachment === null || (!previewLoaded && !showsPlaceholder && !isAv && !isFile)" :size="40" />
	</div>
</template>

<script>
import { decode } from 'blurhash'
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import ImageOff from 'vue-material-design-icons/ImageOff.vue'
import logger from '../services/logger.js'
import { attachHls, isPlaylist, ladderSourceOf, posterOf } from '../services/videoSource.js'

/**
 * The address the element is pointed at: the still of a picture, the file
 * of anything that plays. '' when there is nothing to load.
 *
 * @param {object|null|undefined} attachment the attachment
 * @return {string}
 */
function sourceOf(attachment) {
	if (!attachment) {
		return ''
	}
	const plays = attachment.type === 'video' || attachment.type === 'audio'

	return String((plays ? attachment.url : attachment.preview_url) ?? '')
}

/**
 * The widest original offered beside the preview. A camera's full-size file
 * is several megabytes; past this width it costs far more than a timeline
 * frame can show, so the preview alone is used.
 */
const SRCSET_MAX_WIDTH = 2560

/**
 * How wide the picture is drawn, for the browser to choose between preview
 * and original. `auto` lets a browser that can measure the lazily loaded
 * frame do so; the rest is the widest a timeline column gets.
 */
const SRCSET_SIZES = 'auto, (max-width: 700px) 100vw, 700px'

/** How many pixels the long side of a blurhash placeholder is drawn at. */
const BLURHASH_PX = 32

/**
 * The bitmap a blurhash is decoded into: `BLURHASH_PX` on the long side, the
 * short side in the preview's own ratio, never under a pixel.
 *
 * @param {{width: number, height: number}} small the preview's size
 * @return {{width: number, height: number}}
 */
function blurhashSize(small) {
	const scale = BLURHASH_PX / Math.max(small.width, small.height, 1)

	return {
		width: Math.max(1, Math.round(small.width * scale)),
		height: Math.max(1, Math.round(small.height * scale)),
	}
}

/**
 * A storage key can locate a file without naming it for a reader.
 *
 * @param {string} name a possible filename
 * @return {boolean}
 */
function isOpaqueFileName(name) {
	const stem = name.replace(/\.[^.]+$/, '')

	return /^\d+$/.test(stem)
		|| /^[0-9a-f]{8}-[0-9a-f-]{27,}$/i.test(stem)
		|| /^baf[a-z2-7]{20,}$/i.test(stem)
		|| /^[a-z0-9_-]{40,}$/i.test(stem)
}

export default {
	name: 'MediaAttachment',
	components: {
		FileDocumentOutline,
		ImageOff,
		NcLoadingIcon,
	},

	props: {
		attachment: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon').MediaAttachment>} */ (Object),
			default: null,
		},

		/**
		 * Whether this is the copy the reader came to look at: on everywhere but a
		 * timeline, where the post is a link to itself and a player would
		 * intercept the click.
		 */
		interactive: {
			type: Boolean,
			default: true,
		},

		/**
		 * What the post said about the video beyond the file: its captions and
		 * whether it is live. These are facts about the post, not the attachment.
		 */
		video: {
			type: /** @type {import('vue').PropType<object|null>} */ (Object),
			default: null,
		},
	},

	emits: ['click'],

	data() {
		return {
			previewLoaded: false,
			previewFailed: false,
			/** the hls.js player, where one was needed; not reactive */
			hls: null,
		}
	},

	computed: {
		/**
		 * The subtitle tracks the video published, if any.
		 *
		 * @return {Array<{language: string, url: string}>}
		 */
		captions() {
			return Array.isArray(this.video?.captions) ? this.video.captions : []
		},

		/**
		 * Whether this needs hls.js rather than a `src` a browser can open: a
		 * remote video that is nothing but an HLS playlist.
		 *
		 * @return {boolean}
		 */
		isPlaylist() {
			return isPlaylist(this.attachment)
		},

		/**
		 * The master playlist of a *local* video's ladder, where there is one;
		 * see `ladderSourceOf()`.
		 *
		 * @return {string}
		 */
		ladderSource() {
			return ladderSourceOf(this.attachment)
		},

		/**
		 * The `src` the element itself carries: empty for a playlist, which hls.js
		 * hands to the element. A laddered video keeps its plain `src` for before
		 * the library loads, if the ladder fails, and for browsers without HLS.
		 *
		 * @return {string|undefined}
		 */
		plainSource() {
			if (!this.interactive || this.isPlaylist) {
				return undefined
			}

			return this.attachment.url
		},

		/** @return {boolean} */
		isAv() {
			return this.attachment?.type === 'video' || this.attachment?.type === 'audio'
		},

		/**
		 * Whether there are bytes to point at. An attachment this instance holds no
		 * copy of (refused by the cache limits, or not fetched yet) has `url: null`,
		 * and the placeholder says so.
		 *
		 * @return {boolean}
		 */
		hasSource() {
			return this.attachment !== null
				&& typeof this.attachment.url === 'string'
				&& this.attachment.url !== ''
		},

		/** @return {boolean} whether this is a file rather than something to draw or play */
		isFile() {
			return this.attachment?.type === 'unknown'
		},

		/**
		 * @return {string} what the file is called: the description the server
		 * filled in from the upload's name, else the last bit of its address
		 */
		fileName() {
			const description = (this.attachment?.description || '').trim()
			if (description !== '' && !isOpaqueFileName(description)) {
				return description
			}
			const path = (this.attachment?.url || this.attachment?.remote_url || '').split('?')[0]
			const tail = path.split('/').pop() || ''
			let name = tail
			try {
				name = decodeURIComponent(tail)
			} catch {
				// a malformed escape is not a reason to break the attachment card
			}
			if (name !== '' && !isOpaqueFileName(name)) {
				return name
			}

			// remote posts may have stored only a numeric media id rather than the
			// filename; the MIME type still says what the download contains
			const mediaType = String(this.attachment?.media_type || '').toLowerCase()
			const [major] = mediaType.split('/', 2)
			const category = ({
				application: t('social', 'document'),
				audio: t('social', 'audio file'),
				image: t('social', 'image file'),
				text: t('social', 'text file'),
				video: t('social', 'video file'),
			})[major] || t('social', 'file')
			const kind = this.fileKind

			return kind ? t('social', '{kind} {category}', { kind, category }) : category
		},

		/** @return {string} a useful file kind, from its extension or media type */
		fileKind() {
			// streamed Bluesky files use opaque routes without an extension; their
			// validated name is in `description`
			const description = String(this.attachment?.description || '').trim()
			const path = (this.attachment?.url || this.attachment?.remote_url || '').split('?')[0]
			const match = /\.([a-z0-9]{1,8})$/i.exec(description) || /\.([a-z0-9]{1,8})$/i.exec(path)
			if (match) {
				return match[1].toUpperCase()
			}
			const mediaType = String(this.attachment?.media_type || '').toLowerCase()
			const knownKinds = {
				'application/epub+zip': 'EPUB',
				'application/gzip': 'GZIP',
				'application/msword': 'DOC',
				'application/pdf': 'PDF',
				'application/vnd.ms-excel': 'XLS',
				'application/vnd.ms-powerpoint': 'PPT',
				'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'PPTX',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'XLSX',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'DOCX',
				'application/vnd.oasis.opendocument.presentation': 'ODP',
				'application/vnd.oasis.opendocument.spreadsheet': 'ODS',
				'application/vnd.oasis.opendocument.text': 'ODT',
				'application/x-zip-compressed': 'ZIP',
				'application/zip': 'ZIP',
				'audio/aac': 'AAC',
				'audio/flac': 'FLAC',
				'audio/mp4': 'M4A',
				'audio/mpeg': 'MP3',
				'audio/ogg': 'OGG',
				'audio/opus': 'OPUS',
				'audio/wav': 'WAV',
				'image/avif': 'AVIF',
				'image/gif': 'GIF',
				'image/heic': 'HEIC',
				'image/heif': 'HEIF',
				'image/jpeg': 'JPEG',
				'image/jpg': 'JPEG',
				'image/png': 'PNG',
				'image/webp': 'WEBP',
				'video/mp4': 'MP4',
				'video/quicktime': 'MOV',
				'video/webm': 'WEBM',
				'text/csv': 'CSV',
				'text/markdown': 'MD',
				'text/plain': 'TXT',
			}

			return knownKinds[mediaType] || ''
		},

		/**
		 * The still to show before anything is played: a federated video's
		 * mirrored one, or an upload's poster frame; see `posterOf()`.
		 *
		 * @return {string|undefined} undefined leaves the attribute off
		 */
		poster() {
			return posterOf(this.attachment)
		},

		/**
		 * How much of the video to fetch before anybody has asked to watch it: with
		 * a poster nothing, so a page of federated videos opens no connections;
		 * without one the headers, to have a first frame.
		 *
		 * @return {string}
		 */
		preload() {
			return this.poster === undefined ? 'metadata' : 'none'
		},

		/**
		 * Whether there is a preview to wait for at all. Vue drops a null
		 * `preview_url`, and then neither @load nor @error is guaranteed to fire.
		 *
		 * @return {boolean}
		 */
		hasPreview() {
			return this.attachment !== null
				&& typeof this.attachment.preview_url === 'string'
				&& this.attachment.preview_url !== ''
		},

		/**
		 * The preview and the original as width candidates, where both are
		 * known and the original is wider and not too large. An animated GIF
		 * keeps its still preview.
		 *
		 * @return {string|undefined} undefined leaves the attribute off
		 */
		srcset() {
			const attachment = this.attachment
			if (attachment?.type !== 'image' || !this.hasPreview || !this.hasSource
				|| attachment.url === attachment.preview_url
				|| String(attachment.media_type ?? '').toLowerCase() === 'image/gif') {
				return undefined
			}

			const small = Number(attachment.meta?.small?.width)
			const original = Number(attachment.meta?.original?.width)
			if (!(small > 0) || !(original > small) || original > SRCSET_MAX_WIDTH) {
				return undefined
			}

			return `${attachment.preview_url} ${small}w, ${attachment.url} ${original}w`
		},

		/** @return {string|undefined} the `sizes` the srcset is chosen with */
		srcsetSizes() {
			return this.srcset === undefined ? undefined : SRCSET_SIZES
		},

		/** @return {boolean} whether the still-image placeholder is on screen */
		showsPlaceholder() {
			if (this.attachment === null) {
				return false
			}

			// nothing to play and nothing to download either: the placeholder
			// is the only thing that can say so
			if (!this.hasSource) {
				return true
			}

			return !this.isAv && (this.previewFailed || !this.hasPreview)
		},

		/** @return {string} what the placeholder stands for */
		placeholderLabel() {
			const description = this.attachment?.description
			const cacheError = Number(this.attachment?.cache_error ?? 0)
			if (cacheError > 0) {
				const reason = {
					1: t('social', 'The image exceeds this instance’s media size limit.'),
					2: t('social', 'This image type is not allowed by this instance.'),
					3: t('social', 'This instance could not access the original image.'),
					4: t('social', 'This instance could not read the image.'),
				}[cacheError] ?? t('social', 'This instance could not cache the image.')

				return description ? `${reason} ${description}` : reason
			}
			if (this.previewFailed) {
				return description
					? t('social', 'Attachment could not be loaded: {description}', { description })
					: t('social', 'Attachment could not be loaded')
			}

			return description
				? t('social', 'No preview available: {description}', { description })
				: t('social', 'No preview available')
		},
	},

	watch: {
		/**
		 * A status refreshed from the server replaces its attachment objects without
		 * changing what they point at; the element keeps its `src` and fires no
		 * second `load`, so the loaded state must not be reset then.
		 *
		 * @param {object|null} now the attachment as it is
		 * @param {object|null} before the attachment it replaced
		 */
		attachment(now, before) {
			if (now?.id === before?.id && sourceOf(now) === sourceOf(before)) {
				this.readPreviewState()
				return
			}

			this.previewLoaded = false
			this.previewFailed = false
			this.drawBlurhash()
			this.attachPlaylist()
		},
	},

	mounted() {
		this.drawBlurhash()
		this.attachPlaylist()
	},

	beforeUnmount() {
		this.detachPlaylist()
	},

	methods: {
		t,

		/**
		 * Points the element at an HLS playlist or a ladder, through hls.js
		 * where the browser cannot open one itself (`attachHls()`). Only where the
		 * media is the thing on screen, not for a timeline thumbnail.
		 *
		 * @return {Promise<void>}
		 */
		async attachPlaylist() {
			this.detachPlaylist()

			if (!this.interactive) {
				return
			}

			const element = /** @type {HTMLVideoElement} */ (this.$refs.video)
			this.hls = await attachHls(element, this.attachment, {
				// the component may have been torn down while the library was fetched
				isCurrent: () => this.$refs.video === element,
				onFallback: () => {
					this.hls = null
				},
			})
		},

		/** Lets go of the player, which otherwise goes on fetching segments. */
		detachPlaylist() {
			if (this.hls) {
				this.hls.destroy()
				this.hls = null
			}
		},

		/**
		 * A press on the player itself: where the media is interactive the player
		 * handles it; otherwise it belongs to whatever wraps this, in a timeline the
		 * link to the post.
		 *
		 * @param {Event} event the press
		 */
		onMediaClick(event) {
			if (this.interactive) {
				event.stopPropagation()
			}
		},

		onPreviewError() {
			this.previewFailed = true
			this.previewLoaded = false
		},

		/**
		 * Takes the loaded state from the picture itself, for a `load` that
		 * has already fired or that the browser answered from its cache.
		 */
		readPreviewState() {
			const image = /** @type {HTMLImageElement|null} */ (this.$el?.querySelector?.('img.attachment__preview') ?? null)
			if (image !== null && image.complete && image.naturalWidth > 0) {
				this.previewLoaded = true
			}
		},

		drawBlurhash() {
			if (this.isAv || this.attachment?.meta?.small?.width === undefined) {
				return
			}

			// an image GD could not read arrives with sizes and an empty blurhash,
			// and decode('') throws
			const blurhash = this.attachment.blurhash
			if (typeof blurhash !== 'string' || blurhash.length < 6) {
				return
			}

			if (!this.$refs.canvas) {
				return
			}

			try {
				const canvas = /** @type {HTMLCanvasElement} */ (this.$refs.canvas)
				// a hash holds a few colour components, so it is decoded at a small size
				// and scaled
				const { width, height } = blurhashSize(this.attachment.meta.small)
				canvas.width = width
				canvas.height = height
				const ctx = canvas.getContext('2d')
				const imageData = ctx.createImageData(width, height)
				const pixels = decode(blurhash, width, height)
				imageData.data.set(pixels)
				ctx.putImageData(imageData, 0, 0)
			} catch (error) {
				// a malformed hash is not worth losing the attachment over
				logger.debug('Could not draw the blurhash placeholder', { error })
			}
		},
	},
}
</script>

<style scoped lang="scss">
/* the card a file is shown as: its name, its kind, and all of it a link */
.attachment__file {
	display: flex;
	align-items: center;
	gap: 12px;
	width: 100%;
	min-height: 64px;
	padding: 12px 16px;
	box-sizing: border-box;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	text-decoration: none;

	&:hover,
	&:focus-visible {
		border-color: var(--color-primary-element);
	}
}

.attachment__file-icon {
	flex: none;
	color: var(--color-primary-element);
}

.attachment__file-text {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.attachment__file-name {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.attachment__file-kind {
	font-size: 12px;
	letter-spacing: .04em;
	color: var(--color-text-maxcontrast);
}

.attachment {
	position: relative;
	height: 100%;
	width: 100%;

	&__blurhash {
		position: absolute;
		/* both edges: with no inline offset the box keeps its static position,
		   which the wrapping `<button>` centres */
		inset-inline-start: 0;
		top: 0;
		height: 100%;
		width: 100%;
		object-fit: cover;
		z-index: 1;
		transition: opacity .4s ease;

		&--hidden {
			opacity: 0;
		}
	}

	&__preview {
		position: absolute;
		inset-inline-start: 0;
		top: 0;
		height: 100%;
		width: 100%;
		object-fit: cover;
		z-index: 2;

		/* the image resolves out of its own blur rather than replacing it */
		&--fading {
			opacity: 0;
			transform: scale(1.02);
			transition: opacity .4s ease, transform .4s ease;
		}

		&--shown {
			opacity: 1;
			transform: scale(1);
		}
	}

	&__failed {
		position: absolute;
		inset: 0;
		z-index: 3;
		display: flex;
		align-items: center;
		justify-content: center;
		color: var(--color-text-maxcontrast);
	}

	.loading-icon {
		position: absolute;
		top: calc(50% - 20px);
		inset-inline-start: calc(50% - 20px);
		z-index: 3;
	}
}

@media (prefers-reduced-motion: reduce) {
	.attachment__blurhash,
	.attachment__preview--fading {
		transition: none;
	}

	.attachment__preview--fading {
		transform: none;
	}
}
</style>
