/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createRouter, createWebHistory } from 'vue-router'
import { generateUrl, getRootUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'

import eventBus from './services/eventBus.js'
import { setPageTitle, titleForRoute } from './services/pageTitle.js'
import { scroller } from './utils/scroller.js'
import { timelineSkeleton } from './utils/skeleton.js'

const Timeline = () => import('./views/Timeline.vue')
const TimelineSinglePost = () => import('./views/TimelineSinglePost.vue')
const Profile = () => import(/* webpackChunkName: "profile" */'./views/Profile.vue')
const ProfileTimeline = () => import(/* webpackChunkName: "profile" */'./views/ProfileTimeline.vue')
const ProfileFollowers = () => import(/* webpackChunkName: "profile" */'./views/ProfileFollowers.vue')
const ProfileCollections = () => import(/* webpackChunkName: "profile" */'./views/ProfileCollections.vue')
const ProfileTagged = () => import(/* webpackChunkName: "profile" */'./views/ProfileTagged.vue')
const CollectionPage = () => import(/* webpackChunkName: "profile" */'./views/CollectionPage.vue')
const PlacePage = () => import(/* webpackChunkName: "profile" */'./views/PlacePage.vue')
const ProfileSaved = () => import(/* webpackChunkName: "profile" */'./views/ProfileSaved.vue')
const ProfileDrafts = () => import(/* webpackChunkName: "profile" */'./views/ProfileDrafts.vue')
const ProfileStatistics = () => import(/* webpackChunkName: "profile" */'./views/ProfileStatistics.vue')
const StarterPack = () => import(/* webpackChunkName: "profile" */'./views/StarterPack.vue')
const Discover = () => import('./views/Discover.vue')
// Chunks of their own, not `profile`: every avatar links to a profile, which
// must not download these pages with it.
const SwitchWizard = () => import(/* webpackChunkName: "switch" */'./views/SwitchWizard.vue')
const Settings = () => import(/* webpackChunkName: "settings" */'./views/Settings.vue')
const About = () => import(/* webpackChunkName: "about" */'./views/About.vue')
const Search = () => import('./components/Search.vue')
const VideoShorts = () => import(/* webpackChunkName: "shorts" */'./views/VideoShorts.vue')
const NotFound = () => import(/* webpackChunkName: "not-found" */'./views/NotFound.vue')

/**
 * The path the app is served from, which vue-router needs as its history base:
 * the one the address starts with (`/index.php/apps/social/` or
 * `/apps/social/`), otherwise the one `generateUrl()` would build.
 *
 * @return {string} the base path, without a trailing slash
 */
function getBase() {
	const path = window.location.pathname
	const withIndex = generateUrl('/apps/social', {}, { noRewrite: true }).replace(/\/+$/, '')
	const withoutIndex = getRootUrl().replace(/\/+$/, '') + '/apps/social'
	for (const base of [withIndex, withoutIndex]) {
		if (path === base || path.startsWith(base + '/')) {
			return base
		}
	}

	return generateUrl('/apps/social').replace(/\/+$/, '')
}

/**
 * A page of the reader's own profile, where Favorites, Bookmarks and
 * Statistics live; the home feed for a reader without an account.
 *
 * @param {string} name the profile route
 * @param {object} query what the page is told besides
 * @return {object} the route
 */
function ownProfile(name, query = {}) {
	const account = loadState('social', 'currentAccount', null)?.acct
	return account ? { name, params: { account }, query } : { name: 'timeline' }
}

/**
 * Sends a visitor who asks for the private home feed to the public local feed,
 * rather than showing a predictable 401 as a broken page.
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
 * The timelines that are the reader's own, which hold nothing for a visitor.
 * A list's timeline is one too, under its own route name.
 */
const SIGNED_IN_TIMELINES = ['notifications', 'direct', 'favourites', 'bookmarks', 'interests']

/**
 * Whether a page is the signed-in reader's own: settings, the switch wizard,
 * their profile's saved posts, drafts and statistics, lists, and the timelines
 * that are theirs. The server sends a visitor who loads one of these to the
 * login itself; this answers for a navigation inside the page.
 *
 * @param {object} to target route
 * @return {boolean}
 */
export function needsSignIn(to) {
	if (to.matched.some((record) => record.meta?.signedIn === true)) {
		return true
	}

	return to.name === 'list'
		|| (to.name === 'timeline' && SIGNED_IN_TIMELINES.includes(String(to.params.type ?? '')))
}

/**
 * Nextcloud's login, which brings the reader back to `to` once signed in.
 *
 * @param {object} to target route
 * @param {string} base the path the app is served from
 * @return {string}
 */
export function loginUrl(to, base) {
	return generateUrl('/login') + '?redirect_url=' + encodeURIComponent(base + to.fullPath)
}

/**
 * How long a restored scroll offset waits for the page to be drawn before it
 * is applied anyway.
 */
const RENDER_TIMEOUT = 2000

/**
 * Where the reader was in each view, by path, so Back can put them there.
 * Bounded, as only the most recent few views are reachable with Back.
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
	 * address points to, and otherwise at the top. Back's offset waits until
	 * the view is drawn, or the browser would clamp it to a page not yet filled.
	 *
	 * @param {object} to the route being entered
	 * @param {object} from the route being left
	 * @param {object|null} savedPosition where Back or Forward was last at
	 * @return {object|Promise<object>} the scroll target
	 */
	scrollBehavior(to, from, savedPosition) {
		if (savedPosition) {
			// the window never scrolls here (Nextcloud gives the app a fixed
			// viewport), so restore the content column's remembered offset
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

		// the content column, not the window, is what scrolls; left where it
		// was, a short page would sit far above the viewport during the transition
		const column = scroller()
		if (column !== null) {
			column.scrollTop = 0
		}

		return { top: 0 }
	},
	routes: [
		{
			path: '/',
			// `/` is the private home feed for signed-in readers and the local
			// public feed for visitors, decided before Timeline mounts
			redirect: () => /** @type {{public?: boolean}} */ (loadState('social', 'serverData', {})).public
				? { name: 'timeline', params: { type: 'timeline' } }
				: { name: 'timeline' },
		},
		// Favorites and Bookmarks live on the reader's own profile; a News
		// address opens the home feed
		{
			path: '/timeline/favourites',
			redirect: () => ownProfile('profile.saved'),
		},
		{
			path: '/timeline/bookmarks',
			redirect: () => ownProfile('profile.saved', { list: 'bookmarks' }),
		},
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
			// the placeholder App draws while the page loads: see utils/skeleton.js
			meta: { skeleton: timelineSkeleton },
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
				// a Bluesky feed or list, its `at://` URI spelled as segments
				// (services/blueskyFeeds.js says why)
				/** @type {import('vue-router').RouteRecordRaw} */ ({
					path: 'bluesky/:did/:kind(feed|list)/:rkey',
					name: 'bluesky-feed',
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
				{
					path: 'saved',
					name: 'profile.saved',
					components: {
						details: ProfileSaved,
					},
					meta: { signedIn: true },
				},
				{
					path: 'drafts',
					name: 'profile.drafts',
					components: {
						details: ProfileDrafts,
					},
					meta: { signedIn: true },
				},
				{
					path: 'statistics',
					name: 'profile.statistics',
					components: {
						details: ProfileStatistics,
					},
					meta: { signedIn: true },
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
			meta: { skeleton: 'grid' },
		},
		{
			path: '/places/:id',
			components: {
				default: PlacePage,
			},
			props: true,
			name: 'place',
			meta: { skeleton: 'grid' },
		},
		{
			// a Bluesky starter pack, at the path bsky.app gives it
			path: '/starter-pack/:actor/:rkey',
			components: {
				default: StarterPack,
			},
			name: 'starter-pack',
		},
		{
			path: '/discover',
			components: {
				default: Discover,
			},
			name: 'discover',
		},
		{
			// follow requests are at the top of Activities
			path: '/follow_requests',
			redirect: { name: 'timeline', params: { type: 'notifications' } },
		},
		// Blocking and moving the account are sections of Settings; these
		// addresses, and the parts of them a link names, open them there
		{
			path: '/blocked',
			redirect: (to) => ({ name: 'settings', hash: to.hash || '#blocked' }),
		},
		{
			path: '/filtered',
			redirect: { name: 'settings', hash: '#filtered-notifications' },
		},
		{
			path: '/migration',
			redirect: (to) => ({ name: 'settings', hash: to.hash || '#migration' }),
		},
		{
			path: '/settings',
			components: {
				default: Settings,
			},
			name: 'settings',
			meta: { signedIn: true },
		},
		{
			// the same videos as `/timeline/videos`, watched one by one; a route
			// of its own so it can be left with Back and linked to
			path: '/shorts',
			components: {
				default: VideoShorts,
			},
			// no scope is '', which the view resolves: For you for a reader it
			// has learned about, My Feed otherwise. `account` is whose 24-hour
			// shorts to start at (a face in the Home bar); `start` is the post
			// to start at (the Videos page's Shorts shelf).
			props: { default: (route) => ({ scope: String(route.query.scope ?? ''), account: String(route.query.account ?? ''), start: String(route.query.start ?? '') }) },
			name: 'shorts',
		},
		{
			// the stack's former address, kept for bookmarks and links
			path: '/reels',
			redirect: (to) => ({ name: 'shorts', query: to.query, hash: to.hash }),
		},
		{
			// The way in from another network: a page rather than a section of
			// Settings, as it is a sequence walked once with the old export at hand.
			path: '/switch',
			components: {
				default: SwitchWizard,
			},
			name: 'switch',
			meta: { signedIn: true },
		},
		{
			path: '/statistics',
			redirect: () => ownProfile('profile.statistics'),
		},
		{
			// the server's rules, contact and legal links, for the people on it
			path: '/about',
			components: {
				default: About,
			},
			name: 'about',
		},
		{
			path: '/search/:term?',
			components: {
				default: Search,
			},
			// `?lookup=1` is a submitted search, which may ask other servers
			props: {
				default: (route) => ({ term: route.params.term, lookup: route.query.lookup === '1' }),
			},
			name: 'search',
		},
		{
			path: '/@:account/:id',
			components: {
				default: TimelineSinglePost,
			},
			props: true,
			name: 'single-post',
			meta: { skeleton: 'rows' },
		},
		{
			path: '/ostatus/follow',
			components: {
				default: Profile,
				details: ProfileTimeline,
			},
			props: true,
		},
		{
			// last, so that it catches only what nothing above did: a mistyped
			// link, or one to a page that is gone
			path: '/:pathMatch(.*)*',
			components: {
				default: NotFound,
			},
			name: 'not-found',
		},
	],
})

// before the view changes, not after: once it has, the column belongs to the
// next view and the offset of the one being left is gone
router.beforeEach((to, from) => {
	remember(from.fullPath)

	// a visitor has nothing on the reader's own pages; Nextcloud's login
	// brings them back here once they have signed in
	if (needsSignIn(to) && loadState('social', 'serverData', { public: false }).public === true) {
		window.location.assign(loginUrl(to, router.options.history.base))
		return false
	}
})

// the pages known by name alone; a feed, a profile or a post names itself
// once what it shows has arrived
router.afterEach((to) => {
	const title = titleForRoute(to)
	if (title !== null) {
		setPageTitle(title)
	}
})

export default router
