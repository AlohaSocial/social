<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="watch">
		<VideoPlayer class="watch__player" :status="status" />
		<!-- what the post carries beside the video, which plays above -->
		<PostAttachment
			v-if="besideVideo.length"
			class="watch__beside"
			mediaFirst
			:to="null"
			:attachments="besideVideo" />

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
			<span v-if="dislikes && !hidesCounts">
				{{ n('social', '%n dislike', '%n dislikes', dislikes) }}
			</span>
			<span v-if="video.category">{{ video.category }}</span>
			<span v-if="video.language">{{ video.language }}</span>
			<span v-if="video.licence">{{ video.licence }}</span>
		</p>

		<!-- the channel, which is what a reader subscribes to on PeerTube -->
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
			<!-- a visitor has no account to like, boost or dislike with -->
			<template v-if="signedIn">
				<div
					class="watch__action"
					:title="likeRefusal || undefined">
					<NcButton
						class="watch__like"
						:title="likeRefusal || (isLiked ? t('social', 'Undo Like') : t('social', 'Like'))"
						:aria-label="isLiked ? t('social', 'Undo Like') : t('social', 'Like')"
						:aria-pressed="isLiked ? 'true' : 'false'"
						:disabled="sending || (!canLike && !isLiked)"
						variant="tertiary"
						@click="toggleLike">
						<template #icon>
							<Heart v-if="isLiked" :size="20" fillColor="var(--color-element-error)" />
							<HeartOutline v-else :size="20" />
						</template>
					</NcButton>
					<RollingCount v-if="!hidesCounts" :count="status.favourites_count || 0" />
				</div>
				<div
					class="watch__action"
					:title="boostRefusal || undefined">
					<NcButton
						class="watch__boost"
						:title="boostRefusal || (isBoosted ? t('social', 'Undo boost') : t('social', 'Boost'))"
						:aria-label="isBoosted ? t('social', 'Undo boost') : t('social', 'Boost')"
						:aria-pressed="isBoosted ? 'true' : 'false'"
						:disabled="sending || (!canBoost && !isBoosted)"
						variant="tertiary"
						@click="toggleBoost">
						<template #icon>
							<Repeat :size="20" :fillColor="isBoosted ? 'var(--color-primary)' : 'var(--color-main-text)'" />
						</template>
					</NcButton>
					<RollingCount v-if="!hidesCounts" :count="status.reblogs_count || 0" />
				</div>
				<!-- PeerTube's other counter, only on videos: a server with no model for
				     a `Dislike` drops it -->
				<div class="watch__action">
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
			</template>
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
import Heart from 'vue-material-design-icons/Heart.vue'
import HeartOutline from 'vue-material-design-icons/HeartOutline.vue'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import ThumbDown from 'vue-material-design-icons/ThumbDown.vue'
import ActorAvatar from './ActorAvatar.vue'
import FollowButton from './FollowButton.vue'
import PostAttachment from './PostAttachment.vue'
import RollingCount from './RollingCount.vue'
import VideoPlayer from './VideoPlayer.vue'
import { playedAttachmentOf } from '../services/videoSource.js'
import { useTimelineStore } from '../store/timeline.js'
import { useSettingsStore } from '../store/settings.js'
import { allowedByAuthor, isShareable } from '../utils/interactionPolicy.js'
import { formatTime } from '../utils/shortVideo.js'

/**
 * A video's own page, on the same route a post opens on, with the
 * conversation under it as replies.
 *
 * It shows what a `Video` object carries and a timeline card has no room for:
 * the title as a heading, the counters, category and licence, the chapters
 * to press, and the author's support line.
 */
export default {
	name: 'VideoHeader',

	components: {
		ActorAvatar,
		FollowButton,
		Heart,
		HeartOutline,
		NcButton,
		PostAttachment,
		Repeat,
		RollingCount,
		ThumbDown,
		VideoPlayer,
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
			/** whether a like, boost or dislike of ours is in flight */
			sending: false,
		}
	},

	computed: {
		/** @return {boolean} whether there is an account here to react with */
		signedIn() {
			return !useSettingsStore().getServerData.public
		},

		/** @return {boolean} whether the reader has the like, boost and follower numbers hidden */
		hidesCounts() {
			return useSettingsStore().hidesCounts
		},

		/** @return {object} what the post said about the video */
		video() {
			return this.status.video ?? {}
		},

		/**
		 * How many people disliked it: the post's own count where this server has
		 * one (the `Dislike` activities it received), PeerTube's stated number
		 * otherwise.
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

		/** @return {boolean} whether this reader has liked it */
		isLiked() {
			return this.status.favourited === true
		},

		/** @return {boolean} whether this reader has boosted it */
		isBoosted() {
			return this.status.reblogged === true
		},

		/** @return {boolean} whether the author allows this video to be liked */
		canLike() {
			return allowedByAuthor(this.status, 'like')
		},

		/** @return {boolean} whether this video may be boosted */
		canBoost() {
			return isShareable(this.status) && allowedByAuthor(this.status, 'boost')
		},

		/** @return {string} why the like is not on offer; empty when it is */
		likeRefusal() {
			return this.canLike || this.isLiked
				? ''
				: t('social', 'The author does not allow likes of this post')
		},

		/** @return {string} why the boost is not on offer; empty when it is or can be undone */
		boostRefusal() {
			if (this.canBoost || this.isBoosted) {
				return ''
			}

			return isShareable(this.status)
				? t('social', 'The author does not allow boosts of this post')
				: t('social', 'Only public and unlisted posts can be boosted')
		},

		/**
		 * What it is called: the object's own `name`, or else the first line of
		 * the post.
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

		/**
		 * The photos and files of the post other than the video the player plays,
		 * so nothing is lost and the video is not shown twice.
		 *
		 * @return {object[]}
		 */
		besideVideo() {
			const played = playedAttachmentOf(this.status)

			return (this.status.media_attachments || []).filter((item) => item !== played)
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
		 * @param {() => Promise<unknown>} action the request to send
		 * @return {Promise<void>} when the server has answered
		 */
		async send(action) {
			if (this.sending) {
				return
			}

			this.sending = true
			try {
				await action()
			} finally {
				this.sending = false
			}
		},

		/** Sends a like, or takes it back. */
		async toggleLike() {
			await this.send(() => (this.isLiked
				? this.timelineStore.postUnlike({ status: this.status })
				: this.timelineStore.postLike({ status: this.status })))
		},

		/** Sends a boost, or takes it back. */
		async toggleBoost() {
			await this.send(() => (this.isBoosted
				? this.timelineStore.postUnBoost({ status: this.status })
				: this.timelineStore.postBoost({ status: this.status })))
		},

		/** Sends the dislike, or takes it back. */
		async toggleDislike() {
			await this.send(() => (this.disliked
				? this.timelineStore.postUndislike({ status: this.status })
				: this.timelineStore.postDislike({ status: this.status })))
		},

		/**
		 * @param {number} seconds a moment in the video
		 * @return {string} it as a clock, `1:02:03` or `2:03`
		 */
		clock(seconds) {
			return formatTime(seconds)
		},

		/**
		 * Moves the player to a chapter. The element is looked up, as the player is
		 * several components below this one.
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

.watch__action {
	display: flex;
	align-items: center;
	gap: 4px;
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
