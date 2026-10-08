<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social-admin">
		<header class="social-admin__header">
			<div class="social-admin__header-text">
				<h1 class="social-admin__heading">
					{{ t('social', 'Aloha Social') }}
				</h1>
				<p class="social-admin__lede">
					{{ t('social', 'How this server moderates, what it offers, which servers it talks to and what it keeps.') }}
				</p>
			</div>
			<NcTextField
				v-model="query"
				class="social-admin__search"
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

		<div class="social-admin__body">
			<!-- The groups, with the sections of the open one under it. On a
			     narrow screen the groups are a row of tabs above the page. -->
			<nav class="social-admin__nav" :aria-label="t('social', 'Administration groups')">
				<ul class="social-admin__groups">
					<li v-for="group in groups" :key="group.id" class="social-admin__group">
						<a
							:href="`#${group.sections[0].id}`"
							class="social-admin__group-link"
							:class="{ 'social-admin__group-link--current': !searching && group.id === activeGroup.id }"
							:aria-current="!searching && group.id === activeGroup.id ? 'page' : undefined"
							@click="openGroup(group.id, $event)">
							<span class="social-admin__group-icon">
								<component :is="group.icon" :size="20" />
							</span>
							<span class="social-admin__group-text">
								<span class="social-admin__group-title">{{ group.title }}</span>
								<span class="social-admin__group-description">{{ group.description }}</span>
							</span>
							<span
								v-if="group.waiting > 0"
								class="social-admin__badge"
								:title="n('social', '%n thing needs you here', '%n things need you here', group.waiting)">
								{{ group.waiting }}
							</span>
						</a>
						<ul
							v-if="!searching && group.id === activeGroup.id && group.sections.length > 1"
							class="social-admin__toc"
							:aria-label="t('social', 'On this page')">
							<li v-for="section in group.sections" :key="section.id">
								<a
									:href="`#${section.id}`"
									class="social-admin__toc-link"
									:class="{ 'social-admin__toc-link--current': current === section.id }"
									:aria-current="current === section.id ? 'true' : undefined"
									@click="jumpTo(section.id, $event)">
									{{ section.title }}
								</a>
							</li>
						</ul>
					</li>
				</ul>
			</nav>

			<div class="social-admin__sections">
				<p v-if="searching" class="social-admin__results" role="status">
					{{ matches.length === 0
						? t('social', 'No setting matches “{query}”.', { query: query.trim() })
						: n('social', '%n setting matches “{query}”', '%n settings match “{query}”', matches.length, { query: query.trim() }) }}
				</p>
				<header v-else class="social-admin__group-head">
					<span class="social-admin__group-head-icon">
						<component :is="activeGroup.icon" :size="24" />
					</span>
					<div>
						<h2 class="social-admin__group-heading">
							{{ activeGroup.title }}
						</h2>
						<p class="social-admin__group-lede">
							{{ activeGroup.description }}
						</p>
					</div>
				</header>

				<section
					v-for="(section, index) in shownSections"
					:id="section.id"
					:key="section.id"
					class="social-admin__section"
					:style="{ '--entry-index': index }">
					<header class="social-admin__section-head">
						<span class="social-admin__section-icon">
							<component :is="section.icon" :size="20" />
						</span>
						<div class="social-admin__section-title">
							<span v-if="searching" class="social-admin__section-group">{{ groupOf(section.id).title }}</span>
							<h3 class="social-admin__section-heading">
								{{ section.title }}
							</h3>
							<p class="social-admin__section-lede">
								{{ section.lede }}
							</p>
						</div>
					</header>

					<div class="social-admin__section-body">
						<component :is="section.component" v-bind="section.props ?? {}" v-on="section.on ?? {}" />
					</div>
				</section>
			</div>
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconAccess from 'vue-material-design-icons/ShieldLockOutline.vue'
import IconAccounts from 'vue-material-design-icons/AccountSearchOutline.vue'
import IconActivity from 'vue-material-design-icons/ChartLine.vue'
import IconAnnouncements from 'vue-material-design-icons/BullhornOutline.vue'
import IconAttention from 'vue-material-design-icons/BellAlertOutline.vue'
import IconBackground from 'vue-material-design-icons/TimerCogOutline.vue'
import IconBlocklist from 'vue-material-design-icons/PlaylistRemove.vue'
import IconDiscover from 'vue-material-design-icons/InformationOutline.vue'
import IconEmoji from 'vue-material-design-icons/EmoticonOutline.vue'
import IconExplore from 'vue-material-design-icons/CompassOutline.vue'
import IconExternal from 'vue-material-design-icons/AccountPlusOutline.vue'
import IconExternalAccounts from 'vue-material-design-icons/AccountMultipleOutline.vue'
import IconExternalRequests from 'vue-material-design-icons/AccountClockOutline.vue'
import IconFederation from 'vue-material-design-icons/Earth.vue'
import IconFederationHealth from 'vue-material-design-icons/SendCheckOutline.vue'
import IconFeatures from 'vue-material-design-icons/ViewGridOutline.vue'
import IconInterests from 'vue-material-design-icons/TagHeartOutline.vue'
import IconMedia from 'vue-material-design-icons/ImageOffOutline.vue'
import IconModeration from 'vue-material-design-icons/ShieldAccountOutline.vue'
import IconOverview from 'vue-material-design-icons/ViewDashboardOutline.vue'
import IconRelays from 'vue-material-design-icons/AccessPointNetwork.vue'
import IconReports from 'vue-material-design-icons/FlagOutline.vue'
import IconRetention from 'vue-material-design-icons/DeleteClockOutline.vue'
import IconReview from 'vue-material-design-icons/ShieldAlertOutline.vue'
import IconRules from 'vue-material-design-icons/FormatListChecks.vue'
import IconSearch from 'vue-material-design-icons/Magnify.vue'
import IconServer from 'vue-material-design-icons/ServerOutline.vue'
import IconServerSettings from 'vue-material-design-icons/CogOutline.vue'
import IconStorage from 'vue-material-design-icons/Harddisk.vue'
import IconTrends from 'vue-material-design-icons/TrendingUp.vue'
import { currentSection, scrollToSection, watchSections } from '../../services/sectionRail.js'
import { matchesQuery } from '../../services/settingsSearch.js'
import AccessSection from './AccessSection.vue'
import AccountsSection from './AccountsSection.vue'
import ActivitySection from './ActivitySection.vue'
import AnnouncementsSection from './AnnouncementsSection.vue'
import AttentionSection from './AttentionSection.vue'
import BackgroundSection from './BackgroundSection.vue'
import BlocklistSection from './BlocklistSection.vue'
import DiscoverSection from './DiscoverSection.vue'
import EmojiSection from './EmojiSection.vue'
import ExternalAccountsSection from './ExternalAccountsSection.vue'
import ExternalRequestsSection from './ExternalRequestsSection.vue'
import ExternalUsersSection from './ExternalUsersSection.vue'
import FederationSection from './FederationSection.vue'
import InterestsSection from './InterestsSection.vue'
import MediaBlocksSection from './MediaBlocksSection.vue'
import RelaysSection from './RelaysSection.vue'
import ReportsSection from './ReportsSection.vue'
import RetentionSection from './RetentionSection.vue'
import ReviewSection from './ReviewSection.vue'
import RulesSection from './RulesSection.vue'
import SectionsSection from './SectionsSection.vue'
import ServerSection from './ServerSection.vue'
import StorageSection from './StorageSection.vue'
import TrendsSection from './TrendsSection.vue'

/** What `AdminSettings::getForm()` provides when it provides nothing. */
const NOTHING = {
	reports: [],
	openReports: 0,
	resolvedReports: 0,
	reportsPerPage: 50,
	activity: { day: { posts: 0, authors: 0 }, week: { posts: 0, authors: 0 } },
	review: [],
	reviewTotal: 0,
	reviewFirstPost: true,
	autospam: true,
	/**
	 * For you, or null where this instance does not offer it — the
	 * card and its section are drawn only when the server sends it
	 * (`InterestService::adminSettings()`).
	 *
	 * @type {{enabled: boolean, learningDefault: boolean, halfLife: number, threshold: number, cap: number, window: number}|null}
	 */
	interests: null,
	reviewVideos: false,
	server: null,
	accessType: 'all_but',
	accessList: [],
	retentionDays: 0,
	storage: null,
	/** @type {?object} what each section is switched to; administrators only */
	sections: null,
	/** @type {?Array<{id: string, name: string}>} the groups a list may follow; administrators only */
	groups: null,
	/** @type {?object} the video ladder and its disk; administrators only */
	videoStorage: null,
	/** @type {?object} how the background jobs are doing */
	background: null,
	/** @type {?object} self-registered external users (`ExternalAdminState`); administrators only */
	external: null,
	federation: {
		waiting: 0,
		running: 0,
		failing: 0,
		atRisk: 0,
		abandoned: 0,
		maxTries: 15,
		truncated: false,
		abandonedTruncated: false,
		retentionDays: 7,
		stuckSince: 0,
		instances: [],
		givenUp: [],
	},
}

/**
 * Administration → Aloha Social.
 *
 * The sections are in six groups by what an administrator came to do, and one
 * group is shown at a time, with a search across all of them. Overview opens
 * first and lists what is waiting, each line a link to the section that deals
 * with it. A section a delegate is not sent the data for drops out of its
 * group, and a group with nothing left in it is not listed.
 *
 * Each section keeps its id, so `#reports` and the other old links open the
 * group the section is in.
 *
 * Nothing here uses `v-html`, and nothing below it does either. Half of what
 * these tables draw — a handle, an instance name, the comment on a report — is
 * a string another server sent, and this is the page whose buttons delete
 * accounts. Vue escapes interpolation; `v-html` is the one way to lose that.
 */
export default {
	name: 'AdminSettings',

	components: {
		AccessSection,
		AccountsSection,
		ActivitySection,
		AnnouncementsSection,
		AttentionSection,
		BackgroundSection,
		BlocklistSection,
		DiscoverSection,
		EmojiSection,
		ExternalAccountsSection,
		ExternalRequestsSection,
		ExternalUsersSection,
		FederationSection,
		IconAccess,
		IconAccounts,
		IconActivity,
		IconAnnouncements,
		IconAttention,
		IconBackground,
		IconBlocklist,
		IconDiscover,
		IconEmoji,
		IconExplore,
		IconExternal,
		IconExternalAccounts,
		IconExternalRequests,
		IconFederation,
		IconFederationHealth,
		IconFeatures,
		IconInterests,
		IconMedia,
		IconModeration,
		IconOverview,
		IconRelays,
		IconReports,
		IconRetention,
		IconReview,
		IconRules,
		IconSearch,
		IconServer,
		IconServerSettings,
		IconStorage,
		IconTrends,
		InterestsSection,
		MediaBlocksSection,
		NcTextField,
		RelaysSection,
		ReportsSection,
		RetentionSection,
		ReviewSection,
		RulesSection,
		SectionsSection,
		ServerSection,
		StorageSection,
		TrendsSection,
	},

	data() {
		return {
			state: { ...NOTHING, ...loadState('social', 'adminSettings', {}) },
			/** the group the administrator opened, '' for the first */
			openedGroup: '',
			/** what the administrator typed into the search */
			query: '',
			/** the section to scroll to once it is drawn */
			pending: '',
			/** the section the administrator is looking at, for the rail */
			current: '',
			observer: null,
		}
	},

	computed: {
		/**
		 * What is waiting for an administrator, most urgent first.
		 *
		 * @return {Array<{section: string, count: number, text: string, type: string}>}
		 */
		attention() {
			const federation = this.state.federation ?? NOTHING.federation
			const late = this.state.background?.late ?? 0
			// a job missing from the list is what an upgrade that has not run looks like
			const unregistered = late > 0 ? 0 : (this.state.background?.jobs ?? []).filter((job) => job.registered === false).length
			const awaiting = this.state.external?.settings?.awaitingApproval ?? 0
			// a failed delivery is tried again; it only needs somebody once it is about to be given up on
			const retrying = federation.atRisk > 0 ? 0 : federation.failing

			return [
				{
					section: 'background',
					type: 'error',
					count: late,
					text: n('social', 'background job has not run when it should have', 'background jobs have not run when they should have', late),
				},
				{
					section: 'background',
					type: 'error',
					count: unregistered,
					text: n('social', 'background job is not registered: run occ upgrade', 'background jobs are not registered: run occ upgrade', unregistered),
				},
				{
					section: 'federation',
					type: 'error',
					count: federation.atRisk,
					text: n('social', 'delivery is close to being given up on', 'deliveries are close to being given up on', federation.atRisk),
				},
				{
					section: 'reports',
					type: 'warning',
					count: this.state.openReports,
					text: n('social', 'open report', 'open reports', this.state.openReports),
				},
				{
					section: 'review',
					type: 'warning',
					count: this.state.reviewTotal,
					text: n('social', 'post waiting for review', 'posts waiting for review', this.state.reviewTotal),
				},
				{
					section: 'external-requests',
					type: 'warning',
					count: awaiting,
					text: n('social', 'registration waiting for approval', 'registrations waiting for approval', awaiting),
				},
				{
					section: 'federation',
					type: 'info',
					count: retrying,
					text: n('social', 'delivery has failed and will be tried again', 'deliveries have failed and will be tried again', retrying),
				},
			].filter((item) => item.count > 0)
		},

		/**
		 * Every section, in the order it is read, each naming its group.
		 *
		 * @return {object[]} one entry per section
		 */
		sections() {
			const state = this.state
			// a delegate moderates; they are not sent what administers the server
			const administrator = state.server !== null

			return [
				{
					id: 'attention',
					group: 'overview',
					icon: 'IconAttention',
					component: 'AttentionSection',
					props: { items: this.attention },
					on: { go: this.openSection },
					title: t('social', 'Needs attention'),
					lede: t('social', 'What is waiting for you. Each line takes you to where it is dealt with.'),
				},
				{
					id: 'activity',
					group: 'overview',
					icon: 'IconActivity',
					component: 'ActivitySection',
					props: { activity: state.activity },
					title: t('social', 'Activity here'),
					lede: t('social', 'Posts written on this server in the last day and week. Posts from other servers are not counted.'),
				},
				{
					id: 'reports',
					group: 'moderation',
					icon: 'IconReports',
					component: 'ReportsSection',
					props: { reports: state.reports, openTotal: state.openReports, resolvedTotal: state.resolvedReports, perPage: state.reportsPerPage },
					title: t('social', 'Reports'),
					lede: t('social', 'Complaints from people here and from other servers. Open ones first; the ones dealt with are folded away below.'),
					keywords: t('social', 'complaint abuse flag'),
				},
				{
					id: 'review',
					group: 'moderation',
					icon: 'IconReview',
					component: 'ReviewSection',
					props: { queue: state.review, total: state.reviewTotal, reviewFirstPost: state.reviewFirstPost, autospam: state.autospam, reviewVideos: state.reviewVideos },
					title: t('social', 'Posts waiting for review'),
					lede: t('social', 'The first post of a new account, and posts that look like spam, wait here until somebody publishes or refuses them.'),
					keywords: t('social', 'spam hold queue approve first post video'),
				},
				{
					id: 'accounts',
					group: 'moderation',
					icon: 'IconAccounts',
					component: 'AccountsSection',
					title: t('social', 'Accounts'),
					lede: t('social', 'Every account this server knows. Find one by username, handle or server, then silence or suspend it.'),
					keywords: t('social', 'user silence suspend ban'),
				},
				{
					id: 'media',
					group: 'moderation',
					icon: 'IconMedia',
					component: 'MediaBlocksSection',
					title: t('social', 'Refused pictures'),
					lede: t('social', 'Files this server will not store, wherever they come from, so a removed picture cannot simply be posted again.'),
					keywords: t('social', 'image file checksum hash block'),
				},
				{
					id: 'rules',
					group: 'moderation',
					icon: 'IconRules',
					component: 'RulesSection',
					title: t('social', 'Server rules'),
					lede: t('social', 'The rules shown to somebody deciding whether to join. One per line.'),
					keywords: t('social', 'code of conduct terms'),
				},
				...(state.external
					? [
							{
								id: 'external',
								group: 'registration',
								icon: 'IconExternal',
								component: 'ExternalUsersSection',
								props: { external: state.external },
								on: { changed: this.onExternalChanged },
								title: t('social', 'Who may sign up'),
								lede: t('social', 'Whether people without an account on this server may register one for Aloha Social, and on what terms.'),
								keywords: t('social', 'external users register signup quota age email'),
							},
							{
								id: 'external-requests',
								group: 'registration',
								icon: 'IconExternalRequests',
								component: 'ExternalRequestsSection',
								props: { external: state.external },
								on: { changed: this.onExternalChanged },
								title: t('social', 'Waiting and invited'),
								lede: t('social', 'Sign-ups waiting for your approval, and invitation links that let somebody skip it.'),
								keywords: t('social', 'approve approval invitation invite'),
							},
							{
								id: 'external-accounts',
								group: 'registration',
								icon: 'IconExternalAccounts',
								component: 'ExternalAccountsSection',
								props: { external: state.external },
								on: { changed: this.onExternalChanged },
								title: t('social', 'External accounts'),
								lede: t('social', 'The accounts people registered themselves. Promote one to make it an ordinary account of this server.'),
								keywords: t('social', 'external users promote'),
							},
						]
					: []),
				{
					id: 'discover',
					group: 'explore',
					icon: 'IconDiscover',
					component: 'DiscoverSection',
					title: t('social', 'About this server'),
					lede: t('social', 'A few subjects, each a handful of hashtags, shown at the top of Explore so a newcomer sees what this server is about.'),
					keywords: t('social', 'explore topics hashtags discover'),
				},
				{
					id: 'trends',
					group: 'explore',
					icon: 'IconTrends',
					component: 'TrendsSection',
					title: t('social', 'What may trend'),
					lede: t('social', 'Keep a hashtag, a link or a post off every trending list here.'),
					keywords: t('social', 'trending explore hashtag hide'),
				},
				{
					id: 'emoji',
					group: 'explore',
					icon: 'IconEmoji',
					component: 'EmojiSection',
					title: t('social', 'Custom emoji'),
					lede: t('social', 'Pictures people here can put in a post as :shortcode:. Other servers see them too.'),
				},
				{
					id: 'announcements',
					group: 'explore',
					icon: 'IconAnnouncements',
					component: 'AnnouncementsSection',
					title: t('social', 'Announcements'),
					lede: t('social', 'A notice everybody here is shown once in their app, until they dismiss it.'),
					keywords: t('social', 'notice banner message'),
				},
				...(state.sections
					? [{
							id: 'sections',
							group: 'explore',
							icon: 'IconFeatures',
							component: 'SectionsSection',
							props: { settings: state.sections, groups: state.groups ?? [] },
							title: t('social', 'Features'),
							lede: t('social', 'Turn 24-hour shorts and the Photos and Videos timelines on or off for everybody, and choose which Nextcloud groups become lists.'),
							keywords: t('social', 'sections shorts stories photos videos groups lists'),
						}]
					: []),
				...(state.interests
					? [{
							id: 'interests',
							group: 'explore',
							icon: 'IconInterests',
							component: 'InterestsSection',
							props: { settings: state.interests },
							title: t('social', 'For you'),
							lede: t('social', 'A feed of the hashtags each person reads most. What is learned is only ever shown to the person it is about.'),
							keywords: t('social', 'interests recommendations feed'),
						}]
					: []),
				{
					id: 'access',
					group: 'federation',
					icon: 'IconAccess',
					component: 'AccessSection',
					props: { accessType: state.accessType, addresses: state.accessList },
					title: t('social', 'Allowed and blocked servers'),
					lede: t('social', 'Federate with every server except the ones listed, or only with the ones listed.'),
					keywords: t('social', 'fediverse access blocklist allowlist defederate domain instance'),
				},
				...(administrator
					? [
							{
								id: 'blocklist',
								group: 'federation',
								icon: 'IconBlocklist',
								component: 'BlocklistSection',
								on: { changed: this.onListChanged },
								title: t('social', 'Block lists'),
								lede: t('social', 'Import a list of servers to block, or follow one that somebody else publishes.'),
								keywords: t('social', 'import csv mastodon domain'),
							},
							{
								id: 'relays',
								group: 'federation',
								icon: 'IconRelays',
								component: 'RelaysSection',
								title: t('social', 'Relays'),
								lede: t('social', 'Swap public posts with other servers through a relay, so the timelines of a new server are not empty.'),
							},
						]
					: []),
				{
					id: 'federation',
					group: 'federation',
					icon: 'IconFederationHealth',
					component: 'FederationSection',
					props: { federation: state.federation },
					title: t('social', 'Deliveries'),
					lede: t('social', 'Posts, follows and likes on their way to other servers, and the ones that keep failing.'),
					keywords: t('social', 'federation health queue failing'),
				},
				...(administrator
					? [{
							id: 'server',
							group: 'server',
							icon: 'IconServerSettings',
							component: 'ServerSection',
							props: { settings: state.server },
							title: t('social', 'Server settings'),
							lede: t('social', 'Contact details, upload and video limits, and how strict this server is with other servers.'),
							keywords: t('social', 'contact email upload size video transcode secure mode certificate'),
						}]
					: []),
				{
					id: 'retention',
					group: 'server',
					icon: 'IconRetention',
					component: 'RetentionSection',
					props: { days: state.retentionDays },
					title: t('social', 'Retention'),
					lede: t('social', 'How long posts from other servers are kept. 0 keeps them for good.'),
					keywords: t('social', 'delete prune days cleanup'),
				},
				{
					id: 'storage',
					group: 'server',
					icon: 'IconStorage',
					component: 'StorageSection',
					props: { storage: state.storage, videoStorage: state.videoStorage },
					title: t('social', 'Storage'),
					lede: t('social', 'What Aloha Social keeps on disk, measured once a day.'),
					keywords: t('social', 'disk space media cache'),
				},
				{
					id: 'background',
					group: 'server',
					icon: 'IconBackground',
					component: 'BackgroundSection',
					props: { background: state.background },
					title: t('social', 'Background jobs'),
					lede: t('social', 'When each scheduled job last ran. If cron stops, posts stop going out.'),
					keywords: t('social', 'cron jobs schedule'),
				},
			]
		},

		/**
		 * The groups, in the order they are listed, each holding its sections
		 * and how many things in them are waiting. A group with nothing in it
		 * here is left out.
		 *
		 * @return {object[]}
		 */
		groups() {
			return [
				{
					id: 'overview',
					icon: 'IconOverview',
					title: t('social', 'Overview'),
					description: t('social', 'What needs you, and how busy this server is.'),
				},
				{
					id: 'moderation',
					icon: 'IconModeration',
					title: t('social', 'Moderation'),
					description: t('social', 'Reports, posts held for review, accounts and the rules.'),
				},
				{
					id: 'registration',
					icon: 'IconExternal',
					title: t('social', 'Sign-ups'),
					description: t('social', 'People without an account here registering for Aloha Social.'),
				},
				{
					id: 'explore',
					icon: 'IconExplore',
					title: t('social', 'Explore and features'),
					description: t('social', 'What people find on Explore, and what the app offers them.'),
				},
				{
					id: 'federation',
					icon: 'IconFederation',
					title: t('social', 'Federation'),
					description: t('social', 'Which servers this one talks to, and whether posts get through.'),
				},
				{
					id: 'server',
					icon: 'IconServer',
					title: t('social', 'Server'),
					description: t('social', 'Limits, retention, storage and background jobs.'),
				},
			]
				.map((group) => {
					const sections = this.sections.filter((section) => section.group === group.id)
					const ids = sections.map((section) => section.id)
					// the overview lists all of it already; a number beside it would only repeat the page
					const waiting = group.id === 'overview'
						? 0
						: this.attention.filter((item) => item.type !== 'info' && ids.includes(item.section)).reduce((sum, item) => sum + item.count, 0)

					return { ...group, sections, waiting }
				})
				.filter((group) => group.sections.length > 0)
		},

		/** @return {object} the group on screen */
		activeGroup() {
			return this.groups.find((group) => group.id === this.openedGroup) ?? this.groups[0]
		},

		/** @return {boolean} whether the administrator is searching rather than browsing */
		searching() {
			return this.query.trim() !== ''
		},

		/** @return {object[]} the sections whose title, description, group or keywords say every word typed */
		matches() {
			return this.sections.filter((section) => matchesQuery(
				this.query,
				`${section.title} ${section.lede} ${section.keywords ?? ''} ${this.groupOf(section.id).title}`,
			))
		},

		/** @return {object[]} the sections drawn: the search's, or the open group's */
		shownSections() {
			return this.searching ? this.matches : this.activeGroup.sections
		},
	},

	mounted() {
		window.addEventListener('hashchange', this.onHashChange)
		this.onHashChange()
		this.watch()
	},

	updated() {
		this.scrollToPending()
		// the sections of another group were not there to be watched before
		this.watch()
	},

	beforeUnmount() {
		window.removeEventListener('hashchange', this.onHashChange)
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

		/** Opens the section the address names, as a link from elsewhere or a bookmark does. */
		onHashChange() {
			const id = window.location.hash.replace(/^#/, '')
			if (id !== '' && this.sections.some((section) => section.id === id)) {
				this.openSection(id)
			}
		},

		/**
		 * Opens the group a section is in and puts the section in view.
		 *
		 * @param {string} id the section
		 */
		openSection(id) {
			const group = this.groupOf(id)
			this.query = ''
			this.openedGroup = group.id
			this.current = id
			this.remember(id)
			// the first section is where the group starts: its heading stays in view above it
			if (group.sections[0]?.id === id) {
				this.pending = ''
				this.$nextTick(() => this.$el.scrollIntoView?.({ block: 'start' }))
				return
			}
			this.pending = id
			// drawn on the next update when the group was not open yet
			this.$nextTick(() => this.scrollToPending())
		},

		/** Scrolls to the section asked for, once it is on the page. */
		scrollToPending() {
			if (this.pending !== '' && scrollToSection(this.pending)) {
				this.pending = ''
			}
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
			this.pending = ''
			this.remember(group.sections[0].id)
			this.$el.scrollIntoView?.({ block: 'start' })
		},

		/**
		 * Follows a section link without leaving the page.
		 *
		 * @param {string} id the section to go to
		 * @param {MouseEvent} event the click
		 */
		jumpTo(id, event) {
			if (scrollToSection(id)) {
				event.preventDefault()
				this.current = id
				this.remember(id)
			}
		},

		/**
		 * Puts a section in the address without a history entry or a jump,
		 * so a reload or a copied link comes back to it.
		 *
		 * @param {string} id the section
		 */
		remember(id) {
			window.history.replaceState?.(window.history.state, '', `#${id}`)
		},

		/**
		 * What the Sign-ups sections read, after one of them changed it.
		 *
		 * @param {object} external `ExternalAdminState::current()`, or part of it
		 */
		onExternalChanged(external) {
			this.state = { ...this.state, external: { ...this.state.external, ...external } }
		},

		/**
		 * The access list after a block list was applied.
		 *
		 * The two sections read the same list, so the one above has to be told
		 * rather than left showing what it read when the page opened.
		 *
		 * @param {string[]|undefined} list the addresses now on it
		 */
		onListChanged(list) {
			if (Array.isArray(list)) {
				this.state = { ...this.state, accessList: list }
			}
		},

		/** Watches the sections on screen, and marks the one being read. */
		watch() {
			const ids = this.shownSections.map((section) => section.id)
			this.observer?.disconnect()
			this.observer = watchSections(ids, () => this.mark())
			this.mark()
		},

		/**
		 * Marks the section the administrator is looking at; before any
		 * heading has passed the top, that is the first one.
		 */
		mark() {
			const ids = this.shownSections.map((section) => section.id)
			this.current = currentSection(ids, ids[0] ?? '')
		},
	},
}
</script>

<style lang="scss">
@use '../../styles/layout.scss' as layout;

/**
 * The sections arrive one after another rather than all at once, when the
 * page opens and when another group is opened. The delay is capped, and the
 * whole thing is off for a reader who asked for less movement.
 */
@keyframes section-arrive {
	from { opacity: 0; transform: translateY(10px); }
}

@media (prefers-reduced-motion: no-preference) {
	.social-admin__section,
	.social-admin__group-head {
		animation: section-arrive .26s ease-out both;
		animation-delay: calc(min(var(--entry-index, 0), 7) * 35ms);
	}
}

// Deliberately not scoped: the table below is the same table in four of the
// sections, and a moderator reading a page of reports should not have to learn
// a second layout halfway down it.
.social-admin {
	max-width: 1120px;
	margin-inline: auto;
	padding-inline: calc(var(--default-grid-baseline) * 4);
	// Nextcloud puts its navigation toggle over the top left corner of the
	// settings content, where the heading would otherwise be
	padding-block: calc(var(--default-grid-baseline) * 6);

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
		font-size: 24px;
		font-weight: bold;
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
		gap: calc(var(--default-grid-baseline) * 4);
		min-width: 0;
		max-width: 800px;
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
		// clear of the settings page's own sticky header when jumped to
		scroll-margin-top: calc(var(--default-grid-baseline) * 4);
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
		font-weight: bold;
		line-height: 1.4;
	}

	&__section-lede {
		margin: 2px 0 0;
		color: var(--color-text-maxcontrast);
		max-width: 62ch;
	}

	&__section-body {
		min-width: 0;

		h4 {
			margin-block: calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 1);
			font-size: 15px;
			font-weight: bold;
		}

		> div > h4:first-child,
		> div > p:first-child {
			margin-block-start: 0;
		}
	}

	&__table {
		width: 100%;
		border-collapse: collapse;
		margin-block: calc(var(--default-grid-baseline) * 2);

		th {
			text-align: start;
			font-weight: bold;
			color: var(--color-text-maxcontrast);
			padding: calc(var(--default-grid-baseline) * 2);
			border-block-end: 1px solid var(--color-border);
		}

		td {
			padding: calc(var(--default-grid-baseline) * 2);
			border-block-end: 1px solid var(--color-border);
			vertical-align: top;
			// an actor id is a URL, and one long one used to widen the table
			// until the decision buttons were off the side of the page
			overflow-wrap: anywhere;
		}
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: var(--default-grid-baseline);
		align-items: center;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__scroll {
		overflow-x: auto;
	}
}

/* the groups: a row of tabs on a narrow screen, a list beside the page on a wide one */
.social-admin__groups {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	list-style: none;
	margin: 0;
	padding: 0 0 4px;
	overflow-x: auto;
}

.social-admin__group {
	flex: 0 0 auto;
}

.social-admin__group-link {
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

		.social-admin__group-icon {
			background: transparent;
			color: inherit;
		}
	}
}

.social-admin__group-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 auto;
	inline-size: 28px;
	block-size: 28px;
	border-radius: 50%;
	color: var(--color-primary-element-light-text);
}

.social-admin__group-text {
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	min-width: 0;
}

.social-admin__group-title {
	font-weight: bold;
}

.social-admin__badge {
	flex: 0 0 auto;
	min-inline-size: 22px;
	padding: 1px 7px;
	border-radius: var(--border-radius-pill, 20px);
	// the element colour, not --color-error: that is a pale background from Nextcloud 32 on
	background: var(--color-element-error, var(--color-error));
	color: var(--color-primary-element-text);
	font-size: 12px;
	font-weight: bold;
	line-height: 20px;
	text-align: center;
}

// on a narrow screen a tab is its name; the description is for the list
.social-admin__group-description,
.social-admin__toc {
	display: none;
}

@include layout.from(layout.$wide) {
	.social-admin__body {
		grid-template-columns: 260px minmax(0, 800px);
		gap: calc(var(--default-grid-baseline) * 8);
	}

	.social-admin__nav {
		position: sticky;
		// clear of Nextcloud's navigation toggle, which floats over the top
		// left corner of the settings content whatever is scrolled under it
		inset-block-start: calc(var(--default-grid-baseline) * 12);
		align-self: start;
		max-block-size: calc(100vh - 120px);
		overflow-y: auto;
	}

	.social-admin__groups {
		flex-direction: column;
		gap: 2px;
		overflow-x: visible;
	}

	.social-admin__group-link {
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

	.social-admin__group-icon {
		inline-size: 32px;
		block-size: 32px;
		border-radius: var(--border-radius-large);
		background: var(--color-primary-element-light);
	}

	.social-admin__group-link--current .social-admin__group-icon {
		background: var(--color-primary-element);
		color: var(--color-primary-element-text);
	}

	.social-admin__group-description {
		display: block;
		font-size: 13px;
		line-height: 1.35;
		color: var(--color-text-maxcontrast);
	}

	.social-admin__badge {
		margin-block-start: 5px;
	}

	.social-admin__toc {
		display: flex;
		flex-direction: column;
		gap: 1px;
		list-style: none;
		margin: 4px 0 8px 26px;
		padding-inline-start: 16px;
		border-inline-start: 2px solid var(--color-border);
	}
}

.social-admin__toc-link {
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
}
</style>
