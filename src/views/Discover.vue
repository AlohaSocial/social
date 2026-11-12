<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="discover">
		<PageHeader :title="t('social', 'Discover')" clearNavigation />

		<!-- the same control the timelines and a profile are switched with:
		     four lists of one screen, chosen while reading rather than
		     navigated to -->
		<TimelineSwitcher
			:options="tabs"
			:value="active"
			:label="t('social', 'What to discover')"
			@update:value="select" />

		<!-- above the counted lists, because it is the answer to "what is here"
		     that a visitor can act on: curated, and saying so -->
		<DiscoverCategories />

		<div v-if="error" class="discover__error" role="alert">
			<p>{{ error }}</p>
			<NcButton variant="primary" :disabled="loading || packLoading" @click="retry">
				<template #icon>
					<Refresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<template v-else-if="active === 'posts' || active === 'videos'">
			<ProfileMediaGrid
				v-if="media.length || loading"
				:posts="media"
				account=""
				:loading="loading" />
			<NcEmptyContent
				v-else
				:name="t('social', 'Nothing to discover yet')"
				:description="emptyMediaDescription">
				<template #icon>
					<Compass />
				</template>
			</NcEmptyContent>
		</template>

		<!-- the hashtags tab loads, ranks and follows on its own: which stretch
		     of time it means is a question only this list has -->
		<template v-else-if="active === 'tags'">
			<TrendingHashtags />
			<BlueskyTrends v-if="bluesky" />
		</template>

		<template v-else-if="active === 'packs'">
			<!-- one pack open: its accounts, with a follow-all -->
			<div v-if="openPack" class="discover__pack">
				<NcButton variant="tertiary" @click="closePack">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t('social', 'All starter packs') }}
				</NcButton>

				<StarterPackDetail
					ref="pack"
					:key="openPack.id"
					:pack="packView"
					:headingLevel="2"
					:loading="packLoading"
					:canFollow="!visitor"
					:followMembers="followPackMembers"
					:followMember="followPackMember">
					<!-- a pack that quietly shrinks looks like one somebody wrote
					     badly; say which handles could not be reached, and why it
					     is worth trying again -->
					<div v-if="openPack.unresolved && openPack.unresolved.length" class="discover__unresolved">
						<p>
							{{ unresolvedNotice }}
							<span class="discover__unresolved-handles">{{ openPack.unresolved.join(', ') }}</span>
						</p>
						<p class="discover__unresolved-why">
							{{ t('social', 'Their server did not answer. Often it is only busy for a moment. If this Nextcloud cannot be reached from the internet, some servers never answer it.') }}
						</p>
						<NcButton variant="tertiary" :disabled="packLoading" @click="loadPack(openPack.id)">
							<template #icon>
								<NcLoadingIcon v-if="packLoading" :size="20" />
								<Refresh v-else :size="20" />
							</template>
							{{ t('social', 'Try again') }}
						</NcButton>
					</div>
				</StarterPackDetail>
			</div>

			<ul v-else-if="packs.length" class="discover__packs">
				<li v-for="pack in packs" :key="pack.id">
					<button
						ref="cards"
						type="button"
						class="discover__pack-card"
						:data-pack-id="pack.id"
						@click="loadPack(pack.id)">
						<span class="discover__pack-card-name">{{ pack.name }}</span>
						<span class="discover__pack-card-description">{{ pack.description }}</span>
						<span v-if="pack.curator" class="discover__pack-card-curator">
							{{ t('social', 'Curated by {curator}', { curator: '@' + pack.curator }) }}
						</span>
						<span class="discover__pack-card-size">
							{{ n('social', '%n account', '%n accounts', pack.size) }}
						</span>
					</button>
				</li>
			</ul>

			<NcEmptyContent
				v-else-if="!loading"
				:name="t('social', 'No starter packs')"
				:description="emptyPacksDescription">
				<template #icon>
					<PackageVariantClosed />
				</template>
			</NcEmptyContent>
		</template>

		<template v-else>
			<!--
				Three lists of people, each answering a different question, and
				before this they were stacked with nothing between them: a
				search box, a heading, and a bare grid. A reader could not tell
				why somebody was in one list rather than another, which is the
				only thing that makes a list of strangers worth reading.
			-->
			<section class="discover__section">
				<h2 class="discover__section-title">
					{{ t('social', 'Your island and its neighbours') }}
				</h2>
				<p class="discover__section-hint">
					{{ t('social', 'Every server in the fediverse is an island. Following somebody on another one opens a canoe route to it, and their posts travel to you along that route.') }}
				</p>
				<FederationIslands />
			</section>

			<section class="discover__section">
				<h2 class="discover__section-title">
					{{ t('social', 'Search the fediverse') }}
				</h2>
				<p class="discover__section-hint">
					{{ bluesky
						? t('social', 'Names and handles in public directories, including people on servers this one has never met. Somebody who is not listed is found by their full handle, like @someone@example.org or @someone.example.com, in the search at the top of the sidebar.')
						: t('social', 'Names and handles in public directories, including people on servers this one has never met. Somebody who is not listed is found by their full handle, like @someone@example.org, in the search at the top of the sidebar.') }}
				</p>
				<FediverseSearch />
			</section>

			<section class="discover__section">
				<h2 class="discover__section-title">
					{{ t('social', 'Because of who you follow') }}
				</h2>
				<p class="discover__section-hint">
					{{ t('social', 'The people you follow follow other people. This asks their servers who, and puts the ones several of them share first.') }}
				</p>
				<FollowGraphSuggestions />
			</section>

			<section v-if="bluesky" class="discover__section">
				<h2 class="discover__section-title">
					{{ t('social', 'Suggested on Bluesky') }}
				</h2>
				<p class="discover__section-hint">
					{{ t('social', 'People Bluesky suggests to you, from who you follow there. Follow them here like anybody else.') }}
				</p>
				<BlueskySuggestions />
			</section>

			<section class="discover__section">
				<h2 class="discover__section-title">
					{{ t('social', 'People this server knows') }}
				</h2>
				<p class="discover__section-hint">
					{{ t('social', 'Accounts on this Nextcloud, and the ones it has met through the people here.') }}
				</p>

				<ul v-if="accounts.length" class="discover__people">
					<PersonCard
						v-for="account in accounts"
						:key="account.id"
						:account="account"
						link
						:reason="isColleague(account) ? t('social', 'On your Nextcloud') : ''"
						:followed="isFollowed(account)"
						:pending="isPending(account)"
						@follow="followPerson" />
				</ul>
				<NcEmptyContent
					v-else-if="!loading"
					:name="t('social', 'Nobody here yet')"
					:description="t('social', 'Accounts this server has met will appear here. Until it has met any, the search above asks other servers’ directories instead.')">
					<template #icon>
						<AccountMultipleOutline />
					</template>
				</NcEmptyContent>
			</section>
		</template>

		<NcLoadingIcon
			v-if="loading && active !== 'posts' && active !== 'videos'"
			class="discover__loading"
			:size="32" />
	</div>
</template>

<script>
import PageHeader from '../components/PageHeader.vue'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Compass from 'vue-material-design-icons/Compass.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ImageMultiple from 'vue-material-design-icons/ImageMultiple.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import PlayBoxMultiple from 'vue-material-design-icons/PlayBoxMultiple.vue'
import Pound from 'vue-material-design-icons/Pound.vue'
import ProfileMediaGrid from '../components/ProfileMediaGrid.vue'
import TimelineSwitcher from '../components/TimelineSwitcher.vue'
import DiscoverCategories from '../components/DiscoverCategories.vue'
import FederationIslands from '../components/FederationIslands.vue'
import FediverseSearch from '../components/FediverseSearch.vue'
import PersonCard from '../components/PersonCard.vue'
import StarterPackDetail from '../components/StarterPackDetail.vue'
import { useFollowByHandle } from '../composables/useFollowByHandle.js'
import FollowGraphSuggestions from '../components/FollowGraphSuggestions.vue'
import TrendingHashtags from '../components/TrendingHashtags.vue'
import BlueskySuggestions from '../components/BlueskySuggestions.vue'
import BlueskyTrends from '../components/BlueskyTrends.vue'
import { useAccountStore } from '../store/account.js'
import { useSettingsStore } from '../store/settings.js'
import { isHandleOf } from '../utils/accountLocality.js'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import axios from '@nextcloud/axios'
import logger from '../services/logger.js'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '@nextcloud/l10n'

/**
 * Discover: who to follow, and what is being looked at.
 *
 * People comes first, because it is the question somebody opening this page
 * actually has. A timeline is empty until you follow somebody, and "who?" is
 * unanswerable from a graph you are not yet part of -- which is what the starter
 * packs are for: a human answer where the suggestion engine structurally has
 * none.
 *
 * Every one of these was already answered by the server and none of them was
 * reachable from the interface -- the whole discovery backend existed and there
 * was no page that asked it anything. This is that page.
 *
 * Each tab is loaded when it is first opened and then kept, so flicking between
 * them does not re-ask. A failure is its own state with a retry, rather than an
 * empty list that cannot be told apart from "there is nothing here".
 */
export default {
	name: 'Discover',

	components: {
		PageHeader,
		AccountMultipleOutline,
		BlueskySuggestions,
		BlueskyTrends,
		ArrowLeft,
		Compass,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		PackageVariantClosed,
		ProfileMediaGrid,
		TimelineSwitcher,
		DiscoverCategories,
		FederationIslands,
		FediverseSearch,
		FollowGraphSuggestions,
		PersonCard,
		StarterPackDetail,
		TrendingHashtags,
		Refresh,
	},

	setup() {
		// the same follow-by-handle the search and the suggestions use: these
		// rows carry a handle and no relationship, which is precisely what
		// `FollowButton` cannot draw
		return useFollowByHandle()
	},

	data() {
		return {
			active: 'accounts',
			loading: false,
			error: null,
			/**
			 * Which tab's answer is still wanted. `loading` and `error` are one
			 * pair shared by every tab, so a request left in flight by a reader
			 * moving on would otherwise put its spinner or its message over the
			 * tab they moved to.
			 */
			asked: 0,
			posts: [],
			videos: [],
			accounts: [],
			packs: [],
			/** which pack is open, and the accounts it resolved to */
			openPack: null,
			packLoading: false,
			/** the pack that failed to open, which "Try again" opens again */
			failedPack: '',
			/** which tabs have been asked for, so a second visit does not re-ask */
			loaded: [],
		}
	},

	computed: {
		/** @return {string} the heading over the accounts the open pack could not resolve */
		unresolvedNotice() {
			return n('social', 'One account could not be reached from this server:', '%n accounts could not be reached from this server:', this.openPack?.unresolved?.length ?? 0)
		},

		/** @return {boolean} whether the reader is signed out, and so cannot follow */
		visitor() {
			return useSettingsStore().getServerData?.public === true
		},

		/** @return {string} what an empty pack list says; how to add packs only to an administrator */
		emptyPacksDescription() {
			return useSettingsStore().getServerData?.isAdmin === true
				? t('social', 'Add some with the starter_packs app setting, or read them in from other servers with starter_pack_sources.')
				: t('social', 'This server does not suggest any starter packs yet.')
		},

		/**
		 * The open pack as `StarterPackDetail` draws one: a member is keyed
		 * by account id, which is what the relationships are kept under.
		 *
		 * @return {object}
		 */
		packView() {
			const pack = this.openPack
			const accountStore = useAccountStore()
			const self = accountStore.currentAccount?.id
			return {
				name: pack.name,
				description: pack.description ?? '',
				curator: pack.curator ? '@' + pack.curator : '',
				topic: pack.topic ?? '',
				url: pack.url ?? '',
				members: (pack.accounts ?? []).map((account) => {
					const relationship = accountStore.getRelationshipWith(account.id)
					return {
						key: String(account.id),
						acct: account.acct,
						name: account.display_name || account.username,
						actor: account,
						following: relationship?.following === true || relationship?.requested === true,
						self: self !== undefined && String(account.id) === String(self),
					}
				}),
			}
		},

		/** @return {boolean} whether this server gives accounts a Bluesky identity */
		bluesky() {
			return useSettingsStore().getServerData?.bluesky?.enabled === true
		},

		/**
		 * Pictures and Videos are one grid asked two questions, so the template
		 * reads whichever list the tab on screen stands for rather than naming
		 * one of them.
		 *
		 * @return {object[]} the posts to draw
		 */
		media() {
			return (this.active === 'videos') ? this.videos : this.posts
		},

		/** @return {string} what an empty grid should say it is empty of */
		emptyMediaDescription() {
			return (this.active === 'videos')
				? t('social', 'Videos people are watching will appear here.')
				: t('social', 'Pictures people are looking at will appear here.')
		},

		tabs() {
			// no `to` on any of them: the switcher pushes a route where an
			// option has one and hands the pick back where it does not, and
			// which of these four is on screen is this page's own state
			return [
				// `short` is what a phone shows instead of the icons, which
				// alone do not say which list is which
				{ value: 'accounts', label: t('social', 'People'), short: t('social', 'People'), icon: AccountMultipleOutline },
				{ value: 'packs', label: t('social', 'Starter packs'), short: t('social', 'Packs'), icon: PackageVariantClosed },
				{ value: 'posts', label: t('social', 'Pictures'), short: t('social', 'Photos'), icon: ImageMultiple },
				{ value: 'videos', label: t('social', 'Videos'), short: t('social', 'Videos'), icon: PlayBoxMultiple },
				{ value: 'tags', label: t('social', 'Hashtags'), short: t('social', 'Tags'), icon: Pound },
			]
		},
	},

	beforeMount() {
		this.load(this.active)
	},

	methods: {
		t,
		n,

		select(tab) {
			this.active = tab
			this.error = null
			this.failedPack = ''
			this.load(tab)
		},

		/** Asks again for what failed: the pack that would not open, or the tab. */
		retry() {
			if (this.failedPack !== '') {
				this.loadPack(this.failedPack)
			} else {
				this.load(this.active, true)
			}
		},

		/**
		 * @param {string} tab which tab to fill
		 * @param {boolean} force ask again even if it has been asked once
		 */
		async load(tab, force = false) {
			// the hashtags ask for themselves, per window
			if (tab === 'tags' || (!force && this.loaded.includes(tab))) {
				return
			}

			this.loading = true
			this.error = null
			this.failedPack = ''
			this.asked += 1
			const generation = this.asked
			try {
				if (tab === 'posts') {
					// `media` is this app's own parameter: without it the route
					// answers with every post that has an attachment, which is
					// Pixelfed's meaning of it and would put the same video in
					// both grids
					this.posts = await this.get('api/v2/discover/posts?media=image')
				} else if (tab === 'videos') {
					this.videos = await this.get('api/v2/discover/posts?media=video')
				} else if (tab === 'packs') {
					this.packs = await this.get('api/v1/starter_packs')
				} else {
					// the account, with where the suggestion came from kept on
					// it: a colleague is worth saying so
					this.accounts = await this.get('api/v2/suggestions')
						.then((suggestions) => suggestions
							.map((suggestion) => suggestion.account
								? { ...suggestion.account, sources: suggestion.sources ?? [] }
								: suggestion)
							.filter((account) => account && account.acct))
				}

				if (!this.loaded.includes(tab)) {
					this.loaded.push(tab)
				}
			} catch (error) {
				logger.error('Could not load the discover page', { error, tab })
				if (generation === this.asked) {
					this.error = t('social', 'Could not load this. The server may be busy.')
				}
			} finally {
				// the newer request owns the spinner; turning it off here would
				// say the tab on screen had finished loading when it has not
				if (generation === this.asked) {
					this.loading = false
				}
			}
		},

		/**
		 * Opens one pack, which is where the handles are resolved.
		 *
		 * The index deliberately resolves nobody — turning a handle into a
		 * profile is a WebFinger lookup and an actor fetch against somebody
		 * else's server, so the cost is paid here, when a pack is actually
		 * opened, rather than on the way past.
		 *
		 * @param {string} slug the pack's id
		 */
		async loadPack(slug) {
			this.packLoading = true
			this.error = null
			this.failedPack = ''
			this.asked += 1
			const generation = this.asked
			const opening = this.openPack?.id !== slug
			// shown at once so the name and description are on screen while the
			// accounts are still being fetched
			this.openPack = this.packs.find((pack) => pack.id === slug) ?? null
			if (opening) {
				this.$nextTick(() => /** @type {{focusHeading: () => void}|undefined} */ (this.$refs.pack)?.focusHeading())
			}
			try {
				const { data } = await axios.get(generateUrl(`apps/social/api/v1/starter_packs/${slug}`))
				if (generation !== this.asked) {
					return
				}

				this.openPack = data
				if (!this.visitor) {
					// whom the reader follows already; the store says so itself
					// when it cannot tell
					await useAccountStore().fetchAccountRelationshipInfo((data.accounts ?? []).map((account) => account.id))
				}
			} catch (error) {
				logger.error('Could not open the starter pack', { error, slug })
				// a pack the reader has already closed, or left for another
				// one, must not take the open one off the screen
				if (generation === this.asked) {
					this.error = t('social', 'Could not open this pack. The server may be busy.')
					this.failedPack = slug
					this.openPack = null
				}
			} finally {
				if (generation === this.asked) {
					this.packLoading = false
				}
			}
		},

		/** Back to the list, with the focus on the pack that was open. */
		closePack() {
			const slug = this.openPack?.id
			if (this.packLoading) {
				// the pack still being read must not open again once it arrives
				this.asked += 1
				this.packLoading = false
			}
			this.openPack = null
			this.error = null
			this.failedPack = ''
			this.$nextTick(() => {
				const cards = /** @type {HTMLElement[]|undefined} */ (this.$refs.cards) ?? []
				cards.find((card) => card.dataset.packId === slug)?.focus()
			})
		},

		/**
		 * Whether a suggestion came from a profile on this Nextcloud. Mastodon
		 * calls that source `featured`; see Suggestion::SOURCE_COLLEAGUES.
		 *
		 * @param {object} account the suggested account, with its `sources`
		 * @return {boolean}
		 */
		/**
		 * Follows somebody from the list of accounts this server knows.
		 *
		 * By handle, like every other list of people on this page: these rows
		 * are accounts the server has met rather than accounts the reader has
		 * a relationship with, so there is nothing for `FollowButton` to read.
		 *
		 * @param {object} account the row's account
		 * @return {Promise<void>} when the request has settled
		 */
		async followPerson(account) {
			await this.follow(account)
		},

		isColleague(account) {
			return Array.isArray(account.sources) && account.sources.includes('featured')
		},

		/**
		 * Follows everyone in the open pack who can be reached.
		 *
		 * The server skips whoever it cannot reach rather than failing the lot,
		 * and answers with the handles it followed; the members asked for and
		 * not among them are the ones that failed.
		 *
		 * @param {object[]} members whom to follow, as `StarterPackDetail` names them
		 * @param {boolean} feeds unused: these packs carry no feeds
		 * @param {(done: number) => void} progress told how many have been asked for
		 * @return {Promise<{followed: string[], failed: string[]}>} the members' keys
		 */
		async followPackMembers(members, feeds, progress) {
			const { data } = await axios.post(generateUrl(`apps/social/api/v1/starter_packs/${this.openPack.id}/follow`))
			progress(members.length)
			const handles = Array.isArray(data?.followed) ? data.followed : []
			const followed = members
				.filter((member) => handles.some((handle) => isHandleOf(handle, member.actor)))
				.map((member) => member.key)

			return { followed, failed: members.map((member) => member.key).filter((key) => !followed.includes(key)) }
		},

		/**
		 * @param {object} member one member, as `StarterPackDetail` names them
		 * @return {Promise<boolean>} whether they are followed now
		 */
		async followPackMember(member) {
			await this.follow(member.actor)

			return this.isFollowed(member.actor)
		},

		/**
		 * @param {string} path the route to ask, without the app prefix
		 * @return {Promise<Array>} whatever it answered, as an array
		 */
		async get(path) {
			const { data } = await axios.get(generateUrl(`apps/social/${path}`))

			return Array.isArray(data) ? data : []
		},

	},
}
</script>

<style scoped lang="scss">
.discover {
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: 1000px;
	margin-inline: auto;

	&__error {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		padding: calc(var(--default-grid-baseline) * 4);
	}

	/*
	 * The pack list had markup and no rules at all: a `<button>` with three
	 * spans in it, which the browser drew as one centred, bold, accent-coloured
	 * line — name, description and count run together — and which read as a
	 * page that had failed to load rather than as a list of packs.
	 */
	/* three lists of people, each with a heading that says which question it
	   answers: stacked without them, a reader cannot tell why somebody is in
	   one list rather than another */
	&__section {
		padding-block-end: calc(var(--default-grid-baseline) * 4);
		border-block-end: 1px solid var(--color-border);
		margin-block-end: calc(var(--default-grid-baseline) * 4);

		&:last-child {
			border-block-end: none;
			margin-block-end: 0;
			padding-block-end: 0;
		}
	}

	&__section-title {
		font-size: 1.15em;
		font-weight: bold;
		margin-block-end: var(--default-grid-baseline);
	}

	&__section-hint {
		color: var(--color-text-maxcontrast);
		margin-block-end: calc(var(--default-grid-baseline) * 3);
		max-width: 62ch;
		line-height: 1.4;
	}

	&__people {
		list-style: none;
		display: grid;
		/* one column on a phone, two where there is room: a person's row needs
		   about thirty characters before the handle starts truncating */
		grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
		gap: var(--default-grid-baseline);
	}

	&__packs {
		list-style: none;
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__pack-card {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
		width: 100%;
		height: 100%;
		/* a button in a card's clothing: the defaults it arrives with are for a
		   word on a control, not for a paragraph in a tile */
		text-align: start;
		font-size: inherit;
		font-weight: normal;
		color: var(--color-main-text);
		background-color: var(--color-background-hover);
		border: none;
		border-radius: var(--border-radius-large);
		padding: calc(var(--default-grid-baseline) * 3);
		cursor: pointer;
		transition: background-color .15s ease;

		&:hover,
		&:focus-visible {
			background-color: var(--color-background-dark);
		}
	}

	&__pack-card-name {
		font-weight: bold;
		font-size: 1.05em;
	}

	&__pack-card-description {
		color: var(--color-text-maxcontrast);
		line-height: 1.4;
	}

	&__pack-card-curator {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 0.85em);
		overflow-wrap: anywhere;
	}

	&__pack-card-size {
		margin-top: auto;
		padding-top: var(--default-grid-baseline);
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 0.85em);
	}

	/* and the pack itself, once one is open */
	&__pack {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__unresolved {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 0.85em);

		span {
			display: block;
		}
	}

	&__colleague {
		margin-inline-start: auto;
		flex: none;
		padding: 2px 8px;
		border-radius: 999px;
		font-size: 12px;
		color: var(--color-primary-element-light-text);
		background: var(--color-primary-element-light);
	}

	&__loading {
		margin: 24px auto;
	}
}

/* the one thing on this page that moves, and a reader may have asked for
   nothing to */
@media (prefers-reduced-motion: reduce) {
	.discover__pack-card {
		transition: none;
	}
}
</style>
