<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- no account yet: nothing here may call the API, which would create the
	     account before the reader has answered the setup question -->
	<NcContent v-if="!serverData.setup && serverData.needsAccount" appName="social">
		<NcAppContent>
			<AccountSetup @created="onAccountCreated" />
		</NcAppContent>
	</NcContent>
	<NcContent v-else-if="!serverData.setup" appName="social" :class="{public: serverData.public}">
		<Navigation v-if="!serverData.public" @search="search" />
		<PhoneBar v-if="!serverData.public && phone" />
		<ShortcutHelp :open="shortcutHelpOpen" @close="shortcutHelpOpen = false" />
		<CommandPalette v-if="paletteOpen && !serverData.public" @close="paletteOpen = false" />
		<!-- one emoji picker for the page, mounted on the first reaction request:
		     even unopened it pulls in its chunk and all its emoji data -->
		<ReactionPicker v-if="firstReactionAsk" :firstAsk="firstReactionAsk" />
		<NcAppContent>
			<!-- a visitor on a public page has no sidebar; the login link carries
			     the current address so they come back to this page -->
			<div v-if="serverData.public" class="social__visitor">
				<p class="social__visitor-text">
					{{ t('social', 'You are reading this as a visitor.') }}
					<span class="social__visitor-hint">{{ t('social', 'Anybody here can be followed from Mastodon or any other fediverse app.') }}</span>
				</p>
				<NcButton variant="primary" :href="loginUrl">
					{{ t('social', 'Log in to {host}', { host: instanceHost }) }}
				</NcButton>
			</div>
			<div v-if="serverData.isAdmin && checksFailed" class="setup social__wrapper">
				<SetupChecks
					:checks="serverData.checks.checks"
					:addresses="serverData.checks.addresses"
					:clientApi="serverData.checks.clientApi || []" />
			</div>
			<!-- not keyed on the full path, which would remount the view and lose
			     the timeline's place on every route change. The views watch their
			     own route params, so the transition runs only when the view changes. -->
			<div class="social__pages">
				<router-view v-slot="{ Component }">
					<!-- no `out-in`: the two pages share one grid cell, so the incoming
					     one does not wait for the leave. The name carries the direction
					     (services/pageOrder.js); with the View Transitions API it is
					     empty and the browser animates instead. -->
					<transition :name="transitionName">
						<component :is="Component" />
					</transition>
				</router-view>
				<!-- shown while a lazily loaded page's chunk is on its way, once
				     the wait is long enough to notice -->
				<transition name="pending">
					<TimelineSkeleton
						v-if="pending"
						class="social__pending"
						:variant="pendingSkeleton.variant"
						:shape="pendingSkeleton.shape"
						standalone />
				</transition>
			</div>
		</NcAppContent>
		<!-- a video the reader left the page of while it played, going on in
		     the corner across every page of the app -->
		<MiniPlayer v-if="miniPlayer.status" :key="miniPlayer.status.id" />
	</NcContent>
	<NcContent v-else appName="social">
		<NcAppContent v-if="serverData.isAdmin" class="setup">
			<CloudAddressSetup
				:proposed="serverData.proposedCloudAddress || serverData.cliUrl || ''"
				:proposedFrom="serverData.proposedFrom || 'request'"
				@saved="onCloudAddressSaved">
				<SetupChecks
					v-if="checksFailed"
					:checks="serverData.checks.checks"
					:addresses="serverData.checks.addresses"
					:clientApi="serverData.checks.clientApi || []" />
			</CloudAddressSetup>
		</NcAppContent>
		<NcAppContent v-else class="setup">
			<p>{{ t('social', 'The Aloha Social app needs to be set up by the server administrator.') }}</p>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import { starterPackRoute } from './utils/starterPack.js'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'

import Navigation from './components/Navigation.vue'
import { isPhone, onPhoneChange } from './services/phone.js'
import ShortcutHelp from './components/ShortcutHelp.vue'
import { listenForShortcuts } from './services/shortcuts.js'
import { skeletonFor } from './utils/skeleton.js'
import { isSamePage, pageDirection } from './services/pageOrder.js'
import { canViewTransition, markDirection, startPageTransition } from './services/pageTransition.js'
import { departFor, listenForPresses } from './services/sharedElements.js'
import eventBus, { REACTION_PICK } from './services/eventBus.js'
import { miniPlayer } from './services/miniPlayer.js'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { mapStores } from 'pinia'
import { useAccountStore } from './store/account.js'
import { useSettingsStore } from './store/settings.js'
import { useTimelineStore } from './store/timeline.js'
import { useCurrentUser } from './composables/useCurrentUser.js'
import { useServerData } from './composables/useServerData.js'

// needed once in an account's life, so it is a chunk of its own
const AccountSetup = defineAsyncComponent(() => import(/* webpackChunkName: "account-setup" */'./views/AccountSetup.vue'))
// for an administrator only, while the instance is not set up
const SetupChecks = defineAsyncComponent(() => import(/* webpackChunkName: "setup" */'./components/SetupChecks.vue'))
const CloudAddressSetup = defineAsyncComponent(() => import(/* webpackChunkName: "setup" */'./components/CloudAddressSetup.vue'))
// most of a megabyte, fetched the first time somebody adds a reaction
const ReactionPicker = defineAsyncComponent(() => import(/* webpackChunkName: "reaction-picker" */'./components/ReactionPicker.vue'))
// only ever shown after a video's own page, so it travels with that page
const MiniPlayer = defineAsyncComponent(() => import(/* webpackChunkName: "watch" */'./components/MiniPlayer.vue'))
// shown only once a page has kept the reader waiting, and the timeline's own
// chunk carries it too, so it need not be in the entry
const TimelineSkeleton = defineAsyncComponent(() => import(/* webpackChunkName: "skeleton" */'./components/TimelineSkeleton.vue'))
// drawn on a phone-sized screen only, so a wider one never downloads it
const PhoneBar = defineAsyncComponent(() => import(/* webpackChunkName: "phone-bar" */'./components/PhoneBar.vue'))
// ⌘K / Ctrl+K: nothing of it is fetched until somebody presses it
const CommandPalette = defineAsyncComponent(() => import(/* webpackChunkName: "command-palette" */'./components/CommandPalette.vue'))

/**
 * How long a page may take to arrive before the wait is drawn. Below this a
 * skeleton is a flash rather than an answer.
 */
const PENDING_AFTER_MS = 180

export default {
	name: 'App',
	components: {
		AccountSetup,
		CloudAddressSetup,
		CommandPalette,
		NcContent,
		NcAppContent,
		NcButton,
		MiniPlayer,
		Navigation,
		PhoneBar,
		ReactionPicker,
		ShortcutHelp,
		SetupChecks,
		TimelineSkeleton,
	},

	setup() {
		const { serverData } = useServerData()
		const { cloudId } = useCurrentUser()

		return { serverData, cloudId, miniPlayer }
	},

	data() {
		return {
			infoHidden: false,
			state: [],
			shortcutHelpOpen: false,
			paletteOpen: false,
			stopShortcuts: null,
			/** whether the screen is phone-sized, which is when the bottom bar is drawn */
			phone: isPhone(),
			stopPhone: null,
			/**
			 * Which Vue transition the next page change uses: the direction it
			 * is going in, or '' where the browser is animating it itself.
			 */
			transitionName: 'page',
			/** whether a page is being waited for long enough to say so */
			pending: false,
			/** which placeholder stands in for the page being waited for */
			pendingSkeleton: skeletonFor(undefined),
			pendingTimer: null,
			/** the router hooks, so they can be taken off again */
			stopBefore: null,
			stopAfter: null,
			stopPresses: null,
			/** the request that mounts the emoji picker; it takes the rest itself */
			firstReactionAsk: null,
		}
	},

	computed: {
		...mapStores(useAccountStore, useSettingsStore, useTimelineStore),

		/**
		 * Whether the administrator's checks found something to warn about.
		 * Only an administrator's page carries them at all.
		 *
		 * @return {boolean}
		 */
		checksFailed() {
			return this.serverData.checks !== undefined && !this.serverData.checks.success
		},

		/**
		 * The server the visitor is on, so the button says which Nextcloud it
		 * signs them in to.
		 *
		 * @return {string} the host
		 */
		instanceHost() {
			return window.location.host
		},

		/**
		 * Nextcloud's own login, carrying the page being read so that it is
		 * where the visitor lands afterwards rather than a dashboard.
		 *
		 * @return {string}
		 */
		loginUrl() {
			return generateUrl('/login?redirect_url={url}', {
				url: window.location.pathname + window.location.search,
			})
		},
	},

	watch: {
		$route(to) {
			// keep the store's query in step with the URL for the navigation's search box
			this.timelineStore.setSearchQuery(to.name === 'search' ? String(to.params.term ?? '') : '')
		},
	},

	mounted() {
		this.stopShortcuts = listenForShortcuts(() => this.settingsStore.shortcutsEnabled)
		this.stopPhone = onPhoneChange((phone) => {
			this.phone = phone
		})
		this.watchNavigation()
		eventBus.on('shortcut:help', this.toggleShortcutHelp)
		eventBus.on('shortcut:home', this.goHome)
		eventBus.on('shortcut:palette', this.togglePalette)
		eventBus.on(REACTION_PICK, this.onFirstReactionAsk)
	},

	unmounted() {
		this.stopShortcuts?.()
		this.stopPhone?.()
		this.stopBefore?.()
		this.stopAfter?.()
		this.stopPresses?.()
		this.disarmPending()
		eventBus.off('shortcut:help', this.toggleShortcutHelp)
		eventBus.off('shortcut:home', this.goHome)
		eventBus.off('shortcut:palette', this.togglePalette)
		eventBus.off(REACTION_PICK, this.onFirstReactionAsk)
	},

	beforeMount() {
		this.settingsStore.setServerData(loadState('social', 'serverData'))

		if (!this.serverData.public) {
			// The page was rendered with this reader's account, so there is no need
			// to fetch it; a page rendered before the account existed has none.
			const seeded = loadState('social', 'currentAccount', null)
			if (seeded?.url) {
				this.accountStore.setOwnAccount(seeded)
			} else if (!this.serverData.needsAccount && this.cloudId !== '') {
				// not while the setup screen is up: fetching this reader's account
				// creates it, taking the handle the screen is asking them to choose
				this.accountStore.fetchCurrentAccountInfo(this.cloudId)
			}
		}

		if (OCA.Push && OCA.Push.isEnabled()) {
			OCA.Push.addCallback(this.fromPushApp, 'social')
		}
	},

	methods: {
		/**
		 * Watches for page changes and decides how each is animated. The
		 * animation starts in `beforeResolve`, once the route's component is
		 * loaded, because a view transition holds the old page's picture until then.
		 */
		watchNavigation() {
			const router = this.$router
			// a test may provide a router stub that cannot be hooked
			if (typeof router?.beforeEach !== 'function' || typeof router?.beforeResolve !== 'function') {
				return
			}

			// what was pressed, so the card or face that started a navigation
			// can be carried into the page it opens
			this.stopPresses = listenForPresses()

			this.stopBefore = router.beforeEach((to, from, next) => {
				this.armPending(to)
				next()
			})

			this.stopAfter = router.beforeResolve((to, from) => {
				this.disarmPending()
				// a switch of scope keeps the page still, so the switcher stays under the pointer
				if (isSamePage(to, from)) {
					return true
				}

				const direction = pageDirection(to, from)
				markDirection(direction)

				if (canViewTransition()) {
					// the browser animates the two pictures; a Vue transition on
					// top of that would be the same move played twice
					this.transitionName = ''

					return startPageTransition(() => this.$nextTick(), departFor(to))
				}

				this.transitionName = direction === '' ? 'page' : `page-${direction}`

				return true
			})
		},

		/**
		 * Shows the route's placeholder once a page change takes long enough to
		 * notice; most take a few milliseconds and should not flash a skeleton.
		 *
		 * @param {object} [to] the route being opened
		 */
		armPending(to) {
			this.disarmPending()
			this.pendingSkeleton = skeletonFor(to)
			this.pendingTimer = window.setTimeout(() => {
				this.pending = true
			}, PENDING_AFTER_MS)
		},

		/** The page arrived, or never will. */
		disarmPending() {
			window.clearTimeout(this.pendingTimer)
			this.pendingTimer = null
			this.pending = false
		},

		/**
		 * Reloads with `welcome` so the server hands the page the new account and
		 * the first-run introduction shows.
		 */
		onAccountCreated() {
			this.reloadTo(generateUrl('/apps/social/') + '?welcome=1')
		},

		/** @param {string} url where to send the browser; a test replaces this */
		reloadTo(url) {
			window.location.assign(url)
		},

		/**
		 * Mounts the emoji picker for the first reaction anybody asks for; from
		 * then on it listens on the bus itself.
		 *
		 * @param {{react: (emoji: string) => void}} payload who asked, and what to tell
		 */
		onFirstReactionAsk(payload) {
			if (typeof payload?.react !== 'function') {
				return
			}
			eventBus.off(REACTION_PICK, this.onFirstReactionAsk)
			this.firstReactionAsk = payload
		},

		toggleShortcutHelp() {
			this.shortcutHelpOpen = !this.shortcutHelpOpen
		},

		togglePalette() {
			this.paletteOpen = !this.paletteOpen
		},

		goHome() {
			if (this.$route.name !== 'timeline' || this.$route.params.type) {
				this.$router.push({ name: 'timeline' })
			}
		},

		hideInfo() {
			this.infoHidden = true
		},

		/**
		 * The address is stored. The page reloads, so the server hands it
		 * everything that is built from the address.
		 */
		onCloudAddressSaved() {
			this.reloadTo(generateUrl('/apps/social/'))
		},

		/**
		 * Searches on the server, on its own route.
		 *
		 * @param {string} term what was typed
		 * @param {boolean} submitted whether the reader pressed Enter, which
		 *                            lets the search ask other servers
		 */
		search(term, submitted = false) {
			const query = (term ?? '').trim()
			this.timelineStore.setSearchQuery(query)

			if (query === '') {
				if (this.$route.name === 'search') {
					this.$router.push({ name: 'timeline' })
				}
				return
			}

			// a Bluesky starter pack someone was sent is opened, not searched for
			const pack = starterPackRoute(query)
			if (pack !== null) {
				this.$router.push(pack)
				return
			}

			// replace while the term is being refined, so Back does not have
			// to walk out through every keystroke
			const navigate = this.$route.name === 'search' ? this.$router.replace : this.$router.push
			navigate.call(this.$router, {
				name: 'search',
				params: { term: query },
				...(submitted ? { query: { lookup: '1' } } : {}),
			})
		},

		fromPushApp(data) {
			// a tag, a list or a Bluesky feed is a route of its own, not home
			let timeline = String(this.$route.name ?? '')
			if (timeline === 'timeline') {
				timeline = String(this.$route.params.type || 'home')
			}

			if (data.source === 'timeline.home' && timeline === 'home') {
				this.timelineStore.addToTimeline([data.payload])
			}
			if (data.source === 'timeline.direct' && timeline === 'direct') {
				this.timelineStore.addToTimeline([data.payload])
			}
		},
	},
}
</script>

<style scoped lang="scss">
/* A slim line, not a banner: the page is somebody else's post, and this only
   offers a way back into one's own account. */
.social__visitor {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: center;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 2);
	margin-block-end: calc(var(--default-grid-baseline) * 2);
	border-block-end: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
}

.social__visitor-text {
	margin: 0;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.social__visitor-hint {
	display: block;
	font-size: 0.9em;
}

/* a page narrower than this sets `--social-page-width` on its wrapper, since
   this rule outranks the page's own */
#app-content-vue .social__wrapper {
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: var(--social-page-width, 800px);
	/*
	 * Inline only: auto block margins would centre the page vertically in its
	 * grid row, which during a page change is as tall as the outgoing page.
	 */
	margin-inline: auto;
}

/* Direct messages need the full app-content width for the conversation list
   and the active chat to sit side by side. Other Social pages keep the reading
   column above. */
#app-content-vue .social__wrapper--direct {
	box-sizing: border-box;
	width: 100%;
	max-width: none;
	margin: 0;
	padding: 0;
}

.setup {
	margin: 0 auto !important;
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: 800px;
	display: flex;
	flex-direction: column;
	gap: 20px;

	h2 {
		font-size: 24px;
		font-weight: 700;
		margin-bottom: 8px;
	}

	p {
		color: var(--color-text-lighter);
		line-height: 1.6;
	}
}

.setup-input {
	width: 300px;
	max-width: 100%;
	margin-inline-end: 10px;
	border-radius: var(--border-radius-element);
}

#social-spacer a:hover,
#social-spacer a:focus {
	border: none !important;
}

a.external_link {
	text-decoration: underline;
}

:deep(.app-navigation-entry) {
	.app-navigation-entry__title {
		font-size: 14px;
	}
}

:deep(.app-navigation-entry__subname) {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin-top: -2px;
}

:deep(.app-navigation-entry-icon) {
	display: flex;
	align-items: center;
	justify-content: center;

	.avatardiv {
		margin: 0;
	}
}

.icon-social {
	background-image: url('../img/social-dark.svg');
	filter: var(--background-invert-if-dark);
}
</style>

<style lang="scss">
/* a visitor has no sidebar, so nothing needs room for its toggle */
#content-vue.public {
	.social__wrapper {
		--social-toggle-clearance: 0px;
	}

	.page-header--clear-navigation {
		padding-block-start: 0;
	}
}

/* Moving between the pages the sidebar lists. With the View Transitions API the
   browser animates pictures of the two pages; elsewhere a Vue transition makes
   the same move. Both read the direction from `data-page-direction` on the root
   (services/pageOrder.js). */

/* The pages share one cell, so the one arriving does not wait for the one
   leaving. It also means neither of them is in flow while both exist, which is
   what keeps the content from jumping as they cross. */
.social__pages {
	display: grid;
	min-block-size: 100%;
	/*
	 * Lay the page out from the top: with `normal` the single row is centred in
	 * a grid at least as tall as the window, so a short page floats halfway down.
	 */
	align-content: start;
	/* the foot of every page stays above the phone bar (PhoneBar.vue) */
	box-sizing: border-box;
	padding-block-end: var(--social-phone-bar-space, 0px);
	/* A name of its own lifts the page out of the `root` picture, so only the
	   page moves and the sidebar and header stay still. */
	view-transition-name: social-page;

	> * {
		grid-area: 1 / 1;
		min-width: 0;
		/*
		 * Auto inline margins stop a grid item stretching to its track, so the
		 * page would size to its content; a definite width keeps it in the
		 * column while `max-width` still clamps it.
		 */
		inline-size: 100%;
	}
}

/* The skeleton for a page that is taking its time. It sits in the same cell,
   over the page being left, so nothing moves when it appears. */
.social__pending {
	z-index: 2;
	background: var(--color-main-background);
}

.pending-enter-active,
.pending-leave-active {
	transition: opacity var(--social-motion-fast) linear;
}

.pending-enter-from,
.pending-leave-to {
	opacity: 0;
}

/* ---- the Vue transition, for browsers without view transitions ---- */

.page-enter-active,
.page-forward-enter-active,
.page-back-enter-active {
	transition: opacity var(--social-motion-base) var(--social-ease), transform var(--social-motion-base) var(--social-ease);
}

.page-leave-active,
.page-forward-leave-active,
.page-back-leave-active {
	transition: opacity var(--social-motion-fast) var(--social-ease), transform var(--social-motion-fast) var(--social-ease);
}

/* no direction (the same page, or pages the sidebar does not list): a rise
   rather than a slide */
.page-enter-from {
	opacity: 0;
	transform: translateY(8px);
}

.page-leave-to {
	opacity: 0;
	transform: translateY(-4px);
}

/* down the sidebar: the new page comes from the right, the old one goes left */
.page-forward-enter-from {
	opacity: 0;
	transform: translateX(26px);
}

.page-forward-leave-to {
	opacity: 0;
	transform: translateX(-18px);
}

/* and back up it, the other way round */
.page-back-enter-from {
	opacity: 0;
	transform: translateX(-26px);
}

.page-back-leave-to {
	opacity: 0;
	transform: translateX(18px);
}

/* ---- and the same move, done by the browser ---- */

@keyframes page-vt-in {
	from { opacity: 0; transform: translateX(var(--page-from, 0)) translateY(var(--page-rise, 8px)); }
}

@keyframes page-vt-out {
	to { opacity: 0; transform: translateX(var(--page-to, 0)) translateY(var(--page-fall, -4px)); }
}

::view-transition-old(social-page) {
	animation: page-vt-out var(--social-motion-fast) var(--social-ease) both;
}

::view-transition-new(social-page) {
	animation: page-vt-in var(--social-motion-base) var(--social-ease) both;
}

/* Everything that is not the page -- the sidebar, the header -- is the same
   before and after, so there is nothing to show it doing. Left to itself the
   browser cross-fades it, which on an identical picture is invisible work. */
::view-transition-old(root),
::view-transition-new(root) {
	animation: none;
}

/* Something carried from one place to another -- a card into its thread, a
   face into the top of a profile, a picture into its viewer -- travels on the
   slow step, so the eye can follow it. The names are made up as the page runs
   (utils/viewTransition.js), so the rule finds them by their class. */
::view-transition-group(*.social-morph) {
	animation-duration: var(--social-motion-slow);
	animation-timing-function: var(--social-ease);
}

[data-page-direction='forward'] {
	--page-from: 26px;
	--page-to: -18px;
	--page-rise: 0;
	--page-fall: 0;
}

[data-page-direction='back'] {
	--page-from: -26px;
	--page-to: 18px;
	--page-rise: 0;
	--page-fall: 0;
}

/* A reader who asked their system for less movement gets the change without
   the movement. `canViewTransition()` answers false for them as well, so this
   covers the Vue path; the pseudo-elements are switched off too in case a
   browser starts one regardless. */
@media (prefers-reduced-motion: reduce) {
	.page-enter-active,
	.page-leave-active,
	.page-forward-enter-active,
	.page-forward-leave-active,
	.page-back-enter-active,
	.page-back-leave-active {
		transition: opacity var(--social-motion-fast) linear;
	}

	.page-enter-from,
	.page-leave-to,
	.page-forward-enter-from,
	.page-forward-leave-to,
	.page-back-enter-from,
	.page-back-leave-to {
		transform: none;
	}

	::view-transition-old(social-page),
	::view-transition-new(social-page) {
		animation: none;
	}

	/* a rule of its own: a browser that cannot read the class selector
	   drops the whole rule it is in */
	::view-transition-group(*.social-morph) {
		animation: none;
	}
}

:root {

	/*
	 * The content column. The list keeps a gutter inside it, so a post is
	 * `--social-column-gutter` narrower on each side than the composer, which
	 * takes the column whole.
	 */
	--social-column: 900px;
	--social-column-gutter: calc(var(--default-grid-baseline, 4px) * 2);
	/*
	 * The column a feed of text is read in. A feed page narrows
	 * `--social-column` to it, and what stands above the posts there --
	 * notices, the scope switcher, state cards -- is capped at it, so on a
	 * page of tiles or videos they still line up with each other.
	 */
	--social-reading-column: 616px;
	/*
	 * A page of text with no list around it -- search results, a post's own
	 * page, About -- whose content is as wide as the posts in the reading
	 * column, plus the page's padding.
	 */
	--social-reading-page: calc(var(--social-reading-column) - 2 * var(--social-column-gutter) + 8 * var(--default-grid-baseline, 4px));
}

/* Keep the whole app flat, including nested Nextcloud controls and pseudo-elements. */
#content-vue,
#content-vue *,
#content-vue *::before,
#content-vue *::after {
	box-shadow: none !important;
	text-shadow: none !important;
}

#content-vue :focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

img.emoji {
	margin: 3px;
	width: 16px;
	vertical-align: text-bottom;
}

.social__timeline {
	.social__wrapper {
		padding: 0;
		max-width: var(--social-column);
		margin: 0 auto;
	}

	.timeline-entry {
		list-style: none;
	}
}

/**
 * The transition both timeline <transition-group>s use, here because this style
 * block is global. `-move` makes the surrounding entries slide when one is
 * inserted or removed.
 */
.list-enter-active,
.list-leave-active,
.list-move {
	transition: opacity var(--social-motion-base) var(--social-ease), transform var(--social-motion-base) var(--social-ease);
}

.list-enter-from,
.list-leave-to {
	opacity: 0;
	transform: translateY(-6px);
}

@media (prefers-reduced-motion: reduce) {
	.list-enter-active,
	.list-leave-active,
	.list-move {
		transition: none;
	}
}

/**
 * Posts ease in as they scroll into view, driven by the browser off the scroll
 * position; where the property is missing nothing happens, so no fallback.
 */
@supports (animation-timeline: view()) {
	@media (prefers-reduced-motion: no-preference) {
		@keyframes timeline-entry-rise {
			from {
				opacity: 0;
				transform: translateY(12px) scale(.99);
			}

			to {
				opacity: 1;
				transform: none;
			}
		}

		.social__timeline .timeline-entry {
			animation: timeline-entry-rise linear both;
			animation-timeline: view();
			/* only the arrival is animated, not the departure */
			animation-range: entry 0% entry 45%;
		}
	}
}

.social__welcome {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	margin: calc(var(--default-grid-baseline) * 4) auto;
	padding: calc(var(--default-grid-baseline) * 5);
	max-width: var(--social-column);

	h2 {
		font-size: 22px;
		font-weight: 700;
		margin-bottom: 12px;
	}

	p {
		color: var(--color-text-lighter);
		line-height: 1.7;
	}
}

.new-post {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	margin: calc(var(--default-grid-baseline) * 3) auto;
	padding: calc(var(--default-grid-baseline) * 3);
	max-width: var(--social-column);
	position: sticky;
	top: 0;
	z-index: 100;
}

.app-navigation {
	.app-navigation-entry {
		.app-navigation-entry__title {
			font-size: 14px;
		}

		.app-navigation-entry__subname {
			font-size: 12px;
			color: var(--color-text-lighter);
		}
	}
}

.navigation__subname {
	font-size: 12px;
	color: var(--color-text-lighter);
}

</style>

<style>
/*
 * The on-dark palette for what draws over video (shorts, the short composer,
 * the day panel) or on a brand tint (the switch wizard's network marks): black
 * ground and white words whatever the theme. High contrast is served by each
 * component's `forced-colors` rules.
 */
:root {
	--social-on-dark-ground: #000;
	--social-on-dark-text: #fff;
	--social-on-dark-text-soft: rgba(255, 255, 255, 0.85);
	--social-on-dark-text-muted: rgba(255, 255, 255, 0.7);
	--social-on-dark-line: rgba(255, 255, 255, 0.4);
	--social-on-dark-track: rgba(255, 255, 255, 0.3);
	--social-on-dark-tint: rgba(255, 255, 255, 0.15);
	--social-on-dark-scrim: rgba(0, 0, 0, 0.55);
	--social-on-dark-scrim-light: rgba(0, 0, 0, 0.4);
	--social-on-dark-scrim-heavy: rgba(0, 0, 0, 0.75);
	--social-on-dark-accent: #fe2c55;
	--social-on-dark-accent-cool: #25f4ee;
	--social-on-dark-accent-blue: #0082c9;
}

/* remote custom emoji rendered inline in post content and display names */
img.custom-emoji {
	height: 1.25em;
	width: auto;
	vertical-align: text-bottom;
	object-fit: contain;
}

/*
 * The modal composer sits halfway down the viewport. The emoji picker has a
 * 420px default height and floats above its toolbar, which can put its top
 * edge outside a short viewport. Keep the picker within the available upper
 * half; the emoji list itself scrolls, so every category remains reachable.
 */
.modal-wrapper .v-popper__popper .nc-emoji-picker-container .emoji-mart {
	height: min(420px, calc(50vh + 16px));
}
</style>
