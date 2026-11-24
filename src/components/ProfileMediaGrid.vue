<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="media-grid">
		<ul v-if="tiles.length" class="media-grid__list" :style="listStyle">
			<li v-for="tile in tiles" :key="tile.key" class="media-grid__cell">
				<router-link
					class="media-grid__link"
					:to="tile.route"
					:data-status-id="tile.key"
					:aria-label="tile.label">
					<!-- A tile matched by the reader's keyword filters shows no
					     photo: the file is never fetched and its alt text is
					     not in the page. -->
					<img
						v-if="tile.preview && !tile.filtered"
						class="media-grid__image"
						:src="tile.preview"
						:alt="tile.alt"
						:style="tile.style"
						loading="lazy"
						decoding="async"
						@error="onImageError(tile.key)">
					<video
						v-else-if="tile.videoSource && !tile.filtered"
						ref="videoPreviews"
						class="media-grid__image"
						:data-source="tile.videoSource"
						muted
						playsinline
						preload="none"
						aria-hidden="true"
						@loadedmetadata="showVideoFrame"
						@error="onImageError(tile.key)" />
					<span v-else-if="!tile.filtered" class="media-grid__missing" aria-hidden="true">
						<ImageOffOutline :size="24" />
					</span>

					<!-- an album is one tile; say so, as Pixelfed does -->
					<span v-if="tile.count > 1" class="media-grid__badge" aria-hidden="true">
						<ImageMultipleOutline :size="16" />
					</span>
					<span v-else-if="tile.isVideo" class="media-grid__badge" aria-hidden="true">
						<PlayCircleOutline :size="16" />
					</span>
					<span v-if="tile.filtered || tile.sensitive" class="media-grid__veil" aria-hidden="true">
						<EyeOffOutline :size="20" />
					</span>
				</router-link>
			</li>
		</ul>

		<!-- the first page lands in squares already drawn where its tiles
		     go; a later page arrives under tiles already there -->
		<TimelineSkeleton v-if="loading && tiles.length === 0" variant="grid" />
		<NcLoadingIcon v-else-if="loading" class="media-grid__loading" :size="32" />
	</div>
</template>

<script>
import EyeOffOutline from 'vue-material-design-icons/EyeOffOutline.vue'
import ImageMultipleOutline from 'vue-material-design-icons/ImageMultipleOutline.vue'
import ImageOffOutline from 'vue-material-design-icons/ImageOffOutline.vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import TimelineSkeleton from './TimelineSkeleton.vue'
import { t } from '@nextcloud/l10n'
import { positionOfFocus } from '../utils/focalPoint.js'
import { filterCoverLabel, matchedFilters } from '../utils/filters.js'

/**
 * Posts as a grid of squares, as Pixelfed shows a profile. One tile per post,
 * with a badge for an album, cropped around the photo's focal point (centred
 * when it has none).
 */
export default {
	name: 'ProfileMediaGrid',

	components: {
		EyeOffOutline,
		ImageMultipleOutline,
		ImageOffOutline,
		NcLoadingIcon,
		PlayCircleOutline,
		TimelineSkeleton,
	},

	props: {
		/** The posts to draw; those without pictures are skipped. */
		posts: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status[]>} */ (Array),
			default: () => [],
		},

		/**
		 * Whose grid this is, where it is one account's. Empty on Discover,
		 * where each tile links to its post's author.
		 */
		account: {
			type: String,
			default: '',
		},

		loading: {
			type: Boolean,
			default: false,
		},

		/** How many squares across; 0 fits as many as the width takes. */
		columns: {
			type: Number,
			default: 0,
		},
	},

	data() {
		return {
			/** Tiles whose photo 404'd, so the next render draws the fallback. */
			broken: [],
			/** Observer that only fetches a video when its tile nears the viewport. */
			/** @type {IntersectionObserver | null} */
			videoObserver: null,
		}
	},

	computed: {
		tiles() {
			return this.posts
				.filter((post) => post && Array.isArray(post.media_attachments) && post.media_attachments.length > 0)
				.map((post) => this.toTile(post))
		},

		/** @return {object|undefined} a fixed number of columns, when one is asked for */
		listStyle() {
			return this.columns > 0 ? { gridTemplateColumns: `repeat(${this.columns}, 1fr)` } : undefined
		},
	},

	mounted() {
		this.observeVideoPreviews()
	},

	updated() {
		this.observeVideoPreviews()
	},

	beforeUnmount() {
		this.videoObserver?.disconnect()
	},

	methods: {
		t,

		/**
		 * @param {object} post one status
		 * @return {object} what the template needs, worked out once
		 */
		toTile(post) {
			const first = post.media_attachments[0]
			const key = String(post.id)
			const broken = this.broken.includes(key)
			const isVideo = first.type === 'video' || first.type === 'gifv'
			const preview = typeof first.preview_url === 'string'
				&& first.preview_url !== ''
				&& first.preview_url !== first.url
				? first.preview_url
				: null

			return {
				key,
				count: post.media_attachments.length,
				isVideo,
				sensitive: Boolean(post.sensitive),
				filtered: matchedFilters(post).length > 0,
				// For some older videos preview_url is the video itself, which an
				// <img> cannot decode: draw a muted, lazy video frame instead.
				preview: broken ? null : (preview || (!isVideo ? first.url || null : null)),
				videoSource: !broken && isVideo && !preview ? first.url || null : null,
				alt: first.description || '',
				label: this.labelFor(post, first),
				style: { objectPosition: this.focalPosition(first) },
				route: {
					name: 'single-post',
					// `account` is a required route param: an empty one makes
					// `router-link` throw, so use the post's author first
					params: { account: post.account?.acct || this.account, id: post.id },
				},
			}
		},

		/**
		 * Where to crop the tile, using the focal point editor's own conversion.
		 *
		 * @param {object} attachment the first attachment of the post
		 * @return {string} an `object-position` value
		 */
		focalPosition(attachment) {
			return positionOfFocus(attachment?.meta?.focus).objectPosition
		},

		/**
		 * @param {object} post the status
		 * @param {object} attachment its first attachment
		 * @return {string} what a screen reader announces for the tile
		 */
		labelFor(post, attachment) {
			// the filter's name only: the alt text may be what the filter matched
			if (matchedFilters(post).length > 0) {
				return filterCoverLabel(post)
			}

			if (attachment.description) {
				return attachment.description
			}

			if (post.media_attachments.length > 1) {
				return t('social', 'Post with {count} photos', { count: post.media_attachments.length })
			}

			return (attachment.type === 'video' || attachment.type === 'gifv')
				? t('social', 'Post with a video')
				: t('social', 'Post with a photo')
		},

		onImageError(key) {
			if (!this.broken.includes(key)) {
				this.broken.push(key)
			}
		},

		/**
		 * Fetches a poster-less video only when its tile nears the viewport,
		 * so the grid does not open a connection per video at once.
		 */
		observeVideoPreviews() {
			const videoRefs = this.$refs.videoPreviews
			const videos = /** @type {HTMLVideoElement[]} */ (
				Array.isArray(videoRefs) ? videoRefs : videoRefs ? [videoRefs] : []
			)
			if (videos.length === 0) {
				return
			}

			if (typeof IntersectionObserver === 'undefined') {
				videos.forEach((video) => {
					const source = video.dataset.source
					if (!video.src && source) {
						video.preload = 'metadata'
						video.src = source
					}
				})
				return
			}

			if (!this.videoObserver) {
				this.videoObserver = new IntersectionObserver((entries) => {
					entries.forEach(({ isIntersecting, target }) => {
						const video = /** @type {HTMLVideoElement} */ (target)
						const source = video.dataset.source
						if (isIntersecting && !video.src && source) {
							video.preload = 'metadata'
							video.src = source
							this.videoObserver?.unobserve(video)
						}
					})
				}, { rootMargin: '120px' })
			}

			videos.forEach((video) => {
				if (!video.src) {
					this.videoObserver?.observe(video)
				}
			})
		},

		/**
		 * Seek past black intro frames when metadata lets us.
		 *
		 * @param {Event} event the media metadata event
		 */
		showVideoFrame(event) {
			const video = /** @type {HTMLVideoElement | null} */ (event.target)
			if (!video) {
				return
			}
			if (Number.isFinite(video.duration) && video.duration > 0) {
				video.currentTime = Math.min(1, video.duration / 2)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.media-grid {
	&__list {
		display: grid;
		// auto-fill keeps the squares a sensible size on a narrow screen
		grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
		gap: 2px;
		list-style: none;
		padding: 0;
		margin: 0;
	}

	&__cell {
		position: relative;
		aspect-ratio: 1 / 1;
		overflow: hidden;
		background-color: var(--color-background-dark);
	}

	&__link {
		display: block;
		width: 100%;
		height: 100%;

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: -2px;
		}
	}

	&__image {
		width: 100%;
		height: 100%;
		object-fit: cover;
		display: block;
		transition: transform 0.15s ease-out;

		.media-grid__link:hover &,
		.media-grid__link:focus-visible & {
			transform: scale(1.03);
		}
	}

	&__missing {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 100%;
		height: 100%;
		color: var(--color-text-maxcontrast);
	}

	&__badge {
		position: absolute;
		top: 6px;
		inset-inline-end: 6px;
		color: #fff;
		// the icon carries its own contrast over arbitrary photos
		filter: none;
		pointer-events: none;
	}

	&__veil {
		position: absolute;
		inset: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		color: #fff;
		background-color: rgba(0, 0, 0, 0.5);
		backdrop-filter: blur(12px);
		pointer-events: none;
	}

	&__loading {
		margin: 16px auto;
	}
}

@media (prefers-reduced-motion: reduce) {
	.media-grid__image {
		transition: none;

		.media-grid__link:hover &,
		.media-grid__link:focus-visible & {
			transform: none;
		}
	}
}
</style>
