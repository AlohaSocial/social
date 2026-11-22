<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="settings">
		<PageHeader
			:title="t('social', 'Settings')"
			:description="t('social', 'Changes are saved as you make them.')"
			clearNavigation>
			<template #actions>
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
			</template>
		</PageHeader>

		<div class="settings__body">
			<!-- the groups, with the sections of the open one under them; on a
			     narrow screen the groups are tabs above the page -->
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

				<!-- each section keeps the id other pages link to (#interests, #blocked,
				     #migration); such a link opens the group the section is in -->
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
						<component :is="section.component" v-bind="section.props" />
					</div>
				</section>
			</div>
		</div>
	</div>
</template>

<script>
import PageHeader from '../components/PageHeader.vue'
import AuthorizedApps from '../components/AuthorizedApps.vue'
import DeleteAccount from '../components/DeleteAccount.vue'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconAccount from 'vue-material-design-icons/AccountCircleOutline.vue'
import IconAccountCheck from 'vue-material-design-icons/AccountCheckOutline.vue'
import IconApps from 'vue-material-design-icons/KeyOutline.vue'
import IconBluesky from 'vue-material-design-icons/ButterflyOutline.vue'
import IconBellRing from 'vue-material-design-icons/BellRingOutline.vue'
import IconBlocked from 'vue-material-design-icons/Cancel.vue'
import IconDelete from 'vue-material-design-icons/DeleteOutline.vue'
import IconInterests from 'vue-material-design-icons/TagHeartOutline.vue'
import IconMigration from 'vue-material-design-icons/SwapHorizontal.vue'
import IconNotifications from 'vue-material-design-icons/BellOutline.vue'
import IconReading from 'vue-material-design-icons/BookOpenPageVariantOutline.vue'
import IconSearch from 'vue-material-design-icons/Magnify.vue'
import IconSecurity from 'vue-material-design-icons/ShieldKeyOutline.vue'
import IconSensitive from 'vue-material-design-icons/EyeOffOutline.vue'
import IconCounts from 'vue-material-design-icons/HeartOffOutline.vue'
import IconFilesComments from 'vue-material-design-icons/CommentTextMultipleOutline.vue'
import IconStorage from 'vue-material-design-icons/Harddisk.vue'
import { defineAsyncComponent } from 'vue'
import { n, t } from '@nextcloud/l10n'
import { useServerData } from '../composables/useServerData.js'
import { currentSection, scrollToSection, watchSections } from '../services/sectionRail.js'
import { matchesQuery } from '../services/settingsSearch.js'

// only ever drawn on this page, so these load in a chunk of their own
const AccountSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/AccountSettings.vue'))
const BlueskySettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/BlueskySettings.vue'))
const BlueskyFeedsSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/BlueskyFeedsSettings.vue'))
const ForYouSettings = defineAsyncComponent(() => import(/* webpackChunkName: "for-you-settings" */'../components/ForYouSettings.vue'))
const SensitiveMediaSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/SensitiveMediaSettings.vue'))
const CountsSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/CountsSettings.vue'))
const FilesCommentsSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/FilesCommentsSettings.vue'))
const NotificationDeliverySettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/NotificationDeliverySettings.vue'))
const ContactSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/ContactSettings.vue'))
const BlockedAccounts = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'./BlockedAccounts.vue'))
const MigrationSettings = defineAsyncComponent(() => import(/* webpackChunkName: "migration" */'../components/MigrationSettings.vue'))
const ExternalStorage = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/ExternalStorage.vue'))

/**
 * Anchors inside a section, and the section each is in: the parts of Moving
 * your account (which it opens by the hash) and of Blocked and muted.
 */
const INNER_ANCHORS = {
	'direct-messages': 'contact',
	'notification-policy': 'contact',
	bring: 'migration',
	copy: 'migration',
	leave: 'migration',
	'moderation-lists': 'blocked',
	filters: 'blocked',
	'ai-content': 'blocked',
	labelers: 'blocked',
	'filtered-notifications': 'blocked',
}

/**
 * Settings: what this app holds about how the reader uses it, in groups
 * shown one at a time, with a search across all of them.
 *
 * The sections are described as data, so the rail, the search and the page
 * share one title and id for each.
 */
export default {
	name: 'Settings',

	components: {
		PageHeader,
		AccountSettings,
		BlockedAccounts,
		ContactSettings,
		MigrationSettings,
		IconBlocked,
		IconMigration,
		AuthorizedApps,
		BlueskySettings,
		DeleteAccount,
		ExternalStorage,
		ForYouSettings,
		IconAccount,
		IconAccountCheck,
		IconApps,
		IconBluesky,
		IconBellRing,
		IconDelete,
		IconInterests,
		IconNotifications,
		IconReading,
		IconSearch,
		IconSecurity,
		IconCounts,
		IconFilesComments,
		IconSensitive,
		IconStorage,
		BlueskyFeedsSettings,
		NcTextField,
		NotificationDeliverySettings,
		CountsSettings,
		FilesCommentsSettings,
		SensitiveMediaSettings,
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
		 * @return {object[]}
		 */
		sections() {
			const external = Boolean(this.serverData?.externalMedia)
			const interests = this.serverData?.interests
			const bluesky = this.serverData?.bluesky?.enabled === true

			return [
				{
					id: 'account',
					group: 'profile',
					icon: 'IconAccount',
					component: 'AccountSettings',
					title: t('social', 'Who can find you'),
					lede: t('social', 'How others find you, and who sees what you post.'),
				},
				{
					id: 'contact',
					group: 'profile',
					icon: 'IconAccountCheck',
					component: 'ContactSettings',
					title: t('social', 'Who can contact you'),
					lede: t('social', 'Who may follow you, send you direct messages and reach your activities without waiting.'),
				},
				{
					id: 'blocked',
					group: 'profile',
					icon: 'IconBlocked',
					component: 'BlockedAccounts',
					props: { embedded: true },
					title: t('social', 'Blocked and muted'),
					lede: t('social', 'Accounts, servers and words you would rather not see, and the senders waiting for you to decide.'),
				},
				{
					id: 'migration',
					group: 'profile',
					icon: 'IconMigration',
					component: 'MigrationSettings',
					title: t('social', 'Moving your account'),
					lede: t('social', 'Bring an account here, keep a copy of yours, or move it to another server.'),
				},
				{
					id: 'delete',
					group: 'profile',
					icon: 'IconDelete',
					component: 'DeleteAccount',
					title: t('social', 'Delete your Aloha Social account'),
					lede: external
						? t('social', 'Your account on this server and everything you posted. This cannot be undone.')
						: t('social', 'Your fediverse account and everything you posted. Your Nextcloud account stays as it is. This cannot be undone.'),

					danger: true,
				},
				// only where the administrator has the feature on
				...(interests?.enabled === true || interests?.recommendationsEnabled === true
					? [{
							id: 'interests',
							group: 'reading',
							icon: 'IconInterests',
							component: 'ForYouSettings',
							title: t('social', 'For you'),
							lede: t('social', 'What For you learns from, and the languages it shows.'),
						}]
					: []),
				{
					id: 'sensitive',
					group: 'reading',
					icon: 'IconSensitive',
					component: 'SensitiveMediaSettings',
					title: t('social', 'Sensitive media'),
					lede: t('social', 'How photos and videos their author marked sensitive are shown to you.'),
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
					id: 'notifications',
					group: 'notifications',
					icon: 'IconBellRing',
					component: 'NotificationDeliverySettings',
					title: t('social', 'When to tell you'),
					lede: t('social', 'Right away or once a day, and the hours to stay quiet.'),
				},
				{
					id: 'apps',
					group: 'apps',
					icon: 'IconApps',
					component: 'AuthorizedApps',
					title: t('social', 'Authorized apps'),
					lede: t('social', 'Apps signed in to your account, such as a phone client. Sign out the ones you no longer use, or all of them after losing a phone.'),
				},
				// Files is not open to a self-registered external user
				...(external
					? []
					: [{
							id: 'files-comments',
							group: 'apps',
							icon: 'IconFilesComments',
							component: 'FilesCommentsSettings',
							title: t('social', 'Replies in Files'),
							lede: t('social', 'A photo you post from Files keeps a link to its file. Replies to the post can be read in the file\'s Comments tab, next to the photo they are about.'),
						}]),
				// a self-registered external user's media quota
				...(external
					? [{
							id: 'storage',
							group: 'apps',
							icon: 'IconStorage',
							component: 'ExternalStorage',
							title: t('social', 'Your storage'),
							lede: t('social', 'The photos and videos you uploaded here, against what this server lets each account keep. Media from other servers does not count.'),
						}]
					: []),
				// where this server gives every account a Bluesky identity
				...(bluesky
					? [
							{
								id: 'bluesky',
								group: 'bluesky',
								icon: 'IconBluesky',
								component: 'BlueskySettings',
								title: t('social', 'Your Bluesky account'),
								lede: t('social', 'This account is also reachable on Bluesky, because this server offers one.'),
							},
							{
								id: 'bluesky-feeds',
								group: 'bluesky',
								icon: 'IconBluesky',
								component: 'BlueskyFeedsSettings',
								title: t('social', 'Bluesky feeds'),
								lede: t('social', 'Custom feeds and lists from Bluesky, read here as timelines of their own. A Bluesky app signed in to this account keeps the same ones.'),
							},
						]
					: []),
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
					title: t('social', 'Account and privacy'),
					description: t('social', 'Who can find and contact you, what you blocked, and moving or deleting the account.'),
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
					title: t('social', 'Activities'),
					description: t('social', 'When the app tells you what happened.'),
				},
				{
					id: 'apps',
					icon: 'IconSecurity',
					title: t('social', 'Apps'),
					description: t('social', 'Apps signed in as you, and Files.'),
				},
				{
					id: 'bluesky',
					icon: 'IconBluesky',
					title: t('social', 'Bluesky'),
					description: t('social', 'Your Bluesky account and the Bluesky feeds you read.'),
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
			return this.sections.filter((section) => matchesQuery(this.query, `${section.title} ${section.lede} ${this.groupOf(section.id).title}`))
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
		 * Puts the section named in the address in view, opening its group first.
		 * Vue Router leaves the hash alone on a mounted page, and the section may
		 * not have been drawn yet.
		 */
		scrollToSection() {
			const id = (this.$route?.hash ?? '').replace(/^#/, '')
			if (id === '' || this.scrolledTo === id) {
				return
			}

			// a link to a part of a section opens that section
			const sectionId = INNER_ANCHORS[id] ?? id
			const group = this.groupOf(sectionId)
			if (group.sections.some((section) => section.id === sectionId) && (this.openedGroup !== group.id || this.searching)) {
				this.query = ''
				this.openedGroup = group.id
				// drawn on the next update, which calls this again
				return
			}
			const section = document.getElementById(id) ?? document.getElementById(sectionId)
			if (!section || typeof section.scrollIntoView !== 'function') {
				return
			}
			this.scrolledTo = id
			this.current = sectionId
			// the first section of a group scrolls to the page top, keeping the group's
			// heading in view
			const target = id === group.sections[0].id ? this.$el : section
			target.scrollIntoView?.({ block: 'start' })
			// the sections above it may still be arriving and push it down; scroll
			// again once
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
		 * Rebuilt on `updated`, as sections arrive with their chunk or another
		 * group.
		 */
		watchSections() {
			this.observer?.disconnect()
			this.observer = watchSections(this.shownSections.map((section) => section.id), () => this.markCurrent())
			this.markCurrent()
		},

		/**
		 * Marks the section the reader is looking at: the first one until any
		 * heading has passed the top.
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
 * The sections arrive one after another when the page or another group
 * opens; the delay is capped, and off for reduced motion.
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
	max-width: 1120px;
	margin-inline: auto;

	&__search {
		flex: 0 1 320px;
		min-width: min(220px, 100%);
	}

	/* a switch is set in by its own padding, which keeps the hover's shape;
	   pulled back by it, the switch starts where the section's text and
	   fields do */
	&__section :deep(.checkbox-radio-switch-switch) {
		margin-inline-start: calc((var(--default-clickable-area) - 16px) / -2);
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

	// the one section whose button cannot be undone is marked as such
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

/* once the search wraps under the title it takes the whole row */
@include layout.below(layout.$phone) {
	.settings__search {
		flex-basis: 100%;
	}
}

/* the page is wide enough to reach the corner where the sidebar's toggle
   floats, so it starts beside it */
@include layout.from(layout.$folded) {
	.settings {
		padding-inline-start: calc(var(--default-clickable-area, 44px) + $gutter);
	}
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
