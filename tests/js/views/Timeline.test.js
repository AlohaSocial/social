/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import Timeline from '../../../src/views/Timeline.vue'
import FirstPostCelebration from '../../../src/components/FirstPostCelebration.vue'
import HashtagFollowButton from '../../../src/components/HashtagFollowButton.vue'
import HashtagFollowedList from '../../../src/components/HashtagFollowedList.vue'
import TimelineSwitcher from '../../../src/components/TimelineSwitcher.vue'
import eventBus, { NOTIFICATIONS_READ } from '../../../src/services/eventBus.js'
import { useAccountStore } from '../../../src/store/account.js'
import { useNotificationsStore } from '../../../src/store/notifications.js'
import { useSettingsStore } from '../../../src/store/settings.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const ComposerStub = {
	name: 'Composer',
	props: ['defaultVisibility', 'initialMention', 'offerShort'],
	template: '<div class="composer-stub" />',
}
const OnThisDayStub = { name: 'OnThisDay', template: '<div class="on-this-day-stub" />' }
const AnnouncementsStub = { name: 'Announcements', template: '<div class="announcements-stub" />' }
const WeeklyRecapStub = { name: 'WeeklyRecap', template: '<div class="weekly-recap-stub" />' }
const ShortsBarStub = { name: 'ShortsBar', template: '<div class="shorts-bar-stub" />' }
const NotificationRequestsStub = { name: 'NotificationRequests', emits: ['changed'], template: '<div class="notification-requests-stub" />' }
const TimelineListStub = {
	name: 'TimelineList',
	props: ['type', 'showParents', 'reverseOrder', 'display'],
	template: '<ul class="timeline-list-stub" />',
}
const DirectMessagesStub = {
	name: 'DirectMessages',
	props: ['selectedConversationId'],
	template: '<section class="direct-messages-stub" />',
}
const FirstRunStub = {
	name: 'FirstRun',
	emits: ['done'],
	template: '<section class="first-run-stub" />',
}

let pinia
let accountStore
let timelineStore

function makeStore(serverData = {}) {
	pinia = createPinia()
	setActivePinia(pinia)
	accountStore = useAccountStore()
	timelineStore = useTimelineStore()
	// network actions are replaced, the synchronous ones stay real
	vi.spyOn(accountStore, 'fetchAccountInfo').mockResolvedValue(undefined)
	vi.spyOn(accountStore, 'followAccount').mockResolvedValue(undefined)
	vi.spyOn(timelineStore, 'changeTimelineType')
	vi.spyOn(timelineStore, 'celebrateFirstPost')
	vi.spyOn(useNotificationsStore(), 'fetchPendingRequests').mockResolvedValue(undefined)
	useSettingsStore().setServerData({ public: false, cloudAddress: 'https://cloud.example.org', firstrun: false, ...serverData })

	return pinia
}

function mountTimeline(route = {}) {
	return mount(Timeline, {
		global: {
			plugins: [pinia],
			mocks: { $route: { name: 'timeline', params: {}, query: {}, ...route }, $router: { replace: vi.fn() } },
			// OnThisDay reads the reader's own anniversaries on mount, and
			// Announcements what the instance is telling everybody; each is its
			// own request with its own tests, and left real they would answer
			// after these tests have finished
			stubs: { Announcements: AnnouncementsStub, Composer: ComposerStub, DirectMessages: DirectMessagesStub, FirstRun: FirstRunStub, TimelineList: TimelineListStub, RouterLink: RouterLinkStub, OnThisDay: OnThisDayStub, WeeklyRecap: WeeklyRecapStub, ShortsBar: ShortsBarStub, NotificationRequests: NotificationRequestsStub },
		},
	})
}

describe('Timeline', () => {
	beforeEach(() => {
		window.localStorage.clear()
		makeStore()
	})

	it('remembers a dismissed introduction and allows an explicit replay', async () => {
		accountStore.currentAccountHandle = 'alice@cloud.example'
		useSettingsStore().setServerDataEntry({ key: 'introductionDismissed', value: false })
		const first = mountTimeline({ query: { welcome: '1' } })
		expect(first.find('.first-run-stub').exists()).toBe(true)

		first.vm.hideInfo()
		await nextTick()
		expect(axios.post).toHaveBeenCalled()
		expect(first.find('.first-run-stub').exists()).toBe(false)
		expect(window.localStorage.getItem('social:introduction:alice@cloud.example')).toBe('dismissed')
		first.unmount()

		useSettingsStore().setServerDataEntry({ key: 'introductionDismissed', value: true })
		const refreshed = mountTimeline({ query: { welcome: '1' } })
		expect(refreshed.find('.first-run-stub').exists()).toBe(false)
		refreshed.unmount()

		const replay = mountTimeline({ query: { replay: '1' } })
		expect(replay.find('.first-run-stub').exists()).toBe(true)
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	// The three cards that belong to the reader's own feed and to no other
	// page. `isHome` used to compare `type` against the empty route parameter,
	// which the computed never answers with — so none of them had ever been
	// drawn on any page.
	describe('the reader\'s own feed', () => {
		const cards = (wrapper) => ({
			shorts: wrapper.find('.shorts-bar-stub').exists(),
			recap: wrapper.find('.weekly-recap-stub').exists(),
			memories: wrapper.find('.on-this-day-stub').exists(),
		})

		it('draws the Shorts bar, the recap and the memories on the home feed', () => {
			const wrapper = mountTimeline({ name: 'timeline', params: {} })

			expect(cards(wrapper)).toEqual({ shorts: true, recap: true, memories: true })
		})

		it.each([
			['timeline'],
			['federated'],
			['notifications'],
			['direct'],
			['photos'],
		])('draws none of them on %s', (type) => {
			const wrapper = mountTimeline({ name: 'timeline', params: { type } })

			expect(cards(wrapper)).toEqual({ shorts: false, recap: false, memories: false })
		})

		it('draws none of them on a hashtag page', () => {
			const wrapper = mountTimeline({ name: 'tags', params: { tag: 'nextcloud' } })

			expect(cards(wrapper)).toEqual({ shorts: false, recap: false, memories: false })
		})
	})

	it('switches the store to the home timeline when no type is in the route', () => {
		const wrapper = mountTimeline()
		expect(timelineStore.changeTimelineType).toHaveBeenCalledWith({ type: 'home', params: {} })
		expect(timelineStore.type).toBe('home')
		expect(wrapper.findComponent(TimelineListStub).props('type')).toBe('home')
		expect(wrapper.find('h2').exists()).toBe(false)
	})

	it.each(['timeline', 'federated', 'favourites'])('switches the store to the %s timeline from the route', (type) => {
		const wrapper = mountTimeline({ params: { type } })
		expect(timelineStore.changeTimelineType).toHaveBeenCalledWith({ type, params: {} })
		expect(wrapper.findComponent(TimelineListStub).props('type')).toBe(type)
	})

	it('shows the conversation interface for direct messages without fetching the old flat timeline', () => {
		const wrapper = mountTimeline({ params: { type: 'direct' } })
		expect(timelineStore.changeTimelineType).not.toHaveBeenCalled()
		expect(wrapper.findComponent(DirectMessagesStub).exists()).toBe(true)
		expect(wrapper.findComponent(TimelineListStub).exists()).toBe(false)
		expect(wrapper.find('.social__wrapper').classes()).toContain('social__wrapper--direct')
	})

	// what the instance is telling everybody belongs above the posts and above
	// the composer, on every timeline but a single post's page -- that one was
	// navigated to for that post
	it.each([
		[{ name: 'timeline', params: {} }, true],
		[{ name: 'timeline', params: { type: 'notifications' } }, true],
		[{ name: 'single-post', params: { type: 'single-post' } }, false],
	])('puts the announcements at the top of %o', (route, present) => {
		const wrapper = mountTimeline(route)

		expect(wrapper.find('.announcements-stub').exists()).toBe(present)
		if (present) {
			// the first thing in the column, above the composer
			expect(wrapper.element.firstElementChild.className).toContain('announcements-stub')
		}
	})

	it('resets the previously loaded posts when switching', () => {
		timelineStore.addToTimeline([{ id: 'old', created_at: '2026-01-01T00:00:00Z' }])
		mountTimeline({ params: { type: 'federated' } })
		expect(timelineStore.timeline).toEqual([])
	})

	// the types are the ones the sidebar and the store really use: `timeline`
	// is Local (the store sends `local: true` for it) and the liked timeline is
	// `favourites`, so Local used to be headed "Global timeline" and Liked
	// posts fell through to "Home timeline"
	it.each([
		[{ name: 'timeline', params: {} }, 'Home timeline', false],
		[{ name: 'timeline', params: { type: 'direct' } }, 'Direct messages', false],
		[{ name: 'timeline', params: { type: 'notifications' } }, 'Activities', true],
		[{ name: 'timeline', params: { type: 'timeline' } }, 'Local timeline', false],
		[{ name: 'timeline', params: { type: 'federated' } }, 'Global timeline', false],
		[{ name: 'timeline', params: { type: 'favourites' } }, 'Liked posts', false],
		[{ name: 'timeline', params: { type: 'bookmarks' } }, 'Bookmarks', false],
		// a page of its own like Photos and Videos, so it says which one you
		// are reading
	])('names the timeline %o for a reader who cannot see which one it is', (route, heading, visible) => {
		const wrapper = mountTimeline(route)
		const title = wrapper.find('h1')

		// every view has a heading now; only the two that always showed one stay visible
		expect(title.text()).toBe(heading)
		expect(title.classes('hidden-visually')).toBe(!visible)
	})

	it('loads a list timeline by its id and heads it with the title the server gives', async () => {
		axios.get.mockResolvedValueOnce({ data: { id: '4', title: 'Design', nextcloud_group: 'design' } })
		const wrapper = mountTimeline({ name: 'list', params: { id: '4' } })
		await flushPromises()

		expect(timelineStore.changeTimelineType).toHaveBeenCalledWith({ type: 'list', params: { id: '4' } })
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/lists/4')
		expect(wrapper.find('h1').text()).toBe('Design')
		// a list is a page of its own, so it says which one you are reading
		expect(wrapper.find('h1').classes('hidden-visually')).toBe(false)
		expect(wrapper.findComponent(TimelineListStub).props('type')).toBe('list')
	})

	it('heads a list it could not read as a list, rather than nothing', async () => {
		axios.get.mockRejectedValueOnce(new Error('404'))
		const wrapper = mountTimeline({ name: 'list', params: { id: '9' } })
		await flushPromises()

		expect(wrapper.find('h1').text()).toBe('List')
	})

	it('loads a hashtag timeline with the tag as parameter and shows the tag as heading', () => {
		const wrapper = mountTimeline({ name: 'tags', params: { tag: 'nextcloud' } })
		expect(timelineStore.changeTimelineType).toHaveBeenCalledWith({ type: 'tags', params: { tag: 'nextcloud' } })
		expect(wrapper.find('h1').text()).toBe('#nextcloud')
		expect(wrapper.findComponent(TimelineListStub).props('type')).toBe('tags')
		expect(wrapper.findComponent(ComposerStub).exists()).toBe(true)
	})

	it('shows the composer on the home timeline without a preset visibility', () => {
		const composer = mountTimeline().findComponent(ComposerStub)
		expect(composer.exists()).toBe(true)
		expect(composer.props('defaultVisibility')).toBeUndefined()
	})

	it('uses the conversation interface instead of the ordinary timeline composer for direct messages', () => {
		const wrapper = mountTimeline({ params: { type: 'direct' } })
		expect(wrapper.findComponent(ComposerStub).exists()).toBe(false)
		expect(wrapper.findComponent(DirectMessagesStub).exists()).toBe(true)
	})

	/** A feed of text is read in a narrower column; a grid of pictures keeps the wide one. */
	it('reads a list in the narrow column and shows a grid in the wide one', () => {
		expect(mountTimeline({ params: { type: 'home' } }).find('.social__wrapper').classes()).toContain('social__wrapper--reading')
		expect(mountTimeline({ params: { type: 'photos' } }).find('.social__wrapper').classes()).not.toContain('social__wrapper--reading')
	})

	it('offers the New short camera in the composer on the home feed only', () => {
		expect(mountTimeline({ params: { type: 'home' } }).findComponent(ComposerStub).props('offerShort')).toBe(true)
		expect(mountTimeline({ params: { type: 'timeline' } }).findComponent(ComposerStub).props('offerShort')).toBe(false)
	})

	it('hides the composer on the notifications timeline and titles it', () => {
		const wrapper = mountTimeline({ params: { type: 'notifications' } })
		expect(wrapper.findComponent(ComposerStub).exists()).toBe(false)
		expect(wrapper.find('h1').text()).toBe('Activities')
		expect(wrapper.findComponent(TimelineListStub).props('type')).toBe('notifications')
	})

	it('shows no search banner over a timeline it does not filter', async () => {
		// searching has its own route and asks the server; a banner saying
		// "Search: «…»" over the unfiltered timeline would be a lie
		const wrapper = mountTimeline()
		timelineStore.setSearchQuery('fediverse')
		await nextTick()
		expect(wrapper.find('.search-active').exists()).toBe(false)
		expect(wrapper.findComponent(TimelineListStub).exists()).toBe(true)
	})

	it('shows no introduction after the first run', () => {
		const wrapper = mountTimeline()
		expect(wrapper.findComponent(FirstRunStub).exists()).toBe(false)
	})

	it('shows the introduction after the reload that follows creating the account', () => {
		// the setup screen reloads with ?welcome=1; the server does not know
		// this load is the first, since the account existed when it answered
		const wrapper = mountTimeline({ query: { welcome: '1' } })
		expect(wrapper.findComponent(FirstRunStub).exists()).toBe(true)
	})

	// or reloading the page would bring it back, and so would Back
	it('takes the welcome out of the address once the introduction is closed', async () => {
		const replace = vi.fn()
		const wrapper = mount(Timeline, {
			global: {
				plugins: [pinia],
				mocks: {
					$route: { name: 'timeline', params: {}, query: { welcome: '1', other: 'x' } },
					$router: { replace },
				},
				stubs: { Announcements: AnnouncementsStub, Composer: ComposerStub, DirectMessages: DirectMessagesStub, FirstRun: FirstRunStub, TimelineList: TimelineListStub, RouterLink: RouterLinkStub, OnThisDay: OnThisDayStub, WeeklyRecap: WeeklyRecapStub, ShortsBar: ShortsBarStub, NotificationRequests: NotificationRequestsStub },
			},
		})
		await wrapper.findComponent(FirstRunStub).vm.$emit('done')

		expect(wrapper.findComponent(FirstRunStub).exists()).toBe(false)
		expect(replace).toHaveBeenCalledWith({ query: { other: 'x' } })
	})

	describe('on the first run', () => {
		beforeEach(() => {
			makeStore({ firstrun: true })
		})

		it('opens with the introduction in place of the old beta banner', () => {
			const wrapper = mountTimeline()
			expect(wrapper.findComponent(FirstRunStub).exists()).toBe(true)
			expect(wrapper.find('.social__welcome').exists()).toBe(false)
		})

		it('takes the introduction away for good once it says it is done', async () => {
			const wrapper = mountTimeline()
			await wrapper.findComponent(FirstRunStub).vm.$emit('done')
			expect(wrapper.findComponent(FirstRunStub).exists()).toBe(false)
		})
	})

	// the composer emits `post-published` for every post that goes out; which
	// of them is worth a celebration is decided here
	describe('the first post somebody publishes here', () => {
		const alice = {
			id: '42',
			url: 'https://cloud.example.org/users/alice',
			acct: 'alice',
			username: 'alice',
			display_name: 'Alice',
		}

		/**
		 * Puts the reader's own account in the store, as the page load does.
		 *
		 * @param {number} statusesCount how much this account has posted before
		 */
		const readerWithPosts = (statusesCount) => {
			accountStore.addAccount({ actorId: alice.url, data: { ...alice, statuses_count: statusesCount } })
			accountStore.setCurrentAccount('alice@cloud.example.org')
		}

		const publish = async (wrapper) => {
			eventBus.emit('post-published', { id: '1', content: '<p>hello fediverse</p>' })
			await nextTick()
			return wrapper.findComponent(FirstPostCelebration)
		}

		beforeEach(() => {
			vi.useFakeTimers()
			// the bus is a module singleton: no timeline left over from another
			// test gets to answer for this one
			eventBus.all.clear()
			window.localStorage.clear()
			makeStore()
			readerWithPosts(0)
		})

		afterEach(() => {
			vi.useRealTimers()
		})

		it('celebrates a first post, without the post waiting on it', async () => {
			const wrapper = mountTimeline()
			expect(wrapper.findComponent(FirstPostCelebration).exists()).toBe(false)

			const celebration = await publish(wrapper)

			expect(celebration.exists()).toBe(true)
			expect(celebration.text()).toContain('Your first post is out there')
			// the timeline the post lands in is untouched and still on screen
			expect(wrapper.findComponent(TimelineListStub).exists()).toBe(true)

			wrapper.unmount()
		})

		it('takes itself off screen again', async () => {
			const wrapper = mountTimeline()
			await publish(wrapper)

			vi.advanceTimersByTime(2600 + 300)
			await nextTick()

			expect(wrapper.findComponent(FirstPostCelebration).exists()).toBe(false)
			wrapper.unmount()
		})

		it('does not celebrate the second post', async () => {
			const wrapper = mountTimeline()
			await publish(wrapper)
			vi.advanceTimersByTime(2600 + 300)
			await nextTick()

			const again = await publish(wrapper)

			expect(again.exists()).toBe(false)
			wrapper.unmount()
		})

		it('never celebrates a reader who has posted before', async () => {
			readerWithPosts(1000)
			const wrapper = mountTimeline()

			expect((await publish(wrapper)).exists()).toBe(false)
			wrapper.unmount()
		})

		// a private window throws on every localStorage access; the timeline is
		// not allowed to go down with it
		it('survives a browser that refuses to store anything', async () => {
			vi.spyOn(window.localStorage, 'getItem').mockImplementation(() => {
				throw new Error('The operation is insecure')
			})
			vi.spyOn(window.localStorage, 'setItem').mockImplementation(() => {
				throw new Error('The operation is insecure')
			})
			const wrapper = mountTimeline()

			const celebration = await publish(wrapper)

			expect(celebration.exists()).toBe(true)
			expect(wrapper.findComponent(TimelineListStub).exists()).toBe(true)
			expect(wrapper.find('h1').text()).toBe('Home timeline')
			wrapper.unmount()
		})

		it('stops listening once the reader has navigated away', async () => {
			const wrapper = mountTimeline()
			wrapper.unmount()

			eventBus.emit('post-published', { id: '1' })
			await nextTick()

			expect(timelineStore.firstPostCelebration).toBe(false)
			expect(timelineStore.celebrateFirstPost).not.toHaveBeenCalled()
		})

		it('leaves no celebration standing for the next timeline when the reader navigates away mid-flight', async () => {
			const wrapper = mountTimeline()
			await publish(wrapper)
			expect(timelineStore.firstPostCelebration).toBe(true)

			wrapper.unmount()

			expect(timelineStore.firstPostCelebration).toBe(false)
		})
	})

	describe('following the hashtag a timeline is of', () => {
		const tag = (name, following) => ({ name, url: `https://cloud.example.org/tags/${name}`, history: [], following })

		/**
		 * @param {boolean} following whether the viewer already follows #nextcloud
		 * @param {object[]} followed the tags `/followed_tags` answers with
		 */
		const answerWith = (following = false, followed = []) => {
			axios.get.mockImplementation((url) => Promise.resolve({
				data: url.endsWith('/followed_tags') ? followed : tag('nextcloud', following),
			}))
			axios.post.mockImplementation((url) => Promise.resolve({
				data: tag('nextcloud', url.endsWith('/follow')),
			}))
		}

		const mountTags = async () => {
			const wrapper = mountTimeline({ name: 'tags', params: { tag: 'nextcloud' } })
			await flushPromises()
			return wrapper
		}

		beforeEach(() => {
			vi.clearAllMocks()
			answerWith()
		})

		it('offers to follow the hashtag next to its heading', async () => {
			const wrapper = await mountTags()

			const row = wrapper.find('.timeline-heading-row')
			expect(row.find('h1').text()).toBe('#nextcloud')
			expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/tags/nextcloud')
			expect(row.find('button').text()).toBe('Follow')
			expect(row.find('button').attributes('aria-pressed')).toBe('false')
		})

		it('says which hashtags are already followed', async () => {
			answerWith(true)
			const wrapper = await mountTags()

			expect(wrapper.findComponent(HashtagFollowButton).find('button').text()).toBe('Following')
		})

		it('follows the hashtag from its own timeline', async () => {
			const wrapper = await mountTags()

			await wrapper.findComponent(HashtagFollowButton).find('button').trigger('click')
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/tags/nextcloud/follow')
			expect(wrapper.findComponent(HashtagFollowButton).find('button').text()).toBe('Following')
		})

		it('has no hashtag to follow on any other timeline', async () => {
			expect((await mountTags()).findComponent(HashtagFollowButton).exists()).toBe(true)

			const wrapper = mountTimeline({ params: { type: 'federated' } })
			await flushPromises()

			expect(wrapper.findComponent(HashtagFollowButton).exists()).toBe(false)
			expect(wrapper.findComponent(HashtagFollowedList).exists()).toBe(false)
		})

		// nothing behind these routes answers without a viewer
		it('offers neither control to a reader who is not logged in', async () => {
			makeStore({ public: true })
			const wrapper = await mountTags()

			expect(wrapper.find('.timeline-heading-row button').exists()).toBe(false)
			expect(wrapper.find('.followed-hashtags').exists()).toBe(false)
			expect(axios.get).not.toHaveBeenCalled()

			makeStore({ public: false })
			const loggedIn = await mountTags()

			expect(loggedIn.find('.timeline-heading-row button').exists()).toBe(true)
			expect(loggedIn.find('.followed-hashtags').exists()).toBe(true)
		})

		it('lists the hashtags the reader follows, each linking to its timeline', async () => {
			answerWith(false, [tag('fediverse', true), tag('nextcloud', true)])
			const wrapper = await mountTags()

			await wrapper.find('.followed-hashtags__toggle').trigger('click')
			await flushPromises()

			const links = wrapper.findAllComponents(RouterLinkStub)
			expect(links.map((link) => link.text())).toEqual(['#fediverse', '#nextcloud'])
			expect(links[0].props('to')).toEqual({ name: 'tags', params: { tag: 'fediverse' } })
		})

		it('brings that list up to date when a hashtag is followed under it', async () => {
			answerWith(false, [tag('fediverse', true)])
			const wrapper = await mountTags()
			await wrapper.find('.followed-hashtags__toggle').trigger('click')
			await flushPromises()
			answerWith(true, [tag('fediverse', true), tag('nextcloud', true)])

			await wrapper.findComponent(HashtagFollowButton).find('button').trigger('click')
			await flushPromises()

			expect(wrapper.findAllComponents(RouterLinkStub).map((link) => link.text()))
				.toEqual(['#fediverse', '#nextcloud'])
		})
	})

	// the switcher

	/**
	 * The three timelines a reader moves between all day are on the page
	 * rather than only in the sidebar, because switching between them is
	 * something you do while reading.
	 */
	it.each([
		['home', {}],
		['local', { params: { type: 'timeline' } }],
		['global', { params: { type: 'federated' } }],
		['photos', { params: { type: 'photos' } }],
		['videos', { params: { type: 'videos' } }],
	])('offers the switcher on the %s timeline', (name, route) => {
		const wrapper = mountTimeline(route)

		expect(wrapper.findComponent(TimelineSwitcher).exists()).toBe(true)
	})

	it('tells the switcher which of the three is on screen', () => {
		const wrapper = mountTimeline({ params: { type: 'federated' } })
		const switcher = wrapper.findComponent(TimelineSwitcher)

		expect(switcher.props('value')).toBe('federated')
		expect(switcher.props('options').map((option) => option.label))
			.toEqual(['My Feed', 'Local', 'Global'])
	})

	/**
	 * `home` is the route with no `type` at all: passing `type: 'home'` would
	 * ask for a timeline of that name, which nothing serves.
	 */
	it('gives the switcher a route for each of the three', () => {
		const wrapper = mountTimeline()

		expect(wrapper.findComponent(TimelineSwitcher).props('options').map((option) => option.to))
			.toEqual([
				{ name: 'timeline' },
				{ name: 'timeline', params: { type: 'timeline' } },
				{ name: 'timeline', params: { type: 'federated' } },
			])
	})

	// photos and videos: the same three scopes, read as a query on one page

	/**
	 * One page with a scope on it rather than three pages of its own, so the
	 * sidebar entry stays lit whichever is chosen — and Photos and Videos
	 * cannot send a reader from one to the other.
	 */
	it.each(['photos', 'videos'])('keeps the %s feeds on their own page, as a scope', (type) => {
		const wrapper = mountTimeline({ params: { type } })

		expect(wrapper.findComponent(TimelineSwitcher).props('options').map((option) => option.to))
			.toEqual([
				{ name: 'timeline', params: { type }, query: {} },
				{ name: 'timeline', params: { type }, query: { scope: 'timeline' } },
				{ name: 'timeline', params: { type }, query: { scope: 'federated' } },
			])
	})

	it.each([
		['photos', {}, 'home'],
		['photos', { scope: 'timeline' }, 'timeline'],
		['photos', { scope: 'federated' }, 'federated'],
		['videos', {}, 'home'],
		['videos', { scope: 'timeline' }, 'timeline'],
		['videos', { scope: 'federated' }, 'federated'],
	])('reads the %s scope %o as %s', (type, query, scope) => {
		const wrapper = mountTimeline({ params: { type }, query })

		expect(wrapper.findComponent(TimelineSwitcher).props('value')).toBe(scope)
	})

	/** It arrives from the address bar, so it is read rather than trusted. */
	it.each(['', 'home', 'notifications', 'nonsense'])('falls back to My Feed for the scope %s', (scope) => {
		const wrapper = mountTimeline({ params: { type: 'photos' }, query: { scope } })

		expect(wrapper.findComponent(TimelineSwitcher).props('value')).toBe('home')
	})

	/** The scope is part of what identifies the timeline, or the previous
	 *  photos would stay on screen when it changes. */
	it.each(['photos', 'videos'])('refetches the %s when the scope changes', (type) => {
		mountTimeline({ params: { type }, query: { scope: 'federated' } })

		expect(timelineStore.changeTimelineType)
			.toHaveBeenCalledWith({ type, params: { scope: 'federated' } })
	})

	/**
	 * Everywhere else it would be a switch between three places you are not:
	 * these views are reached from the sidebar and are not read at three
	 * distances. Notifications is the exception -- the same control, choosing
	 * a filter of the one page rather than one page of three.
	 */
	it.each(['direct', 'favourites', 'bookmarks'])(
		'does not offer the switcher on %s',
		(type) => {
			const wrapper = mountTimeline({ params: { type } })

			expect(wrapper.findComponent(TimelineSwitcher).exists()).toBe(false)
		},
	)

	describe('the notifications filter', () => {
		const filters = (wrapper) => wrapper.findComponent(TimelineSwitcher)

		afterEach(() => {
			window.localStorage.removeItem('social.notificationsFilter')
		})

		it('offers one row for every kind of activity', () => {
			const wrapper = mountTimeline({ params: { type: 'notifications' } })

			expect(filters(wrapper).props('options').map((option) => option.label))
				.toEqual(['All', 'Mentions', 'Favourites', 'Boosts', 'Follows', 'Polls', 'Edits'])
			// a filter of this page, not a page of its own
			expect(filters(wrapper).props('options').every((option) => option.to === undefined)).toBe(true)
		})

		it('makes the chosen filter part of what the timeline is', async () => {
			const wrapper = mountTimeline({ params: { type: 'notifications' } })

			await filters(wrapper).vm.$emit('update:value', 'favourites')

			expect(timelineStore.changeTimelineType)
				.toHaveBeenCalledWith({ type: 'notifications', params: { filter: 'favourites' } })
		})

		it('opens again where the reader left it', async () => {
			const first = mountTimeline({ params: { type: 'notifications' } })
			await filters(first).vm.$emit('update:value', 'follows')

			const second = mountTimeline({ params: { type: 'notifications' } })

			expect(filters(second).props('value')).toBe('follows')
		})
	})

	// The badge in the sidebar says how many; until this there was nothing at
	// the other end of it saying so, and no way to answer it except to look at
	// the page for two seconds and let it mark itself.
	describe('the senders the policy is holding back', () => {
		const strip = (wrapper) => wrapper.find('.held-requests')

		const withHeld = (count) => {
			useNotificationsStore().setPendingRequests(count)
		}

		it('says how many are waiting, above the activities, and offers to review them', async () => {
			withHeld(3)
			const wrapper = mountTimeline({ params: { type: 'notifications' } })

			expect(strip(wrapper).text()).toContain('3 people you have no relationship with are waiting to notify you')
			expect(strip(wrapper).find('.notification-requests-stub').exists()).toBe(false)

			await strip(wrapper).findComponent({ name: 'NcButton' }).trigger('click')

			expect(strip(wrapper).find('.notification-requests-stub').exists()).toBe(true)
		})

		it('asks the server for the count when the activities open', () => {
			mountTimeline({ params: { type: 'notifications' } })

			expect(useNotificationsStore().fetchPendingRequests).toHaveBeenCalled()
		})

		it('is not there when nobody is held, and never on another page', () => {
			withHeld(0)
			expect(strip(mountTimeline({ params: { type: 'notifications' } })).exists()).toBe(false)
			withHeld(4)
			expect(strip(mountTimeline({ params: { type: 'home' } })).exists()).toBe(false)
		})

		it('follows the list: an emptied list folds away with its count', async () => {
			withHeld(1)
			const wrapper = mountTimeline({ params: { type: 'notifications' } })
			await strip(wrapper).findComponent({ name: 'NcButton' }).trigger('click')

			wrapper.findComponent(NotificationRequestsStub).vm.$emit('changed', 0)
			await wrapper.vm.$nextTick()

			expect(strip(wrapper).exists()).toBe(false)
			expect(useNotificationsStore().pendingRequests).toBe(0)
		})

		it('links to where the policy is set', () => {
			withHeld(2)
			const links = mountTimeline({ params: { type: 'notifications' } }).find('.held-requests').findAllComponents(RouterLinkStub)

			expect(links.map((link) => link.props('to'))).toContainEqual({ name: 'settings', hash: '#notification-policy' })
		})
	})

	describe('the count above the activities', () => {
		const header = (wrapper) => wrapper.find('.new-activities')

		const withUnread = (count) => {
			const notificationsStore = useNotificationsStore()
			notificationsStore.setUnreadNotifications(count)

			return notificationsStore
		}

		it('says how many there are, in the badge\'s own number', () => {
			withUnread(12)

			expect(header(mountTimeline({ params: { type: 'notifications' } })).text())
				.toContain('12 new activities')
		})

		it('counts one of them in the singular', () => {
			withUnread(1)

			expect(header(mountTimeline({ params: { type: 'notifications' } })).text())
				.toContain('1 new activity')
		})

		it('is not there at all when nothing has arrived', () => {
			withUnread(0)

			expect(header(mountTimeline({ params: { type: 'notifications' } })).exists()).toBe(false)
		})

		it('belongs to the activities and to no other page', () => {
			withUnread(5)

			expect(header(mountTimeline({ params: {} })).exists()).toBe(false)
		})

		it('marks the lot read and tells the list where the line went', async () => {
			const notificationsStore = withUnread(4)
			vi.spyOn(notificationsStore, 'markAllRead').mockResolvedValue('90')
			const heard = vi.fn()
			eventBus.on(NOTIFICATIONS_READ, heard)

			const wrapper = mountTimeline({ params: { type: 'notifications' } })
			await header(wrapper).find('button').trigger('click')
			await flushPromises()

			expect(notificationsStore.markAllRead).toHaveBeenCalled()
			// the list froze its line when it opened, so it has to be told
			expect(heard).toHaveBeenCalledWith('90')
			eventBus.off(NOTIFICATIONS_READ, heard)
		})

		it('says nothing to the list when the marker did not move', async () => {
			const notificationsStore = withUnread(4)
			vi.spyOn(notificationsStore, 'markAllRead').mockResolvedValue('0')
			const heard = vi.fn()
			eventBus.on(NOTIFICATIONS_READ, heard)

			const wrapper = mountTimeline({ params: { type: 'notifications' } })
			await header(wrapper).find('button').trigger('click')
			await flushPromises()

			expect(heard).not.toHaveBeenCalled()
			eventBus.off(NOTIFICATIONS_READ, heard)
		})
	})

	it('does not offer the switcher on a hashtag timeline', () => {
		const wrapper = mountTimeline({ name: 'tags', params: { tag: 'nextcloud' } })

		expect(wrapper.findComponent(TimelineSwitcher).exists()).toBe(false)
	})
	/** the way into the one-at-a-time stack is Shorts in the sidebar now, not a button here */
	it('carries no Watch button on the Videos page', () => {
		const wrapper = mountTimeline({ params: { type: 'videos' } })

		expect(wrapper.text()).not.toContain('Watch')
		expect(wrapper.find('.timeline-heading-row__watch').exists()).toBe(false)
	})

	describe('how the posts are drawn', () => {
		// the same rule the profile follows: Posts is what somebody wrote, which
		// is a list; Photos and Videos are what they showed, which is a grid.
		// These two pages were a list here and a grid on a profile, so the same
		// picture was a row in one place and a tile in the other.
		it.each(['photos', 'videos'])('draws %s as a grid', (type) => {
			const wrapper = mountTimeline({ params: { type } })

			expect(wrapper.findComponent(TimelineListStub).props('display')).toBe('grid')
		})

		it.each(['', 'timeline', 'federated', 'notifications'])('draws %s as a list', (type) => {
			const wrapper = mountTimeline({ params: { type } })

			expect(wrapper.findComponent(TimelineListStub).props('display')).toBe('list')
		})
	})

	describe('link', () => {
		/**
		 * The page about one article. The link is the subject, so another link
		 * is another page rather than the same page filtered.
		 */
		it('carries the article a link page is about into the store', () => {
			mountTimeline({ params: { type: 'link' }, query: { url: 'https://paper.example/piece' } })

			expect(timelineStore.changeTimelineType)
				.toHaveBeenCalledWith({ type: 'link', params: { url: 'https://paper.example/piece' } })
		})

		it('heads a link page with where the article lives', () => {
			const wrapper = mountTimeline({ params: { type: 'link' }, query: { url: 'https://paper.example/piece' } })

			expect(wrapper.find('h1').text()).toBe('paper.example')
		})

		it('heads a link page that carries no readable link with the plain word', () => {
			const wrapper = mountTimeline({ params: { type: 'link' }, query: { url: 'not a url' } })

			expect(wrapper.find('h1').text()).toBe('Link')
		})
	})
})
