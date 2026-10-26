<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		ref="page"
		class="shorts"
		role="region"
		:aria-label="t('social', 'Videos, one at a time')">
		<header ref="header" class="shorts__header">
			<div class="shorts__heading-row">
				<h1 class="shorts__heading">
					{{ t('social', 'Shorts') }}
				</h1>
				<NcButton
					v-if="!settingsStore.getServerData.public"
					class="shorts__create"
					variant="primary"
					:aria-label="t('social', 'New short')"
					@click="startShort">
					<template #icon>
						<IconPlus :size="20" />
					</template>
					{{ t('social', 'New short') }}
				</NcButton>
			</div>
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
		</header>
		<div
			ref="track"
			class="shorts__track"
			:style="trackStyle"
			:aria-label="t('social', 'Shorts player')"
			aria-describedby="shorts-keyboard-help"
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
				<!-- a 24-hour picture or text card is its own picture; pressing
				     it holds its clock, as tapping a video pauses it -->
				<button
					v-if="entry.day && !entry.isVideo && entry.video.url"
					type="button"
					class="short__picture-hold"
					:aria-pressed="index === playing && held"
					:aria-label="t('social', 'Hold this short')"
					@click="togglePlay(index)">
					<img
						class="short__poster short__picture"
						:src="entry.video.url"
						:alt="entry.video.description || entry.text"
						@load="onPosterMetadata($event, index)">
				</button>
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
				<NcActions
					v-if="canOfferRecommendationFeedback(entry)"
					class="short__recommendation-menu"
					:aria-label="t('social', 'For You recommendations')"
					:title="t('social', 'For You recommendations')"
					variant="tertiary-no-background"
					@update:open="onRecommendationMenuToggle(entry.status, $event)">
					<NcActionButton :disabled="recommendationActionLoading(entry.status) || recommendationContext(entry.status)?.similarity_available !== true" closeAfterClick @click="applyRecommendationFeedback(entry.status, 'MORE_SIMILAR')">
						<template #icon>
							<ThumbUpOutline :size="20" />
						</template>
						{{ t('social', 'Show me more like this post') }}
					</NcActionButton>
					<NcActionButton :disabled="recommendationActionLoading(entry.status) || recommendationContext(entry.status)?.similarity_available !== true" closeAfterClick @click="applyRecommendationFeedback(entry.status, 'LESS_SIMILAR')">
						<template #icon>
							<ThumbDownOutline :size="20" />
						</template>
						{{ t('social', 'Show me less like this post') }}
					</NcActionButton>
					<NcActionButton closeAfterClick @click="applyRecommendationFeedback(entry.status, 'HIDE_POST')">
						<template #icon>
							<EyeOffOutline :size="20" />
						</template>
						{{ t('social', 'Hide this post from For You') }}
					</NcActionButton>
					<NcActionButton closeAfterClick @click="applyRecommendationFeedback(entry.status, 'MORE_AUTHOR')">
						<template #icon>
							<AccountPlusOutline :size="20" />
						</template>
						{{ t('social', 'Show me more from this author') }}
					</NcActionButton>
					<NcActionButton :disabled="recommendationActionLoading(entry.status) || !recommendationContext(entry.status)?.format" closeAfterClick @click="applyRecommendationFeedback(entry.status, 'MORE_FORMAT')">
						<template #icon>
							<ShapeOutline :size="20" />
						</template>
						{{ t('social', 'Show me more {format}', { format: recommendationFormatLabel(entry.status) }) }}
					</NcActionButton>
				</NcActions>

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
				@play="playerPaused = false"
				@pause="playerPaused = true"
				@error="onPlaybackError"
				@loadedmetadata="onVideoMetadata"
				@timeupdate="onVideoProgress"
				@ended="onEnded"
				@click="onVideoTap(playing, $event)" />

			<div v-if="current?.isVideo && videoFailed" class="short__playback-error" role="alert">
				<p>{{ t('social', 'This video could not be played. You can retry or move to the next short.') }}</p>
				<NcButton @click="retryPlayback">
					{{ t('social', 'Try again') }}
				</NcButton>
			</div>

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
				<p>{{ t('social', 'No shorts here yet.') }}</p>
				<p class="shorts__rule">
					{{ t('social', 'A short is a video of up to three minutes, upright or square.') }}
				</p>
			</div>
		</div>

		<div ref="controls" class="shorts__footer">
			<div v-if="shorts.length > 0 && failed" class="shorts__page-error" role="alert">
				<p>{{ t('social', 'More videos could not be loaded. The current short is still available.') }}</p>
				<NcButton :disabled="loading" @click="load">
					{{ t('social', 'Try again') }}
				</NcButton>
			</div>
			<div
				v-if="current"
				class="shorts__controls"
				role="group"
				:aria-label="t('social', 'Short playback')">
				<NcButton :disabled="playing === 0" :aria-label="t('social', 'Previous short')" @click="scrollToSlide(playing - 1)">
					<template #icon>
						<IconChevronUp :size="20" />
					</template>
				</NcButton>
				<NcButton :aria-label="playbackPaused ? t('social', 'Play') : t('social', 'Pause')" @click="togglePlay(playing)">
					<template #icon>
						<IconPlay v-if="playbackPaused" :size="20" />
						<IconPause v-else :size="20" />
					</template>
					{{ playbackPaused ? t('social', 'Play') : t('social', 'Pause') }}
				</NcButton>
				<span class="shorts__position" role="status">
					{{ loading ? t('social', 'Loading…') : t('social', '{current} of {total}', { current: playing + 1, total: shorts.length }) }}
				</span>
				<NcButton :disabled="loading || (allLoaded && playing >= shorts.length - 1)" :aria-label="t('social', 'Next short')" @click="nextShort">
					<template #icon>
						<IconChevronDown :size="20" />
					</template>
				</NcButton>
			</div>
		</div>
		<p id="shorts-keyboard-help" class="hidden-visually">
			{{ t('social', 'Use Up and Down to change shorts, Space to pause, M for sound and L to like.') }}
		</p>

		<ShortComposerDialog v-model:open="composing" @posted="onPosted" />
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
 * it goes past. This is that stack, over the same timelines narrowed to
 * shorts — `only_short`, a single video of at most three minutes, upright or
 * square — so the stack, For you's Shorts scope and a client asking the API
 * share the server's one idea of what a short is.
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
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import IconChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import IconPlay from 'vue-material-design-icons/Play.vue'
import IconPause from 'vue-material-design-icons/Pause.vue'
import IconHeart from 'vue-material-design-icons/Heart.vue'
import IconHeartOutline from 'vue-material-design-icons/HeartOutline.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconRefresh from 'vue-material-design-icons/Refresh.vue'
import IconVolumeHigh from 'vue-material-design-icons/VolumeHigh.vue'
import IconVolumeOff from 'vue-material-design-icons/VolumeOff.vue'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import EyeOffOutline from 'vue-material-design-icons/EyeOffOutline.vue'
import ShapeOutline from 'vue-material-design-icons/ShapeOutline.vue'
import ThumbDownOutline from 'vue-material-design-icons/ThumbDownOutline.vue'
import ThumbUpOutline from 'vue-material-design-icons/ThumbUpOutline.vue'
import { useAccountStore } from '../store/account.js'
import { useSettingsStore } from '../store/settings.js'
import { isRanked, useTimelineStore } from '../store/timeline.js'
import { fetchRecommendationPreferences, hasInterestsFeed, isRecommendationLearningActive, isTracking } from '../services/interests.js'
import { createShortSignals } from '../services/shortSignals.js'
import { interestReason } from '../utils/interestReason.js'
import { fetchRecommendationContext, recordRecommendationFeedback, undoRecommendationFeedback } from '../services/recommendationFeedback.js'
import { showError, showUndo } from '../services/toast.js'

/**
 * What a clip must have gathered — likes, boosts and views together — before
 * "Popular right now" or "Trending here" is said of it. Below this the slide
 * carries the author line alone.
 */
const POPULARITY_FLOOR = 10
import { oldestId } from '../utils/snowflake.js'
import { htmlToPlainText } from '../utils/plainText.js'
import logger from '../services/logger.js'
import { feel } from '../services/senses.js'
import { useSoundAutoplay } from '../composables/useSoundAutoplay.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'
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
		IconChevronUp,
		IconChevronDown,
		IconPlay,
		IconPause,
		IconHeart,
		IconHeartOutline,
		IconPlus,
		IconRefresh,
		IconVolumeHigh,
		IconVolumeOff,
		AccountPlusOutline,
		EyeOffOutline,
		NcActions,
		NcActionButton,
		NcButton,
		NcLoadingIcon,
		ShapeOutline,
		ShortComposerDialog,
		ThumbDownOutline,
		ThumbUpOutline,
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

		/**
		 * Which post to start at, by id: a short chosen on the Videos page's
		 * Shorts shelf. '' starts at the top; a post the first page does not
		 * hold starts there too.
		 */
		start: {
			type: String,
			default: '',
		},
	},

	setup() {
		const { muted, soundHeld, silent, playWithSound, toggleMute } = useSoundAutoplay()
		const { username } = useCurrentUser()

		return { muted, soundHeld, silent, playWithSound, toggleMute, username }
	},

	data() {
		return {
			/** the New short dialog is open */
			composing: false,
			/** the post still to be started at, '' once there is none */
			startAt: '',
			/** the slide on screen, which the player is over */
			playing: 0,
			playerPaused: true,
			videoFailed: false,
			/** slide currently loaded by the single video player */
			playerSlideKey: '',
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
			recommendationContexts: {},
			recommendationContextLoading: {},
			/**
			 * Which scope the page in flight belongs to. A page the store
			 * dropped because the scope changed under it comes back empty,
			 * and read as the end of the new scope it left the stack black
			 * for good.
			 */
			generation: 0,
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
				allowUntagged: useSettingsStore().getServerData.interests?.recommendationsEnabled === true,
				legacyLearning: () => isTracking(useSettingsStore().getServerData.interests),
				enabled: () => {
					const serverData = useSettingsStore().getServerData

					return !serverData?.public && (serverData?.interests?.recommendationsEnabled === true || isTracking(serverData?.interests))
				},
			}),

			/** available area below the page header, in CSS pixels */
			availableWidth: 0,
			availableHeight: 0,
			/** the current video's aspect ratio, refined by loaded metadata */
			videoAspectRatio: 9 / 16,
			/** watches the page and header as their size changes */
			layoutObserver: null,
			resizeHandler: null,

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
			return this.accountStore.currentAccount?.acct ?? this.username
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

			return !serverData?.public
				&& (serverData?.interests?.recommendationsEnabled === true || hasInterestsFeed(serverData?.interests))
		},

		/**
		 * The scope being watched: the one asked for when it is on offer, and
		 * otherwise For you when its learned profile is ready or instance
		 * recommendations are enabled, My Feed before that.
		 *
		 * @return {string}
		 */
		watching() {
			if (this.scopes.some((option) => option.value === this.scope)) {
				return this.scope
			}

			const interests = this.settingsStore.getServerData?.interests
			const readyForRecommendations = interests?.recommendationsEnabled === true
				|| interests?.profile === true

			return this.hasForYou && readyForRecommendations ? 'interests' : 'home'
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

		/** @return {boolean} the real video state, or the held picture timer */
		playbackPaused() {
			return this.current?.isVideo ? this.playerPaused : this.held
		},

		/** @return {object|undefined} the slide the player is over */
		current() {
			return this.shorts[this.playing]
		},

		/** @return {string} the current media key */
		currentKey() {
			return this.current?.key ?? ''
		},

		/** @return {Record<string, string>} the largest frame that fits the page */
		trackStyle() {
			const width = this.availableWidth || 440
			const height = this.availableHeight || 780
			const ratio = this.videoAspectRatio || 9 / 16
			const frameWidth = Math.min(width, height * ratio)
			const frameHeight = frameWidth / ratio

			return {
				'--short-track-width': `${frameWidth}px`,
				'--short-track-height': `${frameHeight}px`,
			}
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

		start(now) {
			this.startAt = now
			this.jumpToStart()
		},

		// the slides arrive after the stack opens; the one to start at with them
		shorts() {
			this.jumpToStart()
		},

		loading(now) {
			if (!now) {
				this.jumpToStart()
			}
		},

		currentKey() {
			this.videoFailed = false
			this.playerPaused = true
			const ratio = this.mediaAspectRatio(this.current?.video)
			this.videoAspectRatio = ratio || 9 / 16
			this.$nextTick(this.alignCurrentSlide)
		},
	},

	mounted() {
		this.open()
		this.loadDay()
		const serverData = this.settingsStore.getServerData
		if (!serverData?.public && serverData?.interests?.recommendationsEnabled === true) {
			const signals = this.shortSignals
			fetchRecommendationPreferences().then((preferences) => {
				if (this.shortSignals !== signals) {
					return
				}
				const mayLearn = isRecommendationLearningActive(preferences)
					&& Number.isInteger(preferences.generation)
				signals.setLearningGeneration(mayLearn ? preferences.generation : null)
			}).catch((error) => logger.debug('Could not read For you learning settings for Shorts', { error }))
		}

		// `threshold: 0.6` rather than a bare intersection: two slides touch
		// the viewport for most of a scroll, and whichever was observed last
		// would win. A slide is the one being watched when most of it is there.
		this.observer = new IntersectionObserver(this.onVisible, { threshold: 0.6 })
		this.measureTrackBounds()
		if (typeof ResizeObserver === 'function') {
			this.layoutObserver = new ResizeObserver(this.measureTrackBounds)
			if (this.$refs.page) {
				this.layoutObserver.observe(this.$refs.page)
			}
			if (this.$refs.header) {
				this.layoutObserver.observe(this.$refs.header)
			}
			if (this.$refs.controls) {
				this.layoutObserver.observe(this.$refs.controls)
			}
		} else {
			this.resizeHandler = this.measureTrackBounds
			window.addEventListener('resize', this.resizeHandler)
		}
		const track = /** @type {HTMLElement|undefined} */ (this.$refs.track)
		track?.focus?.()
	},

	beforeUnmount() {
		this.shortSignals.leave()
		window.clearTimeout(this.tapTimer)
		this.observer?.disconnect()
		this.layoutObserver?.disconnect()
		if (this.resizeHandler) {
			window.removeEventListener('resize', this.resizeHandler)
		}
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

		/** @param {object} entry a kept video post @return {boolean} */
		canOfferRecommendationFeedback(entry) {
			const status = entry?.status

			return this.watching === 'interests'
				&& !entry?.day
				&& !this.settingsStore.getServerData?.public
				&& this.settingsStore.getServerData?.interests?.recommendationsEnabled === true
				&& !!status?.nid
				&& !this.isOwn(status)
		},

		/** @param {object} status a personalized video status @return {object|null} */
		recommendationContext(status) {
			return this.recommendationContexts[String(status?.nid)] ?? null
		},

		/** @param {object} status a personalized video status @return {boolean} */
		recommendationActionLoading(status) {
			return this.recommendationContextLoading[String(status?.nid)] === true
		},

		/** @param {object} status a personalized video status @return {string} */
		recommendationFormatLabel(status) {
			const format = this.recommendationContext(status)?.format
			return {
				audio: t('social', 'audio posts'),
				image: t('social', 'photo posts'),
				poll: t('social', 'polls'),
				short: t('social', 'short videos'),
				text: t('social', 'text posts'),
				video: t('social', 'video posts'),
			}[format] || t('social', 'posts like this')
		},

		/**
		 * @param {object} status a personalized video status
		 * @param {boolean} open whether its menu opened
		 */
		onRecommendationMenuToggle(status, open) {
			if (open) {
				this.loadRecommendationContext(status)
			}
		},

		/** @param {object} status a personalized video status */
		async loadRecommendationContext(status) {
			const id = String(status?.nid ?? '')
			if (id === '' || this.recommendationContexts[id] || this.recommendationContextLoading[id]) {
				return
			}
			this.recommendationContextLoading = { ...this.recommendationContextLoading, [id]: true }
			try {
				const context = await fetchRecommendationContext(id)
				this.recommendationContexts = { ...this.recommendationContexts, [id]: context }
			} catch (error) {
				logger.warn('Could not load Shorts recommendation feedback context', { error })
			} finally {
				const loading = { ...this.recommendationContextLoading }
				delete loading[id]
				this.recommendationContextLoading = loading
			}
		},

		/**
		 * @param {object} status the post to rate
		 * @param {string} kind the feedback operation
		 */
		async applyRecommendationFeedback(status, kind) {
			if (!this.canOfferRecommendationFeedback({ status })) {
				return
			}
			try {
				const result = await recordRecommendationFeedback(status.nid, kind)
				const store = this.timelineStore
				const identity = store.getTimelineIdentity
				const index = store.timeline.indexOf(status.id)
				if (kind === 'HIDE_POST' && index !== -1) {
					store.removeStatus(status, false)
					store.forgetRemoval(status)
				}
				showUndo(kind === 'HIDE_POST'
					? t('social', 'Post hidden from For You')
					: t('social', 'Your feedback was saved for future recommendations'), async () => {
					try {
						await undoRecommendationFeedback(result.feedback_id)
						if (kind === 'HIDE_POST' && index !== -1 && store.getTimelineIdentity === identity) {
							store.restoreStatus(status, index)
						}
					} catch (error) {
						logger.error('Could not undo Shorts recommendation feedback', { error })
						showError(t('social', 'Could not undo this recommendation feedback'))
					}
				})
			} catch (error) {
				logger.error('Could not save Shorts recommendation feedback', { error })
				showError(t('social', 'Could not save your recommendation feedback'))
			}
		},

		/** Measures the space the video frame can use after its header. */
		measureTrackBounds() {
			const page = /** @type {HTMLElement|undefined} */ (this.$refs.page)
			const header = /** @type {HTMLElement|undefined} */ (this.$refs.header)
			const controls = /** @type {HTMLElement|undefined} */ (this.$refs.controls)
			if (!page) {
				return
			}
			this.availableWidth = page.clientWidth
			this.availableHeight = Math.max(0, page.clientHeight - (header?.offsetHeight ?? 0) - (controls?.offsetHeight ?? 0) - 24)
		},

		/** @param {object|undefined} media the media object from the timeline */
		mediaAspectRatio(media) {
			const dimensions = media?.original ?? media?.small ?? media
			const width = Number(dimensions?.width)
			const height = Number(dimensions?.height)
			const ratio = width > 0 && height > 0 ? width / height : 0

			return ratio >= 0.2 && ratio <= 5 ? ratio : 0
		},

		/** Keeps the visible slide aligned after its frame changes size. */
		alignCurrentSlide() {
			const track = /** @type {HTMLElement|undefined} */ (this.$refs.track)
			if (track) {
				track.scrollTop = this.playing * track.clientHeight
			}
		},

		/** @param {Event} event metadata event from the current video */
		onVideoMetadata(event) {
			const video = /** @type {HTMLVideoElement} */ (event.target)
			if (!this.isCurrentPlayerSource(video)) {
				return
			}
			this.playerSlideKey = this.currentKey
			if (video.videoWidth > 0 && video.videoHeight > 0) {
				this.videoAspectRatio = video.videoWidth / video.videoHeight
				this.$nextTick(this.alignCurrentSlide)
			}
		},

		/** @param {HTMLVideoElement} video the player @return {boolean} whether its loaded source belongs to the current slide */
		isCurrentPlayerSource(video) {
			const source = this.current?.video?.url
			if (!this.current?.isVideo || typeof source !== 'string' || source === '') {
				return false
			}

			let expected
			try {
				expected = new URL(source, document.baseURI).href
			} catch {
				return false
			}

			return video.currentSrc ? video.currentSrc === expected : video.src === expected
		},

		/** @param {Event} event a player time update */
		onVideoProgress(event) {
			const video = /** @type {HTMLVideoElement} */ (event.target)
			if (this.playerSlideKey !== this.currentKey || !this.isCurrentPlayerSource(video)) {
				return
			}
			this.shortSignals.progress(this.current?.status, video, this.playerSlideKey)
		},

		/**
		 * @param {Event} event a story image after it has loaded
		 * @param {number} index the slide the image belongs to
		 */
		onPosterMetadata(event, index) {
			const image = /** @type {HTMLImageElement} */ (event.target)
			if (index === this.playing && image.naturalWidth > 0 && image.naturalHeight > 0) {
				this.videoAspectRatio = image.naturalWidth / image.naturalHeight
				this.$nextTick(this.alignCurrentSlide)
			}
		},

		/** Points the store at the shorts of this scope and fetches the first page. */
		open() {
			this.shortSignals.leave()
			this.timelineStore.changeTimelineType({
				type: 'shorts',
				params: { scope: this.watching },
			})
			// whatever is still in flight belongs to the scope being left
			this.generation += 1
			this.loading = false
			this.failed = false
			this.allLoaded = false
			this.playing = 0
			this.startAt = this.start
			this.load()
		},

		/**
		 * Brings the slide `start` asked for on screen, at once rather than
		 * scrolled to through every slide above it, once it has arrived.
		 * Given up when the first page has come without it.
		 */
		async jumpToStart() {
			if (this.startAt === '' || !this.dayReady) {
				return
			}

			const index = this.shorts.findIndex((entry) => !entry.day && String(entry.status?.id) === this.startAt)
			if (index === -1) {
				if (!this.loading) {
					this.startAt = ''
				}

				return
			}

			this.startAt = ''
			this.playing = index
			await this.$nextTick()
			this.slides[index]?.scrollIntoView?.({ behavior: 'auto' })
			this.play(index)
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
		 * Why For you showed a slide, as the chip over a post says it — except
		 * that popularity is only claimed of a clip that has some: For you
		 * fills a short ranking with what is trending in the same kind, and
		 * on a small server that is a clip nobody has touched yet.
		 *
		 * @param {object} status a slide's post
		 * @return {{text: string, label: string}|null} why For you showed it
		 */
		reasonOf(status) {
			if (this.watching !== 'interests') {
				return null
			}

			const reason = interestReason(status?.interest)
			if (reason === null) {
				return null
			}
			if (['popular', 'trending'].includes(status.interest?.reason) && this.interactionsOf(status) < POPULARITY_FLOOR) {
				return null
			}

			return reason
		},

		/**
		 * @param {object} status a post
		 * @return {number} its likes, boosts and views together; a count the
		 *         server does not send (views on somebody else's post) is 0
		 */
		interactionsOf(status) {
			return (Number(status.favourites_count) || 0) + (Number(status.reblogs_count) || 0) + (Number(status.view_count) || 0)
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
			const slide = this.shorts[index]
			this.shortSignals.enter(slide?.status, slide?.key)
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
				this.playerSlideKey = ''
				this.player()?.pause?.()

				return
			}

			const player = this.player()
			if (player && this.isCurrentPlayerSource(player)) {
				this.playerSlideKey = slide.key
			}
			await this.playWithSound(player)
		},

		/** A kept short loops; a 24-hour one moves on. */
		/** @param {Event} [event] the video that ended */
		onEnded(event) {
			const video = /** @type {HTMLVideoElement} */ (event?.target ?? this.player())
			if (this.playerSlideKey !== this.currentKey || !this.isCurrentPlayerSource(video)) {
				return
			}
			if (this.current?.day) {
				this.advance()

				return
			}
			this.shortSignals.ended(this.current?.status, this.current?.key)
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
			this.held = true
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

		/** @param {Event} event the current player's network/decoding failure */
		onPlaybackError(event) {
			const video = /** @type {HTMLVideoElement} */ (event.target)
			if (this.isCurrentPlayerSource(video)) {
				this.videoFailed = true
				this.playerPaused = true
			}
		},

		async retryPlayback() {
			const video = this.player()
			if (!video || !this.current?.isVideo) {
				return
			}
			this.videoFailed = false
			video.load()
			await this.playWithSound(video)
		},

		/** Loads the next page when the last available short is reached. */
		async nextShort() {
			const currentKey = this.currentKey
			const generation = this.generation
			if (this.playing >= this.shorts.length - 1 && !this.allLoaded) {
				await this.load()
				await this.$nextTick()
			}
			if (this.currentKey === currentKey && this.generation === generation) {
				this.scrollToSlide(this.playing + 1)
			}
		},

		/**
		 * Up and down, and space for pause — a stack is reachable without a
		 * finger or it is reachable by nobody using a keyboard.
		 *
		 * @param {KeyboardEvent} event the key
		 */
		onKey(event) {
			// somebody writing a reply is not paging
			if (event.defaultPrevented || event.ctrlKey || event.altKey || event.metaKey
				|| /** @type {Element|null} */ (event.target)?.closest?.('button, a, input, textarea, select, [contenteditable], [role=button], [role=menuitem]')) {
				return
			}

			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault()
				if (event.key === 'ArrowDown') {
					this.nextShort()
				} else {
					this.scrollToSlide(this.playing - 1)
				}
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
			const generation = this.generation
			const params = {}
			const ids = this.timelineStore.getTimeline.map((status) => status.id)
			// a ranking pages from where it ended, not from its oldest post
			const cursor = isRanked(this.timelineStore) ? ids[ids.length - 1] : oldestId(ids)
			if (cursor !== undefined) {
				params.max_id = cursor
			}

			try {
				const page = await this.timelineStore.fetchTimeline(params)
				if (generation !== this.generation) {
					return
				}
				this.allLoaded = Array.isArray(page) ? page.length === 0 : true
			} catch (error) {
				if (generation !== this.generation) {
					return
				}
				logger.error('Could not load more videos', { error })
				this.failed = true
			} finally {
				// the newer request owns `loading` now
				if (generation === this.generation) {
					this.loading = false
				}
			}
		},
	},
}
</script>

<style scoped lang="scss" src="./VideoShorts.scss"></style>
