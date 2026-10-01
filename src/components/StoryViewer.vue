<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		:name="modalName"
		:hasPrevious="groupIndex > 0"
		:hasNext="groupIndex < groups.length - 1"
		size="full"
		class="story-viewer"
		@close="$emit('close')"
		@previous="previousGroup"
		@next="nextGroup">
		<div
			v-if="story"
			class="story-viewer__stage"
			@pointerdown="pause"
			@pointerup="resume"
			@pointercancel="resume">
			<!-- one segment per story of the account, the current one filling -->
			<ol class="story-viewer__progress" aria-hidden="true">
				<li
					v-for="(one, index) in group.stories"
					:key="one.id"
					class="story-viewer__segment"
					:class="{ 'story-viewer__segment--done': index < storyIndex }">
					<span
						v-if="index === storyIndex"
						class="story-viewer__fill"
						:style="{ width: Math.round(progress * 100) + '%' }" />
				</li>
			</ol>

			<header class="story-viewer__head">
				<router-link
					class="story-viewer__author"
					:to="{ name: 'profile', params: { account: group.account.acct } }"
					@click="$emit('close')">
					<ActorAvatar
						:actor="group.account"
						:size="32"
						:link="false"
						:hoverCard="false" />
					<span class="story-viewer__author-name">{{ group.account.display_name || group.account.username }}</span>
				</router-link>
				<span class="story-viewer__when">{{ ago(story) }}</span>
				<span v-if="isOwn" class="story-viewer__views" :title="t('social', 'Who has seen it')">
					<IconEye :size="16" />
					{{ n('social', '%n view', '%n views', story.view_count ?? 0) }}
				</span>
				<!-- the browser would not start with sound: say where it is -->
				<span v-if="isVideo && soundHeld" class="story-viewer__sound-hint" aria-hidden="true">
					{{ t('social', 'Tap for sound') }}
				</span>
				<button
					v-if="isVideo"
					type="button"
					class="story-viewer__sound"
					:aria-label="silent ? t('social', 'Unmute') : t('social', 'Mute')"
					@pointerdown.stop
					@pointerup.stop
					@click="toggleSound">
					<IconVolumeOff v-if="silent" :size="20" />
					<IconVolumeHigh v-else :size="20" />
				</button>
				<NcButton
					v-if="isOwn"
					variant="tertiary"
					class="story-viewer__delete"
					:ariaLabel="t('social', 'Delete this story')"
					:disabled="deleting"
					@click="remove">
					<template #icon>
						<IconDelete :size="20" />
					</template>
				</NcButton>
			</header>

			<div class="story-viewer__media">
				<!-- one element for every video story of the viewer, hidden while
				     a picture is up: WebKit lets an element play with sound only
				     once a gesture has allowed it, and a story that moves on by
				     itself is not a gesture -->
				<video
					v-show="isVideo"
					ref="video"
					class="story-viewer__video"
					:src="isVideo ? story.media.url : undefined"
					:aria-label="isVideo ? (story.media.description || story.caption || '') : undefined"
					playsinline
					@ended="next" />
				<img
					v-if="!isVideo && story.media"
					:key="story.id"
					class="story-viewer__image"
					:src="story.media.url"
					:alt="story.media.description || story.caption || ''">
				<p v-else-if="!isVideo" class="story-viewer__gone">
					{{ t('social', 'The picture of this story is gone.') }}
				</p>
			</div>

			<p v-if="story.caption" class="story-viewer__caption">
				{{ story.caption }}
			</p>

			<!-- what somebody says back: emoji for a reaction, a line for a
			     reply. Above the tap halves in the stacking order, or tapping
			     a control would also turn the page. -->
			<div v-if="!isOwn" class="story-viewer__answer">
				<ul class="story-viewer__reactions">
					<li v-for="emoji in REACTIONS" :key="emoji">
						<button
							type="button"
							class="story-viewer__reaction"
							:disabled="answering"
							:aria-label="t('social', 'React with {emoji}', { emoji })"
							@click="react(emoji)">
							{{ emoji }}
						</button>
					</li>
				</ul>
				<form class="story-viewer__reply" @submit.prevent="reply">
					<input
						v-model="replyDraft"
						type="text"
						class="story-viewer__reply-field"
						:placeholder="t('social', 'Reply to this story…')"
						:disabled="answering"
						:maxlength="REPLY_MAX"
						@focus="pause"
						@blur="resume">
					<NcButton
						variant="tertiary"
						type="submit"
						:disabled="answering || replyDraft.trim() === ''"
						:ariaLabel="t('social', 'Send')">
						<template #icon>
							<IconSend :size="20" />
						</template>
					</NcButton>
				</form>
			</div>

			<div v-else-if="answers.length > 0" class="story-viewer__answers">
				<p v-for="said in answers" :key="said.id" class="story-viewer__answers-one">
					<strong>{{ said.account?.display_name || said.account?.username || t('social', 'Somebody') }}</strong>
					{{ said.content }}
				</p>
			</div>

			<!-- the two halves of the stage: back and forward, as every story
			     player has them, without a control drawn over the picture -->
			<button
				type="button"
				class="story-viewer__tap story-viewer__tap--back"
				:aria-label="t('social', 'Previous story')"
				@click="previous" />
			<button
				type="button"
				class="story-viewer__tap story-viewer__tap--forward"
				:aria-label="t('social', 'Next story')"
				@click="next" />
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import IconDelete from 'vue-material-design-icons/Delete.vue'
import IconEye from 'vue-material-design-icons/Eye.vue'
import IconSend from 'vue-material-design-icons/Send.vue'
import IconVolumeHigh from 'vue-material-design-icons/VolumeHigh.vue'
import IconVolumeOff from 'vue-material-design-icons/VolumeOff.vue'
import ActorAvatar from './ActorAvatar.vue'
import { useSoundAutoplay } from '../composables/useSoundAutoplay.js'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { useAccountStore } from '../store/account.js'

/** how often the progress bar moves, in ms */
const TICK = 100

/**
 * The emoji offered for a reaction.
 *
 * A short row rather than a picker: a reaction to a story is a tap, and
 * anything longer is a reply, which is the field beside it.
 */
const REACTIONS = ['❤️', '🔥', '😂', '😮', '👏', '😢']

/** As long as the server will take, so the field stops where the server does. */
const REPLY_MAX = 500

/**
 * Plays the stories of one account after another, full screen.
 *
 * Each story shows for the seconds its poster gave it and is marked seen the
 * moment it is on screen — the server's rule, not this component's — so the
 * ring on the bar behind can follow. Holding the stage pauses; the halves of
 * it go back and forward; the modal's own arrows move between accounts. The
 * poster of a story sees how many people watched it and can take it down;
 * nobody else sees either.
 */
export default {
	name: 'StoryViewer',

	components: {
		ActorAvatar,
		IconDelete,
		IconEye,
		IconSend,
		IconVolumeHigh,
		IconVolumeOff,
		NcButton,
		NcModal,
	},

	props: {
		/** the bar's groups: `{account, stories, seen, own}` each */
		groups: {
			type: /** @type {import('vue').PropType<Array<{account: import('../types/Mastodon.js').Account, stories: object[], seen: boolean, own: boolean}>>} */ (Array),
			required: true,
		},

		/** which group to start on */
		start: {
			type: Number,
			default: 0,
		},
	},

	emits: ['close', 'seen', 'deleted'],

	setup() {
		const { muted, soundHeld, silent, playWithSound, toggleMute } = useSoundAutoplay()

		return { muted, soundHeld, silent, playWithSound, toggleMute }
	},

	data() {
		return {
			groupIndex: Math.min(Math.max(0, this.start), Math.max(0, this.groups.length - 1)),
			storyIndex: 0,
			/** 0..1 of the current story's time */
			progress: 0,
			timer: null,
			paused: false,
			deleting: false,
			answering: false,
			replyDraft: '',
			/** what has been said about the reader's own story on screen */
			answers: [],
			REACTIONS,
			REPLY_MAX,
		}
	},

	computed: {
		...mapStores(useAccountStore),

		/** @return {{account: import('../types/Mastodon.js').Account, stories: object[], seen: boolean, own: boolean}|undefined} */
		group() {
			return this.groups[this.groupIndex]
		},

		/** @return {object|undefined} */
		story() {
			return this.group?.stories[this.storyIndex]
		},

		/** @return {boolean} whether the story on screen is a video */
		isVideo() {
			return this.story?.media?.type === 'video'
		},

		/** @return {boolean} whether the story on screen is the reader's own */
		isOwn() {
			return Boolean(this.group?.own)
				|| this.story?.account?.acct === this.accountStore.currentAccount?.acct
		},

		/** @return {string} */
		modalName() {
			const name = this.group?.account?.display_name || this.group?.account?.username || ''

			return t('social', 'Stories of {name}', { name })
		},
	},

	watch: {
		story: {
			handler: 'begin',
			immediate: true,
		},
	},

	mounted() {
		window.addEventListener('keydown', this.onKey)
	},

	beforeUnmount() {
		this.stop()
		window.removeEventListener('keydown', this.onKey)
	},

	methods: {
		/**
		 * The keyboard works the stage.
		 *
		 * Left and right are the two halves of the stage, and space pauses --
		 * which the pointer could do by holding and the keyboard could not do at
		 * all. Down and up step between people, because a story belongs to
		 * somebody and the next person is a different axis from the next story.
		 *
		 * @param {KeyboardEvent} event the key
		 */
		onKey(event) {
			if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) {
				return
			}
			// somebody writing a reply is not paging
			if (/** @type {Element|null} */ (event.target)?.closest?.('input, textarea, [contenteditable]')) {
				return
			}

			const actions = {
				ArrowRight: () => this.next(),
				ArrowLeft: () => this.previous(),
				ArrowDown: () => this.nextGroup(),
				ArrowUp: () => this.previousGroup(),
				' ': () => (this.paused ? this.resume() : this.pause()),
				m: () => this.toggleSound(),
			}
			const action = actions[event.key]
			if (action) {
				event.preventDefault()
				action()
			}
		},

		t,
		n,

		/** Starts the clock on the story that just came on screen, and tells the server. */
		begin() {
			this.stop()
			this.progress = 0
			if (!this.story) {
				this.$emit('close')

				return
			}

			this.markSeen(this.story)
			this.replyDraft = ''
			this.loadAnswers()

			// a video runs for as long as it runs; a picture for the seconds
			// its poster chose
			if (this.isVideo) {
				// once the element has the new story's src; a story stepped to
				// while the stage is held stays still under the reader holding it
				this.$nextTick(() => {
					if (!this.paused) {
						this.playWithSound(this.videoElement())
					}
				})

				return
			}

			// the element stays for the next video story; hidden, it must not
			// go on playing the last one
			const video = this.$refs.video
			if (video instanceof HTMLVideoElement) {
				video.pause()
			}

			const duration = Math.max(3, Number(this.story.duration) || 5) * 1000
			const started = Date.now()
			let elapsedBeforePause = 0
			let pausedAt = null
			this.timer = window.setInterval(() => {
				if (this.paused) {
					pausedAt ??= Date.now()

					return
				}
				if (pausedAt !== null) {
					elapsedBeforePause += Date.now() - pausedAt
					pausedAt = null
				}
				const elapsed = Date.now() - started - elapsedBeforePause
				this.progress = Math.min(1, elapsed / duration)
				if (elapsed >= duration) {
					this.next()
				}
			}, TICK)
		},

		stop() {
			if (this.timer !== null) {
				window.clearInterval(this.timer)
				this.timer = null
			}
		},

		/**
		 * Holds the story where it is.
		 *
		 * The flag alone only held the clock a picture runs on. A video ran on
		 * its own, so holding the stage did nothing to one, and — worse —
		 * `ended` fired while somebody was still typing a reply and took the
		 * story they were answering off the screen.
		 */
		pause() {
			this.paused = true
			this.videoElement()?.pause()
		},

		/**
		 * Lets it run again, from where it was.
		 *
		 * Only what this component paused: a video the reader stopped with the
		 * player's own controls stays stopped, and one that has played to its
		 * end is not started again by a blur.
		 */
		resume() {
			this.paused = false

			const video = this.videoElement()
			if (video && video.paused && !video.ended) {
				// a browser may refuse to play without a gesture, or refuse
				// the sound; the story is still on screen either way
				this.playWithSound(video)
			}
		},

		/** The speaker, for this story and every video story after it. */
		toggleSound() {
			this.toggleMute(this.videoElement())
		},

		/** @return {HTMLVideoElement|null} the story's video, when it has one */
		videoElement() {
			const video = this.$refs.video

			return this.isVideo && video instanceof HTMLVideoElement ? video : null
		},

		/**
		 * @param {object} story the one on screen
		 * @return {Promise<void>}
		 */
		async markSeen(story) {
			if (story.seen || this.isOwn) {
				return
			}

			try {
				await axios.post(generateUrl(`apps/social/api/v1/stories/${story.id}/seen`))
				this.$emit('seen', story.id)
			} catch (error) {
				// a view the server did not record is not worth interrupting
				// the story for
				logger.debug('could not mark the story seen', { error })
			}
		},

		next() {
			if (!this.group) {
				this.$emit('close')

				return
			}
			if (this.storyIndex < this.group.stories.length - 1) {
				this.storyIndex++

				return
			}
			this.nextGroup()
		},

		previous() {
			if (this.storyIndex > 0) {
				this.storyIndex--

				return
			}
			if (this.groupIndex > 0) {
				this.groupIndex--
				this.storyIndex = Math.max(0, this.group.stories.length - 1)

				return
			}
			// the very first: start it over
			this.begin()
		},

		nextGroup() {
			if (this.groupIndex < this.groups.length - 1) {
				this.groupIndex++
				this.storyIndex = 0

				return
			}
			this.$emit('close')
		},

		previousGroup() {
			if (this.groupIndex > 0) {
				this.groupIndex--
				this.storyIndex = 0
			}
		},

		/** @return {Promise<void>} */
		async remove() {
			if (!this.story || this.deleting) {
				return
			}

			this.deleting = true
			const story = this.story
			try {
				await axios.delete(generateUrl(`apps/social/api/v1/stories/${story.id}`))
				showSuccess(t('social', 'Story deleted'))
				this.$emit('deleted', story)
				// the bar takes the story out of the group; if the group is now
				// empty the index points past it and begin() closes the viewer
				if (this.storyIndex >= (this.group?.stories.length ?? 0)) {
					this.storyIndex = Math.max(0, (this.group?.stories.length ?? 1) - 1)
				}
				this.begin()
			} catch (error) {
				logger.error('could not delete the story', { error })
				showError(t('social', 'Could not delete the story'))
			} finally {
				this.deleting = false
			}
		},

		/**
		 * @param {string} emoji the one that was tapped
		 * @return {Promise<void>}
		 */
		async react(emoji) {
			await this.answer('react', { sid: this.story.id, reaction: emoji })
		},

		/** @return {Promise<void>} */
		async reply() {
			const caption = this.replyDraft.trim()
			if (caption === '') {
				return
			}
			if (await this.answer('comment', { sid: this.story.id, caption })) {
				this.replyDraft = ''
			}
		},

		/**
		 * @param {string} route `react` or `comment`
		 * @param {object} body what to send
		 * @return {Promise<boolean>} whether it went
		 */
		async answer(route, body) {
			if (!this.story || this.answering) {
				return false
			}

			this.answering = true
			try {
				await axios.post(generateUrl(`apps/social/api/v1.2/stories/${route}`), body)
				showSuccess(t('social', 'Sent'))

				return true
			} catch (error) {
				logger.debug('could not answer the story', { error })
				showError(error.response?.data?.error ?? t('social', 'Could not send that'))

				return false
			} finally {
				this.answering = false
			}
		},

		/**
		 * What has been said about the reader's own story.
		 *
		 * Only for their own: somebody else's answers are not theirs to read,
		 * and the server says the same with a 404.
		 *
		 * @return {Promise<void>}
		 */
		async loadAnswers() {
			this.answers = []
			if (!this.story || !this.isOwn) {
				return
			}

			// which story these answers belong to: a reader advancing while a
			// slow request is in flight would otherwise be shown what was said
			// about the story before this one
			const sid = this.story.id
			try {
				const { data } = await axios.get(
					generateUrl('apps/social/api/v1.2/stories/reactions'),
					{ params: { sid } },
				)
				if (sid !== this.story?.id) {
					return
				}

				this.answers = data.reactions ?? []
			} catch (error) {
				// a story without its answers is still a story
				logger.debug('could not load what was said about the story', { error })
			}
		},

		/**
		 * @param {object} story one story
		 * @return {string} how long ago it went up, short
		 */
		ago(story) {
			const then = new Date(story.created_at)
			if (Number.isNaN(then.getTime())) {
				return ''
			}
			const minutes = Math.max(0, Math.round((Date.now() - then.getTime()) / 60000))
			if (minutes < 60) {
				return n('social', '%n minute ago', '%n minutes ago', Math.max(1, minutes))
			}

			return n('social', '%n hour ago', '%n hours ago', Math.round(minutes / 60))
		},
	},
}
</script>

<style scoped lang="scss">
.story-viewer__stage {
	position: relative;
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 60vh;
	color: #fff;
	background: #000;
	user-select: none;
}

.story-viewer__progress {
	display: flex;
	gap: 4px;
	margin: 0;
	padding: 8px 12px 0;
	list-style: none;
}

.story-viewer__segment {
	position: relative;
	flex: 1;
	height: 3px;
	overflow: hidden;
	border-radius: 2px;
	background: rgba(255, 255, 255, 0.35);

	&--done {
		background: #fff;
	}
}

.story-viewer__fill {
	display: block;
	height: 100%;
	background: #fff;
	transition: width 0.1s linear;
}

@media (prefers-reduced-motion: reduce) {
	.story-viewer__fill {
		transition: none;
	}
}

.story-viewer__head {
	position: relative;
	z-index: 2;
	display: flex;
	gap: 10px;
	align-items: center;
	padding: 10px 12px;
}

.story-viewer__author {
	display: inline-flex;
	gap: 8px;
	align-items: center;
	color: #fff;
	font-weight: bold;
}

.story-viewer__when,
.story-viewer__views {
	display: inline-flex;
	gap: 4px;
	align-items: center;
	color: rgba(255, 255, 255, 0.8);
	font-size: 13px;
}

.story-viewer__delete {
	margin-inline-start: auto;
	color: #fff !important;
}

// the same speaker as on Shorts, at the end of the head
.story-viewer__sound {
	display: flex;
	flex: none;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	margin-inline-start: auto;
	padding: 0;
	border: none;
	border-radius: 50%;
	background: rgba(0, 0, 0, 0.55);
	color: #fff;
	cursor: pointer;

	&:focus-visible {
		outline: 2px solid #fff;
		outline-offset: 2px;
	}
}

.story-viewer__sound-hint {
	margin-inline-start: auto;
	padding: 3px 10px;
	border-radius: 999px;
	background: rgba(0, 0, 0, 0.7);
	color: #fff;
	font-size: 12px;
	font-weight: 600;
	pointer-events: none;
	animation: story-viewer-hint-in 0.4s ease-out both;
}

// whichever comes first of hint, speaker and delete takes the free space
.story-viewer__sound-hint + .story-viewer__sound,
.story-viewer__sound + .story-viewer__delete {
	margin-inline-start: 0;
}

@keyframes story-viewer-hint-in {
	from {
		opacity: 0;
		transform: translateX(8px);
	}

	to {
		opacity: 1;
		transform: none;
	}
}

@media (prefers-reduced-motion: reduce) {
	.story-viewer__sound-hint {
		animation: none;
	}
}

.story-viewer__media {
	display: flex;
	flex: 1;
	align-items: center;
	justify-content: center;
	min-height: 0;
}

.story-viewer__image,
.story-viewer__video {
	max-width: 100%;
	max-height: 80vh;
	object-fit: contain;
}

.story-viewer__gone {
	color: rgba(255, 255, 255, 0.7);
}

.story-viewer__caption {
	position: relative;
	z-index: 2;
	margin: 0;
	padding: 12px 16px 18px;
	text-align: center;
	overflow-wrap: anywhere;
}

// the two halves: invisible, but not to a keyboard
.story-viewer__tap {
	position: absolute;
	top: 60px;
	bottom: 60px;
	width: 35%;
	border: 0;
	background: transparent;
	cursor: pointer;

	&--back {
		inset-inline-start: 0;
	}

	&--forward {
		inset-inline-end: 0;
		width: 65%;
	}

	&:focus-visible {
		outline: 2px solid #fff;
		outline-offset: -4px;
	}
}

.story-viewer__answer {
	position: relative;
	z-index: 2;
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px 12px 12px;
}

.story-viewer__reactions {
	display: flex;
	justify-content: center;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.story-viewer__reaction {
	border: none;
	border-radius: var(--border-radius-pill);
	background: rgba(255, 255, 255, 0.15);
	color: inherit;
	font-size: 22px;
	line-height: 1;
	padding: 6px 10px;
	cursor: pointer;

	&:hover,
	&:focus-visible {
		background: rgba(255, 255, 255, 0.3);
	}
}

.story-viewer__reply {
	display: flex;
	align-items: center;
	gap: 8px;
}

.story-viewer__reply-field {
	flex: 1 1 auto;
	min-width: 0;
	border: 1px solid rgba(255, 255, 255, 0.4);
	border-radius: var(--border-radius-pill);
	background: rgba(0, 0, 0, 0.4);
	color: #fff;
	padding: 6px 12px;

	&::placeholder {
		color: rgba(255, 255, 255, 0.7);
	}
}

.story-viewer__answers {
	position: relative;
	z-index: 2;
	max-height: 25vh;
	overflow-y: auto;
	padding: 8px 12px 12px;
}

.story-viewer__answers-one {
	margin: 0 0 4px;
}
</style>
