<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- not focusable on purpose: an image here is already inside the parent's
	     button, and video and audio carry their own controls. The click is a
	     convenience for a pointer, never the only way to reach anything -->
	<div
		class="attachment"
		role="presentation"
		@click="$emit('click')">
		<!-- `playsinline`, or iOS takes every video full screen the moment it
		     starts: the reader is thrown out of the timeline into a player,
		     and comes back to wherever the page has scrolled to meanwhile.
		     `controls` only where the media is the thing on screen. In a
		     timeline a post is a link to itself, so a player here would put a
		     play button in the way of it — and swallow the click that was
		     meant to open the post. The poster still shows, which is what the
		     reader is choosing from. -->
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
			<!-- the subtitle tracks the video published, which PeerTube hangs
			     off its `url` list and nothing here used to read -->
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
		<!-- a file: nothing to draw, so it is named. The whole card is the
		     link, since there is nothing else on it to press -->
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
			<img
				v-if="hasPreview && !previewFailed"
				class="attachment__preview attachment__preview--fading"
				:class="{ 'attachment__preview--shown': previewLoaded }"
				:src="attachment.preview_url"
				:alt="attachment.description || ''"
				@load="previewLoaded = true"
				@error="onPreviewError">
			<!-- federated media that has gone away used to spin forever: no
			     @error meant previewLoaded stayed false and the spinner stayed.
			     So did media the server says outright it has no preview for -->
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
import { translate, translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import ImageOff from 'vue-material-design-icons/ImageOff.vue'
import logger from '../services/logger.js'

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
		 * Whether this is the copy the reader came to look at.
		 *
		 * On by default, because every caller but a timeline is showing the
		 * media for its own sake: the viewer, the composer's previews, a
		 * single post. Off in a timeline, where the whole post is a link to
		 * itself and a player would intercept the click.
		 */
		interactive: {
			type: Boolean,
			default: true,
		},

		/**
		 * What the post said about the video beyond the file: its captions and
		 * whether it is live. Passed in rather than read off the attachment,
		 * because it is a fact about the *post* — one `Video` object, one set
		 * of subtitles — and the attachment is only its file.
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
		 * Whether this needs hls.js rather than a `src` a browser can open.
		 *
		 * A PeerTube transcoding to HLS — its default — publishes a `.m3u8`
		 * playlist and nothing else, which Safari plays natively and no other
		 * browser opens at all. Until this, those videos showed a poster and a
		 * player that did nothing.
		 *
		 * @return {boolean}
		 */
		isPlaylist() {
			const type = (this.attachment?.media_type || '').toLowerCase()

			return type === 'application/x-mpegurl' || type === 'application/vnd.apple.mpegurl'
		},

		/**
		 * The master playlist of a *local* video's ladder, where there is one.
		 *
		 * Different from `isPlaylist` in the one way that matters: there is a
		 * plain file beside it. A remote HLS video is a playlist and nothing
		 * else, so failing to load it is failing to play the video; a ladder is
		 * an addition to a file that plays perfectly well on its own, which is
		 * why this one falls back rather than giving up.
		 *
		 * @return {string}
		 */
		ladderSource() {
			return (!this.isPlaylist && this.attachment?.type === 'video')
				? (this.attachment?.hls_url || '')
				: ''
		},

		/**
		 * The `src` the element itself carries — empty for a playlist, which is
		 * handed to the element by hls.js instead.
		 *
		 * A laddered video keeps its plain `src`: it is what plays while the
		 * library is being fetched, what plays if the ladder cannot be loaded,
		 * and what plays in a browser with no HLS at all.
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
		 * Whether there are bytes to point at.
		 *
		 * An attachment this instance holds no copy of — refused by the cache
		 * limits, or not fetched yet — now arrives with `url: null` rather than
		 * a link to a copy that is not here. Without this the video element
		 * was given no `src` and sat blank, and the file card was a link with
		 * no `href`; the placeholder below says what happened instead.
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
			if (description !== '') {
				return description
			}
			const path = (this.attachment?.url || this.attachment?.remote_url || '').split('?')[0]
			return decodeURIComponent(path.split('/').pop() || '') || t('social', 'File')
		},

		/** @return {string} the extension, upper-cased, as a kind label; '' when there is none */
		fileKind() {
			const path = (this.attachment?.url || this.attachment?.remote_url || '').split('?')[0]
			const match = /\.([a-z0-9]{1,8})$/i.exec(path)
			return match ? match[1].toUpperCase() : ''
		},

		/**
		 * The still to show before anything is played. A federated video has
		 * one -- PeerTube publishes it and this instance mirrors it -- and it
		 * is the whole reason a video timeline can be scrolled without
		 * fetching a frame of anything.
		 *
		 * `preview_url` is the file itself for a video uploaded here, which is
		 * no use as a poster: a browser handed a video for one downloads it to
		 * find a frame, which is what the poster exists to avoid. So only a
		 * preview that differs from the source counts as one.
		 *
		 * @return {string|undefined} undefined leaves the attribute off
		 */
		poster() {
			const preview = this.attachment?.preview_url
			if (typeof preview !== 'string' || preview === '' || preview === this.attachment?.url) {
				return undefined
			}

			return preview
		},

		/**
		 * How much of the video to fetch before anybody has asked to watch it.
		 *
		 * With a poster, nothing: the frame is already on screen, and on a page
		 * of twenty federated videos `metadata` alone would open twenty
		 * connections to other servers through this one. Without a poster there
		 * is nothing to draw until the first frame arrives, so it is worth the
		 * headers.
		 *
		 * @return {string}
		 */
		preload() {
			return this.poster === undefined ? 'metadata' : 'none'
		},

		/**
		 * Whether there is a preview to wait for at all. The server sends
		 * `preview_url: null` when it has none, and Vue drops a null `src`:
		 * neither @load nor @error is then guaranteed to fire — on Firefox
		 * neither does — so the spinner stayed up for good.
		 *
		 * @return {boolean}
		 */
		hasPreview() {
			return this.attachment !== null
				&& typeof this.attachment.preview_url === 'string'
				&& this.attachment.preview_url !== ''
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
					1: translate('social', 'The image exceeds this instance’s media size limit.'),
					2: translate('social', 'This image type is not allowed by this instance.'),
					3: translate('social', 'This instance could not access the original image.'),
					4: translate('social', 'This instance could not read the image.'),
				}[cacheError] ?? translate('social', 'This instance could not cache the image.')

				return description ? `${reason} ${description}` : reason
			}
			if (this.previewFailed) {
				return description
					? translate('social', 'Attachment could not be loaded: {description}', { description })
					: translate('social', 'Attachment could not be loaded')
			}

			return description
				? translate('social', 'No preview available: {description}', { description })
				: translate('social', 'No preview available')
		},
	},

	watch: {
		attachment() {
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
		 * Points the element at an HLS playlist, through hls.js where the
		 * browser cannot open one itself.
		 *
		 * Safari plays a `.m3u8` natively and nothing else does, so everywhere
		 * else the library is fetched — **lazily**, the first time somebody
		 * actually opens such a video, because it is a few hundred kilobytes
		 * and almost every post is not a video at all.
		 *
		 * The playlist it is given is the proxied one: a playlist names its
		 * segments relative to itself, so pointing the player at the origin's
		 * copy would have every segment fetched from there — the very thing
		 * the media proxy exists to prevent.
		 *
		 * @return {Promise<void>}
		 */
		async attachPlaylist() {
			this.detachPlaylist()

			if (!this.interactive) {
				return
			}

			const element = /** @type {HTMLVideoElement} */ (this.$refs.video)
			// a remote video that is nothing but a playlist, or a local one
			// that has a ladder beside its file
			const source = this.isPlaylist ? this.attachment.url : this.ladderSource
			if (!element || !source) {
				return
			}

			// Safari opens a playlist itself, and for a remote one that is the
			// whole answer. A ladder is left to the plain file there instead:
			// pointing the element at the master would throw away the fallback
			// for an adaptive stream of the same video, which is not a trade
			// worth making when the file is already playing.
			if (element.canPlayType('application/vnd.apple.mpegurl')) {
				if (this.isPlaylist) {
					element.src = source
				}

				return
			}

			try {
				const { default: Hls } = await import(/* webpackChunkName: "hls" */'hls.js')
				if (!Hls.isSupported()) {
					return
				}

				// the component may have been torn down while the library was
				// being fetched; attaching then would leak a player nobody can
				// reach
				if (this.$refs.video !== element) {
					return
				}

				const hls = new Hls({ enableWorker: false })
				this.hls = hls

				// A ladder that cannot be loaded must not be the reason a video
				// stops playing: there is a whole file behind it, and going back
				// to it is better than a player that stops. A remote playlist
				// has nothing to fall back to, so it is left to report itself.
				if (!this.isPlaylist) {
					hls.on(Hls.Events.ERROR, (event, data) => {
						if (data?.fatal) {
							logger.debug('falling back to the plain file', { data })
							this.detachPlaylist()
							element.src = this.attachment.url
						}
					})
				}

				hls.loadSource(source)
				hls.attachMedia(element)
			} catch (error) {
				logger.error('could not start an HLS video', { error })
			}
		},

		/** Lets go of the player, which otherwise goes on fetching segments. */
		detachPlaylist() {
			if (this.hls) {
				this.hls.destroy()
				this.hls = null
			}
		},

		/**
		 * A press on the player itself.
		 *
		 * Where the media is interactive the player handles it and nothing
		 * above needs to know — a press on `play` is not a press on the post.
		 * Where it is not, the press belongs to whatever wraps this, which in
		 * a timeline is the link to the post.
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

		drawBlurhash() {
			if (this.isAv || this.attachment?.meta?.small?.width === undefined) {
				return
			}

			// CacheDocumentService sets the copy sizes before it knows GD could
			// read the image, so an unreadable upload arrives with dimensions
			// and an empty blurhash — and decode('') throws
			const blurhash = this.attachment.blurhash
			if (typeof blurhash !== 'string' || blurhash.length < 6) {
				return
			}

			if (!this.$refs.canvas) {
				return
			}

			try {
				const ctx = /** @type {HTMLCanvasElement} */ (this.$refs.canvas).getContext('2d')
				const imageData = ctx.createImageData(this.attachment.meta.small.width, this.attachment.meta.small.height)
				const pixels = decode(blurhash, this.attachment.meta.small.width, this.attachment.meta.small.height)
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
/* the card a file is shown as: its name, what kind it is, and the whole of
   it a link to the download */
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
		/* Both edges, not just the top. An absolutely positioned box with no
		   inline offset keeps its *static* position, and the static position
		   here comes from a `<button>` — which centres its content by UA rule.
		   The picture was drawn half its own width to the right of its frame,
		   the empty half showing the frame's grey and the other half clipped
		   off the card. */
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
