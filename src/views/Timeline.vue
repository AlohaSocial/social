<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__wrapper" :class="{ 'social__wrapper--direct': type === 'direct', 'social__wrapper--reading': display === 'list' && type !== 'direct', 'social__wrapper--videos': display === 'videos', 'social__wrapper--scoped': isFeed }">
		<!-- the first thing a new account sees; gone for good once closed -->
		<FirstRun v-if="showInfo" @done="hideInfo" />

		<!-- administrators' announcements, above the composer and only while
		     something is unread; not on a single post's page -->
		<Announcements v-if="type !== 'single-post' && type !== 'direct'" class="feed-notice" />

		<!-- the feed scopes, switched while reading, so the row sticks to the top
		     of the column -->
		<div v-if="isFeed" class="feed-scopes">
			<TimelineSwitcher
				:options="scopes"
				:value="scope"
				:label="t('social', 'Which posts to show')"
				:compact="scopesCompact"
				@intent="prefetchScope" />
			<FeedHelp :scope="scope" :underfilled="showRecommendationCoverageNote" />
		</div>

		<!-- said once, on the first feed of a session: what For you learns from -->
		<Transition name="feed-notice" appear>
			<InterestsNotice v-if="showInterestsNotice" class="feed-notice" />
		</Transition>

		<PageHeader
			class="timeline-heading-row"
			:class="{ 'timeline-heading-row--tag': type === 'tags', 'timeline-heading-row--compact': !headingIsVisible }"
			:style="tagStyle"
			:title="heading"
			:hideTitle="!headingIsVisible">
			<template v-if="type === 'photos' || (type === 'tags' && !followedTagsOnly)" #actions>
				<!-- one row, as on Videos: the layouts at the start, the upload at the end -->
				<div v-if="type === 'photos'" class="photo-header-bar">
					<TimelineSwitcher
						class="photo-header-bar__layouts"
						:options="photoLayouts"
						:value="selectedPhotoLayout"
						:label="t('social', 'Photo feed layout')"
						@update:value="choosePhotoLayout" />
					<div v-if="!settingsStore.getServerData.public" class="photo-compose-actions">
						<MediaUploadButton
							v-if="!timelineStore.composerDisplayStatus"
							accept="image/*,video/*"
							multiple
							:label="t('social', 'Share photos or a video')" />
						<DraftsButton :disabled="timelineStore.composerDisplayStatus" />
					</div>
				</div>
				<HashtagFollowButton
					v-if="type === 'tags' && !followedTagsOnly"
					:tag="String($route.params.tag)"
					@changed="onHashtagFollowChanged" />
			</template>
		</PageHeader>
		<HashtagFollowedList v-if="type === 'tags'" ref="followedHashtags" />

		<!-- shown once to accounts older than the notification policy; gone when
		     dismissed or once the policy is saved -->
		<div v-if="type === 'notifications' && notificationsStore.policyNotice" class="policy-notice feed-notice" role="note">
			<IconBellCogOutline :size="20" class="policy-notice__icon" />
			<p class="policy-notice__text">
				{{ t('social', 'You can now choose who reaches your activities — new accounts and people you don\'t follow can wait for your review.') }}
				<RouterLink :to="{ name: 'settings', hash: '#notification-policy' }">
					{{ t('social', 'Choose in Settings') }}
				</RouterLink>
			</p>
			<NcButton
				class="policy-notice__close"
				variant="tertiary"
				:aria-label="t('social', 'Close')"
				@click="notificationsStore.dismissPolicyNotice()">
				<template #icon>
					<IconClose :size="20" />
				</template>
			</NcButton>
		</div>

		<!-- the senders the notification policy is holding back, and their list on
		     Review -->
		<div v-if="type === 'notifications' && pendingRequests > 0" class="held-requests feed-notice">
			<div class="held-requests__row">
				<IconInboxOutline :size="20" />
				<button
					type="button"
					class="held-requests__toggle"
					:aria-expanded="showRequests ? 'true' : 'false'"
					@click="showRequests = !showRequests">
					{{ n('social', '{count} person is waiting — Review', '{count} people are waiting — Review', pendingRequests, { count: pendingRequests }) }}
					<IconChevronDown :size="20" class="held-requests__chevron" />
				</button>
				<RouterLink :to="{ name: 'settings', hash: '#notification-policy' }" class="held-requests__settings">
					{{ t('social', 'Who may reach you') }}
				</RouterLink>
			</div>
			<NotificationRequests v-if="showRequests" class="held-requests__list" @changed="onRequestsChanged" />
		</div>

		<!-- what the sidebar badge counts, with a way to mark it all read without
		     scrolling to the bottom -->
		<div v-if="type === 'notifications' && unreadActivities > 0" class="new-activities feed-notice">
			<span class="new-activities__count">
				{{ n('social', '%n new activity', '%n new activities', unreadActivities) }}
			</span>
			<NcButton variant="tertiary" :disabled="markingAllRead" @click="markAllRead">
				<template #icon>
					<IconCheckAll :size="20" />
				</template>
				{{ t('social', 'Mark all as read') }}
			</NcButton>
		</div>

		<!-- which kinds of activity to show: a filter of this page, not a page -->
		<TimelineSwitcher
			v-if="type === 'notifications'"
			class="notifications-filter"
			:options="notificationFilters"
			:value="notificationFilter"
			:label="t('social', 'Which activities to show')"
			@update:value="chooseNotificationFilter" />

		<FollowRequests v-if="type === 'notifications' && ['all', 'follows'].includes(notificationFilter)" />

		<!-- the shorts bar and the memories belong to the reader's
		     own feed only; kept alive across scopes so coming back to My Feed draws
		     them at once instead of refetching and pushing the posts down -->
		<KeepAlive>
			<ShortsBar v-if="isHome" />
		</KeepAlive>

		<KeepAlive>
			<OnThisDay v-if="isHome" />
		</KeepAlive>

		<DirectMessages
			v-if="type === 'direct'"
			:selectedConversationId="String($route.query.conversation ?? '')"
			@select="selectConversation" />
		<TimelineList
			v-else-if="!followedTagsOnly"
			:type="type"
			:listTitle="listTitle"
			:display="display" />

		<!-- the first post somebody ever publishes here, marked once -->
		<FirstPostCelebration v-if="celebratingFirstPost" @done="endCelebration" />
	</div>
</template>

<script>
import PageHeader from '../components/PageHeader.vue'
import { defineAsyncComponent } from 'vue'
import IconAccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import IconAccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import IconAt from 'vue-material-design-icons/At.vue'
import IconBell from 'vue-material-design-icons/Bell.vue'
import IconBellCogOutline from 'vue-material-design-icons/BellCogOutline.vue'
import IconCheckAll from 'vue-material-design-icons/CheckAll.vue'
import IconChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconEarth from 'vue-material-design-icons/Earth.vue'
import IconViewGridOutline from 'vue-material-design-icons/ViewGridOutline.vue'
import IconViewAgendaOutline from 'vue-material-design-icons/ViewAgendaOutline.vue'
import { photoLayout, rememberPhotoLayout } from '../services/photoLayout.js'
import IconHeart from 'vue-material-design-icons/Heart.vue'
import IconHome from 'vue-material-design-icons/Home.vue'
import IconInboxOutline from 'vue-material-design-icons/InboxOutline.vue'
import IconMessagePlusOutline from 'vue-material-design-icons/MessagePlusOutline.vue'
import IconPoll from 'vue-material-design-icons/Poll.vue'
import IconRepeat from 'vue-material-design-icons/Repeat.vue'
import IconTagHeart from 'vue-material-design-icons/TagHeart.vue'
import DraftsButton from '../components/DraftsButton.vue'
import MediaUploadButton from '../components/MediaUploadButton.vue'
import TimelineList from './../components/TimelineList.vue'
import TimelineSwitcher from './../components/TimelineSwitcher.vue'
import FirstPostCelebration from './../components/FirstPostCelebration.vue'
import Announcements from './../components/Announcements.vue'
import HashtagFollowButton from './../components/HashtagFollowButton.vue'
import FeedHelp from './../components/FeedHelp.vue'
import InterestsNotice from './../components/InterestsNotice.vue'
import OnThisDay from './../components/OnThisDay.vue'
import ShortsBar from './../components/ShortsBar.vue'
import { tagStyle } from '../utils/tagColour.js'
import HashtagFollowedList from './../components/HashtagFollowedList.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import { claimNoticeForPage } from '../services/infoCards.js'
import eventBus, { NOTIFICATIONS_READ } from './../services/eventBus.js'
import { rememberFilter, rememberedFilter } from './../services/notifications.js'
import { hasInterestsFeed, isTracking } from './../services/interests.js'
import { fetchSavedFeeds, uriOf } from './../services/blueskyFeeds.js'
import { contextFor } from './../services/interestTracker.js'
import { setPageTitle } from './../services/pageTitle.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useNotificationsStore } from '../store/notifications.js'
import { useSettingsStore } from '../store/settings.js'
import { isPersonalizedFeed, useTimelineStore } from '../store/timeline.js'
import { scrollOffset, scroller } from '../utils/scroller.js'

// only drawn when somebody asks to review who is waiting
const FollowRequests = defineAsyncComponent(() => import(/* webpackChunkName: "follow-requests" */'../components/FollowRequests.vue'))
const NotificationRequests = defineAsyncComponent(() => import(/* webpackChunkName: "notification-requests" */'../components/NotificationRequests.vue'))

// the conversations page and a new account's welcome, both rarely opened
const DirectMessages = defineAsyncComponent(() => import(/* webpackChunkName: "direct-messages" */'../components/DirectMessages.vue'))
const FirstRun = defineAsyncComponent(() => import(/* webpackChunkName: "first-run" */'../components/FirstRun.vue'))

// how far down the scopes fold to icons, and how near the top they open again
const SCOPES_FOLD_AT = 120
const SCOPES_OPEN_AT = 40

export default {
	name: 'Timeline',
	components: {
		PageHeader,
		Announcements,
		IconCheckAll,
		NcButton,
		FirstPostCelebration,
		FirstRun,
		HashtagFollowButton,
		HashtagFollowedList,
		FeedHelp,
		FollowRequests,
		InterestsNotice,
		OnThisDay,
		ShortsBar,
		TimelineList,
		MediaUploadButton,
		DraftsButton,
		DirectMessages,
		TimelineSwitcher,
		IconBellCogOutline,
		IconClose,
		IconInboxOutline,
		IconChevronDown,
		NotificationRequests,
	},

	data() {
		return {
			infoHidden: false,
			/** whether the reader has scrolled far enough for the scopes to fold to icons */
			scopesCompact: false,
			/** @type {Element|null} the column whose scrolling folds the scopes */
			scopeScroller: null,
			/** the frame the next scroll check is waiting for */
			scopeFrame: 0,
			selectedPhotoLayout: photoLayout(),
			/** the title of the list being read, once the server has said */
			listTitle: '',
			/** which kinds of activity the notifications page shows, remembered by the browser */
			notificationFilter: rememberedFilter(),
			/** while the marker is being moved, so it cannot be moved twice */
			markingAllRead: false,
			/** whether the held senders are unfolded under their count */
			showRequests: false,
			/** whether this page is the one the learning notice was given to */
			interestsNoticeHere: false,
		}
	},

	computed: {
		...mapStores(useAccountStore, useNotificationsStore, useSettingsStore, useTimelineStore),

		/** @return {number} how many senders the policy is holding back */
		pendingRequests() {
			return this.notificationsStore.pendingRequests
		},

		/**
		 * How many activities the sidebar badge is counting, from the same source so
		 * the two numbers cannot disagree.
		 *
		 * @return {number}
		 */
		unreadActivities() {
			return this.notificationsStore.unreadNotifications
		},

		/**
		 * The tag's own colour, on a tag page only.
		 *
		 * @return {object|null} custom properties, or null off a tag page
		 */
		tagStyle() {
			return this.type === 'tags' && !this.followedTagsOnly ? tagStyle(String(this.$route.params.tag)) : null
		},

		/**
		 * `/timeline/tags` with no tag: the followed-hashtags list that Explore and
		 * the switch-in wizard link to, with no timeline of its own.
		 *
		 * @return {boolean}
		 */
		followedTagsOnly() {
			return this.type === 'tags' && !this.$route.params.tag
		},

		/** What this timeline is, in the words the sidebar uses for it. */
		heading() {
			switch (this.type) {
				case 'tags':
					return this.followedTagsOnly ? t('social', 'Followed hashtags') : '#' + this.$route.params.tag
				case 'list':
					// fetched here too, so a link opened cold has a heading
					return this.listTitle || t('social', 'List')
				case 'bluesky':
					return this.listTitle || t('social', 'Bluesky feed')
				case 'photos':
					return t('social', 'Photos')
				case 'videos':
					return t('social', 'Videos')
				case 'link':
					// the article's host is all that is known before the first post arrives
					return this.linkHost || t('social', 'Link')
				case 'notifications':
					// the sidebar calls it Activities; the route keeps the API's name
					return t('social', 'Activities')
				case 'direct':
					return t('social', 'Direct messages')
				case 'timeline':
				// what the sidebar calls Local: the store asks for `local: true`
					return t('social', 'Local timeline')
				case 'federated':
					return t('social', 'Global timeline')
				case 'interests':
					return t('social', 'For you')
				case 'favourites':
					return t('social', 'Favorites')
				case 'bookmarks':
					return t('social', 'Bookmarks')
				case 'single-post':
					return t('social', 'Post')
				default:
					return t('social', 'Home timeline')
			}
		},

		/**
		 * @return {boolean} whether the three scopes are what this page shows
		 */
		isFeed() {
			return ['home', 'timeline', 'federated', 'interests', 'photos', 'videos'].includes(this.type)
		},

		/**
		 * Whether this page is one page read at three scopes (Photos and Videos), so
		 * the sidebar entry stays lit whichever scope is chosen.
		 *
		 * @return {boolean}
		 */
		isScopedPage() {
			return ['photos', 'videos'].includes(this.type)
		},

		/** @return {object[]} presentation choices, independent of feed scope */
		photoLayouts() {
			return [
				{ value: 'list', label: t('social', 'Media feed'), icon: IconViewAgendaOutline },
				{ value: 'grid', label: t('social', 'Tiles'), icon: IconViewGridOutline },
			]
		},

		/** @return {string} shared list, media grid or video browse presentation */
		display() {
			// the Videos page has its own "Upload a video" in place of the composer
			if (this.type === 'videos') {
				return 'videos'
			}

			return this.type === 'photos' ? this.selectedPhotoLayout : 'list'
		},

		/**
		 * Whether this is the reader’s own feed, where the memories and
		 * the Shorts bar belong. `type` reports the home route, which has no `type`
		 * parameter, as `'home'`.
		 *
		 * @return {boolean}
		 */
		isHome() {
			return this.type === 'home'
		},

		/**
		 * The three distances the same posts can be read at, for the switcher.
		 *
		 * On Photos and Videos the scope rides in the query, so each stays one page
		 * and one sidebar entry. On the feed each scope is its own route, and home is
		 * the route with no `type`; no timeline is served under `type: 'home'`.
		 *
		 * @return {object[]} what to give the switcher
		 */
		scopes() {
			const routeFor = (scope) => {
				if (this.isScopedPage) {
					return {
						name: 'timeline',
						params: { type: this.type },
						query: scope === 'home' ? {} : { scope },
					}
				}

				return scope === 'home'
					? { name: 'timeline' }
					: { name: 'timeline', params: { type: scope } }
			}

			const scopes = [
				// "My Feed": next to Local and Global, what distinguishes it is whose posts it holds
				{ value: 'home', label: t('social', 'My Feed'), icon: IconHome, to: routeFor('home') },
				{ value: 'timeline', label: t('social', 'Local'), icon: IconAccountMultiple, to: routeFor('timeline') },
				{ value: 'federated', label: t('social', 'Global'), icon: IconEarth, to: routeFor('federated') },
			]

			// ranked by subject rather than by whom the reader follows, so it sits beside
			// My Feed; on Photos and Videos the server ranks that kind on its own
			if (this.hasInterestsTab) {
				scopes.splice(1, 0, {
					value: 'interests',
					label: t('social', 'For you'),
					icon: IconTagHeart,
					to: this.isScopedPage ? routeFor('interests') : { name: 'timeline', params: { type: 'interests' } },
				})
			}

			return this.settingsStore.getServerData.public
				? scopes.filter(({ value }) => value !== 'home')
				: scopes
		},

		/**
		 * Whether the reader has For you: the administrators have it on
		 * and the reader has not opted out. Paused still has the feed.
		 *
		 * @return {boolean}
		 */
		hasInterestsTab() {
			const interests = this.settingsStore.getServerData.interests
			return !this.settingsStore.getServerData.public
				&& (interests?.recommendationsEnabled === true || hasInterestsFeed(interests))
		},

		/**
		 * Whether to say, once, that reading is being learned from: only while
		 * it is, only until the reader has answered, and only on a page whose
		 * reading counts.
		 *
		 * @return {boolean}
		 */
		interestsNoticeDue() {
			const interests = this.settingsStore.getServerData.interests

			return !this.settingsStore.getServerData.public
				&& isTracking(interests)
				&& interests.noticeAcknowledged !== true
				&& contextFor(this.type) !== null
		},

		/**
		 * Whether the learning notice is on this page: due, and the first page of the
		 * session it was due on, so it is not repeated above every feed.
		 *
		 * @return {boolean}
		 */
		showInterestsNotice() {
			return this.interestsNoticeDue && this.interestsNoticeHere
		},

		/**
		 * Which of the three scopes is being read: from the query on Photos and
		 * Videos, the type everywhere else. An unknown query value falls back to the
		 * default, since it comes from the address bar. `interests` is For you.
		 *
		 * @return {string} `home`, `timeline`, `federated` or `interests`
		 */
		scope() {
			if (!this.isScopedPage) {
				return this.type
			}

			const scope = String(this.$route.query.scope ?? '')
			if (scope === 'interests' && this.hasInterestsTab) {
				return scope
			}

			return ['timeline', 'federated'].includes(scope) ? scope : 'home'
		},

		/** Whether this ranked feed has fewer eligible posts than the requested page. */
		showRecommendationCoverageNote() {
			const coverage = this.timelineStore.recommendationCoverage[this.timelineStore.getTimelineIdentity]
			const scope = this.isScopedPage ? this.scope : this.timelineStore.params?.scope

			return isPersonalizedFeed({ type: this.type, params: { scope } })
				&& coverage?.state === 'UNDERFILLED'
		},

		/** Only the feed and tag headings are drawn; the rest name the view for screen readers. */
		headingIsVisible() {
			return this.type === 'tags' || this.type === 'list' || this.type === 'bluesky'
		},

		/**
		 * The filters over the notifications, in the words of the sidebar:
		 * everything, or one kind of activity at a time.
		 *
		 * @return {object[]} what to give the switcher
		 */
		notificationFilters() {
			return [
				{ value: 'all', label: t('social', 'All'), icon: IconBell },
				{ value: 'mentions', label: t('social', 'Mentions'), icon: IconAt },
				{ value: 'favourites', label: t('social', 'Favorites'), icon: IconHeart },
				{ value: 'boosts', label: t('social', 'Boosts'), icon: IconRepeat },
				{ value: 'follows', label: t('social', 'Follows'), icon: IconAccountPlusOutline },
				{ value: 'polls', label: t('social', 'Polls'), icon: IconPoll },
				{ value: 'edits', label: t('social', 'Edits'), icon: IconMessagePlusOutline },
			]
		},

		/** @return {string} what identifies this timeline, params included */
		timelineKey() {
			return this.type + '|' + JSON.stringify(this.params)
		},

		params() {
			if (this.$route.name === 'tags') {
				return { tag: this.$route.params.tag }
			} else if (this.$route.name === 'list') {
				return { id: this.$route.params.id }
			} else if (this.$route.name === 'bluesky-feed') {
				return { feed: uriOf(this.$route.params) }
			} else if (this.$route.name === 'single-post') {
				return this.$route.params
			} else if (this.isScopedPage) {
				// part of the timeline identity, so changing the scope refetches
				return { scope: this.scope }
			} else if (this.type === 'link') {
				// the article being talked about; another link is another list
				return { url: this.linkUrl }
			} else if (this.type === 'notifications') {
				// a filter is a question for the server, so another filter is another list
				return { filter: this.notificationFilter }
			}
			return {}
		},

		/**
		 * The article a `link` page is about, as the address bar carries it.
		 *
		 * @return {string} the URL, or '' when the page was opened without one
		 */
		linkUrl() {
			return String(this.$route.query.url ?? '')
		},

		/**
		 * Where that article lives, for the heading; '' when the URL does not parse.
		 *
		 * @return {string} the host, or '' when there is nothing to show
		 */
		linkHost() {
			try {
				return new URL(this.linkUrl).host
			} catch {
				return ''
			}
		},

		/**
		 * Which list this is, narrowed from the route parameter's `string | string[]`.
		 *
		 * @return {string}
		 */
		type() {
			if (this.$route.name === 'tags') {
				return 'tags'
			}
			if (this.$route.name === 'list') {
				return 'list'
			}
			if (this.$route.name === 'bluesky-feed') {
				return 'bluesky'
			}
			if (this.$route.params.type) {
				return String(this.$route.params.type)
			}
			return 'home'
		},

		showInfo() {
			// `firstrun` from the server, or `?welcome=1`, which the setup screen
			// reloads with and Settings links to
			const replay = this.$route.query?.replay === '1'
			const requested = this.settingsStore.getServerData.firstrun || this.$route.query?.welcome === '1'
			let dismissedLocally = false
			try {
				dismissedLocally = window.localStorage.getItem(`social:introduction:${this.accountStore.currentAccountHandle}`) === 'dismissed'
			} catch {
				// Storage can be disabled by the browser; the server-side setting
				// remains the source of truth in that case.
			}

			const dismissed = this.settingsStore.getServerData.introductionDismissed === true || dismissedLocally
			return (replay || (requested && !dismissed)) && !this.infoHidden
		},

		/** @return {boolean} whether the first-post celebration is on screen */
		celebratingFirstPost() {
			return this.timelineStore.isCelebratingFirstPost
		},

	},

	watch: {
		interestsNoticeDue: {
			immediate: true,
			handler() {
				this.claimInterestsNotice()
			},
		},

		// the tab, the history and a screen reader name the page by its heading
		heading: {
			immediate: true,
			handler(heading) {
				setPageTitle(heading)
			},
		},

		// this view is reused across timelines, so the store must be told to switch
		timelineKey() {
			if (this.type !== 'direct') {
				this.timelineStore.changeTimelineType({ type: this.type, params: this.params })
			}
			this.fetchListTitle()
			this.fetchPendingRequests()
			this.claimInterestsNotice()
		},
	},

	beforeMount() {
		if (this.type !== 'direct') {
			this.timelineStore.changeTimelineType({ type: this.type, params: this.params })
		}
		this.fetchListTitle()
		this.fetchPendingRequests()
	},

	mounted() {
		eventBus.on('post-published', this.onPostPublished)
		this.scopeScroller = scroller()
		this.scopeScroller?.addEventListener('scroll', this.onScopeScroll, { passive: true })
	},

	beforeUnmount() {
		eventBus.off('post-published', this.onPostPublished)
		this.scopeScroller?.removeEventListener('scroll', this.onScopeScroll)
		cancelAnimationFrame(this.scopeFrame)
		// navigating away mid-celebration must not leave the flag set for the next timeline
		if (this.celebratingFirstPost) {
			this.timelineStore.endFirstPostCelebration()
		}
	},

	methods: {
		/**
		 * Folds the scopes to icons once the reader is well into the feed and
		 * opens them again near the top. The two thresholds are apart so a
		 * reader resting on one does not make the row flicker.
		 */
		onScopeScroll() {
			cancelAnimationFrame(this.scopeFrame)
			this.scopeFrame = requestAnimationFrame(() => {
				const offset = scrollOffset()
				if (offset > SCOPES_FOLD_AT) {
					this.scopesCompact = true
				} else if (offset < SCOPES_OPEN_AT) {
					this.scopesCompact = false
				}
			})
		},

		/** Asks whether the learning notice, when due, belongs to this page. */
		claimInterestsNotice() {
			this.interestsNoticeHere = this.interestsNoticeDue
				&& claimNoticeForPage('interests-notice-v1', this.timelineKey)
		},

		choosePhotoLayout(layout) {
			if (!['list', 'grid'].includes(layout)) {
				return
			}
			this.selectedPhotoLayout = layout
			rememberPhotoLayout(layout)
		},

		/**
		 * Fetches the first page of a scope the reader is pointing at on the
		 * switcher, so the click shows it at once. Only on that intent, since the
		 * federated scope is the largest page there is.
		 *
		 * @param {string} value the scope's timeline type
		 */
		prefetchScope(value) {
			if (value === this.type || !this.isFeed || this.isScopedPage
				|| !this.scopes.some((scope) => scope.value === value)) {
				return
			}

			this.timelineStore.prefetchTimeline({ type: value })
		},

		/**
		 * Keep the selected exchange in the address so it can be reopened.
		 *
		 * @param {string} id conversation id, or empty to close the selected thread
		 */
		selectConversation(id) {
			const query = { ...this.$route.query }
			if (id) {
				query.conversation = id
			} else {
				delete query.conversation
			}
			this.$router.replace({
				name: this.$route.name,
				params: this.$route.params,
				query,
			})
		},

		/** Asks for the list's title; nothing to ask when this is not a list. */
		async fetchListTitle() {
			if (this.type === 'bluesky') {
				this.fetchFeedName()
				return
			}
			if (this.type !== 'list') {
				this.listTitle = ''
				return
			}
			const id = this.$route.params.id
			try {
				const { data } = await axios.get(generateUrl(`apps/social/api/v1/lists/${id}`))
				// the reader may have moved on while the server was answering
				if (this.type === 'list' && this.$route.params.id === id) {
					this.listTitle = data?.title ?? ''
				}
			} catch {
				this.listTitle = ''
			}
		},

		/** A Bluesky feed's name, when the reader keeps it. */
		async fetchFeedName() {
			const uri = this.params.feed
			this.listTitle = ''
			try {
				const feed = (await fetchSavedFeeds()).find((saved) => saved.uri === uri)
				if (this.type === 'bluesky' && this.params.feed === uri) {
					this.listTitle = feed?.name ?? ''
				}
			} catch {
				this.listTitle = ''
			}
		},

		hideInfo() {
			this.infoHidden = true
			// kept with the account, so it survives refreshes and other devices
			try {
				window.localStorage.setItem(`social:introduction:${this.accountStore.currentAccountHandle}`, 'dismissed')
			} catch {
				// storage can be disabled; the server-side setting is the source of truth
			}
			try {
				axios.post(generateUrl('apps/social/api/v1/introduction/dismiss'))?.catch(() => {})
			} catch {
				// the local dismissal covers this session if the endpoint cannot be reached
			}
			this.settingsStore.setServerDataEntry({ key: 'introductionDismissed', value: true })
			if (this.$route.query?.welcome !== undefined || this.$route.query?.replay !== undefined) {
				const query = { ...this.$route.query }
				delete query.welcome
				delete query.replay
				this.$router.replace({ query })
			}
		},

		/**
		 * @param {string} filter one of the keys of NOTIFICATION_FILTERS
		 */
		chooseNotificationFilter(filter) {
			this.notificationFilter = filter
			rememberFilter(filter)
		},

		/**
		 * Asks how many senders are held. The list starts folded unless the address
		 * asks for it (`?requests=1`, linked from Blocking in Settings).
		 */
		fetchPendingRequests() {
			this.showRequests = this.type === 'notifications' && this.$route?.query?.requests === '1'
			if (this.type === 'notifications') {
				this.notificationsStore.fetchPendingRequests()
			}
		},

		/**
		 * The list under the count changed; the count follows, and an emptied
		 * list folds away.
		 *
		 * @param {number} left how many senders are still waiting
		 */
		onRequestsChanged(left) {
			this.notificationsStore.setPendingRequests(left)
			if (left === 0) {
				this.showRequests = false
			}
		},

		/**
		 * Marks all activities read, whatever the filter shows, and tells the list
		 * where the marker ended up so it can redraw its new/seen boundary.
		 */
		async markAllRead() {
			this.markingAllRead = true
			try {
				const marker = await this.notificationsStore.markAllRead()
				// a string, and a twenty-digit one: see `newestIdOf()`
				if (marker !== '0') {
					eventBus.emit(NOTIFICATIONS_READ, marker)
				}
			} finally {
				this.markingAllRead = false
			}
		},

		/** A post went out; the store decides whether it was the reader's first. */
		onPostPublished() {
			this.timelineStore.celebrateFirstPost()
		},

		/** The list of followed hashtags is stale the moment one is followed. */
		onHashtagFollowChanged() {
			/** @type {{refresh?: () => void}|undefined} */ (this.$refs.followedHashtags)?.refresh?.()
		},

		endCelebration() {
			this.timelineStore.endFirstPostCelebration()
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/layout.scss' as layout;

/*
 * The column only. `.social__timeline` is another component's root, which a
 * scoped rule here would reach with equal specificity, so it is left alone.
 */
.social__wrapper {
	max-width: var(--social-column);
	margin: 0 auto;
	padding: 0;
}

/*
 * Information cards share one centred layout across every feed, as wide as
 * a post's own box (TimelineList.scss).
 */
.social__wrapper > :deep(.feed-notice) {
	position: relative;
	box-sizing: border-box;
	width: var(--social-card-width);
	margin: 8px auto 12px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	text-align: center;
}

:deep(.feed-notice .info-card__header) {
	position: relative;
	min-height: 32px;
	justify-content: center;
	padding-inline: 32px;
}

:deep(.feed-notice .info-card__header > button),
:deep(.feed-notice .interests-banner > button),
:deep(.feed-notice.interests-banner > button),
:deep(.feed-notice .policy-notice__close) {
	position: absolute;
	inset-block-start: 0;
	inset-inline-end: 0;
}

:deep(.feed-notice .info-card__header h2),
:deep(.feed-notice .announcements__title) {
	font-size: 15px;
	font-weight: 600;
}

:deep(.feed-notice .info-card__body),
:deep(.feed-notice .interests-notice__text),
:deep(.feed-notice .interests-banner__text),
:deep(.feed-notice .policy-notice__text) {
	max-width: 68ch;
	flex: 0 1 auto;
	margin-inline: auto;
	text-align: center;
	overflow-wrap: anywhere;
}

:deep(.feed-notice.interests-notice),
:deep(.feed-notice .interests-notice),
:deep(.feed-notice.policy-notice),
:deep(.feed-notice .policy-notice) {
	position: relative;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
}

:deep(.feed-notice .interests-notice),
:deep(.feed-notice .policy-notice) {
	margin: 0;
	padding: 0 32px;
	border: 0;
	background: transparent;
}

:deep(.feed-notice.policy-notice) {
	padding-inline: 44px;
}

:deep(.feed-notice.interests-banner > button),
:deep(.feed-notice.policy-notice .policy-notice__close) {
	inset-block-start: 8px;
	inset-inline-end: 8px;
}

:deep(.feed-notice .interests-banner__link) {
	white-space: normal;
}

:deep(.feed-notice .interests-notice__actions),
:deep(.feed-notice .held-requests__row) {
	justify-content: center;
	margin-inline: auto;
}

/* an announcement is read as text: only its heading is centred */
:deep(.feed-notice .announcement) {
	text-align: start;
}

:deep(.feed-notice .announcements__header) {
	justify-content: center;
}

:deep(.feed-notice .announcements__earlier) {
	display: flex;
	margin: 8px auto 0;
}

:deep(.feed-notice .interests-banner > button),
:deep(.feed-notice.interests-banner > button),
:deep(.feed-notice .policy-notice__close) {
	inset-block-start: -4px;
	inset-inline-end: -8px;
}

:deep(.feed-notice .held-requests__toggle) {
	flex: 0 1 auto;
	text-align: center;
}

:deep(.feed-notice .new-activities__count) {
	text-align: center;
}

@media (max-width: 600px) {
	.social__wrapper > :deep(.feed-notice) {
		width: calc(100% - 16px);
		padding: 10px 12px;
	}

	:deep(.feed-notice.interests-notice),
	:deep(.feed-notice .interests-notice),
	:deep(.feed-notice.policy-notice),
	:deep(.feed-notice .policy-notice) {
		padding-inline: 24px;
	}
}

.photo-header-bar {
	display: flex;
	flex: 1 1 100%;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 3);
	min-width: 0;
	margin-block-end: 14px;
}

/* the switcher takes the room the buttons leave, its pill at the start like
   the chips on Videos; it scrolls rather than wraps when that room is short */
.photo-header-bar__layouts {
	flex: 1 1 auto;
	min-width: 0;
	margin: 0;

	:deep(.switcher__track) {
		margin-inline: 0;
	}
}

.photo-compose-actions {
	display: flex;
	flex: 0 0 auto;
	align-items: center;
	gap: 8px;
}

.timeline-scope-description {
	color: var(--color-main-text);
	font-size: 0.9rem;
}

/* a one-time card settles in rather than appearing in one step */
.feed-notice-enter-active,
.feed-notice-leave-active {
	transition: opacity .3s ease, transform .3s ease;
}

.feed-notice-enter-from,
.feed-notice-leave-to {
	opacity: 0;
	transform: translateY(-4px);
}

@media (prefers-reduced-motion: reduce) {
	.feed-notice-enter-active,
	.feed-notice-leave-active {
		transition: none;
	}
}

/*
 * The scopes, kept within reach: the row sticks to the top of the column and
 * the posts pass under it, seen through a frosted band. A reader who asked for
 * less transparency, and a forced palette, get an opaque band instead.
 */
.feed-scopes {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 4px;
	position: sticky;
	inset-block-start: 0;
	z-index: 20;
	box-sizing: border-box;
	width: 100%;
	max-width: var(--social-column);
	margin: 0 auto 8px;
	/* the band is as wide as the column; what is on it, as wide as a post */
	padding: 6px var(--social-column-gutter);
	background: color-mix(in srgb, var(--color-main-background) 78%, transparent);
	backdrop-filter: blur(12px) saturate(1.2);
	-webkit-backdrop-filter: blur(12px) saturate(1.2);

	.switcher {
		margin-block-end: 0;
	}
}

@media (prefers-reduced-transparency: reduce) {
	.feed-scopes {
		background: var(--color-main-background);
		backdrop-filter: none;
		-webkit-backdrop-filter: none;
	}
}

@media (forced-colors: active) {
	.feed-scopes {
		background: Canvas;
		backdrop-filter: none;
		-webkit-backdrop-filter: none;
		border-block-end: 1px solid CanvasText;
	}
}

/* how far down a sticky thing in the list starts, clear of the band above:
   the band's padding and the switcher's 36px control */
.social__wrapper--scoped {
	--social-sticky-top: 48px;
}

/* the heading lines up with the posts and cards under it */
.timeline-heading-row {
	box-sizing: border-box;
	width: min(calc(100% - 2 * var(--social-column-gutter)), calc(var(--social-column) - 2 * var(--social-column-gutter)));
	margin-inline: auto;
}

.timeline-heading-row--compact {
	margin-block-end: 0;
}

/*
 * A tag page wears its tag's colour: the heading takes it, and a rule of it
 * runs under the row so the page is recognisable before the word is read.
 *
 * The hue comes from the tag's name (see utils/tagColour.js), so it is the
 * same on every device without anything being stored. Both themes get their
 * own lightness, because one hue cannot be legible on both.
 */
.timeline-heading-row--tag {
	border-block-end: 2px solid var(--tag-colour, var(--color-border));
	margin-block-end: calc(var(--default-grid-baseline) * 2);
	--social-page-title-color: var(--tag-colour, var(--color-main-text));
}

/* the same hue, at the lightness that comes forward on a dark surface */
@media (prefers-color-scheme: dark) {
	.timeline-heading-row--tag {
		border-block-end-color: var(--tag-colour-dark, var(--color-border));
		--social-page-title-color: var(--tag-colour-dark, var(--color-main-text));
	}
}

[data-themes*='dark'] .timeline-heading-row--tag {
	border-block-end-color: var(--tag-colour-dark, var(--color-border));
	--social-page-title-color: var(--tag-colour-dark, var(--color-main-text));
}

.policy-notice {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	margin: 0 0 12px;
	padding: 10px 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);

	&__icon {
		margin-top: 2px;
	}

	&__text {
		flex: 1 1 auto;
		margin: 0;

		a {
			text-decoration: underline;
		}
	}
}

.held-requests {
	margin: 0 0 12px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);

	&__row {
		display: flex;
		align-items: center;
		gap: 10px;
		flex-wrap: wrap;
	}

	/* an action, not a caption: it opens the list of those waiting */
	&__toggle {
		display: inline-flex;
		align-items: center;
		gap: 2px;
		flex: 1 1 200px;
		min-height: var(--default-clickable-area);
		margin: 0;
		padding: 0 4px;
		border: 0;
		background: none;
		color: var(--color-primary-element);
		font: inherit;
		font-weight: 600;
		text-align: start;
		cursor: pointer;

		&:hover {
			text-decoration: underline;
		}

		&:focus-visible {
			outline: 2px solid var(--color-main-text);
			outline-offset: 2px;
		}
	}

	&__chevron {
		transition: transform var(--social-motion-base) var(--social-ease);
	}

	&__toggle[aria-expanded='true'] &__chevron {
		transform: rotate(180deg);
	}

	&__settings {
		font-size: var(--font-size-small, 13px);
		color: var(--color-text-maxcontrast);
		text-decoration: underline;
	}

	&__list {
		margin-top: 8px;
	}
}

/* The row the badge lands on: the count on one side, the way out on the
   other. Tinted rather than bordered, and gone at zero. */
.new-activities {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 8px;
	padding: 4px 4px 4px 14px;
	border-radius: var(--border-radius-large);
	background: var(--color-primary-element-light);
}

.new-activities__count {
	font-weight: 600;
	color: var(--color-primary-element-light-text);
}

#app-content {
	position: relative;
}

.social__wrapper {
	/* a post's own box, which what stands above the posts lines up with */
	--social-card-width: min(calc(100% - 2 * var(--social-column-gutter)), calc(var(--social-reading-column) - 2 * var(--social-column-gutter)));
	@include layout.toggle-clearance;
}

/* the filters are as wide as the cards and posts around them */
.notifications-filter {
	box-sizing: border-box;
	width: var(--social-card-width);
}

/* the scopes are a short centred row, so rather than a row of their own
   below the toggle they sit beside it, kept clear of it on both sides */
@include layout.below(layout.$folded) {
	.social__wrapper > .feed-scopes:first-child {
		margin-top: 0;
		padding-inline: var(--default-clickable-area, 44px);
	}
}

/* A line much wider than 600px is hard to read; the grids keep the wider
   column. Everything in the column follows this one property. */
.social__wrapper--reading {
	--social-column: var(--social-reading-column);
}

/* video cards are looked at four to a row on a desk */
.social__wrapper--videos {
	--social-column: 1440px;
}
</style>
