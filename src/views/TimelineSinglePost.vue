<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div ref="socialWrapper" class="social__wrapper">
		<NcButton
			class="thread__back"
			variant="tertiary"
			:aria-label="t('social', 'Back')"
			@click="goBack">
			<template #icon>
				<ArrowLeft :size="20" />
			</template>
			{{ t('social', 'Back') }}
		</NcButton>
		<!-- the three lists are one conversation; the spine says so -->
		<div class="thread" :class="{ 'thread--connected': hasThread }">
			<TimelineList
				v-if="timeline"
				class="thread__ancestors"
				:showParents="true"
				:reverseOrder="true" />
			<!-- a video gets a page about the video, with its channel and chapters -->
			<VideoHeader v-if="isVideo" :status="singlePost" />
			<TimelineEntry
				v-else-if="singlePost"
				ref="mainPost"
				class="main-post"
				:item="singlePost"
				type="single-post"
				element="div" />
			<!-- what a post's own page shows below it: the fine print, who
			     reacted, and the box for answering -->
			<div v-if="singlePost" class="main-post__under">
				<PostDetails :status="singlePost" />
				<PostReactedBy :status="singlePost" />
				<!-- one box per post, so each gets its own recipients, audience and draft -->
				<Composer
					v-if="canReply"
					v-show="!timelineStore.composerDisplayStatus"
					:key="singlePost.id"
					oneLine
					:listenForIntents="false"
					:inReplyTo="singlePost" />
			</div>
			<TimelineSkeleton v-else-if="postState === 'loading'" :count="1" />
			<!-- a server that did not answer has not said the post is gone -->
			<NcEmptyContent
				v-else-if="postState === 'failed'"
				:name="t('social', 'Could not load this post')"
				:description="t('social', 'Your connection or this server may be having trouble.')">
				<template #icon>
					<CommentRemoveOutline :size="20" />
				</template>
				<template #action>
					<NcButton variant="primary" @click="load">
						<template #icon>
							<Refresh :size="20" />
						</template>
						{{ t('social', 'Try again') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<!-- a deleted post is not an empty page: say so -->
			<NcEmptyContent
				v-else
				:name="t('social', 'This post is not available')"
				:description="t('social', 'It may have been deleted, or this server never received it.')">
				<template #icon>
					<CommentRemoveOutline :size="20" />
				</template>
			</NcEmptyContent>
			<TimelineList
				v-if="timeline"
				class="descendants thread__descendants"
				@settled="repliesSettled = true" />
			<!-- this instance may hold only part of a thread -->
			<p v-if="hiddenReplies > 0" class="thread__hidden">
				{{ hiddenRepliesText }}
			</p>
			<!-- the replies the conversation's author hid: there, behind a button -->
			<NcButton
				v-if="hiddenByAuthor > 0"
				class="thread__hidden-by-author"
				variant="tertiary"
				@click="showHiddenReplies">
				{{ n('social', 'Show %n reply hidden by the author', 'Show %n replies hidden by the author', hiddenByAuthor) }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import CommentRemoveOutline from 'vue-material-design-icons/CommentRemoveOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import PostDetails from '../components/PostDetails.vue'
import PostReactedBy from '../components/PostReactedBy.vue'
import TimelineEntry from '../components/TimelineEntry.vue'
import TimelineList from '../components/TimelineList.vue'
import TimelineSkeleton from '../components/TimelineSkeleton.vue'
import { isTracking } from '../services/interests.js'
import { signalNow } from '../services/interestTracker.js'
// its own chunk: most posts opened are not videos
const VideoHeader = defineAsyncComponent(() => import(/* webpackChunkName: "watch" */'../components/VideoHeader.vue'))
import { loadState } from '@nextcloud/initial-state'
import logger from '../services/logger.js'
import { setPageTitle } from '../services/pageTitle.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useTimelineStore } from '../store/timeline.js'
import { useServerData } from '../composables/useServerData.js'

const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'../components/Composer/Composer.vue'))

/** how often the counts of the opened post are asked for again */
const OPENED_COUNT_REFRESH_MS = 30 * 1000

export default {
	name: 'TimelineSinglePost',
	components: {
		Composer,
		ArrowLeft,
		CommentRemoveOutline,
		NcButton,
		NcEmptyContent,
		PostDetails,
		PostReactedBy,
		Refresh,
		TimelineEntry,
		TimelineList,
		TimelineSkeleton,
		VideoHeader,
	},

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	data() {
		return {
			/** whether the replies have been loaded, so loading is not counted as hidden replies */
			repliesSettled: false,
			/**
			 * Loading state of a post that was not in the store: 'failed' when
			 * the server did not answer, '' when it is there or gone.
			 *
			 * @type {''|'loading'|'failed'}
			 */
			postState: '',
			/** refreshes the one post shown outside either timeline list */
			countRefreshIntervalId: null,
			countRefreshInProgress: false,
		}
	},

	computed: {
		...mapStores(useAccountStore, useTimelineStore),
		singlePost() {
			return this.timelineStore.getSinglePost
		},

		/**
		 * Whether this post is a video: a federated `Video`, or a local post
		 * whose only attachment is a video.
		 *
		 * @return {boolean}
		 */
		isVideo() {
			if (!this.singlePost) {
				return false
			}

			if (this.singlePost.video) {
				return true
			}

			const media = this.singlePost.media_attachments || []

			return media.length === 1 && media[0].type === 'video'
		},

		/**
		 * Whose post this is, from the route.
		 *
		 * @return {string}
		 */
		account() {
			return String(this.$route.params.account ?? '').replace(/^@/, '')
		},

		timeline() {
			return this.timelineStore.getTimeline
		},

		parentsTimeline() {
			return this.timelineStore.getParentsTimeline
		},

		/**
		 * Whether there is a conversation here rather than a single post, which
		 * is when the spine is drawn.
		 *
		 * @return {boolean}
		 */
		hasThread() {
			return this.parentsTimeline.length > 0 || this.timeline.length > 0
		},

		/**
		 * Whether there is an account to reply with: a logged-out reader gets no box.
		 *
		 * @return {boolean}
		 */
		canReply() {
			return !this.serverData.public
		},

		/**
		 * How many direct replies this instance knows of but is not showing:
		 * ones from blocked or muted accounts, and ones that never reached
		 * this server.
		 *
		 * @return {number}
		 */
		hiddenReplies() {
			if (!this.repliesSettled || !this.singlePost) {
				return 0
			}

			const known = this.singlePost.replies_count ?? 0
			const shown = this.timeline.filter((status) => status.in_reply_to_id === this.singlePost.id).length

			// the ones the author hid are said by the button below
			return Math.max(0, known - shown - this.hiddenByAuthor)
		},

		/** @return {number} how many replies the conversation's author hid, not shown */
		hiddenByAuthor() {
			return this.timelineStore.hiddenByAuthor
		},

		/** @return {string} */
		hiddenRepliesText() {
			return n(
				'social',
				'%n reply is not shown here. It may be from an account you blocked or muted, or from a server this one has not heard from.',
				'%n replies are not shown here. They may be from accounts you blocked or muted, or from servers this one has not heard from.',
				this.hiddenReplies,
			)
		},
	},

	watch: {
		'$route.params.id': 'load',

		// the author names the page: a post has no title of its own
		singlePost: {
			immediate: true,
			handler(post) {
				const author = post?.account?.display_name || post?.account?.acct || ''
				setPageTitle(author === '' ? t('social', 'Post') : t('social', 'Post by {name}', { name: author }))
			},
		},

		parentsTimeline(parents, previousValue) {
			// beforeMount() resets the timeline, so this fires during the first render's
			// pre-flush, before the template refs exist.
			if (parents.length === 0 || previousValue.length !== 0 || !this.$refs.socialWrapper
				|| document.activeElement?.closest('input, textarea, [contenteditable="true"]')) {
				return
			}

			if (/** @type {HTMLElement} */ (this.$refs.socialWrapper).parentElement?.scrollTop !== 0) {
				return
			}

			this.$nextTick(() => /** @type {{$el?: HTMLElement}|undefined} */ (this.$refs.mainPost)?.$el?.scrollIntoView({
				behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
				block: 'center',
			}))
		},
	},

	async beforeMount() {
		await this.load()
	},

	mounted() {
		// The main post is outside both lists' visible-card refreshers, so keep
		// its counts fresh here; only the opening read counts as a view.
		this.countRefreshIntervalId = window.setInterval(this.refreshOpenedPostCounts, OPENED_COUNT_REFRESH_MS)
		document.addEventListener('visibilitychange', this.refreshOpenedPostCounts)
	},

	unmounted() {
		clearInterval(this.countRefreshIntervalId)
		document.removeEventListener('visibilitychange', this.refreshOpenedPostCounts)
	},

	methods: {

		t,
		n,

		/** The replies the conversation's author hid, shown after all. */
		async showHiddenReplies() {
			try {
				await this.timelineStore.showHiddenReplies()
			} catch (error) {
				logger.error('Could not show the hidden replies', { error })
			}
		},

		/** Refresh the detail post as soon as its page is shown again. */
		refreshOpenedPostCounts() {
			if (document.visibilityState === 'hidden' || !this.singlePost?.id || this.countRefreshInProgress) {
				return
			}

			const id = String(this.singlePost.id)
			this.countRefreshInProgress = true
			this.timelineStore.refreshCounts([id])
				.catch((error) => {
					logger.debug('The opened post counts were not refreshed', { error, id })
				})
				.finally(() => {
					this.countRefreshInProgress = false
				})
		},

		/**
		 * Back to where the reader came from in the app, or to the home timeline
		 * when the post was opened from outside (`history.state.back` is unset),
		 * so the button never leaves the app.
		 */
		goBack() {
			if (window.history.state?.back) {
				this.$router.back()

				return
			}

			this.$router.push({ name: 'timeline', params: { type: 'home' } })
		},

		/**
		 * Opens the conversation the route names. Also called when the route
		 * changes to another post, since this component is reused.
		 */
		async load() {
			// read before the reset: changeTimelineType prunes the status index
			const singlePost = this.timelineStore.getPostFromTimeline(String(this.$route.params.id)) ?? this.postFromInitialState()

			// The address may hold the client API id or the last segment of the
			// ActivityPub id; `/context` and everything below need the former.
			const id = String(singlePost?.id ?? this.$route.params.id ?? '')

			this.timelineStore.changeTimelineType({
				type: 'single-post',
				params: {
					account: this.account,
					id,
					type: 'single-post',
					singlePost: id,
				},
			})
			this.timelineStore.addToStatuses(singlePost)

			// nothing had loaded this post (a shared link, a reload, a Discover
			// tile), and `/context` never returns the post itself
			if (singlePost === undefined || singlePost === null) {
				const routeId = String(this.$route.params.id)
				/** @type {''|'failed'} */
				let state = ''
				this.postState = 'loading'
				try {
					await this.timelineStore.fetchStatus(routeId, true)
				} catch (error) {
					logger.debug('Could not load a single status', { error, id: routeId })
					state = 'failed'
				}
				// the reader went on to another post while this one was asked for
				if (String(this.$route.params.id) !== routeId) {
					return
				}
				this.postState = state
				if (state === 'failed') {
					return
				}
			} else {
				this.postState = ''
				// a timeline copy can be minutes old: keep it on screen while
				// fetching the current counts
				this.timelineStore.fetchStatus(id, true).catch((error) => {
					logger.debug('Could not refresh an opened status', { error, id })
				})
			}

			this.reportOpened()

			// loaded for the author card; the answer is not read here
			const fetchMethod = this.serverData.public ? 'fetchPublicAccountInfo' : 'fetchAccountInfo'
			await this.accountStore[fetchMethod](this.account)
		},

		/** Tells For you the reader opened this post, when it is learning. */
		reportOpened() {
			const post = this.timelineStore.getSinglePost
			if (!post || this.serverData.public || !isTracking(this.serverData.interests)) {
				return
			}

			const me = this.accountStore.currentAccount?.acct
			signalNow(post, 'open', 'detail', (status) => me !== undefined && status.account?.acct === me)
		},

		/**
		 * The post the server rendered into the page, when the address names it
		 * by its client id or the last segment of its ActivityPub id. Null when
		 * absent, as for a deleted post (`loadState` would throw).
		 *
		 * @return {object|null}
		 */
		postFromInitialState() {
			let item
			try {
				item = loadState('social', 'item')
			} catch (error) {
				logger.debug('No post in the initial state', { error })
				return null
			}

			const id = String(this.$route.params.id ?? '')
			const named = id !== '' && (
				String(item?.id ?? '') === id
				|| String(item?.uri ?? '').endsWith('/' + id)
				|| String(item?.url ?? '').endsWith('/' + id)
			)

			return named ? item : null
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/layout.scss' as layout;

.social__wrapper {
	/* as wide as the posts in the feed the post was opened from */
	--social-page-width: var(--social-reading-page);
	padding-bottom: 25%;
}

.thread__back {
	margin-bottom: 8px;
}

/* beside the sidebar's floating toggle rather than under it */
@include layout.below(layout.$folded) {
	.thread__back {
		margin-inline-start: var(--default-clickable-area, 44px);
	}
}

/*
 * The lists keep a gutter inside them; pulled out by it, their posts share
 * the main post's edge. Scoped under `.thread` because `.social__timeline` is
 * another component's root, where an equally specific rule would win by
 * bundle order.
 */
.thread .social__timeline {
	margin-inline: calc(-1 * var(--social-column-gutter));
}

/**
 * The spine: a line behind the avatars joining the posts of a conversation,
 * drawn only when there is more than one post.
 */
.thread {
	position: relative;

	&--connected::before {
		content: '';
		position: absolute;
		top: 12px;
		bottom: 12px;
		inset-inline-start: 18px;
		width: 2px;
		border-radius: 1px;
		background: var(--color-border);
		z-index: 0;
	}

	&__ancestors,
	&__descendants {
		position: relative;
		z-index: 1;
	}
}

/* No card of its own: the post card is the frame. */
.main-post {
	position: relative;
	z-index: 1;
	margin: 16px 0;
}

/* the fine print, the faces and the reply box share the post's edge, as the
   Back button above it does */
.main-post__under {
	margin-bottom: 16px;
}

.thread__hidden {
	margin: 8px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

/* a phone: the lists keep their gutter, the fine print is set in to the
   card's padding, and the spine runs down the middle of the face inside it */
@include layout.below(layout.$phone) {
	.thread .social__timeline {
		margin-inline: 0;
	}

	.thread--connected::before {
		inset-inline-start: 29px;
	}

	.main-post__under,
	.thread__hidden {
		padding-inline-start: 12px;
	}
}

#app-content {
	position: relative;
}
</style>
