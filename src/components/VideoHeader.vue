<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="watch">
		<PostAttachment
			class="watch__player"
			mediaFirst
			:to="null"
			:video="video"
			:attachments="status.media_attachments || []" />

		<h1 class="watch__title">
			{{ title }}
			<span v-if="video.live" class="watch__live">{{ t('social', 'Live') }}</span>
		</h1>

		<p class="watch__facts">
			<span v-if="video.views !== undefined">
				{{ n('social', '%n view', '%n views', video.views) }}
			</span>
			<span v-if="video.likes !== undefined">
				{{ n('social', '%n like', '%n likes', video.likes) }}
			</span>
			<span v-if="dislikes">
				{{ n('social', '%n dislike', '%n dislikes', dislikes) }}
			</span>
			<span v-if="video.category">{{ video.category }}</span>
			<span v-if="video.language">{{ video.language }}</span>
			<span v-if="video.licence">{{ video.licence }}</span>
		</p>

		<!-- the channel, which is what a reader subscribes to: on PeerTube a
		     video is listed under it rather than under the person -->
		<div class="watch__channel">
			<ActorAvatar :actor="status.account" />
			<router-link
				class="watch__channel-link"
				:to="{ name: 'profile', params: { account: status.account.acct } }">
				<span class="watch__channel-name">
					{{ status.account.display_name || status.account.username }}
				</span>
				<span class="watch__channel-acct">{{ status.account.acct }}</span>
			</router-link>
			<FollowButton :uid="status.account.acct" />
			<!-- PeerTube's other counter, and only here: a dislike button
			     under a written post is a product this app is not, and a
			     `Dislike` sent to a server with no model for it is dropped -->
			<NcButton
				class="watch__dislike"
				:title="disliked ? t('social', 'Undo dislike') : t('social', 'Dislike')"
				:aria-label="disliked ? t('social', 'Undo dislike') : t('social', 'Dislike')"
				:aria-pressed="disliked ? 'true' : 'false'"
				:disabled="sending"
				variant="tertiary"
				@click="toggleDislike">
				<template #icon>
					<ThumbDown :size="20" :fillColor="disliked ? 'var(--color-primary)' : 'var(--color-main-text)'" />
				</template>
			</NcButton>
		</div>

		<div v-if="chapters.length" class="watch__chapters">
			<h2 class="watch__heading">
				{{ t('social', 'Chapters') }}
			</h2>
			<ol class="watch__chapter-list">
				<li v-for="chapter in chapters" :key="chapter.start">
					<button class="watch__chapter" type="button" @click="seekTo(chapter.start)">
						<span class="watch__chapter-time">{{ clock(chapter.start) }}</span>
						<span class="watch__chapter-title">{{ chapter.title }}</span>
					</button>
				</li>
			</ol>
		</div>

		<p v-if="video.support" class="watch__support">
			{{ video.support }}
		</p>

		<p v-if="video.download === false" class="watch__note">
			{{ t('social', 'The author has asked that this video not be downloaded.') }}
		</p>
	</div>
</template>

<script>
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ThumbDown from 'vue-material-design-icons/ThumbDown.vue'
import ActorAvatar from './ActorAvatar.vue'
import FollowButton from './FollowButton.vue'
import PostAttachment from './PostAttachment.vue'
import { useTimelineStore } from '../store/timeline.js'

/**
 * A video's own page, rather than a post with a rectangle in it.
 *
 * It is the same route a post opens on, not a second one somebody has to find:
 * every link to a video already leads here, and what changes is what the page
 * says about it. Underneath, the conversation is the conversation — a video's
 * comments are replies, and this app already has replies.
 *
 * What is on it is what a `Video` object actually carries and a timeline card
 * has no room for: the title as a heading (a `Note` has no title, so the reader
 * used to get it as the first line of a paragraph), the counters, the category
 * and licence, the chapters as something to press, and the line the author
 * wrote asking for support.
 */
export default {
	name: 'VideoHeader',

	components: {
		ActorAvatar,
		FollowButton,
		NcButton,
		PostAttachment,
		ThumbDown,
	},

	props: {
		status: {
			type: /** @type {import('vue').PropType<object>} */ (Object),
			required: true,
		},
	},

	setup() {
		return { timelineStore: useTimelineStore() }
	},

	data() {
		return {
			/** whether a dislike of ours is in flight */
			sending: false,
		}
	},

	computed: {
		/** @return {object} what the post said about the video */
		video() {
			return this.status.video ?? {}
		},

		/**
		 * How many people disliked it.
		 *
		 * The post's own count where this server has one — it counts the
		 * `Dislike` activities it received — and the number PeerTube stated
		 * otherwise, which covers everybody who watched it there.
		 *
		 * @return {number}
		 */
		dislikes() {
			return this.status.dislikes_count || this.video.dislikes || 0
		},

		/** @return {boolean} whether this reader has disliked it */
		disliked() {
			return this.status.disliked === true
		},

		/**
		 * What it is called.
		 *
		 * The object's own `name` where there is one. A video posted from here
		 * has no separate title — the composer does not ask for one — so the
		 * first line of the post stands in, which is where somebody writing
		 * about a video puts its name.
		 *
		 * @return {string}
		 */
		title() {
			if (this.video.title) {
				return this.video.title
			}

			const text = (this.status.content || '').replace(/<[^>]*>/g, '\n')

			return (text.split('\n').find((line) => line.trim() !== '') || '').trim()
		},

		/** @return {Array<{title: string, start: number}>} */
		chapters() {
			return Array.isArray(this.video.chapters) ? this.video.chapters : []
		},
	},

	methods: {
		t,
		n,

		/**
		 * Sends the dislike, or takes it back.
		 *
		 * @return {Promise<void>} when the server has answered
		 */
		async toggleDislike() {
			if (this.sending) {
				return
			}

			this.sending = true
			try {
				await (this.disliked
					? this.timelineStore.postUndislike({ status: this.status })
					: this.timelineStore.postDislike({ status: this.status }))
			} finally {
				this.sending = false
			}
		},

		/**
		 * @param {number} seconds a moment in the video
		 * @return {string} it as a clock, `1:02:03` or `2:03`
		 */
		clock(seconds) {
			const whole = Math.max(0, Math.floor(seconds))
			const parts = [Math.floor(whole / 60) % 60, whole % 60]
			if (whole >= 3600) {
				parts.unshift(Math.floor(whole / 3600))
			}

			return parts
				.map((part, index) => (index === 0 ? String(part) : String(part).padStart(2, '0')))
				.join(':')
		},

		/**
		 * Moves the player to a chapter.
		 *
		 * The element is found rather than passed down: the player is several
		 * components below this one and threading a ref through all of them to
		 * seek would tie each of them to this page.
		 *
		 * @param {number} seconds where to go
		 */
		seekTo(seconds) {
			const player = this.$el?.querySelector('video')
			if (!player) {
				return
			}

			player.currentTime = seconds
			// a chapter pressed is a chapter somebody wants to watch
			player.play().catch(() => {})
		},
	},
}
</script>

<style scoped lang="scss">
.watch__title {
	margin-block: 12px 4px;
	font-size: 20px;
	line-height: 1.3;
}

.watch__live {
	display: inline-block;
	margin-inline-start: 8px;
	padding: 0 6px;
	border-radius: var(--border-radius);
	background: var(--color-error);
	color: var(--color-primary-text);
	font-size: 12px;
	vertical-align: middle;
}

.watch__facts {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	color: var(--color-text-maxcontrast);
	margin: 0 0 12px;
}

.watch__channel {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	padding-block: 12px;
	border-block: 1px solid var(--color-border);
}

.watch__channel-link {
	display: flex;
	flex-direction: column;
	text-decoration: none;
	color: inherit;
	margin-inline-end: auto;
}

.watch__channel-name {
	font-weight: bold;
}

.watch__channel-acct {
	color: var(--color-text-maxcontrast);
}

.watch__heading {
	font-size: 15px;
	margin-block: 16px 4px;
}

.watch__chapter-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.watch__chapter {
	display: flex;
	gap: 8px;
	width: 100%;
	padding: 4px 8px;
	border: none;
	border-radius: var(--border-radius);
	background: transparent;
	color: inherit;
	text-align: start;
	cursor: pointer;

	&:hover,
	&:focus-visible {
		background: var(--color-background-hover);
	}
}

.watch__chapter-time {
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast);
	min-width: 4em;
}

.watch__support,
.watch__note {
	color: var(--color-text-maxcontrast);
	margin-block: 12px 0;
	overflow-wrap: anywhere;
}
</style>
