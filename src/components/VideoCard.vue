<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="video-card" :class="{ 'video-card--short': upright }">
		<!-- one link for the whole card: the picture, the title and the
		     channel all open the video, so the face is drawn without a link
		     of its own rather than nesting one inside this one -->
		<router-link
			class="video-card__link"
			:to="route"
			:data-status-id="entry.id"
			:aria-label="label"
			@mouseenter="schedulePreview"
			@mouseleave="stopPreview">
			<span class="video-card__thumb">
				<img
					v-if="thumbnail"
					class="video-card__image"
					:class="{ 'video-card__image--veiled': sensitive }"
					:src="thumbnail"
					alt=""
					loading="lazy"
					decoding="async"
					@error="broken = true">
				<span v-else class="video-card__nothumb" aria-hidden="true">
					<IconVideoOutline :size="32" />
				</span>
				<video
					v-if="previewing"
					class="video-card__preview"
					:src="previewSource"
					muted
					autoplay
					loop
					playsinline
					aria-hidden="true"
					@error="stopPreview" />
				<span v-if="card.live" class="video-card__badge video-card__badge--live">{{ t('social', 'Live') }}</span>
				<span v-else-if="card.duration" class="video-card__badge">{{ clock }}</span>
				<span v-if="progress !== null" class="video-card__progress" aria-hidden="true">
					<span class="video-card__progress-done" :style="{ inlineSize: `${Math.round(progress.fraction * 100)}%` }" />
				</span>
			</span>
			<span class="video-card__details">
				<ActorAvatar
					v-if="!upright"
					class="video-card__avatar"
					:actor="post.account"
					:size="36"
					:link="false"
					:hoverCard="false" />
				<span class="video-card__text">
					<span class="video-card__title">{{ title }}</span>
					<span v-if="!upright" class="video-card__channel">{{ channel }}</span>
					<span class="video-card__facts">{{ facts }}</span>
				</span>
			</span>
		</router-link>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import IconVideoOutline from 'vue-material-design-icons/VideoOutline.vue'
import ActorAvatar from './ActorAvatar.vue'
import { progressOf } from '../services/videoProgress.js'
import { duration } from '../utils/duration.js'
import { filterCoverLabel, matchedFilters } from '../utils/filters.js'
import { fromNow } from '../utils/relativeTime.js'
import { formatTime } from '../utils/shortVideo.js'
import { compactCount, mayPreview, videoAttachmentOf, videoCardOf, videoPostOf } from '../utils/videoCard.js'

/** How long the pointer rests on a card before it starts to move, in milliseconds. */
export const PREVIEW_AFTER = 600

/**
 * One video in the Videos page's grid: a 16:9 picture with its running time,
 * the channel's face, the title, the channel, views and age. `upright` is the
 * Shorts shelf's tall card. Everything comes from the status's `video_card`
 * (`Stream::exportVideoCard()`), so videos from any network are drawn alike,
 * and a count nobody kept is left out rather than shown as zero.
 */
export default {
	name: 'VideoCard',

	components: {
		ActorAvatar,
		IconVideoOutline,
	},

	props: {
		/** The timeline entry: the video post, or a boost of one. */
		entry: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */ (Object),
			required: true,
		},

		/** Where the card leads; the video's own page unless said otherwise. */
		to: {
			type: [Object, String],
			default: null,
		},

		/** The Shorts shelf's tall card rather than the grid's wide one. */
		upright: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			/** the picture would not load, so the placeholder stands in */
			broken: false,
			/** the muted preview is playing */
			previewing: false,
			/** @type {ReturnType<typeof setTimeout>|null} */
			previewTimer: null,
		}
	},

	computed: {
		/** @return {import('../types/Mastodon.js').Status} the post the video is on */
		post() {
			return videoPostOf(this.entry)
		},

		/** @return {import('../types/Mastodon.js').VideoCard} */
		card() {
			return videoCardOf(this.entry) ?? { title: '', duration: null, thumbnail: null, views: null, live: false, category: null, published: this.post.created_at }
		},

		/**
		 * Whether the reader's own keyword filters matched it: then neither
		 * its picture nor its words are on the card, as in the list.
		 *
		 * @return {boolean}
		 */
		filtered() {
			return matchedFilters(this.post).length > 0
		},

		/** @return {boolean} marked sensitive, so the picture is veiled */
		sensitive() {
			return Boolean(this.post.sensitive)
		},

		/** @return {string|null} */
		thumbnail() {
			return this.filtered || this.broken ? null : this.card.thumbnail
		},

		/** @return {string} */
		channel() {
			return this.post.account?.display_name || this.post.account?.username || ''
		},

		/** @return {string} */
		title() {
			if (this.filtered) {
				return filterCoverLabel(this.post)
			}

			return this.card.title || t('social', 'Video by {name}', { name: this.channel })
		},

		/** @return {string} the running time as a clock */
		clock() {
			return formatTime(this.card.duration ?? 0)
		},

		/**
		 * "1.2K views · 3 days ago", or only the age when nobody counted.
		 *
		 * @return {string}
		 */
		facts() {
			const parts = []
			if (this.card.views !== null && this.card.views !== undefined) {
				parts.push(n('social', '{count} view', '{count} views', this.card.views, { count: compactCount(this.card.views) }))
			}
			const age = fromNow(this.card.published)
			if (age !== '') {
				parts.push(age)
			}

			return parts.join(' · ')
		},

		/** @return {string} what a screen reader is told the card is */
		label() {
			const parts = [this.title]
			if (this.channel !== '' && !this.upright) {
				parts.push(this.channel)
			}
			if (this.card.live) {
				parts.push(t('social', 'Live'))
			} else if (this.card.duration) {
				parts.push(duration(this.card.duration))
			}
			if (this.facts !== '') {
				parts.push(this.facts)
			}

			return parts.join(', ')
		},

		/** @return {import('../services/videoProgress.js').VideoProgress|null} */
		progress() {
			return this.card.live ? null : progressOf(this.post)
		},

		/** @return {string|null} what the preview plays */
		previewSource() {
			return videoAttachmentOf(this.post)?.url ?? null
		},

		/** @return {object|string} */
		route() {
			return this.to ?? {
				name: 'single-post',
				params: { account: this.post.account?.acct ?? '', id: this.post.id },
			}
		},
	},

	beforeUnmount() {
		this.stopPreview()
	},

	methods: {
		t,

		/**
		 * Starts the preview once the pointer has rested on the card. Not for a
		 * live broadcast, whose stream is a playlist a plain video element
		 * cannot always play, and never for something veiled.
		 */
		schedulePreview() {
			if (this.previewSource === null || this.card.live || this.filtered || this.sensitive || !mayPreview()) {
				return
			}

			this.clearTimer()
			this.previewTimer = setTimeout(() => {
				this.previewTimer = null
				this.previewing = true
			}, PREVIEW_AFTER)
		},

		/** Stops it; taking the element away stops the download with it. */
		stopPreview() {
			this.clearTimer()
			this.previewing = false
		},

		clearTimer() {
			if (this.previewTimer !== null) {
				clearTimeout(this.previewTimer)
				this.previewTimer = null
			}
		},
	},
}
</script>

<style scoped lang="scss">
.video-card {
	min-inline-size: 0;

	&__link {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 3);
		color: var(--color-main-text);
		text-decoration: none;
		border-radius: var(--border-radius-large);

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 4px;
		}
	}

	&__thumb {
		position: relative;
		display: block;
		aspect-ratio: 16 / 9;
		overflow: hidden;
		border-radius: var(--border-radius-large);
		background-color: var(--color-background-dark);
	}

	&__image,
	&__preview {
		position: absolute;
		inset: 0;
		inline-size: 100%;
		block-size: 100%;
		object-fit: cover;
	}

	&__image--veiled {
		filter: blur(16px);
		transform: scale(1.1);
	}

	&__nothumb {
		display: flex;
		align-items: center;
		justify-content: center;
		block-size: 100%;
		color: var(--color-text-maxcontrast);
	}

	// over an arbitrary picture, so the badge brings its own contrast in both themes
	&__badge {
		position: absolute;
		inset-block-end: 8px;
		inset-inline-end: 8px;
		padding: 1px 5px;
		border-radius: 4px;
		background: rgba(0, 0, 0, .8);
		color: #fff;
		font-size: 12px;
		font-weight: 600;
		line-height: 18px;
		font-variant-numeric: tabular-nums;
	}

	&__badge--live {
		background: #c00;
		text-transform: uppercase;
	}

	&__progress {
		position: absolute;
		inset-inline: 0;
		inset-block-end: 0;
		block-size: 4px;
		background: rgba(255, 255, 255, .45);
	}

	&__progress-done {
		display: block;
		block-size: 100%;
		background: var(--color-primary-element);
	}

	// a thumbnail is an arbitrary picture: the theme's own colours are not
	// guaranteed to stand out on it, so high contrast uses the system ones
	@media (forced-colors: active) {
		&__progress {
			background: ButtonFace;
			border-block: 1px solid ButtonText;
		}

		&__progress-done {
			background: Highlight;
		}
	}

	&__details {
		display: flex;
		align-items: flex-start;
		gap: calc(var(--default-grid-baseline) * 3);
	}

	&__avatar {
		flex: 0 0 auto;
	}

	&__text {
		display: flex;
		flex-direction: column;
		min-inline-size: 0;
		gap: 2px;
	}

	&__title {
		display: -webkit-box;
		overflow: hidden;
		-webkit-box-orient: vertical;
		-webkit-line-clamp: 2;
		line-clamp: 2;
		font-weight: 600;
		line-height: 1.4;
		overflow-wrap: anywhere;
	}

	&__channel,
	&__facts {
		overflow: hidden;
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
		text-overflow: ellipsis;
		white-space: nowrap;
	}
}

.video-card--short {
	.video-card__thumb {
		aspect-ratio: 9 / 16;
	}

	.video-card__link {
		gap: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
