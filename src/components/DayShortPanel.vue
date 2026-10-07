<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="day-short">
		<!-- how much of a picture's time has gone; a video shows its own -->
		<div v-if="timed" class="day-short__progress" aria-hidden="true">
			<span class="day-short__fill" :style="{ width: Math.round(progress * 100) + '%' }" />
		</div>

		<div class="day-short__bottom">
			<p class="day-short__marks">
				<span class="day-short__mark">{{ t('social', '24h') }}</span>
				<span v-if="timeLeft" class="day-short__left">{{ timeLeft }}</span>
				<span v-if="own" class="day-short__views" :title="t('social', 'Who has seen it')">
					<IconEye :size="16" />
					{{ n('social', '%n view', '%n views', short.view_count ?? 0) }}
				</span>
				<NcButton
					v-if="own"
					variant="tertiary"
					class="day-short__delete"
					:ariaLabel="t('social', 'Delete this short')"
					:disabled="deleting"
					@click="remove">
					<template #icon>
						<IconDelete :size="20" />
					</template>
				</NcButton>
			</p>

			<router-link
				v-if="short.account"
				class="day-short__author"
				:to="{ name: 'profile', params: { account: short.account.acct } }">
				<img
					v-if="short.account.avatar"
					class="day-short__avatar"
					:src="short.account.avatar"
					alt="">
				<span class="day-short__name">{{ short.account.display_name || short.account.username }}</span>
			</router-link>
			<p v-if="short.caption" class="day-short__caption">
				{{ short.caption }}
			</p>

			<!-- somebody else's: an emoji for a reaction, a line for a reply -->
			<div v-if="!own" class="day-short__answer">
				<ul class="day-short__reactions">
					<li v-for="emoji in REACTIONS" :key="emoji">
						<button
							type="button"
							class="day-short__reaction"
							:disabled="answering"
							:aria-label="t('social', 'React with {emoji}', { emoji })"
							@click="react(emoji)">
							{{ emoji }}
						</button>
					</li>
				</ul>
				<form class="day-short__reply" @submit.prevent="reply">
					<input
						v-model="replyDraft"
						type="text"
						class="day-short__reply-field"
						:placeholder="t('social', 'Reply to this short…')"
						:disabled="answering"
						:maxlength="REPLY_MAX"
						@focus="setTyping(true)"
						@blur="setTyping(false)">
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

			<!-- the poster's own: what was said about it -->
			<div v-else-if="answers.length > 0" class="day-short__answers">
				<p v-for="said in answers" :key="said.id" class="day-short__answers-one">
					<strong>{{ said.account?.display_name || said.account?.username || t('social', 'Somebody') }}</strong>
					{{ said.content }}
				</p>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconDelete from 'vue-material-design-icons/Delete.vue'
import IconEye from 'vue-material-design-icons/Eye.vue'
import IconSend from 'vue-material-design-icons/Send.vue'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'

/** how often a picture's clock moves, in ms */
const TICK = 100

/**
 * The emoji offered for a reaction: a short row rather than a picker, since
 * a reaction is a tap and anything longer is a reply.
 */
export const REACTIONS = ['❤️', '🔥', '😂', '😮', '👏', '😢']

/** As long as the server will take, so the field stops where the server does. */
const REPLY_MAX = 500

/**
 * What a 24-hour short carries over its slide in the Shorts stack.
 *
 * The "24h" mark and the hours left; for the poster, how many have seen it,
 * what was said about it and a delete; for everybody else, a row of reactions
 * and a reply. While its slide is the one on screen it is marked seen (the
 * server's rule: never the poster's own), and a picture or a text card runs a
 * clock for the seconds its poster gave it and says `done` when they are up.
 * The clock stands still while `held`, and while a reply is being written,
 * which is also reported as `hold` so that a video stops under the writer.
 * The stack moves on; this component only says when.
 *
 * In the API a 24-hour short is a story; the routes are the stories routes.
 */
export default {
	name: 'DayShortPanel',

	components: {
		IconDelete,
		IconEye,
		IconSend,
		NcButton,
	},

	props: {
		/** the short, as the carousel gives it */
		short: {
			type: Object,
			required: true,
		},

		/** whether it is the reader's own */
		own: {
			type: Boolean,
			default: false,
		},

		/** whether its slide is the one on screen */
		active: {
			type: Boolean,
			default: false,
		},

		/** the stack holds it where it is */
		held: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['done', 'seen', 'deleted', 'hold'],

	data() {
		return {
			/** 0..1 of a picture's time */
			progress: 0,
			/** ms of a picture's time that have run */
			elapsed: 0,
			timer: null,
			/** a reply is being written */
			typing: false,
			deleting: false,
			answering: false,
			replyDraft: '',
			/** what has been said about the reader's own short */
			answers: [],
			/** the clock the hours left are read against */
			now: Date.now(),
			REACTIONS,
			REPLY_MAX,
		}
	},

	computed: {
		/** @return {boolean} whether it runs on a clock rather than as a video */
		timed() {
			return this.short.media?.type !== 'video'
		},

		/** @return {number} how long a picture is shown, in ms */
		duration() {
			return Math.max(3, Number(this.short.duration) || 5) * 1000
		},

		/** @return {string} the hours it has left, rounded up; '' when unknown */
		timeLeft() {
			const expires = Date.parse(this.short.expires_at ?? '')
			if (Number.isNaN(expires)) {
				return ''
			}
			const hours = Math.max(1, Math.ceil((expires - this.now) / 3600000))

			return t('social', '{hours}h left', { hours })
		},
	},

	watch: {
		active: {
			handler: 'begin',
			immediate: true,
		},

		'short.id': 'begin',
	},

	beforeUnmount() {
		this.stop()
	},

	methods: {
		t,
		n,

		/** Starts what being on screen means, or stops it when it no longer is. */
		begin() {
			this.stop()
			this.progress = 0
			this.elapsed = 0
			this.answers = []
			this.replyDraft = ''
			this.now = Date.now()
			if (!this.active) {
				return
			}

			this.markSeen()
			this.loadAnswers()
			if (this.timed) {
				this.startClock()
			}
		},

		startClock() {
			let last = Date.now()
			this.timer = window.setInterval(() => {
				const now = Date.now()
				if (!this.held && !this.typing) {
					this.elapsed += now - last
				}
				last = now
				this.progress = Math.min(1, this.elapsed / this.duration)
				if (this.elapsed >= this.duration) {
					this.stop()
					this.$emit('done')
				}
			}, TICK)
		},

		stop() {
			if (this.timer !== null) {
				window.clearInterval(this.timer)
				this.timer = null
			}
		},

		/** @param {boolean} on whether a reply is being written */
		setTyping(on) {
			this.typing = on
			this.$emit('hold', on)
		},

		/** @return {Promise<void>} */
		async markSeen() {
			if (this.short.seen || this.own) {
				return
			}

			try {
				await axios.post(generateUrl(`apps/social/api/v1/stories/${this.short.id}/seen`))
				this.$emit('seen', this.short.id)
			} catch (error) {
				// a view the server did not record is not worth interrupting for
				logger.debug('could not mark the short seen', { error })
			}
		},

		/** @return {Promise<void>} */
		async remove() {
			if (this.deleting) {
				return
			}

			this.deleting = true
			const short = this.short
			try {
				await axios.delete(generateUrl(`apps/social/api/v1/stories/${short.id}`))
				showSuccess(t('social', 'Short deleted'))
				this.$emit('deleted', short)
			} catch (error) {
				logger.error('could not delete the short', { error })
				showError(t('social', 'Could not delete the short'))
			} finally {
				this.deleting = false
			}
		},

		/**
		 * @param {string} emoji the one that was tapped
		 * @return {Promise<void>}
		 */
		async react(emoji) {
			await this.answer('react', { sid: this.short.id, reaction: emoji })
		},

		/** @return {Promise<void>} */
		async reply() {
			const caption = this.replyDraft.trim()
			if (caption === '') {
				return
			}
			if (await this.answer('comment', { sid: this.short.id, caption })) {
				this.replyDraft = ''
			}
		},

		/**
		 * @param {string} route `react` or `comment`
		 * @param {object} body what to send
		 * @return {Promise<boolean>} whether it went
		 */
		async answer(route, body) {
			if (this.answering) {
				return false
			}

			this.answering = true
			try {
				await axios.post(generateUrl(`apps/social/api/v1.2/stories/${route}`), body)
				showSuccess(t('social', 'Sent'))

				return true
			} catch (error) {
				logger.debug('could not answer the short', { error })
				showError(error.response?.data?.error ?? t('social', 'Could not send that'))

				return false
			} finally {
				this.answering = false
			}
		},

		/**
		 * What has been said about the reader's own short. Nobody else's: the
		 * server answers that with a 404.
		 *
		 * @return {Promise<void>}
		 */
		async loadAnswers() {
			if (!this.own) {
				return
			}

			// a reader who moves on while a slow request is in flight must not
			// be shown what was said about the short before
			const sid = this.short.id
			try {
				const { data } = await axios.get(
					generateUrl('apps/social/api/v1.2/stories/reactions'),
					{ params: { sid } },
				)
				if (sid !== this.short.id || !this.active) {
					return
				}

				this.answers = data.reactions ?? []
			} catch (error) {
				logger.debug('could not load what was said about the short', { error })
			}
		},
	},
}
</script>

<style scoped lang="scss">
.day-short {
	position: absolute;
	z-index: 1;
	inset: 0;
	/* the controls take presses; the rest lets them through to the slide */
	pointer-events: none;
	color: #fff;

	a,
	button,
	input,
	form,
	.day-short__answers {
		pointer-events: auto;
	}
}

.day-short__progress {
	position: absolute;
	inset-block-start: 0;
	inset-inline: 0;
	block-size: 3px;
	background: rgba(255, 255, 255, 0.35);
}

.day-short__fill {
	display: block;
	block-size: 100%;
	background: #fff;
	transition: width 0.1s linear;
}

.day-short__bottom {
	position: absolute;
	inset-block-end: 0;
	inset-inline: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
	/* room on the end for the stack's own buttons, which sit over this */
	padding-block: 16px;
	padding-inline: 16px 72px;
	background: linear-gradient(to top, rgba(0, 0, 0, 0.75), transparent);
}

.day-short__marks {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin: 0;
	font-size: 13px;
}

.day-short__mark {
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 999px);
	background: #fff;
	color: #000;
	font-weight: 700;
}

.day-short__left,
.day-short__views {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	color: rgba(255, 255, 255, 0.85);
}

.day-short__delete {
	color: #fff !important;
}

.day-short__author {
	display: flex;
	align-items: center;
	gap: 8px;
	color: inherit;
	font-weight: bold;
	text-decoration: none;
}

.day-short__avatar {
	inline-size: 36px;
	block-size: 36px;
	border-radius: 50%;
}

.day-short__caption {
	margin: 0;
	overflow-wrap: anywhere;
}

.day-short__answer {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.day-short__reactions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.day-short__reaction {
	padding: 6px 10px;
	border: none;
	border-radius: var(--border-radius-pill, 999px);
	background: rgba(255, 255, 255, 0.15);
	color: inherit;
	font-size: 22px;
	line-height: 1;
	cursor: pointer;

	&:hover,
	&:focus-visible {
		background: rgba(255, 255, 255, 0.3);
	}
}

.day-short__reply {
	display: flex;
	align-items: center;
	gap: 8px;
}

.day-short__reply-field {
	flex: 1 1 auto;
	min-inline-size: 0;
	padding: 6px 12px;
	border: 1px solid rgba(255, 255, 255, 0.4);
	border-radius: var(--border-radius-pill, 999px);
	background: rgba(0, 0, 0, 0.4);
	color: #fff;

	&::placeholder {
		color: rgba(255, 255, 255, 0.7);
	}
}

.day-short__answers {
	max-block-size: 25vh;
	overflow-y: auto;
}

.day-short__answers-one {
	margin: 0 0 4px;
}

@media (prefers-reduced-motion: reduce) {
	.day-short__fill {
		transition: none;
	}
}
</style>
