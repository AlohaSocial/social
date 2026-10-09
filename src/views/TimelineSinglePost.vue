<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div ref="socialWrapper" class="social__wrapper">
		<!-- a post opened from a timeline is a place the reader went into, and
		     the way out of it was the browser's own button or the sidebar -->
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
			<!-- a video gets a page about the video: the same route, because
			     every link to one already leads here, with a heading, the
			     channel to subscribe to and its chapters instead of a card -->
			<VideoHeader v-if="isVideo" :status="singlePost" />
			<TimelineEntry
				v-else-if="singlePost"
				ref="mainPost"
				class="main-post"
				:item="singlePost"
				type="single-post"
				element="div" />
			<!-- what a post's own page can say that a card in a list should
			     not: the fine print, who reacted, and the box for answering -->
			<div v-if="singlePost" class="main-post__under">
				<PostDetails :status="singlePost" />
				<PostReactedBy :status="singlePost" />
				<Composer v-if="canReply" :inReplyTo="singlePost" />
			</div>
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
			<!-- a thread this instance holds only part of should say so rather
			     than present what it has as the whole of it -->
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
import { translate, translatePlural } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import CommentRemoveOutline from 'vue-material-design-icons/CommentRemoveOutline.vue'
import PostDetails from '../components/PostDetails.vue'
import PostReactedBy from '../components/PostReactedBy.vue'
import TimelineEntry from '../components/TimelineEntry.vue'
import TimelineList from '../components/TimelineList.vue'
import { isTracking } from '../services/interests.js'
import { signalNow } from '../services/interestTracker.js'
// its own chunk: it brings a player and a follow button, and almost every post
// opened is not a video
const VideoHeader = defineAsyncComponent(() => import(/* webpackChunkName: "watch" */'../components/VideoHeader.vue'))
import { loadState } from '@nextcloud/initial-state'
import eventBus from '../services/eventBus.js'
import logger from '../services/logger.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useTimelineStore } from '../store/timeline.js'
import { useServerData } from '../composables/useServerData.js'

const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'../components/Composer/Composer.vue'))

export default {
	name: 'TimelineSinglePost',
	components: {
		ArrowLeft,
		Composer,
		CommentRemoveOutline,
		NcButton,
		NcEmptyContent,
		PostDetails,
		PostReactedBy,
		TimelineEntry,
		TimelineList,
		VideoHeader,
	},

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	data() {
		return {
			/**
			 * Whether the replies have been asked for and answered. Until they
			 * have, every reply is a reply this page has not drawn, and saying
			 * so while they are on their way would be counting the loading.
			 */
			repliesSettled: false,
			/** the composer-reply handler, kept so only this one is removed */
			onComposerReply: null,
		}
	},

	computed: {
		...mapStores(useAccountStore, useTimelineStore),
		singlePost() {
			return this.timelineStore.getSinglePost
		},

		/**
		 * Whether this post is a video, and so gets a page about the video.
		 *
		 * The `video` block is what a federated `Video` said about itself; a
		 * post written here whose only attachment is a video is one too, and
		 * gets the heading and the channel without the counters it has none of.
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

		composerDisplayStatus() {
			return this.timelineStore.getComposerDisplayStatus
		},

		/**
		 * Whose post this is. The route says so; this used to be read off
		 * window.location by splitting the href and slicing a '@' off the
		 * second-to-last segment, which broke on any URL shape but one.
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
		 * Whether there is a conversation here, or only the one post.
		 *
		 * The spine is a line drawn behind the avatars to say that what it runs
		 * through belongs together. With nothing above the post and nothing
		 * below it there is nothing to say, and the line is a mark beside a
		 * single card with no other end.
		 *
		 * @return {boolean}
		 */
		hasThread() {
			return this.parentsTimeline.length > 0 || this.timeline.length > 0
		},

		/**
		 * Whether there is somebody here to write a reply. A post read without
		 * logging in is read from a page with no account behind it, and a box
		 * that cannot send is worse than no box.
		 *
		 * @return {boolean}
		 */
		canReply() {
			return !this.serverData.public
		},

		/**
		 * How many replies this instance knows of but is not showing.
		 *
		 * `replies_count` is what the post's own instance said plus what has
		 * arrived here, so the two differ honestly: a reply from an account the
		 * reader blocked or muted is filtered out of the thread but still
		 * counted, and a remote thread is only ever as complete as what has
		 * reached this server. Counted against the *direct* replies, since that
		 * is what the number on the post counts — a reply to a reply is in the
		 * thread below without being in it.
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
			return translatePlural(
				'social',
				'%n reply is not shown here. It may be from an account you blocked or muted, or from a server this one has not heard from.',
				'%n replies are not shown here. They may be from accounts you blocked or muted, or from servers this one has not heard from.',
				this.hiddenReplies,
			)
		},
	},

	watch: {
		'$route.params.id': 'load',
		parentsTimeline(_, previousValue) {
			// beforeMount() resets the timeline, so this fires during the first render's
			// pre-flush, before the template refs exist.
			if (previousValue.length !== 0 || !this.$refs.socialWrapper) {
				return
			}

			if (/** @type {HTMLElement} */ (this.$refs.socialWrapper).parentElement?.scrollTop !== 0) {
				return
			}

			this.$nextTick(() => /** @type {{$el?: HTMLElement}|undefined} */ (this.$refs.mainPost)?.$el?.scrollIntoView({ behavior: 'smooth', block: 'center' }))
		},
	},

	async beforeMount() {
		// Keep the handler so unmounted() removes only this one — a bare
		// eventBus.off('composer-reply') would also detach the Composer's.
		this.onComposerReply = (item) => {
			this.$nextTick(() => {
				/** @type {HTMLElement|undefined} */ (this.$refs.socialWrapper)?.querySelector(`[data-social-status="${item.id}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
			})
		}
		eventBus.on('composer-reply', this.onComposerReply)

		await this.load()
	},

	unmounted() {
		eventBus.off('composer-reply', this.onComposerReply)
	},

	methods: {
		t: translate,
		n: translatePlural,

		/** The replies the conversation's author hid, shown after all. */
		async showHiddenReplies() {
			try {
				await this.timelineStore.showHiddenReplies()
			} catch (error) {
				logger.error('Could not show the hidden replies', { error })
			}
		},

		/**
		 * Back to wherever the reader came from, and to the home timeline when
		 * that is nowhere.
		 *
		 * `history.state.back` is what the router writes when it navigates
		 * inside the app, so it is also how to tell a post opened from a
		 * timeline from one opened from a link somebody sent: going back from
		 * the second would leave the app entirely, which is not what a button
		 * inside it should do.
		 */
		goBack() {
			if (window.history.state?.back) {
				this.$router.back()

				return
			}

			this.$router.push({ name: 'timeline', params: { type: 'home' } })
		},

		/**
		 * Opens the conversation the route names. Called again when the route
		 * changes to another post, because the router-view is no longer keyed
		 * on the full path and this component is reused.
		 */
		async load() {
			// read before the reset: changeTimelineType prunes the status index
			const singlePost = this.timelineStore.getPostFromTimeline(String(this.$route.params.id)) ?? this.postFromInitialState()

			// A post has two addresses here. The app links it by the numeric id
			// its client API knows, `/@alice/42`; the wider fediverse links it
			// by its ActivityPub id, whose last segment is a twenty-digit
			// number. Both land on this view, and everything below — the
			// `/context` request, and which status the page then shows — speaks
			// the first. So when the server has handed the post over, its own
			// id is the one to go on rather than the one in the address.
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

			// nothing had loaded this post: a link somebody sent, a reload, or a
			// tile on Discover, whose posts belong to that view and never went
			// through the store. `/context` answers with what is around a post
			// and never with the post, so the page used to say it did not exist.
			if (singlePost === undefined || singlePost === null) {
				await this.timelineStore.fetchStatus(String(this.$route.params.id))
			}

			this.reportOpened()

			// the account is loaded for the post's author card; nothing here
			// reads the answer, which is why it is not kept
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
		 * The post the server rendered into the page, for a permalink opened
		 * cold. `loadState` throws when the key is absent — which is what a
		 * deleted post looks like — and the throw used to happen inside
		 * beforeMount, so the view never rendered at all.
		 *
		 * It is only this post if the address says so. The page is rendered
		 * once and the reader goes on reading: opening a second post from the
		 * first would otherwise have been answered with the first one again,
		 * whenever the store had not loaded the second. The address names it
		 * either by the client id or by the last segment of its ActivityPub id,
		 * and both are checked, which is also what makes a fediverse permalink
		 * resolve at all.
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
	padding-bottom: 25%;
}

.thread__back {
	margin-bottom: 8px;
}

/*
 * The indent belongs to the thread, not to the list: `.social__timeline` is
 * another component's root, and a scoped rule here lands on it beside the
 * list's own layout with the same specificity, so which one wins depends on
 * the order the bundle happens to put them in.
 */
.thread .social__timeline {
	margin-inline-start: 16px;
}

/**
 * A reply chain used to read as three unrelated stacks of cards. The spine is
 * a single line behind the avatars: everything on it belongs to the same
 * conversation.
 *
 * Only when there is one. A post with no parent and no replies is a single
 * card, and the line beside it ran from nothing to nothing.
 */
.thread {
	position: relative;

	&--connected::before {
		content: '';
		position: absolute;
		top: 12px;
		bottom: 12px;
		inset-inline-start: 42px;
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

/* The post being read is the card, and this is spacing around it. It used to
   be a second card: white ground, 20px of padding, rounded corners, a shadow,
   and an accent border on top of all that — so a post on its own page was
   drawn inside two boxes, one nested in the other about ten pixels out. What
   marks the post the page is about is that it is the one at the top with the
   thread hanging off it, which needs no frame of its own. */
.main-post {
	position: relative;
	z-index: 1;
	margin: 16px 0;
	/* the lists above and below sit 16px out and pad 8 inside that, so this
	   takes the 24 that puts its avatar on the same line as theirs — which is
	   the line the spine runs down */
	margin-inline-start: 24px;
}

/* the fine print, the faces and the reply box belong to the post above them,
   so they sit on its line rather than on the thread's */
.main-post__under {
	margin-inline-start: 24px;
	/* level with the card, which starts where the avatar column ends */
	padding-inline-start: 64px;
	margin-bottom: 16px;
}

.thread__hidden {
	margin: 8px 0 0 24px;
	padding-inline-start: 64px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

/* a phone: no avatar column to be level with, so nothing is set in 64px
   from it, and the spine runs down the middle of the face inside the card */
@include layout.below(layout.$phone) {
	.thread .social__timeline {
		margin-inline-start: 0;
	}

	.thread--connected::before {
		inset-inline-start: 29px;
	}

	.main-post {
		margin-inline-start: 0;
	}

	.main-post__under,
	.thread__hidden {
		margin-inline-start: 0;
		padding-inline-start: 12px;
	}
}

#app-content {
	position: relative;
}
</style>
