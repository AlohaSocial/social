/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import Statistics from '../../../src/views/Statistics.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

// @nextcloud/auth reads the user from <head>, which the harness does not set
vi.mock('@nextcloud/auth', async (importOriginal) => ({
	...(await importOriginal()),
	getCurrentUser: () => ({ uid: 'alice', displayName: 'Alice Cooper', isAdmin: false }),
}))

/** @param {number[]} spikes one day each, the rest of the window quiet */
function series(spikes = {}) {
	return Array.from({ length: 30 }, (unused, day) => spikes[day] ?? 0)
}

function answer(overrides = {}) {
	return {
		account: {
			acct: 'alice',
			display_name: 'Alice',
			created_at: '2024-03-17T09:00:00.000Z',
			followers: 26,
			following: 24,
		},
		posts: {
			total: 43,
			originals: 34,
			replies: 9,
			boosts: 0,
			with_media: 4,
			sensitive: 1,
		},
		engagement: {
			likes: 185,
			boosts: 43,
			replies: 5,
			likes_per_post: 4.3,
			boosts_per_post: 1,
			replies_per_post: 0.1,
		},
		visibility: { public: 42, unlisted: 0, followers: 1, direct: 0 },
		rates: {
			total: 233,
			per_post: 5.4,
			applause: 4.3,
			amplification: 1,
			conversation: 0.1,
			per_follower: 20.84,
			median: 7,
			best: 14,
			silent: 16,
			silent_share: 37.2,
		},
		by_weekday: [
			{ day: 0, posts: 1, engagement: 0, average: 0 },
			{ day: 1, posts: 7, engagement: 65, average: 9.3 },
			{ day: 2, posts: 4, engagement: 0, average: 0 },
			{ day: 3, posts: 4, engagement: 24, average: 6 },
			{ day: 4, posts: 5, engagement: 37, average: 7.4 },
			{ day: 5, posts: 6, engagement: 48, average: 8 },
			{ day: 6, posts: 16, engagement: 59, average: 3.7 },
		],
		best_hour: { hour: 8, average: 9.3, posts: 3 },
		content: [
			{ key: 'text_only', posts: 39, engagement: 199, average: 5.1 },
			{ key: 'with_media', posts: 4, engagement: 35, average: 8.8 },
			{ key: 'original', posts: 34, engagement: 180, average: 5.3 },
		],
		hashtag_performance: [
			{ name: 'nextcloud', posts: 6, average: 9.3 },
			{ name: 'accessibility', posts: 4, average: 3 },
		],
		audience: {
			by_month: { '2026-08': 4, '2026-09': 22 },
			instances: [{ host: 'remote.example', count: 20 }, { host: 'cloud.example', count: 6 }],
			local_share: 23.1,
			counted: 26,
			capped: false,
		},
		engagement_by_month: { '2026-08': 180, '2026-09': 53 },
		by_month: { '2026-08': 39, '2026-09': 4 },
		by_hour: Array.from({ length: 24 }, (unused, hour) => (hour === 11 ? 5 : 0)),
		hashtags: [{ name: 'nextcloud', count: 6 }, { name: 'a11y', count: 1 }],
		best: [
			{ id: '7', url: 'https://cloud.example.org/@alice/7', published_at: '2026-09-01T10:00:00.000Z', excerpt: 'A good one', likes: 9, boosts: 4, replies: 2 },
		],
		window: { days: 0, choices: [0, 30, 90, 365], cached: false, counted: 43, capped: false, max: 2000, first_at: '2026-08-03T21:30:34.000Z', last_at: '2026-09-12T23:20:02.000Z' },
		periods: {
			days: 30,
			current: {
				posts: 4,
				reach: 1200,
				interactions: 184,
				likes: 83,
				boosts: 81,
				replies: 20,
				from: '2026-08-16T00:00:00.000Z',
				until: '2026-09-14T23:59:59.000Z',
				series: {
					reach: series({ 3: 900, 27: 300 }),
					interactions: series({ 3: 120, 27: 64 }),
					likes: series({ 3: 60, 27: 23 }),
					boosts: series({ 3: 50, 27: 31 }),
				},
			},
			previous: {
				posts: 2,
				reach: 400,
				interactions: 61,
				likes: 46,
				boosts: 14,
				replies: 1,
				from: '2026-07-17T00:00:00.000Z',
				until: '2026-08-15T23:59:59.000Z',
				series: {
					reach: series({ 10: 400 }),
					interactions: series({ 10: 61 }),
					likes: series({ 10: 46 }),
					boosts: series({ 10: 14 }),
				},
			},
			change: { posts: 100, reach: 200, interactions: 201.6, likes: 80.4, boosts: 478.6, replies: null },
		},
		timeline: [
			{ id: '7', url: 'https://cloud.example.org/@alice/7', published_at: '2026-09-08T15:42:00.000Z', excerpt: 'The loud one', likes: 15, boosts: 7, replies: 3, score: 25, media: false, visibility: 'public', reach: 900 },
			{ id: '6', url: 'https://cloud.example.org/@alice/6', published_at: '2026-09-06T14:12:00.000Z', excerpt: 'The quiet one', likes: 1, boosts: 0, replies: 0, score: 1, media: false, visibility: 'public', reach: 300 },
		],
		reach: { followers: 26, known_boosters: 5, unknown_boosters: 0, listed: 100, instances: 14 },
		activity: {
			originals: { '2026-08': 30, '2026-09': 4 },
			replies: { '2026-08': 8, '2026-09': 1 },
			boosts: { '2026-08': 1, '2026-09': 0 },
		},
		consistency: { active_days: 12, span_days: 41, share: 29.3, longest_gap: 9, streak: 4 },
		media: { images: 4, described: 3, described_share: 75 },
		languages: [{ name: 'en', count: 30 }, { name: 'de', count: 13 }],
		domains: [{ name: 'nextcloud.com', count: 7 }, { name: 'joinfediverse.wiki', count: 2 }],
		partners: {
			inbound: [
				{ id: 'https://remote.example/users/bob', account: 'bob@remote.example', replies: 7, followed: true },
				{ id: 'https://remote.example/users/carol', account: 'carol@remote.example', replies: 2, followed: false },
			],
			outbound: [
				{ id: 'https://remote.example/users/dave', account: 'dave@remote.example', replies: 5, followed: true },
			],
			not_followed_share: 50,
			listed: 10,
		},
		...overrides,
	}
}

function mountPage() {
	return mount(Statistics, {
		global: { stubs: { RouterLink: RouterLinkStub } },
		attachTo: document.body,
	})
}

const text = (wrapper) => wrapper.text().replace(/\s+/g, ' ')

describe('Statistics', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		document.body.innerHTML = ''
	})

	it('asks the server once and shows what came back', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get.mock.calls[0][0]).toContain('/apps/social/api/v1/statistics')
		expect(text(wrapper)).toContain('@alice')
		expect(text(wrapper)).toContain('185')
		expect(text(wrapper)).toContain('likes received')
		expect(text(wrapper)).toContain('4.3 per post')
	})

	it('shows a spinner rather than an empty page while it counts', async () => {
		axios.get.mockReturnValue(new Promise(() => {}))

		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('.stats__loading').exists()).toBe(true)
		expect(wrapper.find('.stats__card').exists()).toBe(false)
	})

	it('offers to try again rather than showing nothing when the count fails', async () => {
		axios.get.mockRejectedValue(new Error('nope'))

		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('.stats__error').exists()).toBe(true)

		axios.get.mockResolvedValue({ data: answer() })
		await wrapper.find('.stats__error button').trigger('click')
		await flushPromises()

		expect(wrapper.find('.stats__error').exists()).toBe(false)
		expect(text(wrapper)).toContain('@alice')
	})

	it('scales the bars to the tallest column, so a quiet month is still visible', async () => {
		axios.get.mockResolvedValue({ data: answer({ by_month: { '2026-07': 0, '2026-08': 39, '2026-09': 4 } }) })

		const wrapper = mountPage()
		await flushPromises()

		const months = wrapper.findAll('.stats__bars--posts .stats__bar')
		expect(months).toHaveLength(3)
		expect(months[1].attributes('style')).toContain('--height: 100%')
		expect(months[2].attributes('style')).toContain('--height: 10%')
		// nothing posted is nothing, not a bar of its own height
		expect(months[0].attributes('style')).toContain('--height: 0%')
	})

	it('says what every bar holds, for a reader who cannot see its height', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const hours = wrapper.findAll('.stats__bars--hours li')
		expect(hours).toHaveLength(24)
		expect(hours[11].attributes('title')).toContain('11:00')
		expect(hours[11].attributes('title')).toContain('5')
	})

	it('links a post to the post, and a hashtag to its timeline', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const to = (selector) => wrapper.findComponent(selector).props('to')
		expect(to('.stats__post a')).toEqual({ name: 'single-post', params: { account: 'alice', id: '7' } })
		expect(to('.stats__best a')).toEqual({ name: 'single-post', params: { account: 'alice', id: '7' } })
		expect(to('.stats__tags a')).toEqual({ name: 'tags', params: { tag: 'nextcloud' } })
	})

	it('says how far back the numbers go, and says so differently when it stopped early', async () => {
		axios.get.mockResolvedValue({ data: answer() })
		const wrapper = mountPage()
		await flushPromises()
		expect(text(wrapper)).toContain('43')

		axios.get.mockResolvedValue({
			data: answer({ window: { counted: 2000, capped: true, max: 2000, first_at: '', last_at: '' } }),
		})
		const capped = mountPage()
		await flushPromises()
		expect(text(capped)).toContain('most recent posts')
		// and the coverage bar says it stopped at its ceiling rather than at the end
		expect(capped.find('.stats__coverage-fill').classes()).toContain('stats__coverage-fill--capped')
		expect(text(capped)).toContain('as far back as this page goes')
	})

	it('shows the rates an agency reports on, not only the totals', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const shown = text(wrapper)
		expect(shown).toContain('5.4engagement per post')
		expect(shown).toContain('median 7')
		// one decimal on screen, whatever the server sent
		expect(shown).toContain('20.8%engagement rate')
		expect(shown).toContain('37.2%got no answer')
		// the best post against the middle one, which is the number a mean hides
		expect(shown).toContain('2× the median')
	})

	it('sorts what works by what it averages, best first', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const labels = wrapper.findAll('.stats__card')
			.find((card) => card.text().includes('What works'))
			.findAll('.stats__row dt')
			.map((dt) => dt.text())

		expect(labels[0]).toBe('With a picture or a video')
		expect(labels).toContain('Text only')
	})

	it('names the hour that works, and says nothing when there is too little to go on', async () => {
		axios.get.mockResolvedValue({ data: answer() })
		const wrapper = mountPage()
		await flushPromises()
		expect(text(wrapper)).toContain('8:00 UTC')

		axios.get.mockResolvedValue({ data: answer({ best_hour: { hour: null, average: 0, posts: 0 } }) })
		const quiet = mountPage()
		await flushPromises()
		expect(text(quiet)).toContain('Not enough posts at any one hour')
	})

	it('names the weekdays in the reader\'s own calendar, Sunday first as the server counts them', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const days = wrapper.findAll('.stats__card')
			.find((card) => card.text().includes('When your posts do best'))
			.findAll('.stats__row dt')
			.map((dt) => dt.text())

		expect(days).toHaveLength(7)
		expect(days[0]).toBe(new Date(Date.UTC(2024, 0, 7)).toLocaleDateString(undefined, { weekday: 'long', timeZone: 'UTC' }))
	})

	/**
	 * The server counts in UTC and keys its months `YYYY-MM`. The first
	 * midnight of September in UTC is the evening of 31 August in New York,
	 * and read in the local zone every chart there was a month out and every
	 * weekday a day out.
	 */
	describe('west of UTC', () => {
		let zone

		beforeEach(() => {
			zone = process.env.TZ
			process.env.TZ = 'America/New_York'
		})

		afterEach(() => {
			if (zone === undefined) {
				delete process.env.TZ
			} else {
				process.env.TZ = zone
			}
		})

		const long = (month) => new Date(Date.UTC(2026, month, 15)).toLocaleDateString(undefined, { year: 'numeric', month: 'long' })

		it('names each month\'s column by the month it counts', async () => {
			axios.get.mockResolvedValue({ data: answer({ by_month: { '2026-08': 39, '2026-09': 4 } }) })

			const wrapper = mountPage()
			await flushPromises()

			const months = wrapper.findAll('.stats__bars--posts li')
			expect(months[1].attributes('title')).toContain(long(8))
			expect(months[0].attributes('title')).toContain(long(7))
		})

		it('names the month in what the account did', async () => {
			axios.get.mockResolvedValue({ data: answer() })

			const wrapper = mountPage()
			await flushPromises()

			const titles = wrapper.findAll('.stats__stack-bars').map((bar) => bar.attributes('title'))
			expect(titles[0]).toContain(long(7))
			expect(titles[1]).toContain(long(8))
		})

		it('starts the weekdays on Sunday', async () => {
			axios.get.mockResolvedValue({ data: answer() })

			const wrapper = mountPage()
			await flushPromises()

			const first = wrapper.findAll('.stats__card')
				.find((card) => card.text().includes('When your posts do best'))
				.find('.stats__row dt')
				.text()
			// 2024-01-07 at noon is a Sunday in every zone on Earth
			expect(first).toBe(new Date(2024, 0, 7, 12).toLocaleDateString(undefined, { weekday: 'long' }))
		})
	})

	it('says where the audience is and how much of it is on this server', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const shown = text(wrapper)
		expect(shown).toContain('remote.example')
		expect(shown).toContain('23.1% of your followers are on this server')
		expect(wrapper.findAll('.stats__bars--followers .stats__bar')).toHaveLength(2)
	})

	it('puts the two windows side by side, each figure against the one before it', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const cards = wrapper.findAll('.stats__kpi')
		expect(cards).toHaveLength(4)
		expect(text(cards[0])).toContain('Estimated reach')
		expect(text(cards[0])).toContain('1,200')
		// what it was over the window before, so the reader can check the claim
		expect(text(cards[0])).toContain('Previous total400')
		expect(text(cards[1])).toContain('↑ +201.6%')
		expect(text(wrapper)).toContain('30 days. Directly comparable.')
	})

	it('draws both windows against the taller of the two, so the pair can be read as one picture', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const spark = wrapper.findAll('.stats__kpi')[0].find('.stats__spark')
		const current = spark.find('.stats__spark-line--current').attributes('points').split(' ')
		const previous = spark.find('.stats__spark-line--previous').attributes('points').split(' ')

		expect(current).toHaveLength(30)
		expect(previous).toHaveLength(30)
		// the tallest day of either line touches the top; the other does not
		expect(current[3]).toBe('10.34,2')
		expect(previous[10]).toBe('34.48,17.56')
		// and a day nothing happened on sits on the floor
		expect(current[0]).toBe('0,30')
	})

	it('says new rather than a percentage against a window that held nothing', async () => {
		axios.get.mockResolvedValue({
			data: answer({
				periods: {
					...answer().periods,
					change: { posts: null, reach: null, interactions: null, likes: null, boosts: null, replies: null },
				},
			}),
		})

		const wrapper = mountPage()
		await flushPromises()

		expect(text(wrapper.findAll('.stats__kpi')[0])).toContain('new')
		expect(text(wrapper)).not.toContain('Infinity')
	})

	it('lists the window post by post, and reorders them without asking the server again', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const posts = () => wrapper.findAll('.stats__post').map((post) => post.text())
		expect(posts()[0]).toContain('The loud one')
		expect(posts()[0]).toContain('900')

		// the bar is each post's reach against the best of them
		const bars = wrapper.findAll('.stats__post-fill')
		expect(bars[0].attributes('style')).toContain('--share: 100%')
		expect(bars[1].attributes('style')).toContain('--share: 33%')

		await wrapper.find('.stats__sort select').setValue('engagement')
		expect(posts()[0]).toContain('The loud one')
		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('admits the boosters whose audience it cannot know', async () => {
		axios.get.mockResolvedValue({ data: answer() })
		const wrapper = mountPage()
		await flushPromises()
		expect(text(wrapper)).toContain('Reach is an estimate')
		expect(text(wrapper)).not.toContain('not known here')

		axios.get.mockResolvedValue({
			data: answer({ reach: { followers: 26, known_boosters: 1, unknown_boosters: 3, listed: 100 } }),
		})
		const gappy = mountPage()
		await flushPromises()
		expect(text(gappy)).toContain('audiences of 3 accounts')
	})

	it('heads the page with a name rather than a login, when the account has never been given one', async () => {
		// what a local account that never opened the profile editor carries
		axios.get.mockResolvedValue({
			data: answer({ account: { ...answer().account, acct: 'alice', display_name: 'alice' } }),
		})

		const wrapper = mountPage()
		await flushPromises()

		expect(text(wrapper)).toContain('Alice Cooper')
		expect(text(wrapper)).toContain('@alice')
	})

	it('shows whose numbers these are, and how much of their history was counted', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const shown = text(wrapper)
		expect(shown).toContain('Analysed account')
		// the name the account publishes under, not the Nextcloud one
		expect(shown).toContain('Alice')
		expect(shown).not.toContain('Alice Cooper')
		expect(shown).toContain('26current followers')
		expect(shown).toContain('43 posts, all of them')
		// the walk finished, so the bar is full and plain
		expect(wrapper.find('.stats__coverage-fill').classes()).not.toContain('stats__coverage-fill--capped')
	})

	it('leaves out the sections the answer has nothing for', async () => {
		axios.get.mockResolvedValue({ data: answer({ best: [], hashtags: [] }) })

		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('.stats__best').exists()).toBe(false)
		expect(wrapper.find('.stats__tags').exists()).toBe(false)

		axios.get.mockResolvedValue({ data: answer({ audience: undefined, content: [], by_weekday: [] }) })
		const bare = mountPage()
		await flushPromises()

		expect(bare.text()).not.toContain('Who is listening')
		expect(bare.text()).not.toContain('What works')
		expect(bare.text()).not.toContain('When your posts do best')
	})

	/**
	 * The figures above say how big the fediverse is today, which cannot
	 * answer the thing somebody deciding whether to write here wants to know:
	 * whether this is somewhere more people are arriving, or leaving.
	 */
	describe('how the fediverse has grown', () => {
		const GROWTH = {
			months: [
				{ month: '2025-10', servers: 40000, accounts: 30000000, active: 1000000, posts: 1500000000 },
				{ month: '2025-11', servers: 41000, accounts: 31000000, active: 1050000, posts: 1550000000 },
				{ month: '2026-09', servers: 49683, accounts: 38069836, active: 1451756, posts: 1913585339 },
			],
			change: {
				month: { servers: 0.4, accounts: 3.0, active: 3.8, posts: 3.3 },
				year: { servers: 5.8, accounts: 12.1, active: 28.2, posts: 4.1 },
			},
			coverage_changed: false,
			source: 'Fediverse Observer',
			source_url: 'https://fediverse.observer',
		}

		/**
		 * @param {object|undefined} growth what the server sent
		 * @return {Promise<object>} the page, drawn
		 */
		async function mountGrowth(growth) {
			axios.get.mockResolvedValue({ data: answer(growth === undefined ? {} : { growth }) })
			const wrapper = mountPage()
			await flushPromises()

			return wrapper
		}

		it('draws the section with what changed and who counted it', async () => {
			const wrapper = await mountGrowth(GROWTH)

			expect(wrapper.find('.stats__growth').exists()).toBe(true)
			expect(wrapper.text()).toContain('How the fediverse has grown')
			expect(wrapper.text()).toContain('↑ +3%')
			expect(wrapper.text()).toContain('Fediverse Observer')
		})

		/**
		 * The first thing anybody comparing the two cards will ask is why the
		 * numbers differ, and the answer is that they are different surveys.
		 */
		it('says why its numbers differ from the ones above', async () => {
			const wrapper = await mountGrowth(GROWTH)

			expect(wrapper.text()).toContain('crawl different servers')
		})

		it('draws the chart with the months it covers named', async () => {
			const wrapper = await mountGrowth(GROWTH)

			expect(wrapper.find('.stats__area-line').attributes('points')).toBeTruthy()
			// closed along the bottom, so the area under it can be filled
			expect(wrapper.find('.stats__area-fill').attributes('points')).toContain('0,40')
			expect(wrapper.find('.stats__growth-span').text()).toContain('2025')
		})

		/**
		 * A series from thirty million to thirty-eight, drawn from zero, is a
		 * flat line with a lot of empty chart under it.
		 */
		it('scales the line to its own range rather than to zero', async () => {
			const wrapper = await mountGrowth(GROWTH)
			const ys = wrapper.find('.stats__area-line').attributes('points')
				.split(' ')
				.map((point) => Number(point.split(',')[1]))

			expect(Math.max(...ys) - Math.min(...ys)).toBeGreaterThan(20)
		})

		/**
		 * A young instance talks to two servers out of forty thousand, which
		 * is 0.0%: a true number that tells its reader nothing.
		 */
		it('counts the servers it reaches when the share rounds to nothing', async () => {
			axios.get.mockResolvedValue({
				data: answer({ growth: GROWTH, network: { peers: 2, servers: 42809, accounts: 1, active: 1, measured: '', source: 'FediDB', source_url: 'https://fedidb.org' } }),
			})
			const wrapper = mountPage()
			await flushPromises()

			expect(wrapper.find('.stats__derived').text()).toContain('2')
			expect(wrapper.find('.stats__derived').text()).toContain('of 42,809 servers')
			expect(wrapper.find('.stats__derived').text()).not.toContain('0%')
		})

		it('says what the numbers mean rather than leaving it as arithmetic', async () => {
			const wrapper = await mountGrowth(GROWTH)

			expect(wrapper.find('.stats__derived').text()).toContain('posted last month')
			expect(wrapper.find('.stats__derived').text()).toContain('posts per account')
		})

		/**
		 * A reader who is not told reads a step in the line as the network
		 * doubling, which is the one thing it is not.
		 */
		it('explains a missing year rather than leaving a gap', async () => {
			const wrapper = await mountGrowth({
				...GROWTH,
				change: { month: GROWTH.change.month, year: {} },
				coverage_changed: true,
			})

			expect(wrapper.text()).toContain('reaching servers it had not reached before')
			expect(wrapper.text()).not.toContain('over a year')
		})

		/** One point is not a trend. */
		it('leaves the section out when there is no series', async () => {
			const wrapper = await mountGrowth({ ...GROWTH, months: [GROWTH.months[0]] })

			expect(wrapper.find('.stats__growth').exists()).toBe(false)
		})

		it('leaves it out entirely when the server sent none', async () => {
			const wrapper = await mountGrowth(undefined)

			expect(wrapper.find('.stats__growth').exists()).toBe(false)
		})
	})

	/**
	 * "Forty thousand servers" is an abstraction; the list of platforms is a
	 * picture of a place — and for somebody reading this from inside a
	 * Nextcloud, that the network is many kinds of software talking to each
	 * other is the whole point of it.
	 */
	describe('what the fediverse runs on', () => {
		const SOFTWARE = {
			platforms: [
				{ name: 'Mastodon', accounts: 8726285, servers: 8716, active: 972920, posts: 999431667, share: 64.1 },
				{ name: 'Misskey', accounts: 1249410, servers: 1178, active: 20046, posts: 458311684, share: 9.2 },
				{ name: '', accounts: 3634687, servers: 32250, active: 312416, posts: 178313649, share: 26.7 },
			],
			accounts: 13610382,
			source: 'FediDB',
			source_url: 'https://fedidb.org',
		}

		/**
		 * @param {object|undefined} software what the server sent
		 * @return {Promise<object>} the page, drawn
		 */
		async function mountSoftware(software) {
			axios.get.mockResolvedValue({ data: answer(software === undefined ? {} : { software }) })
			const wrapper = mountPage()
			await flushPromises()

			return wrapper
		}

		it('draws a band per platform and a row to read it by', async () => {
			const wrapper = await mountSoftware(SOFTWARE)

			expect(wrapper.findAll('.stats__composition-band')).toHaveLength(3)
			expect(wrapper.find('.stats__platform-list').text()).toContain('Mastodon')
			expect(wrapper.find('.stats__platform-list').text()).toContain('64.1%')
			expect(wrapper.find('.stats__platform-list').text()).toContain('8,716 servers')
		})

		/** Which keeps the shares summing to the whole. */
		it('names what is left over rather than dropping it', async () => {
			const wrapper = await mountSoftware(SOFTWARE)

			expect(wrapper.find('.stats__platform-list').text()).toContain('everything else')
		})

		it('says what the shares are shares of', async () => {
			const wrapper = await mountSoftware(SOFTWARE)

			expect(wrapper.text()).toContain('Share of accounts')
		})

		it('leaves the card out when the server sent none', async () => {
			const wrapper = await mountSoftware(undefined)

			expect(wrapper.find('.stats__platforms').exists()).toBe(false)
		})
	})

	it('asks for the window the reader picked, and only when it changes', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		expect(axios.get.mock.calls[0][1].params).toEqual({ days: 0, fresh: false })

		const choices = wrapper.findAll('.stats__windows .switcher__option')
		expect(choices.map((choice) => choice.find('.switcher__label').text())).toEqual([
			'All time',
			'Last 30 days',
			'Last 90 days',
			'Last 365 days',
		])

		await choices[2].trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(axios.get.mock.calls[1][1].params).toEqual({ days: 90, fresh: false })
		expect(choices[2].attributes('aria-checked')).toBe('true')

		// the same window again is not a second walk of the same posts
		await choices[2].trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('counts them again rather than reading the cache when asked to', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		await wrapper.find('.stats__actions button').trigger('click')
		await flushPromises()

		expect(axios.get.mock.calls[1][1].params).toEqual({ days: 0, fresh: true })
	})

	it('offers the numbers as a file, for the window on screen', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()
		await wrapper.findAll('.stats__windows .switcher__option')[1].trigger('click')
		await flushPromises()

		const download = wrapper.find('.stats__actions a')
		expect(download.attributes('href')).toContain('/apps/social/api/v1/statistics/export?days=30')
		expect(download.attributes('download')).toBe('alice-statistics.csv')
	})

	it('names who talks with the account, and who among them is a stranger', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const page = text(wrapper)
		expect(page).toContain('Who answers you')
		expect(page).toContain('@bob@remote.example')
		expect(page).toContain('Who you answer')
		expect(page).toContain('@dave@remote.example')
		// carol replied twice and is not followed; bob is
		expect(page).toContain('not followed')
		expect(page).toContain('50% of the people who replied to you are people you do not follow.')
	})

	it('says what the account did, not only what came back', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		expect(text(wrapper)).toContain('What you did')
		// two months, three parts each
		expect(wrapper.findAll('.stats__stack > li')).toHaveLength(2)
		expect(wrapper.findAll('.stats__stack-part')).toHaveLength(6)
		// the busiest month fills the column
		const tallest = wrapper.find('.stats__stack-part--originals')
		expect(tallest.attributes('style')).toContain('height: 77%')
	})

	it('shows how much of the pictures describe themselves', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const page = text(wrapper)
		expect(page).toContain('carry a description')
		expect(page).toContain('75%')
		expect(page).toContain('3 of 4')
	})

	it('names the languages rather than repeating their tags', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const page = text(wrapper)
		expect(page).toContain('English')
		expect(page).toContain('German')
		expect(page).toContain('nextcloud.com')
	})

	it('leaves the new cards out when the answer has nothing for them', async () => {
		axios.get.mockResolvedValue({
			data: answer({
				partners: { inbound: [], outbound: [], not_followed_share: 0, listed: 10 },
				media: { images: 0, described: 0, described_share: 0 },
				languages: [],
				domains: [],
				consistency: { active_days: 0, span_days: 0, share: 0, longest_gap: 0, streak: 0 },
			}),
		})

		const wrapper = mountPage()
		await flushPromises()

		const page = text(wrapper)
		expect(page).not.toContain('Who answers you')
		expect(page).not.toContain('carry a description')
		expect(page).not.toContain('How steadily you post')
		expect(wrapper.find('.stats__list').exists()).toBe(false)
	})

	/** the window choice is the same control as every other switch in the app */
	it('chooses the window with the app\'s own switcher', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const switcher = wrapper.findComponent({ name: 'TimelineSwitcher' })
		expect(switcher.exists()).toBe(true)
		expect(switcher.attributes('role')).toBe('radiogroup')
		expect(switcher.attributes('aria-label')).toBe('Counted over')
	})

	/**
	 * On a phone the switcher hides its labels behind their icons, and four
	 * calendars cannot be told apart. Four labels of up to 13 characters were
	 * also once cut to "Last 36" on a 400 px screen. Each window carries a
	 * short name the switcher shows there instead.
	 */
	it('gives each window a short name for a phone', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.findAll('.stats__windows .switcher__short').map((short) => short.text()))
			.toEqual(['All', '30 days', '90 days', '365 days'])
	})
})
