<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__timeline">
		<!-- the timeline changes under the reader without a word otherwise: new
		     posts appear, a page loads, the end is reached, and none of it is
		     announced. Polite, so it waits for a gap rather than interrupting -->
		<div class="hidden-visually" role="status" aria-live="polite">
			{{ announcement }}
		</div>
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
		<!-- The grid is a different way of drawing the same timeline, not a
		     different timeline: paging, the new-post pill, the error state and
		     the empty state all stay here, because otherwise the grid would
		     have to reimplement every one of them. -->
		<ProfileMediaGrid
			v-if="display === 'grid'"
			:posts="timeline"
			:account="account"
			:loading="loading" />
		<transition-group
			v-else
			name="list"
			tag="ul"
			:class="{ 'timeline-list--settling': holding, 'timeline-list--swapping': swapping }">
			<template v-for="(entry, index) in entries" :key="entry.id">
				<!-- the two headings only appear when there is a boundary to
				     mark: a page that is all new, or all seen, is one run -->
				<li v-if="index === 0 && dividerAt > 0" class="timeline-divider timeline-divider--new">
					{{ t('social', 'New') }}
				</li>
				<li v-else-if="dividerAt > 0 && index === dividerAt" class="timeline-divider">
					{{ t('social', 'Earlier') }}
				</li>
				<!-- Where the reader had got to last time. Everything below it
				     they have seen, and is drawn a shade quieter, which is what
				     makes a timeline finite: a scroll with no bottom and no
				     marks in it is the same screen for ever. -->
				<li v-if="caughtUpAt > 0 && index === caughtUpAt" class="timeline-caughtup">
					<span class="timeline-caughtup__line" />
					<span class="timeline-caughtup__label">{{ caughtUpLabel }}</span>
					<span class="timeline-caughtup__line" />
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
					:unread="isUnread(entry)" />
			</template>
		</transition-group>
		<!--
			Not while a switch is settling. Going from My Feed to Local empties
			the list and fills it again about 150 ms later, and a skeleton in
			that gap is the content blinking out and back — the flicker people
			report when they use the switcher above. A list that had posts a
			moment ago keeps them, dimmed and inert, until the new ones arrive;
			only a genuinely cold list draws bones.
		-->
		<TimelineSkeleton v-if="display !== 'grid' && loading && timeline.length === 0 && !holding" />
		<!--
		  A failure used to set allLoaded, so the reader was shown "No posts
		  found / Posts from people you follow will show up here" for what was
		  a server error, and the observer never fired again: paging was dead
		  until a full reload. An error is now its own state, with a retry.
		-->
		<div v-if="error !== null" class="timeline-error" role="alert">
			<p class="timeline-error__message">
				{{ error }}
			</p>
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
			<!-- in both views: the grid used to carry an empty state of its own
			     that said "No photos yet" whatever the tab was, so an account
			     with no videos was told it had no photos. This one knows which
			     tab asked -->
			<EmptyContent v-if="showEmptyContent" :item="emptyContentData" />
		</div>
	</div>
</template>

<script>
import { showError } from '../services/toast.js'
import { offTimelinePush, onTimelinePush } from '../services/timelinePush.js'

import { translate, translatePlural } from '@nextcloud/l10n'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import ProfileMediaGrid from './ProfileMediaGrid.vue'
import TimelineEntry from './TimelineEntry.vue'
import TimelineSkeleton from './TimelineSkeleton.vue'
import WaveLoading from './WaveLoading.vue'

/**
 * How long a list counts as swapping after a held-over list is replaced, in
 * milliseconds: a little longer than an entry takes to rise in.
 */
const SWAP_SETTLE = 320

/**
 * The lists a switch holds the previous one over for even on a first visit:
 * the three scopes of the switcher, which are the same kind of list and
 * practically never empty, so the posts being left are a fair stand-in.
 */
const HELD_SCOPES = ['home', 'timeline', 'federated']
import EmptyContent from './EmptyContent.vue'
import logger from '../services/logger.js'
import eventBus, { NOTIFICATIONS_READ } from '../services/eventBus.js'
import { groupNotifications, newestIdOf } from '../services/notifications.js'
import { isNewerId, newerId, newestId, oldestId } from '../utils/snowflake.js'
import { scrollOffset, scroller } from '../utils/scroller.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useNotificationsStore } from '../store/notifications.js'
import { isRanked, useTimelineStore } from '../store/timeline.js'
import { isTracking } from '../services/interests.js'
import { contextFor, createInterestTracker } from '../services/interestTracker.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'
import { useServerData } from '../composables/useServerData.js'

/**
 * How many times fetchNewStatuses() may follow itself in one tick. It recursed
 * with no cap and terminated only because the server honours min_id; a server
 * that ignores it looped for as long as the tab was open.
 */
const MAX_CATCHUP_PAGES = 10

/**
 * How far down the reader has to be for arriving posts to be announced with
 * the pill rather than simply prepended. Above this the top of the list is on
 * screen and a post appearing there is its own announcement.
 */
const ANNOUNCE_BELOW = 240

/**
 * How long the notifications have to be on screen before they count as read.
 *
 * The rule, in one sentence: the page is read once it has been in front of the
 * reader for two seconds, and what it is read *up to* is the newest card that
 * was on it at that moment.
 *
 * It used to be read the instant it rendered — a watcher with `immediate: true`
 * — so a badge was cleared by the page merely existing. That marker is shared
 * with every other client, so opening the tab in a background window cleared
 * the reader's phone too, for notifications no human had seen. Two seconds is
 * not a claim that they were read; it is the shortest interval that cannot
 * happen by accident, and the tab has to be in front for it to pass at all.
 */
const SEEN_AFTER = 2000

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
		NcButton,
		TimelineEntry,
		TimelineSkeleton,
		WaveLoading,
		EmptyContent,
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

		/**
		 * How to draw the posts: as a list of entries, or as the grid of
		 * squares a profile shows on Pixelfed. Only the drawing differs --
		 * the timeline, its paging and its states are the same.
		 */
		display: {
			type: String,
			default: 'list',
			validator: (value) => ['list', 'grid'].includes(String(value)),
		},

		/** Whose profile the grid links its tiles into. */
		account: {
			type: String,
			default: '',
		},

		/**
		 * The title of the list being read, when this is a list's timeline and
		 * the server has said what it is called. Only the empty state uses it:
		 * "Nothing in Colleagues yet" names the list the reader is looking at,
		 * where "No posts found" could be about anything.
		 */
		listTitle: {
			type: String,
			default: '',
		},
	},

	/**
	 * `settled` fires whenever a fetch finishes, successfully or not — what a
	 * view needs to know before it can say anything about what the list does
	 * not* hold. It is not "loaded": a failure settles the list too.
	 */
	emits: ['settled'],

	setup() {
		const { serverData } = useServerData()
		const { currentUser } = useCurrentUser()

		return { serverData, currentUser }
	},

	data() {
		return {
			/**
			 * The reading tracker this list feeds, or null while there is
			 * none. Held raw: it carries an IntersectionObserver and its own
			 * timers, and proxying those deeply buys nothing.
			 *
			 * @type {ReturnType<typeof import('../services/interestTracker.js').createInterestTracker>|null}
			 */
			tracker: null,
			/**
			 * The posts of the list being left, kept on screen while the next
			 * one loads so a switch does not blink through a skeleton.
			 *
			 * @type {string[]}
			 */
			heldOver: [],
			/**
			 * Whether the held-over list is being replaced by the one that was
			 * asked for. For that moment the old entries go without their
			 * leave transition and the new ones rise together: a switch is
			 * one list becoming another, not a page arriving from nothing.
			 */
			swapping: false,
			/** @type {ReturnType<typeof setTimeout>|null} */
			swapTimer: null,
			infoHidden: false,
			state: [],
			intervalId: -1,
			pollEvery: 30000,
			/** when the tab was last hidden, so returning can catch up */
			hiddenSince: 0,
			/** posts that arrived while the reader was further down the page */
			arrived: 0,
			/** how tall the sticky composer above this list is, 0 when there is none */
			composerHeight: 0,
			/** watches that composer, only while the pill is on screen */
			composerObserver: null,
			/** index of the post the keyboard is on, -1 when none */
			focused: -1,
			loading: false,
			allLoaded: false,
			/**
			 * Which list the requests in flight belong to. Switching timeline
			 * used to leave `loading` set, so nothing was ever asked for the
			 * list now on screen, and the previous list's answer was committed
			 * under the new heading when it arrived.
			 */
			generation: 0,
			/** what went wrong, when something did; null while all is well */
			error: null,
			/** whether the polling failure has already been said once */
			pollFailureReported: false,
			observer: null,
			/**
			 * The server's read marker as it stood when this page opened, and
			 * what the "New" line is drawn against. Frozen on purpose: reading
			 * the page moves the marker, and a line that moved with it would
			 * rub out the very boundary it is there to show.
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
			/**
			 * Whether the server has answered where the marker is. Not the
			 * same question as `seenUpTo > 0`: an account that has never read
			 * anything has a marker of 0, and everything it has is new.
			 */
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
					title: t('social', 'No notifications found'),
					description: t('social', 'You have not received any notifications yet'),
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
					title: t('social', 'No liked posts found'),
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
					description: t('social', 'Posts with pictures will show up here'),
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

				interests: {
					illustration: 'quiet-timeline',
					title: t('social', 'Nothing here for you yet'),
					description: t('social', 'For you learns which hashtags you care about from how you read: the posts you spend time on, like, boost, reply to or open. Keep reading your other timelines, or tell it about a few hashtags yourself.'),
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
		...mapStores(useAccountStore, useNotificationsStore, useTimelineStore),

		/**
		 * Whether this list is in the order things were posted.
		 *
		 * For you is ranked instead, so nothing that reasons about "newer
		 * than" applies to it: there is no top to catch up on, no line where
		 * the reader left off, and the cursor is where the page ended rather
		 * than the oldest post on it. The same holds for the For you scope of
		 * Photos and Videos.
		 *
		 * @return {boolean}
		 */
		chronological() {
			return !isRanked({ type: this.type, params: this.timelineStore.params })
		},

		/**
		 * What For you hears this list's reading as, or null when it
		 * hears nothing: the feature is off, the reader opted out or paused,
		 * nobody is signed in, or this is not a timeline that teaches it.
		 *
		 * @return {string|null}
		 */
		interestContext() {
			if (this.serverData.public || !isTracking(this.serverData.interests)) {
				return null
			}

			// the thread's two lists are given whatever type the route
			// carries, which on a post's page is none
			return contextFor(this.$route?.name === 'single-post' ? 'single-post' : this.type)
		},

		/**
		 * What has just changed, in one sentence. Only the thing worth saying:
		 * a reader does not need to hear about every page that loads while
		 * they scroll, but they do need to know when nothing more is coming.
		 */
		announcement() {
			if (this.arrived > 0) {
				return translatePlural('social', '%n new post available', '%n new posts available', this.arrived)
			}
			if (this.loading) {
				return translate('social', 'Loading posts')
			}
			if (this.allLoaded && this.timeline.length > 0) {
				return translate('social', 'You have reached the end')
			}

			return ''
		},

		/** @return {string} which list the store is holding */
		timelineIdentity() {
			return this.timelineStore.getTimelineIdentity
		},

		/** @return {boolean} nothing to show, and nothing went wrong */
		showEmptyContent() {
			return this.error === null
				&& this.allLoaded
				&& this.timeline.length === 0
				&& this.emptyContentData.title !== ''
		},

		/**
		 * What to say when the list is empty. This used to write to
		 * `this.emptyContent[...]` from inside the computed, which mutated
		 * component data during evaluation and left the mutation behind for
		 * every later route.
		 *
		 * @return {object} a drawing, a title and a description
		 */
		emptyContentData() {
			// a filtered tab of a profile is empty for a different reason than
			// an account that has never posted: they may have posted plenty,
			// just none of this
			const media = String(this.$route.query?.media ?? '')
			const emptyTab = {
				image: {
					scene: 'tide',
					title: t('social', 'No photos yet'),
					description: t('social', 'Posts with pictures appear here.'),
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

			// what the page says it is, before what the URL happens to spell.
			// Bookmarks, Photos and a list all reach TimelineList as a `type`
			// and none of them as a route of that name, so a lookup that began
			// at the route fell through all three to "Posts from people you
			// follow will show up here" — which a list is not, and Bookmarks
			// least of all.
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
				if (this.$route.name === 'profile' && (this.serverData.public || this.$route.params.account !== this.currentUser.uid)) {
					return {
						...byName,
						title: this.$route.params.account + ' ' + t('social', 'hasn\'t tooted yet'),
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

			// a copy: .reverse() sorts in place, and this array comes from a
			// cached Vuex getter that every other reader shares
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
		 * The replies under a post, as the conversation they are: each reply
		 * followed by the replies to it, oldest first, with how deep it sits.
		 *
		 * They used to be one flat list, newest first — a reply to a reply
		 * landed above the post it answered, and nothing said which reply it
		 * answered (alohasocial/social#825, #1630). The tree is built from
		 * `in_reply_to_id` over what this page holds; a reply whose parent is
		 * not here (deleted, or a branch this instance holds only part of)
		 * keeps its place in time at the top level, and its own replies hang
		 * off it.
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
		 * Which entries have a reply under them, so the thread line can carry
		 * on down past them rather than stopping at every elbow.
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
		 * Where what the reader has already seen begins.
		 *
		 * The mark is the newest post that was on screen the last time they
		 * were here, remembered per timeline. 0 when there is nothing new --
		 * a line over the whole page separates nothing -- and 0 when there is
		 * nothing old either, because a line under everything is a line at the
		 * bottom of the screen that nobody ever reaches.
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
			return `social:seen:${this.timelineIdentity}`
		},

		/** @return {string} what the line says */
		caughtUpLabel() {
			if (this.lastSeenAt === 0) {
				return t('social', 'You are up to date')
			}

			const when = new Date(this.lastSeenAt)
			const time = when.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
			const today = new Date().toDateString() === when.toDateString()

			return today
				? t('social', 'Up to date since {time}', { time })
				: t('social', 'Up to date since {date}', { date: when.toLocaleDateString() })
		},

		/**
		 * Where the "N new posts" pill sits.
		 *
		 * It is sticky, and so is the box the reader writes in, which is taller
		 * and paints above it in the same stacking context. The pill only shows
		 * once the reader has scrolled a screen or so — which is exactly when
		 * the composer is stuck to the top — so it was painted behind it every
		 * single time. The composer's height is measured rather than assumed,
		 * because it grows: a content warning, a row of attachments, a poll.
		 *
		 * @return {object} the inline offset, or nothing when there is no composer
		 */
		pillStyle() {
			return this.composerHeight === 0 ? {} : { top: `${this.composerHeight + 8}px` }
		},

		/**
		 * What is actually drawn. The same list everywhere but the
		 * notifications page, where runs of the same reaction are folded into
		 * one card — twelve people liking one post is one thing that happened,
		 * not twelve, and quoting the post twelve times buried everything
		 * else. Paging still works off `timeline`, which is untouched.
		 *
		 * @return {object[]}
		 */
		entries() {
			// what was on screen when the switch started, until the new list
			// has something of its own: see `holding`
			const timeline = (this.timeline.length === 0 && this.holding)
				? this.heldOver
				: this.timeline

			if (this.type !== 'notifications') {
				return timeline
			}

			return groupNotifications(timeline)
		},

		/**
		 * Whether what is on screen is the list being left.
		 *
		 * Only while the next one is loading: once the request has settled on
		 * an empty list, that emptiness is the answer and the reader is owed
		 * it rather than somebody else's posts.
		 */
		holding() {
			return this.loading && this.timeline.length === 0 && this.heldOver.length > 0 && this.holdable
		},

		/**
		 * Whether the list being loaded is one worth holding the last one
		 * over for: a scope of the switcher, or any list that has shown a
		 * post before this session. A ranked feed opened for the first time
		 * may well be empty, and posts that appear only to vanish are worse
		 * than a moment of skeleton.
		 */
		holdable() {
			return HELD_SCOPES.includes(this.timelineStore.type) || this.timelineStore.wasFilled(this.timelineStore.getTimelineIdentity)
		},

		/**
		 * Where the already-seen part of the notifications page begins: the
		 * number of cards newer than the marker the server held when this page
		 * was opened. 0 when there is nothing new, or when this is not the
		 * notifications page.
		 *
		 * Taken against `seenUpTo`, which is frozen at that moment rather than
		 * following the store: the line has to stay where the reader found it
		 * for as long as they are on the page, or reading the page would erase
		 * the mark that says what they had not read.
		 *
		 * @return {number}
		 */
		dividerAt() {
			if (this.type !== 'notifications' || this.seenUpTo === '0') {
				return 0
			}

			const older = this.entries.findIndex((entry) => !isNewerId(newestIdOf(entry), this.seenUpTo))

			// every card is newer than the marker: the whole page is new, and
			// a heading over all of it separates nothing
			return older <= 0 ? 0 : older
		},
	},

	watch: {
		/**
		 * The router-view is no longer keyed on the full path, so switching
		 * timeline reuses this component: the paging state has to be put back
		 * by hand, or Home keeps Global's "you have reached the end" — and
		 * nothing would ask the server for the list that is now current.
		 */
		timelineIdentity() {
			if (!this.showParents) {
				// a list put back, or fetched ahead, replaces the one on
				// screen in the same render: drawn as a swap, like a held-over
				// list being replaced
				if (this.timelineStore.restored && this.timeline.length > 0) {
					this.startSwap()
				}
				this.rememberPlace()
				this.readPlace()
				this.resetAndLoad()
			}
			// a different list is a different page view: what was read on
			// the last one is said, and the new one starts counting afresh
			this.startTracking()
		},

		// turning learning off, or on, from the notice above the list
		interestContext() {
			this.startTracking()
		},

		// the pill is only on screen for as long as there is something to say,
		// so the composer is only watched for that long either
		arrived(count) {
			if (count > 0) {
				this.watchComposer()
			} else {
				this.unwatchComposer()
			}
		},

		// something new to look at restarts the dwell: what arrived while the
		// reader was here is read on the same terms as what was already there
		timeline(entries, previous) {
			if (entries.length > 0) {
				this.timelineStore.markFilled(this.timelineStore.getTimelineIdentity)
			}
			// the held-over list is on screen when the new one lands: that
			// render swaps them, so it is drawn as a swap
			if (entries.length > 0 && previous.length === 0 && this.heldOver.length > 0 && this.holdable) {
				this.startSwap()
			}
			// the last list that had anything is what a switch shows while the
			// next one is on its way. Taken here rather than when the switch is
			// noticed, because by then the store has already emptied it; ids
			// only, so holding one costs nothing.
			if (entries.length > 0) {
				this.heldOver = [...entries]
			}

			this.armSeenTimer()
		},

		// the entries are in the DOM once the render has been flushed, which
		// is when the tracker can find them
		entries: {
			handler() {
				this.syncTracked()
			},

			flush: 'post',
		},
	},

	mounted() {
		// a list that was already full when this mounted has had no change for
		// the watcher below to see, and the first switch away from it is
		// exactly the one worth not flickering
		if (this.timeline.length > 0) {
			this.heldOver = [...this.timeline]
		}

		// the ancestors above a post are read as much as the replies below it
		this.startTracking()

		// The ancestors list in the single-post view renders the same
		// /context response its sibling fetches: it used to page, poll and
		// observe on its own, so opening a thread made two identical
		// requests and left two 30-second intervals running. It also listened
		// for j/k, so one press moved the focus in both lists at once.
		if (this.showParents) {
			return
		}

		this.readPlace()

		eventBus.on('shortcut:next', this.focusNext)
		eventBus.on('shortcut:previous', this.focusPrevious)

		// coming back to the tab is what starts the dwell on a page that was
		// opened in the background, so this is registered whatever the
		// timeline is: the router-view is reused, and the notifications page
		// is often arrived at rather than landed on
		document.addEventListener('visibilitychange', this.armSeenTimer)
		this.ensureMarker()

		// the page header above this list can mark everything read; the line
		// this list froze when it opened has to move with it, or the heading
		// and the pills stay up over activities the badge no longer counts
		eventBus.on(NOTIFICATIONS_READ, this.onMarkedAllRead)

		this.loadFirstPage()
		// with notify_push the server tells us about new entries; polling
		// remains as a slow safety net. Without it, poll every 30 seconds.
		const hasPush = onTimelinePush(this.onPushed)
		this.pollEvery = (hasPush ? 300 : 30) * 1000
		this.intervalId = window.setInterval(() => this.pollIfVisible(), this.pollEvery)
		// a tab nobody is looking at does not need to ask; it catches up when
		// it comes back
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
		this.unwatchComposer()
		if (this.observer) {
			this.observer.disconnect()
		}
	},

	methods: {
		/**
		 * Where this reader had got to on this timeline, from last time.
		 *
		 * Per timeline and per browser, in local storage, for most lists: a
		 * convenience about where somebody was looking. The home timeline asks
		 * the server as well — the `home` marker of `GET /api/v1/markers`,
		 * which every Mastodon client reads and writes — so a feed read on the
		 * phone is up to date here, and the other way round. Whichever of the
		 * two is further along wins; the server answers when it answers, and
		 * the line is drawn from the browser's copy until then. A browser that
		 * refuses storage and a server with no marker yet simply draw no line,
		 * which is the state every timeline was in before.
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

			if (this.type !== 'home') {
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
				// only now does looking at the page count as reading it: the
				// line is drawn first, and the marker it is drawn against is
				// the one that was there before this visit moved it
				this.armSeenTimer()
			})
		},

		/** Remembers the newest post on this timeline as where the reader got to. */
		rememberPlace() {
			const newest = this.entries[0]
			if (this.isThread || this.type === 'notifications' || !this.chronological || !newest?.id) {
				return
			}

			try {
				window.localStorage.setItem(
					this.placeKey,
					JSON.stringify({ id: String(newest.id), at: Date.now() }),
				)
			} catch {
				// a browser that will not store it shows no line next time
			}
		},

		/**
		 * Asks for the first page, unless this list is already loaded.
		 *
		 * A timeline that came back with the reader — Back out of a post, most
		 * of all — is held by the store with every page they had read. Asking
		 * again here would have appended the page *after* those, which is both
		 * a request nobody needed and a list that changes height underneath the
		 * scroll offset being restored.
		 */
		async loadFirstPage() {
			if (!this.timelineStore.restored || this.timeline.length === 0) {
				await this.infiniteHandler()
			}

			// the router holds a restored scroll offset until this says the
			// list is on the page: before it is, the document is one screen
			// tall and the browser clamps the offset to the bottom of it
			await this.$nextTick()
			eventBus.emit('timeline:rendered')
		},

		/**
		 * Keeps `composerHeight` in step with the box the reader writes in.
		 *
		 * The composer is a sibling of this list rather than a child, so it is
		 * found by walking back from here: a page without one — a profile's
		 * grid, a thread — simply finds nothing and the pill keeps its own
		 * offset.
		 */
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

		/**
		 * Marks the next render as a swap, for as long as the entering
		 * entries take to rise: long enough for the leave to be skipped and
		 * the stagger to be read as off, short enough that a post removed
		 * afterwards fades as usual.
		 */
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
		 * Starts measuring what is read in this list for the For you feed, or
		 * stops, when it should not be: the one tracker the list had is
		 * always finished first, so its reading is sent under the context it
		 * was read in.
		 */
		startTracking() {
			this.stopTracking()

			const context = this.interestContext
			if (context === null || !this.$el) {
				return
			}

			this.tracker = createInterestTracker({
				context,
				isOwn: (status) => {
					const me = this.accountStore.currentAccount?.acct
					return me !== undefined && status.account?.acct === me
				},
			})
			// delegated, so a page of new posts needs nothing added. Capture,
			// because `play` does not bubble and a picture's own click handler
			// may stop the event before it would get here
			this.$el.addEventListener('click', this.onTrackedClick, true)
			this.$el.addEventListener('play', this.onTrackedPlay, true)
			this.$nextTick(() => this.syncTracked())
		},

		/** Finishes the tracker, sending what it still holds. */
		stopTracking() {
			if (!this.tracker) {
				return
			}

			this.tracker.destroy()
			this.tracker = null
			this.$el?.removeEventListener('click', this.onTrackedClick, true)
			this.$el?.removeEventListener('play', this.onTrackedPlay, true)
		},

		/** Hands the tracker the entries on the page, each with its post. */
		syncTracked() {
			// what is held over from the list being left is not this list
			// a grid is a wall of tiles, all of them on screen together, so
			// time in view says nothing about any one: there, only opening a
			// tile counts (`onTrackedClick`)
			if (!this.tracker || !this.$el || this.holding || this.display === 'grid') {
				return
			}

			const pairs = [...this.$el.querySelectorAll('[data-status-id]')]
				.map((element) => [element, this.timelineStore.getStatus(element.dataset.statusId)])
				.filter(([, status]) => status !== undefined)
			this.tracker.sync(pairs)
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
		 * A picture opened, or a link followed, from a post.
		 *
		 * Only a link out of the post counts: a hashtag or a mention goes
		 * somewhere inside this app, and says nothing about the post's
		 * subject that its hashtags do not already.
		 *
		 * @param {MouseEvent} event the click
		 */
		onTrackedClick(event) {
			const status = this.trackedStatusOf(event)
			if (status === undefined) {
				return
			}

			if (/** @type {HTMLElement} */ (event.target).closest('.post-attachments, .media-grid__link')) {
				this.tracker?.record(status, 'media')
				return
			}

			const link = /** @type {HTMLAnchorElement|null} */ (/** @type {HTMLElement} */ (event.target).closest('a[href]'))
			if (link && link.closest('.post-message, .post-card') && isLinkOut(link)) {
				this.tracker?.record(status, 'link')
			}
		},

		/** @param {Event} event a video or audio starting */
		onTrackedPlay(event) {
			const status = this.trackedStatusOf(event)
			if (status !== undefined) {
				this.tracker?.record(status, 'media')
			}
		},

		setupIntersectionObserver() {
			this.observer = new IntersectionObserver((entries) => {
				if (entries[0].isIntersecting && !this.loading && !this.allLoaded) {
					this.infiniteHandler()
				}
			}, { rootMargin: '200px' })
			this.$nextTick(() => {
				if (this.$refs.sentinel) {
					this.observer.observe(this.$refs.sentinel)
				}
			})
		},

		/**
		 * Reads where the reader had got to, once per visit to the
		 * notifications page.
		 *
		 * Asked before anything is marked, so the line lands where they left
		 * off rather than where they have just got to. Switching the filter is
		 * the same visit and asks nothing; leaving the page forgets, so the
		 * next visit draws its line against a marker this one has moved.
		 */
		ensureMarker() {
			if (this.type !== 'notifications' || this.showParents) {
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
		 * Starts the dwell after which what is on screen counts as read.
		 *
		 * Nothing happens on a tab nobody is looking at, or on a page with
		 * nothing on it yet; coming back to the tab arms it, which is why this
		 * is also the `visibilitychange` handler.
		 */
		armSeenTimer() {
			// the two lists that carry a badge: Activities, and Direct
			// messages -- which is one page with the messages on it rather
			// than exchanges to open one at a time, so having looked at it is
			// having read them -- and the home timeline, whose marker every
			// other client draws its own line from
			if (!['notifications', 'direct', 'home'].includes(this.type) || this.showParents) {
				return
			}
			if (this.entries.length === 0 || document.visibilityState === 'hidden') {
				return
			}

			clearTimeout(this.seenTimer)
			this.seenTimer = window.setTimeout(() => this.markSeen(), SEEN_AFTER)
		},

		/**
		 * Whether this card arrived since the reader last looked.
		 *
		 * Taken against the marker rather than against `dividerAt`, which is
		 * where it used to come from. That index is 0 both when none of the
		 * page is new and when all of it is -- a heading over everything
		 * separates nothing -- so reading the highlight off it left exactly
		 * the case the badge is loudest about, a page whose every card is new,
		 * with nothing marked on it at all.
		 *
		 * @param {object} entry a card, grouped or not
		 * @return {boolean}
		 */
		isUnread(entry) {
			// nothing is marked until the server has said where the marker is,
			// or a page would light up entirely for the moment before the
			// answer arrives and then settle, which reads as a fault
			if (this.type !== 'notifications' || this.showParents || !this.markerKnown) {
				return false
			}

			// a marker of 0 is an account that has never read anything -- not
			// one that has read everything. It is what the badge is counting
			// against, so it is what the cards are marked against
			return isNewerId(newestIdOf(entry), this.seenUpTo)
		},

		/**
		 * The reader said they were done with the lot, from the page header.
		 *
		 * @param {number|string} marker the id it was marked up to
		 */
		onMarkedAllRead(marker) {
			this.seenUpTo = newerId(marker, this.seenUpTo)
			// the dwell must not report it again: it is already reported
			this.markedUpTo = newerId(marker, this.markedUpTo)
		},

		/**
		 * Reports everything now on screen as read.
		 *
		 * The marker is "up to", so the newest id covers the whole page —
		 * grouped cards included, which is why the id comes from
		 * `newestIdOf()` rather than from the card's own `id`: a card that
		 * stands for nine favourites must not leave eight of them unread.
		 */
		markSeen() {
			this.seenTimer = -1

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
			// whatever is still in flight belongs to the list that was here a
			// moment ago; it is disowned above, and this is what lets the new
			// list ask at all
			this.loading = false
			this.allLoaded = false
			this.error = null
			this.arrived = 0
			this.focused = -1
			this.pollFailureReported = false
			// the dwell belonged to the list that was here; the new one earns
			// its own. What has already been reported read stays reported.
			clearTimeout(this.seenTimer)
			this.seenTimer = -1
			this.ensureMarker()
			this.loadFirstPage()
		},

		/** What the retry button does. */
		retry() {
			this.error = null
			this.allLoaded = false
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
				// The timeline getter sorts by created_at while min_id/max_id
				// filter on the id, and a federated post can have a high id
				// with an old date — so page on the ids themselves, or the
				// cursor never advances and the same page loops forever.
				//
				// As strings, end to end: a cursor rounded through a Number
				// either re-fetches the post it points at, so the end of the
				// list is never reached, or skips the rows between the two.
				//
				// A ranked list pages from where the last page ended: its
				// oldest post may be anywhere in it.
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
				// a /context response is the whole thread at once rather than a
				// page of one, and its `.length` is undefined — so `=== 0` was
				// never true and the sentinel kept asking for a next page that
				// does not exist
				this.allLoaded = Array.isArray(response) ? response.length === 0 : true
			} catch (error) {
				if (generation !== this.generation) {
					return
				}
				logger.error('Failed to load more timeline entries', { error })
				// not allLoaded: that told the observer to stop watching and
				// showed the reader an empty timeline for a server error
				this.error = this.timeline.length === 0
					? translate('social', 'The posts could not be loaded.')
					: translate('social', 'No more posts could be loaded.')
			} finally {
				// the newer request owns `loading` now
				if (generation === this.generation) {
					this.loading = false
					this.$emit('settled')
				}
			}
		},

		/**
		 * The polling tick. Asking while the tab is hidden is traffic nobody
		 * is waiting for — on an instance with many open tabs it is most of
		 * the traffic there is.
		 */
		pollIfVisible() {
			if (document.visibilityState === 'hidden') {
				this.hiddenSince = this.hiddenSince || Date.now()
				return
			}

			this.fetchNewStatuses()
		},

		/** What the server's push event runs. */
		onPushed() {
			this.fetchNewStatuses()
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
			// `entries`, not `timeline`: on the notifications page the two are
			// different lengths, and the highlight is drawn against the cards
			// that are actually on screen
			if (this.entries.length === 0) {
				return
			}

			const next = Math.min(Math.max(this.focused + step, 0), this.entries.length - 1)
			this.focused = next
			eventBus.emit('timeline:focused', this.entries[next])

			this.$nextTick(() => {
				const entries = this.$el.querySelectorAll('.timeline-entry')
				const entry = entries[next]
				if (entry === undefined) {
					return
				}

				// real focus, not just a highlight: without it the reader most
				// likely to be using j/k is told nothing at all, and scrolling
				// alone leaves the keyboard somewhere else entirely
				entry.focus({ preventScroll: true })
				entry.scrollIntoView({
					block: 'center',
					behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
				})
			})
		},

		showArrived() {
			this.arrived = 0
			// the app's content column, not the window: see utils/scroller.js
			const column = scroller() ?? window
			column.scrollTo({
				top: 0,
				behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
			})

			// scrolling moves the page, not the keyboard: send focus to the
			// first of the posts the reader just asked to see
			this.$nextTick(() => {
				this.focused = 0
				this.$el.querySelector('.timeline-entry')?.focus({ preventScroll: true })
			})
		},

		t: translate,
		n: translatePlural,
		/**
		 * Catches up on what arrived since the newest post on screen.
		 *
		 * @param {number} depth how many pages have already been followed
		 */
		async fetchNewStatuses(depth = 0) {
			// a ranked feed has no top for new posts to arrive at; pulling the
			// list again is how it is refreshed
			if (this.showParents || !this.chronological) {
				return
			}

			// Newest by id, not this.timeline[0] (sorted by created_at): a
			// federated post with a high id but an old date would otherwise
			// keep min_id stuck and this method would refetch forever. As a
			// string: rounded down through a Number it names a post already on
			// screen, which comes back on every tick and drives the catch-up
			// below to its page cap.
			const newest = newestId(this.timeline.map((entry) => entry.id))

			try {
				const response = await this.timelineStore.fetchTimeline({
					min_id: newest,
				})
				this.pollFailureReported = false

				if (response.length > 0) {
					// only worth announcing when the top of the list is out of
					// sight; up there the posts simply appear
					if (scrollOffset() > ANNOUNCE_BELOW) {
						this.arrived += response.length
					}
					if (depth + 1 < MAX_CATCHUP_PAGES) {
						this.fetchNewStatuses(depth + 1)
					} else {
						logger.debug('Stopped catching up after the page cap', { pages: MAX_CATCHUP_PAGES })
					}
				}
			} catch (error) {
				logger.error('Failed to load newer timeline entries', { error })
				// once, not once per tick: a server that is down produced an
				// endless stream of identical toasts every 30 seconds
				if (!this.pollFailureReported) {
					this.pollFailureReported = true
					showError(translate('social', 'Could not load the newest posts'))
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
// the list being left, while the next one loads: visibly not current, and not
// clickable — acting on a post that belongs to the timeline you just left is
// worse than waiting 150 ms for the one you asked for
.timeline-list--settling {
	opacity: .55;
	pointer-events: none;
	transition: opacity .15s ease-out;
}

.social__timeline {
	max-width: var(--social-column);
	margin: 0 auto;
	padding: 0 var(--social-column-gutter);

	ul {
		margin: 0;
		padding: 0;
		/* a leaving entry is taken out of the flow and sized to its nearest
		   positioned ancestor; without this that is the whole content area,
		   and the entry is drawn at the width of the page while it fades */
		position: relative;
	}

	.list-end {
		height: 1px;
	}

	.list-sentinel {
		min-height: 1px;
	}
}

.timeline-error {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	margin: 20px 0;
	padding: 24px 20px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	text-align: center;

	&__message {
		margin: 0;
		color: var(--color-text-lighter);
		line-height: 1.5;
	}
}

.new-posts-pill {
	position: sticky;
	// the fallback for a page with no composer above the list; where there is
	// one, the component sets `top` to just below it
	top: 8px;
	// above the composer rather than below it: the offset keeps the two apart,
	// and if the composer grows between two measurements the pill is still the
	// one you can see
	z-index: 101;
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 0 auto 8px;
	padding: 6px 16px;
	border: none;
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: bold;
	cursor: pointer;
	box-shadow: var(--social-elevation-raised);

	&:hover,
	&:focus-visible {
		background: var(--color-primary-element-hover);
	}
}

.pill-enter-active,
.pill-leave-active {
	transition: opacity .2s ease, transform .2s ease;
}

.pill-enter-from,
.pill-leave-to {
	opacity: 0;
	transform: translateY(-8px);
}

@media (prefers-reduced-motion: reduce) {
	.pill-enter-active,
	.pill-leave-active {
		transition: none;
	}
}
/* The line between what arrived since the reader last looked and what was
   already there. A heading rather than a rule: "New" and "Earlier" say what
   the two runs are, where a bare line only says that there are two of them. */
/**
 * The line that says where the reader had got to.
 *
 * A hairline either side of a few quiet words, and everything below it drawn
 * a shade back. What it does is make the timeline finite: a scroll with no
 * bottom and no marks in it is the same screen for ever.
 */
.timeline-caughtup {
	display: flex;
	align-items: center;
	gap: 12px;
	margin: 18px 0 14px;
	list-style: none;

	&__line {
		flex: 1 1 auto;
		block-size: 1px;
		background: var(--color-border);
	}

	&__label {
		flex: 0 0 auto;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
	}
}

.timeline-entry--seen {
	opacity: .72;
	transition: opacity .2s ease;

	&:hover,
	&:focus-within {
		opacity: 1;
	}
}

@media (prefers-reduced-motion: reduce) {
	.timeline-entry--seen {
		transition: none;
	}
}

.timeline-divider {
	margin: 4px 0 10px;
	padding: 0 2px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-weight: 700;
	letter-spacing: .04em;
	text-transform: uppercase;
	list-style: none;
}

/* "New" is the boundary the sidebar badge was pointing at, so it is drawn in
   the accent and carries a rule across the rest of the row: at 12px uppercase,
   maxcontrast grey is exactly as quiet as the timestamps it has to stand out
   from, and the heading read as the first line of the card beneath it.
   "Earlier" stays grey -- it labels what has already been read. */
.timeline-divider--new {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-primary-element);

	&::after {
		content: '';
		flex: 1;
		height: 1px;
		background: var(--color-primary-element);
		opacity: .35;
	}
}

/* where the keyboard is, for j/k readers */
.timeline-entry--focused :deep(.post-content),
.timeline-entry--focused :deep(.main-post) {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

/*
 * The list has been a <transition-group name="list"> for as long as it has
 * existed, with no rules to go with the name: deleting a post made it vanish
 * and everything under it jump. Entering is the entry's own business — it
 * staggers itself, see TimelineEntry — so only leaving and moving are here.
 */
.list-leave-active {
	transition: opacity .2s ease, transform .2s ease;
	/* taken out of the flow, or the gap it leaves closes in one step while it
	   is still fading in place */
	position: absolute;
	width: 100%;
}

.list-leave-to {
	opacity: 0;
	transform: translateY(-4px);
}

/* a swap replaces every entry at once: the old ones are not worth a fade
   each, and twenty of them fading on top of twenty rising is a slow page */
.timeline-list--swapping .list-leave-active {
	display: none;
}

.list-move {
	transition: transform .24s ease;
}

@media (prefers-reduced-motion: reduce) {
	.list-leave-active,
	.list-move {
		transition: none;
	}
}
</style>
