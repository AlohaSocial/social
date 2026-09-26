<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="settings">
		<header class="settings__header">
			<h2 class="settings__heading">
				{{ t('social', 'Settings') }}
			</h2>
			<p class="settings__lede">
				{{ t('social', 'Everything this app keeps about you and the way you use it. Nothing here changes until you change it.') }}
			</p>
		</header>

		<div class="settings__body">
			<!-- Twelve sections is more than a reader can hold in their head,
			     and the one they came for was somewhere in a long scroll. The
			     rail is that scroll as a list, with the section they are in
			     marked. It is the page's own table of contents, so it is gone
			     on a narrow screen rather than stacked above the thing it
			     describes. -->
			<nav class="settings__toc" :aria-label="t('social', 'On this page')">
				<ul class="settings__toc-list">
					<li v-for="section in sections" :key="section.id" class="settings__toc-item">
						<a
							:href="`#${section.id}`"
							class="settings__toc-link"
							:class="{
								'settings__toc-link--current': current === section.id,
								'settings__toc-link--danger': section.danger,
							}"
							:aria-current="current === section.id ? 'true' : undefined"
							@click="jumpTo(section.id, $event)">
							<component :is="section.icon" :size="16" />
							<span class="settings__toc-label">{{ section.title }}</span>
						</a>
					</li>
				</ul>
			</nav>

			<!-- Each section carries an id, because other pages link to one of
			     them: the follow requests page sends the reader to #account for
			     the switch its empty state talks about. -->
			<div class="settings__sections">
				<section
					v-for="(section, index) in sections"
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
							<h3 class="settings__section-heading">
								{{ section.title }}
							</h3>
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
import IconAccount from 'vue-material-design-icons/AccountCircleOutline.vue'
import IconApps from 'vue-material-design-icons/KeyOutline.vue'
import IconArchive from 'vue-material-design-icons/ArchiveOutline.vue'
import IconDelete from 'vue-material-design-icons/DeleteOutline.vue'
import IconInterests from 'vue-material-design-icons/TagHeartOutline.vue'
import IconIntroduction from 'vue-material-design-icons/HandWaveOutline.vue'
import IconKeyboard from 'vue-material-design-icons/KeyboardOutline.vue'
import IconLists from 'vue-material-design-icons/FormatListBulleted.vue'
import IconPortfolio from 'vue-material-design-icons/ImageMultipleOutline.vue'
import IconRecap from 'vue-material-design-icons/CalendarMonthOutline.vue'
import IconReview from 'vue-material-design-icons/ShieldAlertOutline.vue'
import IconScheduled from 'vue-material-design-icons/ClockOutline.vue'
import IconSenses from 'vue-material-design-icons/VolumeHigh.vue'
import IconTags from 'vue-material-design-icons/Pound.vue'
import { defineAsyncComponent } from 'vue'
import { t } from '@nextcloud/l10n'
import { useServerData } from '../composables/useServerData.js'
import { currentSection, scrollToSection, watchSections } from '../services/sectionRail.js'

// Two forms of some size that nobody sees until they open this page, so they
// travel in a chunk of their own rather than in the entry every reader loads.
const AccountSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/AccountSettings.vue'))
// in the same chunk as the two forms above, and for the same reason: it is a
// switch nobody sees until they open this page, and it brings a form control
// of its own with it
const RecapSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/RecapSettings.vue'))
const ListsSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/ListsSettings.vue'))
// same chunk again: forms and text fields nobody sees until they open this page
const FeaturedTagsSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/FeaturedTagsSettings.vue'))
// and again: the cloud, its popovers and the language picker are only ever
// drawn on this page
const InterestsSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/InterestsSettings.vue'))
// and again: two switches and a button, only ever drawn on this page
const SensesSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/SensesSettings.vue'))

/**
 * Settings: what this app holds about how the reader uses it.
 *
 * A page rather than a dialog. The shortcuts were already a dialog — `?` opens
 * it over whatever you were reading, which is right for a reminder mid-scroll
 * and wrong for a place you go to change things. What arrives here later is
 * another section, so the page is a list of sections from the start rather
 * than a page about shortcuts that has to be taken apart the first time it
 * gains a second subject.
 *
 * The sections are described rather than written out one by one: the rail
 * needs the same title and id the section does, and the two drifted apart the
 * first time one was renamed in only one of the places.
 */
export default {
	name: 'Settings',

	components: {
		AccountSettings,
		ArchivedPosts,
		AuthorizedApps,
		DeleteAccount,
		FeaturedTagsSettings,
		HeldPosts,
		InterestsSettings,
		IntroductionSettings,
		IconAccount,
		IconApps,
		IconArchive,
		IconDelete,
		IconInterests,
		IconIntroduction,
		IconKeyboard,
		IconLists,
		IconPortfolio,
		IconRecap,
		IconReview,
		IconScheduled,
		IconSenses,
		IconTags,
		ListsSettings,
		PortfolioSettings,
		RecapSettings,
		SensesSettings,
		ScheduledPosts,
		ShortcutList,
	},

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	data() {
		return {
			/** the section already scrolled to, so it is not chased twice */
			scrolledTo: '',
			/** the id of the section the reader is looking at, for the rail */
			current: '',
			observer: null,
		}
	},

	computed: {
		/**
		 * The page, in the order it is read.
		 *
		 * The order is deliberate: the account first, the things it holds in
		 * the middle, then what is done to the account as a whole. The
		 * shortcuts are reference rather than a setting — nothing there is
		 * changed, it is a list to look something up in — so they sit near the
		 * end, and the deletion is last because it is the one thing on this
		 * page that cannot be undone.
		 *
		 * @return {object[]} one entry per section
		 */
		sections() {
			return [
				{
					id: 'account',
					icon: 'IconAccount',
					component: 'AccountSettings',
					title: t('social', 'Your account'),
					lede: t('social', 'How others find you, and who sees what you post.'),
				},
				{
					id: 'featured-tags',
					icon: 'IconTags',
					component: 'FeaturedTagsSettings',
					title: t('social', 'Featured hashtags'),
					lede: t('social', 'The hashtags you want your profile to be known for. They sit under your bio, and anybody can click one to read what you posted with it.'),
				},
				// only where the administrator has the feature on: without it
				// there is no feed for the list to shape
				...(this.serverData?.interests?.enabled === true
					? [{
							id: 'interests',
							icon: 'IconInterests',
							component: 'InterestsSettings',
							title: t('social', 'My interests'),
							lede: t('social', 'The hashtags Social has noticed you reading, the biggest first. They make up your My interests feed: drag one to change its place, pin it, remove it, or add your own. Nobody but you sees this.'),
						}]
					: []),
				{
					id: 'lists',
					icon: 'IconLists',
					component: 'ListsSettings',
					title: t('social', 'Lists'),
					lede: t('social', 'A list is a few of the people you follow, read as a timeline of its own. Your lists are in the sidebar.'),
				},
				{
					id: 'scheduled',
					icon: 'IconScheduled',
					component: 'ScheduledPosts',
					title: t('social', 'Scheduled posts'),
					lede: t('social', 'What you have written to be published later. Move one to another time or cancel it here.'),
				},
				{
					id: 'portfolio',
					icon: 'IconPortfolio',
					component: 'PortfolioSettings',
					title: t('social', 'Portfolio'),
					lede: t('social', 'A page of your work with its own address, to put on a CV. A profile is a feed — everything you posted, newest first, with the follow button and the boosts around it. This is the opposite: a title, a sentence, the pictures you chose, and nothing else. Anybody can read it without signing in, so only your public photos are ever on it.'),
				},
				{
					id: 'archive',
					icon: 'IconArchive',
					component: 'ArchivedPosts',
					title: t('social', 'Archived posts'),
					lede: t('social', 'Posts you have put away. They are off your profile and out of every timeline here, and nobody was told — an archived post is still on the servers that received it, which is what deleting is for. Put one back whenever you like.'),
				},
				{
					id: 'review',
					icon: 'IconReview',
					component: 'HeldPosts',
					title: t('social', 'Waiting to be looked at'),
					lede: t('social', 'Some posts are kept for a moderator to see before they go out — the first post of a new account, and posts that tripped one of this server\'s spam rules. Yours are here until somebody looks at them. You can take one back; nobody is told if you do.'),
				},
				{
					id: 'recap',
					icon: 'IconRecap',
					component: 'RecapSettings',
					title: t('social', 'Looking back'),
					lede: t('social', 'Posts you wrote on this day in earlier years appear at the top of your feed on their own. This is the other half: a note about the week just gone, if you want one.'),
				},
				{
					id: 'senses',
					icon: 'IconSenses',
					component: 'SensesSettings',
					title: t('social', 'Sound and touch'),
					lede: t('social', 'Small confirmations you hear or feel. They are kept on this device only, so a laptop at work can stay quiet while your phone taps back.'),
				},
				{
					id: 'apps',
					icon: 'IconApps',
					component: 'AuthorizedApps',
					title: t('social', 'Authorized apps'),
					lede: t('social', 'The apps you have signed in to with this account — a phone client, a cross-poster, anything that asked. Each one holds a key to your account until you take it back, so this is the page to open after losing a phone.'),
				},
				{
					id: 'introduction',
					icon: 'IconIntroduction',
					component: 'IntroductionSettings',
					title: t('social', 'Introduction'),
					lede: t('social', 'The four steps shown when your account was new: your address, people to follow, and the follows you had on another server.'),
				},
				{
					id: 'shortcuts',
					icon: 'IconKeyboard',
					component: 'ShortcutList',
					title: t('social', 'Keyboard shortcuts'),
					lede: t('social', 'The keys this app listens for while you are reading.'),
				},
				{
					id: 'delete',
					icon: 'IconDelete',
					component: 'DeleteAccount',
					title: t('social', 'Delete your Social account'),
					lede: t('social', 'Your fediverse account, gone, while your Nextcloud account stays exactly as it is. Last, and on its own, because it is the one thing on this page that cannot be undone.'),
					danger: true,
				},
			]
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
		t,

		/**
		 * Puts the section named in the address in view. Vue Router leaves the
		 * hash alone on a page that is already mounted, and the section a link
		 * points at may not have been drawn when the page first was.
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
			const section = document.getElementById(id)
			if (!section || typeof section.scrollIntoView !== 'function') {
				return
			}
			this.scrolledTo = id
			this.current = id
			section.scrollIntoView({ behavior: this.reducedMotion() ? 'auto' : 'smooth', block: 'start' })
		},

		/**
		 * Follows a rail link without leaving the page.
		 *
		 * The href is a real one, so the link can be copied and opens the right
		 * section in a new tab; handling the click here keeps the router out of
		 * it and scrolls the way the rest of the page does.
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
		 * chunk were not there to be watched when the page mounted.
		 */
		watchSections() {
			this.observer?.disconnect()
			this.observer = watchSections(this.sections.map((section) => section.id), () => this.markCurrent())
			this.markCurrent()
		},

		/** Marks the section the reader is looking at in the rail. */
		markCurrent() {
			this.current = currentSection(this.sections.map((section) => section.id), this.current)
		},

		/** @return {boolean} whether the reader asked their system for less movement */
		reducedMotion() {
			return window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/layout.scss' as layout;

/**
 * The sections arrive one after another rather than all at once.
 *
 * The same idea the timeline already uses for its posts (`--stagger-delay` in
 * TimelineEntry): a page that appears whole reads as pasted, and one that
 * assembles over a fifth of a second reads as made. The delay is capped, so a
 * page of seventeen cards does not take a second and a half to finish
 * arriving, and the whole thing is off for a reader who asked for less
 * movement.
 */
@keyframes section-arrive {
	from { opacity: 0; transform: translateY(10px); }
}

@media (prefers-reduced-motion: no-preference) {
	.settings__section {
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
		max-width: 760px;
		margin-block-end: calc(var(--default-grid-baseline) * 6);
	}

	&__heading {
		margin: 0;
	}

	&__lede {
		margin: 4px 0 0;
		color: var(--color-text-maxcontrast);
	}

	// the rail and the sections. One column until there is room for both, so
	// the sections keep the width they read best at rather than being squeezed
	// to make space for a list.
	&__body {
		display: grid;
		gap: calc(var(--default-grid-baseline) * 8);
		grid-template-columns: minmax(0, 760px);
		justify-content: center;
	}

	&__toc {
		display: none;
	}

	&__sections {
		display: flex;
		flex-direction: column;
		gap: $gutter;
		min-width: 0;
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

	// the one section on the page whose button cannot be taken back: it is
	// marked so a reader scrolling past knows before they read the words
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
		inline-size: 40px;
		block-size: 40px;
		border-radius: var(--border-radius-large);
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
	}

	&__section-title {
		min-width: 0;
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

@include layout.from(layout.$wide) {
	.settings {
		&__body {
			grid-template-columns: 224px minmax(0, 760px);
		}

		&__toc {
			display: block;
			position: sticky;
			inset-block-start: 0;
			align-self: start;
			max-block-size: calc(100vh - 120px);
			overflow-y: auto;
		}
	}
}

.settings__toc-list {
	display: flex;
	flex-direction: column;
	gap: 2px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.settings__toc-link {
	display: flex;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: 6px 10px;
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
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
		font-weight: bold;
	}

	&--danger:hover,
	&--danger:focus-visible,
	&--danger.settings__toc-link--current {
		color: var(--color-error);
	}
}

.settings__toc-label {
	min-width: 0;
	// two lines rather than an ellipsis: "Delete your Social account" is the
	// one entry that does not fit, and it is the one worth reading in full
	overflow-wrap: anywhere;
}
</style>
