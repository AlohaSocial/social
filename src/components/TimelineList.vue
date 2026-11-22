<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__timeline" @focusout="onFocusOut">
		<!-- announces new posts, loaded pages and the end of the list to screen readers -->
		<div class="hidden-visually" role="status" aria-live="polite">
			{{ announcement }}
		</div>
		<PullToRefresh v-if="canPull" :refreshing="refreshing" @refresh="refresh" />
		<transition name="pill">
			<button
				v-if="arrived > 0"
				class="new-posts-pill"
				:style="pillStyle"
				:aria-label="n('social', 'Show %n new post', 'Show %n new posts', arrived)"
				@click="showArrived">
				<ArrowUp :size="18" />
				{{ n('social', '%n new post', '%n new posts', arrived) }}
			</button>
		</transition>
		<div
			v-if="profileSyncNotice && error === null"
			class="profile-sync-notice timeline-state-card"
			:class="{ 'profile-sync-notice--warning': profileSyncState !== 'pending' }"
			role="status"
			:aria-busy="profileSyncChecking"
			aria-live="polite">
			<Refresh
				v-if="profileSyncState === 'pending'"
				class="timeline-state-card__icon"
				:size="24"
				aria-hidden="true" />
			<AlertCircleOutline
				v-else
				class="timeline-state-card__icon"
				:size="24"
				aria-hidden="true" />
			<div class="timeline-state-card__content">
				<h2>{{ profileSyncState === 'pending' ? t('social', 'Updating this profile') : t('social', 'Profile update incomplete') }}</h2>
				<p>{{ profileSyncNotice }}</p>
			</div>
			<NcButton
				v-if="profileSyncState !== 'pending'"
				variant="tertiary"
				:disabled="profileSyncChecking || loading"
				@click="retryProfileSync">
				{{ profileSyncChecking ? t('social', 'Checking…') : profileSyncState === 'stalled' ? t('social', 'Check again') : t('social', 'Try again') }}
			</NcButton>
		</div>

		<!-- The grid draws the same timeline, so paging, the pill and the error
		     and empty states stay here for all displays. -->
		<ProfileMediaGrid
			v-if="display === 'grid'"
			:posts="timeline"
			:account="account"
			:loading="loading" />
		<VideoBrowse
			v-else-if="display === 'videos'"
			:posts="timeline"
			:loading="loading"
			:allLoaded="allLoaded"
			:failed="error !== null"
			@more="infiniteHandler" />
		<transition-group
			v-else
			name="list"
			tag="ul"
			:class="{ 'timeline-list--settling': holding, 'timeline-list--swapping': swapping }">
			<template v-for="(entry, index) in entries" :key="entry.id">
				<!-- notifications only: "New" over the unread run, then the
				     day each card arrived. See dayHeadings() -->
				<li
					v-if="headings[index]"
					class="timeline-divider"
					:class="{ 'timeline-divider--new': headings[index] === 'new' }">
					{{ dayHeadingLabel(headings[index]) }}
				</li>
				<!-- Where the reader got to last time; everything below it is drawn quieter. -->
				<li v-if="caughtUpAt > 0 && index === caughtUpAt" class="timeline-caughtup">
					<span class="timeline-caughtup__line" />
					<AlohaScene scene="hammock" class="timeline-caughtup__scene" />
					<span class="timeline-caughtup__line" />
					<span class="timeline-caughtup__label">{{ t('social', 'You\'re all caught up') }}</span>
					<span v-if="caughtUpSince" class="timeline-caughtup__since" :title="caughtUpWhen">{{ caughtUpSince }}</span>
				</li>
				<TimelineEntry
					:class="{
						'timeline-entry--focused': index === focused,
						'timeline-entry--seen': caughtUpAt > 0 && index >= caughtUpAt,
					}"
					:item="entry"
					:type="type"
					:index="index"
					:immediate="swapping"
					:depth="depths[entry.id] ?? 0"
					:continues="hasReplies[entry.id] === true"
					:grouped="grouped.has(entry.id)"
					:unread="isUnread(entry)" />
			</template>
		</transition-group>
		<!-- Not while a switch is settling: the previous posts stay dimmed until
		     the new ones arrive, so only a cold list draws a skeleton. -->
		<TimelineSkeleton v-if="display === 'list' && loading && timeline.length === 0 && !holding" />
		<!-- An error is its own state with a retry, so paging resumes without a reload. -->
		<div v-if="error !== null" class="timeline-error timeline-state-card" role="alert">
			<AlertCircleOutline class="timeline-state-card__icon" :size="24" aria-hidden="true" />
			<div class="timeline-state-card__content">
				<h2>{{ timeline.length > 0 ? t('social', 'Could not load more posts') : t('social', 'Could not load posts') }}</h2>
				<p class="timeline-error__message">
					{{ error }}
				</p>
			</div>
			<NcButton variant="primary" :disabled="loading" @click="retry">
				<template #icon>
					<Refresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>
		<div ref="sentinel" class="list-sentinel">
			<WaveLoading v-if="loading && timeline.length > 0" class="list-loading" />
			<div v-else-if="!loading && !allLoaded" class="list-end" />
			<!-- in every display, so the empty state names the tab that asked -->
			<EmptyContent v-if="showEmptyContent" :item="emptyContentData" />
		</div>
	</div>
</template>

<script>
import { showError } from '../services/toast.js'
import { offTimelinePush, onTimelinePush, PUSH_DIRECT, PUSH_HOME, PUSH_KINDS, PUSH_NOTIFICATION } from '../services/timelinePush.js'

import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { fromNow, fullDateTime } from '../utils/relativeTime.js'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import ProfileMediaGrid from './ProfileMediaGrid.vue'
import TimelineEntry from './TimelineEntry.vue'
import TimelineSkeleton from './TimelineSkeleton.vue'
import VideoBrowse from './VideoBrowse.vue'
import WaveLoading from './WaveLoading.vue'
import AlohaScene from './illustrations/AlohaScene.vue'
import PullToRefresh from './PullToRefresh.vue'

/** How long a list counts as swapping after a held-over list is replaced, in ms: a little longer than the rise-in transition. */
const SWAP_SETTLE = 320

/** The switcher's scopes, which hold the previous list over even on a first visit. */
const HELD_SCOPES = ['home', 'timeline', 'federated']
const UNTAGGED_RECOMMENDATION_CONTEXTS = ['interests', 'photos', 'videos', 'shorts', 'reels']
import EmptyContent from './EmptyContent.vue'
import logger from '../services/logger.js'
import eventBus, { NOTIFICATIONS_READ } from '../services/eventBus.js'
import { dayHeadingLabel, dayHeadings, groupNotifications, newestIdOf } from '../services/notifications.js'
import { isNewerId, newerId, newestId, oldestId } from '../utils/snowflake.js'
import { scrollOffset, scroller } from '../utils/scroller.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useNotificationsStore } from '../store/notifications.js'
import { COUNTS_BATCH, isRanked, useTimelineStore } from '../store/timeline.js'
import { fetchRecommendationPreferences, isRecommendationLearningActive, isTracking } from '../services/interests.js'
import { contextFor, createInterestTracker } from '../services/interestTracker.js'
import { accumulateVideoPlayback } from '../services/videoPlayback.js'
import { isPostControl } from '../utils/postControls.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'
import { useServerData } from '../composables/useServerData.js'

/** How many times fetchNewStatuses() may follow itself in one tick, so a server that ignores min_id cannot loop it. */
const MAX_CATCHUP_PAGES = 10

/** The longest poll backoff in ms; each failure doubles the wait up to this and an answer resets it. */
const MAX_POLL_WAIT = 5 * 60 * 1000

/** How far down, in px, the reader has to be for arriving posts to be held behind the pill rather than prepended. */
const ANNOUNCE_BELOW = 240

/** How far apart two posts by one author may be, in ms, and still be drawn as one run. */
const RUN_WITHIN = 30 * 60 * 1000

/** The lists whose posts are never drawn as runs. */
const NO_RUNS = ['notifications', 'direct', 'single-post', 'account']

/**
 * @param {object} earlier the entry above
 * @param {object} later the entry below it
 * @return {boolean} whether the two read as one author's run
 */
function continuesRun(earlier, later) {
	const author = later?.account?.id
	if (!author || earlier?.account?.id !== author || earlier.reblog || later.reblog) {
		return false
	}

	const apart = Math.abs(Date.parse(earlier.created_at) - Date.parse(later.created_at))

	return apart <= RUN_WITHIN
}

/**
 * @param {string} identity a list, as `getTimelineIdentity` names it
 * @return {string} where that list's mark is kept
 */
function placeKeyFor(identity) {
	return `social:seen:${identity}`
}

/**
 * Whether a list is one the caught-up line is drawn on: a chronological feed,
 * not a thread, not the notifications page.
 *
 * @param {string} identity the list, as `getTimelineIdentity` names it
 * @return {boolean}
 */
function keepsPlace(identity) {
	const [type, , params] = JSON.parse(identity)

	return type !== 'single-post' && type !== 'notifications' && !isRanked({ type, params })
}

/**
 * How long the notifications have to be on screen, with the tab in front,
 * before they count as read up to the newest card shown. The marker is shared
 * with every client, so merely rendering the page must not clear it.
 */
const SEEN_AFTER = 2000

/** How often the counts of posts on screen are refreshed while the screen stays put. */
const COUNT_REFRESH_MS = 2 * 60 * 1000
/** how long scrolling has to rest before posts that came into view are asked about */
const COUNT_SETTLE_MS = 1500
/** the shortest time between two asks about one post, a little under the interval so each tick finds it due */
const COUNT_REASK_MS = 110 * 1000

/**
 * Whether a link leaves this app: not a hashtag, not a mention, not a page of
 * this instance.
 *
 * @param {HTMLAnchorElement} link the link
 * @return {boolean}
 */
function isLinkOut(link) {
	const text = (link.textContent ?? '').trim()
	if (text.startsWith('#') || text.startsWith('@')) {
		return false
	}

	try {
		return new URL(link.href, window.location.href).host !== window.location.host
	} catch {
		return false
	}
}

export default {
	name: 'TimelineList',
	components: {
		ProfileMediaGrid,
		ArrowUp,
		Refresh,
		AlertCircleOutline,
		NcButton,
		TimelineEntry,
		TimelineSkeleton,
		VideoBrowse,
		WaveLoading,
		EmptyContent,
		AlohaScene,
		PullToRefresh,
	},

	props: {
		type: {
			type: String,
			default: () => 'home',
		},

		showParents: {
			type: Boolean,
			default: false,
		},

		reverseOrder: {
			type: Boolean,
			default: false,
		},

		/** How to draw the posts: entries, a Pixelfed-style grid, or the Videos page's cards. */
		display: {
			type: String,
			default: 'list',
			validator: (value) => ['list', 'grid', 'videos'].includes(String(value)),
		},

		/** Whose profile the grid links its tiles into. */
		account: {
			type: String,
			default: '',
		},

		/** The title of the list being read, for the empty state. */
		listTitle: {
			type: String,
			default: '',
		},
	},

	/** `settled` fires whenever a fetch finishes, successfully or not. */
	emits: ['settled'],

	setup() {
		const { serverData } = useServerData()
		const { isOwnHandle } = useCurrentUser()

		return { serverData, isOwnHandle }
	},

	data() {
		return {
			/**
			 * The reading tracker this list feeds, or null. Held raw: it carries
			 * an IntersectionObserver and timers.
			 *
			 * @type {ReturnType<typeof import('../services/interestTracker.js').createInterestTracker>|null}
			 */
			tracker: null,
			/** @type {Map<HTMLVideoElement, {status: object, lastTime: number, watchedSeconds: number}>|null} Active playback measurements. */
			videoPlayback: null,
			/**
			 * The posts of the list being left, kept on screen while the next
			 * one loads so a switch does not blink through a skeleton.
			 *
			 * @type {string[]}
			 */
			heldOver: [],
			/**
			 * Whether the held-over list is being replaced: the old entries skip
			 * their leave transition and the new ones rise together.
			 */
			swapping: false,
			/** @type {ReturnType<typeof setTimeout>|null} */
			swapTimer: null,
			/**
			 * The newest post each list has shown this session, by identity,
			 * remembered as the reader's place. Taken as the lists fill, since
			 * by the time a switch is noticed the store holds the next list.
			 *
			 * @type {Object<string, string>}
			 */
			newestShown: {},
			infoHidden: false,
			state: [],
			intervalId: -1,
			countRefreshIntervalId: -1,
			countRefreshTimer: -1,
			/**
			 * Tells which entries are on screen, for their counts
			 *
			 * @type {IntersectionObserver|null}
			 */
			countObserver: null,
			countRefreshLastAsked: new Map(),
			/** the entries on screen, by `data-status-id`, as the counts observer last said */
			countVisible: new Set(),
			pollEvery: 30000,
			/** how long the poll is waiting after failures, 0 while the server answers */
			pollWait: 0,
			/** how many ticks of the interval still pass before the next attempt */
			pollSkip: 0,
			/** when the tab was last hidden, so returning can catch up */
			hiddenSince: 0,
			/**
			 * The posts that arrived while the reader was further down the
			 * page, by id: in the store, but kept off the list until the
			 * reader asks for them, so nothing moves above what they read
			 *
			 * @type {string[]}
			 */
			withheld: [],
			/** whether a refresh pulled for on a touch screen is running */
			refreshing: false,
			/** how tall the sticky composer above this list is, 0 when there is none */
			composerHeight: 0,
			/** watches that composer, only while the pill is on screen */
			composerObserver: null,
			/** index of the post the keyboard is on, -1 when none */
			focused: -1,
			/**
			 * Watches the post the keyboard is on, so it stops being the one
			 * l/b/r act on once it has been scrolled away
			 *
			 * @type {IntersectionObserver|null}
			 */
			focusObserver: null,
			loading: false,
			profileSyncChecking: false,
			allLoaded: false,
			/** Which list the requests in flight belong to, so a late answer for a previous list is dropped. */
			generation: 0,
			/** what went wrong, when something did; null while all is well */
			error: null,
			/** whether the polling failure has already been said once */
			pollFailureReported: false,
			observer: null,
			/**
			 * The server's read marker when this page opened, which the "New"
			 * line is drawn against. Frozen, as reading the page moves the marker.
			 */
			seenUpTo: '0',
			/** the newest post id this reader had seen on this timeline last time */
			lastSeen: '',
			/** when that was, as a timestamp; 0 when it is not known */
			lastSeenAt: 0,
			/** the dwell in progress, -1 when none */
			seenTimer: -1,
			/** the newest id already reported read, so it is reported once */
			markedUpTo: '0',
			/** whether the marker has been asked for on this visit */
			markerAsked: false,
			/** Whether the server has answered where the marker is; an account that never read anything has a marker of 0. */
			markerKnown: false,
			emptyContent: {
				default: {
					illustration: 'quiet-timeline',
					title: t('social', 'Your timeline is quiet'),
					description: t('social', 'Posts from the people you follow will show up here. Find a few to get started.'),
					action: {
						label: t('social', 'Find people to follow'),
						to: { name: 'discover' },
					},
				},

				direct: {
					scene: 'bottle',
					title: t('social', 'Nothing private yet'),
					description: t('social', 'A post addressed to you and nobody else arrives here. Start one by writing a post and choosing Direct.'),
					action: {
						label: t('social', 'Find people to write to'),
						to: { name: 'discover' },
					},
				},

				timeline: {
					scene: 'tide',
					title: t('social', 'No local posts found'),
					description: t('social', 'Posts from other people on this instance will show up here'),
				},

				notifications: {
					scene: 'hammock',
					title: t('social', 'No activities yet'),
					description: t('social', 'Nothing has happened here yet'),
				},

				federated: {
					scene: 'islands',
					title: t('social', 'No global posts found'),
					description: t('social', 'Posts from federated instances will show up here'),
					action: {
						label: t('social', 'Discover accounts'),
						to: { name: 'discover' },
					},
				},

				favourites: {
					scene: 'plumeria',
					title: t('social', 'No favorites found'),
					description: t('social', 'Posts you like are kept here, for you alone to see'),
				},

				profile: {
					scene: 'board',
					title: t('social', 'You have not tooted yet'),
				},

				tags: {
					scene: 'tracks',
					title: t('social', 'No posts found for this tag'),
				},

				videos: {
					scene: 'wave',
					title: t('social', 'No videos found'),
					description: t('social', 'Videos posted here, and videos from the PeerTube channels you follow, will show up here'),
				},

				photos: {
					scene: 'tide',
					title: t('social', 'No photos found'),
					description: t('social', 'Posts with photos will show up here'),
				},

				link: {
					scene: 'tracks',
					title: t('social', 'Nothing said about this link'),
					description: t('social', 'Nobody here has posted this link since, or the link is not one this instance has seen.'),
				},

				bookmarks: {
					scene: 'plumeria',
					title: t('social', 'No bookmarks yet'),
					description: t('social', 'Posts you bookmark are kept here, for you alone to see'),
				},

				list: {
					illustration: 'nobody-yet',
					title: t('social', 'Nothing in this list yet'),
					description: t('social', 'Posts from the people in this list will show up here. A list with nobody in it stays empty.'),
					action: {
						label: t('social', 'Find people to add'),
						to: { name: 'discover' },
					},
				},

				bluesky: {
					illustration: 'nobody-yet',
					title: t('social', 'Nothing in this feed right now'),
					description: t('social', 'Bluesky had no posts in this feed for you. Some feeds only fill up once you follow people or like posts there.'),
				},

				interests: {
					illustration: 'quiet-timeline',
					title: t('social', 'Nothing here for you yet'),
					description: t('social', 'For You ranks posts from the hashtags and people you follow and from local discovery. Turn on learning in its settings and it also learns from posts you open, like, boost or save, and from videos you watch most of; simply leaving a post on screen does not count.'),
					action: {
						label: t('social', 'Manage interests'),
						to: { name: 'settings', hash: '#interests' },
					},
				},

				'single-post': {
					illustration: 'no-replies',
					title: this.showParents ? '' : t('social', 'No replies yet'),
				},
			},
		}
	},

	computed: {
		/**
		 * Whether the list can be pulled down to ask for what is newer: a
		 * chronological list of posts, which has a top for them to arrive at.
		 *
		 * @return {boolean}
		 */
		canPull() {
			return this.display === 'list' && !this.showParents && !this.isThread
				&& this.timelineStore.type !== 'single-post' && this.chronological
		},

		/** @return {number} how many posts are waiting above the list */
		arrived() {
			return this.withheld.length
		},

		...mapStores(useAccountStore, useNotificationsStore, useTimelineStore),

		/**
		 * Whether this list is in posting order. For you (also as a scope of
		 * Photos and Videos) is ranked, so nothing about "newer than" applies.
		 *
		 * @return {boolean}
		 */
		chronological() {
			return !isRanked({ type: this.type, params: this.timelineStore.params })
		},

		/**
		 * The For you context this list's reading feeds, or null when it
		 * feeds none.
		 *
		 * @return {string|null}
		 */
		interestContext() {
			if (this.serverData.public) {
				return null
			}

			// the thread's two lists are given whatever type the route
			// carries, which on a post's page is none
			const context = contextFor(this.$route?.name === 'single-post' ? 'single-post' : this.type)
			const tracksV4 = this.serverData.interests?.recommendationsEnabled === true
			return isTracking(this.serverData.interests) || tracksV4 ? context : null
		},

		/** What the live region announces: new posts, loading, or the end of the list. */
		announcement() {
			if (this.arrived > 0) {
				return n('social', '%n new post available', '%n new posts available', this.arrived)
			}
			if (this.loading) {
				return t('social', 'Loading posts')
			}
			if (this.allLoaded && this.timeline.length > 0) {
				return t('social', 'You have reached the end')
			}

			return ''
		},

		/** @return {string} which list the store is holding */
		timelineIdentity() {
			return this.timelineStore.getTimelineIdentity
		},

		/** @return {boolean} whether the current remote page can still grow */
		timelinePageFilling() {
			return this.timelineStore.timelinePageFilling
		},

		/** @return {boolean} whether the last timeline page was empty */
		timelinePageEmpty() {
			return this.timelineStore.timelinePageEmpty
		},

		/** @return {string} the backend or bounded-poll synchronization state */
		profileSyncState() {
			return this.timelineStore.type === 'account' ? this.timelineStore.profileSyncState : ''
		},

		profileSyncNotice() {
			if (this.profileSyncState === 'pending') {
				return this.timeline.length > 0
					? t('social', 'This profile is being synchronized. Available posts are shown below.')
					: t('social', 'This profile is being synchronized. Posts will appear as they arrive.')
			}
			if (this.profileSyncState === 'failed') {
				return this.timeline.length > 0
					? t('social', 'This profile could not be refreshed. Previously loaded posts are still available. Try again shortly.')
					: t('social', 'This profile could not be refreshed. This does not mean it has no posts. Try again shortly.')
			}
			return this.profileSyncState === 'stalled'
				? t('social', 'Synchronization is taking longer than expected. Check again to see whether more posts have arrived.')
				: ''
		},

		/** @return {boolean} nothing to show, and nothing went wrong */
		showEmptyContent() {
			return this.error === null
				&& !['pending', 'failed', 'stalled'].includes(this.profileSyncState)
				&& this.allLoaded
				&& this.timeline.length === 0
				&& this.emptyContentData.title !== ''
		},

		/**
		 * What to say when the list is empty.
		 *
		 * @return {object} a drawing, a title and a description
		 */
		emptyContentData() {
			// a filtered profile tab says what the filter found none of
			const media = String(this.$route.query?.media ?? '')
			const emptyTab = {
				image: {
					scene: 'tide',
					title: t('social', 'No photos yet'),
					description: t('social', 'Posts with photos appear here.'),
				},

				video: {
					scene: 'wave',
					title: t('social', 'No videos yet'),
					description: t('social', 'Posts with videos appear here.'),
				},
			}[media]
			if (this.$route.name === 'profile' && emptyTab !== undefined) {
				return emptyTab
			}

			// the `type` prop first: Bookmarks, Photos and lists have no route of that name
			const byProp = this.emptyContent[this.type]
			if (byProp !== undefined) {
				if (this.type === 'list' && this.listTitle !== '') {
					return {
						...byProp,
						title: t('social', 'Nothing in {list} yet', { list: this.listTitle }),
					}
				}

				return byProp
			}

			const byType = this.emptyContent[String(this.$route.params.type)]
			if (byType !== undefined) {
				return byType
			}

			const byName = this.emptyContent[this.$route.name]
			if (byName !== undefined) {
				if (this.$route.name === 'timeline') {
					return this.emptyContent.default
				}
				if (this.$route.name === 'profile' && (this.serverData.public || !this.isOwnHandle(this.$route.params.account))) {
					return {
						...byName,
						title: t('social', 'No posts are available here yet'),
						description: t('social', 'This server has no posts to show for this profile.'),
					}
				}
				return byName
			}

			logger.debug('Did not find any empty content for this route', { routeType: this.$route.params.type, routeName: this.$route.name })
			return this.emptyContent.default
		},

		timeline() {
			if (this.isThread) {
				return this.thread.order
			}

			const timeline = this.showParents
				? this.timelineStore.getParentsTimeline
				: this.timelineStore.getTimeline

			// a copy: .reverse() works in place on a shared cached getter
			return this.reverseOrder ? [...timeline].reverse() : timeline
		},

		/**
		 * @return {boolean} whether this list is the replies under a post,
		 * which read as a conversation rather than as a feed
		 */
		isThread() {
			return this.type === 'single-post' && !this.showParents
		},

		/**
		 * The replies under a post as a tree: each reply followed by its
		 * replies, oldest first, with its depth. A reply whose parent is not
		 * on the page stays at the top level.
		 *
		 * @return {{order: object[], depth: Record<string, number>}}
		 */
		thread() {
			const root = String(this.timelineStore.params?.singlePost ?? this.timelineStore.params?.id ?? '')
			const chronological = [...this.timelineStore.getTimeline].reverse()
			const byParent = new Map()
			for (const status of chronological) {
				const parent = String(status.in_reply_to_id ?? '')
				if (!byParent.has(parent)) {
					byParent.set(parent, [])
				}
				byParent.get(parent).push(status)
			}

			const order = []
			const depth = /** @type {Record<string, number>} */ ({})
			const seen = new Set()
			const visit = (parentId, level) => {
				for (const status of byParent.get(parentId) ?? []) {
					if (seen.has(status.id)) {
						continue
					}
					seen.add(status.id)
					order.push(status)
					depth[status.id] = level
					visit(status.id, level + 1)
				}
			}
			visit(root, 0)
			for (const status of chronological) {
				if (!seen.has(status.id)) {
					seen.add(status.id)
					order.push(status)
					depth[status.id] = 0
					visit(status.id, 1)
				}
			}

			return { order, depth }
		},

		/** @return {Record<string, number>} how deep each entry sits; empty outside a thread */
		depths() {
			return this.isThread ? this.thread.depth : {}
		},

		/**
		 * Which entries have a reply under them, so the thread line continues past them.
		 *
		 * @return {Record<string, boolean>}
		 */
		hasReplies() {
			if (!this.isThread) {
				return {}
			}

			const parents = /** @type {Record<string, boolean>} */ ({})
			for (const status of this.timelineStore.getTimeline) {
				const parent = String(status.in_reply_to_id ?? '')
				if (parent !== '') {
					parents[parent] = true
				}
			}

			return parents
		},

		/**
		 * Index where what the reader saw last time (per timeline) begins;
		 * 0 when everything is new or nothing is.
		 *
		 * @return {number}
		 */
		caughtUpAt() {
			if (this.isThread || this.type === 'notifications' || !this.chronological || this.lastSeen === '') {
				return 0
			}

			const older = this.entries.findIndex((entry) => !isNewerId(String(entry.id ?? ''), this.lastSeen))

			return older <= 0 || older >= this.entries.length ? 0 : older
		},

		/** @return {string} where this timeline's mark is kept */
		placeKey() {
			return placeKeyFor(this.timelineIdentity)
		},

		/** @return {string} when the reader was last here, in words; '' when not known */
		caughtUpSince() {
			return this.lastSeenAt === 0
				? ''
				: t('social', 'Your last visit was {time}', { time: fromNow(this.lastSeenAt) })
		},

		/** @return {string} the same moment as a date and time */
		caughtUpWhen() {
			return this.lastSeenAt === 0 ? '' : fullDateTime(this.lastSeenAt)
		},

		/**
		 * Puts the sticky pill below the sticky composer, which paints above it.
		 * The composer's height is measured because it grows.
		 *
		 * @return {object} the inline offset, or nothing when there is no composer
		 */
		pillStyle() {
			return this.composerHeight === 0 ? {} : { top: `${this.composerHeight + 8}px` }
		},

		/**
		 * What is drawn: the timeline, with runs of the same reaction folded
		 * into one card on the notifications page. Paging works off `timeline`.
		 *
		 * @return {object[]}
		 */
		entries() {
			// see `holding`
			const timeline = (this.timeline.length === 0 && this.holding)
				? this.heldOver
				: this.revealed(this.timeline)

			if (this.type !== 'notifications') {
				return timeline
			}

			return groupNotifications(timeline)
		},

		/**
		 * The posts that continue a run by the author of the post above them.
		 * Not across the caught-up line, which is a break in the reading.
		 *
		 * @return {Set<string>} their ids
		 */
		grouped() {
			const ids = new Set()
			if (this.display !== 'list' || this.showParents
				|| NO_RUNS.includes(this.type) || NO_RUNS.includes(this.timelineStore.type)) {
				return ids
			}

			this.entries.forEach((entry, index) => {
				if (index > 0 && index !== this.caughtUpAt && continuesRun(this.entries[index - 1], entry)) {
					ids.add(entry.id)
				}
			})

			return ids
		},

		/** Whether the list being left is on screen, which lasts only while the next one is loading. */
		holding() {
			return this.loading && this.timeline.length === 0 && this.heldOver.length > 0 && this.holdable
		},

		/**
		 * Whether to hold the last list over while this one loads: only for a
		 * switcher scope or a list that has shown posts this session, since a
		 * first-time ranked feed may well be empty.
		 */
		holdable() {
			return HELD_SCOPES.includes(this.timelineStore.type) || this.timelineStore.wasFilled(this.timelineStore.getTimelineIdentity)
		},

		/**
		 * Where the already-seen part of the notifications page begins, against
		 * the frozen `seenUpTo`; 0 when nothing is new or elsewhere.
		 *
		 * @return {number}
		 */
		dividerAt() {
			if (this.type !== 'notifications' || this.seenUpTo === '0') {
				return 0
			}

			const older = this.entries.findIndex((entry) => !isNewerId(newestIdOf(entry), this.seenUpTo))

			// every card is newer: a heading over all of it separates nothing
			return older <= 0 ? 0 : older
		},

		/**
		 * The headings between the notification cards, by card index.
		 *
		 * @return {Object<number, 'new'|'today'|'week'|'earlier'>} empty on every other timeline
		 */
		headings() {
			return this.type === 'notifications' ? dayHeadings(this.entries, this.dividerAt) : {}
		},
	},

	watch: {
		/**
		 * Switching timeline reuses this component, so the paging state is reset by hand.
		 *
		 * @param {string} now the list the store holds
		 * @param {string} before the list being left
		 */
		timelineIdentity(now, before) {
			this.profileSyncChecking = false
			if (!this.showParents) {
				// a restored or prefetched list replaces the one on screen at once: drawn as a swap
				if (this.timelineStore.restored && this.timeline.length > 0) {
					this.startSwap()
				}
				// under the left list's key: the store already holds the next list
				this.rememberPlace(before, this.newestShown[before])
				this.readPlace()
				this.resetAndLoad()
			}
			// a different list is a different page view for the tracker
			this.startTracking()
		},

		// A Bluesky profile can have an empty intermediate page while the
		// server continues past hidden or unavailable records. Keep the end
		// sentinel active until the background refill confirms the end.
		timelinePageFilling(filling) {
			if (filling) {
				this.allLoaded = false
			} else if (this.timelinePageEmpty && !this.loading) {
				this.allLoaded = true
			}
		},

		timelinePageEmpty(empty) {
			if (empty && !this.timelinePageFilling && !this.loading) {
				this.allLoaded = true
			}
		},

		// turning learning off, or on, from the notice above the list
		interestContext() {
			this.startTracking()
		},

		// the composer is watched only while the pill shows
		arrived(count) {
			if (count > 0) {
				this.watchComposer()
			} else {
				this.unwatchComposer()
				// the withheld posts are on the list now
				this.noteNewestShown(this.timeline)
			}
		},

		// new entries restart the read dwell
		timeline(entries, previous) {
			if (entries.length > 0) {
				this.timelineStore.markFilled(this.timelineStore.getTimelineIdentity)
				this.noteNewestShown(this.revealed(entries))
			}
			// the new list replaces the held-over one: drawn as a swap
			if (entries.length > 0 && previous.length === 0 && this.heldOver.length > 0 && this.holdable) {
				this.startSwap()
			}
			// kept here for the next switch, since by the time it is noticed the store has emptied it
			if (entries.length > 0) {
				this.heldOver = [...entries]
			}

			this.armSeenTimer()
		},

		// post flush, so the entries are in the DOM
		entries: {
			handler() {
				this.syncTracked()
				this.observeCountTargets()
			},

			flush: 'post',
		},
	},

	mounted() {
		// a list already full on mount never reaches the `timeline` watcher
		if (this.timeline.length > 0) {
			this.heldOver = [...this.timeline]
			this.noteNewestShown(this.timeline)
		}

		// the ancestors above a post are read as much as the replies below it
		this.startTracking()

		// The ancestors list renders the /context response its sibling
		// fetches, so it neither pages, polls nor handles j/k itself.
		if (this.showParents) {
			return
		}

		this.readPlace()

		eventBus.on('shortcut:next', this.focusNext)
		eventBus.on('shortcut:previous', this.focusPrevious)

		// returning to the tab starts the dwell on a page opened in the
		// background; registered for every timeline as this component is reused
		document.addEventListener('visibilitychange', this.armSeenTimer)
		this.ensureMarker()

		// the page header can mark everything read, which must move the frozen line too
		eventBus.on(NOTIFICATIONS_READ, this.onMarkedAllRead)

		this.loadFirstPage()
		// Counts on remote posts can change after their first render. Refresh
		// only what is on screen, once scrolling settles and then slowly, and
		// let the server's per-post window cap requests to the origin.
		this.countObserver = new IntersectionObserver(this.onCountIntersection)
		this.observeCountTargets()
		this.countRefreshIntervalId = window.setInterval(() => this.refreshVisiblePostCounts(), COUNT_REFRESH_MS)
		// with notify_push, polling is only a slow safety net
		const hasPush = onTimelinePush(this.onPushed)
		this.pollEvery = (hasPush ? 300 : 30) * 1000
		this.intervalId = window.setInterval(() => this.pollIfVisible(), this.pollEvery)
		// a hidden tab does not poll; it catches up when it comes back
		document.addEventListener('visibilitychange', this.pollOnReturn)
		this.setupIntersectionObserver()
	},

	unmounted() {
		if (this.swapTimer !== null) {
			clearTimeout(this.swapTimer)
			this.swapTimer = null
		}
		this.stopTracking()
		this.rememberPlace()
		offTimelinePush(this.onPushed)
		document.removeEventListener('visibilitychange', this.pollOnReturn)
		document.removeEventListener('visibilitychange', this.armSeenTimer)
		clearTimeout(this.seenTimer)
		eventBus.off('shortcut:next', this.focusNext)
		eventBus.off('shortcut:previous', this.focusPrevious)
		eventBus.off(NOTIFICATIONS_READ, this.onMarkedAllRead)
		clearInterval(this.intervalId)
		clearInterval(this.countRefreshIntervalId)
		clearTimeout(this.countRefreshTimer)
		this.countObserver?.disconnect()
		this.unwatchComposer()
		if (this.observer) {
			this.observer.disconnect()
		}
		this.focusObserver?.disconnect()
	},

	methods: {
		/**
		 * Reads where the reader got to on this timeline last time, from local
		 * storage. Home also asks the server's `home` marker (shared with other
		 * Mastodon clients), and whichever is further along wins.
		 */
		readPlace() {
			this.lastSeen = ''
			this.lastSeenAt = 0
			try {
				const stored = window.localStorage.getItem(this.placeKey)
				if (stored) {
					const { id, at } = JSON.parse(stored)
					this.lastSeen = String(id ?? '')
					this.lastSeenAt = Number(at ?? 0)
				}
			} catch {
				// no stored place to read, which is not a failure
			}

			if (this.serverData.public || this.type !== 'home') {
				return
			}
			const identity = this.timelineIdentity
			this.timelineStore.fetchHomeMarker().then((marker) => {
				// the list may have been switched while the server was asked
				if (identity !== this.timelineIdentity) {
					return
				}
				if (marker !== null && (this.lastSeen === '' || isNewerId(marker.id, this.lastSeen))) {
					this.lastSeen = marker.id
					this.lastSeenAt = marker.at
				}
				// only now does the dwell start, so the line is drawn against the marker from before this visit
				this.armSeenTimer()
			})
		},

		/**
		 * @param {object[]} entries a list of posts
		 * @return {object[]} those of them that are not being held back
		 */
		revealed(entries) {
			if (this.withheld.length === 0) {
				return entries
			}

			return entries.filter((entry) => !this.withheld.includes(String(entry.id)))
		},

		/**
		 * Keeps the newest post of the list on screen, for `rememberPlace`.
		 *
		 * @param {object[]} entries the list, newest first
		 */
		noteNewestShown(entries) {
			const id = entries[0]?.id
			if (id !== undefined) {
				this.newestShown[this.timelineIdentity] = String(id)
			}
		},

		/**
		 * Remembers the newest post of a timeline as where the reader got to.
		 *
		 * @param {string} [identity] which list; the one on screen by default
		 * @param {string} [newest] the newest post it showed; the first entry by default
		 */
		rememberPlace(identity = this.timelineIdentity, newest = this.entries[0]?.id) {
			if (!newest || !keepsPlace(identity)) {
				return
			}

			try {
				window.localStorage.setItem(
					placeKeyFor(identity),
					JSON.stringify({ id: String(newest), at: Date.now() }),
				)
			} catch {
				// a browser that will not store it shows no line next time
			}
		},

		/**
		 * Asks for the first page, unless the store restored this list (on Back),
		 * where another page would shift the scroll offset being restored.
		 */
		async loadFirstPage() {
			if (!this.timelineStore.restored || this.timeline.length === 0) {
				await this.infiniteHandler()
			}

			// the router waits for this to restore the scroll offset, which the browser would clamp on a short page
			await this.$nextTick()
			eventBus.emit('timeline:rendered')
		},

		/** Keeps `composerHeight` in step with the composer, a preceding sibling of this list if there is one. */
		watchComposer() {
			this.unwatchComposer()

			let sibling = this.$el?.previousElementSibling ?? null
			while (sibling !== null && !sibling.classList?.contains('new-post')) {
				sibling = sibling.previousElementSibling
			}
			if (sibling === null) {
				this.composerHeight = 0
				return
			}

			const measure = () => {
				this.composerHeight = sibling.offsetHeight
			}
			measure()

			if (typeof ResizeObserver === 'undefined') {
				return
			}
			this.composerObserver = new ResizeObserver(measure)
			this.composerObserver.observe(sibling)
		},

		/** Marks the next render as a swap, for as long as the entering entries take to rise. */
		startSwap() {
			this.swapping = true
			if (this.swapTimer !== null) {
				clearTimeout(this.swapTimer)
			}
			this.swapTimer = setTimeout(() => {
				this.swapping = false
				this.swapTimer = null
			}, SWAP_SETTLE)
		},

		/** Stops watching it, and forgets what it measured. */
		unwatchComposer() {
			if (this.composerObserver !== null) {
				this.composerObserver.disconnect()
				this.composerObserver = null
			}
			this.composerHeight = 0
		},

		/**
		 * Starts measuring what is read for the For you feed, finishing the
		 * previous tracker first so its reading goes under its own context.
		 */
		startTracking() {
			this.stopTracking()

			const context = this.interestContext
			if (context === null || !this.$el) {
				return
			}
			this.videoPlayback = new Map()

			this.tracker = createInterestTracker({
				context,
				legacyLearning: isTracking(this.serverData.interests),
				allowUntagged: this.serverData.interests?.recommendationsEnabled === true
					&& UNTAGGED_RECOMMENDATION_CONTEXTS.includes(context),
				isOwn: (status) => {
					const me = this.accountStore.currentAccount?.acct
					return me !== undefined && status.account?.acct === me
				},
			})
			if (this.serverData.interests?.recommendationsEnabled === true) {
				const tracker = this.tracker
				fetchRecommendationPreferences().then((preferences) => {
					if (this.tracker !== tracker) {
						return
					}
					if (isRecommendationLearningActive(preferences)
						&& Number.isInteger(preferences.generation)) {
						tracker.setLearningGeneration(preferences.generation)
					} else {
						tracker.setLearningGeneration(null)
						if (!isTracking(this.serverData.interests)) {
							this.stopTracking()
						}
					}
				}).catch((error) => logger.debug('Could not read For You learning settings', { error }))
			}
			// delegated, in the capture phase: `play` does not bubble and a
			// picture's click handler may stop the event
			this.$el.addEventListener('click', this.onTrackedClick, true)
			this.$el.addEventListener('play', this.onTrackedPlay, true)
			this.$el.addEventListener('timeupdate', this.onTrackedVideoProgress, true)
			this.$el.addEventListener('pause', this.onTrackedVideoProgress, true)
			this.$el.addEventListener('ended', this.onTrackedVideoProgress, true)
			document.addEventListener('visibilitychange', this.onTrackedVisibility)
			this.$nextTick(() => this.syncTracked())
		},

		/** Finishes the tracker, sending what it still holds. */
		stopTracking() {
			if (!this.tracker) {
				return
			}

			this.flushVideoWatches()
			this.tracker.destroy()
			this.tracker = null
			this.videoPlayback?.clear()
			this.videoPlayback = null
			this.$el?.removeEventListener('click', this.onTrackedClick, true)
			this.$el?.removeEventListener('play', this.onTrackedPlay, true)
			this.$el?.removeEventListener('timeupdate', this.onTrackedVideoProgress, true)
			this.$el?.removeEventListener('pause', this.onTrackedVideoProgress, true)
			this.$el?.removeEventListener('ended', this.onTrackedVideoProgress, true)
			document.removeEventListener('visibilitychange', this.onTrackedVisibility)
		},

		/** Hands the tracker the entries on the page, each with its post. */
		syncTracked() {
			// not the held-over list, and not a grid, where all tiles are in view
			// at once and only opening one counts (`onTrackedClick`)
			if (!this.tracker || !this.$el || this.holding || this.display !== 'list') {
				return
			}

			const pairs = [...this.$el.querySelectorAll('[data-status-id]')]
				.map((element) => [element, this.timelineStore.getStatus(element.dataset.statusId)])
				.filter(([, status]) => status !== undefined)
			this.tracker.sync(pairs)
		},

		/** Hands the counts observer the entries on the page. */
		observeCountTargets() {
			if (!this.countObserver || !this.$el?.querySelectorAll) {
				return
			}
			this.countObserver.disconnect()
			this.countVisible.clear()
			for (const element of this.$el.querySelectorAll('[data-status-id]')) {
				this.countObserver.observe(element)
			}
		},

		/**
		 * Keeps the set of entries on screen, and refreshes the counts of
		 * the ones that came into view once scrolling has settled.
		 *
		 * @param {IntersectionObserverEntry[]} changes what came into or went out of view
		 */
		onCountIntersection(changes) {
			let shown = false
			for (const change of changes) {
				const id = /** @type {HTMLElement} */ (change.target)?.dataset?.statusId
				if (!id) {
					continue
				}
				if (change.isIntersecting) {
					this.countVisible.add(id)
					shown = true
				} else {
					this.countVisible.delete(id)
				}
			}
			if (shown) {
				clearTimeout(this.countRefreshTimer)
				this.countRefreshTimer = window.setTimeout(() => this.refreshVisiblePostCounts(), COUNT_SETTLE_MS)
			}
		},

		/** Refresh the counts of the posts on screen without waiting for cron. */
		refreshVisiblePostCounts() {
			if (document.visibilityState !== 'visible' || this.display !== 'list' || this.showParents) {
				return
			}

			const now = Date.now()
			const ids = new Set()
			for (const entryId of this.countVisible) {
				const indexed = this.timelineStore.getStatus(entryId)
				// a notification is about its status, and a boost counts what it boosts
				const post = indexed?.status ?? indexed
				const status = post?.reblog ?? post
				if (status?.id && status.visibility !== 'direct'
					&& now - (this.countRefreshLastAsked.get(String(status.id)) ?? 0) >= COUNT_REASK_MS) {
					ids.add(String(status.id))
				}
			}

			const selected = [...ids].slice(0, COUNTS_BATCH)
			if (selected.length === 0) {
				return
			}
			for (const id of selected) {
				this.countRefreshLastAsked.set(id, now)
			}
			this.timelineStore.refreshCounts(selected).catch((error) => {
				logger.debug('Visible post counts were not refreshed', { error, ids: selected })
			})
		},

		/**
		 * @param {Event} event anything that happened inside the list
		 * @return {object|undefined} the post it happened to
		 */
		trackedStatusOf(event) {
			const entry = /** @type {HTMLElement|null} */ (/** @type {HTMLElement} */ (event.target)?.closest?.('[data-status-id]'))
			if (!entry || !this.$el.contains(entry)) {
				return undefined
			}

			return this.timelineStore.getStatus(entry.dataset.statusId)
		},

		/**
		 * Records a picture opened, a post opened or a link out followed;
		 * hashtags and mentions do not count.
		 *
		 * @param {MouseEvent} event the click
		 */
		onTrackedClick(event) {
			const status = this.trackedStatusOf(event)
			if (status === undefined) {
				return
			}

			const target = /** @type {HTMLElement} */ (event.target)
			if (target.closest('.post-attachments, .media-grid__link, .video-card__link')) {
				this.tracker?.record(status, 'media')
				return
			}

			if (target.closest('.post-timestamp')) {
				this.tracker?.record(status, 'open')
				return
			}

			const link = /** @type {HTMLAnchorElement|null} */ (target.closest('a[href]'))
			if (link && link.closest('.post-message, .post-card') && isLinkOut(link)) {
				this.tracker?.record(status, 'link')
				return
			}

			if (target.closest('.post-content--openable')
				&& event.button === 0
				&& !event.defaultPrevented
				&& !event.metaKey
				&& !event.ctrlKey
				&& !event.shiftKey
				&& !event.altKey
				&& (window.getSelection?.()?.toString() ?? '') === ''
				&& !isPostControl(event)) {
				this.tracker?.record(status, 'open')
			}
		},

		/** @param {Event} event a video or audio starting */
		onTrackedPlay(event) {
			const status = this.trackedStatusOf(event)
			const media = event.target
			if (status !== undefined && media instanceof HTMLMediaElement) {
				this.tracker?.record(status, 'media')
				if (media instanceof HTMLVideoElement && this.videoPlayback && !this.videoPlayback.has(media)) {
					this.videoPlayback.set(media, { status, lastTime: media.currentTime, watchedSeconds: 0 })
				}
			}
		},

		/** @param {Event} event the media event */
		onTrackedVideoProgress(event) {
			const media = event.target
			if (!(media instanceof HTMLVideoElement) || !this.videoPlayback?.has(media)) {
				return
			}
			const playback = this.videoPlayback.get(media)
			accumulateVideoPlayback(playback, media)
			if (event.type === 'timeupdate') {
				return
			}
			if (event.type === 'ended') {
				this.reportVideoWatch(media, playback)
				this.videoPlayback.delete(media)
			}
		},

		/** Finishes partial playback before a backgrounded page stops running. */
		onTrackedVisibility() {
			if (document.visibilityState !== 'hidden' || !this.videoPlayback) {
				return
			}
			this.flushVideoWatches()
		},

		flushVideoWatches() {
			if (!this.videoPlayback) {
				return
			}
			for (const [media, playback] of this.videoPlayback) {
				accumulateVideoPlayback(playback, media)
				this.reportVideoWatch(media, playback)
			}
			this.videoPlayback.clear()
		},

		/**
		 * Sends only the aggregate fraction, never a playback timeline.
		 *
		 * @param {HTMLVideoElement} media the video element
		 * @param {{status: object, lastTime: number, watchedSeconds: number}} playback accumulated playback state
		 */
		reportVideoWatch(media, playback) {
			const duration = Number(media.duration)
			if (Number.isFinite(duration) && duration > 0 && playback.watchedSeconds > 0) {
				this.tracker?.recordVideoWatchRatio(playback.status, playback.watchedSeconds / duration)
			}
		},

		setupIntersectionObserver() {
			this.observer = new IntersectionObserver((entries) => {
				if (entries[0].isIntersecting && !this.loading && !this.allLoaded && this.error === null) {
					this.infiniteHandler()
				}
			}, { rootMargin: '200px' })
			this.$nextTick(() => this.watchSentinel())
		},

		/** Lets the sentinel below the list ask for the next page. */
		watchSentinel() {
			if (this.observer && this.$refs.sentinel) {
				this.observer.observe(this.$refs.sentinel)
			}
		},

		/**
		 * Stops the sentinel asking after a failed page, where every reflow of
		 * the error block would otherwise retry at once.
		 */
		unwatchSentinel() {
			if (this.observer && this.$refs.sentinel) {
				this.observer.unobserve(this.$refs.sentinel)
			}
		},

		/**
		 * Reads the read marker once per visit to the notifications page,
		 * before anything is marked; switching the filter is the same visit.
		 */
		ensureMarker() {
			if (this.serverData.public || this.type !== 'notifications' || this.showParents) {
				this.markerAsked = false
				this.markerKnown = false
				this.seenUpTo = '0'
				this.markedUpTo = '0'

				return
			}
			if (this.markerAsked) {
				return
			}

			this.markerAsked = true
			this.notificationsStore.fetchLastRead().then((marker) => {
				this.seenUpTo = marker
				this.markerKnown = true
				this.armSeenTimer()
			})
		},

		/**
		 * Starts the dwell after which what is on screen counts as read; not
		 * on a hidden tab or an empty page. Also the `visibilitychange` handler.
		 */
		armSeenTimer() {
			// Activities and Direct messages carry a badge; home has the marker other clients share
			if (this.serverData.public || !['notifications', 'direct', 'home'].includes(this.type) || this.showParents) {
				return
			}
			if (this.entries.length === 0 || document.visibilityState === 'hidden') {
				return
			}

			clearTimeout(this.seenTimer)
			this.seenTimer = window.setTimeout(() => this.markSeen(), SEEN_AFTER)
		},

		/**
		 * Whether this card arrived since the reader last looked. Taken against
		 * the marker, since `dividerAt` is also 0 when every card is new.
		 *
		 * @param {object} entry a card, grouped or not
		 * @return {boolean}
		 */
		isUnread(entry) {
			// nothing is marked until the server has said where the marker is
			if (this.type !== 'notifications' || this.showParents || !this.markerKnown) {
				return false
			}

			// a marker of 0 means nothing was ever read, so every card is new
			return isNewerId(newestIdOf(entry), this.seenUpTo)
		},

		/**
		 * Handles "mark all read" from the page header.
		 *
		 * @param {number|string} marker the id it was marked up to
		 */
		onMarkedAllRead(marker) {
			this.seenUpTo = newerId(marker, this.seenUpTo)
			// so the dwell does not report it again
			this.markedUpTo = newerId(marker, this.markedUpTo)
		},

		/**
		 * Reports everything now on screen as read, up to the newest id;
		 * `newestIdOf()` covers every notification in a grouped card.
		 */
		markSeen() {
			this.seenTimer = -1
			if (this.serverData.public) {
				return
			}

			if (this.type === 'direct') {
				this.notificationsStore.markDirectMessagesRead()

				return
			}

			const newest = this.entries.reduce(
				(highest, entry) => newerId(newestIdOf(entry), highest),
				'0',
			)
			if (this.type === 'home') {
				// the position Mastodon apps share; the store says it once
				this.timelineStore.markHomeRead(newest)

				return
			}
			if (isNewerId(newest, this.markedUpTo)) {
				this.markedUpTo = newest
				this.notificationsStore.markNotificationsRead(newest)
			}
		},

		/** Starts this timeline over: a different type is a different list. */
		resetAndLoad() {
			this.generation += 1
			// requests in flight belong to the previous list, disowned above
			this.loading = false
			this.allLoaded = false
			this.error = null
			this.withheld = []
			this.setFocus(-1)
			this.pollFailureReported = false
			this.pollWait = 0
			this.pollSkip = 0
			// the dwell belonged to the previous list
			clearTimeout(this.seenTimer)
			this.seenTimer = -1
			this.ensureMarker()
			this.watchSentinel()
			this.loadFirstPage()
		},

		/** Recheck the first page without discarding cached posts or adding duplicates. */
		async retryProfileSync() {
			if (this.profileSyncChecking || this.loading) {
				return
			}
			const generation = this.generation
			this.profileSyncChecking = true
			this.timelineStore.cancelBackgroundRefetch()
			try {
				await this.timelineStore.fetchTimeline({})
				if (generation === this.generation) {
					this.allLoaded = this.timeline.length === 0 && !this.timelinePageFilling
					this.watchSentinel()
				}
			} catch (error) {
				if (generation === this.generation) {
					this.timelineStore.markProfileSyncFailed()
					this.allLoaded = this.timeline.length === 0
				}
				logger.debug('The profile synchronization check failed', { error })
			} finally {
				if (generation === this.generation) {
					this.profileSyncChecking = false
				}
			}
		},

		/** What the retry button does: the one thing that re-arms the sentinel after a failure. */
		retry() {
			this.error = null
			this.allLoaded = false
			this.watchSentinel()
			this.infiniteHandler()
		},

		async infiniteHandler() {
			if (this.loading) {
				return
			}
			this.loading = true

			const generation = this.generation
			const params = {}

			if (this.timeline.length !== 0) {
				// Page on the ids, as strings: the getter sorts by created_at,
				// and a federated post can have a high id with an old date. A
				// ranked list pages from where the last page ended.
				const ids = this.timeline.map((entry) => entry.id)
				const cursor = !this.chronological
					? ids[ids.length - 1]
					: (this.reverseOrder ? newestId(ids) : oldestId(ids))
				if (cursor !== undefined) {
					params[this.reverseOrder ? 'min_id' : 'max_id'] = cursor
				}
			}

			try {
				const response = await this.timelineStore.fetchTimeline(params)
				if (generation !== this.generation) {
					return
				}
				this.error = null
				// a /context response is the whole thread, not an array page
				this.allLoaded = Array.isArray(response)
					? response.length === 0 && !this.timelinePageFilling
					: !this.timelinePageFilling
			} catch (error) {
				if (generation !== this.generation) {
					return
				}
				logger.error('Failed to load more timeline entries', { error })
				// not allLoaded, which would show the empty state for a server error
				this.error = this.timeline.length === 0
					? t('social', 'The posts could not be loaded.')
					: t('social', 'No more posts could be loaded.')
				this.unwatchSentinel()
			} finally {
				// the newer request owns `loading` now
				if (generation === this.generation) {
					this.loading = false
					this.$emit('settled')
				}
			}
		},

		/** The polling tick; a hidden tab does not ask. */
		pollIfVisible() {
			if (document.visibilityState === 'hidden') {
				this.hiddenSince = this.hiddenSince || Date.now()
				return
			}

			// backing off after failures
			if (this.pollSkip > 0) {
				this.pollSkip -= 1
				return
			}

			this.fetchNewStatuses()
		},

		/**
		 * Handles a push event: Activities for a notification, every other list
		 * for a post. Checked here since switching timeline keeps this component.
		 *
		 * @param {string[]} kinds what arrived (`timelinePush.js`)
		 * @return {Promise<void>|undefined} settled once the new posts are in
		 */
		onPushed(kinds = PUSH_KINDS) {
			const wanted = this.type === 'notifications' ? [PUSH_NOTIFICATION] : [PUSH_HOME, PUSH_DIRECT]
			if (!kinds.some((kind) => wanted.includes(kind))) {
				return undefined
			}

			return this.fetchNewStatuses()
		},

		/** Catches up once, on the way back to a tab that was left. */
		pollOnReturn() {
			if (document.visibilityState !== 'visible' || this.hiddenSince === 0) {
				return
			}

			const away = Date.now() - this.hiddenSince
			this.hiddenSince = 0
			if (away >= this.pollEvery) {
				this.fetchNewStatuses()
			}
		},

		focusNext() {
			this.moveFocus(1)
		},

		focusPrevious() {
			this.moveFocus(-1)
		},

		/**
		 * Moves the keyboard's attention through the list and scrolls it into
		 * view, so j/k reads a timeline without touching the mouse.
		 *
		 * @param {number} step 1 for the next post, -1 for the previous one
		 */
		moveFocus(step) {
			// `entries`, not `timeline`: notifications are grouped into fewer cards
			if (this.entries.length === 0) {
				return
			}

			// with no focus, start from what is on screen
			const from = this.focused >= 0 ? this.focused + step : Math.max(this.firstOnScreen(), 0)
			const next = Math.min(Math.max(from, 0), this.entries.length - 1)
			this.setFocus(next)

			this.$nextTick(() => {
				const entries = this.$el.querySelectorAll('.timeline-entry')
				const entry = entries[next]
				if (entry === undefined) {
					return
				}

				this.watchFocused(entry)

				// real focus, not just a highlight, for keyboard and screen reader users
				entry.focus({ preventScroll: true })
				entry.scrollIntoView({
					block: 'center',
					behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
				})
			})
		},

		/** What a pull at the top of the list asks for: the newest posts, now. */
		async refresh() {
			if (this.refreshing) {
				return
			}

			this.refreshing = true
			try {
				if (this.error !== null) {
					this.retry()
				} else {
					this.withheld = []
					await this.fetchNewStatuses()
				}
			} finally {
				this.refreshing = false
			}
		},

		showArrived() {
			this.withheld = []
			// the app's content column, not the window: see utils/scroller.js
			const column = scroller() ?? window
			column.scrollTo({
				top: 0,
				behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
			})

			// scrolling does not move focus: focus the first new post
			this.$nextTick(() => {
				this.setFocus(0)
				const entry = this.$el.querySelector('.timeline-entry')
				entry?.focus({ preventScroll: true })
				if (entry) {
					this.watchFocused(entry)
				}
			})
		},

		/**
		 * Keeps posts that just arrived off the list until they are asked for.
		 *
		 * @param {object[]} posts what the server answered with
		 */
		withhold(posts) {
			const ids = posts.map((post) => String(post.id)).filter((id) => !this.withheld.includes(id))
			this.withheld = [...this.withheld, ...ids]
		},

		/**
		 * Puts the keyboard's attention on one post, or on none with -1, and
		 * tells the posts which one it is, since that is the one l/b/r act on.
		 *
		 * @param {number} index the post's place in `entries`
		 */
		setFocus(index) {
			this.focused = index
			eventBus.emit('timeline:focused', index >= 0 ? this.entries[index] : null)
			if (index < 0) {
				this.focusObserver?.disconnect()
			}
		},

		/**
		 * Lets go of the focused post once it has been scrolled out of view.
		 * Only after it was in view: j scrolls it in smoothly, and it starts
		 * off screen.
		 *
		 * @param {Element} entry the focused post's element
		 */
		watchFocused(entry) {
			if (typeof IntersectionObserver === 'undefined') {
				return
			}

			this.focusObserver?.disconnect()
			let seen = false
			this.focusObserver = new IntersectionObserver((changes) => {
				const visible = changes[changes.length - 1].isIntersecting
				if (visible) {
					seen = true
				} else if (seen) {
					this.setFocus(-1)
				}
			})
			this.focusObserver.observe(entry)
		},

		/**
		 * Lets go of the focused post when the keyboard leaves it: a click
		 * elsewhere, a dialog, the composer. Not when the window itself loses
		 * focus, since the keyboard comes back to the same post.
		 *
		 * @param {FocusEvent} event the focus leaving something in this list
		 */
		onFocusOut(event) {
			if (this.focused < 0 || !document.hasFocus()) {
				return
			}

			const entry = this.$el.querySelectorAll('.timeline-entry')[this.focused]
			const to = event.relatedTarget
			if (entry !== undefined && to instanceof Node && entry.contains(to)) {
				return
			}

			this.setFocus(-1)
		},

		/** @return {number} the first post in view, -1 when that cannot be told */
		firstOnScreen() {
			return [...this.$el.querySelectorAll('.timeline-entry')]
				.findIndex((entry) => entry.getBoundingClientRect().bottom > 0)
		},

		t,
		n,
		dayHeadingLabel,
		/**
		 * Catches up on what arrived since the newest post on screen.
		 *
		 * @param {number} depth how many pages have already been followed
		 */
		async fetchNewStatuses(depth = 0) {
			// a ranked feed has no top for new posts to arrive at
			if (this.showParents || !this.chronological) {
				return
			}
			// not while a page is loading, and an empty list that failed belongs to the retry button
			if (this.loading || (this.timeline.length === 0 && this.error !== null)) {
				return
			}

			// back at the top: show what was withheld
			if (depth === 0 && this.withheld.length > 0 && scrollOffset() <= ANNOUNCE_BELOW) {
				this.withheld = []
			}

			// newest by id, as a string, not this.timeline[0], which is sorted by created_at
			const newest = newestId(this.timeline.map((entry) => entry.id))

			try {
				const response = await this.timelineStore.fetchTimeline({
					min_id: newest,
				})
				this.pollFailureReported = false
				this.pollWait = 0
				this.pollSkip = 0

				if (response.length > 0) {
					// held back while the top of the list is out of sight, so nothing moves above the reader
					if (scrollOffset() > ANNOUNCE_BELOW) {
						this.withhold(response)
					}
					if (depth + 1 < MAX_CATCHUP_PAGES) {
						this.fetchNewStatuses(depth + 1)
					} else {
						logger.debug('Stopped catching up after the page cap', { pages: MAX_CATCHUP_PAGES })
					}
				}
			} catch (error) {
				logger.error('Failed to load newer timeline entries', { error })
				// double the wait each time, up to MAX_POLL_WAIT
				this.pollWait = Math.min(Math.max(this.pollWait, this.pollEvery) * 2, Math.max(MAX_POLL_WAIT, this.pollEvery))
				this.pollSkip = Math.max(0, Math.round(this.pollWait / this.pollEvery) - 1)
				// one toast, not one per tick
				if (!this.pollFailureReported) {
					this.pollFailureReported = true
					showError(t('social', 'Could not load the newest posts'))
				}
			}
		},
	},
}
</script>

<style scoped lang="scss" src="./TimelineList.scss"></style>
