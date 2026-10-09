<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="settings">
		<header class="settings__header">
			<div class="settings__header-text">
				<h2 class="settings__heading">
					{{ t('social', 'Settings') }}
				</h2>
				<p class="settings__lede">
					{{ t('social', 'Changes are saved as you make them.') }}
				</p>
			</div>
			<NcTextField
				v-model="query"
				class="settings__search"
				type="search"
				:label="t('social', 'Find a setting')"
				trailingButtonIcon="close"
				:showTrailingButton="query !== ''"
				@trailingButtonClick="query = ''">
				<template #icon>
					<IconSearch :size="20" />
				</template>
			</NcTextField>
		</header>

		<div class="settings__body">
			<!-- The groups, with the sections of the open one under it. On a
			     narrow screen the groups are a row of tabs above the page and
			     the sections are left to the page itself. -->
			<nav class="settings__nav" :aria-label="t('social', 'Settings groups')">
				<ul class="settings__groups">
					<li v-for="group in groups" :key="group.id" class="settings__group">
						<a
							:href="`#${group.sections[0].id}`"
							class="settings__group-link"
							:class="{ 'settings__group-link--current': !searching && group.id === activeGroup.id }"
							:aria-current="!searching && group.id === activeGroup.id ? 'page' : undefined"
							@click="openGroup(group.id, $event)">
							<span class="settings__group-icon">
								<component :is="group.icon" :size="20" />
							</span>
							<span class="settings__group-text">
								<span class="settings__group-title">{{ group.title }}</span>
								<span class="settings__group-description">{{ group.description }}</span>
							</span>
						</a>
						<ul
							v-if="!searching && group.id === activeGroup.id && group.sections.length > 1"
							class="settings__toc-list"
							:aria-label="t('social', 'On this page')">
							<li v-for="section in group.sections" :key="section.id">
								<a
									:href="`#${section.id}`"
									class="settings__toc-link"
									:class="{
										'settings__toc-link--current': current === section.id,
										'settings__toc-link--danger': section.danger,
									}"
									:aria-current="current === section.id ? 'true' : undefined"
									@click="jumpTo(section.id, $event)">
									{{ section.title }}
								</a>
							</li>
						</ul>
					</li>
				</ul>
			</nav>

			<div class="settings__sections">
				<p v-if="searching" class="settings__results" role="status">
					{{ matches.length === 0
						? t('social', 'No setting matches “{query}”.', { query: query.trim() })
						: n('social', '%n setting matches “{query}”', '%n settings match “{query}”', matches.length, { query: query.trim() }) }}
				</p>
				<header v-else class="settings__group-head">
					<span class="settings__group-head-icon">
						<component :is="activeGroup.icon" :size="24" />
					</span>
					<div>
						<h3 class="settings__group-heading">
							{{ activeGroup.title }}
						</h3>
						<p class="settings__group-lede">
							{{ activeGroup.description }}
						</p>
					</div>
				</header>

				<!-- Each section keeps the id other pages link to: the follow
				     requests page sends the reader to #account, a profile to
				     #featured-tags. The link opens the group the section is in. -->
				<section
					v-for="(section, index) in shownSections"
					:id="section.id"
					:key="section.id"
					class="settings__section"
					:style="{ '--entry-index': index }"
					:class="{ 'settings__section--danger': section.danger }">
					<header class="settings__section-head">
						<span class="settings__section-icon">
							<component :is="section.icon" :size="20" />
						</span>
						<div class="settings__section-title">
							<span v-if="searching" class="settings__section-group">{{ groupOf(section.id).title }}</span>
							<h4 class="settings__section-heading">
								{{ section.title }}
							</h4>
							<p class="settings__section-lede">
								{{ section.lede }}
							</p>
						</div>
					</header>

					<div class="settings__section-body">
						<component :is="section.component" />
					</div>
				</section>
			</div>
		</div>
	</div>
</template>

<script>
import ArchivedPosts from '../components/ArchivedPosts.vue'
import AuthorizedApps from '../components/AuthorizedApps.vue'
import DeleteAccount from '../components/DeleteAccount.vue'
import HeldPosts from '../components/HeldPosts.vue'
import IntroductionSettings from '../components/IntroductionSettings.vue'
import PortfolioSettings from '../components/PortfolioSettings.vue'
import ScheduledPosts from '../components/ScheduledPosts.vue'
import ShortcutList from '../components/ShortcutList.vue'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconAccount from 'vue-material-design-icons/AccountCircleOutline.vue'
import IconAccountCheck from 'vue-material-design-icons/AccountCheckOutline.vue'
import IconApps from 'vue-material-design-icons/KeyOutline.vue'
import IconArchive from 'vue-material-design-icons/ArchiveOutline.vue'
import IconBluesky from 'vue-material-design-icons/ButterflyOutline.vue'
import IconBellRing from 'vue-material-design-icons/BellRingOutline.vue'
import IconDelete from 'vue-material-design-icons/DeleteOutline.vue'
import IconDirectMessages from 'vue-material-design-icons/MessageLockOutline.vue'
import IconHelp from 'vue-material-design-icons/HelpCircleOutline.vue'
import IconInterests from 'vue-material-design-icons/TagHeartOutline.vue'
import IconIntroduction from 'vue-material-design-icons/HandWaveOutline.vue'
import IconInvite from 'vue-material-design-icons/EmailPlusOutline.vue'
import IconKeyboard from 'vue-material-design-icons/KeyboardOutline.vue'
import IconLists from 'vue-material-design-icons/FormatListBulleted.vue'
import IconNotifications from 'vue-material-design-icons/BellOutline.vue'
import IconPortfolio from 'vue-material-design-icons/ImageMultipleOutline.vue'
import IconPosts from 'vue-material-design-icons/NoteTextOutline.vue'
import IconReading from 'vue-material-design-icons/BookOpenPageVariantOutline.vue'
import IconRecap from 'vue-material-design-icons/CalendarMonthOutline.vue'
import IconReview from 'vue-material-design-icons/ShieldAlertOutline.vue'
import IconScheduled from 'vue-material-design-icons/ClockOutline.vue'
import IconSearch from 'vue-material-design-icons/Magnify.vue'
import IconSecurity from 'vue-material-design-icons/ShieldKeyOutline.vue'
import IconSenses from 'vue-material-design-icons/VolumeHigh.vue'
import IconSensitive from 'vue-material-design-icons/EyeOffOutline.vue'
import IconCounts from 'vue-material-design-icons/HeartOffOutline.vue'
import IconFilesComments from 'vue-material-design-icons/CommentTextMultipleOutline.vue'
import IconStorage from 'vue-material-design-icons/Harddisk.vue'
import IconTags from 'vue-material-design-icons/Pound.vue'
import { defineAsyncComponent } from 'vue'
import { n, t } from '@nextcloud/l10n'
import { useServerData } from '../composables/useServerData.js'
import { currentSection, scrollToSection, watchSections } from '../services/sectionRail.js'

// The forms, switches and lists below are only ever drawn on this page, so
// they travel in a chunk of their own rather than in the entry every reader
// loads.
const AccountSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/AccountSettings.vue'))
const DirectMessagesSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/DirectMessagesSettings.vue'))
const BlueskySettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/BlueskySettings.vue'))
const RecapSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/RecapSettings.vue'))
const ListsSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/ListsSettings.vue'))
const BlueskyFeedsSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/BlueskyFeedsSettings.vue'))
const FeaturedTagsSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/FeaturedTagsSettings.vue'))
const InterestsSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/InterestsSettings.vue'))
const SensesSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/SensesSettings.vue'))
const SensitiveMediaSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/SensitiveMediaSettings.vue'))
const CountsSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/CountsSettings.vue'))
const FilesCommentsSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/FilesCommentsSettings.vue'))
const NotificationDeliverySettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/NotificationDeliverySettings.vue'))
const NotificationPolicySettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/NotificationPolicySettings.vue'))
const ExternalStorage = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/ExternalStorage.vue'))
const InviteSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/InviteSettings.vue'))

/**
 * Lower case and without accents, so "benachrichtigung" finds
 * "Benachrichtigungen" and "e" finds "é".
 *
 * @param {string} text what to compare
 * @return {string}
 */
function folded(text) {
	return String(text).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
}

/**
 * Settings: what this app holds about how the reader uses it.
 *
 * Seventeen sections in one scroll were more than anybody could find their
 * way through, and they were of different kinds: switches about privacy next
 * to lists of scheduled posts next to a page of keyboard shortcuts. They are
 * in six groups now and one group is shown at a time, with a search across
 * all of them for the reader who knows what they want but not where it is.
 *
 * The sections are described rather than written out one by one: the rail,
 * the search and the page all need the same title and id, and the copies
 * drifted apart the first time one was renamed in only one of the places.
 */
export default {
	name: 'Settings',

	components: {
		AccountSettings,
		ArchivedPosts,
		AuthorizedApps,
		BlueskySettings,
		DeleteAccount,
		DirectMessagesSettings,
		ExternalStorage,
		FeaturedTagsSettings,
		HeldPosts,
		InterestsSettings,
		IntroductionSettings,
		InviteSettings,
		IconAccount,
		IconAccountCheck,
		IconApps,
		IconArchive,
		IconBluesky,
		IconBellRing,
		IconDelete,
		IconDirectMessages,
		IconHelp,
		IconInterests,
		IconIntroduction,
		IconInvite,
		IconKeyboard,
		IconLists,
		IconNotifications,
		IconPortfolio,
		IconPosts,
		IconReading,
		IconRecap,
		IconReview,
		IconScheduled,
		IconSearch,
		IconSecurity,
		IconCounts,
		IconFilesComments,
		IconSenses,
		IconSensitive,
		IconStorage,
		IconTags,
		ListsSettings,
		BlueskyFeedsSettings,
		NcTextField,
		NotificationDeliverySettings,
		NotificationPolicySettings,
		PortfolioSettings,
		RecapSettings,
		CountsSettings,
		FilesCommentsSettings,
		SensesSettings,
		SensitiveMediaSettings,
		ScheduledPosts,
		ShortcutList,
	},

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	data() {
		return {
			/** the group the reader opened, '' for the first */
			openedGroup: '',
			/** what the reader typed into the search */
			query: '',
			/** the section already scrolled to, so it is not chased twice */
			scrolledTo: '',
			/** the id of the section the reader is looking at, for the rail */
			current: '',
			observer: null,
		}
	},

	computed: {
		/**
		 * Every section, in the order it is read, each naming its group.
		 *
		 * @return {object[]} one entry per section
		 */
		sections() {
			const external = Boolean(this.serverData?.externalMedia)

			return [
				{
					id: 'account',
					group: 'profile',
					icon: 'IconAccountCheck',
					component: 'AccountSettings',
					title: t('social', 'Who can find and follow you'),
					lede: t('social', 'How others find you, and who sees what you post.'),
				},
				{
					id: 'direct-messages',
					group: 'profile',
					icon: 'IconDirectMessages',
					component: 'DirectMessagesSettings',
					title: t('social', 'Who may send you direct messages'),
					lede: t('social', 'A message from anybody else does not notify you, and waits among your requests.'),
				},
				{
					id: 'featured-tags',
					group: 'profile',
					icon: 'IconTags',
					component: 'FeaturedTagsSettings',
					title: t('social', 'Featured hashtags'),
					lede: t('social', 'Hashtags shown under your bio. Anyone can click one to read what you posted with it.'),
				},
				{
					id: 'portfolio',
					group: 'profile',
					icon: 'IconPortfolio',
					component: 'PortfolioSettings',
					title: t('social', 'Portfolio'),
					lede: t('social', 'A public page with the photos you choose and its own address, for a CV or a website. Only public photos can be on it.'),
				},
				// only where the administrator has the feature on: without it
				// there is no feed for the list to shape
				...(this.serverData?.interests?.enabled === true
					? [{
							id: 'interests',
							group: 'reading',
							icon: 'IconInterests',
							component: 'InterestsSettings',
							title: t('social', 'For you'),
							lede: t('social', 'The hashtags your For you feed is made of, learned from what you read. Only you see this.'),
						}]
					: []),
				{
					id: 'lists',
					group: 'reading',
					icon: 'IconLists',
					component: 'ListsSettings',
					title: t('social', 'Lists'),
					lede: t('social', 'A list is a few of the people you follow, read as a timeline of its own. Your lists are in the sidebar.'),
				},
				...(this.serverData?.bluesky?.enabled === true
					? [{
							id: 'bluesky-feeds',
							group: 'reading',
							icon: 'IconBluesky',
							component: 'BlueskyFeedsSettings',
							title: t('social', 'Bluesky feeds'),
							lede: t('social', 'Custom feeds and lists from Bluesky, read here as timelines of their own. A Bluesky app signed in to this account keeps the same ones.'),
						}]
					: []),
				{
					id: 'sensitive',
					group: 'reading',
					icon: 'IconSensitive',
					component: 'SensitiveMediaSettings',
					title: t('social', 'Sensitive media'),
					lede: t('social', 'How pictures and videos their author marked sensitive are shown to you.'),
				},
				{
					id: 'counts',
					group: 'reading',
					icon: 'IconCounts',
					component: 'CountsSettings',
					title: t('social', 'Likes and followers'),
					lede: t('social', 'Whether you see how many likes, boosts, replies and followers posts and people have. Off unless you turn it on.'),
				},
				{
					id: 'recap',
					group: 'reading',
					icon: 'IconRecap',
					component: 'RecapSettings',
					title: t('social', 'Looking back'),
					lede: t('social', 'A short note about your week at the top of your feed, next to your posts from this day in earlier years.'),
				},
				{
					id: 'senses',
					group: 'reading',
					icon: 'IconSenses',
					component: 'SensesSettings',
					title: t('social', 'Sound and touch'),
					lede: t('social', 'Small confirmations you hear or feel. They are kept on this device only, so a laptop at work can stay quiet while your phone taps back.'),
				},
				{
					id: 'notifications',
					group: 'notifications',
					icon: 'IconBellRing',
					component: 'NotificationDeliverySettings',
					title: t('social', 'When to tell you'),
					lede: t('social', 'Right away or in a digest, and the hours to stay quiet.'),
				},
				{
					id: 'notification-policy',
					group: 'notifications',
					icon: 'IconAccountCheck',
					component: 'NotificationPolicySettings',
					title: t('social', 'Who may reach you'),
					lede: t('social', 'Whose posts, likes and follows reach you right away, and whose wait for you to look.'),
				},
				{
					id: 'scheduled',
					group: 'posts',
					icon: 'IconScheduled',
					component: 'ScheduledPosts',
					title: t('social', 'Scheduled posts'),
					lede: t('social', 'What you have written to be published later. Move one to another time or cancel it here.'),
				},
				{
					id: 'review',
					group: 'posts',
					icon: 'IconReview',
					component: 'HeldPosts',
					title: t('social', 'Waiting for review'),
					lede: t('social', 'Posts a moderator looks at before they go out, such as the first post of a new account. You can take one back.'),
				},
				{
					id: 'archive',
					group: 'posts',
					icon: 'IconArchive',
					component: 'ArchivedPosts',
					title: t('social', 'Archived posts'),
					lede: t('social', 'Posts you put away. They are off your profile and your timelines here, but not deleted on other servers. Put one back any time.'),
				},
				// Files is not open to a self-registered external user
				...(external
					? []
					: [{
							id: 'files-comments',
							group: 'posts',
							icon: 'IconFilesComments',
							component: 'FilesCommentsSettings',
							title: t('social', 'Replies in Files'),
							lede: t('social', 'A picture you post from Files keeps a link to its file. Replies to the post can be read in the file\'s Comments tab, next to the picture they are about.'),
						}]),
				{
					id: 'apps',
					group: 'security',
					icon: 'IconApps',
					component: 'AuthorizedApps',
					title: t('social', 'Authorized apps'),
					lede: t('social', 'Apps signed in to your account, such as a phone client. Sign out the ones you no longer use, or all of them after losing a phone.'),
				},
				// where this server gives every account a Bluesky identity
				...(this.serverData?.bluesky?.enabled === true
					? [{
							id: 'bluesky',
							group: 'security',
							icon: 'IconBluesky',
							component: 'BlueskySettings',
							title: t('social', 'Bluesky'),
							lede: t('social', 'This account is also reachable on Bluesky, because this server offers one.'),
						}]
					: []),
				// where the administrator lets people invite others to register
				...(this.serverData?.externalInvites === true
					? [{
							id: 'invites',
							group: 'security',
							icon: 'IconInvite',
							component: 'InviteSettings',
							title: t('social', 'Invite people'),
							lede: t('social', 'A link somebody without an account can use to register on this server. Each link works once and for a week.'),
						}]
					: []),
				// a self-registered external user's media quota
				...(external
					? [{
							id: 'storage',
							group: 'security',
							icon: 'IconStorage',
							component: 'ExternalStorage',
							title: t('social', 'Your storage'),
							lede: t('social', 'The pictures and videos you uploaded here, against what this server lets each account keep. Media from other servers does not count.'),
						}]
					: []),
				{
					id: 'delete',
					group: 'security',
					icon: 'IconDelete',
					component: 'DeleteAccount',
					title: t('social', 'Delete your Aloha Social account'),
					lede: external
						? t('social', 'Your account on this server and everything you posted. This cannot be undone.')
						: t('social', 'Your fediverse account and everything you posted. Your Nextcloud account stays as it is. This cannot be undone.'),

					danger: true,
				},
				{
					id: 'introduction',
					group: 'help',
					icon: 'IconIntroduction',
					component: 'IntroductionSettings',
					title: t('social', 'Introduction'),
					lede: t('social', 'The four steps shown when your account was new: your address, people to follow, and the follows you had on another server.'),
				},
				{
					id: 'shortcuts',
					group: 'help',
					icon: 'IconKeyboard',
					component: 'ShortcutList',
					title: t('social', 'Keyboard shortcuts'),
					lede: t('social', 'The keys this app listens for while you are reading.'),
				},
			]
		},

		/**
		 * The groups, in the order they are listed, each holding its sections.
		 * A group with nothing in it on this server is left out.
		 *
		 * @return {object[]}
		 */
		groups() {
			return [
				{
					id: 'profile',
					icon: 'IconAccount',
					title: t('social', 'Profile and privacy'),
					description: t('social', 'Who can find you, follow you and see your posts.'),
				},
				{
					id: 'reading',
					icon: 'IconReading',
					title: t('social', 'Reading'),
					description: t('social', 'What your feeds show, and how reading feels.'),
				},
				{
					id: 'notifications',
					icon: 'IconNotifications',
					title: t('social', 'Notifications'),
					description: t('social', 'When the app may interrupt you, and who may reach you.'),
				},
				{
					id: 'posts',
					icon: 'IconPosts',
					title: t('social', 'Your posts'),
					description: t('social', 'Posts waiting to go out, waiting for review, or put away.'),
				},
				{
					id: 'security',
					icon: 'IconSecurity',
					title: t('social', 'Apps and account'),
					description: t('social', 'Apps signed in as you, invitations, and deleting the account.'),
				},
				{
					id: 'help',
					icon: 'IconHelp',
					title: t('social', 'Help'),
					description: t('social', 'The introduction again, and the keyboard shortcuts.'),
				},
			]
				.map((group) => ({ ...group, sections: this.sections.filter((section) => section.group === group.id) }))
				.filter((group) => group.sections.length > 0)
		},

		/** @return {object} the group on screen */
		activeGroup() {
			return this.groups.find((group) => group.id === this.openedGroup) ?? this.groups[0]
		},

		/** @return {boolean} whether the reader is searching rather than browsing */
		searching() {
			return this.query.trim() !== ''
		},

		/**
		 * The sections whose name, description or group says every word
		 * typed, in page order.
		 *
		 * @return {object[]}
		 */
		matches() {
			const words = folded(this.query).split(/\s+/).filter(Boolean)
			return this.sections.filter((section) => {
				const text = folded(`${section.title} ${section.lede} ${this.groupOf(section.id).title}`)
				return words.every((word) => text.includes(word))
			})
		},

		/** @return {object[]} the sections drawn: the search's, or the open group's */
		shownSections() {
			return this.searching ? this.matches : this.activeGroup.sections
		},
	},

	watch: {
		// a link to another section while the page is already open
		'$route.hash': function() {
			this.scrolledTo = ''
			this.scrollToSection()
		},
	},

	mounted() {
		this.scrollToSection()
		this.watchSections()
	},

	updated() {
		// the sections arrive after their chunk does, so a link to one of them
		// lands on a page that does not have it yet
		this.scrollToSection()
		this.watchSections()
	},

	beforeUnmount() {
		this.observer?.disconnect()
	},

	methods: {
		n,
		t,

		/**
		 * @param {string} id a section
		 * @return {object} the group it is in, or the first for one it does not know
		 */
		groupOf(id) {
			return this.groups.find((group) => group.sections.some((section) => section.id === id)) ?? this.groups[0]
		},

		/**
		 * Puts the section named in the address in view, opening its group
		 * first. Vue Router leaves the hash alone on a page that is already
		 * mounted, and the section a link points at may not have been drawn
		 * when the page first was.
		 */
		scrollToSection() {
			const id = (this.$route?.hash ?? '').replace(/^#/, '')
			if (id === '' || this.scrolledTo === id) {
				return
			}

			// Migration is a page of its own now, and `#migration` is what has
			// been linked to and bookmarked for as long as it was a section
			// here. Sent on rather than ignored: landing on a settings page
			// with nothing highlighted is the one answer that says nothing.
			if (id === 'migration') {
				this.scrolledTo = id
				this.$router.replace({ name: 'migration' })

				return
			}

			const group = this.groupOf(id)
			if (group.sections.some((section) => section.id === id) && (this.openedGroup !== group.id || this.searching)) {
				this.query = ''
				this.openedGroup = group.id
				// drawn on the next update, which calls this again
				return
			}
			const section = document.getElementById(id)
			if (!section || typeof section.scrollIntoView !== 'function') {
				return
			}
			this.scrolledTo = id
			this.current = id
			// the first section of a group is where the group starts: the
			// group's own heading stays in view above it
			const target = id === group.sections[0].id ? this.$el : section
			target.scrollIntoView?.({ block: 'start' })
			// the sections above it can still be arriving with their chunk, and
			// push it down the page after it was scrolled to; once is enough
			const landed = section.getBoundingClientRect().top
			window.setTimeout(() => {
				if (this.scrolledTo === id && Math.abs(section.getBoundingClientRect().top - landed) > 24) {
					target.scrollIntoView?.({ block: 'start' })
				}
			}, 700)
		},

		/**
		 * Opens a group without leaving the page. The href is a real one, so
		 * the link can be copied and opens the same group in a new tab.
		 *
		 * @param {string} id the group
		 * @param {MouseEvent} event the click
		 */
		openGroup(id, event) {
			const group = this.groups.find((one) => one.id === id)
			if (!group) {
				return
			}
			event?.preventDefault()
			this.query = ''
			this.openedGroup = id
			this.current = group.sections[0].id
			this.scrolledTo = group.sections[0].id
			this.$router?.replace({ hash: `#${group.sections[0].id}` }).catch(() => {})
			this.$el.scrollIntoView?.({ block: 'start' })
		},

		/**
		 * Follows a section link without leaving the page.
		 *
		 * @param {string} id the section to go to
		 * @param {MouseEvent} event the click
		 */
		jumpTo(id, event) {
			if (!scrollToSection(id)) {
				return
			}
			event.preventDefault()
			this.current = id
			this.scrolledTo = id
			this.$router?.replace({ hash: `#${id}` }).catch(() => {})
		},

		/**
		 * Watches for a section coming into or out of view.
		 *
		 * Rebuilt on `updated` because the sections that arrive with their
		 * chunk, or with another group, were not there to be watched before.
		 */
		watchSections() {
			this.observer?.disconnect()
			this.observer = watchSections(this.shownSections.map((section) => section.id), () => this.markCurrent())
			this.markCurrent()
		},

		/**
		 * Marks the section the reader is looking at. Before any heading has
		 * passed the top, that is the first one: keeping whatever was marked
		 * before left a section far down the page marked at the very top.
		 */
		markCurrent() {
			const ids = this.shownSections.map((section) => section.id)
			this.current = currentSection(ids, ids[0] ?? '')
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/layout.scss' as layout;

/**
 * The sections arrive one after another rather than all at once, when the
 * page opens and when another group is opened. The delay is capped, and the
 * whole thing is off for a reader who asked for less movement.
 */
@keyframes section-arrive {
	from { opacity: 0; transform: translateY(10px); }
}

@media (prefers-reduced-motion: no-preference) {
	.settings__section,
	.settings__group-head {
		animation: section-arrive .26s ease-out both;
		animation-delay: calc(min(var(--entry-index, 0), 7) * 35ms);
	}
}

$gutter: calc(var(--default-grid-baseline) * 4);

.settings {
	padding: $gutter;
	// Nextcloud puts its navigation toggle at the top left corner of the app
	// content, over whatever starts there; the heading used to sit under it
	padding-block-start: calc(var(--default-grid-baseline) * 12);
	max-width: 1120px;
	margin-inline: auto;

	&__header {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-end;
		justify-content: space-between;
		gap: calc(var(--default-grid-baseline) * 4);
		margin-block-end: calc(var(--default-grid-baseline) * 6);
	}

	&__heading {
		margin: 0;
	}

	&__lede {
		margin: 4px 0 0;
		color: var(--color-text-maxcontrast);
	}

	&__search {
		flex: 0 1 320px;
		min-width: 220px;
	}

	&__body {
		display: grid;
		gap: calc(var(--default-grid-baseline) * 4);
		grid-template-columns: minmax(0, 1fr);
	}

	&__sections {
		display: flex;
		flex-direction: column;
		gap: $gutter;
		min-width: 0;
		max-width: 760px;
	}

	&__results {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__group-head {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 3);
		padding-block: calc(var(--default-grid-baseline) * 1);
	}

	&__group-head-icon {
		display: flex;
		align-items: center;
		justify-content: center;
		flex: 0 0 auto;
		inline-size: 48px;
		block-size: 48px;
		border-radius: var(--border-radius-container-large, 16px);
		background: var(--color-primary-element);
		color: var(--color-primary-element-text);
	}

	&__group-heading {
		margin: 0;
		font-size: 22px;
		line-height: 1.3;
	}

	&__group-lede {
		margin: 2px 0 0;
		color: var(--color-text-maxcontrast);
	}

	&__section {
		padding: calc(var(--default-grid-baseline) * 5);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background: var(--color-main-background);
		// the sticky app header would otherwise cover the heading of whichever
		// section was jumped to
		scroll-margin-top: calc(var(--default-grid-baseline) * 4);
	}

	// the one section whose button cannot be taken back: it is marked so a
	// reader knows before they read the words
	&__section--danger {
		border-color: var(--color-error);

		.settings__section-icon {
			background: var(--color-error);
			color: var(--color-primary-element-text);
		}
	}

	&__section-head {
		display: flex;
		align-items: flex-start;
		gap: calc(var(--default-grid-baseline) * 3);
		margin-block-end: calc(var(--default-grid-baseline) * 4);
	}

	&__section-icon {
		display: flex;
		align-items: center;
		justify-content: center;
		flex: 0 0 auto;
		inline-size: 36px;
		block-size: 36px;
		border-radius: var(--border-radius-large);
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
	}

	&__section-title {
		min-width: 0;
	}

	&__section-group {
		display: block;
		font-size: 12px;
		font-weight: bold;
		letter-spacing: .04em;
		text-transform: uppercase;
		color: var(--color-text-maxcontrast);
	}

	&__section-heading {
		margin: 0;
		font-size: 17px;
		line-height: 1.4;
	}

	&__section-lede {
		margin: 2px 0 0;
		color: var(--color-text-maxcontrast);
		max-width: 62ch;
	}

	&__section-body {
		min-width: 0;
	}
}

/* the groups: a row of tabs on a narrow screen, a list beside the page on a wide one */
.settings__groups {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	list-style: none;
	margin: 0;
	padding: 0 0 4px;
	overflow-x: auto;
}

.settings__group {
	flex: 0 0 auto;
}

.settings__group-link {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: 6px 14px 6px 8px;
	border-radius: var(--border-radius-pill, 20px);
	background: var(--color-background-dark);
	color: var(--color-main-text);
	text-decoration: none;
	white-space: nowrap;

	&:hover,
	&:focus-visible {
		background: var(--color-background-hover);
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}

	&--current,
	&--current:hover {
		background: var(--color-primary-element);
		color: var(--color-primary-element-text);

		.settings__group-icon {
			background: transparent;
			color: inherit;
		}
	}
}

.settings__group-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 auto;
	inline-size: 28px;
	block-size: 28px;
	border-radius: 50%;
	color: var(--color-primary-element-light-text);
}

.settings__group-text {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.settings__group-title {
	font-weight: bold;
}

// on a narrow screen a tab is its name; the description is for the list
.settings__group-description,
.settings__toc-list {
	display: none;
}

@include layout.from(layout.$wide) {
	.settings__body {
		grid-template-columns: 260px minmax(0, 760px);
		gap: calc(var(--default-grid-baseline) * 8);
	}

	.settings__nav {
		position: sticky;
		inset-block-start: 0;
		align-self: start;
		max-block-size: calc(100vh - 120px);
		overflow-y: auto;
	}

	.settings__groups {
		flex-direction: column;
		gap: 2px;
		overflow-x: visible;
	}

	.settings__group-link {
		align-items: flex-start;
		padding: 8px 10px;
		border-radius: var(--border-radius-large);
		background: transparent;
		white-space: normal;

		&--current,
		&--current:hover {
			background: var(--color-primary-element-light);
			color: var(--color-primary-element-light-text);
		}
	}

	.settings__group-icon {
		inline-size: 32px;
		block-size: 32px;
		border-radius: var(--border-radius-large);
		background: var(--color-primary-element-light);
	}

	.settings__group-link--current .settings__group-icon {
		background: var(--color-primary-element);
		color: var(--color-primary-element-text);
	}

	.settings__group-description {
		display: block;
		font-size: 13px;
		line-height: 1.35;
		color: var(--color-text-maxcontrast);
	}

	.settings__toc-list {
		display: flex;
		flex-direction: column;
		gap: 1px;
		list-style: none;
		margin: 4px 0 8px 26px;
		padding-inline-start: 16px;
		border-inline-start: 2px solid var(--color-border);
	}
}

.settings__toc-link {
	display: block;
	padding: 4px 10px;
	border-radius: var(--border-radius-large);
	color: var(--color-text-maxcontrast);
	text-decoration: none;
	line-height: 1.3;

	&:hover,
	&:focus-visible {
		background: var(--color-background-hover);
		color: var(--color-main-text);
	}

	&--current {
		color: var(--color-main-text);
		font-weight: bold;
	}

	&--danger:hover,
	&--danger:focus-visible,
	&--danger.settings__toc-link--current {
		color: var(--color-error);
	}
}
</style>
