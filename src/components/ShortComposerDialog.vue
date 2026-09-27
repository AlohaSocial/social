<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		v-if="open"
		size="large"
		:name="forStory ? t('social', 'Add to your story') : t('social', 'New short')"
		:closeOnClickOutside="false"
		@close="requestClose">
		<div class="short" @dragover.prevent @drop.prevent="onDrop">
			<!-- 1. where the video comes from -->
			<div v-if="phase === 'choose'" class="short__choose">
				<h2 class="short__title">
					{{ forStory ? t('social', 'Add to your story') : t('social', 'Post a short') }}
				</h2>
				<p class="short__lede">
					{{ forStory
						? t('social', 'For the people who follow you, gone after a day. Record a video, upload one, or post a picture or a few words.')
						: t('social', 'A video, watched full height, one after another. Upload one, drop one here, or record one now.') }}
				</p>
				<input
					ref="file"
					type="file"
					:accept="forStory ? 'video/mp4,video/webm,video/quicktime,video/*,image/*' : 'video/mp4,video/webm,video/quicktime,video/*'"
					class="hidden-visually"
					tabindex="-1"
					aria-hidden="true"
					@change="onPick">
				<div class="short__sources">
					<button type="button" class="short__source" @click="pickFile">
						<IconUpload :size="36" />
						<span class="short__source-name">{{ forStory ? t('social', 'Upload') : t('social', 'Upload a video') }}</span>
						<span class="short__source-hint">{{ t('social', 'or drop it here') }}</span>
					</button>
					<button
						v-if="canRecord"
						type="button"
						class="short__source short__source--record"
						@click="startCamera">
						<IconRecord :size="36" />
						<span class="short__source-name">{{ t('social', 'Record') }}</span>
						<span class="short__source-hint">{{ t('social', 'with your camera') }}</span>
					</button>
					<!-- a browser offers the camera only over https, and a Record
					     button that silently is not there is a puzzle -->
					<div
						v-else-if="needsSecureContext"
						class="short__source short__source--record short__source--unavailable">
						<IconRecord :size="36" />
						<span class="short__source-name">{{ t('social', 'Record') }}</span>
						<span class="short__source-hint">{{ t('social', 'needs a secure (https) connection') }}</span>
					</div>
					<!-- a picture with stickers, or words on a card, are the
					     story editor's; this dialog is for video -->
					<button
						v-if="forStory"
						type="button"
						class="short__source short__source--other"
						@click="$emit('other', null)">
						<IconImageText :size="36" />
						<span class="short__source-name">{{ t('social', 'Picture or words') }}</span>
						<span class="short__source-hint">{{ t('social', 'with stickers, or on a card') }}</span>
					</button>
				</div>
				<p v-if="cameraError" class="short__error" role="alert">
					{{ cameraError }}
				</p>
			</div>

			<!-- 2. recording -->
			<div v-else-if="phase === 'record'" class="short__record">
				<div class="short__stage">
					<video
						ref="camera"
						class="short__video"
						:class="{ 'short__video--mirrored': facing === 'user' }"
						autoplay
						muted
						playsinline />
					<span v-if="countdown > 0" class="short__countdown" aria-live="assertive">{{ countdown }}</span>
					<div class="short__rec-progress" aria-hidden="true">
						<span :style="{ width: (elapsed / limit * 100) + '%' }" />
					</div>
					<span class="short__rec-time" :class="{ 'short__rec-time--live': recording }">
						{{ formatTime(elapsed) }} / {{ formatTime(limit) }}
					</span>
				</div>

				<div class="short__rec-controls">
					<div class="short__pills" role="radiogroup" :aria-label="t('social', 'Longest recording')">
						<button
							v-for="option in recordLimits"
							:key="option"
							type="button"
							role="radio"
							class="short__pill"
							:aria-checked="option === limit"
							:disabled="recording || countdown > 0"
							@click="limit = option">
							{{ option < 60 ? t('social', '{n} s', { n: option }) : t('social', '{n} min', { n: option / 60 }) }}
						</button>
					</div>
					<div class="short__rec-buttons">
						<NcButton variant="tertiary" :disabled="recording" @click="stopCamera(true)">
							{{ t('social', 'Back') }}
						</NcButton>
						<button
							type="button"
							class="short__shutter"
							:class="{ 'short__shutter--recording': recording }"
							:aria-label="recording ? t('social', 'Stop recording') : t('social', 'Start recording')"
							:disabled="countdown > 0"
							@click="recording ? stopRecording() : startCountdown()" />
						<NcButton
							variant="tertiary"
							:disabled="recording || countdown > 0"
							:aria-label="t('social', 'Switch camera')"
							@click="flipCamera">
							<template #icon>
								<IconCameraFlip :size="22" />
							</template>
						</NcButton>
					</div>
				</div>
			</div>

			<!-- 3. trimming, the cover, and what the post says -->
			<div v-else class="short__edit">
				<div class="short__preview-column">
					<div class="short__stage">
						<video
							ref="preview"
							class="short__video"
							:src="url"
							playsinline
							autoplay
							:muted="previewMuted"
							:aria-label="t('social', 'Preview of your short')"
							@loadedmetadata="onLoaded"
							@timeupdate="keepInsideTrim"
							@click="togglePreview" />
						<button
							type="button"
							class="short__stage-button"
							:aria-label="previewMuted ? t('social', 'Unmute') : t('social', 'Mute')"
							@click="previewMuted = !previewMuted">
							<IconVolumeOff v-if="previewMuted" :size="20" />
							<IconVolumeHigh v-else :size="20" />
						</button>
					</div>

					<!-- the trim bar: stills across the video, and two handles -->
					<div
						ref="trimBar"
						class="short__trim"
						:class="{ 'short__trim--empty': frames.length === 0 }">
						<div class="short__strip" aria-hidden="true">
							<img
								v-for="(frame, index) in frames"
								:key="index"
								:src="frame"
								alt=""
								draggable="false">
						</div>
						<div class="short__shade short__shade--before" :style="{ width: percent(trim.start) }" aria-hidden="true" />
						<div class="short__shade short__shade--after" :style="{ width: percent(duration - trim.end) }" aria-hidden="true" />
						<div class="short__window" :style="{ left: percent(trim.start), width: percent(trim.end - trim.start) }" aria-hidden="true" />
						<div class="short__playhead" :style="{ left: percent(playhead) }" aria-hidden="true" />
						<button
							v-for="edge in ['start', 'end']"
							:key="edge"
							type="button"
							role="slider"
							class="short__handle"
							:class="`short__handle--${edge}`"
							:style="{ left: percent(trim[edge]) }"
							:aria-label="edge === 'start' ? t('social', 'Start of the short') : t('social', 'End of the short')"
							:aria-valuemin="0"
							:aria-valuemax="Math.round(duration)"
							:aria-valuenow="Math.round(trim[edge])"
							:aria-valuetext="formatTime(trim[edge])"
							@pointerdown="grab(edge, $event)"
							@keydown="nudge(edge, $event)" />
					</div>
					<p class="short__trim-times">
						<span>{{ formatTime(trim.start) }} – {{ formatTime(trim.end) }}</span>
						<strong>{{ formatTime(trim.end - trim.start) }}</strong>
					</p>
					<p v-if="trimmed && !canTrim" class="short__note">
						{{ t('social', 'This browser cannot cut videos, so the whole video will be posted.') }}
					</p>
				</div>

				<div class="short__details">
					<section class="short__section">
						<h3 class="short__heading">
							{{ t('social', 'Cover') }}
						</h3>
						<div class="short__cover">
							<img
								v-if="coverUrl"
								class="short__cover-image"
								:src="coverUrl"
								:alt="t('social', 'The cover of your short')">
							<span v-else class="short__cover-image short__cover-image--empty" />
							<div class="short__cover-pick">
								<label :for="coverId" class="short__label">{{ t('social', 'Pick the frame people see before it plays') }}</label>
								<input
									:id="coverId"
									v-model.number="coverTime"
									type="range"
									class="short__range"
									:min="trim.start"
									:max="trim.end"
									step="0.1"
									@change="takeCover">
							</div>
						</div>
						<!-- the frames are taken from a video of their own, so choosing
						     a cover does not jump the preview about -->
						<video
							ref="coverSource"
							class="hidden-visually"
							:src="url"
							muted
							playsinline
							preload="auto"
							aria-hidden="true"
							@loadeddata="takeCover" />
					</section>

					<section class="short__section">
						<label :for="captionId" class="short__heading">{{ t('social', 'Caption') }}</label>
						<textarea
							:id="captionId"
							v-model="caption"
							class="short__caption"
							rows="3"
							:maxlength="maxCharacters"
							:placeholder="forStory ? t('social', 'Optional') : t('social', 'Say what it is. Add #hashtags so people find it.')" />
						<span class="short__counter" :class="{ 'short__counter--near': caption.length > maxCharacters * 0.9 }">
							{{ caption.length }} / {{ maxCharacters }}
						</span>
						<div v-if="suggestions.length" class="short__tags" :aria-label="t('social', 'Popular hashtags')">
							<button
								v-for="tag in suggestions"
								:key="tag"
								type="button"
								class="short__tag"
								:disabled="hasTag(tag)"
								@click="addTag(tag)">
								#{{ tag }}
							</button>
						</div>
					</section>

					<p v-if="forStory" class="short__note short__note--plain">
						{{ t('social', 'Your followers can watch it for a day.') }}
					</p>

					<section v-if="!forStory" class="short__section">
						<h3 id="short-audience" class="short__heading">
							{{ t('social', 'Who can watch') }}
						</h3>
						<div class="short__pills short__pills--wide" role="radiogroup" aria-labelledby="short-audience">
							<button
								v-for="option in audiences"
								:key="option.value"
								type="button"
								role="radio"
								class="short__pill"
								:aria-checked="option.value === visibility"
								:title="option.hint"
								@click="visibility = option.value">
								<component :is="option.icon" :size="18" />
								{{ option.label }}
							</button>
						</div>
					</section>

					<section v-if="!forStory" class="short__section">
						<NcCheckboxRadioSwitch v-model="sensitive" type="switch">
							{{ t('social', 'Sensitive content: hide it until somebody chooses to watch') }}
						</NcCheckboxRadioSwitch>
					</section>

					<div
						v-if="busy"
						class="short__progress"
						role="status"
						aria-live="polite">
						<span class="short__progress-label">{{ busyLabel }}</span>
						<span class="short__progress-bar"><span :style="{ width: Math.round(progress * 100) + '%' }" /></span>
					</div>

					<div class="short__actions">
						<NcButton variant="tertiary" :disabled="busy !== ''" @click="changeVideo">
							{{ t('social', 'Change video') }}
						</NcButton>
						<NcButton variant="primary" :disabled="busy !== '' || duration <= 0" @click="post">
							<template #icon>
								<NcLoadingIcon v-if="busy" :size="20" />
								<IconSend v-else :size="20" />
							</template>
							{{ t('social', 'Post') }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- a short in progress is not thrown away by a stray click -->
			<div
				v-if="confirmingDiscard"
				class="short__confirm"
				role="alertdialog"
				aria-labelledby="short-discard">
				<p id="short-discard">
					{{ t('social', 'Discard this short?') }}
				</p>
				<div class="short__actions">
					<NcButton variant="tertiary" @click="confirmingDiscard = false">
						{{ t('social', 'Keep editing') }}
					</NcButton>
					<NcButton variant="error" @click="discard">
						{{ t('social', 'Discard') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import { mapStores } from 'pinia'
import IconAccountGroup from 'vue-material-design-icons/AccountMultiple.vue'
import IconCameraFlip from 'vue-material-design-icons/CameraFlipOutline.vue'
import IconEarth from 'vue-material-design-icons/Earth.vue'
import IconMoon from 'vue-material-design-icons/WeatherNight.vue'
import IconRecord from 'vue-material-design-icons/RecordCircleOutline.vue'
import IconSend from 'vue-material-design-icons/Send.vue'
import IconImageText from 'vue-material-design-icons/ImageText.vue'
import IconUpload from 'vue-material-design-icons/Upload.vue'
import IconVolumeHigh from 'vue-material-design-icons/VolumeHigh.vue'
import IconVolumeOff from 'vue-material-design-icons/VolumeOff.vue'
import { knownLimits } from '../services/instanceLimits.js'
import logger from '../services/logger.js'
import { feel } from '../services/senses.js'
import { showError, showSuccess } from '../services/toast.js'
import { useTimelineStore } from '../store/timeline.js'
import {
	captureFrame,
	clampTrim,
	filmstrip,
	formatTime,
	isTrimmed,
	knownDuration,
	RECORD_LIMITS,
	recorderMimeType,
	fileTypeFor,
	seekTo,
	trimVideo,
} from '../utils/shortVideo.js'

/** how many stills the trim bar shows */
const FRAMES = 10

/** the longest a story's caption may be, as the story editor has it */
const STORY_CAPTION_MAX = 500

/** how far one arrow key moves a trim handle, in seconds; shift moves five times as far */
const NUDGE = 0.5

let serial = 0

/**
 * Posting a short, the way short-video apps do it.
 *
 * One dialog, three steps: where the video comes from (a file, a drop, or the
 * camera), then the video itself -- trimmed with two handles over a strip of
 * stills, a cover picked from any frame -- beside what the post says and who
 * sees it. Everything that changes the video happens in the browser
 * (`utils/shortVideo.js`), so what goes up is what the writer watched, and it
 * goes up as an ordinary video attachment on an ordinary post: a short is a
 * post with a video, and federates as one.
 */
export default {
	name: 'ShortComposerDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		IconCameraFlip,
		IconRecord,
		IconSend,
		IconImageText,
		IconUpload,
		IconVolumeHigh,
		IconVolumeOff,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		/**
		 * 'short' posts the video as a post; 'story' adds it to the writer's
		 * story, for followers and for a day, and hands a picture or a text
		 * story to the story editor through `other`.
		 */
		mode: {
			type: String,
			default: 'short',
			validator: (value) => ['short', 'story'].includes(String(value)),
		},
	},

	emits: ['update:open', 'posted', 'other'],

	data() {
		serial++

		return {
			/** 'choose', 'record' or 'edit' */
			phase: 'choose',
			/** @type {File|null} */
			file: null,
			url: '',
			duration: 0,
			trim: { start: 0, end: 0 },
			playhead: 0,
			frames: [],
			previewMuted: false,
			coverTime: 0,
			/** @type {Blob|null} */
			cover: null,
			coverUrl: '',
			caption: '',
			visibility: 'public',
			sensitive: false,
			suggestions: [],
			/** '' when idle, otherwise 'prepare', 'upload' or 'post' */
			busy: '',
			progress: 0,
			confirmingDiscard: false,
			// recording
			facing: 'user',
			limit: 60,
			countdown: 0,
			recording: false,
			elapsed: 0,
			cameraError: '',
			/** the trim handle being dragged, while it is */
			dragging: null,
			/** @type {((event: PointerEvent) => void)|null} */
			onDrag: null,
			/** @type {(() => void)|null} */
			onLift: null,
			/** @type {MediaStream|null} */
			stream: null,
			/** @type {MediaRecorder|null} */
			recorder: null,
			ticker: 0,
			counter: 0,
			coverId: `short-cover-${serial}`,
			captionId: `short-caption-${serial}`,
		}
	},

	computed: {
		...mapStores(useTimelineStore),

		/** @return {boolean} whether this browser can record from a camera */
		canRecord() {
			return typeof window.MediaRecorder === 'function'
				&& typeof window.navigator?.mediaDevices?.getUserMedia === 'function'
		},

		/** @return {boolean} whether recording is missing only because the page is not served over https */
		needsSecureContext() {
			return !this.canRecord && window.isSecureContext === false && typeof window.MediaRecorder === 'function'
		},

		/** @return {boolean} whether this browser can cut a video */
		canTrim() {
			return typeof window.MediaRecorder === 'function'
				&& typeof HTMLCanvasElement !== 'undefined'
				&& typeof HTMLCanvasElement.prototype.captureStream === 'function'
		},

		/** @return {boolean} whether the handles cut anything off */
		trimmed() {
			return this.duration > 0 && isTrimmed(this.trim, this.duration)
		},

		/** @return {boolean} whether this is adding to a story rather than posting */
		forStory() {
			return this.mode === 'story'
		},

		/** @return {number} how long a caption may be */
		maxCharacters() {
			return this.forStory ? STORY_CAPTION_MAX : knownLimits().maxCharacters
		},

		/** @return {number[]} the recording limits on offer; a story is a minute at most */
		recordLimits() {
			return this.forStory ? RECORD_LIMITS.filter((seconds) => seconds <= 60) : RECORD_LIMITS
		},

		/** @return {object[]} the audiences a short can have */
		audiences() {
			return [
				{ value: 'public', label: t('social', 'Everyone'), hint: t('social', 'Anybody, and in the public timelines'), icon: IconEarth },
				{ value: 'unlisted', label: t('social', 'Quiet public'), hint: t('social', 'Anybody with the link, but not in the public timelines'), icon: IconMoon },
				{ value: 'private', label: t('social', 'Followers'), hint: t('social', 'Only the people who follow you'), icon: IconAccountGroup },
			]
		},

		/** @return {string} what the post button is waiting for */
		busyLabel() {
			switch (this.busy) {
				case 'prepare': return t('social', 'Cutting your short …')
				case 'upload': return t('social', 'Uploading …')
				default: return t('social', 'Posting …')
			}
		},
	},

	watch: {
		open(now) {
			if (now) {
				if (!this.forStory) {
					this.loadSuggestions()
				}
			} else {
				this.reset()
			}
		},

		previewMuted(now) {
			if (this.video('preview')) {
				this.video('preview').muted = now
			}
		},
	},

	mounted() {
		if (this.open && !this.forStory) {
			this.loadSuggestions()
		}
	},

	beforeUnmount() {
		this.reset()
	},

	methods: {
		t,
		formatTime,

		/**
		 * @param {'preview'|'camera'|'coverSource'} name which of the video elements
		 * @return {HTMLVideoElement|undefined} it, while it is on screen
		 */
		video(name) {
			return /** @type {HTMLVideoElement|undefined} */ (this.$refs[name])
		},

		/**
		 * @param {number} seconds a time in the video
		 * @return {string} where it is along the trim bar, as a CSS width or offset
		 */
		percent(seconds) {
			return this.duration > 0 ? `${Math.max(0, Math.min(100, (seconds / this.duration) * 100))}%` : '0%'
		},

		pickFile() {
			/** @type {HTMLInputElement|undefined} */ (this.$refs.file)?.click()
		},

		/** @param {Event} event the file input's change */
		onPick(event) {
			const input = /** @type {HTMLInputElement} */ (event.target)
			const chosen = input.files?.[0] ?? null
			input.value = ''
			if (chosen) {
				this.useFile(chosen)
			}
		},

		/** @param {DragEvent} event something dropped on the dialog */
		onDrop(event) {
			if (this.phase !== 'choose') {
				return
			}
			const dropped = [...(event.dataTransfer?.files ?? [])].find((one) => one.type.startsWith('video/') || (this.forStory && one.type.startsWith('image/')))
			if (dropped) {
				this.useFile(dropped)
			}
		},

		/**
		 * Starts editing a video, from a file or from the camera.
		 *
		 * @param {File} file the video
		 */
		useFile(file) {
			if (this.forStory && file.type.startsWith('image/')) {
				this.$emit('other', file)
				return
			}
			if (!file.type.startsWith('video/')) {
				showError(t('social', 'A short has to be a video'))
				return
			}

			this.releaseVideo()
			this.file = file
			this.url = URL.createObjectURL(file)
			this.phase = 'edit'
		},

		/** The preview knows how long the video is: set up the trim and the strip. */
		async onLoaded() {
			const video = this.video('preview')
			this.duration = video ? await knownDuration(video) : 0
			this.trim = { start: 0, end: this.duration }
			this.coverTime = Math.min(1, this.duration / 2)
			this.frames = await filmstrip(this.url, FRAMES)
		},

		/** The preview loops inside the trim, so what plays is what will be posted. */
		keepInsideTrim() {
			const video = this.video('preview')
			if (!video) {
				return
			}
			this.playhead = video.currentTime
			if (video.currentTime >= this.trim.end || video.currentTime < this.trim.start - 0.2) {
				video.currentTime = this.trim.start
				video.play?.()?.catch?.(() => {})
			}
		},

		togglePreview() {
			const video = this.video('preview')
			if (!video) {
				return
			}
			if (video.paused) {
				video.play?.()?.catch?.(() => {})
			} else {
				video.pause?.()
			}
		},

		/**
		 * @param {'start'|'end'} edge the handle pressed
		 * @param {PointerEvent} event the press
		 */
		grab(edge, event) {
			this.dragging = edge
			const target = /** @type {HTMLElement|null} */ (event.target)
			target?.setPointerCapture?.(event.pointerId)
			this.onDrag = (move) => this.drag(move)
			this.onLift = () => this.release()
			window.addEventListener('pointermove', this.onDrag)
			window.addEventListener('pointerup', this.onLift)
		},

		/** @param {PointerEvent} event where the pointer is */
		drag(event) {
			const bar = /** @type {HTMLElement|undefined} */ (this.$refs.trimBar)
			const box = bar?.getBoundingClientRect?.()
			if (!box || box.width === 0 || this.dragging === null) {
				return
			}
			const at = ((event.clientX - box.left) / box.width) * this.duration
			this.setEdge(this.dragging, at)
		},

		release() {
			this.dragging = null
			if (this.onDrag) {
				window.removeEventListener('pointermove', this.onDrag)
				window.removeEventListener('pointerup', this.onLift)
				this.onDrag = null
				this.onLift = null
			}
		},

		/**
		 * The keyboard's way of moving a handle.
		 *
		 * @param {'start'|'end'} edge the focused handle
		 * @param {KeyboardEvent} event the key
		 */
		nudge(edge, event) {
			const step = NUDGE * (event.shiftKey ? 5 : 1)
			const moves = { ArrowLeft: -step, ArrowDown: -step, ArrowRight: step, ArrowUp: step }
			if (event.key === 'Home' || event.key === 'End') {
				event.preventDefault()
				this.setEdge(edge, event.key === 'Home' ? 0 : this.duration)
				return
			}
			if (moves[event.key] === undefined) {
				return
			}
			event.preventDefault()
			this.setEdge(edge, this.trim[edge] + moves[event.key])
		},

		/**
		 * Moves one end of the trim, keeps the cover inside it, and shows the
		 * frame at the handle so the writer sees where they are cutting.
		 *
		 * @param {'start'|'end'} edge which end
		 * @param {number} at where to, in seconds
		 */
		setEdge(edge, at) {
			this.trim = clampTrim({ ...this.trim, [edge]: at }, this.duration, edge)
			this.coverTime = Math.min(Math.max(this.coverTime, this.trim.start), this.trim.end)
			const video = this.video('preview')
			if (video) {
				video.currentTime = this.trim[edge]
			}
		},

		/** Takes the frame at the cover time as the cover. */
		async takeCover() {
			const source = this.video('coverSource')
			if (!source || !this.url) {
				return
			}
			try {
				await seekTo(source, this.coverTime)
				const blob = await captureFrame(source)
				if (blob) {
					if (this.coverUrl) {
						URL.revokeObjectURL(this.coverUrl)
					}
					this.cover = blob
					this.coverUrl = URL.createObjectURL(blob)
				}
			} catch (error) {
				logger.debug('the cover frame could not be taken', { error })
			}
		},

		/** @param {string} tag a hashtag @return {boolean} whether the caption has it */
		hasTag(tag) {
			return new RegExp(`(^|\\s)#${tag}\\b`, 'i').test(this.caption)
		},

		/** @param {string} tag a hashtag to add to the caption */
		addTag(tag) {
			const glue = this.caption === '' || /\s$/.test(this.caption) ? '' : ' '
			this.caption = `${this.caption}${glue}#${tag} `.slice(0, this.maxCharacters)
		},

		/** What people are tagging, for one-press hashtags; nothing if it cannot be read. */
		async loadSuggestions() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/trends/tags'), { params: { limit: 8 } })
				this.suggestions = (Array.isArray(data) ? data : [])
					.map((tag) => String(tag?.name ?? ''))
					.filter((name) => name !== '')
					.slice(0, 8)
			} catch (error) {
				logger.debug('no hashtag suggestions', { error })
				this.suggestions = []
			}
		},

		// ------------------------------------------------------------ camera

		async startCamera() {
			this.cameraError = ''
			try {
				this.stream = await navigator.mediaDevices.getUserMedia({
					video: { facingMode: this.facing, width: { ideal: 1080 }, height: { ideal: 1920 } },
					audio: true,
				})
			} catch (error) {
				logger.debug('the camera could not be opened', { error })
				this.cameraError = t('social', 'The camera could not be opened. Check that this page may use it.')
				return
			}
			this.phase = 'record'
			await this.$nextTick()
			if (this.video('camera')) {
				this.video('camera').srcObject = this.stream
			}
		},

		/** @param {boolean} [back] whether to go back to choosing a source */
		stopCamera(back = false) {
			window.clearInterval(this.ticker)
			window.clearInterval(this.counter)
			this.countdown = 0
			this.recording = false
			this.stream?.getTracks?.().forEach((track) => track.stop())
			this.stream = null
			if (back) {
				this.phase = 'choose'
			}
		},

		async flipCamera() {
			this.facing = this.facing === 'user' ? 'environment' : 'user'
			this.stopCamera()
			await this.startCamera()
		},

		/** Three, two, one: time to get the phone steady. */
		startCountdown() {
			this.countdown = 3
			feel('roll')
			this.counter = window.setInterval(() => {
				this.countdown--
				if (this.countdown <= 0) {
					window.clearInterval(this.counter)
					this.startRecording()
				}
			}, 1000)
		},

		startRecording() {
			const mime = recorderMimeType((type) => window.MediaRecorder.isTypeSupported(type))
			try {
				this.recorder = new window.MediaRecorder(this.stream, mime ? { mimeType: mime } : undefined)
			} catch (error) {
				logger.error('recording could not start', { error })
				showError(t('social', 'Recording could not start'))
				return
			}
			const chunks = []
			this.recorder.addEventListener('dataavailable', (event) => {
				if (event.data?.size > 0) {
					chunks.push(event.data)
				}
			})
			this.recorder.addEventListener('stop', () => {
				const { type, extension } = fileTypeFor(this.recorder?.mimeType || mime)
				const file = new File(chunks, `short-${Date.now()}.${extension}`, { type })
				this.stopCamera()
				this.useFile(file)
			}, { once: true })

			this.elapsed = 0
			this.recording = true
			this.recorder.start(250)
			feel('post')
			const began = Date.now()
			this.ticker = window.setInterval(() => {
				this.elapsed = (Date.now() - began) / 1000
				if (this.elapsed >= this.limit) {
					this.stopRecording()
				}
			}, 100)
		},

		stopRecording() {
			window.clearInterval(this.ticker)
			this.recording = false
			if (this.recorder?.state === 'recording') {
				this.recorder.stop()
			}
		},

		// -------------------------------------------------------------- post

		/** @return {Promise<void>} */
		async post() {
			if (!this.file || this.busy !== '') {
				return
			}

			try {
				let upload = this.file
				if (this.trimmed && this.canTrim) {
					this.busy = 'prepare'
					this.progress = 0
					const cut = await trimVideo(this.file, this.trim.start, this.trim.end, {
						onProgress: (share) => { this.progress = share },
					})
					if (cut) {
						upload = cut
					}
				}

				this.busy = 'upload'
				this.progress = 0
				const media = await this.timelineStore.createMedia({
					file: upload,
					thumbnail: this.cover,
					onProgress: (share) => { this.progress = share },
				})
				if (!media?.id) {
					// the store has already said what went wrong
					return
				}

				this.busy = 'post'
				this.progress = 1
				if (this.forStory) {
					const { data } = await axios.post(generateUrl('apps/social/api/v1/stories'), {
						media_id: media.id,
						caption: this.caption.trim(),
						// how long a picture is shown; a video plays for as long as it is
						duration: 5,
					})
					feel('post')
					showSuccess(t('social', 'Your story is up for a day'))
					this.$emit('posted', data)
					this.$emit('update:open', false)
					return
				}
				const created = await this.timelineStore.post({
					status: this.caption.trim(),
					media_ids: [media.id],
					visibility: this.visibility,
					sensitive: this.sensitive,
					spoiler_text: '',
				})
				if (created === undefined) {
					return
				}

				feel('post')
				if (!created?.held_for_review) {
					showSuccess(t('social', 'Your short is up'))
				}
				this.$emit('posted', created)
				this.$emit('update:open', false)
			} catch (error) {
				logger.error('the short could not be posted', { error })
				showError(this.forStory
					? (error?.response?.data?.error || t('social', 'Could not post the story'))
					: t('social', 'The short could not be posted'))
			} finally {
				this.busy = ''
			}
		},

		// ------------------------------------------------------------ closing

		/** A stray click must not throw a short away. */
		requestClose() {
			if (this.busy !== '') {
				return
			}
			if (this.file || this.recording) {
				this.confirmingDiscard = true
				return
			}
			this.$emit('update:open', false)
		},

		discard() {
			this.confirmingDiscard = false
			this.$emit('update:open', false)
		},

		changeVideo() {
			this.releaseVideo()
			this.phase = 'choose'
		},

		releaseVideo() {
			if (this.url) {
				URL.revokeObjectURL(this.url)
			}
			if (this.coverUrl) {
				URL.revokeObjectURL(this.coverUrl)
			}
			this.file = null
			this.url = ''
			this.duration = 0
			this.trim = { start: 0, end: 0 }
			this.frames = []
			this.cover = null
			this.coverUrl = ''
		},

		/** Back to an empty dialog, the camera off. */
		reset() {
			this.stopCamera()
			this.release()
			this.releaseVideo()
			this.phase = 'choose'
			this.caption = ''
			this.visibility = 'public'
			this.sensitive = false
			this.confirmingDiscard = false
			this.cameraError = ''
			this.busy = ''
		},
	},
}
</script>

<style scoped lang="scss">
.short {
	position: relative;
	padding: 20px;
	min-height: min(80vh, 640px);
	display: flex;
	flex-direction: column;
}

.short__title {
	margin: 0 0 6px;
	font-size: 22px;
	font-weight: 700;
	text-align: center;
}

.short__lede {
	max-width: 44ch;
	margin: 0 auto 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.short__choose {
	flex: 1;
	display: flex;
	flex-direction: column;
	justify-content: center;
}

.short__sources {
	display: flex;
	flex-wrap: wrap;
	justify-content: center;
	gap: 16px;
}

.short__source {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 6px;
	inline-size: 220px;
	padding: 32px 16px;
	border: 2px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large, 16px);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	cursor: pointer;
	transition: border-color .15s ease, transform .15s ease;

	&:hover,
	&:focus-visible {
		border-color: var(--color-primary-element);
		background: var(--color-background-hover);
		transform: translateY(-2px);
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}

	&--record {
		border-style: solid;
		color: #fe2c55;
	}

	&--other {
		border-style: solid;
	}

	&--unavailable {
		cursor: default;
		opacity: .6;

		&:hover {
			border-color: var(--color-border-dark);
			transform: none;
		}
	}
}

.short__source-name {
	font-weight: 700;
	color: var(--color-main-text);
}

.short__source-hint {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.short__error {
	margin-top: 16px;
	text-align: center;
	color: var(--color-error-text, var(--color-error));
}

/* the 9:16 frame a short is watched in, used for the camera and the preview */
.short__stage {
	position: relative;
	aspect-ratio: 9 / 16;
	max-height: 62vh;
	margin-inline: auto;
	overflow: hidden;
	border-radius: 16px;
	background: #000;
}

.short__video {
	display: block;
	inline-size: 100%;
	block-size: 100%;
	object-fit: contain;
	cursor: pointer;

	&--mirrored {
		transform: scaleX(-1);
	}
}

.short__stage-button {
	position: absolute;
	inset-block-end: 12px;
	inset-inline-end: 12px;
	display: flex;
	align-items: center;
	justify-content: center;
	inline-size: 36px;
	block-size: 36px;
	padding: 0;
	border: none;
	border-radius: 50%;
	background: rgba(0, 0, 0, .55);
	color: #fff;
	cursor: pointer;

	&:hover {
		background: rgba(0, 0, 0, .75);
	}

	&:focus-visible {
		outline: 2px solid #fff;
		outline-offset: 2px;
	}
}

/* ------------------------------------------------------------- recording */
.short__record {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 14px;
}

.short__countdown {
	position: absolute;
	inset: 0;
	display: grid;
	place-items: center;
	color: #fff;
	font-size: 96px;
	font-weight: 800;
	text-shadow: 0 2px 16px rgba(0, 0, 0, .6);
	animation: short-count 1s ease-out infinite;
}

@keyframes short-count {
	from { transform: scale(1.4); opacity: 0; }
	30% { opacity: 1; }
	to { transform: scale(.9); opacity: .8; }
}

.short__rec-progress {
	position: absolute;
	inset-block-start: 10px;
	inset-inline: 10px;
	block-size: 4px;
	border-radius: 4px;
	background: rgba(255, 255, 255, .3);
	overflow: hidden;

	span {
		display: block;
		block-size: 100%;
		background: #fe2c55;
	}
}

.short__rec-time {
	position: absolute;
	inset-block-start: 22px;
	inset-inline-start: 50%;
	transform: translateX(-50%);
	padding: 2px 10px;
	border-radius: 999px;
	background: rgba(0, 0, 0, .5);
	color: #fff;
	font-size: 13px;
	font-variant-numeric: tabular-nums;

	&--live::before {
		content: '';
		display: inline-block;
		inline-size: 8px;
		block-size: 8px;
		margin-inline-end: 6px;
		border-radius: 50%;
		background: #fe2c55;
		animation: short-blink 1s steps(2) infinite;
	}
}

@keyframes short-blink {
	50% { opacity: 0; }
}

.short__rec-controls {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
}

.short__rec-buttons {
	display: flex;
	align-items: center;
	gap: 24px;
}

.short__shutter {
	inline-size: 72px;
	block-size: 72px;
	padding: 0;
	border: 5px solid #fff;
	border-radius: 50%;
	background: #fe2c55;
	box-shadow: var(--social-elevation-raised);
	cursor: pointer;
	transition: border-radius .2s ease, transform .2s ease;

	&:hover:not(:disabled) {
		background: #fe2c55;
	}

	&--recording {
		transform: scale(.9);
		border-radius: 30%;
	}

	&:focus-visible {
		outline: 3px solid var(--color-primary-element);
		outline-offset: 3px;
	}
}

/* ------------------------------------------------------------------ pills */
.short__pills {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	padding: 3px;
	border-radius: 999px;
	background: var(--color-background-dark);

	&--wide {
		border-radius: 16px;
	}
}

.short__pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 5px 14px;
	border: none;
	border-radius: 999px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
	cursor: pointer;

	&:hover:not(:disabled) {
		background: var(--color-background-hover);
	}

	&[aria-checked="true"],
	&[aria-checked="true"]:hover {
		background: var(--color-primary-element);
		color: var(--color-primary-element-text);
	}

	&:focus-visible {
		outline: 2px solid var(--color-main-text);
		outline-offset: 1px;
	}
}

/* ------------------------------------------------------------------ edit */
.short__edit {
	display: grid;
	grid-template-columns: minmax(240px, 340px) 1fr;
	gap: 24px;
	align-items: start;
}

@media (max-width: 720px) {
	.short__edit {
		grid-template-columns: 1fr;
	}
}

.short__trim {
	position: relative;
	margin-top: 14px;
	block-size: 52px;
	border-radius: 10px;
	background: var(--color-background-dark);
	touch-action: none;
	user-select: none;

	&--empty .short__strip {
		opacity: 0;
	}
}

.short__strip {
	display: flex;
	block-size: 100%;
	overflow: hidden;
	border-radius: 10px;

	img {
		flex: 1;
		min-inline-size: 0;
		object-fit: cover;
	}
}

.short__shade {
	position: absolute;
	inset-block: 0;
	background: rgba(0, 0, 0, .55);
	pointer-events: none;

	&--before {
		inset-inline-start: 0;
		border-start-start-radius: 10px;
		border-end-start-radius: 10px;
	}

	&--after {
		inset-inline-end: 0;
		border-start-end-radius: 10px;
		border-end-end-radius: 10px;
	}
}

.short__window {
	position: absolute;
	inset-block: 0;
	border: 3px solid #fe2c55;
	border-radius: 8px;
	pointer-events: none;
}

.short__playhead {
	position: absolute;
	inset-block: -4px;
	inline-size: 2px;
	margin-inline-start: -1px;
	background: #fff;
	box-shadow: var(--social-elevation-resting);
	pointer-events: none;
}

.short__handle {
	position: absolute;
	inset-block: -4px;
	inline-size: 16px;
	margin-inline-start: -8px;
	padding: 0;
	border: none;
	border-radius: 6px;
	background: #fe2c55;
	cursor: ew-resize;

	&::after {
		content: '';
		position: absolute;
		inset-block: 35%;
		inset-inline-start: 50%;
		inline-size: 2px;
		margin-inline-start: -1px;
		background: #fff;
		border-radius: 1px;
	}

	&:focus-visible {
		outline: 2px solid var(--color-main-text);
		outline-offset: 2px;
	}
}

.short__trim-times {
	display: flex;
	justify-content: space-between;
	margin: 6px 2px 0;
	font-size: 13px;
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast);

	strong {
		color: var(--color-main-text);
	}
}

.short__note.short__note--plain {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.short__note {
	margin-top: 8px;
	font-size: 13px;
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}

.short__details {
	display: flex;
	flex-direction: column;
	gap: 18px;
}

.short__section {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.short__heading {
	margin: 0;
	font-size: 15px;
	font-weight: 700;
}

.short__label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.short__cover {
	display: flex;
	align-items: center;
	gap: 14px;
}

.short__cover-image {
	flex: none;
	inline-size: 72px;
	aspect-ratio: 9 / 16;
	border-radius: 10px;
	object-fit: cover;
	background: var(--color-background-dark);
	box-shadow: var(--social-elevation-resting);
}

.short__cover-pick {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.short__range {
	inline-size: 100%;
	accent-color: #fe2c55;
}

.short__caption {
	inline-size: 100%;
	min-block-size: 84px;
	resize: vertical;
}

.short__counter {
	align-self: flex-end;
	font-size: 12px;
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast);

	&--near {
		color: var(--color-warning-text, #b36b00);
	}
}

.short__tags {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.short__tag {
	padding: 3px 10px;
	border: 1px solid var(--color-border-dark);
	border-radius: 999px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
	cursor: pointer;

	&:hover:not(:disabled) {
		border-color: var(--color-primary-element);
		background: var(--color-main-background);
		color: var(--color-primary-element);
	}

	&:disabled {
		opacity: .45;
		cursor: default;
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 1px;
	}
}

.short__progress {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.short__progress-label {
	font-size: 13px;
	font-weight: 600;
}

.short__progress-bar {
	block-size: 6px;
	border-radius: 6px;
	background: var(--color-background-dark);
	overflow: hidden;

	span {
		display: block;
		block-size: 100%;
		background: linear-gradient(90deg, #25f4ee, #fe2c55);
		transition: width .2s ease;
	}
}

.short__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.short__confirm {
	position: absolute;
	inset: 0;
	z-index: 5;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 14px;
	background: color-mix(in srgb, var(--color-main-background) 92%, transparent);
	font-size: 17px;
	font-weight: 600;
}

@media (prefers-reduced-motion: reduce) {
	.short__source,
	.short__shutter,
	.short__progress-bar span {
		transition: none;
	}

	.short__countdown,
	.short__rec-time--live::before {
		animation: none;
	}
}
</style>
