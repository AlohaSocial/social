<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__wrapper" :class="{ 'social__wrapper--direct': type === 'direct', 'social__wrapper--reading': display === 'list' && type !== 'direct' }">
		<!-- the first thing a new account sees; gone for good once closed -->
		<FirstRun v-if="showInfo" @done="hideInfo" />

		<!-- what the administrators are telling everybody, above the posts and
		     above the composer: it is read before anything is written, and it
		     is only here at all while something in it is unread. Not on a
		     single post's page, which the reader navigated to for that post -->
		<Announcements v-if="type !== 'single-post' && type !== 'direct'" />

		<!-- said once, on the pages where the reading it describes happens -->
		<InterestsNotice v-if="showInterestsNotice" />

		<Composer v-if="!settingsStore.getServerData.public && type !== 'notifications' && type !== 'single-post' && type !== 'direct'" :offerShort="isHome && settingsStore.getServerData.sections?.stories !== false" />

		<!-- the three timelines that are the same place seen from three
		     distances: switching between them is something a reader does while
		     reading, not something they navigate to -->
		<TimelineSwitcher
			v-if="isFeed"
			:options="scopes"
			:value="scope"
			:label="t('social', 'Which posts to show')" />

		<InterestsLearningBanner v-if="type === 'interests' && hasInterestsTab" />

		<div
			class="timeline-heading-row"
			:class="{ 'timeline-heading-row--tag': type === 'tags' }"
			:style="tagStyle">
			<!-- the page had no heading at all outside tags and notifications, so
			     there was nothing to land on and nothing to say where you were -->
			<h1 class="timeline-heading" :class="{ 'hidden-visually': !headingIsVisible }">
				{{ heading }}
			</h1>
			<HashtagFollowButton
				v-if="type === 'tags'"
				:tag="String($route.params.tag)"
				@changed="onHashtagFollowChanged" />
		</div>

		<HashtagFollowedList v-if="type === 'tags'" ref="followedHashtags" />

		<!-- said once to an account that existed before the policy did: it
		     can now choose who reaches it. Gone for good when put away, or
		     once the policy has been saved -->
		<div v-if="type === 'notifications' && notificationsStore.policyNotice" class="policy-notice" role="note">
			<IconBellCogOutline :size="20" class="policy-notice__icon" />
			<p class="policy-notice__text">
				{{ t('social', 'You can now choose who reaches your notifications — new accounts and people you don\'t follow can wait for your review.') }}
				<RouterLink :to="{ name: 'settings', hash: '#notification-policy' }">
					{{ t('social', 'Choose in Settings') }}
				</RouterLink>
			</p>
			<NcButton
				variant="tertiary"
				:aria-label="t('social', 'Close')"
				@click="notificationsStore.dismissPolicyNotice()">
				<template #icon>
					<IconClose :size="20" />
				</template>
			</NcButton>
		</div>

		<!-- the people the notification policy is holding back: how many,
		     and the list itself on Review. Here, because this is where
		     somebody wonders why a mention never arrived -->
		<div v-if="type === 'notifications' && pendingRequests > 0" class="held-requests">
			<div class="held-requests__row">
				<IconInboxOutline :size="20" />
				<button
					type="button"
					class="held-requests__toggle"
					:aria-expanded="showRequests ? 'true' : 'false'"
					@click="showRequests = !showRequests">
					{{ n('social', '{count} person is waiting — Review', '{count} people are waiting — Review', pendingRequests, { count: pendingRequests }) }}
				</button>
				<RouterLink :to="{ name: 'settings', hash: '#notification-policy' }" class="held-requests__settings">
					{{ t('social', 'Who may reach you') }}
				</RouterLink>
			</div>
			<NotificationRequests v-if="showRequests" class="held-requests__list" @changed="onRequestsChanged" />
		</div>

		<!-- what the sidebar badge was counting, where pressing it lands.
		     The page marks itself read after a dwell, which is something that
		     happens rather than something anybody did: this says how much
		     there is, and gives the reader a way to say they are done with it
		     without reading down to the bottom of the list. -->
		<div v-if="type === 'notifications' && unreadActivities > 0" class="new-activities">
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

		<!-- which kinds of activity to show; the same control as the scopes
		     above the feed, choosing a filter of this page rather than a page -->
		<TimelineSwitcher
			v-if="type === 'notifications'"
			class="notifications-filter"
			:options="notificationFilters"
			:value="notificationFilter"
			:label="t('social', 'Which activities to show')"
			@update:value="chooseNotificationFilter" />

		<!-- what the reader wrote on this day in years gone by, and how their
		     week went if they asked to be told; only over their own home feed,
		     which is the one page that is about them -->
		<!-- whose 24-hour shorts are up: a row of faces above the reader's
		     own feed, and only there; they are for the people who follow -->
		<ShortsBar v-if="isHome" />

		<WeeklyRecap v-if="isHome" />
		<OnThisDay v-if="isHome" />

		<DirectMessages
			v-if="type === 'direct'"
			:selectedConversationId="String($route.query.conversation ?? '')"
			@select="selectConversation" />
		<TimelineList
			v-else
			:type="type"
			:listTitle="listTitle"
			:display="display" />

		<!-- the first post somebody ever publishes here, marked once -->
		<FirstPostCelebration v-if="celebratingFirstPost" @done="endCelebration" />
	</div>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import IconAccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import IconAccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import IconAt from 'vue-material-design-icons/At.vue'
import IconBell from 'vue-material-design-icons/Bell.vue'
import IconBellCogOutline from 'vue-material-design-icons/BellCogOutline.vue'
import IconCheckAll from 'vue-material-design-icons/CheckAll.vue'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconEarth from 'vue-material-design-icons/Earth.vue'
import IconHeart from 'vue-material-design-icons/Heart.vue'
import IconHome from 'vue-material-design-icons/Home.vue'
import IconInboxOutline from 'vue-material-design-icons/InboxOutline.vue'
import IconMessagePlusOutline from 'vue-material-design-icons/MessagePlusOutline.vue'
import IconPoll from 'vue-material-design-icons/Poll.vue'
import IconRepeat from 'vue-material-design-icons/Repeat.vue'
import IconTagHeart from 'vue-material-design-icons/TagHeart.vue'
import TimelineList from './../components/TimelineList.vue'
import DirectMessages from './../components/DirectMessages.vue'
import TimelineSwitcher from './../components/TimelineSwitcher.vue'
import FirstPostCelebration from './../components/FirstPostCelebration.vue'
import Announcements from './../components/Announcements.vue'
import FirstRun from './../components/FirstRun.vue'
import HashtagFollowButton from './../components/HashtagFollowButton.vue'
import InterestsLearningBanner from './../components/InterestsLearningBanner.vue'
import InterestsNotice from './../components/InterestsNotice.vue'
import OnThisDay from './../components/OnThisDay.vue'
import ShortsBar from './../components/ShortsBar.vue'
import WeeklyRecap from './../components/WeeklyRecap.vue'
import { tagStyle } from '../utils/tagColour.js'
import HashtagFollowedList from './../components/HashtagFollowedList.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import eventBus, { NOTIFICATIONS_READ } from './../services/eventBus.js'
import { rememberFilter, rememberedFilter } from './../services/notifications.js'
import { hasInterestsFeed, isTracking } from './../services/interests.js'
import { contextFor } from './../services/interestTracker.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useNotificationsStore } from '../store/notifications.js'
import { useSettingsStore } from '../store/settings.js'
import { useTimelineStore } from '../store/timeline.js'

const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'../components/Composer/Composer.vue'))
// only drawn when somebody asks to review who is waiting
const NotificationRequests = defineAsyncComponent(() => import(/* webpackChunkName: "notification-requests" */'../components/NotificationRequests.vue'))

export default {
	name: 'Timeline',
	components: {
		Announcements,
		Composer,
		IconCheckAll,
		NcButton,
		FirstPostCelebration,
		FirstRun,
		HashtagFollowButton,
		HashtagFollowedList,
		InterestsLearningBanner,
		InterestsNotice,
		OnThisDay,
		ShortsBar,
		WeeklyRecap,
		TimelineList,
		DirectMessages,
		TimelineSwitcher,
		IconBellCogOutline,
		IconClose,
		IconInboxOutline,
		NotificationRequests,
	},

	data() {
		return {
			infoHidden: false,
			/** the title of the list being read, once the server has said */
			listTitle: '',
			/**
			 * Which kinds of activity the notifications page shows; the
			 * browser remembers the choice, so the page opens where it was left
			 */
			notificationFilter: rememberedFilter(),
			/** while the marker is being moved, so it cannot be moved twice */
			markingAllRead: false,
			/** whether the held senders are unfolded under their count */
			showRequests: false,
		}
	},

	computed: {
		...mapStores(useAccountStore, useNotificationsStore, useSettingsStore, useTimelineStore),

		/** @return {number} how many senders the policy is holding back */
		pendingRequests() {
			return this.notificationsStore.pendingRequests
		},

		/**
		 * How many activities the sidebar badge is counting.
		 *
		 * The same number from the same place, rather than something counted
		 * off this page: the page holds one filter's worth and the badge
		 * counts them all, and two numbers that disagree would be worse than
		 * the one that was hard to find.
		 *
		 * @return {number}
		 */
		unreadActivities() {
			return this.notificationsStore.unreadNotifications
		},

		/**
		 * The tag's own colour, so `#design` reads as `#design` wherever it is
		 * met. Only a tag page has one; everywhere else the heading keeps the
		 * theme's colour and the properties are simply absent.
		 *
		 * @return {object|null} custom properties, or null off a tag page
		 */
		tagStyle() {
			return this.type === 'tags' ? tagStyle(String(this.$route.params.tag)) : null
		},

		/** What this timeline is, in the words the sidebar uses for it. */
		heading() {
			switch (this.type) {
				case 'tags':
					return '#' + this.$route.params.tag
				case 'list':
					// the sidebar knows the title; the page asks for it itself
					// so that a link opened cold has a heading too
					return this.listTitle || t('social', 'List')
				case 'photos':
					return t('social', 'Photos')
				case 'videos':
					return t('social', 'Videos')
				case 'link':
					// the article is the subject of this page, and the only
					// thing known about it before the first post arrives is
					// where it lives
					return this.linkHost || t('social', 'Link')
				case 'notifications':
					// the sidebar calls it Activities; the route keeps the name
					// the API gives it
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
					return t('social', 'Liked posts')
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
		 * Whether this page is one page read at three scopes rather than three
		 * pages. Photos and Videos both are: the sidebar entry stays lit
		 * whichever of the three the reader chose, which it would not if each
		 * scope were a `type` of its own.
		 *
		 * @return {boolean}
		 */
		isScopedPage() {
			return ['photos', 'videos'].includes(this.type)
		},

		/**
		 * How the posts are drawn here.
		 *
		 * The same rule the profile follows, and for the same reason it gives:
		 * Posts is what somebody wrote, which is a list; Photos and Videos are
		 * what they showed, which is a grid. Those two pages were a list here
		 * and a grid on a profile, so the same picture was a row in one place
		 * and a tile in the other.
		 *
		 * Not a toggle. One question, one answer — a control to make Photos
		 * look like a list would be asking the reader to settle something the
		 * page has already answered by being Photos.
		 *
		 * @return {string} 'grid' or 'list'
		 */
		display() {
			return ['photos', 'videos'].includes(this.type) ? 'grid' : 'list'
		},

		/**
		 * Whether this is the reader's own feed.
		 *
		 * The memories, the recap and the Shorts bar belong here and nowhere
		 * else; on a tag page or a profile they would be an interruption from
		 * another subject.
		 *
		 * The *route* of the home feed carries no `type` — see `scopes()`: the
		 * local and global feeds are `timeline` and `federated`, and home is
		 * the one with nothing. This compared against that empty route
		 * parameter, but `type` above never answers with it: a route without
		 * one is reported as `'home'`, which is the word the rest of this view
		 * uses. So `isHome` was false on every page including the home feed,
		 * and the three cards it guards had never once been drawn.
		 *
		 * @return {boolean}
		 */
		isHome() {
			return this.type === 'home'
		},

		/**
		 * The three distances the same posts can be read at, as the switcher
		 * above them offers them.
		 *
		 * On Photos and Videos they are the same three, so those stay one page
		 * with a scope on it: the scope rides in the query, which keeps the
		 * sidebar entry lit whichever is chosen and keeps each of them one
		 * timeline rather than three that look alike. On the feed itself each
		 * scope is its own route, and `home` is the route with no `type` at
		 * all — passing `type: 'home'` would ask for a timeline of that name,
		 * which nothing serves.
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
				// "My Feed" rather than "Home": next to Local and Global, what
				// distinguishes it is whose posts it holds, not where it sits
				{ value: 'home', label: t('social', 'My Feed'), icon: IconHome, to: routeFor('home') },
				{ value: 'timeline', label: t('social', 'Local'), icon: IconAccountMultiple, to: routeFor('timeline') },
				{ value: 'federated', label: t('social', 'Global'), icon: IconEarth, to: routeFor('federated') },
			]

			// the reader's own again, chosen by subject rather than by whom they
			// follow, so it sits beside My Feed rather than after the distances.
			// On Photos and Videos it is the same ranking narrowed to that
			// kind, which the server keeps as a ranking of its own
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
			return !this.settingsStore.getServerData.public && hasInterestsFeed(this.settingsStore.getServerData.interests)
		},

		/**
		 * Whether to say, once, that reading is being learned from: only while
		 * it is, only until the reader has answered, and only on a page whose
		 * reading counts.
		 *
		 * @return {boolean}
		 */
		showInterestsNotice() {
			const interests = this.settingsStore.getServerData.interests

			return !this.settingsStore.getServerData.public
				&& isTracking(interests)
				&& interests.noticeAcknowledged !== true
				&& contextFor(this.type) !== null
		},

		/**
		 * Which of the three scopes is being read.
		 *
		 * Photos and Videos are each one page with a scope on it rather than
		 * three pages, so on them the scope comes from the query; everywhere
		 * else the type *is* the scope. A query that says anything else is read
		 * as the default rather than trusted: it arrives from the address bar.
		 * `interests` is For you, for a reader who has it.
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

		/**
		 * The two that were on the page before stay on the page; the rest name
		 * the view for a screen reader without changing what anyone sees.
		 */
		headingIsVisible() {
			// Photos and Videos are views of their own rather than a filter of
			// a list you were already on, so they say which one you are
			// looking at
			return this.type === 'tags' || this.type === 'list' || this.type === 'notifications' || this.isScopedPage
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
				{ value: 'favourites', label: t('social', 'Favourites'), icon: IconHeart },
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
			} else if (this.$route.name === 'single-post') {
				return this.$route.params
			} else if (this.isScopedPage) {
				// part of what identifies this timeline, so that changing the
				// scope refetches rather than leaving the previous photos up
				return { scope: this.scope }
			} else if (this.type === 'link') {
				// the article being talked about. Same reason: another link is
				// another page, not the same page filtered
				return { url: this.linkUrl }
			} else if (this.type === 'notifications') {
				// the same reason: a filter is a question for the server, and
				// a different one is a different list
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
		 * Where that article lives, which is what the page is headed with.
		 * A URL that will not parse is not one this page can say anything
		 * about, and the heading falls back to the plain word.
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
		 * Which list this is. A route parameter is `string | string[]`, and
		 * every reader of this compares it against one string or looks it up
		 * in a list of them, so it is narrowed here rather than at each of
		 * them.
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
			if (this.$route.params.type) {
				return String(this.$route.params.type)
			}
			return 'home'
		},

		showInfo() {
			// `firstrun` from the server, or `?welcome=1`, which is what the
			// setup screen reloads with once the account exists and what
			// Settings links to for somebody who wants it again
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
		// the router-view is no longer keyed on the full path, so switching
		// from Home to Global reuses this view: without this the store would
		// keep serving the previous timeline
		timelineKey() {
			if (this.type !== 'direct') {
				this.timelineStore.changeTimelineType({ type: this.type, params: this.params })
			}
			this.fetchListTitle()
			this.fetchPendingRequests()
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
	},

	beforeUnmount() {
		eventBus.off('post-published', this.onPostPublished)
		// navigating away mid-celebration must not leave the flag standing for
		// whatever timeline mounts next
		if (this.celebratingFirstPost) {
			this.timelineStore.endFirstPostCelebration()
		}
	},

	methods: {
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

		hideInfo() {
			this.infoHidden = true
			// Keep the dismissal with the account, so it survives refreshes and
			// signing in on another device. Settings can still explicitly reopen it.
			try {
				window.localStorage.setItem(`social:introduction:${this.accountStore.currentAccountHandle}`, 'dismissed')
			} catch {
				// Storage can be disabled by the browser; the server-side setting
				// remains the source of truth in that case.
			}
			try {
				axios.post(generateUrl('apps/social/api/v1/introduction/dismiss'))?.catch(() => {})
			} catch {
				// The local dismissal is enough for this browser session if the
				// preference endpoint cannot be reached.
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
		 * Asks how many senders are held, on the one page that shows them.
		 * The list starts folded, unless the address asks for it
		 * (`?requests=1`, where the Blocking page links).
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
		 * Says the reader is done with the lot, whatever the filter shows.
		 *
		 * The list below has frozen where the line between new and already
		 * seen goes, so it is told where the marker ended up rather than left
		 * drawing a boundary that has stopped meaning anything.
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

		/**
		 * A post went out. Whether that is the reader's first is the store's
		 * decision; asking costs nothing and nothing here waits on the answer,
		 * so the post itself appears exactly as it did before.
		 */
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
 * The column, and nothing about what is in it. `.social__timeline` is another
 * component's root element, and a scoped style still reaches a child's root —
 * so a rule here lands on it with the same specificity as the list's own and
 * wins or loses on bundle order. This view used to set `margin: 0` on it, which
 * beat the list's own `margin: 0 auto` and left the timeline flush to one side
 * while the composer beside it stayed centred.
 */
.social__wrapper {
	max-width: var(--social-column);
	margin: 0 auto;
	padding: 0;
}

.timeline-heading-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-inline-end: calc(var(--default-grid-baseline) * 2);
}

.timeline-heading {
	font-size: 20px;
	font-weight: 700;
	margin: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-lighter);
	letter-spacing: -.01em;
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

	.timeline-heading {
		color: var(--tag-colour, var(--color-text-lighter));
	}
}

/* the same hue, at the lightness that comes forward on a dark surface */
@media (prefers-color-scheme: dark) {
	.timeline-heading-row--tag {
		border-block-end-color: var(--tag-colour-dark, var(--color-border));

		.timeline-heading {
			color: var(--tag-colour-dark, var(--color-text-lighter));
		}
	}
}

[data-themes*='dark'] .timeline-heading-row--tag {
	border-block-end-color: var(--tag-colour-dark, var(--color-border));

	.timeline-heading {
		color: var(--tag-colour-dark, var(--color-text-lighter));
	}
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

	&__toggle {
		flex: 1 1 200px;
		min-height: var(--default-clickable-area);
		margin: 0;
		padding: 0 4px;
		border: 0;
		background: none;
		color: var(--color-main-text);
		font: inherit;
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

	&__settings {
		font-size: var(--font-size-small, 13px);
		color: var(--color-text-maxcontrast);
		text-decoration: underline;
	}

	&__list {
		margin-top: 8px;
	}
}

/*
 * Seven options where the switcher was drawn for three.
 *
 * They used to be squeezed here — half the switcher's own padding — because
 * every option was as wide as the widest of them, and seven of those ran past
 * the column. An option is as wide as its own words now and the pill is
 * measured rather than assumed, so the squeeze is gone and the row keeps the
 * spacing every other switcher has.
 *
 * What is still needed is the point where even that stops fitting: below this
 * width the labels give way to the icons they sit beside, staying in the
 * accessibility tree because the label is the option's name. The switcher does
 * the same thing itself further down; this only brings the point forward for a
 * row twice as long as the ones it was built for.
 */
/* The row the badge lands on: how much there is on one side, the way out of
   it on the other. Tinted rather than bordered -- it is the page saying
   something, not another card to read -- and gone entirely at zero, so the
   list does not keep a permanent header it has no news for. */
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

// the notification filter has more options than the timeline switcher does, so
// its labels run out of room before that one's do
@include layout.below(layout.$crowded) {
	.notifications-filter :deep(.switcher__label) {
		@include layout.visually-hidden;
	}
}

#app-content {
	position: relative;
}

/* while the sidebar is collapsed its toggle sits over the top-left corner of
   the content, where the composer or the heading begins; the first thing on
   the page starts below it. The distance is a property so that a page sized
   to the viewport (DirectMessages.vue) can take it off its own height */
@include layout.below(layout.$folded) {
	.social__wrapper {
		--social-toggle-clearance: calc(var(--default-clickable-area, 44px) + var(--default-grid-baseline, 4px) * 2);
	}

	.social__wrapper > :first-child {
		margin-top: var(--social-toggle-clearance);
	}
}

/* A feed of text is read, and a line much wider than 600 pixels is hard to
   follow back to its start. The grids keep the wider column: a picture is
   looked at, not read. Everything in the column — composer, switcher,
   the Shorts bar, posts — follows the one property. */
.social__wrapper--reading {
	--social-column: 616px;
}
</style>
