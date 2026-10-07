<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- no account yet: the page is the question, not the app. Nothing here
	     may call the API, which would create the account behind the reader's
	     back, so the navigation and the timeline stay out until they answer -->
	<NcContent v-if="!serverData.setup && serverData.needsAccount" appName="social">
		<NcAppContent>
			<AccountSetup @created="onAccountCreated" />
		</NcAppContent>
	</NcContent>
	<NcContent v-else-if="!serverData.setup" appName="social" :class="{public: serverData.public}">
		<Navigation v-if="!serverData.public" @search="search" />
		<ShortcutHelp :open="shortcutHelpOpen" @close="shortcutHelpOpen = false" />
		<!-- one emoji picker for the page, fetched the first time a reaction
		     bar asks for one; see ReactionPicker for why it is not on the card.
		     Rendered, even unopened, it is the chunk and all its emoji data -->
		<ReactionPicker v-if="firstReactionAsk" :firstAsk="firstReactionAsk" />
		<NcAppContent>
			<!--
				A visitor reading a public page has no sidebar and therefore no
				way back into their own Nextcloud -- somebody who follows a link
				to a post, likes what they see and has an account here had to
				know to go to the front page and find the app themselves. The
				address they are on is carried through the login, so they come
				back to the page they were reading rather than to a dashboard.
			-->
			<div v-if="serverData.public" class="social__visitor">
				<p class="social__visitor-text">
					{{ t('social', 'You are reading this as a visitor.') }}
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
			<!-- not keyed on the full path: that remounted the whole view on
			     every route change, so opening a post and pressing Back
			     refetched page one and landed at the top of the timeline.
			     The views watch their own route params instead.

			     Which is also what decides when the page animates: the
			     transition runs when the view itself changes -- one sidebar
			     entry to another -- and not when the same view is handed new
			     params, where a fade would be the timeline blinking at
			     somebody who only opened a post. -->
			<div class="social__pages">
				<router-view v-slot="{ Component }">
					<!-- No `out-in` any more: the two pages share one grid cell
					     and overlap, so the incoming one does not wait for the
					     outgoing one to finish. `out-in` added the whole leave
					     to every change, which on a page already waiting for
					     its chunk is the wrong place to spend a tenth of a
					     second.

					     The name carries the direction -- which way down the
					     sidebar the reader went, see services/pageOrder.js.
					     Where the browser has the View Transitions API this
					     runs at all: `transitionName` is empty there and the
					     browser animates two pictures instead. -->
					<transition :name="transitionName">
						<component :is="Component" />
					</transition>
				</router-view>
				<!-- A lazily loaded page holds the navigation until its chunk
				     arrives, so the reader presses an entry and, for a moment,
				     nothing happens at all. This is what happens instead, and
				     only once the wait is long enough to notice. -->
				<transition name="pending">
					<TimelineSkeleton v-if="pending" class="social__pending" />
				</transition>
			</div>
		</NcAppContent>
	</NcContent>
	<NcContent v-else appName="social">
		<NcAppContent v-if="serverData.isAdmin" class="setup">
			<h2>{{ t('social', 'Aloha Social app setup') }}</h2>
			<p>{{ t('social', 'ActivityPub requires a fixed URL to make entries unique. Note that this cannot be changed later without resetting the Aloha Social app.') }}</p>
			<form @submit.prevent="setCloudAddress">
				<p>
					<label class="hidden" for="setup-cloud-address">
						{{ t('social', 'ActivityPub URL base') }}
					</label>
					<input
						id="setup-cloud-address"
						v-model="cloudAddress"
						:placeholder="serverData.cliUrl"
						type="url"
						class="setup-input"
						required>
					<NcButton
						variant="primary"
						type="submit">
						{{ t('social', 'Finish setup') }}
					</NcButton>
				</p>
				<SetupChecks
					v-if="checksFailed"
					:checks="serverData.checks.checks"
					:addresses="serverData.checks.addresses"
					:clientApi="serverData.checks.clientApi || []" />
			</form>
		</NcAppContent>
		<NcAppContent v-else class="setup">
			<p>{{ t('social', 'The Aloha Social app needs to be set up by the server administrator.') }}</p>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'

import Navigation from './components/Navigation.vue'
import ShortcutHelp from './components/ShortcutHelp.vue'
import SetupChecks from './components/SetupChecks.vue'
import { listenForShortcuts } from './services/shortcuts.js'
import TimelineSkeleton from './components/TimelineSkeleton.vue'
import { isSamePage, pageDirection } from './services/pageOrder.js'
import { canViewTransition, markDirection, startPageTransition } from './services/pageTransition.js'
import eventBus, { REACTION_PICK } from './services/eventBus.js'

import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { mapStores } from 'pinia'
import { useAccountStore } from './store/account.js'
import { useSettingsStore } from './store/settings.js'
import { useTimelineStore } from './store/timeline.js'
import { useCurrentUser } from './composables/useCurrentUser.js'
import { useServerData } from './composables/useServerData.js'

// one page load in an account's life: not worth a place in the entry every
// other load pays for, so it arrives as its own chunk when it is needed
const AccountSetup = defineAsyncComponent(() => import(/* webpackChunkName: "account-setup" */'./views/AccountSetup.vue'))
// the emoji picker is most of a megabyte; it travels in its own chunk and is
// fetched the first time somebody presses "add a reaction"
const ReactionPicker = defineAsyncComponent(() => import(/* webpackChunkName: "reaction-picker" */'./components/ReactionPicker.vue'))

/**
 * How long a page may take to arrive before the wait is drawn. Below this a
 * skeleton is a flash rather than an answer.
 */
const PENDING_AFTER_MS = 180

export default {
	name: 'App',
	components: {
		AccountSetup,
		NcContent,
		NcAppContent,
		NcButton,
		Navigation,
		ReactionPicker,
		ShortcutHelp,
		SetupChecks,
		TimelineSkeleton,
	},

	setup() {
		const { serverData } = useServerData()
		const { cloudId } = useCurrentUser()

		return { serverData, cloudId }
	},

	data() {
		return {
			infoHidden: false,
			state: [],
			cloudAddress: '',
			shortcutHelpOpen: false,
			stopShortcuts: null,
			/**
			 * Which Vue transition the next page change uses: the direction it
			 * is going in, or '' where the browser is animating it itself.
			 */
			transitionName: 'page',
			/** whether a page is being waited for long enough to say so */
			pending: false,
			pendingTimer: null,
			/** the router hooks, so they can be taken off again */
			stopBefore: null,
			stopAfter: null,
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
		 * The server the visitor is already looking at, named so that the
		 * button says which Nextcloud it will sign them in to -- somebody who
		 * arrived from a link on another server has no other way to tell.
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
			// the query lives in the URL now; keep the store in step with it
			// so the navigation's search box shows what is being searched
			this.timelineStore.setSearchQuery(to.name === 'search' ? String(to.params.term ?? '') : '')
		},
	},

	mounted() {
		this.stopShortcuts = listenForShortcuts()
		this.watchNavigation()
		eventBus.on('shortcut:help', this.toggleShortcutHelp)
		eventBus.on('shortcut:home', this.goHome)
		eventBus.on(REACTION_PICK, this.onFirstReactionAsk)
	},

	unmounted() {
		this.stopShortcuts?.()
		this.stopBefore?.()
		this.stopAfter?.()
		this.disarmPending()
		eventBus.off('shortcut:help', this.toggleShortcutHelp)
		eventBus.off('shortcut:home', this.goHome)
		eventBus.off(REACTION_PICK, this.onFirstReactionAsk)
	},

	beforeMount() {
		this.settingsStore.setServerData(loadState('social', 'serverData'))

		if (!this.serverData.public) {
			// The page was rendered for this reader and carries their account
			// with it, so there is nothing to go and ask for. Asking was a
			// second authenticated round trip that everything else waited on.
			// A page rendered before the account existed sends nothing, and
			// then the old question is still the right one.
			const seeded = loadState('social', 'currentAccount', null)
			if (seeded?.url) {
				this.accountStore.setCurrentAccount(this.cloudId)
				this.accountStore.addAccount({ actorId: seeded.url, data: seeded })
			} else if (!this.serverData.needsAccount) {
				// ...but not while the setup screen is up. Asking the API for
				// this reader's account is what creates it, handle and all, so
				// the question the screen is asking would already have been
				// answered for them -- and pressing the button then failed with
				// "that handle is taken", by them, a second earlier.
				this.accountStore.fetchCurrentAccountInfo(this.cloudId)
			}
		}

		if (OCA.Push && OCA.Push.isEnabled()) {
			OCA.Push.addCallback(this.fromPushApp, 'social')
		}
	},

	methods: {
		/**
		 * Watches for a page change, and decides how it is animated.
		 *
		 * `beforeResolve` rather than `beforeEach`: by then the route's component
		 * has been fetched, so the change is about to be drawn rather than about
		 * to be waited for -- which is what a view transition needs, since it
		 * holds a picture of the old page until the new one is there.
		 */
		watchNavigation() {
			const router = this.$router
			// a mounted app always has a real router; a test may have a stub
			// standing in for one, and a stub that cannot be hooked is not a
			// reason for the app to fail to start
			if (typeof router?.beforeEach !== 'function' || typeof router?.beforeResolve !== 'function') {
				return
			}

			// the wait for a chunk happens before this, so the skeleton is armed
			// at the start of the navigation and disarmed when it lands
			this.stopBefore = router.beforeEach((to, from, next) => {
				this.armPending()
				next()
			})

			this.stopAfter = router.beforeResolve((to, from) => {
				this.disarmPending()
				// a switch of scope keeps the page where it is: animating it
				// moved the switcher out from under the pointer that used it
				if (isSamePage(to, from)) {
					return true
				}

				const direction = pageDirection(to, from)
				markDirection(direction)

				if (canViewTransition()) {
					// the browser animates the two pictures; a Vue transition on
					// top of that would be the same move played twice
					this.transitionName = ''

					return startPageTransition(() => this.$nextTick())
				}

				this.transitionName = direction === '' ? 'page' : `page-${direction}`

				return true
			})
		},

		/**
		 * A page that is slow enough to notice says so.
		 *
		 * Not at once: most changes are a few milliseconds, and a skeleton that
		 * flashed up for every one of them would be the busiest thing on screen.
		 */
		armPending() {
			this.disarmPending()
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
		 * The account was just made. The page reloads with `welcome`, so the
		 * server hands it the new account and the first-run introduction shows:
		 * everything on the page was mounted for somebody without an account.
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

		goHome() {
			if (this.$route.name !== 'timeline' || this.$route.params.type) {
				this.$router.push({ name: 'timeline' })
			}
		},

		hideInfo() {
			this.infoHidden = true
		},

		setCloudAddress() {
			axios.post(generateUrl('apps/social/api/v1/config/cloudAddress'), { cloudAddress: this.cloudAddress }).then(() => {
				this.settingsStore.setServerDataEntry({ key: 'setup', value: false })
				this.settingsStore.setServerDataEntry({ key: 'cloudAddress', value: this.cloudAddress })
			})
		},

		/**
		 * Searching asks the server, on its own route.
		 *
		 * It used to commit the term to the store, where two getters filtered
		 * the ~15 statuses that happened to be loaded with String.includes —
		 * so a fresh timeline answered "No posts match your search" for posts
		 * this instance was holding.
		 *
		 * @param {string} term what was typed
		 */
		search(term) {
			const query = (term ?? '').trim()
			this.timelineStore.setSearchQuery(query)

			if (query === '') {
				if (this.$route.name === 'search') {
					this.$router.push({ name: 'timeline' })
				}
				return
			}

			// replace while the term is being refined, so Back does not have
			// to walk out through every keystroke
			const navigate = this.$route.name === 'search' ? this.$router.replace : this.$router.push
			navigate.call(this.$router, { name: 'search', params: { term: query } })
		},

		fromPushApp(data) {
			let timeline = 'home'
			if (this.$route.name === 'tags') {
				timeline = 'tags'
			} else if (this.$route.params.type) {
				timeline = String(this.$route.params.type)
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
/* A line across the top of a public page, not a banner: the page is somebody
   else's post, and this is a way back into one's own account rather than an
   advertisement for signing up. */
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
	color: var(--color-text-maxcontrast);
}

#app-content-vue .social__wrapper {
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: 800px;
	/*
	 * Inline only. `margin: auto` centres the page in the block axis too, and
	 * auto margins take precedence over every alignment property — so a page
	 * shorter than its grid row sits in the middle of it whatever
	 * `align-content` says.
	 *
	 * Normally the row is the page's own height and there is nothing to
	 * centre in. During a page change there is: the two pages share one grid
	 * cell, so while the outgoing one is still there the row is as tall as
	 * *it* is, and the incoming page was dropped halfway down it. Leaving
	 * Settings — eight thousand pixels of it — for a short page therefore drew
	 * the new page four thousand pixels below the viewport and showed a blank
	 * column until the old page unmounted, which on a first visit is however
	 * long the new page's chunk takes to arrive.
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
/* Moving between the pages the sidebar lists.

   Two ways of doing it, and only one of them ever runs. Where the browser has
   the View Transitions API it takes a picture of the page before and after and
   animates those, which costs nothing however tall the page is; everywhere
   else the same move is a Vue transition over the live DOM. Both read the
   direction off `data-page-direction` on the root, which the app sets from
   where the two pages sit in the sidebar -- see services/pageOrder.js. */

/* The pages share one cell, so the one arriving does not wait for the one
   leaving. It also means neither of them is in flow while both exist, which is
   what keeps the content from jumping as they cross. */
.social__pages {
	display: grid;
	min-block-size: 100%;
	/*
	 * Lay the page out from the top. The grid is at least as tall as the
	 * window, and with the default `normal` the single row takes the leftover
	 * height and sits in the middle of it -- so a page with little on it, which
	 * in practice means an empty one, floated halfway down the window with a
	 * screenful of nothing above it. The composer went with it.
	 *
	 * `align-self` on the page does not help: what is centred is the row, not
	 * the item in it. Only pages shorter than the window move; every page with
	 * enough content to fill it was already starting at the top and is
	 * untouched.
	 */
	align-content: start;
	/* What the browser animates. Without a name of its own the only thing
	   there is to animate is `root`, which is a picture of the whole window --
	   so the sidebar, the header and the search box all slid across with the
	   page. Naming this element lifts it out of that picture and leaves the
	   rest of the app still. */
	view-transition-name: social-page;

	> * {
		grid-area: 1 / 1;
		min-width: 0;
		/*
		 * Every page centres itself with `margin-inline: auto`, and auto
		 * margins opt a grid item out of stretching to its track: the page is
		 * then sized to its content, clamped only by its own `max-width`. A
		 * results row with a long handle on it therefore widened Discover to
		 * the full 1000px it is allowed inside a 963px column, and the Follow
		 * buttons at the end of each row were clipped by the edge of the app.
		 *
		 * A definite width puts the track back in charge; `max-width` still
		 * clamps it, and the auto margins still centre what is left over.
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
	transition: opacity .12s linear;
}

.pending-enter-from,
.pending-leave-to {
	opacity: 0;
}

/* ---- the Vue transition, for browsers without view transitions ---- */

.page-enter-active,
.page-forward-enter-active,
.page-back-enter-active {
	transition: opacity .2s ease-out, transform .2s cubic-bezier(.2, 0, .1, 1);
}

.page-leave-active,
.page-forward-leave-active,
.page-back-leave-active {
	transition: opacity .13s ease-in, transform .13s ease-in;
}

/* no direction to give -- the same page, or two pages the sidebar does not
   list: a rise rather than a slide, which says "replaced" without claiming a
   geography that is not there */
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
	animation: page-vt-out .13s ease-in both;
}

::view-transition-new(social-page) {
	animation: page-vt-in .2s cubic-bezier(.2, 0, .1, 1) both;
}

/* Everything that is not the page -- the sidebar, the header -- is the same
   before and after, so there is nothing to show it doing. Left to itself the
   browser cross-fades it, which on an identical picture is invisible work. */
::view-transition-old(root),
::view-transition-new(root) {
	animation: none;
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
		transition: opacity .12s linear;
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
}

/**
 * Two levels of elevation, defined once, so every card in the app agrees about
 * what "resting" and "lifted" look like.
 *
 * Nextcloud's own --color-box-shadow is built for modals: rgba(77,77,77,.5) in
 * the light theme and solid black in the dark one. Used raw under a timeline it
 * would put a hard slab under every post, so it is thinned with color-mix and
 * split in two — a tight contact shadow that seats the card on the page, and a
 * wider ambient one that gives it depth. A browser without color-mix drops the
 * declaration and gets the borders, which is what the app looked like before.
 */
:root {
	--social-elevation-resting:
		0 1px 2px color-mix(in srgb, var(--color-box-shadow) 14%, transparent),
		0 2px 6px color-mix(in srgb, var(--color-box-shadow) 9%, transparent);
	--social-elevation-raised:
		0 2px 4px color-mix(in srgb, var(--color-box-shadow) 20%, transparent),
		0 6px 16px color-mix(in srgb, var(--color-box-shadow) 12%, transparent);

	/*
	 * One column, stated once. The composer and the timeline used to each
	 * carry their own max-width — the same number, but the list also had a
	 * horizontal padding and the composer did not, so the two boxes were a
	 * gutter's width apart at both edges and nothing on the page lined up.
	 * Anything that sits in the column is `--social-column` wide. The list
	 * keeps a gutter inside that, so a post is narrower than the column by
	 * `--social-column-gutter` on each side and the composer, which takes the
	 * column whole, stands that much proud of the posts beneath it.
	 */
	--social-column: 900px;
	--social-column-gutter: calc(var(--default-grid-baseline, 4px) * 2);
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
 * The transition both timeline <transition-group>s use, defined once here
 * because this style block is global. Vue 3 names the starting class
 * `-enter-from` (Vue 2 called it `-enter`, which never matched and left the
 * enter animation dead), and `-move` is what makes the surrounding entries
 * slide when one is inserted or removed instead of jumping.
 */
.list-enter-active,
.list-leave-active,
.list-move {
	transition: opacity .2s ease, transform .2s ease;
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
 * Posts ease in as they scroll into view. The browser drives this off the
 * scroll position on the compositor — no scroll listener, no observer, no
 * work on the main thread — and where the property is missing nothing
 * happens at all, which is why it needs no fallback.
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
