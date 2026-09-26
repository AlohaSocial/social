<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="reels" role="region" :aria-label="t('social', 'Videos, one at a time')">
		<div
			ref="track"
			class="reels__track"
			tabindex="0"
			@keydown="onKey"
			@scroll.passive="onScroll">
			<article
				v-for="(entry, index) in reels"
				:key="entry.key"
				:ref="(el) => setSlide(el, index)"
				class="reel"
				:data-index="index">
				<video
					:ref="(el) => setVideo(el, index)"
					class="reel__video"
					:src="entry.video.url"
					:poster="entry.video.preview_url || undefined"
					:aria-label="entry.video.description || entry.text"
					:muted="muted"
					playsinline
					loop
					preload="none"
					@click="onVideoTap(index, $event)" />

				<!-- the hearts a like releases: one where a double tap landed,
				     and a few rising up the edge, the way live video does it.
				     Decoration only; the button below is what a screen reader
				     is told about. -->
				<div class="reel__hearts" aria-hidden="true">
					<svg
						v-for="heart in heartsOn(index)"
						:key="heart.id"
						class="reel__heart"
						:class="heart.big ? 'reel__heart--burst' : 'reel__heart--float'"
						:style="heart.style"
						viewBox="0 0 24 24">
						<path fill="currentColor" :d="HEART_PATH" />
					</svg>
				</div>

				<button
					type="button"
					class="reel__like"
					:class="{ 'reel__like--on': entry.status.favourited === true }"
					:aria-pressed="entry.status.favourited === true"
					:aria-label="entry.status.favourited === true ? t('social', 'Unlike') : t('social', 'Like')"
					@click.stop="toggleLike(index)">
					<IconHeart v-if="entry.status.favourited === true" :size="24" />
					<IconHeartOutline v-else :size="24" />
					<span v-if="entry.status.favourites_count > 0" class="reel__like-count">
						{{ entry.status.favourites_count }}
					</span>
				</button>

				<!-- the one control that is not a gesture: a pointer has no swipe -->
				<button
					type="button"
					class="reel__sound"
					:aria-label="muted ? t('social', 'Unmute') : t('social', 'Mute')"
					@click.stop="toggleSound">
					<IconVolumeOff v-if="muted" :size="20" />
					<IconVolumeHigh v-else :size="20" />
				</button>

				<div class="reel__caption">
					<router-link
						class="reel__author"
						:to="{ name: 'profile', params: { account: entry.status.account.acct } }">
						<img
							v-if="entry.status.account.avatar"
							class="reel__avatar"
							:src="entry.status.account.avatar"
							alt="">
						<span class="reel__names">
							<span class="reel__name">{{ entry.status.account.display_name || entry.status.account.username }}</span>
							<span class="reel__handle">@{{ entry.status.account.acct }}</span>
						</span>
					</router-link>
					<p v-if="entry.text" class="reel__text">
						{{ entry.text }}
					</p>
					<router-link
						class="reel__open"
						:to="{ name: 'single-post', params: { account: entry.status.account.acct, id: entry.status.id } }">
						{{ t('social', 'Open the post') }}
					</router-link>
				</div>
			</article>

			<div v-if="reels.length === 0 && loading" class="reels__empty">
				<NcLoadingIcon :size="44" appearance="light" />
				<p>{{ t('social', 'Loading videos …') }}</p>
			</div>

			<!-- a feed that could not be fetched is not a feed with nothing in it -->
			<div v-else-if="reels.length === 0 && failed" class="reels__empty" role="alert">
				<p>{{ t('social', 'The videos could not be loaded.') }}</p>
				<NcButton @click="load">
					<template #icon>
						<IconRefresh :size="20" />
					</template>
					{{ t('social', 'Try again') }}
				</NcButton>
			</div>

			<div v-else-if="reels.length === 0" class="reels__empty">
				<p>{{ t('social', 'No videos here yet.') }}</p>
				<NcButton :to="{ name: 'timeline', params: { type: 'videos' } }">
					{{ t('social', 'Back to Videos') }}
				</NcButton>
			</div>
		</div>

		<NcButton
			class="reels__close"
			:aria-label="t('social', 'Back to Videos')"
			:to="{ name: 'timeline', params: { type: 'videos' } }">
			<template #icon>
				<IconClose :size="20" />
			</template>
		</NcButton>
	</div>
</template>

<script>
/**
 * Videos one at a time, full height, the way the app people are leaving shows
 * them.
 *
 * The Videos page is a grid, which is right for choosing and wrong for
 * watching: every video on it is a still until it is clicked, and the thing
 * somebody arriving from TikTok is used to is a stack that plays by itself as
 * it goes past. This is that stack, over the same timeline — no new endpoint,
 * no second idea of what a video is.
 *
 * Three rules hold it together:
 *
 *  - **One plays at a time.** An IntersectionObserver decides which, and every
 *    other element is paused rather than left buffering; a dozen videos
 *    playing behind the one on screen is a phone getting hot for nothing.
 *  - **Muted until asked.** A page that makes noise on open is a page people
 *    close, and no browser will autoplay with sound anyway. The choice is
 *    remembered for the session and applies to every video, because it is a
 *    statement about this page rather than about one video.
 *  - **The scroll does the work.** CSS scroll-snap rather than a transform per
 *    slide: it is the one thing that behaves the same under a finger, a
 *    trackpad, a wheel and a keyboard, and it keeps working when the
 *    JavaScript that observes it does not.
 */
import { mapStores } from 'pinia'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconHeart from 'vue-material-design-icons/Heart.vue'
import IconHeartOutline from 'vue-material-design-icons/HeartOutline.vue'
import IconRefresh from 'vue-material-design-icons/Refresh.vue'
import IconVolumeHigh from 'vue-material-design-icons/VolumeHigh.vue'
import IconVolumeOff from 'vue-material-design-icons/VolumeOff.vue'
import { useTimelineStore } from '../store/timeline.js'
import { oldestId } from '../utils/snowflake.js'
import { htmlToPlainText } from '../utils/plainText.js'
import logger from '../services/logger.js'
import { feel } from '../services/senses.js'

/** How close to the end the reader gets before the next page is asked for. */
const LOOK_AHEAD = 3

/**
 * How long a second tap may take to count as a double tap. A single tap waits
 * this long before it pauses, which is the price every app with double-tap to
 * like pays, and at this length nobody notices it.
 */
export const DOUBLE_TAP_MS = 280

/** How long a heart is on screen, in milliseconds; the longer of the two animations. */
export const HEART_MS = 1600

/** mdi's heart, drawn inline so a dozen of them cost no component each */
const HEART_PATH = 'M12,21.35L10.55,20.03C5.4,15.36 2,12.28 2,8.5C2,5.42 4.42,3 7.5,3C9.24,3 10.91,3.81 12,5.09C13.09,3.81 14.76,3 16.5,3C19.58,3 22,5.42 22,8.5C22,12.28 18.6,15.36 13.45,20.04L12,21.35Z'

let heartSerial = 0

export default {
	name: 'VideoReels',
	components: {
		IconClose,
		IconHeart,
		IconHeartOutline,
		IconRefresh,
		IconVolumeHigh,
		IconVolumeOff,
		NcButton,
		NcLoadingIcon,
	},

	props: {
		/**
		 * Which circle of people: 'home' (the ones you follow), 'timeline'
		 * (this instance) or 'federated' (everywhere) — the same three the
		 * Videos page is read at, carried here so that leaving the grid for
		 * the stack does not silently change what is in it.
		 */
		scope: {
			type: String,
			default: 'home',
		},
	},

	data() {
		return {
			muted: true,
			playing: 0,
			loading: false,
			/** the last page asked for could not be fetched */
			failed: false,
			allLoaded: false,
			/** the <video> of each slide, by index */
			videos: [],
			slides: [],
			/** tells onVisible which slide is on screen */
			observer: null,
			/** the hearts on screen, each with the slide it belongs to */
			hearts: [],
			/** the first tap of what may become a double tap */
			lastTap: null,
			/** the pause a single tap is waiting to do */
			tapTimer: null,
			HEART_PATH,
		}
	},

	computed: {
		...mapStores(useTimelineStore),

		/**
		 * One entry per video, not per post: a post with three videos on it is
		 * three things to watch, and a stack that showed only the first would
		 * be hiding two of them behind a grid tile nobody goes back to.
		 *
		 * @return {object[]} the video, the post it is on, and its words
		 */
		reels() {
			const entries = []

			for (const status of this.timelineStore.getTimeline) {
				for (const media of status.media_attachments ?? []) {
					if (media.type === 'video' || media.type === 'gifv') {
						entries.push({
							// one slide per video, so the post's id alone is
							// shared by every slide of a post with several
							key: status.id + ':' + media.id,
							status,
							video: media,
							text: htmlToPlainText(status.content ?? '').trim(),
						})
					}
				}
			}

			return entries
		},
	},

	watch: {
		// the query is the prop, and the router reuses this view when only
		// the query changes, so a new scope has to switch the feed here
		scope() {
			this.open()
		},
	},

	mounted() {
		this.open()

		// `threshold: 0.6` rather than a bare intersection: two slides touch
		// the viewport for most of a scroll, and whichever was observed last
		// would win. A slide is the one being watched when most of it is there.
		this.observer = new IntersectionObserver(this.onVisible, { threshold: 0.6 })
		this.$refs.track?.focus?.()
	},

	beforeUnmount() {
		window.clearTimeout(this.tapTimer)
		this.observer?.disconnect()
		for (const video of this.videos) {
			video?.pause?.()
		}
	},

	updated() {
		// slides arrive a page at a time, so each new one is taken under
		// observation as it appears rather than all of them once at mount
		for (const slide of this.slides) {
			if (slide && !slide.dataset.observed) {
				slide.dataset.observed = '1'
				this.observer?.observe(slide)
			}
		}
	},

	methods: {
		t,

		/** Points the store at the videos of this scope and fetches the first page. */
		open() {
			this.timelineStore.changeTimelineType({
				type: 'videos',
				params: { scope: this.scope },
			})
			this.allLoaded = false
			this.playing = 0
			this.load()
		},

		setSlide(el, index) {
			this.slides[index] = el
		},

		setVideo(el, index) {
			this.videos[index] = el
		},

		/**
		 * Whichever slide is mostly on screen becomes the one playing, and
		 * every other one stops. Pausing rather than leaving them be: a video
		 * scrolled past keeps downloading otherwise, and a page of them is a
		 * phone getting hot to buffer what nobody is watching.
		 *
		 * @param {IntersectionObserverEntry[]} entries what moved
		 */
		onVisible(entries) {
			for (const entry of entries) {
				if (!entry.isIntersecting) {
					continue
				}

				const index = Number(entry.target.dataset.index)
				if (Number.isNaN(index)) {
					continue
				}

				this.playing = index
				this.play(index)
			}
		},

		play(index) {
			this.videos.forEach((video, at) => {
				if (!video) {
					return
				}

				if (at === index) {
					video.muted = this.muted
					// a rejected play is the browser's autoplay rule, which is
					// an answer rather than a fault: the poster stays up and
					// the reader can press it
					video.play?.().catch((error) => logger.debug('autoplay refused', { error }))
				} else {
					video.pause?.()
					// back to the first frame, so coming back to it is the
					// video again rather than its last second
					if (video.currentTime > 0) {
						video.currentTime = 0
					}
				}
			})

			if (index >= this.reels.length - LOOK_AHEAD) {
				this.load()
			}
		},

		/**
		 * One tap pauses; two tap-taps like. The first tap has to wait to see
		 * whether a second follows, or a double tap would also pause and play.
		 *
		 * @param {number} index the slide
		 * @param {MouseEvent} event the tap, for where the heart goes
		 */
		onVideoTap(index, event) {
			const now = Date.now()
			if (this.lastTap !== null && this.lastTap.index === index && now - this.lastTap.at < DOUBLE_TAP_MS) {
				window.clearTimeout(this.tapTimer)
				this.tapTimer = null
				this.lastTap = null
				this.doubleTap(index, event)

				return
			}

			window.clearTimeout(this.tapTimer)
			this.lastTap = { index, at: now }
			this.tapTimer = window.setTimeout(() => {
				this.tapTimer = null
				this.lastTap = null
				this.togglePlay(index)
			}, DOUBLE_TAP_MS)
		},

		/**
		 * A double tap likes, and never unlikes: it is the gesture for "I love
		 * this", done again because it is fun, and taking the like back on the
		 * second go would punish exactly that. The heart goes where the
		 * finger was.
		 *
		 * @param {number} index the slide
		 * @param {MouseEvent} event the second tap
		 */
		doubleTap(index, event) {
			const target = /** @type {HTMLElement|null} */ (event?.currentTarget)
			const box = target?.getBoundingClientRect?.()
			const x = box ? event.clientX - box.left : null
			const y = box ? event.clientY - box.top : null

			this.addHeart(index, { big: true, x, y })
			this.releaseHearts(index, 2)
			if (this.reels[index]?.status?.favourited !== true) {
				this.like(index)
			}
		},

		/**
		 * The heart button: a like with hearts rising from it, or the like
		 * taken back quietly.
		 *
		 * @param {number} index the slide
		 */
		async toggleLike(index) {
			const status = this.reels[index]?.status
			if (!status) {
				return
			}

			if (status.favourited === true) {
				await this.timelineStore.postUnlike({ status })

				return
			}

			this.releaseHearts(index, 3)
			await this.like(index)
		},

		/** @param {number} index the slide whose post to like */
		async like(index) {
			const status = this.reels[index]?.status
			if (!status) {
				return
			}

			feel('like')
			await this.timelineStore.postLike({ status })
		},

		/**
		 * @param {number} index the slide
		 * @return {object[]} the hearts drawn over it
		 */
		heartsOn(index) {
			return this.hearts.filter((heart) => heart.index === index)
		},

		/**
		 * @param {number} index the slide
		 * @param {number} count how many hearts to send up the edge
		 */
		releaseHearts(index, count) {
			for (let i = 0; i < count; i++) {
				this.addHeart(index, { big: false, delay: i * 120 })
			}
		},

		/**
		 * Puts one heart on screen and takes it off again when its animation
		 * is over.
		 *
		 * @param {number} index the slide
		 * @param {object} heart what kind
		 * @param {boolean} heart.big the burst where a tap landed, or one that floats up
		 * @param {number|null} [heart.x] where, for a burst, from the slide's left
		 * @param {number|null} [heart.y] where, for a burst, from the slide's top
		 * @param {number} [heart.delay] how long to wait before it sets off, in ms
		 */
		addHeart(index, { big, x = null, y = null, delay = 0 }) {
			const id = ++heartSerial
			// a little sideways drift, a tilt and a size each, so a handful of
			// hearts reads as a handful and not as one heart drawn five times
			const drift = Math.round((Math.random() - 0.5) * 60)
			const tilt = Math.round((Math.random() - 0.5) * 30)
			const size = big ? 96 : 26 + Math.round(Math.random() * 12)
			const style = {
				'--drift': drift + 'px',
				'--tilt': tilt + 'deg',
				'--size': size + 'px',
				animationDelay: delay + 'ms',
			}
			if (big && x !== null && y !== null) {
				style.left = x + 'px'
				style.top = y + 'px'
			}

			this.hearts.push({ id, index, big, style })
			feel('heart')
			window.setTimeout(() => {
				this.hearts = this.hearts.filter((heart) => heart.id !== id)
			}, HEART_MS + delay)
		},

		togglePlay(index) {
			const video = this.videos[index]
			if (!video) {
				return
			}

			if (video.paused) {
				video.play?.().catch((error) => logger.debug('play refused', { error }))
			} else {
				video.pause?.()
			}
		},

		toggleSound() {
			this.muted = !this.muted
			const video = this.videos[this.playing]
			if (video) {
				video.muted = this.muted
			}
		},

		/**
		 * Up and down, and space for pause — a stack is reachable without a
		 * finger or it is reachable by nobody using a keyboard.
		 *
		 * @param {KeyboardEvent} event the key
		 */
		onKey(event) {
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault()
				const next = this.playing + ((event.key === 'ArrowDown') ? 1 : -1)
				this.slides[next]?.scrollIntoView({
					behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ? 'auto' : 'smooth',
				})
			} else if (event.key === ' ') {
				event.preventDefault()
				this.togglePlay(this.playing)
			} else if (event.key === 'm') {
				this.toggleSound()
			} else if (event.key === 'l') {
				this.toggleLike(this.playing)
			}
		},

		/**
		 * A fallback for the observer: a browser that does not fire it, or a
		 * scroll that lands between two thresholds, still ends up with the
		 * right slide playing.
		 */
		onScroll() {
			const track = this.$refs.track
			if (!track) {
				return
			}

			const at = Math.round(track.scrollTop / track.clientHeight)
			if (at !== this.playing && this.slides[at]) {
				this.playing = at
				this.play(at)
			}
		},

		/**
		 * The next page of videos, paged on the ids themselves.
		 *
		 * As strings end to end: an id here is a snowflake, and one rounded
		 * through a Number either re-fetches the post it points at or skips
		 * the rows between the two.
		 */
		async load() {
			if (this.loading || this.allLoaded) {
				return
			}

			this.loading = true
			this.failed = false
			const params = {}
			const ids = this.timelineStore.getTimeline.map((status) => status.id)
			const cursor = oldestId(ids)
			if (cursor !== undefined) {
				params.max_id = cursor
			}

			try {
				const page = await this.timelineStore.fetchTimeline(params)
				this.allLoaded = Array.isArray(page) ? page.length === 0 : true
			} catch (error) {
				logger.error('Could not load more videos', { error })
				this.failed = true
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.reels {
	position: relative;
	/* the app's own content area, not the window: the navigation stays where
	   it is and the stack fills what is left of the page.
	   Measured off the viewport rather than given `100%`, because nothing in
	   the chain above this has a height for a percentage to be of — so the
	   stack was as tall as one slide's contents and sat in the top half of a
	   white page. Both of the server's own offsets are taken off, or the
	   stack overhangs its column by the eight pixels of the one that was
	   missed and the column scrolls behind the slides. */
	block-size: calc(
		100vh - var(--header-height, 50px) - var(--body-container-margin, 8px)
	);
	inline-size: 100%;
	background: #000;

	&__track {
		block-size: 100%;
		overflow-y: auto;
		scroll-snap-type: y mandatory;
		/* the one gesture this page has, and it must not also pull the page
		   behind it down to refresh */
		overscroll-behavior-y: contain;

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: -2px;
		}
	}

	&__empty {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 12px;
		block-size: 100%;
		color: #fff;
	}

	&__close {
		position: absolute;
		inset-block-start: 12px;
		inset-inline-end: 12px;
	}
}

.reel {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	block-size: 100%;
	scroll-snap-align: start;
	scroll-snap-stop: always;

	&__video {
		inline-size: 100%;
		block-size: 100%;
		/* contain rather than cover: a video shot wide is not improved by
		   having its sides cut off to fill a tall window */
		object-fit: contain;
		background: #000;
	}

	&__sound {
		position: absolute;
		/* bottom right, clear of both the app's own sidebar toggle in the top
		   left and the way out in the top right */
		inset-block-end: 16px;
		inset-inline-end: 16px;
		display: flex;
		align-items: center;
		justify-content: center;
		inline-size: 40px;
		block-size: 40px;
		border: none;
		border-radius: 50%;
		background: rgba(0, 0, 0, 0.55);
		color: #fff;
		cursor: pointer;
	}

	/* above the sound button, the column every short-video app keeps its
	   actions in */
	&__like {
		position: absolute;
		inset-block-end: 68px;
		inset-inline-end: 16px;
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 2px;
		min-inline-size: 40px;
		padding: 8px 0 6px;
		border: none;
		border-radius: 20px;
		background: rgba(0, 0, 0, 0.55);
		color: #fff;
		cursor: pointer;
		transition: transform .15s ease;

		&:active {
			transform: scale(.9);
		}

		&--on {
			color: #ff3b5c;
		}

		&:focus-visible {
			outline: 2px solid #fff;
			outline-offset: 2px;
		}
	}

	&__like-count {
		font-size: 12px;
		font-weight: 600;
		color: #fff;
		font-variant-numeric: tabular-nums;
	}

	&__hearts {
		position: absolute;
		inset: 0;
		overflow: hidden;
		pointer-events: none;
	}

	&__heart {
		position: absolute;
		inline-size: var(--size);
		block-size: var(--size);
		color: #ff3b5c;
		filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.35));

		/* where a double tap landed: it swells, holds, and lifts away */
		&--burst {
			left: 50%;
			top: 45%;
			animation: reel-heart-burst .9s cubic-bezier(.2, 1.4, .4, 1) both;
		}

		/* up the edge from the heart button, drifting as it goes */
		&--float {
			inset-inline-end: 24px;
			inset-block-end: 120px;
			animation: reel-heart-float 1.6s ease-out both;
		}
	}

	&__caption {
		position: absolute;
		inset-block-end: 0;
		inset-inline: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;
		/* room on the end for the sound button, which sits over this */
		padding: 16px 72px 16px 16px;
		color: #fff;
		/* the words sit on whatever the video happens to be showing, so they
		   carry their own ground rather than hoping it is dark there */
		background: linear-gradient(to top, rgba(0, 0, 0, 0.75), transparent);
	}

	&__author {
		display: flex;
		align-items: center;
		gap: 8px;
		color: inherit;
		text-decoration: none;
	}

	&__avatar {
		inline-size: 36px;
		block-size: 36px;
		border-radius: 50%;
	}

	&__names {
		display: flex;
		flex-direction: column;
	}

	&__name {
		font-weight: bold;
	}

	&__handle {
		font-size: 90%;
		opacity: 0.8;
	}

	&__text {
		margin: 0;
		/* a caption is a caption, not the post: three lines and the rest is
		   behind Open the post */
		display: -webkit-box;
		-webkit-line-clamp: 3;
		-webkit-box-orient: vertical;
		overflow: hidden;
	}

	&__open {
		color: #fff;
		text-decoration: underline;
	}
}

@keyframes reel-heart-burst {
	0% { opacity: 0; transform: translate(-50%, -50%) scale(.2) rotate(var(--tilt)); }
	25% { opacity: 1; transform: translate(-50%, -50%) scale(1.15) rotate(var(--tilt)); }
	45% { transform: translate(-50%, -50%) scale(.95) rotate(var(--tilt)); }
	70% { opacity: 1; transform: translate(-50%, -50%) scale(1) rotate(var(--tilt)); }
	100% { opacity: 0; transform: translate(-50%, -140%) scale(.8) rotate(var(--tilt)); }
}

@keyframes reel-heart-float {
	0% { opacity: 0; transform: translate(0, 0) scale(.4) rotate(0); }
	15% { opacity: 1; transform: translate(calc(var(--drift) * .2), -20px) scale(1) rotate(var(--tilt)); }
	100% { opacity: 0; transform: translate(var(--drift), -45vh) scale(.8) rotate(calc(var(--tilt) * -1)); }
}

/* the like still lands; only the flight is taken away */
@media (prefers-reduced-motion: reduce) {
	.reel__heart {
		animation: none;
		display: none;
	}

	.reel__like {
		transition: none;
	}
}
</style>
