<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppNavigation>
		<template #search>
			<NcAppNavigationSearch
				v-model="localSearch"
				:label="t('social', 'Search …')"
				@update:modelValue="onSearchInput" />
		</template>
		<template #list>
			<!-- The mark that says where you are, as one thing that moves
			     rather than a highlight that blinks out of one row and into
			     another. It animates the *click*, at the place the click
			     happened, which is the one piece of feedback that is instant
			     however long the page it opens takes to arrive.

			     Measured rather than laid out: the rows are
			     `@nextcloud/vue`'s and this is drawn over them, so it is
			     positioned from the active row's own box and follows it. -->
			<span
				v-show="indicator.height > 0"
				class="navigation__indicator"
				:class="{ 'navigation__indicator--settled': indicator.settled }"
				:style="{ transform: `translateY(${indicator.top}px)`, height: `${indicator.height}px` }"
				aria-hidden="true" />
			<!-- The one thing in the sidebar that is not a place to go: it is
			     what the app is for, so it is a call to action rather than a
			     row among rows. It was an NcAppNavigationItem with no `to`,
			     which renders href="#" and needed a .prevent to stop the bare
			     fragment becoming a history entry; a button has nowhere to go
			     by construction. NcButton rather than a bare <button> because
			     the server's own button rules all exclude `.button-vue` — a
			     hand-rolled one has to win an argument with them in every
			     state, and loses the pressed one. -->
			<NcButton
				class="navigation__compose"
				variant="primary"
				wide
				alignment="start"
				@click="showComposer = true">
				<template #icon>
					<IconPlus :size="20" />
				</template>
				{{ t('social', 'New post') }}
			</NcButton>

			<NcAppNavigationItem
				v-if="hasErrors"
				:name="t('social', 'Errors')"
				@click.prevent="showErrors = true">
				<template #icon>
					<IconAlertCircle class="error-icon" :size="20" />
				</template>
				<!-- `counter` is a slot in @nextcloud/vue 9, not a prop: passing
				     the number as `:counter` rendered nothing at all -->
				<template #counter>
					<NcCounterBubble :count="errorCount" type="highlighted" />
				</template>
			</NcAppNavigationItem>

			<!-- `href` rather than `to`: with `to`, the component ORs its own
			     router-derived state into `active`, and vue-router counts
			     /timeline as active while /timeline/direct is open — so My Feed
			     stayed lit next to whichever timeline was actually chosen.
			     navigate() keeps the click in the SPA while leaving a modified
			     click (new tab, new window) to the browser. -->
			<NcAppNavigationItem
				v-for="item in menu.timelines"
				:key="item.key"
				:class="{
					navigation__chosen: chosen === item.key,
					navigation__rang: ringing && item.key === 'social-notifications',
				}"
				:name="item.title"
				:href="hrefFor(item.to)"
				:active="isActive(item)"
				@click="navigate(item.to, $event)">
				<template #icon>
					<component :is="item.icon" :size="20" />
				</template>
				<!-- A count, not an alarm. `highlighted` is the loud accent
				     bubble Nextcloud uses for something that needs answering;
				     what this is counting is things that happened. The bell
				     wobbles once when the number goes up, which is noticed and
				     is over. -->
				<template v-if="item.counter > 0" #counter>
					<NcCounterBubble :count="item.counter" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationSpacer />

			<!-- One entry for everything there is to look at besides your own
			     feed: the hashtags you follow, your lists, and what this
			     instance is talking about — in one collapsible row rather than
			     three captions that each grow without limit.

			     Trending used to be a section of its own above this one. It is
			     in here now, last and only in the room the other two leave, so
			     that what somebody chose to follow can never be pushed out of
			     their own sidebar by what happens to be busy today.

			     No icon of its own, so the chevron is the only thing before
			     the word and the children line up under it. -->
			<NcAppNavigationItem
				v-if="exploreTotal > 0"
				ref="exploreItem"
				class="navigation__explore"
				:name="t('social', 'Explore')"
				:allowCollapse="true"
				:open="exploreOpen"
				@update:open="onExploreToggle">
				<!-- Followed tags, trending tags and lists were one
				     undifferentiated column, so which kind a row was had to be read
				     off its icon. A caption before each run costs one line and says
				     it. A run of one still gets its caption: the point is that the
				     reader knows what they are looking at, not that the list is
				     long enough to need dividing. -->
				<template v-for="group in exploreGroups" :key="group.kind">
					<NcAppNavigationCaption :name="group.caption" />
					<NcAppNavigationItem
						v-for="entry in group.entries"
						:key="keyFor(entry)"
						:class="entry.kind === 'list' ? 'navigation__list' : 'navigation__trend'"
						:name="entry.kind === 'list' ? entry.list.title : `#${entry.tag.name}`"
						:title="titleFor(entry)"
						:href="hrefFor(routeFor(entry))"
						:active="isExploreActive(entry)"
						@click="navigate(routeFor(entry), $event)">
						<template #icon>
							<IconAccountGroup v-if="entry.kind === 'list' && entry.list.nextcloud_group" :size="20" />
							<IconFormatListBulleted v-else-if="entry.kind === 'list'" :size="20" />
							<IconTrendingUp v-else-if="entry.kind === 'trend'" :size="20" />
							<IconPound v-else :size="20" />
						</template>
						<!-- how busy it is, which is the whole reason a trending
						     tag is worth a row: a followed one is there because it
						     was chosen, not because of a number -->
						<template v-if="entry.kind === 'trend'" #extra>
							<span class="navigation__subname">
								{{ n('social', '%n post', '%n posts', usesOf(entry.tag)) }}
							</span>
						</template>
					</NcAppNavigationItem>
				</template>

				<!-- the lists are made and filled in Settings; this is where a
				     reader looks for the way there -->
				<template #actions>
					<NcActionButton closeAfterClick @click="navigate({ name: 'settings', hash: '#lists' })">
						<template #icon>
							<IconCog :size="20" />
						</template>
						{{ t('social', 'Manage lists') }}
					</NcActionButton>
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationSpacer v-if="exploreTotal > 0" />
		</template>
		<template #footer>
			<div class="navigation__footer">
				<!-- The way out of every app in Nextcloud is the thing at the
				     bottom with your face on it, so that is what this is: the
				     name the reader publishes under, their portrait, and
				     everything they go looking for rather than read, behind it.
				     `NcAppNavigationSettings` draws a cog and offers no slot to
				     replace it, so the picture is set as that icon's background
				     and the cog itself is hidden. -->
				<NcAppNavigationSettings
					class="navigation__more"
					:class="{ 'navigation__more--rang': ringing }"
					:data-unread="unreadBadge"
					:style="{ '--social-face': `url(${avatarUrl})`, '--entry-total': moreCount }"
					:name="profileName">
					<!-- `--entry-index`, with `--entry-total` above, is what
					     staggers the opening: the bottom row pops first and the
					     rest follow upward. Both are passed from here because
					     the DOM cannot be counted -- every entry sits in a
					     wrapper of its own, so each one is its parent's first
					     child and neither `nth-child` nor `nth-last-child`
					     distinguishes them. -->
					<NcAppNavigationItem
						v-for="(item, index) in menu.more"
						:key="item.key"
						:class="{ navigation__rang: ringing && item.key === 'social-notifications' }"
						:style="{ '--entry-index': index }"
						:name="item.title"
						:href="hrefFor(item.to)"
						:active="isActive(item)"
						@click="navigate(item.to, $event)">
						<template #icon>
							<component :is="item.icon" :size="20" />
						</template>
						<template v-if="item.counter > 0" #counter>
							<NcCounterBubble :count="item.counter" type="highlighted" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						:style="{ '--entry-index': menu.more.length }"
						:name="t('social', 'Blocking')"
						:href="hrefFor({ name: 'blocked-accounts' })"
						:active="isActive({ to: { name: 'blocked-accounts' } })"
						@click="navigate({ name: 'blocked-accounts' }, $event)">
						<template #icon>
							<IconCancel :size="20" />
						</template>
					</NcAppNavigationItem>
					<!-- Moving an account in or out of this server: a job
					     somebody sits down to do with a file manager open, so a
					     page of its own rather than a section of Settings. -->
					<NcAppNavigationItem
						:style="{ '--entry-index': menu.more.length + 1 }"
						:name="t('social', 'Migration')"
						:href="hrefFor({ name: 'migration' })"
						:active="isActive({ to: { name: 'migration' } })"
						@click="navigate({ name: 'migration' }, $event)">
						<template #icon>
							<IconSwapHorizontal :size="20" />
						</template>
					</NcAppNavigationItem>
					<!-- last, and on its own: everything above this menu is a
					     place to read something, and this is the one place to
					     change something -->
					<NcAppNavigationItem
						:style="{ '--entry-index': menu.more.length + 2 }"
						:name="t('social', 'Settings')"
						:href="hrefFor({ name: 'settings' })"
						:active="isActive({ to: { name: 'settings' } })"
						@click="navigate({ name: 'settings' }, $event)">
						<template #icon>
							<IconCog :size="20" />
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationSettings>
			</div>
		</template>
	</NcAppNavigation>

	<!-- `closeOnClickOutside` is a boolean prop with no default, so the dialog
	     ignored a click on the dimmed page around it and only the X closed it.
	     Nothing is lost by closing: the draft is saved on every keystroke and
	     restored the next time the composer opens. -->
	<NcModal
		v-if="showComposer"
		closeOnClickOutside
		:name="t('social', 'New post')"
		@close="closeComposer">
		<div class="modal-composer">
			<!-- the box emptied and the modal stayed open, which reads as if
			     nothing had been sent -->
			<Composer
				startExpanded
				:initialPaths="composerPaths"
				:initialFiles="composerFiles"
				emojiPickerContainer=".modal-wrapper"
				@posted="closeComposer" />
		</div>
	</NcModal>

	<NcModal
		v-if="showErrors"
		:name="t('social', 'Errors')"
		@close="showErrors = false">
		<div class="modal-errors">
			<div v-for="error in appErrors" :key="error.id" class="modal-errors__item">
				<div class="modal-errors__title">
					{{ error.title }}
				</div>
				<div class="modal-errors__message">
					{{ error.message }}
				</div>
				<NcButton variant="tertiary" @click="dismissError(error.id)">
					{{ t('social', 'Dismiss') }}
				</NcButton>
			</div>
			<NcButton v-if="appErrors.length > 1" variant="tertiary" @click="clearAllErrors">
				{{ t('social', 'Dismiss all') }}
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationSearch from '@nextcloud/vue/components/NcAppNavigationSearch'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationSpacer from '@nextcloud/vue/components/NcAppNavigationSpacer'
import NcAppNavigationSettings from '@nextcloud/vue/components/NcAppNavigationSettings'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'

import { defineAsyncComponent } from 'vue'

import IconHome from 'vue-material-design-icons/Home.vue'
import IconCompass from 'vue-material-design-icons/Compass.vue'
import IconImageMultiple from 'vue-material-design-icons/ImageMultiple.vue'
import IconPlayBoxMultiple from 'vue-material-design-icons/PlayBoxMultiple.vue'
import IconPlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import IconRss from 'vue-material-design-icons/Rss.vue'
import IconBell from 'vue-material-design-icons/Bell.vue'
import IconCommentAccount from 'vue-material-design-icons/CommentAccount.vue'
import IconAccountCircle from 'vue-material-design-icons/AccountCircle.vue'
import IconAccountClock from 'vue-material-design-icons/AccountClock.vue'
import IconHeart from 'vue-material-design-icons/Heart.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconBookmark from 'vue-material-design-icons/Bookmark.vue'
import IconPound from 'vue-material-design-icons/Pound.vue'
import IconTrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import IconAccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import IconFormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import IconChartBox from 'vue-material-design-icons/ChartBox.vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import { capacityFrom, chooseEntries, entriesThatFit } from '../utils/explore.js'
import { listen } from '@nextcloud/notify_push'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import IconCancel from 'vue-material-design-icons/Cancel.vue'
import IconSwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import IconCog from 'vue-material-design-icons/Cog.vue'
import IconAlertCircle from 'vue-material-design-icons/AlertCircle.vue'

import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useErrorsStore } from '../store/errors.js'
import { useInstanceStore } from '../store/instance.js'
import { useNotificationsStore } from '../store/notifications.js'
import { useSettingsStore } from '../store/settings.js'
import { pageIdentity } from '../services/pageOrder.js'
import { useTimelineStore } from '../store/timeline.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'
import { afterFirstTimeline } from '../services/boot.js'
import eventBus, { COMPOSE_WITH_FILES, LISTS_CHANGED } from '../services/eventBus.js'
import { ownAvatarUrl } from '../services/avatar.js'

// the composer pulls the emoji picker and the attachment stack with it:
// its own chunk keeps all of that out of the entry bundle
const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'./Composer/Composer.vue'))

/** how often to re-read the badge when the server cannot push */
const UNREAD_POLL_MS = 60 * 1000

/** how long to let the typing settle before searching */
const SEARCH_DEBOUNCE_MS = 300

/**
 * Where the Explore entry remembers whether it was left open.
 *
 * `localStorage` rather than a user setting: it is a per-browser convenience
 * worth nothing to anybody else, and a reader whose browser cannot store it
 * simply gets it open, which is the better default.
 */
const EXPLORE_OPEN_KEY = 'social.navigation.exploreOpen'

export default {
	name: 'Navigation',
	components: {
		NcActionButton,
		NcAppNavigation,
		NcAppNavigationSearch,
		NcAppNavigationItem,
		NcAppNavigationCaption,
		NcAppNavigationSpacer,
		NcAppNavigationSettings,
		NcAvatar,
		NcModal,
		NcButton,
		NcCounterBubble,
		Composer,
		IconHome,
		IconAccountCircle,
		IconBell,
		IconCommentAccount,
		IconHeart,
		IconPlus,
		IconBookmark,
		IconPound,
		IconTrendingUp,
		IconAccountGroup,
		IconFormatListBulleted,
		IconCancel,
		IconSwapHorizontal,
		IconCog,
		IconAlertCircle,
	},

	emits: ['search'],
	setup() {
		const { currentUser } = useCurrentUser()

		return { currentUser }
	},

	data() {
		return {
			/** the hashtags the instance is using most, newest counts first */
			trending: [],
			/** the reader's lists, the group-bound ones first */
			lists: [],
			/** the hashtags the reader follows, as the server orders them */
			followedTags: [],
			/** whether the Explore entry is open; remembered per reader */
			exploreOpen: true,
			/** the window's height, the fallback until the rail can be measured */
			viewportHeight: 0,
			/**
			 * How many entries the rail has room for, measured from the rail.
			 * `null` until there is something to measure — zero is a real
			 * answer, so it cannot double as "not measured yet".
			 */
			measuredCap: null,
			localSearch: '',
			/** where the travelling mark is, in the list's own coordinates */
			indicator: { top: 0, height: 0, settled: false },
			indicatorFrame: null,
			/** the entry whose icon is popping, '' when none */
			chosen: '',
			chosenTimer: null,
			/** true for as long as the bell is wobbling */
			ringing: false,
			ringTimer: null,
			showComposer: false,
			/** files handed to the dialog by a page without a composer, like Shorts */
			composerFiles: [],
			/** files "Share to Social" in the Files app sent along, attached when the dialog opens */
			composerPaths: [],
			showErrors: false,
			stopListening: null,
			pollTimer: null,
			searchTimer: null,
			/** the LISTS_CHANGED handler, kept so only this one is removed */
			onListsChanged: null,
			/** re-measures the rail whenever it changes shape */
			railObserver: null,
		}
	},

	computed: {
		...mapStores(useAccountStore, useErrorsStore, useInstanceStore, useNotificationsStore, useSettingsStore, useTimelineStore),

		/**
		 * Everything inside Explore, however much of it the rail can show.
		 *
		 * @return {number} how many hashtags and lists there are in all
		 */
		exploreTotal() {
			return this.followedTags.length + this.lists.length + this.trendingToShow.length
		},

		/**
		 * How many entries there is room for, which shrinks with the window.
		 *
		 * @return {number}
		 */
		exploreCap() {
			return this.measuredCap === null ? entriesThatFit(this.viewportHeight) : this.measuredCap
		},

		/**
		 * What Explore actually draws: hashtags first, then lists, shared out
		 * between the two when there is not room for all of them.
		 *
		 * @return {Array<object>}
		 */
		exploreEntries() {
			return chooseEntries(this.followedTags, this.lists, this.trendingToShow, this.exploreCap)
		},

		/**
		 * The same rows, in runs of a kind, each run with a caption.
		 *
		 * The order is whatever `chooseEntries` decided -- which rows are worth
		 * the room is its question, and this does not reopen it. All this does
		 * is put a word over each run so a reader knows whether they are
		 * looking at a tag they chose, a tag the instance is talking about, or
		 * a list of theirs.
		 *
		 * @return {Array<{kind: string, caption: string, entries: object[]}>}
		 */
		exploreGroups() {
			const groups = []

			for (const entry of this.exploreEntries) {
				const last = groups[groups.length - 1]
				if (last !== undefined && last.kind === entry.kind) {
					last.entries.push(entry)
					continue
				}

				groups.push({ kind: entry.kind, caption: this.exploreCaption(entry.kind), entries: [entry] })
			}

			return groups
		},

		/**
		 * The trending tags worth a row: the ones this reader does not already
		 * follow. A followed tag is in the list above under the same name, and
		 * counting it here as well would make Explore claim to hold more than
		 * it can show.
		 *
		 * @return {Array<object>}
		 */
		trendingToShow() {
			const followed = new Set(this.followedTags.map((tag) => String(tag.name).toLowerCase()))

			return this.trending.filter((tag) => !followed.has(String(tag.name).toLowerCase()))
		},

		hasErrors() {
			return this.errorsStore.hasErrors
		},

		errorCount() {
			return this.errorsStore.appErrors.length
		},

		unreadNotifications() {
			return this.notificationsStore.unreadNotifications
		},

		/** @return {number} conversations with something unread in them */
		unreadDirectMessages() {
			return this.notificationsStore.unreadDirectMessages
		},

		appErrors() {
			return this.errorsStore.appErrors
		},

		/**
		 * @return {string} the name the reader publishes under, falling back to
		 *                  the login name only while the account is still loading
		 */
		profileName() {
			return this.currentAccount?.display_name
				|| this.currentUser?.displayName
				|| this.currentUser?.uid
				|| ''
		},

		/**
		 * The unread count the account button shows, so moving Activities
		 * into the menu does not hide it while the menu is shut. Undefined
		 * when there is nothing unread, which removes the attribute.
		 *
		 * @return {string|undefined}
		 */
		unreadBadge() {
			const count = this.unreadNotifications
			if (!(count > 0)) {
				return undefined
			}

			return count > 99 ? '99+' : String(count)
		},

		/**
		 * How many rows the account menu holds.
		 *
		 * The stylesheet staggers them from the bottom row upward, so it needs
		 * the count as well as each row's index: the delay is
		 * `total - 1 - index`. Blocking, Migration and Settings are written out
		 * in the template rather than coming from `menu.more`, so they are
		 * added here; a test holds this equal to the number of entries drawn.
		 */
		moreCount() {
			return this.menu.more.length + 3
		},

		/**
		 * The reader's own face, as a URL for the button at the bottom.
		 *
		 * The server's avatar endpoint rather than the account's `avatar`
		 * field: it answers for every account — a generated set of initials
		 * when nobody has uploaded a picture — so the button is never a blank
		 * circle, and it is the same picture the rest of Nextcloud shows.
		 *
		 * The version in the address is what makes a newly uploaded picture
		 * appear: see services/avatar.js.
		 *
		 * @return {string} where the picture is
		 */
		avatarUrl() {
			return ownAvatarUrl(64)
		},

		currentAccount() {
			return this.accountStore.currentAccount
		},

		/** what is being searched for, as the URL says it */
		searchQuery() {
			return this.timelineStore.getSearchQuery ?? ''
		},

		menu() {
			return {
				timelines: this.offered([
					{
						key: 'social-home',
						icon: IconHome,
						// the switcher over the timelines has called this one "My Feed"
						// since it was written: next to Local and Global what
						// distinguishes it is whose posts it holds, not where it sits.
						// The sidebar was the last place still calling it Home, for the
						// same destination.
						title: t('social', 'My Feed'),
						to: { name: 'timeline' },
						// Local and Global are scopes of this page rather than
						// pages of their own now that the switcher sets them,
						// so the entry stays lit while the reader is on one:
						// otherwise the sidebar shows nothing chosen at all
						covers: ['', 'timeline', 'federated'],
					},
					{
						key: 'social-photos',
						offered: this.offers('photos'),
						icon: IconImageMultiple,
						title: t('social', 'Photos'),
						to: { name: 'timeline', params: { type: 'photos' } },
					},
					// its own entry rather than a filter inside Photos: a video
					// is watched rather than glanced at, and the two are mixed
					// together nowhere else on the fediverse either — PeerTube
					// publishes nothing but videos and Pixelfed nothing but
					// pictures
					{
						key: 'social-videos',
						offered: this.offers('videos'),
						icon: IconPlayBoxMultiple,
						title: t('social', 'Videos'),
						to: { name: 'timeline', params: { type: 'videos' } },
					},
					// the same videos again, watched one at a time rather than
					// chosen from a grid. Its own entry under Videos, not a
					// button on that page: it is where somebody arriving from
					// TikTok or YouTube Shorts goes first, and a way in that
					// only shows once you are already on another page is one
					// most people never find. Offered with Videos, since it is
					// the same videos.
					{
						key: 'social-shorts',
						offered: this.offers('videos'),
						icon: IconPlayCircleOutline,
						title: t('social', 'Shorts'),
						to: { name: 'reels' },
					},
					// the last of the things to read, after the two kind
					// filters: what this account follows off the fediverse
					// rather than on it.
					{
						key: 'social-subscriptions',
						icon: IconRss,
						title: t('social', 'Subscriptions'),
						to: { name: 'subscriptions' },
					},
					{
						key: 'social-direct',
						icon: IconCommentAccount,
						title: t('social', 'Direct messages'),
						to: { name: 'timeline', params: { type: 'direct' } },
						counter: this.unreadDirectMessages,
					},
					{
						key: 'social-discover',
						icon: IconCompass,
						title: t('social', 'Discover'),
						to: { name: 'discover' },
					},
				]),

				// The sidebar's top level is for the timelines a reader moves
				// between all day; these three are things they go looking for,
				// and eight equal-weight entries made the first five harder to
				// pick out. They keep their routes, their icons and their
				// active state — only where they are drawn changes.
				more: [
					// first, because it is the reader's own page rather than
					// somewhere they go looking: the button this menu hangs off
					// used to *be* the link to it, and moving that button here
					// without putting the link back would have left a profile
					// nobody could reach.
					//
					// One entry, not two. There were "My profile", pointing at
					// Nextcloud's user page, and "Social profile", pointing at
					// the profile this app publishes — two rows a few pixels
					// apart, both called somebody's profile, and no way to tell
					// from the sidebar which one had your posts on it. This is
					// the app's own profile: the page with the posts, the
					// followers and the bio, which is what somebody clicking
					// their own name in a social app is looking for. Nextcloud's
					// user page is still where Nextcloud puts it, under the
					// avatar at the top right of every app.
					...(this.currentAccount?.acct
						? [{
								key: 'social-profile',
								icon: IconAccountCircle,
								title: t('social', 'My profile'),
								to: { name: 'profile', params: { account: this.currentAccount.acct } },
							}]
						: []),
					// Under the reader's own name, beside their profile: what
					// happened to *them* -- a mention, a like, a follow -- is
					// about the account, where the sidebar above is places to
					// go and read. "Activities" rather than "Notifications":
					// only some of it was ever notified. The count is not lost
					// with the menu shut: the account button carries it too.
					{
						key: 'social-notifications',
						icon: IconBell,
						title: t('social', 'Activities'),
						to: { name: 'timeline', params: { type: 'notifications' } },
						counter: this.unreadNotifications,
					},
					{
						key: 'social-follow-requests',
						icon: IconAccountClock,
						title: t('social', 'Follow requests'),
						to: { name: 'follow-requests' },
					},
					{
						key: 'social-liked',
						icon: IconHeart,
						title: t('social', 'Liked posts'),
						to: { name: 'timeline', params: { type: 'favourites' } },
					},
					{
						key: 'social-bookmarks',
						icon: IconBookmark,
						title: t('social', 'Bookmarks'),
						to: { name: 'timeline', params: { type: 'bookmarks' } },
					},
					{
						key: 'social-statistics',
						icon: IconChartBox,
						title: t('social', 'Statistics'),
						to: { name: 'statistics' },
					},
				],

			}
		},
	},

	watch: {
		/**
		 * Something arrived. The bell says so once.
		 *
		 * Only upwards: reading the page takes the number down, and a bell that
		 * wobbled then would be ringing about the reader's own click.
		 *
		 * @param {number} now the count now
		 * @param {number} before what it was
		 */
		unreadNotifications(now, before) {
			if (now <= before) {
				return
			}

			window.clearTimeout(this.ringTimer)
			this.ringing = true
			this.ringTimer = window.setTimeout(() => {
				this.ringing = false
			}, 700)
		},

		/**
		 * another page: the mark goes to the row that is lit now
		 *
		 * @param to
		 * @param from
		 */
		$route(to, from) {
			this.$nextTick(() => this.placeIndicator())
			this.markChosen(to, from)
		},

		/**
		 * The entry is not in the rail until there is something to put in it,
		 * so there is nothing to observe or measure at mount: both wait for
		 * the hashtags and lists to arrive.
		 */
		exploreTotal: {
			async handler(total) {
				await this.$nextTick()

				if (total > 0) {
					this.watchRail()
				}

				this.measureRail()
			},

			immediate: true,
		},

		showComposer(open) {
			// the paths were for that one dialog; a later "New post" starts empty
			if (!open) {
				this.composerPaths = []
			}
		},

		// the box has to follow the store, not just read it once: synced only
		// in mounted() it kept showing a term the reader had already navigated
		// away from
		searchQuery: {
			immediate: true,
			handler(query) {
				this.localSearch = query
			},
		},
	},

	mounted() {
		this.$nextTick(() => this.placeIndicator())
		// none of these is what the reader opened the page for, and fired
		// together with the timeline they slowed it down by their number:
		// they wait for it (services/boot.js says how)
		afterFirstTimeline(() => {
			this.fetchTrending()
			this.fetchLists()
			this.fetchFollowedTags()
			this.notificationsStore.fetchUnreadNotifications()
			this.notificationsStore.fetchUnreadDirectMessages()
		})
		// this one defers itself: the composer asks for it too, and it must
		// wait whichever of the two asks first
		this.instanceStore.load()
		// the settings page changes them; this sidebar holds its own copy
		this.onListsChanged = () => this.fetchLists()
		eventBus.on(LISTS_CHANGED, this.onListsChanged)
		eventBus.on(COMPOSE_WITH_FILES, this.composeWithFiles)

		// how many entries Explore shows depends on how much room the rail has
		this.measureViewport()
		window.addEventListener('resize', this.measureViewport)
		this.watchRail()

		try {
			this.exploreOpen = window.localStorage.getItem(EXPLORE_OPEN_KEY) !== '0'
		} catch {
			// no stored preference to read; open is the better default
		}
		this.openComposerFromQuery()

		// the badge is only honest if it keeps up: with notify_push the server
		// says when something arrived, and without it a slow poll is enough
		this.stopListening = listen('social_timeline', () => {
			this.notificationsStore.fetchUnreadNotifications()
			this.notificationsStore.fetchUnreadDirectMessages()
		})
		if (!this.stopListening) {
			this.pollTimer = setInterval(
				() => {
					this.notificationsStore.fetchUnreadNotifications()
					this.notificationsStore.fetchUnreadDirectMessages()
				},
				UNREAD_POLL_MS,
			)
		}
	},

	beforeUnmount() {
		eventBus.off(LISTS_CHANGED, this.onListsChanged)
		eventBus.off(COMPOSE_WITH_FILES, this.composeWithFiles)
		window.removeEventListener('resize', this.measureViewport)
		this.railObserver?.disconnect()
		if (typeof this.stopListening === 'function') {
			this.stopListening()
		}
		if (this.pollTimer !== null) {
			clearInterval(this.pollTimer)
		}
		if (this.searchTimer !== null) {
			window.clearTimeout(this.searchTimer)
		}
	},

	methods: {
		/**
		 * What a run of Explore rows is called.
		 *
		 * @param {string} kind followed, trend or list
		 * @return {string} the caption over that run
		 */
		exploreCaption(kind) {
			const captions = {
				tag: t('social', 'Tags you follow'),
				trend: t('social', 'Trending now'),
				list: t('social', 'Your lists'),
			}

			return captions[kind] ?? t('social', 'Explore')
		},

		/**
		 * Puts the travelling mark on the row that is lit.
		 *
		 * Read off the row rather than computed from an index: the rows are not
		 * all the same height -- a counter makes one taller -- and Explore folds,
		 * which moves everything below it.
		 *
		 * The first placement does not animate. A mark that slid in from the top
		 * of the sidebar every time the app opened would be the first thing a
		 * reader saw and would say nothing.
		 */
		/**
		 * The icon of the row just chosen acknowledges the press.
		 *
		 * At the sidebar rather than at the page, and that is the point: the page
		 * may be a chunk away, and something has to answer the click now. Only
		 * when the page actually changed -- a press on the row you are already on
		 * is not a choice.
		 *
		 * @param {object} to where the reader is going
		 * @param {object} from where they were
		 */
		markChosen(to, from) {
			if (pageIdentity(to) === pageIdentity(from)) {
				return
			}

			// matched on where the entry goes rather than on what is lit: the
			// route has not been applied yet when this runs, so asking which
			// row is active answers with the one being left
			const entry = this.menu.timelines.find((item) => pageIdentity(item.to) === pageIdentity(to))
			window.clearTimeout(this.chosenTimer)
			this.chosen = entry?.key ?? ''
			this.chosenTimer = window.setTimeout(() => {
				this.chosen = ''
			}, 420)
		},

		placeIndicator() {
			const list = this.$el?.querySelector?.('.app-navigation__list')
			const active = list?.querySelector?.('.app-navigation-entry--active')
			if (!list || !active) {
				this.indicator = { ...this.indicator, height: 0 }

				return
			}

			const box = active.getBoundingClientRect()
			const frame = list.getBoundingClientRect()
			const settled = this.indicator.height > 0

			this.indicator = {
				top: Math.round(box.top - frame.top + list.scrollTop),
				height: Math.round(box.height),
				settled,
			}
		},

		/**
		 * Whether this instance offers a section.
		 *
		 * Default on, and on for anything the server did not mention: an older
		 * server, or a page rendered before this setting existed, sends nothing,
		 * and a sidebar that lost two entries because of that would look like
		 * the app had broken.
		 *
		 * @param {string} section photos or videos
		 * @return {boolean} whether to draw its entry
		 */
		offers(section) {
			return this.settingsStore.getServerData?.sections?.['section_' + section] !== false
		},

		/**
		 * Drops the entries this instance does not offer.
		 *
		 * `offered` is taken off the entries that pass, because every entry is
		 * spread into the template as it stands and a stray property would end up
		 * on the component.
		 *
		 * @param {object[]} entries the menu as written
		 * @return {object[]} the menu as drawn
		 */
		offered(entries) {
			return entries
				.filter((entry) => entry.offered !== false)
				.map((entry) => {
					const drawn = { ...entry }
					delete drawn.offered

					return drawn
				})
		},

		/**
		 * "Share to Social" in the Files app lands here with `?attach=<path>`
		 * once per file. Open the New post dialog with them, and take the
		 * query off the address so a reload does not attach them twice.
		 */
		/**
		 * Opens the New post dialog with files already attached, for a page
		 * that picked them without a composer of its own.
		 *
		 * @param {File[]} files what was chosen
		 */
		composeWithFiles(files) {
			const chosen = (Array.isArray(files) ? files : []).filter((file) => file instanceof File)
			if (chosen.length === 0) {
				return
			}

			this.composerFiles = chosen
			this.showComposer = true
		},

		/** Closes the dialog and forgets what it was opened with, so it opens empty next time. */
		closeComposer() {
			this.showComposer = false
			this.composerFiles = []
			this.composerPaths = []
		},

		openComposerFromQuery() {
			const raw = this.$route?.query?.attach
			const paths = (Array.isArray(raw) ? raw : [raw])
				.filter((path) => typeof path === 'string' && path !== '')
			if (paths.length === 0) {
				return
			}

			this.composerPaths = paths
			this.showComposer = true

			const query = { ...this.$route.query }
			delete query.attach
			this.$router?.replace?.({ ...this.$route, query })
		},

		t: translate,
		n: translatePlural,
		/**
		 * What the instance is talking about. The counts are kept by cron, so
		 * this is one cheap read; a failure leaves the section out rather than
		 * bothering anyone about it.
		 */
		async fetchTrending() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/trends/tags'), {
					params: { limit: 5 },
				})
				this.trending = Array.isArray(data) ? data : []
			} catch {
				this.trending = []
			}
		},

		/**
		 * The reader's lists. Asking for them is also what makes the group
		 * lists they are missing, so this is asked once per page and the
		 * answer drawn as it comes.
		 */
		/**
		 * The hashtags the reader follows.
		 *
		 * These had no place in the sidebar at all: somebody could follow a
		 * tag and then only meet it again by going to its page. A failure
		 * leaves them out rather than bothering anyone about it, the way the
		 * trending read does.
		 */
		async fetchFollowedTags() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/followed_tags'))
				this.followedTags = Array.isArray(data) ? data : []
			} catch {
				this.followedTags = []
			}
		},

		/**
		 * @param {object} entry one Explore entry
		 * @return {object} where pressing it goes
		 */
		/**
		 * @param {object} entry one of the Explore children
		 * @return {string} something stable to key it by
		 */
		keyFor(entry) {
			return entry.kind === 'list' ? `list-${entry.list.id}` : `${entry.kind}-${entry.tag.name}`
		},

		routeFor(entry) {
			return entry.kind === 'list'
				? { name: 'list', params: { id: entry.list.id } }
				: { name: 'tags', params: { tag: entry.tag.name } }
		},

		/**
		 * @param {object} entry one Explore entry
		 * @return {string|undefined} what hovering it explains, where that is
		 *                            not obvious from the name
		 */
		titleFor(entry) {
			if (entry.kind === 'list' && entry.list.nextcloud_group) {
				return translate(
					'social',
					'Everyone in the Nextcloud group {group} who has a Social account',
					{ group: entry.list.title },
				)
			}

			// the two kinds of hashtag row look alike and mean different
			// things: one is a choice this reader made, the other is what the
			// instance happens to be busy with
			if (entry.kind === 'trend') {
				return translate('social', 'Being posted about on this server right now')
			}

			return undefined
		},

		/**
		 * @param {object} entry one Explore entry
		 * @return {boolean} whether its timeline is the one being shown
		 */
		isExploreActive(entry) {
			return entry.kind === 'list' ? this.isListActive(entry.list) : this.isTagActive(entry.tag)
		},

		/**
		 * @param {boolean} open what the reader just did to the entry
		 */
		onExploreToggle(open) {
			this.exploreOpen = open

			try {
				window.localStorage.setItem(EXPLORE_OPEN_KEY, open ? '1' : '0')
			} catch {
				// a private window or blocked site data; the entry still works,
				// it just opens again next time
			}
		},

		/**
		 * Re-measures whenever the rail changes shape, not only when the
		 * window does: an error entry appearing, a zoom, a theme with taller
		 * rows.
		 */
		watchRail() {
			if (typeof ResizeObserver !== 'function') {
				// the window listener still keeps it roughly right
				return
			}

			// called again whenever the entry comes back; one observer only
			this.railObserver?.disconnect()

			this.railObserver = new ResizeObserver(() => {
				// off the observer's own callback, or a measurement that
				// changes the rail re-enters it
				window.requestAnimationFrame(() => this.measureRail())
			})

			const list = this.$refs.exploreItem?.$el?.parentElement
			if (list) {
				this.railObserver.observe(list)
			}
		},

		/** Keeps the fallback in step with the window. */
		measureViewport() {
			this.viewportHeight = window.innerHeight
			this.measureRail()
		},

		/**
		 * How many entries the rail actually has room for, right now.
		 *
		 * Measured rather than worked out from the window, because the rail
		 * holds things that come and go — an error entry when something
		 * breaks — and because browser zoom and a denser theme change every
		 * height at once.
		 * A constant for "everything that is not an Explore child" was wrong
		 * the first time it was written and would go wrong again.
		 *
		 * The free space is the rail's own height less everything in it that
		 * is not an Explore child. It has to be measured that way round: the
		 * children are in the rail too, so a figure that counted them would
		 * grow each time it was applied and shrink each time it was read back.
		 */
		measureRail() {
			const item = this.$refs.exploreItem?.$el
			const list = item?.parentElement
			const children = item?.querySelector('.app-navigation-entry__children')
			const rows = children ? children.children : []

			if (!list || !list.clientHeight || rows.length === 0) {
				// nothing laid out yet, or collapsed, or jsdom: the window
				// figure stands in
				this.measuredCap = null
				return
			}

			// Where the children begin, in the rail's own content
			// coordinates. This is set by everything *above* them and not by
			// how many there are, which is what keeps the measurement from
			// feeding on itself.
			const listTop = list.getBoundingClientRect().top
			const childrenTop = children.getBoundingClientRect().top - listTop + list.scrollTop

			// The pitch from one row to the next, which is the only figure
			// that carries whatever margin they have. `offsetHeight` does not,
			// and summing it left the rail overflowing by exactly the margins
			// — the last entries ran under the account footer.
			const first = rows[0].getBoundingClientRect()
			const pitch = rows.length > 1
				? rows[1].getBoundingClientRect().top - first.top
				: first.height

			this.measuredCap = capacityFrom({
				free: list.clientHeight - childrenTop,
				rowHeight: pitch,
			})
		},

		async fetchLists() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/lists'))
				const lists = Array.isArray(data) ? data : []
				// group lists first: nobody made them, and they are what a
				// colleague is looking for; within each kind, as the server orders
				this.lists = [
					...lists.filter((list) => list.nextcloud_group),
					...lists.filter((list) => !list.nextcloud_group),
				]
			} catch {
				this.lists = []
			}
		},

		/**
		 * @param {object} list a List entity
		 * @return {boolean} whether its timeline is the one being shown
		 */
		isListActive(list) {
			return this.$route.name === 'list' && String(this.$route.params.id ?? '') === String(list.id)
		},

		/**
		 * @param {object} tag a Tag entity
		 * @return {number} how often it was used in the window the server chose
		 */
		usesOf(tag) {
			return Number.parseInt(tag.history?.[0]?.uses ?? 0) || 0
		},

		/**
		 * @param {object} tag a Tag entity
		 * @return {boolean} whether its timeline is the one being shown
		 */
		/**
		 * @param {object} to a route location
		 * @return {string} where it points, so the entry is a real link that can
		 *                  be opened in a new tab or copied
		 */
		hrefFor(to) {
			if (typeof to === 'string') {
				return to
			}
			return this.$router.resolve(to).href
		},

		/**
		 * Follows the entry inside the app, unless the reader asked the browser
		 * for something else — the modifier keys and the middle button belong to
		 * them, which is the rule router-link itself applies.
		 *
		 * @param {object} to a route location
		 * @param {MouseEvent} event the click
		 */
		navigate(to, event) {
			// Let the browser follow an absolute Nextcloud page URL. This keeps
			// normal link behavior (including modified clicks) for cross-app pages.
			if (typeof to === 'string') {
				return
			}

			if (event && (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button > 0)) {
				return
			}

			event?.preventDefault()
			this.$router.push(to)
		},

		/**
		 * @param {object} tag a Tag entity
		 * @return {boolean} whether its timeline is the one being shown
		 */
		isTagActive(tag) {
			return this.$route?.name === 'tags' && this.$route?.params?.tag === tag.name
		},

		dismissError(id) {
			this.errorsStore.dismissAppError(id)
		},

		clearAllErrors() {
			this.errorsStore.clearErrors()
		},

		/**
		 * Searching now costs a request, so it waits for the typing to stop.
		 * Un-debounced, every keystroke went straight through.
		 */
		onSearchInput() {
			if (this.searchTimer !== null) {
				window.clearTimeout(this.searchTimer)
			}
			this.searchTimer = window.setTimeout(() => {
				this.searchTimer = null
				this.$emit('search', this.localSearch)
			}, SEARCH_DEBOUNCE_MS)
		},

		/**
		 * Whether an entry is the page on screen. An entry matches its own
		 * route and any dot-namespaced child of it, so Profile stays lit on
		 * `profile.followers`, while a sibling that differs only by a param
		 * does not match.
		 *
		 * @param {object} item one of the menu entries
		 * @return {boolean} whether it is the current page
		 */
		isActive(item) {
			const route = this.$route
			const to = item.to
			if (typeof to === 'string') {
				return false
			}
			const name = String(route.name ?? '')
			if (name !== to.name && !name.startsWith(to.name + '.')) {
				return false
			}

			// an entry whose page is read at more than one scope owns all of
			// them: the type says which, and the switcher on the page says
			// which one of the entry's own scopes is being read
			if (item.covers !== undefined) {
				return item.covers.includes(String(route.params.type ?? ''))
			}

			// An optional param the URL leaves out still arrives as '', so the
			// two sides are compared value by value across both key sets:
			// counting keys makes /timeline, whose params are {type: ''}, look
			// different from the My Feed entry, which carries no params at all.
			const wanted = to.params ?? {}
			const actual = route.params ?? {}
			for (const key of new Set([...Object.keys(wanted), ...Object.keys(actual)])) {
				if (String(wanted[key] ?? '') !== String(actual[key] ?? '')) {
					return false
				}
			}

			return true
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/layout.scss' as layout;

/**
 * The mark that travels between the rows.
 *
 * Drawn over `@nextcloud/vue`'s rows rather than inside one, because they are
 * not ours to change, and positioned from the active row's measured box --
 * see `placeIndicator`. It is decoration: the rows carry their own active
 * state for anybody not looking at the screen.
 *
 * `--settled` is what allows it to move. The first placement has it off, so
 * the mark appears where it belongs instead of sliding down from the top of
 * the sidebar the moment the app opens.
 */
.navigation__indicator {
	position: absolute;
	inset-inline: 4px;
	inset-block-start: 0;
	border-radius: var(--border-radius-large);
	background: var(--color-primary-element-light);
	pointer-events: none;
	z-index: 0;
}

.navigation__indicator--settled {
	transition: transform .26s cubic-bezier(.2, .8, .2, 1), height .26s cubic-bezier(.2, .8, .2, 1);
}

/* the rows have to sit over it, or it covers their words */
:deep(.app-navigation-entry) {
	position: relative;
	z-index: 1;
}

/* the list is what the mark is positioned against */
:deep(.app-navigation__list) {
	position: relative;
}

/* one small pop on the icon of the row just chosen: the press is answered at
   the sidebar, which is where it happened, rather than only by the page that
   may still be loading */
@keyframes navigation-chosen {
	0% { transform: scale(1); }
	45% { transform: scale(1.22); }
	100% { transform: scale(1); }
}

.navigation__chosen :deep(.app-navigation-entry-icon),
.navigation__chosen :deep(.material-design-icon) {
	animation: navigation-chosen .42s cubic-bezier(.2, .8, .2, 1);
}

/* the bell, once: a quarter turn each way and done */
@keyframes navigation-ring {
	0%, 100% { transform: rotate(0); }
	15% { transform: rotate(-14deg); }
	35% { transform: rotate(11deg); }
	55% { transform: rotate(-7deg); }
	75% { transform: rotate(4deg); }
}

.navigation__rang :deep(.material-design-icon) {
	animation: navigation-ring .7s ease-in-out;
	transform-origin: 50% 20%;
}

/* smaller than Nextcloud's default, which is sized for a number that has to be
   answered rather than one that is only being reported */
:deep(.counter-bubble__counter) {
	font-size: 11px;
	min-inline-size: 18px;
	block-size: 18px;
	line-height: 18px;
}

@media (prefers-reduced-motion: reduce) {
	.navigation__indicator--settled {
		transition: none;
	}

	.navigation__chosen :deep(.app-navigation-entry-icon),
	.navigation__chosen :deep(.material-design-icon),
	.navigation__rang :deep(.material-design-icon) {
		animation: none;
	}
}

/* The button the More menu hangs off is the reader's own account: their
   portrait where the cog was, and the name they publish under beside it.
   `NcAppNavigationSettings` renders that cog from a hard-coded path with no
   slot to replace it, so the icon box carries the picture as its background
   and the cog inside it is hidden rather than fought with. */
.navigation__more :deep(.button-vue__icon) {
	background-image: var(--social-face);
	background-position: center;
	background-size: cover;
	border-radius: 50%;
	// the picture is the icon, so the glyph that was there must not show
	// through it — including its own background, which is not transparent
	overflow: hidden;

	svg {
		visibility: hidden;
	}
}

.navigation__more :deep(.button-vue__text) {
	// the button's wrapper has no gap of its own, so the name sits against the
	// portrait unless it is given one
	margin-inline-start: 8px;
	font-weight: 600;
}

.navigation__subname {
	font-size: 12px;
	color: var(--color-text-lighter);
}

/*
 * The account menu is a panel over the sidebar, not more of the sidebar.
 *
 * Its rows are `NcAppNavigationItem`s -- the same component the timelines
 * above are drawn with -- so opening it put eight more rows on the end of a
 * column of seven, in the same type at the same indent with the same icons.
 * Nothing said where the places to read ended and the reader's own pages
 * began, and over a full sidebar the whole thing read as one list that had
 * grown rather than as a menu that had opened.
 *
 * So while it is open the drawer is drawn as its own surface: the page's
 * background rather than the sidebar's, a rounded edge, a hairline and a
 * shadow cast up over the rows it covers. Closed, it is a row like any other
 * again -- nothing here applies until the button says it is open.
 *
 * The panel is addressed as the container's only child carrying an `id`: that
 * is the element the button's `aria-controls` points at, and the component's
 * own class names are content-hashed (`_content_CW2CF`) and change with the
 * next release of the library. There is a test for both.
 */
.navigation__more {
	/* the shadow falls on the entries above, so the panel has to be over them */
	position: relative;
	z-index: 2;
	transition: background-color .2s ease, box-shadow .2s ease;
}

/*
 * Open, the drawer is frosted glass: the page's own background, a little
 * over half opaque and blurred hard, so the entries it covers show through as
 * a soft haze and the rows on it keep the sidebar's ink. A bright hairline
 * catches the top edge and a shadow lifts the pane off the sidebar, which is
 * what separates the two -- the drawer is lighter and sharper-edged than the
 * tinted sidebar it sits on.
 *
 * Built from `--color-main-background`, so the dark theme gets smoked glass
 * with light type rather than a white pane with dark type in the dark.
 */
.navigation__more:has(button[aria-expanded="true"]) {
	background-color: color-mix(in srgb, var(--color-main-background) 62%, transparent);
	backdrop-filter: blur(16px) saturate(1.6);
	-webkit-backdrop-filter: blur(16px) saturate(1.6);
	border-start-start-radius: var(--border-radius-large, 12px);
	border-start-end-radius: var(--border-radius-large, 12px);
	border-top: 1px solid color-mix(in srgb, #fff 90%, transparent);
	box-shadow:
		0 -1px 0 color-mix(in srgb, #fff 70%, transparent),
		0 -16px 34px -12px rgba(0, 50, 100, .5);
}

.navigation__more > :deep(div[id]) {
	/* The library caps this at 300px, which is four pixels short of the eight
	   entries this menu has: Settings, the last of them, was cut in half and
	   reachable only by scrolling a panel that did not look scrollable. It
	   still gives way to a short window rather than running off the screen. */
	max-height: min(60vh, 420px);
}

/* The account row is the panel's head while the panel is open, so it is set
   in the ink the rest of the sidebar uses for the page you are on, and the
   rows below it are inset from it. */
.navigation__more:has(button[aria-expanded="true"]) :deep(.button-vue__text) {
	color: var(--color-main-text);
}

/*
 * The account menu opens as a drawer: each row pops up out of its own bottom
 * edge, the row nearest the button first and the rest following upward.
 *
 * `NcAppNavigationSettings` animates its own `max-height`, so the panel grew
 * and the rows inside it were simply there when it finished -- the container
 * moved and its contents did not. These scale up from 82%, overshooting a
 * little on the way (the tail of the easing curve goes past 1), so the menu
 * reads as growing out of the button rather than as a list that was already
 * written and got uncovered.
 *
 * **An animation and not a transition, because a transition cannot run here.**
 * The panel is held by `v-show`, so while the menu is shut it is
 * `display: none` and its rows are not rendered at all. An element that was
 * not rendered has no previous value to transition *from*, so the browser
 * jumps straight to the final one: the rows appeared fully formed, all eight
 * at once, and the stagger this file has carried since it was written never
 * ran a single time. The delay was computed correctly and thrown away. A
 * keyframe animation does start when an element is first rendered, which is
 * exactly the moment in question.
 *
 * The stagger runs from the bottom row up, which is the direction the panel
 * itself grows: it is anchored at the foot of the sidebar, so an opening
 * drawer pushes its own top edge upward. Rows appearing the other way would
 * travel against it. There is nothing to mirror for right-to-left -- a scale
 * has no leading edge -- so the drawer is the same in both.
 *
 * Driven off `aria-expanded`, which is the accordion's own state and the only
 * hook it offers: there is no `open` prop and no event, and the component's
 * internal class names are content-hashed, so anything keyed on those would
 * break on the next release of the library.
 *
 * The delay comes from `--entry-index` and `--entry-total`, both set in the
 * template. The DOM cannot be counted here: `NcAppNavigationItem` puts every
 * entry in a wrapper of its own, so each one is its parent's first child and
 * `nth-child` hands them all the same delay -- and `nth-last-child`, which
 * would give the bottom-up order directly, fails for the same reason. There
 * are tests that they are laid out that way and that the total matches the
 * number of entries, so these comments stop being true loudly rather than
 * quietly.
 *
 * Nothing here needs an `@supports` guard any more. Where `:has()` is not
 * supported the whole rule is invalid and dropped, and because the resting
 * state of a row is now its own -- opacity and scale live in the keyframes,
 * not in a base rule -- what is left is a menu that opens without animating.
 * The guard existed to stop a bare `opacity: 0` from leaving it permanently
 * empty, and there is no longer a bare `opacity: 0`.
 */
/*
 * The unread Activities count, on the account button while the menu is shut.
 *
 * Activities lives inside the account menu, and a count nobody can see until
 * they open a menu is a count nobody sees. So the button carries it, in the
 * same quiet bubble the entry uses, and drops it once the menu is open and
 * the entry shows its own. `attr()` reads `data-unread`, which the template
 * removes altogether when there is nothing unread.
 */
.navigation__more[data-unread]::after {
	content: attr(data-unread);
	position: absolute;
	inset-block-start: calc(var(--default-grid-baseline) + var(--default-clickable-area) / 2);
	inset-inline-end: calc(var(--default-grid-baseline) * 3);
	transform: translateY(-50%);
	min-inline-size: 20px;
	padding-inline: 6px;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 12px;
	font-weight: 600;
	line-height: 20px;
	text-align: center;
	font-variant-numeric: tabular-nums;
	pointer-events: none;
}

.navigation__more:has(button[aria-expanded="true"])::after {
	display: none;
}

/* a new one arrived: the bubble swells once, as the bell used to wobble */
@keyframes social-unread-bump {
	0%, 100% { transform: translateY(-50%) scale(1); }
	40% { transform: translateY(-50%) scale(1.3); }
}

.navigation__more--rang[data-unread]::after {
	animation: social-unread-bump .5s cubic-bezier(.3, 1.5, .5, 1);
}

@media (prefers-reduced-motion: reduce) {
	.navigation__more--rang[data-unread]::after {
		animation: none;
	}
}

@keyframes social-menu-pop {
	from {
		opacity: 0;
		transform: scale(.82);
	}
}

.navigation__more:has(button[aria-expanded="true"]) :deep(.app-navigation-entry) {
	/* out of its own bottom edge, towards the row above */
	transform-origin: 50% 100%;
	animation: social-menu-pop .39s cubic-bezier(.34, 1.56, .64, 1) both;
	/* counted from the bottom: the last row waits for nothing */
	animation-delay: calc((var(--entry-total, 1) - 1 - var(--entry-index, 0)) * 38ms);
}

/* A reader who asked for no movement gets the menu all at once, with no
   stagger: a row that appears a fifth of a second after the one below it is
   the movement they turned off, even though nothing travels. */
@media (prefers-reduced-motion: reduce) {
	.navigation__more:has(button[aria-expanded="true"]) :deep(.app-navigation-entry) {
		animation: none;
	}
}

/*
 * The composer draws its own frame, and in a dialog that is one frame too many.
 *
 * In a timeline it is a card among cards: a border, a radius and the app's
 * resting shadow are how it says it is the box that makes the posts below it,
 * and `position: sticky` keeps it at the top while they scroll past. A dialog
 * has already said all of that -- it is a panel over a dimmed page, with its
 * own edge -- so the inner border reads as a box drawn inside a box, and
 * sticking to the top of something that does not scroll does nothing at all.
 *
 * Undone here rather than in the composer: what changes is not the composer but
 * where it is, and this is the only place that knows.
 */
.modal-composer :deep(.new-post) {
	margin: 0;
	padding: 0;
	max-width: none;
	position: static;
	border: 0;
	border-radius: 0;
	box-shadow: none;
}

/*
 * The gap the composer lost when its frame came off.
 *
 * In a timeline the composer is a card with 18px of padding of its own. The
 * rule above takes the card away inside a dialog -- rightly, a panel over a
 * dimmed page has already drawn one frame -- but it took the padding with it,
 * and nothing put it back, so the box you write in and the Post button sat
 * hard against the dialog's edge. The dialog owns the inset now, which is
 * what the Errors dialog beside it has always done.
 *
 * Less at the top than the sides: the dialog's own header and its rule
 * already separate the composer from the name above it.
 */
.modal-composer {
	padding: 12px 18px 18px;
}

/* a phone has less to spare, and the dialog is the whole screen */
@include layout.below(layout.$phone) {
	.modal-composer {
		padding: 8px 12px 12px;
	}
}

/* the lift on focus goes with it: there is nothing left to lift */
.modal-composer :deep(.new-post:focus-within) {
	box-shadow: none;
}

.modal-errors {
	padding: calc(var(--default-grid-baseline) * 4);

	&__item {
		padding: calc(var(--default-grid-baseline) * 2) 0;
		border-bottom: 1px solid var(--color-border);
	}

	&__title {
		font-weight: 700;
		margin-bottom: 4px;
	}

	&__message {
		color: var(--color-text-lighter);
		margin-bottom: 8px;
		overflow-wrap: break-word;
	}
}

.error-icon {
	color: var(--color-error);
}

:deep(.app-navigation-entry) {
	border-radius: 8px;
	margin: 2px 0;

	&:hover {
		background: var(--color-background-hover);
	}

	&.active {
		background: var(--color-background-dark);
	}
}

/* The call to action.
 *
 * Concentric: a pill that becomes briefly larger than itself. Two rings open
 * outward from its own outline while the disc grows inside it, so everything on
 * screen expands from the same two centres at once. Nothing fills, nothing
 * sweeps, no colour changes hands — the button is the primary colour at rest and
 * the primary colour when reached for, and the only thing that happens is size.
 *
 * The shape is why it works. At 44px tall a full pill is a 22px radius, so its
 * end caps are arcs of a 22px circle; the plus already sits in a 28px disc, a
 * 14px circle, with 8px of air around it. Rounded all the way, the two are the
 * same family of curve and a ring drawn around the outside is concentric with
 * the badge inside. At the 8px corner this button used to have, the same ring
 * cut across the disc instead of agreeing with it.
 *
 * Rings rather than a blurred shadow: `0 0 0 <spread>` is a hard outline offset
 * from the border box, which is the only kind of shadow that stays the button's
 * own shape at any distance from it.
 */

/* The class is written twice because it has to win, not because it is two
 * things. `.button-vue[data-v-…]` sets `border-radius`, `font-weight` and
 * `transition` at exactly the specificity a single scoped class has, which
 * leaves the winner to source order — and the order of this component's styles
 * against the library's is not something this file gets to decide. Everything
 * that would otherwise be a silent tie lives in here. */
.navigation__compose.navigation__compose {
	position: relative;
	margin: 2px 4px 8px;
	/* `wide` sets `width: 100%`, which is the container's full content width --
	   and the margin above it has nowhere to go. The button was as wide as the
	   whole list *and* shifted 4px right, so its right edge overhung the rows
	   below it and ran into the edge of the sidebar; the left looked correct
	   because there the margin pushed it inward. The width has to come off
	   explicitly: `auto` is no use on a `<button>`, which shrink-wraps, and that
	   is why NcButton reaches for `fit-content` and `100%` and never `auto`. */
	width: calc(100% - 8px);
	font-weight: 600;
	/* The height the design was drawn at, and the reason the rest of it works.
	   NcButton stands at `--default-clickable-area`, which this server sets to
	   34px — and the plus already sits in a 28px disc, so at 34 the badge has
	   three pixels of air and reads as jammed between the two edges rather than
	   held inside them. At 44 it has eight, the pill's end caps become arcs of a
	   22px circle against the disc's 14, and a ring drawn around the outside is
	   concentric with the badge inside instead of merely near it.

	   It does make this taller than every other control in the sidebar. That is
	   the one thing in there that is not a place to go, so it is the one thing
	   that may be. */
	min-height: 44px;
	/* a true pill: half of the 44 above */
	border-radius: 999px;
	/* NcButton draws a 1px border weighted to 2px along the bottom. On a solid
	   primary fill it is invisible either way, but rings drawn around an uneven
	   outline are not concentric with anything. */
	border: 0;
	/* NcButton pads to `--button-radius` + a baseline, which is 12px and was
	   drawn as 8: at 12 the disc sits too far inside its own end cap for the two
	   curves to read as the same shape. */
	padding-inline: 8px;
	/* The rings hang outside the button, so nothing may clip them. NcButton
	   sets `overflow: hidden` on itself, which does not affect a shadow on the
	   button but does cut one off a child -- and the rings are a child now, for
	   the reason below. */
	overflow: visible;
	transition: transform .32s cubic-bezier(.22, 1.2, .48, 1);
}

/*
 * The rings, on a layer of their own.
 *
 * They were two `box-shadow`s on the button, grown from zero spread. That reads
 * correctly and paints terribly: `box-shadow` is not a compositor property, so
 * every frame of the growth was a repaint on the main thread -- the same thread
 * that, while the app is still starting, is parsing chunks and mounting a
 * timeline that renders every post it has with a plain `v-for`. The rings lost
 * that race, which is why the button looked dead until the page settled.
 *
 * Here the shadow is painted once, at full size, and never animated. What
 * animates is `opacity` and `transform` on the layer holding it, and those two
 * are handled by the compositor: once the hover has been noticed the growth
 * runs off the main thread and keeps its frame rate no matter what Vue is doing
 * on the other side of the page.
 *
 * What this cannot fix is a main thread blocked solid. Noticing `:hover` at all
 * is a style recalculation, and a recalculation waits its turn like everything
 * else; through one long task the button still cannot respond. It responds
 * through all the short ones, which is most of a page load.
 */
.navigation__compose::after {
	content: '';
	position: absolute;
	z-index: -1;
	inset: 0;
	border-radius: inherit;
	box-shadow:
		0 0 0 3px color-mix(in srgb, var(--color-primary-element) 34%, transparent),
		0 0 0 9px color-mix(in srgb, var(--color-primary-element) 14%, transparent);
	opacity: 0;
	transform: scale(.94);
	transition:
		opacity .28s ease,
		transform .32s cubic-bezier(.22, 1.2, .48, 1);
	pointer-events: none;
	/* promoted up front: a layer created at the moment of the hover would be
	   created by the main thread, which is the thread being waited on */
	will-change: opacity, transform;
}

/* Reached for, by pointer or by keyboard. The focus ring is the server's and
   arrives `!important`, so on focus the rings give way to it — which is the
   right way round: the ring that says "the keyboard is here" is the one that
   has to be unmissable. */
.navigation__compose.navigation__compose:is(:hover, :focus-visible) {
	transform: translateY(-1px);
}

.navigation__compose:is(:hover, :focus-visible)::after {
	opacity: 1;
	transform: scale(1);
}

.navigation__compose.navigation__compose:active {
	transform: translateY(0) scale(.985);
}

.navigation__compose:active::after {
	transform: scale(.97);
}

/* the plus sits in a disc of its own, which grows with the rings rather than
   kicking once and settling back: the whole gesture is one expansion, and a pop
   that returns to where it started would be the one thing on screen not doing
   it. It grows rather than turns, as it always did — a plus is the same plus at
   every right angle, so a rotation of one is movement nobody can see. */
/* the glyph rather than the icon box it sits in, which is nearly as tall as
   the button and would make a disc the size of the whole end of it */
.navigation__compose :deep(.plus-icon) {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	/* a wash of the label's own colour, so the disc belongs to the button
	   whatever the instance's primary colour is */
	background: color-mix(in srgb, var(--color-primary-element-text) 22%, transparent);
	transition:
		background-color .2s ease,
		transform .32s cubic-bezier(.22, 1.2, .48, 1);
}

/* the wrapper has no gap of its own, and the label sits against the disc */
.navigation__compose :deep(.button-vue__text) {
	margin-inline-start: 6px;
}

.navigation__compose:is(:hover, :focus-visible) :deep(.plus-icon) {
	transform: scale(1.12);
	background: color-mix(in srgb, var(--color-primary-element-text) 32%, transparent);
}

@media (prefers-reduced-motion: reduce) {
	.navigation__compose.navigation__compose,
	.navigation__compose::after,
	.navigation__compose :deep(.plus-icon) {
		transition: none;
	}

	/* the rings still open, they just open at once */
	.navigation__compose:is(:hover, :focus-visible)::after {
		transform: none;
	}

	.navigation__compose.navigation__compose:is(:hover, :focus-visible),
	.navigation__compose.navigation__compose:active {
		transform: none;
	}

	.navigation__compose:is(:hover, :focus-visible) :deep(.plus-icon) {
		transform: none;
	}

	/* the rings still open, they just open at once: a reader who asked for no
	   movement still has to be able to tell a hovered button from a resting
	   one, and with nothing else changing the rings are all there is to tell
	   them with */
}
</style>
