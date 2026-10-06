/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

import router from '../../src/router.js'
import eventBus from '../../src/services/eventBus.js'

// The views are lazy-loaded chunks; navigation would otherwise pull in the
// whole component tree. Stubs keep the routing table under test.
vi.mock('../../src/views/Timeline.vue', () => ({ default: { name: 'Timeline', render: () => null } }))
vi.mock('../../src/views/TimelineSinglePost.vue', () => ({ default: { name: 'TimelineSinglePost', render: () => null } }))
vi.mock('../../src/views/Profile.vue', () => ({ default: { name: 'Profile', render: () => null } }))
vi.mock('../../src/views/ProfileTimeline.vue', () => ({ default: { name: 'ProfileTimeline', render: () => null } }))
vi.mock('../../src/views/ProfileFollowers.vue', () => ({ default: { name: 'ProfileFollowers', render: () => null } }))
vi.mock('../../src/views/BlockedAccounts.vue', () => ({ default: { name: 'BlockedAccounts', render: () => null } }))
vi.mock('../../src/views/ProfileCollections.vue', () => ({ default: { name: 'ProfileCollections', render: () => null } }))
vi.mock('../../src/views/CollectionPage.vue', () => ({ default: { name: 'CollectionPage', render: () => null } }))
vi.mock('../../src/views/PlacePage.vue', () => ({ default: { name: 'PlacePage', render: () => null } }))

describe('router', () => {
	it('is served under the app path and uses the "active" link class', () => {
		expect(router.options.history.base).toBe('/index.php/apps/social')
		expect(router.options.linkActiveClass).toBe('active')
	})

	it('redirects / to the default timeline', async () => {
		await router.push('/')

		const route = router.currentRoute.value
		expect(route.name).toBe('timeline')
		expect(route.path).toBe('/timeline')
		expect(route.redirectedFrom.path).toBe('/')
		expect(route.params.type).toBeFalsy()
	})

	it('exposes the timeline type as a route param passed to the view as a prop', () => {
		const route = router.resolve('/timeline/notifications')

		expect(route.name).toBe('timeline')
		expect(route.params).toEqual({ type: 'notifications' })
		expect(route.matched).toHaveLength(1)
		expect(route.matched[0].props.default).toBe(true)
	})

	it.each(['home', 'direct', 'federated', 'favourites'])('builds /timeline/%s from the named timeline route', (type) => {
		expect(router.resolve({ name: 'timeline', params: { type } }).fullPath).toBe(`/timeline/${type}`)
	})

	it('routes hashtag timelines to the "tags" child of the timeline route', () => {
		const route = router.resolve('/timeline/tags/nextcloud')

		expect(route.name).toBe('tags')
		expect(route.params.tag).toBe('nextcloud')
		expect(route.matched.map((record) => record.name)).toEqual(['timeline', 'tags'])
		expect(router.resolve({ name: 'tags', params: { tag: 'nextcloud' } }).fullPath).toBe('/timeline/tags/nextcloud')
	})

	it('routes a list timeline to the "list" child of the timeline route', () => {
		const route = router.resolve('/timeline/list/4')

		expect(route.name).toBe('list')
		expect(route.params.id).toBe('4')
		expect(route.matched.map((record) => record.name)).toEqual(['timeline', 'list'])
		expect(router.resolve({ name: 'list', params: { id: 4 } }).fullPath).toBe('/timeline/list/4')
	})

	it('routes /@account to the profile with the profile timeline in the details view', () => {
		const route = router.resolve('/@alice')

		expect(route.name).toBe('profile')
		expect(route.params).toEqual({ account: 'alice' })
		expect(route.matched).toHaveLength(2)
		expect(route.matched[0].path).toBe('/@:account')
		expect(Object.keys(route.matched[0].components)).toEqual(['default', 'details'])
		expect(route.matched[0].props.default).toBe(true)
		expect(Object.keys(route.matched[1].components)).toEqual(['details'])
	})

	it('routes an account\'s collections under the account, and one collection to its own page', () => {
		expect(router.resolve({ name: 'profile.collections', params: { account: 'bob@remote.tld' } }).fullPath)
			.toBe('/@bob@remote.tld/collections')
		expect(router.resolve('/@bob@remote.tld/collections').name).toBe('profile.collections')

		const collection = router.resolve({ name: 'collection', params: { id: '9' } })
		expect(collection.fullPath).toBe('/collections/9')
		expect(collection.params.id).toBe('9')
	})

	it('routes a place to its own page', () => {
		const place = router.resolve({ name: 'place', params: { id: '4' } })
		expect(place.fullPath).toBe('/places/4')
		expect(router.resolve('/places/4').name).toBe('place')
	})

	it('keeps remote handles intact in the account param', () => {
		expect(router.resolve('/@bob@remote.tld').params.account).toBe('bob@remote.tld')
		expect(router.resolve({ name: 'profile', params: { account: 'bob@remote.tld' } }).fullPath).toBe('/@bob@remote.tld')
	})

	it.each([
		['/@alice/followers', 'profile.followers'],
		['/@alice/following', 'profile.following'],
		['/@bob@remote.tld/followers', 'profile.followers'],
	])('routes %s to the %s child view rather than to a single post', (path, name) => {
		const route = router.resolve(path)

		expect(route.name).toBe(name)
		expect(route.params.id).toBeUndefined()
		expect(route.matched[0].path).toBe('/@:account')
		expect(Object.keys(route.matched[1].components)).toEqual(['details'])
	})

	it('routes /@account/:id to the single post view', () => {
		const route = router.resolve('/@alice/12345')

		expect(route.name).toBe('single-post')
		expect(route.params).toEqual({ account: 'alice', id: '12345' })
		expect(route.matched).toHaveLength(1)
		expect(route.matched[0].props.default).toBe(true)
		expect(router.resolve({ name: 'single-post', params: { account: 'bob@remote.tld', id: '99' } }).fullPath).toBe('/@bob@remote.tld/99')
	})

	it('serves the OStatus follow page with the profile layout', () => {
		const route = router.resolve('/ostatus/follow')

		expect(route.matched).toHaveLength(1)
		expect(route.matched[0].path).toBe('/ostatus/follow')
		expect(route.name).toBeUndefined()
		expect(Object.keys(route.matched[0].components)).toEqual(['default', 'details'])
		expect(route.matched[0].props.default).toBe(true)
	})

	it('resolves the blocked accounts route', () => {
		const route = router.resolve('/blocked')

		expect(route.name).toBe('blocked-accounts')
		expect(route.matched).toHaveLength(1)
	})

	it('resolves the search route, with and without a term', () => {
		expect(router.resolve('/search/nextcloud').name).toBe('search')
		expect(router.resolve('/search/nextcloud').params.term).toBe('nextcloud')
		expect(router.resolve('/search').name).toBe('search')
		// vue-router 5 leaves an absent optional param `undefined`, where 4 gave
		// `''`. Nothing downstream cares: `Search.vue` declares `default: ''`
		// on the prop, so Vue fills it in, and `App.vue` reads it as
		// `to.params.term ?? ''`. Asserted so the next person to see
		// `undefined` here knows it is the router's answer and not a hole.
		expect(router.resolve('/search').params.term).toBeUndefined()
	})

	it('does not match unknown paths', () => {
		expect(router.resolve('/nope/nope').matched).toEqual([])
	})

	describe('where a navigation lands', () => {
		it('restores the position Back and Forward were last at, once the list is drawn', async () => {
			// without a scrollBehavior, opening a post and pressing Back
			// landed at the top of a timeline the reader had scrolled far into
			const saved = { left: 0, top: 1200 }
			const pending = router.options.scrollBehavior({}, {}, saved)
			let settled = false
			pending.then(() => {
				settled = true
			})

			// nothing has been drawn yet: scrolling now would be scrolling a
			// page one screen tall, and the browser would clamp the offset
			await Promise.resolve()
			expect(settled).toBe(false)

			eventBus.emit('timeline:rendered')

			await expect(pending).resolves.toBe(saved)
		})

		it('puts the scrolling column back where it was, not the window', async () => {
			// the window never scrolls in a Nextcloud app: the content column
			// does, so restoring `savedPosition` alone moved nothing. This is
			// the whole of what Back has to do.
			const column = document.createElement('div')
			column.id = 'app-content-vue'
			document.body.appendChild(column)
			try {
				const guard = router.options.scrollBehavior
				await router.push('/timeline/home').catch(() => {})
				// scrolled *while on* the timeline, which is the only moment
				// there is one to record: arriving takes the column to the top
				column.scrollTop = 4200
				// and leaving it is what records the offset
				await router.push('/@alice/7').catch(() => {})
				column.scrollTop = 0

				const pending = guard({ fullPath: '/timeline/home' }, {}, { left: 0, top: 0 })
				eventBus.emit('timeline:rendered')
				await pending

				expect(column.scrollTop).toBe(4200)
			} finally {
				column.remove()
			}
		})

		it('leaves the column alone when the view was never scrolled', async () => {
			const column = document.createElement('div')
			column.id = 'app-content-vue'
			document.body.appendChild(column)
			try {
				column.scrollTop = 0
				const pending = router.options.scrollBehavior({ fullPath: '/never-visited' }, {}, { left: 0, top: 0 })
				eventBus.emit('timeline:rendered')
				await pending

				expect(column.scrollTop).toBe(0)
			} finally {
				column.remove()
			}
		})

		it('restores it anyway when nothing says it has been drawn', async () => {
			// a view with no timeline in it never sends the event; waiting for
			// it forever would mean never scrolling at all
			vi.useFakeTimers()
			try {
				const saved = { left: 0, top: 40 }
				const pending = router.options.scrollBehavior({}, {}, saved)

				vi.advanceTimersByTime(2000)

				await expect(pending).resolves.toBe(saved)
			} finally {
				vi.useRealTimers()
			}
		})

		it('goes to the top of a page it has not been at before', () => {
			expect(router.options.scrollBehavior({}, {}, null)).toEqual({ top: 0 })
		})

		/**
		 * `{ top: 0 }` is the window's, and the window never scrolls here — so
		 * the column kept the offset the last page was read at. Going from a
		 * long page to a short one then showed a screenful of nothing, for as
		 * long as the incoming page's chunk took to arrive.
		 */
		it('takes the scrolling column to the top as well', () => {
			const column = document.createElement('div')
			column.id = 'app-content-vue'
			document.body.appendChild(column)
			try {
				column.scrollTop = 6000

				router.options.scrollBehavior({}, {}, null)

				expect(column.scrollTop).toBe(0)
			} finally {
				column.remove()
			}
		})

		it('scrolls to an anchor when the URL names one', () => {
			expect(router.options.scrollBehavior({ hash: '#reply-7' }, {}, null))
				.toEqual({ el: '#reply-7', behavior: 'smooth' })
		})
	})
})

describe('router navigation', () => {
	afterEach(() => {
		delete document.startViewTransition
	})

	/** A router of its own, so pushing here cannot leak into the tests above. */
	const freshRouter = async () => {
		vi.resetModules()
		const { default: instance } = await import('../../src/router.js')

		return instance
	}

	it('does not wait for a view transition', async () => {
		// A beforeResolve guard used to await document.startViewTransition on
		// every navigation. The browser captures the whole document before it
		// runs the callback, so the route — and with it the sidebar highlight —
		// could not move until that snapshot and the cross-fade had finished.
		// Worse, a call that never reached the callback never resolved at all,
		// and the navigation hung: this stub reproduces exactly that.
		const startViewTransition = vi.fn(() => ({
			updateCallbackDone: Promise.resolve(),
			ready: Promise.resolve(),
			finished: Promise.resolve(),
		}))
		document.startViewTransition = startViewTransition

		const router = await freshRouter()
		await router.push('/timeline/direct')

		expect(startViewTransition).not.toHaveBeenCalled()
		expect(router.currentRoute.value.params.type).toBe('direct')
	})

	it('starts anonymous readers on the public local timeline', async () => {
		globalThis.setInitialState('social', 'serverData', { public: true })
		const router = await freshRouter()
		await router.push('/')

		expect(router.currentRoute.value.path).toBe('/timeline/timeline')
		expect(router.currentRoute.value.params.type).toBe('timeline')
		document.getElementById('initial-state-social-serverData')?.remove()
	})

	it('redirects anonymous direct links from the private home feed to the public local feed', async () => {
		globalThis.setInitialState('social', 'serverData', { public: true })
		const router = await freshRouter()
		await router.push('/timeline/home')

		expect(router.currentRoute.value.path).toBe('/timeline/timeline')
		expect(router.currentRoute.value.params.type).toBe('timeline')
		expect(router.currentRoute.value.redirectedFrom.path).toBe('/timeline/home')
		document.getElementById('initial-state-social-serverData')?.remove()
	})

	it('redirects the parameterless anonymous timeline home to the public local feed', async () => {
		globalThis.setInitialState('social', 'serverData', { public: true })
		const router = await freshRouter()
		await router.push('/timeline')

		expect(router.currentRoute.value.path).toBe('/timeline/timeline')
		expect(router.currentRoute.value.params.type).toBe('timeline')
		expect(router.currentRoute.value.redirectedFrom.path).toBe('/timeline')
		document.getElementById('initial-state-social-serverData')?.remove()
	})
})

describe('router base detection', () => {
	afterEach(() => {
		globalThis._oc_webroot = ''
		window.OC.config.modRewriteWorking = false
		window.history.replaceState({}, '', '/')
	})

	const freshBase = async () => {
		vi.resetModules()
		const { default: freshRouter } = await import('../../src/router.js')

		return freshRouter.options.history.base
	}

	it('carries index.php when the instance has no pretty urls', async () => {
		// the base has to match the path the app is really served from, or
		// vue-router writes base + path and the reload of that 404s
		globalThis._oc_webroot = '/nextcloud'

		expect(await freshBase()).toBe('/nextcloud/index.php/apps/social')
	})

	it('leaves index.php out where mod_rewrite is working', async () => {
		globalThis._oc_webroot = '/nextcloud'
		window.OC.config.modRewriteWorking = true

		expect(await freshBase()).toBe('/nextcloud/apps/social')
	})

	it('works at the root of a domain', async () => {
		window.OC.config.modRewriteWorking = true

		expect(await freshBase()).toBe('/apps/social')
	})

	it('does not depend on the path the app was opened at', async () => {
		globalThis._oc_webroot = '/nextcloud'
		window.history.replaceState({}, '', '/nextcloud/index.php/apps/social/@alice')

		expect(await freshBase()).toBe('/nextcloud/index.php/apps/social')
	})

	/** News is gone; an address somebody kept for it opens their feed */
	it('sends the old News address to My Feed', async () => {
		globalThis.setInitialState('social', 'serverData', { public: false })
		vi.resetModules()
		const { default: testRouter } = await import('../../src/router.js')
		await testRouter.push('/timeline/news')

		expect(testRouter.currentRoute.value.name).toBe('timeline')
		expect(['home', 'timeline']).toContain(testRouter.currentRoute.value.params.type)
		document.getElementById('initial-state-social-serverData')?.remove()
	})
})
