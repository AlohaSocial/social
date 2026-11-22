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
				@update:modelValue="onSearchInput"
				@keydown.enter="onSearchSubmit" />
		</template>
		<template #list>
			<!-- The mark that slides to the active row, so a click is answered
			     at once however long the page takes. The rows are
			     `@nextcloud/vue`'s, so it is positioned from the active row's box. -->
			<span
				v-show="indicator.height > 0"
				class="navigation__indicator"
				:class="{ 'navigation__indicator--settled': indicator.settled }"
				:style="{ transform: `translateY(${indicator.top}px)`, height: `${indicator.height}px` }"
				aria-hidden="true" />
			<!-- NcButton rather than a bare <button>: the server's button rules
			     all exclude `.button-vue`, and a hand-rolled one loses to them. -->
			<NcButton
				v-if="!currentAccount?.moved"
				class="navigation__compose"
				variant="primary"
				wide
				alignment="start"
				@click="openComposer()">
				<template #icon>
					<IconPlus :size="20" />
				</template>
				{{ t('social', 'New post') }}
			</NcButton>
			<!-- a moved account cannot post: the server refuses it -->
			<p v-else class="navigation__moved">
				{{ t('social', 'This account has moved to {acct}.', { acct: '@' + currentAccount.moved.acct }) }}
			</p>

			<NcAppNavigationItem
				v-if="hasErrors"
				:name="t('social', 'Errors')"
				@click.prevent="showErrors = true">
				<template #icon>
					<IconAlertCircle class="error-icon" :size="20" />
				</template>
				<!-- `counter` is a slot in @nextcloud/vue 9, not a prop -->
				<template #counter>
					<NcCounterBubble :count="errorCount" type="highlighted" />
				</template>
			</NcAppNavigationItem>

			<!-- `href` rather than `to`: with `to` the component ORs in the
			     router's own active state, which lights /timeline while
			     /timeline/direct is open. navigate() keeps plain clicks in the app. -->
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
				:ariaDescription="unreadDescription(item.counter)"
				@click="navigate(item.to, $event)">
				<template #icon>
					<component :is="item.icon" :size="20" />
				</template>
				<!-- not `highlighted`: this counts things that happened, not
				     things that need answering -->
				<template v-if="item.counter > 0" #counter>
					<NcCounterBubble :count="item.counter" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationSpacer />

			<!-- Followed tags, lists, Bluesky feeds and trending tags in one
			     collapsible entry. Trending only gets the room the others leave. -->
			<NcAppNavigationItem
				v-if="exploreTotal > 0"
				ref="exploreItem"
				class="navigation__explore"
				:name="t('social', 'Explore')"
				:allowCollapse="true"
				:open="exploreOpen"
				@update:open="onExploreToggle">
				<template #icon>
					<IconBinoculars :size="20" />
				</template>
				<!-- a caption over each run of one kind, even a run of one -->
				<template v-for="group in exploreGroups" :key="group.kind">
					<NcAppNavigationCaption :name="group.caption" />
					<NcAppNavigationItem
						v-for="entry in group.entries"
						:key="keyFor(entry)"
						:class="entry.kind === 'list' || entry.kind === 'feed' ? 'navigation__list' : 'navigation__trend'"
						:name="nameFor(entry)"
						:title="titleFor(entry)"
						:href="hrefFor(routeFor(entry))"
						:active="isExploreActive(entry)"
						@click="navigate(routeFor(entry), $event)">
						<template #icon>
							<IconAccountGroup v-if="entry.kind === 'list' && entry.list.nextcloud_group" :size="20" />
							<IconFormatListBulleted v-else-if="entry.kind === 'list'" :size="20" />
							<IconBluesky v-else-if="entry.kind === 'feed'" :size="20" />
							<IconTrendingUp v-else-if="entry.kind === 'trend'" :size="20" />
							<IconPound v-else :size="20" />
						</template>
						<!-- a trending tag shows how busy it is, where the other
						     entries show their unread count -->
						<template v-if="entry.kind === 'trend'" #counter>
							<span class="navigation__subname">
								{{ n('social', '%n post', '%n posts', usesOf(entry.tag)) }}
							</span>
						</template>
					</NcAppNavigationItem>
				</template>

				<template #actions>
					<NcActionButton closeAfterClick @click="managingLists = true">
						<template #icon>
							<IconCog :size="20" />
						</template>
						{{ t('social', 'Manage lists') }}
					</NcActionButton>
					<NcActionButton v-if="blueskyEnabled" closeAfterClick @click="navigate({ name: 'settings', hash: '#bluesky-feeds' })">
						<template #icon>
							<IconBluesky :size="20" />
						</template>
						{{ t('social', 'Manage Bluesky feeds') }}
					</NcActionButton>
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationSpacer v-if="exploreTotal > 0" />
		</template>
		<template #footer>
			<div class="navigation__footer">
				<!-- The account menu, under the reader's name and portrait.
				     `NcAppNavigationSettings` has no slot for its cog icon, so the
				     portrait is set as the icon's background and the cog hidden. -->
				<NcAppNavigationSettings
					class="navigation__more"
					:style="{ '--social-face': faceImage, '--entry-total': moreCount }"
					:name="profileName">
					<!-- `--entry-index` and `--entry-total` stagger the opening.
					     Each entry sits in its own wrapper, so `nth-child` cannot
					     count them. -->
					<NcAppNavigationItem
						v-for="(item, index) in menu.more"
						:key="item.key"
						:style="{ '--entry-index': index }"
						:name="item.title"
						:href="hrefFor(item.to)"
						:active="isActive(item)"
						:ariaDescription="unreadDescription(item.counter)"
						@click="navigate(item.to, $event)">
						<template #icon>
							<component :is="item.icon" :size="20" />
						</template>
						<template v-if="item.counter > 0" #counter>
							<NcCounterBubble :count="item.counter" type="highlighted" />
						</template>
					</NcAppNavigationItem>
					<!-- the server's rules, contact and legal links -->
					<NcAppNavigationItem
						:style="{ '--entry-index': menu.more.length }"
						:name="t('social', 'About this server')"
						:href="hrefFor({ name: 'about' })"
						:active="isActive({ to: { name: 'about' } })"
						@click="navigate({ name: 'about' }, $event)">
						<template #icon>
							<IconInformationOutline :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						:style="{ '--entry-index': menu.more.length + 1 }"
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

	<SettingsDialog
		v-if="managingLists"
		:title="t('social', 'Lists')"
		:description="t('social', 'A list is a few of the people you follow, read as a timeline of its own.')"
		@close="managingLists = false">
		<ListsSettings />
	</SettingsDialog>

	<!-- Closing on an outside click loses nothing: the draft is saved on every
	     keystroke. Labelled by its own heading rather than `name`, which NcModal
	     draws into the page header over Nextcloud's search box. -->
	<NcModal
		v-if="showComposer"
		class="composer-modal"
		closeOnClickOutside
		labelId="social-new-post-title"
		:show="showComposer"
		@update:show="onComposerVisibility">
		<div class="modal-composer" @keydown.esc="onComposerEscape">
			<div class="modal-composer__header">
				<h2 id="social-new-post-title" class="modal-composer__title">
					<NcAvatar
						:url="composerAvatarUrl"
						:displayName="currentUser?.displayName || currentUser?.uid || ''"
						:hideStatus="true"
						:disableMenu="true"
						:disableTooltip="true"
						:size="32"
						aria-hidden="true" />
					{{ composerEdit ? t('social', 'Edit post') : composerReply ? t('social', 'Reply') : t('social', 'New post') }}
				</h2>
				<NcButton
					v-if="storiesAvailable && !composerEdit"
					class="modal-composer__story"
					variant="tertiary"
					@click="openStoryComposer">
					<template #icon>
						<IconImageMultiple :size="20" />
					</template>
					{{ t('social', 'New short') }}
				</NcButton>
			</div>
			<PostEditForm
				v-if="composerEdit"
				ref="postEditForm"
				:item="composerEdit"
				:maxLength="instanceStore.maxCharacters"
				:saving="composerSaving"
				@save="saveComposerEdit"
				@cancel="requestComposerClose" />
			<Composer
				v-else
				ref="modalComposer"
				startExpanded
				compactOptions
				showReplyContext
				:listenForIntents="false"
				:inReplyTo="composerReply"
				:initialQuote="composerQuote"
				:initialDraft="composerDraft"
				:initialDraftContext="composerDraftContext"
				:initialFiles="composerFiles"
				:initialPaths="composerPaths"
				emojiPickerContainer=".modal-wrapper"
				@posted="finishComposer" />
		</div>
	</NcModal>

	<ConfirmDialog
		:open="composerDiscardOpen"
		:name="t('social', 'Discard these changes?')"
		:message="t('social', 'Your unsaved changes and unfinished uploads will be lost. The published post will stay unchanged.')"
		:confirmLabel="t('social', 'Discard changes')"
		@update:open="answerComposerDiscard(false)"
		@confirm="answerComposerDiscard(true)" />

	<ShortComposerDialog
		v-if="showStoryComposer"
		v-model:open="showStoryComposer"
		lifetime="day"
		@posted="showStoryComposer = false" />

	<NcModal
		v-if="showErrors"
		labelId="social-errors-title"
		@close="showErrors = false">
		<div class="modal-errors">
			<h2 id="social-errors-title" class="modal-errors__heading">
				{{ t('social', 'Errors') }}
			</h2>
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
import SettingsDialog from './SettingsDialog.vue'

import IconHome from 'vue-material-design-icons/Home.vue'
import IconBinoculars from 'vue-material-design-icons/Binoculars.vue'
import IconCompass from 'vue-material-design-icons/Compass.vue'
import IconImageMultiple from 'vue-material-design-icons/ImageMultiple.vue'
import IconPlayBoxMultiple from 'vue-material-design-icons/PlayBoxMultiple.vue'
import IconPlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import IconBell from 'vue-material-design-icons/Bell.vue'
import IconCommentAccount from 'vue-material-design-icons/CommentAccount.vue'
import IconAccountCircle from 'vue-material-design-icons/AccountCircle.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconPound from 'vue-material-design-icons/Pound.vue'
import IconTrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import IconAccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import IconFormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import IconBluesky from 'vue-material-design-icons/ButterflyOutline.vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { cssUrl } from '../utils/cssUrl.js'
import { capacityFrom, chooseEntries, entriesThatFit } from '../utils/explore.js'
import { offTimelinePush, onTimelinePush, PUSH_DIRECT, PUSH_NOTIFICATION } from '../services/timelinePush.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import IconInformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import IconCog from 'vue-material-design-icons/Cog.vue'
import IconAlertCircle from 'vue-material-design-icons/AlertCircle.vue'

import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useErrorsStore } from '../store/errors.js'
import { useInstanceStore } from '../store/instance.js'
import { useNotificationsStore } from '../store/notifications.js'
import { useSettingsStore } from '../store/settings.js'
import { pageIdentity } from '../services/pageOrder.js'
import { isCurrentLocation, isEntryActive, MY_FEED_COVERS } from '../utils/navigationActive.js'
import { scrollToTop } from '../utils/scroller.js'
import { useTimelineStore } from '../store/timeline.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'
import { afterFirstTimeline } from '../services/boot.js'
import ConfirmDialog from './admin/ConfirmDialog.vue'
import { focusedElement, returnFocusTo } from '../utils/focus.js'
import eventBus, { BLUESKY_FEEDS_CHANGED, LISTS_CHANGED } from '../services/eventBus.js'
import { fetchSavedFeeds, routeFor as feedRoute, uriOf } from '../services/blueskyFeeds.js'
import { ownAvatarUrl } from '../services/avatar.js'

// the composer pulls the emoji picker and the attachment stack with it:
// its own chunk keeps all of that out of the entry bundle
const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'./Composer/Composer.vue'))
const PostEditForm = defineAsyncComponent(() => import(/* webpackChunkName: "composer-edit" */'./PostEditForm.vue'))
const ListsSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'./ListsSettings.vue'))
const ShortComposerDialog = defineAsyncComponent(() => import(/* webpackChunkName: "short-composer" */'./ShortComposerDialog.vue'))

/** how often to re-read the badges when the server cannot push */
const UNREAD_POLL_MS = 60 * 1000

/** the longest the badge poll backs off to while the server keeps failing */
const MAX_UNREAD_POLL_MS = 5 * 60 * 1000

/** how long to let the typing settle before searching */
const SEARCH_DEBOUNCE_MS = 300

/** where the Explore entry remembers, per browser, whether it was left open */
const EXPLORE_OPEN_KEY = 'social.navigation.exploreOpen'

export default {
	name: 'Navigation',
	components: {
		ListsSettings,
		SettingsDialog,
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
		PostEditForm,
		ConfirmDialog,
		ShortComposerDialog,
		IconImageMultiple,
		IconHome,
		IconAccountCircle,
		IconBell,
		IconCommentAccount,
		IconPlus,
		IconPound,
		IconTrendingUp,
		IconAccountGroup,
		IconFormatListBulleted,
		IconBluesky,
		IconBinoculars,
		IconCompass,
		IconInformationOutline,
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
			/** the Bluesky feeds and lists the reader keeps, pinned first */
			blueskyFeeds: [],
			/** whether the Explore entry is open; remembered per reader */
			exploreOpen: true,
			/** the window's height, the fallback until the rail can be measured */
			viewportHeight: 0,
			/** how many entries the rail has room for; `null` until measured */
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
			managingLists: false,
			composerReply: null,
			composerQuote: null,
			composerDraft: null,
			composerDraftContext: '',
			composerEdit: null,
			composerFiles: [],
			composerSaving: false,
			composerDiscardOpen: false,
			resolveComposerDiscard: null,
			composerHandlers: {},
			composerIntentGeneration: 0,
			composerOpener: null,
			stopComposerRouteGuard: null,
			onComposerBeforeUnload: null,
			/** the short composer opened from the New post dialog */
			showStoryComposer: false,
			/** files "Share to Social" in the Files app sent along, attached when the dialog opens */
			composerPaths: [],
			showErrors: false,
			pollTimer: null,
			/** how many ticks of the poll still pass before the next attempt */
			pollSkip: 0,
			/** how long the poll is waiting after failures, 0 while the server answers */
			pollWait: 0,
			/** when the tab was last hidden, so returning can catch up */
			hiddenSince: 0,
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
		 * @return {number} how many entries Explore holds, however many fit
		 */
		exploreTotal() {
			return this.followedTags.length + this.lists.length + this.blueskyFeeds.length + this.trendingToShow.length
		},

		/** @return {boolean} whether this server gives accounts a Bluesky identity */
		blueskyEnabled() {
			return this.settingsStore.getServerData?.bluesky?.enabled === true
		},

		/**
		 * How many entries there is room for.
		 *
		 * @return {number}
		 */
		exploreCap() {
			return this.measuredCap === null ? entriesThatFit(this.viewportHeight) : this.measuredCap
		},

		/**
		 * What Explore draws, as `chooseEntries` shares out the room.
		 *
		 * @return {Array<object>}
		 */
		exploreEntries() {
			return chooseEntries(this.followedTags, this.lists, this.trendingToShow, this.exploreCap, this.blueskyFeeds)
		},

		/**
		 * The same rows in `chooseEntries` order, grouped into runs of a kind,
		 * each with a caption.
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
		 * The trending tags this reader does not already follow.
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

		/** @return {number} unread activities and the people waiting for a decision */
		activitiesCount() {
			return this.notificationsStore.activitiesCount
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
		 * How many rows the account menu holds, for the stylesheet's stagger.
		 * About and Settings are written out in the template, so they are added.
		 */
		moreCount() {
			return this.menu.more.length + 2
		},

		/**
		 * The reader's Nextcloud avatar, which is never blank: the server
		 * generates initials when there is no picture.
		 *
		 * @return {string} where the picture is
		 */
		avatarUrl() {
			return ownAvatarUrl(64)
		},

		composerAvatarUrl() {
			return ownAvatarUrl(32)
		},

		/** @return {boolean} whether the signed-in account can post a short */
		storiesAvailable() {
			return Boolean(this.currentUser)
				&& !this.settingsStore.getServerData?.public
				&& this.settingsStore.getServerData?.sections?.stories !== false
				&& !this.currentAccount?.moved
		},

		/** the reader's picture as the CSS value the menu's icon is drawn with */
		faceImage() {
			return cssUrl(this.avatarUrl)
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
						title: t('social', 'My Feed'),
						to: { name: 'timeline' },
						// Local and Global are scopes of this page, so the entry
						// stays lit on them
						covers: MY_FEED_COVERS,
					},
					{
						key: 'social-photos',
						offered: this.offers('photos'),
						icon: IconImageMultiple,
						title: t('social', 'Photos'),
						to: { name: 'timeline', params: { type: 'photos' } },
					},
					{
						key: 'social-videos',
						offered: this.offers('videos'),
						icon: IconPlayBoxMultiple,
						title: t('social', 'Videos'),
						to: { name: 'timeline', params: { type: 'videos' } },
					},
					// the same videos, one at a time, so offered with Videos
					{
						key: 'social-shorts',
						offered: this.offers('videos'),
						icon: IconPlayCircleOutline,
						title: t('social', 'Shorts'),
						to: { name: 'shorts' },
					},
					{
						key: 'social-notifications',
						icon: IconBell,
						title: t('social', 'Activities'),
						to: { name: 'timeline', params: { type: 'notifications' } },
						counter: this.activitiesCount,
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

				// Under the reader's face at the foot of the sidebar. The profile
				// holds what they saved, their drafts and their statistics.
				more: [
					...(this.currentAccount?.acct
						? [{
								key: 'social-profile',
								icon: IconAccountCircle,
								title: t('social', 'My profile'),
								to: { name: 'profile', params: { account: this.currentAccount.acct } },
							}]
						: []),
				],

			}
		},
	},

	watch: {
		/**
		 * Wobbles the bell once when the count goes up, never when it goes down.
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
		 * Moves the mark to the row that is lit now.
		 *
		 * @param {import('vue-router').RouteLocationNormalized} to where the reader is going
		 * @param {import('vue-router').RouteLocationNormalized} from where they were
		 */
		$route(to, from) {
			this.$nextTick(() => this.placeIndicator())
			this.markChosen(to, from)
		},

		/**
		 * The Explore entry is only rendered once it has content, so observing
		 * and measuring it waits for that.
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
				this.$nextTick(() => {
					window.requestAnimationFrame(() => {
						if (!returnFocusTo(this.composerOpener)) {
							const replacement = document.querySelector('.new-post--one-line .composer-line__input')
								|| document.querySelector('.navigation__compose')
							const rect = replacement?.getBoundingClientRect()
							if (replacement instanceof HTMLElement && rect && rect.left >= 0 && rect.right <= window.innerWidth) {
								returnFocusTo(replacement)
							}
						}
					})
				})
			}
		},

		// follows the store, so navigating away from a search clears the box
		searchQuery: {
			immediate: true,
			handler(query) {
				this.localSearch = query
			},
		},
	},

	mounted() {
		this.composerHandlers = {
			'composer-open': (intent) => this.openComposer(intent),
			'composer-reply': (reply) => this.openComposer({ reply }),
			'composer-quote': (quote) => this.openComposer({ quote }),
			'composer-edit': (intent) => this.openComposer(intent),
			'shortcut:compose': () => this.openComposer(),
			'lists:manage': () => {
				this.managingLists = true
			},
		}
		for (const [event, handler] of Object.entries(this.composerHandlers)) {
			eventBus.on(event, handler)
		}
		this.onComposerBeforeUnload = (event) => {
			const editor = /** @type {{isDirty: boolean, editMediaUploading: boolean}|undefined} */ (this.$refs.postEditForm)
			if (this.showComposer && (this.composerSaving || this.timelineStore.composerSubmitting || editor?.isDirty || editor?.editMediaUploading)) {
				event.preventDefault()
				event.returnValue = ''
			}
		}
		window.addEventListener('beforeunload', this.onComposerBeforeUnload)
		if (this.$router?.beforeEach) {
			this.stopComposerRouteGuard = this.$router.beforeEach((to, from) => {
				return !this.timelineStore.composerSubmitting
					&& (!this.showComposer || to.path === from.path || this.requestComposerClose())
			})
		}

		this.$nextTick(() => this.placeIndicator())
		// none of these may compete with the first timeline request
		afterFirstTimeline(() => {
			this.fetchTrending()
			this.fetchLists()
			this.fetchBlueskyFeeds()
			this.fetchFollowedTags()
			this.refreshBadges()
		})
		// defers itself, as the composer may ask first
		this.instanceStore.load()
		// the settings page changes them; this sidebar holds its own copy
		this.onListsChanged = () => this.fetchLists()
		eventBus.on(LISTS_CHANGED, this.onListsChanged)
		eventBus.on(BLUESKY_FEEDS_CHANGED, this.fetchBlueskyFeeds)

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

		// with notify_push the server says when a badge changes; without it a
		// slow poll runs while the tab is visible
		if (!onTimelinePush(this.refreshBadges, [PUSH_DIRECT, PUSH_NOTIFICATION])) {
			this.pollTimer = setInterval(this.pollIfVisible, UNREAD_POLL_MS)
			document.addEventListener('visibilitychange', this.pollOnReturn)
		}
	},

	beforeUnmount() {
		for (const [event, handler] of Object.entries(this.composerHandlers)) {
			eventBus.off(event, handler)
		}
		window.removeEventListener('beforeunload', this.onComposerBeforeUnload)
		this.stopComposerRouteGuard?.()
		this.resolveComposerDiscard?.(false)

		eventBus.off(LISTS_CHANGED, this.onListsChanged)
		eventBus.off(BLUESKY_FEEDS_CHANGED, this.fetchBlueskyFeeds)
		window.removeEventListener('resize', this.measureViewport)
		this.railObserver?.disconnect()
		offTimelinePush(this.refreshBadges)
		if (this.pollTimer !== null) {
			clearInterval(this.pollTimer)
			document.removeEventListener('visibilitychange', this.pollOnReturn)
		}
		if (this.searchTimer !== null) {
			window.clearTimeout(this.searchTimer)
		}
	},

	methods: {
		async openComposer(intent = {}) {
			if (!this.showComposer && this.timelineStore.composerSubmitting) {
				return
			}
			const generation = ++this.composerIntentGeneration
			const opener = intent.opener || focusedElement()
			if (this.showComposer && !(await this.requestComposerClose())) {
				return
			}
			if (generation !== this.composerIntentGeneration) {
				return
			}
			this.timelineStore.setComposerDisplayStatus(true)
			await this.$nextTick()
			if (generation !== this.composerIntentGeneration) {
				return
			}
			this.composerOpener = opener
			this.composerReply = intent.reply || null
			this.composerQuote = intent.quote || null
			this.composerDraft = intent.draft || null
			this.composerDraftContext = typeof intent.context === 'string' ? intent.context : ''
			this.composerEdit = intent.edit || null
			this.composerFiles = intent.files || []
			this.composerPaths = intent.paths || []
			this.showComposer = true
		},

		onComposerEscape(event) {
			const composer = /** @type {{tribute?: {isActive: boolean}}|undefined} */ (this.$refs.modalComposer)
			const editor = /** @type {{isAutocompleteActive: boolean}|undefined} */ (this.$refs.postEditForm)
			if (event.defaultPrevented || this.composerDiscardOpen || composer?.tribute?.isActive || editor?.isAutocompleteActive) {
				return
			}
			event.preventDefault()
			event.stopPropagation()
			this.requestComposerClose()
		},

		onComposerVisibility(open) {
			if (!open) {
				this.requestComposerClose()
			}
		},

		async requestComposerClose() {
			const composer = /** @type {{loading: boolean, confirmLeave: (to: {path: string}, from: {path: string}) => boolean|Promise<boolean>, rememberDraft: () => void}|undefined} */ (this.$refs.modalComposer)
			if (this.composerSaving || composer?.loading) {
				return false
			}
			const editor = /** @type {{isDirty: boolean, editMediaUploading: boolean}|undefined} */ (this.$refs.postEditForm)
			if (editor?.isDirty || editor?.editMediaUploading) {
				if (this.composerDiscardOpen) {
					return false
				}
				this.composerDiscardOpen = true
				const discard = await new Promise((resolve) => {
					this.resolveComposerDiscard = resolve
				})
				if (!discard) {
					return false
				}
			} else if (composer) {
				if (!(await composer.confirmLeave({ path: 'close-modal' }, { path: 'composer' }))) {
					return false
				}
				composer.rememberDraft()
			}
			this.finishComposer()
			return true
		},

		answerComposerDiscard(discard) {
			this.composerDiscardOpen = false
			const resolve = this.resolveComposerDiscard
			if (!resolve) {
				return
			}
			if (discard) {
				this.resolveComposerDiscard = null
				resolve(true)
				return
			}
			// ConfirmDialog closes before emitting confirm in the same tick.
			this.$nextTick(() => {
				if (this.resolveComposerDiscard === resolve) {
					this.resolveComposerDiscard = null
					resolve(false)
				}
			})
		},

		finishComposer() {
			this.showComposer = false
			this.timelineStore.setComposerDisplayStatus(false)
		},

		async saveComposerEdit(edit) {
			if (this.composerSaving || !this.composerEdit) {
				return
			}
			this.composerSaving = true
			try {
				const response = await this.timelineStore.postEdit({ status: this.composerEdit, ...edit })
				if (response !== undefined) {
					this.finishComposer()
				}
			} finally {
				this.composerSaving = false
			}
		},

		/**
		 * The badge in words, as the bubble sits outside the entry's link.
		 *
		 * @param {number|undefined} count what the badge shows
		 * @return {string|undefined} what the entry says about it, or nothing
		 */
		unreadDescription(count) {
			return count > 0 ? n('social', '%n unread', '%n unread', count) : undefined
		},

		/**
		 * Reads the counts the sidebar badges show, in one request.
		 *
		 * @return {Promise<boolean>} whether the server answered
		 */
		refreshBadges() {
			return this.notificationsStore.fetchBadges()
		},

		async openStoryComposer() {
			if (await this.requestComposerClose()) {
				this.showStoryComposer = true
			}
		},

		/** The polling tick; a hidden tab does not ask. */
		pollIfVisible() {
			if (document.visibilityState === 'hidden') {
				this.hiddenSince = this.hiddenSince || Date.now()
				return
			}
			if (this.pollSkip > 0) {
				this.pollSkip -= 1
				return
			}

			this.pollBadges()
		},

		/** One round of the poll, and the wait before the next one. */
		async pollBadges() {
			if (await this.refreshBadges()) {
				this.pollWait = 0
				this.pollSkip = 0
				return
			}

			// doubles the wait on each failure, up to MAX_UNREAD_POLL_MS
			this.pollWait = Math.min(Math.max(this.pollWait, UNREAD_POLL_MS) * 2, MAX_UNREAD_POLL_MS)
			this.pollSkip = Math.round(this.pollWait / UNREAD_POLL_MS) - 1
		},

		/** Catches up once, on the way back to a tab that was left. */
		pollOnReturn() {
			if (document.visibilityState !== 'visible' || this.hiddenSince === 0) {
				return
			}

			const away = Date.now() - this.hiddenSince
			this.hiddenSince = 0
			if (away >= UNREAD_POLL_MS) {
				this.pollBadges()
			}
		},

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
				feed: t('social', 'Bluesky feeds'),
			}

			return captions[kind] ?? t('social', 'Explore')
		},

		/**
		 * Pops the icon of the row just chosen, when the page actually changes.
		 *
		 * @param {object} to where the reader is going
		 * @param {object} from where they were
		 */
		markChosen(to, from) {
			if (pageIdentity(to) === pageIdentity(from)) {
				return
			}

			// matched on the entry's target: the route is not applied yet, so
			// the active row is still the one being left
			const entry = this.menu.timelines.find((item) => pageIdentity(item.to) === pageIdentity(to))
			window.clearTimeout(this.chosenTimer)
			this.chosen = entry?.key ?? ''
			this.chosenTimer = window.setTimeout(() => {
				this.chosen = ''
			}, 420)
		},

		/**
		 * Puts the travelling mark on the active row, measured because rows
		 * differ in height. The first placement does not animate.
		 */
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
		 * Whether this instance offers a section; on unless the server says off.
		 *
		 * @param {string} section photos or videos
		 * @return {boolean} whether to draw its entry
		 */
		offers(section) {
			return this.settingsStore.getServerData?.sections?.['section_' + section] !== false
		},

		/**
		 * Drops the entries this instance does not offer, and the `offered`
		 * property from the rest.
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
		 * Opens the New post dialog with the `?attach=<path>` files from "Share
		 * to Social" in Files, and drops the query so a reload does not repeat it.
		 */
		openComposerFromQuery() {
			const raw = this.$route?.query?.attach
			const paths = (Array.isArray(raw) ? raw : [raw])
				.filter((path) => typeof path === 'string' && path !== '')
			if (paths.length === 0) {
				return
			}

			this.openComposer({ paths })

			const query = { ...this.$route.query }
			delete query.attach
			this.$router?.replace?.({ ...this.$route, query })
		},

		t,
		n,
		/** Reads the trending tags; a failure leaves them out silently. */
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

		/** Reads the hashtags the reader follows; a failure leaves them out silently. */
		async fetchFollowedTags() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/followed_tags'))
				this.followedTags = Array.isArray(data) ? data : []
			} catch {
				this.followedTags = []
			}
		},

		/**
		 * @param {object} entry one of the Explore children
		 * @return {string} something stable to key it by
		 */
		keyFor(entry) {
			if (entry.kind === 'feed') {
				return `feed-${entry.feed.uri}`
			}
			return entry.kind === 'list' ? `list-${entry.list.id}` : `${entry.kind}-${entry.tag.name}`
		},

		/**
		 * @param {object} entry one of the Explore children
		 * @return {string} what its row says
		 */
		nameFor(entry) {
			if (entry.kind === 'feed') {
				return entry.feed.name
			}
			return entry.kind === 'list' ? entry.list.title : `#${entry.tag.name}`
		},

		/**
		 * @param {object} entry one Explore entry
		 * @return {object} where pressing it goes
		 */
		routeFor(entry) {
			if (entry.kind === 'feed') {
				return feedRoute(entry.feed.uri)
			}
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
				return t(
					'social',
					'Everyone in the Nextcloud group {group} who has an Aloha Social account',
					{ group: entry.list.title },
				)
			}

			// tells a trending tag apart from a followed one, which looks alike
			if (entry.kind === 'trend') {
				return t('social', 'Being posted about on this server right now')
			}

			if (entry.kind === 'feed' && entry.feed.creator) {
				return t('social', 'A Bluesky feed by {creator}', { creator: entry.feed.creator })
			}

			return undefined
		},

		/**
		 * @param {object} entry one Explore entry
		 * @return {boolean} whether its timeline is the one being shown
		 */
		isExploreActive(entry) {
			if (entry.kind === 'feed') {
				return this.$route.name === 'bluesky-feed' && uriOf(this.$route.params) === entry.feed.uri
			}
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
				// blocked storage: the entry just opens again next time
			}
		},

		/**
		 * Re-measures whenever the rail changes shape, not only when the
		 * window does.
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

			const list = /** @type {{$el?: HTMLElement}|undefined} */ (this.$refs.exploreItem)?.$el?.parentElement
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
		 * How many entries the rail has room for: its height less everything
		 * above the Explore children, so the result does not depend on how
		 * many children are drawn.
		 */
		measureRail() {
			const item = /** @type {{$el?: HTMLElement}|undefined} */ (this.$refs.exploreItem)?.$el
			const list = item?.parentElement
			const children = item?.querySelector('.app-navigation-entry__children')
			const rows = children ? children.children : []

			if (!list || !list.clientHeight || rows.length === 0) {
				// nothing laid out yet, or collapsed, or jsdom: the window
				// figure stands in
				this.measuredCap = null
				return
			}

			// where the children begin, in the rail's content coordinates
			const listTop = list.getBoundingClientRect().top
			const childrenTop = children.getBoundingClientRect().top - listTop + list.scrollTop

			// row to row, which includes the margins `offsetHeight` leaves out
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
				// group lists first, each kind in the server's order
				this.lists = [
					...lists.filter((list) => list.nextcloud_group),
					...lists.filter((list) => !list.nextcloud_group),
				]
			} catch {
				this.lists = []
			}
		},

		async fetchBlueskyFeeds() {
			if (!this.blueskyEnabled) {
				return
			}
			try {
				this.blueskyFeeds = await fetchSavedFeeds()
			} catch {
				this.blueskyFeeds = []
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
		 * Follows the entry inside the app, leaving modified and middle clicks
		 * to the browser as router-link does.
		 *
		 * @param {object} to a route location
		 * @param {MouseEvent} [event] the click
		 */
		navigate(to, event) {
			// the browser follows an absolute URL to another Nextcloud page
			if (typeof to === 'string') {
				return
			}

			if (event && (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button > 0)) {
				return
			}

			event?.preventDefault()
			// a second press on the page already open goes back to its top
			if (event && isCurrentLocation(this.$router, this.$route, to)) {
				scrollToTop()
				return
			}
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

		/** Searching costs a request, so it waits for the typing to stop. */
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
		 * Enter searches at once and also resolves unknown addresses on other
		 * servers, which typing alone never does.
		 */
		onSearchSubmit() {
			if (this.searchTimer !== null) {
				window.clearTimeout(this.searchTimer)
				this.searchTimer = null
			}
			this.$emit('search', this.localSearch, true)
		},

		/**
		 * @param {object} item one of the menu entries
		 * @return {boolean} whether it is the current page
		 */
		isActive(item) {
			return isEntryActive(this.$route, item)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/layout.scss' as layout;

/**
 * The mark that travels between the rows, positioned by `placeIndicator`.
 * `--settled` lets it move; the first placement has it off so it does not
 * slide in from the top.
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

/* one small pop on the icon of the row just chosen */
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

/* smaller than Nextcloud's default, as these counts need no answer */
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

/* The reader's portrait in place of `NcAppNavigationSettings`' hard-coded
   cog, which has no slot: the icon box carries it as a background. */
.navigation__more :deep(.button-vue__icon) {
	background-image: var(--social-face);
	background-position: center;
	background-size: cover;
	border-radius: 50%;
	// the cog and its non-transparent background must not show through
	overflow: hidden;

	svg {
		visibility: hidden;
	}
}

.navigation__more :deep(.button-vue__text) {
	// the wrapper has no gap of its own
	margin-inline-start: 8px;
	font-weight: 600;
}

/* Up-and-down chevrons mark the row as a menu. The component has no slot
   beside its name, so they are a mask filled with the text colour. */
.navigation__more :deep(.button-vue__wrapper)::after {
	content: '';
	flex: 0 0 auto;
	inline-size: 20px;
	block-size: 20px;
	margin-inline-start: auto;
	background-color: var(--color-text-maxcontrast);
	mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 18.17 8.83 15l-1.42 1.41L12 21l4.59-4.59L15.17 15M12 5.83 15.17 9l1.42-1.41L12 3 7.41 7.59 8.83 9z'/%3E%3C/svg%3E") center / contain no-repeat;
}

.navigation__more:has(button[aria-expanded="true"]) :deep(.button-vue__wrapper)::after {
	background-color: var(--color-main-text);
}

@media (forced-colors: active) {
	.navigation__more :deep(.button-vue__wrapper)::after {
		forced-color-adjust: none;
		background-color: ButtonText;
	}
}

.navigation__subname {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

/*
 * Open, the account menu is drawn as a panel over the sidebar so it does not
 * read as more sidebar rows. The panel is addressed as the container's child
 * with an `id` (the button's `aria-controls` target), because the component's
 * class names are content-hashed.
 */
.navigation__more {
	/* the shadow falls on the entries above, so the panel has to be over them */
	position: relative;
	z-index: 2;
	transition: background-color .2s ease, box-shadow .2s ease;
}

/*
 * The open panel is a lightly frosted glass pane with a lit rim and a shadow.
 * It is a pseudo-element so the account row does not shift when it opens.
 * The rim light is fainter in the dark theme.
 */
.navigation__more {
	--social-glass-light: #fff;
}

@media (prefers-color-scheme: dark) {
	body:not([data-themes*='light']) .navigation__more {
		--social-glass-light: rgba(255, 255, 255, .34);
	}
}

[data-themes*='dark'] .navigation__more {
	--social-glass-light: rgba(255, 255, 255, .34);
}

@keyframes social-glass-pop {
	from {
		opacity: 0;
		transform: scale(.9) translateY(10px);
	}
}

.navigation__more:has(button[aria-expanded="true"])::before {
	content: '';
	position: absolute;
	inset: 0 6px 6px;
	z-index: -1;
	border: 1px solid color-mix(in srgb, var(--social-glass-light) 70%, transparent);
	border-radius: 22px;
	background-color: color-mix(in srgb, var(--color-main-background) 28%, transparent);
	backdrop-filter: blur(10px) saturate(1.9) contrast(1.05);
	-webkit-backdrop-filter: blur(10px) saturate(1.9) contrast(1.05);
	box-shadow:
		inset 0 1px 0 color-mix(in srgb, var(--social-glass-light) 80%, transparent),
		inset 0 -1px 0 color-mix(in srgb, var(--color-main-text) 10%, transparent),
		inset 0 0 24px color-mix(in srgb, var(--social-glass-light) 28%, transparent),
		0 12px 30px -10px rgba(0, 20, 50, .45);
	transform-origin: 50% 100%;
	animation: social-glass-pop .42s cubic-bezier(.3, 1.5, .5, 1) backwards;
	pointer-events: none;
}

.navigation__more > :deep(div[id]) {
	/* above the library's 300px cap so no entry is cut off, but still within a
	   short window */
	max-height: min(60vh, 420px);
}

/* the account row heads the open panel */
.navigation__more:has(button[aria-expanded="true"]) :deep(.button-vue__text) {
	color: var(--color-main-text);
}

/*
 * The menu rows pop up one after another from the bottom row, the way the
 * panel grows. A keyframe animation, not a transition: the panel is `v-show`n,
 * so its rows have no previous value to transition from. Keyed on
 * `aria-expanded`, the component's only open state; the delay comes from
 * `--entry-index` and `--entry-total` set in the template. Without `:has()`
 * the menu simply opens without animating.
 */
@keyframes social-menu-pop {
	from {
		opacity: 0;
		transform: scale(.82);
	}
}

.navigation__more:has(button[aria-expanded="true"]) :deep(.app-navigation-entry) {
	/* out of its own bottom edge, towards the row above */
	transform-origin: 50% 100%;
	animation: social-menu-pop .38s cubic-bezier(.34, 1.56, .64, 1) both;
	/* counted from the bottom: the last row waits for nothing */
	animation-delay: calc((var(--entry-total, 1) - 1 - var(--entry-index, 0)) * 32ms);
}

/* reduced motion: the whole menu at once, with no stagger */
@media (prefers-reduced-motion: reduce) {
	.navigation__more:has(button[aria-expanded="true"]) :deep(.app-navigation-entry),
	.navigation__more:has(button[aria-expanded="true"])::before {
		animation: none;
	}
}

/* in a dialog the composer drops its own card frame and stickiness */
.modal-composer :deep(.new-post) {
	margin: 0;
	padding: 0;
	max-width: none;
	position: static;
	border: 0;
	border-radius: 0;
	box-shadow: none;
}

/* wide enough for the row of controls and the Post button on one line;
   NcModal's normal size is 600px */
.composer-modal :deep(.modal-wrapper--normal > .modal-container) {
	width: 640px;
}

/* the dialog supplies the inset the composer's card padding gave; less at the
   top, where the heading sits */
.modal-composer {
	padding: 12px 18px 18px;

	&__header {
		display: flex;
		align-items: center;
		justify-content: flex-start;
		gap: 16px;
		margin-bottom: 12px;
	}

	&__title {
		display: flex;
		align-items: center;
		gap: 10px;
		min-width: 0;
		margin: 0;
		font-size: 20px;
		font-weight: 700;
	}

	&__story {
		flex: 0 0 auto;
		margin-inline-start: 0;
	}
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

	&__heading {
		margin: 0 0 calc(var(--default-grid-baseline) * 2);
		font-size: 20px;
		font-weight: 700;
	}

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

.navigation__moved {
	margin: 0 8px 8px;
	padding: 8px 12px;
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text, var(--color-main-text));
	font-size: var(--font-size-small, 13px);
}

/* The New post button: a pill whose rings and plus disc grow on hover.
 * The class is doubled to beat `.button-vue[data-v-…]`, which has the same
 * specificity as one scoped class and would otherwise win on source order. */
.navigation__compose.navigation__compose {
	position: relative;
	margin: 2px 4px 8px;
	/* `wide` is `width: 100%`, which with the margin would overhang the sidebar */
	width: calc(100% - 8px);
	font-weight: 600;
	/* tall enough that the 28px plus disc sits concentrically in the pill's
	   end cap, which the server's 34px clickable area is not */
	min-height: 44px;
	border-radius: 999px;
	/* NcButton's heavier bottom border would make the rings uneven */
	border: 0;
	padding-inline: 8px;
	/* NcButton's `overflow: hidden` would clip the rings drawn by ::after */
	overflow: visible;
	transition: transform .32s cubic-bezier(.22, 1.2, .48, 1);
}

/*
 * The rings, painted once on their own layer. Only `opacity` and `transform`
 * animate, so the compositor runs the hover while the main thread is busy
 * loading the page; animating `box-shadow` would repaint every frame.
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
	/* promoted up front, not by the main thread at hover time */
	will-change: opacity, transform;
}

/* on focus the server's `!important` focus ring wins over the rings */
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

/* the plus sits in a disc that grows with the rings; on the glyph, as the icon
   box is nearly as tall as the button */
.navigation__compose :deep(.plus-icon) {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	/* the label's colour, so it suits any primary colour */
	background: color-mix(in srgb, var(--color-primary-element-text) 22%, transparent);
	transition:
		background-color .2s ease,
		transform .32s cubic-bezier(.22, 1.2, .48, 1);
}

/* the wrapper has no gap of its own */
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
}
</style>
