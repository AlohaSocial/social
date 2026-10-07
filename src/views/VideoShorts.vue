<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="shorts" role="region" :aria-label="t('social', 'Videos, one at a time')">
		<div
			ref="track"
			class="shorts__track"
			tabindex="0"
			@keydown="onKey"
			@scroll.passive="onScroll">
			<article
				v-for="(entry, index) in shorts"
				:key="entry.key"
				:ref="(el) => setSlide(el, index)"
				class="short"
				:class="{ 'short--day': entry.day }"
				:data-index="index">
				<!-- a 24-hour picture or text card is its own picture; tapping
				     it holds its clock, as tapping a video pauses it -->
				<img
					v-if="entry.day && !entry.isVideo && entry.video.url"
					class="short__poster short__picture"
					:src="entry.video.url"
					:alt="entry.video.description || entry.text"
					@click="togglePlay(index)">
				<p v-else-if="entry.day && !entry.isVideo" class="short__gone">
					{{ t('social', 'The picture of this short is gone.') }}
				</p>
				<!-- a still where the player is not: the one <video> below
				     is over whichever slide is being watched -->
				<img
					v-else-if="entry.video.preview_url"
					class="short__poster"
					:src="entry.video.preview_url"
					:alt="index === playing ? '' : (entry.video.description || entry.text)"
					loading="lazy">

				<!-- the hearts a like releases: one where a double tap landed,
				     and a few rising up the edge, the way live video does it.
				     Decoration only; the button below is what a screen reader
				     is told about. -->
				<div class="short__hearts" aria-hidden="true">
					<svg
						v-for="heart in heartsOn(index)"
						:key="heart.id"
						class="short__heart"
						:class="heart.big ? 'short__heart--burst' : 'short__heart--float'"
						:style="heart.style"
						viewBox="0 0 24 24">
						<path fill="currentColor" :d="HEART_PATH" />
					</svg>
				</div>

				<button
					v-if="entry.status"
					type="button"
					class="short__like"
					:class="{ 'short__like--on': entry.status.favourited === true }"
					:aria-pressed="entry.status.favourited === true"
					:aria-label="entry.status.favourited === true ? t('social', 'Unlike') : t('social', 'Like')"
					@click.stop="toggleLike(index)">
					<IconHeart v-if="entry.status.favourited === true" :size="24" />
					<IconHeartOutline v-else :size="24" />
					<span v-if="entry.status.favourites_count > 0 && !settingsStore.hidesCounts" class="short__like-count">
						{{ entry.status.favourites_count }}
					</span>
				</button>

				<!-- the one control that is not a gesture: a pointer has no swipe -->
				<button
					v-if="entry.isVideo"
					type="button"
					class="short__sound"
					:aria-label="silent ? t('social', 'Unmute') : t('social', 'Mute')"
					@click.stop="toggleSound">
					<IconVolumeOff v-if="silent" :size="20" />
					<IconVolumeHigh v-else :size="20" />
				</button>
				<!-- the browser would not start with sound: say where it is -->
				<span v-if="entry.isVideo && soundHeld && index === playing" class="short__sound-hint" aria-hidden="true">
					{{ t('social', 'Tap for sound') }}
				</span>

				<DayShortPanel
					v-if="entry.day"
					:short="entry.day"
					:own="entry.own"
					:active="index === playing"
					:held="held"
					@done="onDayDone(index)"
					@seen="onDaySeen"
					@deleted="onDayDeleted"
					@hold="onDayHold" />

				<div v-else class="short__caption">
					<router-link
						class="short__author"
						:to="{ name: 'profile', params: { account: entry.status.account.acct } }">
						<img
							v-if="entry.status.account.avatar"
							class="short__avatar"
							:src="entry.status.account.avatar"
							alt="">
						<span class="short__names">
							<span class="short__name">{{ entry.status.account.display_name || entry.status.account.username }}</span>
							<span class="short__handle">@{{ entry.status.account.acct }}</span>
						</span>
					</router-link>
					<p v-if="entry.text" class="short__text">
						{{ entry.text }}
					</p>
					<!-- why For you put it here, as the chip over a post says it -->
					<p v-if="reasonOf(entry.status)" class="short__reason" :aria-label="reasonOf(entry.status).label">
						{{ reasonOf(entry.status).text }}
					</p>
					<router-link
						class="short__open"
						:to="{ name: 'single-post', params: { account: entry.status.account.acct, id: entry.status.id } }">
						{{ t('social', 'Open the post') }}
					</router-link>
				</div>
			</article>

			<!-- one player for the whole stack, moved to the slide on screen and
			     given its video. WebKit lets an element play with sound only
			     once a gesture has allowed it, and a scroll is not a gesture:
			     a new element per slide was refused the sound on every slide
			     after the first. It stays, hidden, while a 24-hour picture is
			     on screen, for the same reason. A kept short loops; a 24-hour
			     one moves on when it ends. -->
			<video
				v-if="current"
				v-show="current.isVideo"
				ref="player"
				class="short__video"
				:style="{ '--at': playing }"
				:src="current.isVideo ? current.video.url : undefined"
				:poster="(current.isVideo && current.video.preview_url) || undefined"
				:aria-label="current.isVideo ? (current.video.description || current.text) : undefined"
				playsinline
				:loop="!current.day"
				preload="auto"
				@timeupdate="shortSignals.progress(current.status, $event.target)"
				@ended="onEnded"
				@click="onVideoTap(playing, $event)" />

			<div v-if="shorts.length === 0 && (loading || !dayReady)" class="shorts__empty">
				<NcLoadingIcon :size="44" appearance="light" />
				<p>{{ t('social', 'Loading videos …') }}</p>
			</div>

			<!-- a feed that could not be fetched is not a feed with nothing in it -->
			<div v-else-if="shorts.length === 0 && failed" class="shorts__empty" role="alert">
				<p>{{ t('social', 'The videos could not be loaded.') }}</p>
				<NcButton @click="load">
					<template #icon>
						<IconRefresh :size="20" />
					</template>
					{{ t('social', 'Try again') }}
				</NcButton>
			</div>

			<div v-else-if="shorts.length === 0" class="shorts__empty">
				<p>{{ t('social', 'No videos here yet.') }}</p>
				<NcButton :to="{ name: 'timeline', params: { type: 'videos' } }">
					{{ t('social', 'Back to Videos') }}
				</NcButton>
			</div>
		</div>

		<!-- a new short: its own dialog, made for a video -->
		<button
			type="button"
			class="shorts__create"
			:title="t('social', 'New short')"
			:aria-label="t('social', 'New short')"
			@click="startShort">
			<IconPlus :size="24" />
		</button>
		<ShortComposerDialog v-model:open="composing" @posted="onPosted" />

		<!-- whose videos: the three circles the Videos page is read at. Shorts
		     is its own entry in the sidebar, so this page is where the choice
		     is made rather than something carried over from the grid -->
		<nav class="shorts__scopes" :aria-label="t('social', 'Whose videos')">
			<router-link
				v-for="option in scopes"
				:key="option.value"
				class="shorts__scope"
				:class="{ 'shorts__scope--current': option.value === watching }"
				:aria-current="option.value === watching ? 'page' : undefined"
				:to="{ name: 'shorts', query: { scope: option.value } }">
				{{ option.label }}
			</router-link>
		</nav>
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
 * Four rules hold it together:
 *
 *  - **One plays at a time.** An IntersectionObserver decides which, and
 *    there is only one element to play it with; a dozen videos buffering
 *    behind the one on screen is a phone getting hot for nothing.
 *  - **Sound on, where the browser allows it.** Somebody who opens Shorts
 *    from the sidebar has pressed something to get here, and a browser
 *    counts that as leave to play with sound. Opened cold -- a pasted link,
 *    a reload -- the browser refuses, and the stack falls back to muted and
 *    says "Tap for sound" rather than showing a still. The reader's choice
 *    applies to every video, because it is a statement about this page
 *    rather than about one video; a refusal is not a choice, and the next
 *    video is asked with sound again (see useSoundAutoplay).
 *  - **One element.** A single <video> follows the slide on screen and the
 *    others show their poster, so the element a tap once allowed to play
 *    with sound keeps that leave for every video after it.
 *  - **The scroll does the work.** CSS scroll-snap rather than a transform per
 *    slide: it is the one thing that behaves the same under a finger, a
 *    trackpad, a wheel and a keyboard, and it keeps working when the
 *    JavaScript that observes it does not.
 *
 * Before the kept shorts come the 24-hour shorts of the people the reader
 * follows (the stories carousel), one person after another, those with
 * something unseen first and the reader's own last. `?account=` puts that
 * person first, which is how a face in the Home bar opens the stack at them.
 * A 24-hour short plays in the same stack: a video in the one player, ending
 * into the next slide; a picture or a text card for the seconds its poster
 * gave it. Its marks and its answers are DayShortPanel's.
 */
import axios from '@nextcloud/axios'
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { mapStores } from 'pinia'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconHeart from 'vue-material-design-icons/Heart.vue'
import IconHeartOutline from 'vue-material-design-icons/HeartOutline.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconRefresh from 'vue-material-design-icons/Refresh.vue'
import IconVolumeHigh from 'vue-material-design-icons/VolumeHigh.vue'
import IconVolumeOff from 'vue-material-design-icons/VolumeOff.vue'
import { useAccountStore } from '../store/account.js'
import { useSettingsStore } from '../store/settings.js'
import { isRanked, useTimelineStore } from '../store/timeline.js'
import { hasInterestsFeed, isTracking } from '../services/interests.js'
import { createShortSignals } from '../services/shortSignals.js'
import { interestReason } from '../utils/interestReason.js'
import { oldestId } from '../utils/snowflake.js'
import { htmlToPlainText } from '../utils/plainText.js'
import logger from '../services/logger.js'
import { feel } from '../services/senses.js'
import { useSoundAutoplay } from '../composables/useSoundAutoplay.js'
import DayShortPanel from '../components/DayShortPanel.vue'
import ShortComposerDialog from '../components/ShortComposerDialog.vue'

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
	name: 'VideoShorts',
	components: {
		DayShortPanel,
		IconHeart,
		IconHeartOutline,
		IconPlus,
		IconRefresh,
		IconVolumeHigh,
		IconVolumeOff,
		NcButton,
		NcLoadingIcon,
		ShortComposerDialog,
	},

	props: {
		/**
		 * Which circle of people: 'home' (the ones you follow), 'timeline'
		 * (this instance) or 'federated' (everywhere) — the same three the
		 * Videos page is read at, carried here so that leaving the grid for
		 * the stack does not silently change what is in it — or 'interests',
		 * For you narrowed to videos. '' is the default, see `watching`.
		 */
		scope: {
			type: String,
			default: '',
		},

		/** whose 24-hour shorts come first, by handle; '' for the usual order */
		account: {
			type: String,
			default: '',
		},
	},

	setup() {
		const { muted, soundHeld, silent, playWithSound, toggleMute } = useSoundAutoplay()

		return { muted, soundHeld, silent, playWithSound, toggleMute }
	},

	data() {
		return {
			/** the New short dialog is open */
			composing: false,
			/** the slide on screen, which the player is over */
			playing: 0,
			/** the 24-hour shorts, in the order they are watched */
			dayShorts: [],
			/** the 24-hour shorts have been asked for; the stack waits for them */
			dayReady: false,
			/** a 24-hour picture's clock is held */
			held: false,
			loading: false,
			/** the last page asked for could not be fetched */
			failed: false,
			allLoaded: false,
			slides: [],
			/** tells onVisible which slide is on screen */
			observer: null,
			/** the hearts on screen, each with the slide it belongs to */
			hearts: [],
			/** the first tap of what may become a double tap */
			lastTap: null,
			/** the pause a single tap is waiting to do */
			tapTimer: null,
			/** what watching teaches For you (`shortSignals.js`) */
			shortSignals: createShortSignals({
				enabled: () => {
					const serverData = useSettingsStore().getServerData

					return !serverData?.public && isTracking(serverData?.interests)
				},
			}),

			HEART_PATH,
		}
	},

	computed: {
		...mapStores(useAccountStore, useSettingsStore, useTimelineStore),

		/** @return {boolean} whether this reader is offered 24-hour shorts at all */
		dayOffered() {
			const serverData = this.settingsStore.getServerData

			return getCurrentUser() !== null
				&& !serverData?.public
				&& serverData?.sections?.stories !== false
		},

		/** @return {string} the reader's handle, for telling their own shorts apart */
		viewerAcct() {
			return this.accountStore.currentAccount?.acct ?? getCurrentUser()?.uid ?? ''
		},

		/**
		 * The circles a reader can watch: the people they follow, this server,
		 * everywhere. Somebody reading without a session follows nobody, so
		 * they are offered the other two.
		 *
		 * @return {Array<{ value: string, label: string }>}
		 */
		scopes() {
			const all = [
				{ value: 'home', label: t('social', 'My Feed') },
				{ value: 'timeline', label: t('social', 'Local') },
				{ value: 'federated', label: t('social', 'Global') },
			]
			// For you, for a signed-in reader who has it: beside My Feed, as
			// above the timeline
			if (this.hasForYou) {
				all.splice(1, 0, { value: 'interests', label: t('social', 'For you') })
			}

			return this.settingsStore.getServerData?.public ? all.slice(1) : all
		},

		/** @return {boolean} whether For you is on for this reader */
		hasForYou() {
			const serverData = this.settingsStore.getServerData

			return !serverData?.public && hasInterestsFeed(serverData?.interests)
		},

		/**
		 * The scope being watched: the one asked for when it is on offer, and
		 * otherwise For you once reading has taught it something, My Feed
		 * before that.
		 *
		 * @return {string}
		 */
		watching() {
			if (this.scopes.some((option) => option.value === this.scope)) {
				return this.scope
			}

			return this.hasForYou && this.settingsStore.getServerData?.interests?.profile === true ? 'interests' : 'home'
		},

		/**
		 * One slide per 24-hour short.
		 *
		 * @return {object[]} the short, its media, its words, and whether it is the reader's
		 */
		dayEntries() {
			return this.dayShorts.map((short) => ({
				key: 'day:' + short.id,
				day: short,
				video: short.media ?? {},
				isVideo: short.media?.type === 'video',
				text: String(short.caption ?? ''),
				own: this.isOwn(short),
			}))
		},

		/**
		 * The 24-hour shorts, then the kept ones. Nothing until the 24-hour
		 * shorts have been asked for, so that they do not arrive above a slide
		 * somebody is already watching.
		 *
		 * @return {object[]}
		 */
		shorts() {
			return this.dayReady ? [...this.dayEntries, ...this.keptEntries] : []
		},

		/**
		 * One entry per video, not per post: a post with three videos on it is
		 * three things to watch, and a stack that showed only the first would
		 * be hiding two of them behind a grid tile nobody goes back to.
		 *
		 * @return {object[]} the video, the post it is on, and its words
		 */
		keptEntries() {
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
							isVideo: true,
							text: htmlToPlainText(status.content ?? '').trim(),
						})
					}
				}
			}

			return entries
		},

		/** @return {object|undefined} the slide the player is over */
		current() {
			return this.shorts[this.playing]
		},
	},

	watch: {
		// the query is the prop, and the router reuses this view when only
		// the query changes, so a new scope has to switch the feed here
		watching() {
			this.open()
		},

		// a face tapped while the stack is already open
		account(now) {
			this.dayShorts = this.orderDay(this.dayShorts, now)
			this.toTop()
		},
	},

	mounted() {
		this.open()
		this.loadDay()

		// `threshold: 0.6` rather than a bare intersection: two slides touch
		// the viewport for most of a scroll, and whichever was observed last
		// would win. A slide is the one being watched when most of it is there.
		this.observer = new IntersectionObserver(this.onVisible, { threshold: 0.6 })
		const track = /** @type {HTMLElement|undefined} */ (this.$refs.track)
		track?.focus?.()
	},

	beforeUnmount() {
		this.shortSignals.leave()
		window.clearTimeout(this.tapTimer)
		this.observer?.disconnect()
		this.player()?.pause?.()
	},

	updated() {
		// a 24-hour short taken out leaves its old place behind
		this.slides.length = this.shorts.length
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
			this.shortSignals.leave()
			this.timelineStore.changeTimelineType({
				type: 'videos',
				params: { scope: this.watching },
			})
			this.allLoaded = false
			this.playing = 0
			this.load()
		},

		/**
		 * The 24-hour shorts of the people the reader follows, and the
		 * reader's own.
		 *
		 * @param {string} [first] whose to put first, by handle
		 * @return {Promise<void>}
		 */
		async loadDay(first = this.account) {
			if (!this.dayOffered) {
				this.dayShorts = []
				this.dayReady = true

				return
			}

			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/stories/carousel'))
				this.dayShorts = this.orderDay(Array.isArray(data) ? data : [], first)
			} catch (error) {
				// the kept shorts are still a stack without them
				logger.debug('could not load the 24-hour shorts', { error })
				this.dayShorts = []
			} finally {
				this.dayReady = true
			}
		},

		/**
		 * One person after another: the one asked for first, then those with
		 * something unseen, then those seen, then the reader's own. Each
		 * person's shorts stay in the order the server gave them.
		 *
		 * Worked out when the list arrives and not as it is watched, or a
		 * short marked seen would move under the reader.
		 *
		 * @param {object[]} shorts the carousel
		 * @param {string} first whose to put first, by handle
		 * @return {object[]} the same shorts, ordered
		 */
		orderDay(shorts, first) {
			const groups = new Map()
			for (const short of shorts) {
				const account = short?.account
				if (!account) {
					continue
				}
				if (!groups.has(account.id)) {
					groups.set(account.id, { account, shorts: [], seen: true, own: this.isOwn(short) })
				}
				const group = groups.get(account.id)
				group.shorts.push(short)
				if (!short.seen) {
					group.seen = false
				}
			}

			const rank = (group) => {
				if (first !== '' && group.account.acct === first) {
					return 0
				}
				if (group.own) {
					return 3
				}

				return group.seen ? 2 : 1
			}

			return [...groups.values()]
				.sort((a, b) => rank(a) - rank(b))
				.flatMap((group) => group.shorts)
		},

		/**
		 * @param {object} short a 24-hour short
		 * @return {boolean} whether it is the reader's own
		 */
		isOwn(short) {
			return this.viewerAcct !== '' && short?.account?.acct === this.viewerAcct
		},

		/** Back to the first slide, and plays it. */
		toTop() {
			this.playing = 0
			const track = /** @type {HTMLElement|undefined} */ (this.$refs.track)
			if (track) {
				track.scrollTop = 0
			}
			this.play(0)
		},

		/**
		 * @param {object} status a slide's post
		 * @return {{text: string, label: string}|null} why For you showed it
		 */
		reasonOf(status) {
			return interestReason(status?.interest)
		},

		setSlide(el, index) {
			this.slides[index] = el
		},

		/** @return {HTMLVideoElement|undefined} the one player */
		player() {
			return /** @type {HTMLVideoElement|undefined} */ (this.$refs.player)
		},

		/**
		 * Whichever slide is mostly on screen becomes the one playing. There
		 * is only one element to play it with, so a slide scrolled past stops
		 * by losing it rather than being paused and left buffering.
		 *
		 * @param {IntersectionObserverEntry[]} entries what moved
		 */
		onVisible(entries) {
			for (const entry of entries) {
				if (!entry.isIntersecting) {
					continue
				}

				const index = Number(/** @type {HTMLElement} */ (entry.target).dataset.index)
				if (Number.isNaN(index)) {
					continue
				}

				this.playing = index
				this.play(index)
			}
		},

		/**
		 * Plays the slide once the player has moved to it and been given its
		 * video. A new `src` starts from the first frame, so coming back to a
		 * slide is the video again rather than its last second.
		 *
		 * @param {number} index the slide on screen
		 * @return {Promise<void>}
		 */
		async play(index) {
			this.held = false
			this.shortSignals.enter(this.shorts[index]?.status)
			if (index >= this.shorts.length - LOOK_AHEAD) {
				this.load()
			}

			await this.$nextTick()
			if (index !== this.playing) {
				return
			}

			// a picture runs on its own clock; the hidden player must not go
			// on with the video before it
			if (!this.shorts[index]?.isVideo) {
				this.player()?.pause?.()

				return
			}

			await this.playWithSound(this.player())
		},

		/** A kept short loops; a 24-hour one moves on. */
		onEnded() {
			if (this.current?.day) {
				this.advance()

				return
			}
			this.shortSignals.ended(this.current?.status)
		},

		/** On to the next slide, the way the arrow key goes. */
		advance() {
			this.scrollToSlide(this.playing + 1)
		},

		/** @param {number} index the slide to bring on screen */
		scrollToSlide(index) {
			this.slides[index]?.scrollIntoView?.({
				behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ? 'auto' : 'smooth',
			})
		},

		/** @param {number} index the slide whose picture's time is up */
		onDayDone(index) {
			if (index === this.playing) {
				this.advance()
			}
		},

		/** @param {string} id the 24-hour short the server has marked seen */
		onDaySeen(id) {
			const short = this.dayShorts.find((one) => one.id === id)
			if (short) {
				short.seen = true
			}
		},

		/**
		 * One of the reader's own is gone: the slide after it takes its place.
		 *
		 * @param {object} deleted the short
		 * @return {Promise<void>}
		 */
		async onDayDeleted(deleted) {
			this.dayShorts = this.dayShorts.filter((one) => one.id !== deleted.id)
			this.playing = Math.max(0, Math.min(this.playing, this.shorts.length - 1))
			await this.$nextTick()
			this.play(this.playing)
		},

		/**
		 * A reply is being written: the video under it stops, and goes on
		 * when the writer leaves the field.
		 *
		 * @param {boolean} on whether to hold
		 */
		onDayHold(on) {
			this.held = on
			const video = this.current?.isVideo ? this.player() : undefined
			if (!video) {
				return
			}
			if (on) {
				video.pause?.()
			} else if (video.paused && !video.ended) {
				this.playWithSound(video)
			}
		},

		/**
		 * Something new from the New short dialog: a kept one is in the
		 * timeline, a 24-hour one in the carousel, shown first.
		 *
		 * @param {object} [made] what was posted
		 * @param {string} [lifetime] 'kept' or 'day'
		 * @return {Promise<void>}
		 */
		async onPosted(made, lifetime) {
			if (lifetime !== 'day') {
				this.open()

				return
			}

			await this.loadDay(this.viewerAcct)
			await this.$nextTick()
			this.toTop()
		},

		/**
		 * One tap pauses; two tap-taps like. The first tap has to wait to see
		 * whether a second follows, or a double tap would also pause and play.
		 *
		 * @param {number} index the slide
		 * @param {MouseEvent} event the tap, for where the heart goes
		 */
		onVideoTap(index, event) {
			// a 24-hour short is answered with a reaction, not a like
			if (this.shorts[index]?.day) {
				this.togglePlay(index)

				return
			}

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
			if (this.shorts[index]?.status?.favourited !== true) {
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
			const status = this.shorts[index]?.status
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
			const status = this.shorts[index]?.status
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

		/** Opens the New short dialog, with the stack paused behind it. */
		startShort() {
			this.player()?.pause?.()
			this.composing = true
		},

		togglePlay(index) {
			if (index !== this.playing) {
				return
			}
			if (!this.shorts[index]?.isVideo) {
				this.held = !this.held

				return
			}

			const video = this.player()
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
			this.toggleMute(this.player())
		},

		/**
		 * Up and down, and space for pause — a stack is reachable without a
		 * finger or it is reachable by nobody using a keyboard.
		 *
		 * @param {KeyboardEvent} event the key
		 */
		onKey(event) {
			// somebody writing a reply is not paging
			if (/** @type {Element|null} */ (event.target)?.closest?.('input, textarea, [contenteditable]')) {
				return
			}

			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault()
				this.scrollToSlide(this.playing + ((event.key === 'ArrowDown') ? 1 : -1))
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
			const track = /** @type {HTMLElement|undefined} */ (this.$refs.track)
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
			// a ranking pages from where it ended, not from its oldest post
			const cursor = isRanked(this.timelineStore) ? ids[ids.length - 1] : oldestId(ids)
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
.shorts {
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
		/* the player is placed against the track, a slide's height per slide */
		position: relative;
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

	/* top right, over the video, where every short-video app keeps it */
	/* above the column of per-video buttons, for the whole stack */
	&__create {
		position: absolute;
		z-index: 3;
		inset-block-end: 132px;
		inset-inline-end: 16px;
		display: flex;
		align-items: center;
		justify-content: center;
		inline-size: 40px;
		block-size: 40px;
		padding: 0;
		border: 2px solid #fff;
		border-radius: 12px;
		background: linear-gradient(135deg, #ff3b5c, #0082c9);
		color: #fff;
		cursor: pointer;
		transition: transform .15s ease;

		&:hover {
			transform: scale(1.08);
		}

		&:focus-visible {
			outline: 2px solid #fff;
			outline-offset: 2px;
		}
	}

	&__scopes {
		position: absolute;
		inset-block-start: 12px;
		inset-inline-end: 12px;
		display: flex;
		gap: 2px;
		padding: 3px;
		border-radius: var(--border-radius-pill, 999px);
		background: rgba(0, 0, 0, 0.55);
	}

	&__scope {
		padding: 4px 12px;
		border-radius: var(--border-radius-pill, 999px);
		color: rgba(255, 255, 255, 0.8);
		font-size: 13px;
		font-weight: 600;
		text-decoration: none;

		&--current {
			background: #fff;
			color: #000;
		}

		&:focus-visible {
			outline: 2px solid #fff;
			outline-offset: 2px;
		}
	}
}

.short {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	block-size: 100%;
	scroll-snap-align: start;
	scroll-snap-stop: always;

	&__video,
	&__poster {
		position: absolute;
		inset-inline: 0;
		inline-size: 100%;
		block-size: 100%;
		/* contain rather than cover: a video shot wide is not improved by
		   having its sides cut off to fill a tall window */
		object-fit: contain;
		background: #000;
	}

	&__poster {
		inset-block-start: 0;
	}

	&__picture {
		cursor: pointer;
	}

	&__gone {
		color: rgba(255, 255, 255, 0.7);
	}

	/* over the slide being watched: every slide is exactly the track's
	   height, so the n-th starts n track-heights down */
	&__video {
		inset-block-start: calc(var(--at) * 100%);
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
		/* over the caption, which runs the width of the slide underneath it
		   and used to swallow every press meant for this button */
		z-index: 2;
	}

	&__sound-hint {
		position: absolute;
		inset-block-end: 24px;
		inset-inline-end: 64px;
		z-index: 2;
		padding: 3px 10px;
		border-radius: 999px;
		background: rgba(0, 0, 0, 0.7);
		color: #fff;
		font-size: 12px;
		font-weight: 600;
		pointer-events: none;
		animation: short-hint-in .4s ease-out both;
	}

	/* above the sound button, the column every short-video app keeps its
	   actions in */
	&__caption a {
		pointer-events: auto;
	}

	&__like {
		position: absolute;
		z-index: 2;
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
		/* over the player, which comes after the slides */
		z-index: 1;
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
			/* stylelint-disable-next-line csstools/use-logical -- a point on the screen, not a side: where the finger landed is set inline as `left`, and the heart is centred on it with translate() */
			left: 50%;
			top: 45%;
			animation: short-heart-burst .9s cubic-bezier(.2, 1.4, .4, 1) both;
		}

		/* up the edge from the heart button, drifting as it goes */
		&--float {
			inset-inline-end: 24px;
			inset-block-end: 120px;
			animation: short-heart-float 1.6s ease-out both;
		}
	}

	&__caption {
		position: absolute;
		z-index: 1;
		/* the caption's own links take presses; the rest of it lets them
		   through to the video (tap to pause) and to the buttons */
		pointer-events: none;
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

	&__reason {
		margin: 0;
		font-size: 90%;
		opacity: 0.8;
	}

	&__open {
		color: #fff;
		text-decoration: underline;
	}
}

@keyframes short-heart-burst {
	0% { opacity: 0; transform: translate(-50%, -50%) scale(.2) rotate(var(--tilt)); }
	25% { opacity: 1; transform: translate(-50%, -50%) scale(1.15) rotate(var(--tilt)); }
	45% { transform: translate(-50%, -50%) scale(.95) rotate(var(--tilt)); }
	70% { opacity: 1; transform: translate(-50%, -50%) scale(1) rotate(var(--tilt)); }
	100% { opacity: 0; transform: translate(-50%, -140%) scale(.8) rotate(var(--tilt)); }
}

@keyframes short-heart-float {
	0% { opacity: 0; transform: translate(0, 0) scale(.4) rotate(0); }
	15% { opacity: 1; transform: translate(calc(var(--drift) * .2), -20px) scale(1) rotate(var(--tilt)); }
	100% { opacity: 0; transform: translate(var(--drift), -45vh) scale(.8) rotate(calc(var(--tilt) * -1)); }
}

@keyframes short-hint-in {
	from { opacity: 0; transform: translateX(8px); }
	to { opacity: 1; transform: none; }
}

/* the like still lands; only the flight is taken away */
@media (prefers-reduced-motion: reduce) {
	.short__sound-hint {
		animation: none;
	}

	.shorts__create {
		transition: none;
	}

	.short__heart {
		animation: none;
		display: none;
	}

	.short__like {
		transition: none;
	}
}
</style>
