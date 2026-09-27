<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="media-grid">
		<ul v-if="tiles.length" class="media-grid__list">
			<li v-for="tile in tiles" :key="tile.key" class="media-grid__cell">
				<router-link
					class="media-grid__link"
					:to="tile.route"
					:aria-label="tile.label">
					<!-- A tile the reader's own keyword filters matched carries
					     no picture at all: the grid is the other way this app
					     draws a timeline, and a filter that covers a post in the
					     list has to cover it here too. Not merely veiled, as a
					     sensitive picture is — the file is never fetched, and
					     the alt text, which a filter matches on as well, is not
					     in the page either. -->
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

		<NcLoadingIcon v-if="loading" class="media-grid__loading" :size="32" />
	</div>
</template>

<script>
import EyeOffOutline from 'vue-material-design-icons/EyeOffOutline.vue'
import ImageMultipleOutline from 'vue-material-design-icons/ImageMultipleOutline.vue'
import ImageOffOutline from 'vue-material-design-icons/ImageOffOutline.vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import { t } from '@nextcloud/l10n'
import { positionOfFocus } from '../utils/focalPoint.js'
import { filterCoverLabel, matchedFilters } from '../utils/filters.js'

/**
 * A profile as a grid of squares, which is what a profile looks like on
 * Pixelfed and is the single most visible difference between the two apps.
 *
 * One tile per post, not per picture: an album is one thing somebody posted and
 * tapping it opens that post, so a ten-picture album taking ten tiles would
 * make a profile look like ten posts. The badge says there is more behind it.
 *
 * The crop is where the focal point says, not the middle. That is the whole
 * reason the focal point exists -- a square crop of a portrait photo cuts the
 * subject out of it about half the time, and `object-position` is what puts it
 * back. A post that never set one is centred, which is what every client
 * assumes when the field is absent.
 */
export default {
	name: 'ProfileMediaGrid',

	components: {
		EyeOffOutline,
		ImageMultipleOutline,
		ImageOffOutline,
		NcLoadingIcon,
		PlayCircleOutline,
	},

	props: {
		/** The posts to draw; those without pictures are skipped. */
		posts: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status[]>} */ (Array),
			default: () => [],
		},

		/**
		 * Whose grid this is, where it is one account's. Empty on Discover,
		 * which is everybody's: a tile there links at the account that wrote
		 * the post it draws.
		 */
		account: {
			type: String,
			default: '',
		},

		loading: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			/** Tiles whose picture 404'd, so the next render draws the fallback. */
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
				// For older videos, preview_url is the video itself. An <img> cannot
				// decode it; use a muted, lazy video frame instead. Images may still
				// fall back to their full-size source when they have no thumbnail.
				preview: broken ? null : (preview || (!isVideo ? first.url || null : null)),
				videoSource: !broken && isVideo && !preview ? first.url || null : null,
				alt: first.description || '',
				label: this.labelFor(post, first),
				style: { objectPosition: this.focalPosition(first) },
				route: {
					name: 'single-post',
					// the post's own author first: `account` is a required
					// route param, and an empty one made `router-link` throw
					// while resolving — which took every tile on Discover with
					// it, so the Pictures tab drew nothing at all
					params: { account: post.account?.acct || this.account, id: post.id },
				},
			}
		},

		/**
		 * Where to crop the tile. The conversion lives with the editor that
		 * writes the point, so the two cannot disagree about which way is up.
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
			// the name of the filter rather than anything of the post: the alt
			// text is matched by a filter as surely as the words of the post are
			if (matchedFilters(post).length > 0) {
				return filterCoverLabel(post)
			}

			if (attachment.description) {
				return attachment.description
			}

			if (post.media_attachments.length > 1) {
				return t('social', 'Post with {count} pictures', { count: post.media_attachments.length })
			}

			return (attachment.type === 'video' || attachment.type === 'gifv')
				? t('social', 'Post with a video')
				: t('social', 'Post with a picture')
		},

		onImageError(key) {
			if (!this.broken.includes(key)) {
				this.broken.push(key)
			}
		},

		/**
		 * Only fetch a video when its tile is near the viewport. Most videos
		 * have a server-generated JPEG poster and never reach this path; for old
		 * uploads without one, this lets the browser draw an actual frame without
		 * opening a connection for every video in the grid at once.
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
		// three across is Pixelfed's; auto-fill keeps the squares a sensible
		// size on a narrow screen instead of shrinking them to nothing
		grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
		gap: 2px;
		list-style: none;
		padding: 0;
		margin: 0;
	}

	&__cell {
		position: relative;
		// the square the whole thing is for
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
		// the pictures underneath are arbitrary, so the icon carries its own
		// contrast rather than relying on them
		filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.6));
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
