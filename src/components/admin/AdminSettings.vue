<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social-admin">
		<header class="social-admin__header">
			<h1 class="social-admin__heading">
				{{ t('social', 'Social') }}
			</h1>
			<p class="social-admin__lede">
				{{ t('social', 'Everything this server decides for itself: what is moderated, what people are shown, what is kept, and who it federates with.') }}
			</p>
		</header>

		<div class="social-admin__body">
			<!-- Seventeen cards is more than a page can present as a list, and
			     the one an administrator came for was somewhere in a long
			     scroll. The rail is that scroll, grouped and with the card they
			     are in marked. Gone on a narrow screen rather than stacked
			     above the thing it describes. -->
			<nav class="social-admin__rail" :aria-label="t('social', 'On this page')">
				<template v-for="group in groups" :key="group.name">
					<p v-if="group.cards.length" class="social-admin__rail-caption">
						{{ group.name }}
					</p>
					<ul v-if="group.cards.length" class="social-admin__rail-list">
						<li v-for="card in group.cards" :key="card.id">
							<a
								:href="`#${card.id}`"
								class="social-admin__rail-link"
								:class="{ 'social-admin__rail-link--current': current === card.id }"
								:aria-current="current === card.id ? 'true' : undefined"
								@click="jumpTo(card.id, $event)">
								{{ card.title }}
							</a>
						</li>
					</ul>
				</template>
			</nav>

			<div class="social-admin__cards">
				<section v-for="group in groups" :key="group.name" class="social-admin__group">
					<h3 v-if="group.cards.length" class="social-admin__group-name">
						{{ group.name }}
					</h3>

					<div v-if="group.cards.length" class="social-admin__group-cards">
						<div
							v-for="(card, index) in group.cards"
							:id="card.id"
							:key="card.id"
							class="social-admin__card"
							:style="{ '--entry-index': index }">
							<ActivitySection v-if="card.id === 'activity'" :activity="state.activity" />
							<ExternalUsersSection
								v-else-if="card.id === 'external'"
								:external="state.external"
								@changed="onExternalChanged" />
							<ExternalRequestsSection
								v-else-if="card.id === 'external-requests'"
								:external="state.external"
								@changed="onExternalChanged" />
							<ExternalAccountsSection
								v-else-if="card.id === 'external-accounts'"
								:external="state.external"
								@changed="onExternalChanged" />
							<ReportsSection
								v-else-if="card.id === 'reports'"
								:reports="state.reports"
								:openTotal="state.openReports"
								:resolvedTotal="state.resolvedReports"
								:perPage="state.reportsPerPage" />
							<ReviewSection
								v-else-if="card.id === 'review'"
								:queue="state.review"
								:total="state.reviewTotal"
								:reviewFirstPost="state.reviewFirstPost"
								:autospam="state.autospam"
								:reviewVideos="state.reviewVideos" />
							<AccountsSection v-else-if="card.id === 'accounts'" />
							<MediaBlocksSection v-else-if="card.id === 'media'" />
							<RulesSection v-else-if="card.id === 'rules'" />
							<DiscoverSection v-else-if="card.id === 'discover'" />
							<TrendsSection v-else-if="card.id === 'trends'" />
							<EmojiSection v-else-if="card.id === 'emoji'" />
							<AnnouncementsSection v-else-if="card.id === 'announcements'" />
							<SectionsSection
								v-else-if="card.id === 'sections'"
								:settings="state.sections"
								:groups="state.groups ?? []" />
							<InterestsSection v-else-if="card.id === 'interests'" :settings="state.interests" />
							<RetentionSection v-else-if="card.id === 'retention'" :days="state.retentionDays" />
							<StorageSection
								v-else-if="card.id === 'storage'"
								:storage="state.storage"
								:videoStorage="state.videoStorage" />
							<FederationSection v-else-if="card.id === 'federation'" :federation="state.federation" />
							<BackgroundSection v-else-if="card.id === 'background'" :background="state.background" />
							<AccessSection
								v-else-if="card.id === 'access'"
								:accessType="state.accessType"
								:addresses="state.accessList" />
							<BlocklistSection v-else-if="card.id === 'blocklist'" @changed="onListChanged" />
							<RelaysSection v-else-if="card.id === 'relays'" />
							<ServerSection v-else-if="card.id === 'server'" :settings="state.server" />
						</div>
					</div>
				</section>
			</div>
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { currentSection, scrollToSection, watchSections } from '../../services/sectionRail.js'
import AccessSection from './AccessSection.vue'
import BlocklistSection from './BlocklistSection.vue'
import ActivitySection from './ActivitySection.vue'
import AccountsSection from './AccountsSection.vue'
import AnnouncementsSection from './AnnouncementsSection.vue'
import DiscoverSection from './DiscoverSection.vue'
import EmojiSection from './EmojiSection.vue'
import ExternalAccountsSection from './ExternalAccountsSection.vue'
import ExternalRequestsSection from './ExternalRequestsSection.vue'
import ExternalUsersSection from './ExternalUsersSection.vue'
import BackgroundSection from './BackgroundSection.vue'
import FederationSection from './FederationSection.vue'
import InterestsSection from './InterestsSection.vue'
import MediaBlocksSection from './MediaBlocksSection.vue'
import RelaysSection from './RelaysSection.vue'
import ReportsSection from './ReportsSection.vue'
import RetentionSection from './RetentionSection.vue'
import RulesSection from './RulesSection.vue'
import ReviewSection from './ReviewSection.vue'
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
 * The twelve sections of Administration → Social.
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
		BlocklistSection,
		ActivitySection,
		AccountsSection,
		AnnouncementsSection,
		DiscoverSection,
		EmojiSection,
		ExternalAccountsSection,
		ExternalRequestsSection,
		ExternalUsersSection,
		BackgroundSection,
		FederationSection,
		InterestsSection,
		MediaBlocksSection,
		RelaysSection,
		ReportsSection,
		RetentionSection,
		RulesSection,
		ReviewSection,
		SectionsSection,
		ServerSection,
		StorageSection,
		TrendsSection,
	},

	data() {
		return {
			state: { ...NOTHING, ...loadState('social', 'adminSettings', {}) },
			/** the card the administrator is looking at, for the rail */
			current: '',
			observer: null,
		}
	},

	computed: {
		/**
		 * The page, grouped.
		 *
		 * Seventeen cards in one run is a list nobody reads to the end of;
		 * grouped, an administrator who came to deal with a report does not
		 * read past storage and federation to find it. The groups are what the
		 * cards are *for*, not what they are made of: "Reports" and "Accounts"
		 * are both moderation however differently they are built.
		 *
		 * A card a delegate is not shown drops out of its group, and a group
		 * with nothing left in it draws neither a caption nor a heading.
		 *
		 * @return {object[]} one entry per group
		 */
		groups() {
			const administrator = this.state.server !== null

			return [
				{
					name: t('social', 'Overview'),
					cards: [
						{ id: 'activity', title: t('social', 'Activity here') },
					],
				},
				{
					// who may have an account on this server: an administrator's
					// decision, so a delegate is sent none of it
					name: t('social', 'External users'),
					cards: this.state.external
						? [
								{ id: 'external', title: t('social', 'Registration') },
								{ id: 'external-requests', title: t('social', 'Waiting and invited') },
								{ id: 'external-accounts', title: t('social', 'External accounts') },
							]
						: [],
				},
				{
					name: t('social', 'Moderation'),
					cards: [
						{ id: 'reports', title: t('social', 'Reports') },
						{ id: 'review', title: t('social', 'Posts waiting') },
						{ id: 'accounts', title: t('social', 'Accounts') },
						{ id: 'media', title: t('social', 'Refused pictures') },
						{ id: 'rules', title: t('social', 'Rules') },
					],
				},
				{
					name: t('social', 'What people see'),
					cards: [
						{ id: 'discover', title: t('social', 'About this server') },
						{ id: 'trends', title: t('social', 'What may trend') },
						{ id: 'emoji', title: t('social', 'Custom emoji') },
						{ id: 'announcements', title: t('social', 'Announcements') },
						...(this.state.sections ? [{ id: 'sections', title: t('social', 'Sections') }] : []),
						// an administrator's decision, like the sections: a delegate is
						// sent no settings for it
						...(this.state.interests ? [{ id: 'interests', title: t('social', 'For you') }] : []),
					],
				},
				{
					name: t('social', 'What is kept'),
					cards: [
						{ id: 'retention', title: t('social', 'Retention') },
						{ id: 'storage', title: t('social', 'Storage') },
					],
				},
				{
					name: t('social', 'Federation'),
					cards: [
						{ id: 'federation', title: t('social', 'Federation health') },
						{ id: 'background', title: t('social', 'Background work') },
						{ id: 'access', title: t('social', 'Fediverse access') },
						...(administrator ? [{ id: 'blocklist', title: t('social', 'Block lists') }] : []),
						...(administrator ? [{ id: 'relays', title: t('social', 'Relays') }] : []),
					],
				},
				{
					name: t('social', 'Server'),
					cards: administrator ? [{ id: 'server', title: t('social', 'Server') }] : [],
				},
			]
		},

		/** @return {string[]} every card drawn, in the order it is drawn */
		cardIds() {
			return this.groups.flatMap((group) => group.cards.map((card) => card.id))
		},
	},

	mounted() {
		this.watch()
	},

	updated() {
		// a card that arrived with its data was not there to be watched when
		// the page mounted
		this.watch()
	},

	beforeUnmount() {
		this.observer?.disconnect()
	},

	methods: {
		t,

		/**
		 * What the External users cards read, after one of them changed it.
		 *
		 * @param {object} external `ExternalAdminState::current()`, or part of it
		 */
		onExternalChanged(external) {
			this.state = { ...this.state, external: { ...this.state.external, ...external } }
		},

		/**
		 * The access list after a block list was applied.
		 *
		 * The two cards read the same list, so the one above has to be told
		 * rather than left showing what it read when the page opened.
		 *
		 * @param {string[]|undefined} list the addresses now on it
		 */
		onListChanged(list) {
			if (Array.isArray(list)) {
				this.state = { ...this.state, accessList: list }
			}
		},

		/**
		 * Follows a rail link without leaving the page.
		 *
		 * The href is a real one, so the link can be copied; handling the click
		 * here scrolls the way the rest of the page does.
		 *
		 * @param {string} id the card to go to
		 * @param {MouseEvent} event the click
		 */
		jumpTo(id, event) {
			if (scrollToSection(id)) {
				event.preventDefault()
				this.current = id
			}
		},

		/** Watches the cards, and marks the one being read. */
		watch() {
			this.observer?.disconnect()
			this.observer = watchSections(this.cardIds, () => this.mark())
			this.mark()
		},

		/** Marks the card the administrator is looking at. */
		mark() {
			this.current = currentSection(this.cardIds, this.current)
		},
	},
}
</script>

<style lang="scss">
@use '../../styles/layout.scss' as layout;

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
	.social-admin__card {
		animation: section-arrive .26s ease-out both;
		animation-delay: calc(min(var(--entry-index, 0), 7) * 35ms);
	}
}

// Deliberately not scoped: the table below is the same table in four of the
// sections, and a moderator reading a page of reports should not have to learn
// a second layout halfway down it.
.social-admin {
	max-width: 1180px;
	margin-inline: auto;
	// Nextcloud puts its navigation toggle over the top left corner of the
	// settings content, where the heading would otherwise be
	padding-block-start: calc(var(--default-grid-baseline) * 6);

	&__header {
		max-width: 800px;
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

	// one column until there is room for both, so the cards keep the width
	// they read best at rather than being squeezed to make space for a rail
	&__body {
		display: grid;
		gap: calc(var(--default-grid-baseline) * 8);
		grid-template-columns: minmax(0, 800px);
	}

	&__rail {
		display: none;
	}

	&__rail-caption {
		margin: calc(var(--default-grid-baseline) * 3) 0 calc(var(--default-grid-baseline) * 1);
		padding-inline: 10px;
		font-size: 12px;
		font-weight: bold;
		text-transform: uppercase;
		letter-spacing: 0.04em;
		color: var(--color-text-maxcontrast);

		&:first-child {
			margin-block-start: 0;
		}
	}

	&__rail-list {
		display: flex;
		flex-direction: column;
		gap: 2px;
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__rail-link {
		display: block;
		padding: 5px 10px;
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
	}

	&__cards {
		min-width: 0;
	}

	&__group + &__group {
		margin-block-start: calc(var(--default-grid-baseline) * 8);
	}

	&__group-name {
		margin: 0 0 calc(var(--default-grid-baseline) * 3);
		padding-block-end: calc(var(--default-grid-baseline) * 2);
		border-block-end: 1px solid var(--color-border);
		font-size: 13px;
		font-weight: bold;
		text-transform: uppercase;
		letter-spacing: 0.04em;
		color: var(--color-text-maxcontrast);
	}

	&__group-cards {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 4);
	}

	&__card {
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 5);
		background: var(--color-main-background);
		// clear of the settings page's own sticky header when jumped to
		scroll-margin-top: calc(var(--default-grid-baseline) * 4);

		// the cards supply the frame now, so the section inside one does not
		// also pad the page away from it
		> div > :first-child {
			margin-block-start: calc(var(--default-grid-baseline) * 3);
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

@include layout.from(layout.$wide) {
	.social-admin {
		&__body {
			grid-template-columns: 232px minmax(0, 800px);
		}

		&__rail {
			display: block;
			position: sticky;
			// clear of Nextcloud's navigation toggle, which floats over the top
			// left corner of the settings content whatever is scrolled under it
			inset-block-start: calc(var(--default-grid-baseline) * 12);
			align-self: start;
			max-block-size: calc(100vh - 100px);
			overflow-y: auto;
		}
	}
}
</style>
