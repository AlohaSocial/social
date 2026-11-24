<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		v-if="playable"
		class="video-player"
		:class="{
			'video-player--mini': mini,
			'video-player--fullscreen': fullscreen,
			'video-player--idle': !controlsShown,
		}"
		role="region"
		:aria-label="t('social', 'Video player')"
		tabindex="0"
		@pointermove="showControls"
		@focusin="showControls">
		<!-- `playsinline`, or iOS takes the video full screen the moment it
		     starts and the reader loses the page around it -->
		<video
			ref="video"
			class="video-player__video"
			:src="plainSource"
			:poster="poster"
			:aria-label="attachment.description || title"
			preload="metadata"
			playsinline
			@click="togglePlay"
			@play="onPlay"
			@pause="onPause"
			@ended="onEnded"
			@timeupdate="onTimeUpdate"
			@loadedmetadata="onLoadedMetadata"
			@durationchange="duration = readDuration()"
			@volumechange="onVolumeChange"
			@ratechange="onRateChange"
			@enterpictureinpicture="pictureInPicture = true"
			@leavepictureinpicture="pictureInPicture = false">
			<track
				v-for="caption in captions"
				:key="caption.url"
				ref="tracks"
				kind="subtitles"
				:src="caption.url"
				:srclang="caption.language || undefined"
				:label="languageName(caption.language)">
		</video>

		<!-- a press anywhere on the picture plays; the buttons below are the
		     way in for a keyboard and a screen reader -->
		<div
			v-if="!started && !mini"
			class="video-player__start"
			aria-hidden="true"
			@click="togglePlay">
			<Play :size="48" />
		</div>

		<div class="video-player__controls">
			<input
				v-if="!live"
				class="video-player__seek"
				type="range"
				min="0"
				:max="duration || 0"
				step="any"
				:value="currentTime"
				:aria-label="t('social', 'Position in the video')"
				:aria-valuetext="t('social', '{position} of {duration}', { position: clock(currentTime), duration: clock(duration) })"
				:style="{ '--played': playedShare }"
				@input="onSeekInput">

			<p v-if="saveFailed && !mini" class="video-player__save-failed" role="status">
				{{ t('social', 'Your place could not be saved. It will be tried again.') }}
			</p>

			<div class="video-player__bar">
				<button
					type="button"
					class="video-player__button"
					:aria-label="playing ? t('social', 'Pause (k)') : t('social', 'Play (k)')"
					:title="playing ? t('social', 'Pause (k)') : t('social', 'Play (k)')"
					@click="togglePlay">
					<Pause v-if="playing" :size="24" />
					<Play v-else :size="24" />
				</button>

				<button
					type="button"
					class="video-player__button"
					:aria-label="muted ? t('social', 'Sound on (m)') : t('social', 'Sound off (m)')"
					:title="muted ? t('social', 'Sound on (m)') : t('social', 'Sound off (m)')"
					:aria-pressed="muted ? 'true' : 'false'"
					@click="toggleMute">
					<VolumeOff v-if="muted" :size="24" />
					<VolumeHigh v-else :size="24" />
				</button>
				<input
					v-if="!mini"
					class="video-player__volume"
					type="range"
					min="0"
					max="1"
					step="0.05"
					:value="muted ? 0 : volume"
					:aria-label="t('social', 'Volume')"
					:aria-valuetext="percent(muted ? 0 : volume)"
					@input="onVolumeInput">

				<span v-if="live" class="video-player__live">{{ t('social', 'Live') }}</span>
				<span v-else class="video-player__time">
					{{ clock(currentTime) }} / {{ clock(duration) }}
				</span>

				<span class="video-player__spacer" />

				<template v-if="!mini">
					<div v-if="captionChoices.length" class="video-player__menu-anchor">
						<button
							ref="captionsButton"
							type="button"
							class="video-player__button"
							:aria-label="t('social', 'Subtitles (c)')"
							:title="t('social', 'Subtitles (c)')"
							:aria-pressed="captionChoices.length === 1 ? (activeCaption !== '' ? 'true' : 'false') : undefined"
							:aria-haspopup="captionChoices.length > 1 ? 'menu' : undefined"
							:aria-expanded="captionChoices.length > 1 ? (menu === 'captions' ? 'true' : 'false') : undefined"
							@click="captionChoices.length > 1 ? toggleMenu('captions') : toggleCaptions()">
							<ClosedCaption v-if="activeCaption !== ''" :size="24" />
							<ClosedCaptionOutline v-else :size="24" />
						</button>
						<ul
							v-if="menu === 'captions'"
							ref="menu"
							class="video-player__menu"
							role="menu"
							:aria-label="t('social', 'Subtitles')"
							@keydown="onMenuKeydown">
							<li role="none">
								<button
									type="button"
									role="menuitemradio"
									:aria-checked="activeCaption === '' ? 'true' : 'false'"
									@click="chooseCaption('')">
									{{ t('social', 'Off') }}
								</button>
							</li>
							<li v-for="choice in captionChoices" :key="choice.key" role="none">
								<button
									type="button"
									role="menuitemradio"
									:aria-checked="activeCaption === choice.key ? 'true' : 'false'"
									@click="chooseCaption(choice.key)">
									{{ choice.label }}
								</button>
							</li>
						</ul>
					</div>

					<div class="video-player__menu-anchor">
						<button
							ref="speedButton"
							type="button"
							class="video-player__button video-player__button--text"
							:aria-label="t('social', 'Playback speed: {speed}', { speed: speedLabel(rate) })"
							:title="t('social', 'Playback speed (< and >)')"
							aria-haspopup="menu"
							:aria-expanded="menu === 'speed' ? 'true' : 'false'"
							@click="toggleMenu('speed')">
							{{ speedLabel(rate) }}
						</button>
						<ul
							v-if="menu === 'speed'"
							ref="menu"
							class="video-player__menu"
							role="menu"
							:aria-label="t('social', 'Playback speed')"
							@keydown="onMenuKeydown">
							<li v-for="speed in speeds" :key="speed" role="none">
								<button
									type="button"
									role="menuitemradio"
									:aria-checked="speed === rate ? 'true' : 'false'"
									@click="chooseSpeed(speed)">
									{{ speed === 1 ? t('social', 'Normal') : speedLabel(speed) }}
								</button>
							</li>
						</ul>
					</div>

					<div v-if="qualities.length > 1" class="video-player__menu-anchor">
						<button
							ref="qualityButton"
							type="button"
							class="video-player__button"
							:aria-label="t('social', 'Quality: {quality}', { quality: qualityLabel })"
							:title="t('social', 'Quality')"
							aria-haspopup="menu"
							:aria-expanded="menu === 'quality' ? 'true' : 'false'"
							@click="toggleMenu('quality')">
							<Cog :size="24" />
						</button>
						<ul
							v-if="menu === 'quality'"
							ref="menu"
							class="video-player__menu"
							role="menu"
							:aria-label="t('social', 'Quality')"
							@keydown="onMenuKeydown">
							<li role="none">
								<button
									type="button"
									role="menuitemradio"
									:aria-checked="level === -1 ? 'true' : 'false'"
									@click="chooseQuality(-1)">
									{{ autoLabel }}
								</button>
							</li>
							<li v-for="quality in qualities" :key="quality.index" role="none">
								<button
									type="button"
									role="menuitemradio"
									:aria-checked="level === quality.index ? 'true' : 'false'"
									@click="chooseQuality(quality.index)">
									{{ quality.label }}
								</button>
							</li>
						</ul>
					</div>
				</template>

				<button
					v-if="pictureInPictureSupported"
					type="button"
					class="video-player__button"
					:aria-label="pictureInPicture ? t('social', 'Back from the floating window') : t('social', 'Play in a floating window')"
					:title="pictureInPicture ? t('social', 'Back from the floating window') : t('social', 'Play in a floating window')"
					:aria-pressed="pictureInPicture ? 'true' : 'false'"
					@click="togglePictureInPicture">
					<PictureInPictureBottomRight :size="24" />
				</button>

				<button
					v-if="!mini"
					type="button"
					class="video-player__button"
					:aria-label="fullscreen ? t('social', 'Leave full screen (f)') : t('social', 'Full screen (f)')"
					:title="fullscreen ? t('social', 'Leave full screen (f)') : t('social', 'Full screen (f)')"
					@click="toggleFullscreen">
					<FullscreenExit v-if="fullscreen" :size="24" />
					<Fullscreen v-else :size="24" />
				</button>
			</div>
		</div>

		<!-- what a key or a menu changed, said once to a screen reader -->
		<p class="hidden-visually" aria-live="polite">
			{{ announcement }}
		</p>
	</div>
	<!-- nothing this server can play: the attachment says why, or the post is
	     a link to where the video was published -->
	<div v-else class="video-player video-player--unplayable">
		<MediaAttachment v-if="attachment" :attachment="attachment" />
		<p v-else class="video-player__elsewhere">
			{{ t('social', 'This video cannot be played here.') }}
			<a
				v-if="post.url"
				:href="post.url"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('social', 'Watch it where it was published') }}
			</a>
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { getCurrentUser } from '@nextcloud/auth'
import { getLanguage, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { markRaw } from 'vue'
import ClosedCaption from 'vue-material-design-icons/ClosedCaption.vue'
import ClosedCaptionOutline from 'vue-material-design-icons/ClosedCaptionOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Fullscreen from 'vue-material-design-icons/Fullscreen.vue'
import FullscreenExit from 'vue-material-design-icons/FullscreenExit.vue'
import Pause from 'vue-material-design-icons/Pause.vue'
import PictureInPictureBottomRight from 'vue-material-design-icons/PictureInPictureBottomRight.vue'
import Play from 'vue-material-design-icons/Play.vue'
import VolumeHigh from 'vue-material-design-icons/VolumeHigh.vue'
import VolumeOff from 'vue-material-design-icons/VolumeOff.vue'
import MediaAttachment from './MediaAttachment.vue'
import logger from '../services/logger.js'
import { closeMiniPlayer, handOff, miniPlayer, movedTo, takeBack } from '../services/miniPlayer.js'
import { belongsToElement, isDialogOpen, offInNextcloud } from '../services/shortcuts.js'
import { FINISHED, progressOf, rememberProgress } from '../services/videoProgress.js'
import { attachHls, isPlaylist, playedAttachmentOf, posterOf } from '../services/videoSource.js'
import { useSettingsStore } from '../store/settings.js'
import { formatTime } from '../utils/shortVideo.js'
import { videoPostOf } from '../utils/videoCard.js'

/**
 * Safari's prefixed full screen, which no type declares.
 *
 * @typedef {Document & {webkitFullscreenElement?: Element|null, webkitExitFullscreen?: () => void}} PrefixedDocument
 * @typedef {HTMLVideoElement & {webkitEnterFullscreen?: () => void}} PrefixedVideo
 */

/** The speeds offered, as on every video site. */
export const SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2]

/** Where the chosen speed is kept, for this browser rather than this account. */
export const SPEED_KEY = 'social.video.speed'

/** How often, at most, the place in a playing video is sent to the server, in milliseconds. */
export const REPORT_EVERY = 10000

/** How many seconds have to be played before the view is counted (`VideoViewService::MIN_SECONDS`). */
export const VIEW_AFTER = 5

/** Closer to the end than this, in seconds, a video starts again from the beginning. */
const RESUME_MARGIN = 10

/** How long the controls stay up after the pointer last moved, in milliseconds. */
const CONTROLS_FOR = 3000

/** Keys a focused range input moves itself with. */
const SLIDER_KEYS = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown']

/** @return {number} the speed this browser chose last, 1 when it chose none */
function rememberedSpeed() {
	try {
		const speed = Number(window.localStorage.getItem(SPEED_KEY))

		return SPEEDS.includes(speed) ? speed : 1
	} catch {
		return 1
	}
}

/**
 * Whether a key press is the reader typing: in a field, a contenteditable
 * composer, or with a modifier held. The player's own sliders are not typing.
 *
 * @param {KeyboardEvent} event the press
 * @param {HTMLElement} player the player's element
 * @return {boolean}
 */
function isTyping(event, player) {
	if (event.ctrlKey || event.metaKey || event.altKey) {
		return true
	}

	const target = /** @type {HTMLElement|null} */ (event.target)
	if (!target || typeof target.tagName !== 'string') {
		return false
	}

	const tag = target.tagName.toLowerCase()
	if (tag === 'input' && /** @type {HTMLInputElement} */ (target).type === 'range' && player.contains(target)) {
		return false
	}

	return ['input', 'textarea', 'select'].includes(tag) || target.isContentEditable === true
}

/**
 * The player of a video's watch page, and (with `mini`) of the small player
 * that keeps playing it after the reader leaves that page. It resumes where
 * the reader stopped, reports progress (`/watched`) and views (`/view`), and
 * keeps its menus inside the player so they work in full screen.
 */
export default {
	name: 'VideoPlayer',

	components: {
		ClosedCaption,
		ClosedCaptionOutline,
		Cog,
		Fullscreen,
		FullscreenExit,
		MediaAttachment,
		Pause,
		PictureInPictureBottomRight,
		Play,
		VolumeHigh,
		VolumeOff,
	},

	props: {
		/** the video post */
		status: {
			type: /** @type {import('vue').PropType<object>} */ (Object),
			required: true,
		},

		/** whether this is the small player */
		mini: {
			type: Boolean,
			default: false,
		},

		/** where to start, in seconds, over where the reader stopped */
		startAt: {
			type: Number,
			default: null,
		},

		/** whether to go on playing once loaded: a video handed over while it played */
		autoplay: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			playing: false,
			/** whether it has been played at all, which takes the big button away */
			started: false,
			currentTime: 0,
			duration: 0,
			volume: 1,
			muted: false,
			rate: rememberedSpeed(),
			/** which menu is open: `captions`, `speed`, `quality` or null */
			menu: null,
			/** the hls.js player, where there is one */
			hls: null,
			/** the sizes an HLS source offers, by hls.js level */
			levels: [],
			/** the chosen level; -1 is Auto */
			level: -1,
			/** the level Auto is playing */
			playingLevel: -1,
			/** the subtitle tracks an HLS playlist carries */
			hlsSubtitles: [],
			/** the subtitles shown, as a choice's key; '' is none */
			activeCaption: '',
			/** the choice `c` turns back on */
			lastCaption: '',
			fullscreen: false,
			pictureInPicture: false,
			controlsShown: true,
			/** what a screen reader is told changed */
			announcement: '',
			/** where to start once the video has loaded, decided once */
			resumeAt: null,
			resumePlaying: this.autoplay,
			/** the last place sent, in whole seconds, and when */
			lastReported: -1,
			lastReportAt: 0,
			/** whether the last place sent did not reach the server */
			saveFailed: false,
			/** seconds actually played, which is what a view is */
			played: 0,
			lastTime: 0,
			viewSent: false,
			/** the post this player is playing, which a new one still has to leave as it was */
			current: null,
			/** the timer that hides the controls */
			idleTimer: 0,
		}
	},

	computed: {
		/** @return {object} the post the video is on, not the boost of it */
		post() {
			return videoPostOf(this.status) ?? {}
		},

		/** @return {object|null} the attachment that is the video */
		attachment() {
			return playedAttachmentOf(this.post)
		},

		/** @return {boolean} whether there are bytes here to play */
		playable() {
			return typeof this.attachment?.url === 'string' && this.attachment.url !== ''
		},

		/**
		 * The `src` the element carries: none for a remote playlist, which
		 * hls.js hands the element, and the file for everything else.
		 *
		 * @return {string|undefined}
		 */
		plainSource() {
			return isPlaylist(this.attachment) ? undefined : this.attachment?.url
		},

		/** @return {string|undefined} */
		poster() {
			return posterOf(this.attachment)
		},

		/** @return {object} what the post says about the video */
		video() {
			return this.post.video ?? {}
		},

		/** @return {string} */
		title() {
			return this.post.video_card?.title || this.video.title || ''
		},

		/** @return {boolean} a broadcast, which has no end to seek in or to come back to */
		live() {
			return this.video.live === true || this.post.video_card?.live === true
		},

		/**
		 * The subtitle tracks the video published, each at this server's
		 * address (`video.captions`).
		 *
		 * @return {Array<{language: string, url: string}>}
		 */
		captions() {
			const captions = Array.isArray(this.video.captions) ? this.video.captions : []

			return captions.filter((caption) => typeof caption?.url === 'string' && caption.url !== '')
		},

		/** @return {Array<{key: string, label: string}>} every subtitle track there is to choose */
		captionChoices() {
			return [
				...this.captions.map((caption, index) => ({ key: `track-${index}`, label: this.languageName(caption.language) })),
				...this.hlsSubtitles.map((track) => ({ key: `hls-${track.index}`, label: track.label })),
			]
		},

		/** @return {number[]} */
		speeds() {
			return SPEEDS
		},

		/**
		 * The sizes to choose from, largest first, each once.
		 *
		 * @return {Array<{index: number, label: string}>}
		 */
		qualities() {
			const seen = new Set()

			return [...this.levels]
				.sort((a, b) => b.height - a.height || b.bitrate - a.bitrate)
				.filter((level) => level.height > 0 && !seen.has(level.height) && seen.add(level.height))
				.map((level) => ({ index: level.index, label: `${level.height}p` }))
		},

		/** @return {string} Auto, and what it is playing */
		autoLabel() {
			const playing = this.levels.find((level) => level.index === this.playingLevel)

			return playing?.height
				? t('social', 'Auto ({quality})', { quality: `${playing.height}p` })
				: t('social', 'Auto')
		},

		/** @return {string} */
		qualityLabel() {
			return this.level === -1
				? this.autoLabel
				: (this.qualities.find((quality) => quality.index === this.level)?.label ?? '')
		},

		/** @return {string} the share played, for the seek bar's fill */
		playedShare() {
			return this.duration > 0 ? String(Math.min(1, this.currentTime / this.duration)) : '0'
		},

		/** @return {boolean} */
		pictureInPictureSupported() {
			return typeof document !== 'undefined'
				&& document.pictureInPictureEnabled === true
				&& typeof HTMLVideoElement !== 'undefined'
				&& typeof HTMLVideoElement.prototype.requestPictureInPicture === 'function'
		},

		/**
		 * Whether where the reader is goes to the server: only for somebody
		 * signed in, and only for a video with an end.
		 *
		 * @return {boolean}
		 */
		reportsProgress() {
			return getCurrentUser() !== null && !this.live && Number.isFinite(this.duration) && this.duration > 0
		},
	},

	watch: {
		/**
		 * Another video on the same page: the one that was playing is left as
		 * if the page had been, and the new one starts as a page does.
		 */
		'post.id': function() {
			this.leave()
			this.detachSource()
			clearTimeout(this.idleTimer)
			Object.assign(this.$data, this.$options.data.call(this))
			this.decideStart()
			this.$nextTick(() => this.attachSource())
		},
	},

	created() {
		this.decideStart()
	},

	mounted() {
		window.addEventListener('keydown', this.onKeydown, true)
		window.addEventListener('pagehide', this.onPageHide)
		document.addEventListener('fullscreenchange', this.onFullscreenChange)
		document.addEventListener('webkitfullscreenchange', this.onFullscreenChange)
		document.addEventListener('pointerdown', this.onPointerDown, true)
		this.attachSource()
	},

	beforeUnmount() {
		window.removeEventListener('keydown', this.onKeydown, true)
		window.removeEventListener('pagehide', this.onPageHide)
		document.removeEventListener('fullscreenchange', this.onFullscreenChange)
		document.removeEventListener('webkitfullscreenchange', this.onFullscreenChange)
		document.removeEventListener('pointerdown', this.onPointerDown, true)
		clearTimeout(this.idleTimer)
		this.leave()
		this.detachSource()
	},

	methods: {
		t,

		/** @return {HTMLVideoElement|undefined} the element that plays */
		media() {
			return /** @type {HTMLVideoElement|undefined} */ (this.$refs.video)
		},

		/**
		 * Where to start: where the small player was, for a video taken back
		 * from it; else what the caller said; else where the reader stopped.
		 */
		decideStart() {
			this.current = this.post
			const taken = this.mini ? null : takeBack(this.post.id)
			if (taken !== null) {
				this.resumeAt = taken.position
				this.resumePlaying = taken.playing
				return
			}

			this.resumeAt = this.startAt ?? progressOf(this.post)?.position ?? null
		},

		/** Points the element at the source, through hls.js where it has to be. */
		async attachSource() {
			this.detachSource()
			const element = this.media()
			if (!element) {
				return
			}

			element.defaultPlaybackRate = this.rate
			element.playbackRate = this.rate

			const hls = await attachHls(element, this.attachment, {
				isCurrent: () => this.media() === element,
				onFallback: () => {
					this.hls = null
					this.levels = []
				},
				onCreated: (player, Hls) => this.listenToHls(player, Hls),
			})
			this.hls = hls === null ? null : markRaw(hls)
		},

		/** Lets go of hls.js, which otherwise goes on fetching segments. */
		detachSource() {
			if (this.hls) {
				this.hls.destroy()
				this.hls = null
			}
		},

		/**
		 * What hls.js says about the sizes and subtitle tracks the source has.
		 *
		 * @param {object} hls the player
		 * @param {object} Hls the library, for its event names
		 */
		listenToHls(hls, Hls) {
			hls.on(Hls.Events.MANIFEST_PARSED, () => {
				this.levels = (hls.levels ?? []).map((level, index) => ({
					index,
					height: Number(level?.height ?? 0),
					bitrate: Number(level?.bitrate ?? 0),
				}))
			})
			hls.on(Hls.Events.LEVEL_SWITCHED, (event, data) => {
				this.playingLevel = data?.level ?? -1
			})
			hls.on(Hls.Events.SUBTITLE_TRACKS_UPDATED, (event, data) => {
				this.hlsSubtitles = (data?.subtitleTracks ?? []).map((track, index) => ({
					index,
					label: track?.name || this.languageName(track?.lang ?? ''),
				}))
			})
		},

		/**
		 * The video is going: from its page, which hands a playing video to
		 * the small player, or from the small player, which stops it. Where
		 * the reader got to is sent either way.
		 */
		leave() {
			const element = this.media()
			if (!element) {
				return
			}

			const position = element.currentTime
			if (!this.mini && !element.paused && !element.ended) {
				handOff(this.current, position)
			}

			if (!element.ended) {
				this.report(position, { force: true })
			}
		},

		togglePlay() {
			const element = this.media()
			if (!element) {
				return
			}

			if (element.paused || element.ended) {
				const playing = element.play()
				if (playing && typeof playing.catch === 'function') {
					playing.catch((error) => logger.debug('the browser would not play', { error }))
				}
			} else {
				element.pause()
			}
		},

		/** @param {number} seconds where to go */
		seekTo(seconds) {
			const element = this.media()
			if (!element || this.live) {
				return
			}

			const end = Number.isFinite(this.duration) && this.duration > 0 ? this.duration : Infinity
			element.currentTime = Math.max(0, Math.min(end, seconds))
			this.currentTime = element.currentTime
		},

		/** @param {number} seconds how far, back when negative */
		seekBy(seconds) {
			this.seekTo((this.media()?.currentTime ?? 0) + seconds)
		},

		/** @param {number} level the volume, 0 to 1 */
		setVolume(level) {
			const element = this.media()
			if (!element) {
				return
			}

			element.volume = Math.max(0, Math.min(1, Math.round(level * 100) / 100))
			element.muted = element.volume === 0
		},

		/** @param {number} step how much louder, quieter when negative */
		volumeBy(step) {
			const element = this.media()
			if (!element) {
				return
			}

			this.setVolume((element.muted ? 0 : element.volume) + step)
			this.say(t('social', 'Volume {volume}', { volume: this.percent(element.muted ? 0 : element.volume) }))
		},

		toggleMute() {
			const element = this.media()
			if (!element) {
				return
			}

			element.muted = !element.muted
			if (!element.muted && element.volume === 0) {
				element.volume = 1
			}
			this.say(element.muted ? t('social', 'Sound off') : t('social', 'Sound on'))
		},

		/**
		 * @param {number} speed how fast
		 */
		chooseSpeed(speed) {
			const element = this.media()
			this.rate = speed
			if (element) {
				element.defaultPlaybackRate = speed
				element.playbackRate = speed
			}
			try {
				window.localStorage.setItem(SPEED_KEY, String(speed))
			} catch {
				// a browser that keeps nothing still plays at the chosen speed
			}
			this.closeMenu()
			this.say(t('social', 'Speed {speed}', { speed: this.speedLabel(speed) }))
		},

		/** @param {number} step one speed faster, slower when negative */
		speedBy(step) {
			const at = SPEEDS.indexOf(this.rate)
			const next = SPEEDS[Math.max(0, Math.min(SPEEDS.length - 1, (at === -1 ? SPEEDS.indexOf(1) : at) + step))]
			this.chooseSpeed(next)
		},

		/** @param {number} index the hls.js level, -1 for Auto */
		chooseQuality(index) {
			this.level = index
			if (this.hls) {
				this.hls.currentLevel = index
			}
			this.closeMenu()
			this.say(t('social', 'Quality {quality}', { quality: this.qualityLabel }))
		},

		/**
		 * Shows one subtitle track, or none.
		 *
		 * @param {string} key the choice, '' for none
		 */
		chooseCaption(key) {
			this.activeCaption = key
			if (key !== '') {
				this.lastCaption = key
			}

			const tracks = /** @type {HTMLTrackElement[]} */ (this.$refs.tracks ?? [])
			tracks.forEach((element, index) => {
				if (element?.track) {
					element.track.mode = (key === `track-${index}`) ? 'showing' : 'disabled'
				}
			})
			if (this.hls) {
				const hlsTrack = key.startsWith('hls-') ? Number(key.slice(4)) : -1
				this.hls.subtitleTrack = hlsTrack
				this.hls.subtitleDisplay = hlsTrack !== -1
			}

			this.closeMenu()
			const choice = this.captionChoices.find((item) => item.key === key)
			this.say(choice ? t('social', 'Subtitles: {language}', { language: choice.label }) : t('social', 'Subtitles off'))
		},

		/**
		 * Subtitles on or off: back on in the language last chosen, else the
		 * reader's own where the video has it, else the first.
		 */
		toggleCaptions() {
			if (this.captionChoices.length === 0) {
				return
			}
			if (this.activeCaption !== '') {
				this.chooseCaption('')
				return
			}

			const language = getLanguage().split(/[-_]/)[0]
			const own = this.captions.findIndex((caption) => String(caption.language ?? '').split(/[-_]/)[0] === language)
			const remembered = this.captionChoices.find((choice) => choice.key === this.lastCaption)
			this.chooseCaption(remembered?.key ?? (own !== -1 ? `track-${own}` : this.captionChoices[0].key))
		},

		async togglePictureInPicture() {
			const element = this.media()
			try {
				if (document.pictureInPictureElement === element) {
					await document.exitPictureInPicture()
				} else {
					await element.requestPictureInPicture()
				}
			} catch (error) {
				logger.debug('the browser would not change picture in picture', { error })
			}
		},

		async toggleFullscreen() {
			const root = this.$el
			const doc = /** @type {PrefixedDocument} */ (document)
			const element = /** @type {PrefixedVideo|undefined} */ (this.media())
			try {
				if (doc.fullscreenElement === root || doc.webkitFullscreenElement === root) {
					await (doc.exitFullscreen?.() ?? doc.webkitExitFullscreen?.())
				} else if (typeof root.requestFullscreen === 'function') {
					await root.requestFullscreen()
				} else if (typeof root.webkitRequestFullscreen === 'function') {
					root.webkitRequestFullscreen()
				} else if (typeof element?.webkitEnterFullscreen === 'function') {
					// an iPhone has full screen for a video element and nothing else
					element.webkitEnterFullscreen()
				}
			} catch (error) {
				logger.debug('the browser would not change full screen', { error })
			}
		},

		onFullscreenChange() {
			const doc = /** @type {PrefixedDocument} */ (document)
			this.fullscreen = (doc.fullscreenElement ?? doc.webkitFullscreenElement ?? null) === this.$el
		},

		/** @param {Event} event the seek bar moved */
		onSeekInput(event) {
			this.seekTo(Number(/** @type {HTMLInputElement} */ (event.target).value))
		},

		/** @param {Event} event the volume slider moved */
		onVolumeInput(event) {
			this.setVolume(Number(/** @type {HTMLInputElement} */ (event.target).value))
		},

		/** @param {Event} event the element's speed changed */
		onRateChange(event) {
			this.rate = /** @type {HTMLVideoElement} */ (event.target).playbackRate
		},

		// --- the element's own events ------------------------------------

		/** @return {number} how long it runs, 0 while that is not known */
		readDuration() {
			const duration = this.media()?.duration ?? 0

			return Number.isFinite(duration) && duration > 0 ? duration : 0
		},

		/** Starts where the reader stopped, once there is a length to measure it against. */
		onLoadedMetadata() {
			const element = this.media()
			this.duration = this.readDuration()
			element.playbackRate = this.rate

			const at = Number(this.resumeAt ?? 0)
			this.resumeAt = null
			if (at > 1 && !this.live && this.duration > 0
				&& at < this.duration - RESUME_MARGIN && at / this.duration < FINISHED) {
				this.seekTo(at)
				this.say(t('social', 'Going on from {position}', { position: this.clock(at) }))
			}

			if (this.resumePlaying) {
				this.resumePlaying = false
				this.togglePlay()
			}
		},

		onPlay() {
			this.playing = true
			this.started = true
			this.lastTime = this.media().currentTime
			this.lastReportAt = Date.now()
			// another video playing small is not something to talk over
			if (!this.mini && miniPlayer.status !== null && String(miniPlayer.status.id) !== String(this.post.id)) {
				closeMiniPlayer()
			}
			this.hideControlsSoon()
			this.tellMiniPlayer()
		},

		onPause() {
			this.playing = false
			this.controlsShown = true
			this.report(this.media().currentTime)
			this.tellMiniPlayer()
		},

		onEnded() {
			this.playing = false
			this.controlsShown = true
			// to the end is finished, which the server forgets
			this.report(this.duration, { force: true })
			this.tellMiniPlayer()
		},

		onTimeUpdate() {
			const element = this.media()
			const now = element.currentTime
			const step = now - this.lastTime
			this.lastTime = now
			this.currentTime = now
			if (this.duration === 0) {
				this.duration = this.readDuration()
			}

			// a seek is not watching, and neither is a jump of the clock
			if (!element.paused && step > 0 && step < 2) {
				this.played += step
				if (!this.viewSent && this.played >= VIEW_AFTER) {
					this.countView()
				}
			}

			if (!element.paused && Date.now() - this.lastReportAt >= REPORT_EVERY) {
				this.report(now)
			}
			this.tellMiniPlayer()
		},

		/** @param {Event} event the element saying so */
		onVolumeChange(event) {
			const element = /** @type {HTMLVideoElement} */ (event.target)
			this.volume = element.volume
			this.muted = element.muted
		},

		/** The page is going away for good: the place goes by beacon, which outlives it. */
		onPageHide() {
			const element = this.media()
			if (element && !element.ended) {
				this.report(element.currentTime, { force: true, beacon: true })
			}
		},

		/** The small player's state is where its watch page takes it back from. */
		tellMiniPlayer() {
			if (this.mini) {
				const element = this.media()
				movedTo(element.currentTime, !element.paused && !element.ended)
			}
		},

		// --- telling the server ------------------------------------------

		/**
		 * Sends where the reader is: no more often than every `REPORT_EVERY`,
		 * and never the same place twice.
		 *
		 * @param {number} position in seconds
		 * @param {object} [options] how
		 * @param {boolean} [options.force] whatever the last report was
		 * @param {boolean} [options.beacon] by beacon, for a page that is going away
		 */
		report(position, { force = false, beacon = false } = {}) {
			if (!this.reportsProgress) {
				return
			}

			const at = Math.floor(position)
			if (at === this.lastReported && !force) {
				return
			}

			this.lastReported = at
			this.lastReportAt = Date.now()
			const duration = Math.round(this.duration)

			const url = generateUrl(`apps/social/api/v1/statuses/${this.current.id}/watched`)
			if (beacon && typeof navigator.sendBeacon === 'function') {
				// the route takes no CSRF token, which is what a beacon cannot carry
				navigator.sendBeacon(url, new URLSearchParams({ position: String(at), duration: String(duration) }))
				// a beacon cannot answer: the card keeps the best effort this page has
				rememberProgress(this.current.id, at, duration)
				return
			}

			axios.post(url, { position: at, duration })
				.then((response) => {
					if (response?.data?.stored === false) {
						// the request went through but the row did not
						this.lastReported = -1
						this.saveFailed = true

						return
					}

					this.saveFailed = false
					rememberProgress(this.current.id, at, duration)
				})
				.catch((error) => {
					// the place was not stored, so let the next report try again
					this.lastReported = -1
					this.saveFailed = true
					logger.debug('could not say where the reader stopped', { error })
				})
		},

		/** A few seconds were watched: a view, as far as this player is concerned. */
		countView() {
			this.viewSent = true
			axios.post(generateUrl(`apps/social/api/v1/statuses/${this.current.id}/view`))
				.catch((error) => logger.debug('could not count a view', { error }))
		},

		// --- keys ---------------------------------------------------------

		/**
		 * The player's keys, YouTube's.
		 *
		 * Listened for on the window, before anything else, so that on a
		 * video's page `j`, `k` and `l` are the player's rather than the
		 * timeline's; a key the player takes is not passed on.
		 *
		 * @param {KeyboardEvent} event the press
		 */
		onKeydown(event) {
			const root = /** @type {HTMLElement|undefined} */ (this.$el)
			if (!this.playable || !root || typeof root.contains !== 'function') {
				return
			}

			const target = /** @type {HTMLElement|null} */ (event.target)
			const inside = target instanceof Node && root.contains(target)
			// the small player's keys are its own, and only while it has the focus
			if (!inside && (this.mini || target?.closest?.('.video-player'))) {
				return
			}

			if (!useSettingsStore().shortcutsEnabled || offInNextcloud()) {
				return
			}
			if (isTyping(event, root) || (!inside && isDialogOpen())) {
				return
			}
			// an open menu has keys of its own
			if (inside && target?.closest?.('.video-player__menu')) {
				return
			}
			if (/** @type {HTMLInputElement|null} */ (target)?.type === 'range' && SLIDER_KEYS.includes(event.key)) {
				return
			}
			if (target !== root && belongsToElement(event)) {
				return
			}

			const action = this.actionFor(event.key, inside)
			if (action === null) {
				return
			}

			event.preventDefault()
			event.stopImmediatePropagation()
			this.showControls()
			action()
		},

		/**
		 * @param {string} key the key
		 * @param {boolean} inside whether the player has the focus
		 * @return {(() => void)|null} what the key does, null when it does nothing here
		 */
		actionFor(key, inside) {
			const lower = key.length === 1 ? key.toLowerCase() : key
			if (/^[0-9]$/.test(key)) {
				return () => this.seekTo(this.duration * Number(key) / 10)
			}

			return {
				k: () => this.togglePlay(),
				' ': () => this.togglePlay(),
				j: () => this.seekBy(-10),
				l: () => this.seekBy(10),
				ArrowLeft: () => this.seekBy(-5),
				ArrowRight: () => this.seekBy(5),
				// out of the player the arrows scroll the page
				ArrowUp: inside ? () => this.volumeBy(0.05) : null,
				ArrowDown: inside ? () => this.volumeBy(-0.05) : null,
				m: () => this.toggleMute(),
				f: this.mini ? null : () => this.toggleFullscreen(),
				c: this.captionChoices.length ? () => this.toggleCaptions() : null,
				'<': () => this.speedBy(-1),
				'>': () => this.speedBy(1),
			}[lower] ?? null
		},

		// --- menus --------------------------------------------------------

		/** @param {string} name the menu to open, or to close when it is open */
		async toggleMenu(name) {
			if (this.menu === name) {
				this.closeMenu()
				return
			}

			this.menu = name
			await this.$nextTick()
			const items = this.menuItems()
			;(items.find((item) => item.getAttribute('aria-checked') === 'true') ?? items[0])?.focus()
		},

		/** Closes the open menu and gives the focus back to its button. */
		closeMenu() {
			const name = this.menu
			if (name === null) {
				return
			}

			this.menu = null
			const button = /** @type {HTMLElement|undefined} */ (this.$refs[`${name}Button`])
			button?.focus()
		},

		/** @return {HTMLElement[]} the items of the open menu */
		menuItems() {
			const menu = this.$refs.menu
			const element = Array.isArray(menu) ? menu[0] : menu

			return element ? [...element.querySelectorAll('[role="menuitemradio"]')] : []
		},

		/**
		 * Up and down through the items, Home and End to the ends, Escape out.
		 *
		 * @param {KeyboardEvent} event the press
		 */
		onMenuKeydown(event) {
			const items = this.menuItems()
			const at = items.indexOf(/** @type {HTMLElement} */ (event.target))
			const next = {
				ArrowDown: (at + 1) % items.length,
				ArrowUp: (at - 1 + items.length) % items.length,
				Home: 0,
				End: items.length - 1,
			}[event.key]

			if (event.key === 'Escape' || event.key === 'Tab') {
				if (event.key === 'Escape') {
					event.preventDefault()
				}
				event.stopPropagation()
				this.closeMenu()
			} else if (next !== undefined) {
				event.preventDefault()
				items[next]?.focus()
			}
		},

		/** @param {PointerEvent} event a press anywhere: outside the open menu, it closes */
		onPointerDown(event) {
			if (this.menu === null) {
				return
			}

			const menu = this.menuItems()[0]?.closest('.video-player__menu-anchor')
			if (!menu || !menu.contains(/** @type {Node} */ (event.target))) {
				this.menu = null
			}
		},

		// --- the rest -----------------------------------------------------

		showControls() {
			this.controlsShown = true
			this.hideControlsSoon()
		},

		/** The controls get out of the picture while it plays and nobody is pointing at it. */
		hideControlsSoon() {
			clearTimeout(this.idleTimer)
			this.idleTimer = window.setTimeout(() => {
				const element = this.media()
				const focused = this.$el?.contains?.(document.activeElement) && document.activeElement !== this.$el
				if (element && !element.paused && this.menu === null && !focused) {
					this.controlsShown = false
				}
			}, CONTROLS_FOR)
		},

		/** @param {string} message what a screen reader is told */
		say(message) {
			// the same words twice in a row are not read again unless they change
			this.announcement = this.announcement === message ? `${message}\u00A0` : message
		},

		/**
		 * @param {number} seconds a moment
		 * @return {string} it as a clock
		 */
		clock(seconds) {
			return formatTime(Number.isFinite(seconds) ? seconds : 0)
		},

		/**
		 * @param {number} speed a speed
		 * @return {string} `1.5×`
		 */
		speedLabel(speed) {
			return `${speed}×`
		},

		/**
		 * @param {number} share 0 to 1
		 * @return {string} `40%`
		 */
		percent(share) {
			return `${Math.round(share * 100)}%`
		},

		/**
		 * @param {string} code a language code
		 * @return {string} its name in the reader's language, else the code
		 */
		languageName(code) {
			if (!code) {
				return t('social', 'Subtitles')
			}
			try {
				return new Intl.DisplayNames([getLanguage()], { type: 'language' }).of(code) || code
			} catch {
				return code
			}
		},
	},
}
</script>

<style scoped lang="scss">
.video-player {
	position: relative;
	width: 100%;
	aspect-ratio: 16 / 9;
	max-height: 75vh;
	background: #000;
	border-radius: var(--border-radius-large, 12px);
	overflow: hidden;
	color: #fff;

	&:focus-visible {
		outline: 3px solid var(--color-primary-element);
		outline-offset: 2px;
	}

	&--fullscreen {
		max-height: none;
		height: 100%;
		border-radius: 0;
	}

	&--mini {
		max-height: none;
		border-radius: var(--border-radius, 8px);
	}

	&--unplayable {
		display: flex;
		align-items: center;
		justify-content: center;
		background: var(--color-background-dark);
		color: var(--color-main-text);
	}

	&--idle {
		cursor: none;
	}
}

.video-player__video {
	width: 100%;
	height: 100%;
	object-fit: contain;
	display: block;
}

.video-player__start {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	background: rgb(0 0 0 / 20%);
	cursor: pointer;
}

.video-player__controls {
	position: absolute;
	inset-inline: 0;
	inset-block-end: 0;
	padding: 24px 8px 4px;
	background: linear-gradient(to top, rgb(0 0 0 / 75%), transparent);
	transition: opacity var(--social-motion-base) var(--social-ease);

	.video-player--idle & {
		opacity: 0;
		pointer-events: none;
	}
}

.video-player__bar {
	display: flex;
	align-items: center;
	gap: 2px;
}

.video-player__spacer {
	flex: 1;
}

.video-player__button {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 40px;
	min-height: 40px;
	margin: 0;
	padding: 0 8px;
	border: none;
	border-radius: var(--border-radius, 8px);
	background: transparent;
	color: #fff;
	cursor: pointer;

	&:hover {
		background: rgb(255 255 255 / 15%);
	}

	&--text {
		font-weight: bold;
		font-variant-numeric: tabular-nums;
	}
}

.video-player__button,
.video-player__seek,
.video-player__volume,
.video-player__menu button {
	&:focus-visible {
		outline: 2px solid #fff;
		outline-offset: 1px;
		box-shadow: none;
	}
}

.video-player__seek {
	display: block;
	width: 100%;
	margin: 0 0 4px;
	accent-color: var(--color-primary-element);
	cursor: pointer;
}

@media (forced-colors: active) {
	.video-player__seek,
	.video-player__volume {
		accent-color: Highlight;
	}
}

.video-player__volume {
	width: 80px;
	accent-color: #fff;
	cursor: pointer;
}

.video-player__save-failed {
	margin: 0 0 4px;
	padding-inline: 8px;
	color: var(--color-error);
	font-size: 12px;
}

.video-player__time,
.video-player__live {
	padding-inline: 8px;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.video-player__live {
	border-radius: var(--border-radius, 8px);
	background: var(--color-error, #e9322d);
	font-weight: bold;
}

.video-player__menu-anchor {
	position: relative;
}

.video-player__menu {
	position: absolute;
	inset-block-end: calc(100% + 4px);
	inset-inline-end: 0;
	z-index: 2;
	min-width: 140px;
	max-height: 50vh;
	overflow-y: auto;
	margin: 0;
	padding: 4px;
	list-style: none;
	border-radius: var(--border-radius, 8px);
	background: rgb(28 28 28 / 92%);

	button {
		display: block;
		width: 100%;
		margin: 0;
		padding: 8px 12px;
		border: none;
		border-radius: var(--border-radius, 8px);
		background: transparent;
		color: #fff;
		text-align: start;
		cursor: pointer;

		&:hover {
			background: rgb(255 255 255 / 15%);
		}

		&[aria-checked='true'] {
			font-weight: bold;

			&::before {
				content: '✓ ';
			}
		}
	}
}

.video-player__elsewhere {
	padding: 16px;
	text-align: center;
}

/* the small player has room for the few controls it shows */
.video-player--mini .video-player__controls {
	padding-top: 12px;
}

@media (prefers-reduced-motion: reduce) {
	.video-player__controls {
		transition: none;
	}
}

@media (forced-colors: active) {
	.video-player__button,
	.video-player__menu button {
		border: 1px solid ButtonText;
	}
}
</style>
