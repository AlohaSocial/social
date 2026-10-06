/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createRouter, createWebHistory } from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'

import eventBus from './services/eventBus.js'
import { scroller } from './utils/scroller.js'

const Timeline = () => import('./views/Timeline.vue')
const TimelineSinglePost = () => import('./views/TimelineSinglePost.vue')
const Profile = () => import(/* webpackChunkName: "profile" */'./views/Profile.vue')
const ProfileTimeline = () => import(/* webpackChunkName: "profile" */'./views/ProfileTimeline.vue')
const ProfileFollowers = () => import(/* webpackChunkName: "profile" */'./views/ProfileFollowers.vue')
const ProfileCollections = () => import(/* webpackChunkName: "profile" */'./views/ProfileCollections.vue')
const ProfileTagged = () => import(/* webpackChunkName: "profile" */'./views/ProfileTagged.vue')
const Portfolio = () => import(/* webpackChunkName: "portfolio" */'./views/Portfolio.vue')
const CollectionPage = () => import(/* webpackChunkName: "profile" */'./views/CollectionPage.vue')
const PlacePage = () => import(/* webpackChunkName: "profile" */'./views/PlacePage.vue')
const FollowRequests = () => import(/* webpackChunkName: "settings" */'./views/FollowRequests.vue')
const BlockedAccounts = () => import(/* webpackChunkName: "settings" */'./views/BlockedAccounts.vue')
const Discover = () => import('./views/Discover.vue')
// Not in the `profile` chunk. Settings and Statistics are pages somebody goes
// to on purpose, once in a while; a profile is what every avatar in every
// timeline links to. Sharing a chunk meant opening a profile downloaded the
// statistics engine -- Statistics.vue is the third-largest component in the
// app -- and the settings page with it, before drawing a single post.
const Migration = () => import(/* webpackChunkName: "migration" */'./views/Migration.vue')
const SwitchWizard = () => import(/* webpackChunkName: "switch" */'./views/SwitchWizard.vue')
const Settings = () => import(/* webpackChunkName: "settings" */'./views/Settings.vue')
const Statistics = () => import(/* webpackChunkName: "statistics" */'./views/Statistics.vue')
const Search = () => import('./components/Search.vue')
const VideoReels = () => import(/* webpackChunkName: "reels" */'./views/VideoReels.vue')

/**
 * The path the app is actually served from, which is what the history base has
 * to be: vue-router can only strip a base that prefixes the current path, and
 * writes `base + path` into the address bar when it cannot.
 *
 * `generateUrl()` is the only thing that knows which of the two shapes this
 * instance uses — `/apps/social/` where mod_rewrite is working, and
 * `/index.php/apps/social/` where it is not. Building the base by hand from
 * the web root assumes the first and breaks the second: the address bar ends
 * up at `/nextcloud/apps/social/nextcloud/index.php/apps/social/`, and a
 * reload of that 404s.
 *
 * @return {string} the base path, without a trailing slash
 */
function getBase() {
	return generateUrl('/apps/social').replace(/\/+$/, '')
}

/**
 * A direct link to the private home feed must not make a visitor ask for it.
 * The page shell is public so visitors can read the public timeline; sending
 * them to the public local feed here prevents a predictable 401 from being
 * shown as a broken page.
 *
 * @param {object} to target route
 * @param {boolean} isPublic whether this page has no signed-in reader
 * @return {object|undefined} the public feed route when a guest asks for home
 */
export function redirectGuestHome(to, isPublic) {
	if (isPublic && (to.params.type ?? 'home') === 'home') {
		return { name: 'timeline', params: { type: 'timeline' } }
	}
}

/**
 * How long a restored scroll offset waits for the page to be drawn before it is
 * applied anyway. Long enough for a list to come back from the store and for a
 * page of posts to arrive over a slow connection; short enough that a view that
 * never says anything still scrolls rather than staying where it was.
 */
const RENDER_TIMEOUT = 2000

/**
 * Where the reader was in each view, by path, so Back can put them there.
 *
 * Bounded: the app has a handful of views and only the most recent few are
 * ever reachable with Back in one session, so an unbounded map would be a
 * leak for no benefit.
 */
const offsets = new Map()

/** How many views are remembered before the oldest is forgotten. */
const REMEMBERED_VIEWS = 10

/**
 * Remembers where the reader is in the view they are leaving.
 *
 * @param {string} path the view being left
 */
function remember(path) {
	const column = scroller()
	if (column === null || path === '') {
		return
	}

	offsets.delete(path)
	offsets.set(path, column.scrollTop)
	while (offsets.size > REMEMBERED_VIEWS) {
		offsets.delete(offsets.keys().next().value)
	}
}

/**
 * Resolves once the timeline has said it is on the page, or after
 * `RENDER_TIMEOUT`, whichever is first.
 *
 * @return {Promise<void>}
 */
function whenRendered() {
	return new Promise((resolve) => {
		const done = () => {
			clearTimeout(timer)
			eventBus.off('timeline:rendered', done)
			resolve()
		}

		const timer = setTimeout(done, RENDER_TIMEOUT)
		eventBus.on('timeline:rendered', done)
	})
}

const router = createRouter({
	history: createWebHistory(getBase()),
	linkActiveClass: 'active',
	/**
	 * Where to be after a navigation: back where Back came from, at what the
	 * address points to, and otherwise at the top.
	 *
	 * The offset Back remembers is waited for rather than returned outright.
	 * Vue Router scrolls as soon as the route has changed, and at that moment
	 * the timeline is one screen tall — the view has mounted but its posts have
	 * not been drawn yet — so the browser clamped a four-page offset to the
	 * bottom of what was there and the reader landed nowhere near where they
	 * had been.
	 *
	 * @param {object} to the route being entered
	 * @param {object} from the route being left
	 * @param {object|null} savedPosition where Back or Forward was last at
	 * @return {object|Promise<object>} the scroll target
	 */
	scrollBehavior(to, from, savedPosition) {
		if (savedPosition) {
			// `savedPosition` is the window's, and the window never scrolls
			// here: Nextcloud gives the app a fixed viewport and the content
			// column scrolls inside it. Restoring it therefore did nothing.
			// What Back needs is the offset of that column, remembered when
			// the reader left the view.
			return whenRendered().then(() => {
				const column = scroller()
				const top = offsets.get(to.fullPath) ?? 0
				if (column !== null && top > 0) {
					column.scrollTop = top
				}

				return savedPosition
			})
		}

		if (to.hash) {
			return { el: to.hash, behavior: 'smooth' }
		}

		// `{ top: 0 }` is the window's, and the window never scrolls here for
		// the same reason as above — so on a forward navigation the content
		// column kept whatever offset the last page was read at.
		//
		// Nothing looked wrong going from one long page to another, because
		// the offset was still inside the new page. Leaving a long page for a
		// short one showed a screenful of nothing: the two pages share one
		// grid cell, the outgoing one is still in it until its leave
		// transition ends *and its replacement's chunk has arrived*, so the
		// cell stays as tall as the page being left and the incoming page sits
		// thousands of pixels above the viewport. It corrected itself the
		// moment the old page unmounted and the browser clamped the offset —
		// which on a first visit to a lazily loaded page is however long the
		// download takes, and long enough to read as a broken layout.
		const column = scroller()
		if (column !== null) {
			column.scrollTop = 0
		}

		return { top: 0 }
	},
	routes: [
		{
			path: '/',
			// `/` is a private home feed for signed-in readers and the local
			// public feed for visitors. This runs before Timeline mounts, so a
			// guest never briefly asks for private posts or account-only data.
			redirect: () => /** @type {{public?: boolean}} */ (loadState('social', 'serverData', {})).public
				? { name: 'timeline', params: { type: 'timeline' } }
				: { name: 'timeline' },
		},
		// News was a timeline of its own and is gone; an address somebody
		// kept for it opens their feed rather than an empty page
		{
			path: '/timeline/news',
			redirect: { name: 'timeline', params: { type: 'home' } },
		},
		{
			path: '/timeline/:type?',
			beforeEnter: (to) => redirectGuestHome(
				to,
				loadState('social', 'serverData', { public: false }).public === true,
			),
			components: {
				default: Timeline,
			},
			props: true,
			name: 'timeline',
			children: [
				// rendered by the parent: the timeline reads the tag from the route
				/** @type {import('vue-router').RouteRecordRaw} */ ({
					path: 'tags/:tag',
					name: 'tags',
				}),
				// a list's timeline: the id is the list's, and the page is the
				// same timeline view read through it
				/** @type {import('vue-router').RouteRecordRaw} */ ({
					path: 'list/:id',
					name: 'list',
				}),
			],
		},
		{
			path: '/@:account',
			components: {
				default: Profile,
				details: ProfileTimeline,
			},
			props: true,
			children: [
				{
					path: '',
					name: 'profile',
					components: {
						details: ProfileTimeline,
					},
				},
				{
					path: 'followers',
					name: 'profile.followers',
					components: {
						details: ProfileFollowers,
					},
				},
				{
					path: 'following',
					name: 'profile.following',

					components: {
						details: ProfileFollowers,
					},
				},
				{
					path: 'portfolio',
					name: 'profile.portfolio',
					components: {
						details: Portfolio,
					},
				},
				{
					path: 'tagged',
					name: 'profile.tagged',
					components: {
						details: ProfileTagged,
					},
				},
				{
					path: 'collections',
					name: 'profile.collections',
					components: {
						details: ProfileCollections,
					},
				},
			],
		},
		{
			path: '/collections/:id',
			components: {
				default: CollectionPage,
			},
			props: true,
			name: 'collection',
		},
		{
			path: '/places/:id',
			components: {
				default: PlacePage,
			},
			props: true,
			name: 'place',
		},
		{
			path: '/discover',
			components: {
				default: Discover,
			},
			name: 'discover',
		},
		{
			path: '/follow_requests',
			components: {
				default: FollowRequests,
			},
			name: 'follow-requests',
		},
		{
			path: '/blocked',
			components: {
				default: BlockedAccounts,
			},
			name: 'blocked-accounts',
		},
		{
			// The held senders are a card on the Blocking page now. The path
			// stays so that a bookmark, or a link somebody was sent, lands
			// where the thing they wanted actually is rather than on a 404.
			path: '/filtered',
			redirect: { name: 'blocked-accounts' },
		},
		{
			// A page of its own again. Exporting an archive, importing one and
			// bringing a following list over from another network are each a
			// job somebody sits down to do, not a setting changed in passing,
			// and they were the twelfth section of a page of switches.
			path: '/migration',
			components: {
				default: Migration,
			},
			name: 'migration',
		},
		{
			path: '/settings',
			components: {
				default: Settings,
			},
			name: 'settings',
		},
		{
			// the same videos as `/timeline/videos`, watched rather than
			// chosen from. Its own route so that it can be left with Back,
			// and so that a link to it is a link somebody can be sent.
			path: '/reels',
			components: {
				default: VideoReels,
			},
			// no scope asked for is '', which the view resolves: For you for a
			// reader it has learned about, My Feed otherwise
			props: { default: (route) => ({ scope: String(route.query.scope ?? '') }) },
			name: 'reels',
		},
		{
			// The way in from another network. A page rather than a section of
			// Migration: it is a sequence somebody walks once, with their old
			// account's export in a file manager beside them, and the rest of
			// that page is machinery for people who already know what an
			// export is.
			path: '/switch',
			components: {
				default: SwitchWizard,
			},
			name: 'switch',
		},
		{
			path: '/statistics',
			components: {
				default: Statistics,
			},
			name: 'statistics',
		},
		{
			path: '/search/:term?',
			components: {
				default: Search,
			},
			props: true,
			name: 'search',
		},
		{
			path: '/@:account/:id',
			components: {
				default: TimelineSinglePost,
			},
			props: true,
			name: 'single-post',
		},
		{
			path: '/ostatus/follow',
			components: {
				default: Profile,
				details: ProfileTimeline,
			},
			props: true,
		},
	],
})

// before the view changes, not after: once it has, the column belongs to the
// next view and the offset of the one being left is gone
router.beforeEach((to, from) => {
	remember(from.fullPath)
})

export default router
