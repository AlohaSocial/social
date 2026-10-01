/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { RouterLinkStub, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import Timeline from '../../../src/views/Timeline.vue'
import TimelineSwitcher from '../../../src/components/TimelineSwitcher.vue'
import { useSettingsStore } from '../../../src/store/settings.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const stub = (name, tag = 'div') => ({ name, template: `<${tag} class="${name}-stub" />` })

const ON = { enabled: true, learning: true, paused: false, noticeAcknowledged: true }

/**
 * @param {object} route what the router says
 * @param {object} serverData what the page was told
 * @return {object} the mounted view
 */
function mountTimeline(route, serverData = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	vi.spyOn(useTimelineStore(), 'changeTimelineType').mockImplementation(() => {})
	useSettingsStore().setServerData({ public: false, cloudAddress: 'https://cloud.example.org', firstrun: false, ...serverData })

	return mount(Timeline, {
		global: {
			plugins: [pinia],
			mocks: { $route: { name: 'timeline', params: {}, query: {}, ...route } },
			stubs: {
				Announcements: stub('Announcements'),
				Composer: stub('Composer'),
				FirstRun: stub('FirstRun'),
				TimelineList: { name: 'TimelineList', props: ['type', 'display', 'listTitle'], template: '<ul class="timeline-list-stub" />' },
				OnThisDay: stub('OnThisDay'),
				WeeklyRecap: stub('WeeklyRecap'),
				StoryBar: stub('StoryBar'),
				InterestsNotice: stub('InterestsNotice', 'section'),
				InterestsLearningBanner: stub('InterestsLearningBanner'),
				RouterLink: RouterLinkStub,
			},
		},
	})
}

const options = (wrapper) => wrapper.findComponent(TimelineSwitcher).props('options').map((option) => option.value)

describe('For you on the timeline page', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	describe('the switcher', () => {
		it('offers For you second, right after My Feed, while the feed is on', () => {
			const wrapper = mountTimeline({}, { interests: ON })

			expect(options(wrapper)).toEqual(['home', 'interests', 'timeline', 'federated'])
			const option = wrapper.findComponent(TimelineSwitcher).props('options')[1]
			expect(option.label).toBe('For you')
			expect(option.to).toEqual({ name: 'timeline', params: { type: 'interests' } })
		})

		it('still offers it while learning is paused', () => {
			expect(options(mountTimeline({}, { interests: { ...ON, paused: true } }))).toContain('interests')
		})

		it.each([
			['the feature is off', { interests: { ...ON, enabled: false } }],
			['the reader opted out', { interests: { ...ON, learning: false } }],
			['the server says nothing about it', {}],
		])('leaves it out when %s', (_, serverData) => {
			expect(options(mountTimeline({}, serverData))).toEqual(['home', 'timeline', 'federated'])
		})

		it('leaves it out on the public pages', () => {
			const wrapper = mountTimeline({ params: { type: 'timeline' } }, { public: true, interests: ON })

			expect(options(wrapper)).not.toContain('interests')
		})

		it.each(['photos', 'videos'])('is a scope of %s too, narrowed to that kind', (type) => {
			const wrapper = mountTimeline({ params: { type }, query: { scope: 'interests' } }, { interests: ON })

			expect(options(wrapper)).toEqual(['home', 'interests', 'timeline', 'federated'])
			const option = wrapper.findComponent(TimelineSwitcher).props('options')[1]
			expect(option.label).toBe('For you')
			expect(option.to).toEqual({ name: 'timeline', params: { type }, query: { scope: 'interests' } })
			expect(wrapper.findComponent(TimelineSwitcher).props('value')).toBe('interests')
			expect(useTimelineStore().changeTimelineType).toHaveBeenCalledWith(expect.objectContaining({
				type,
				params: { scope: 'interests' },
			}))
		})

		it('reads a For you scope of Photos as My Feed for a reader without it', () => {
			const wrapper = mountTimeline({ params: { type: 'photos' }, query: { scope: 'interests' } }, { interests: { ...ON, enabled: false } })

			expect(options(wrapper)).toEqual(['home', 'timeline', 'federated'])
			expect(wrapper.findComponent(TimelineSwitcher).props('value')).toBe('home')
		})

		it('is the chosen option on the feed itself, which is headed and listed as its own timeline', () => {
			const wrapper = mountTimeline({ params: { type: 'interests' } }, { interests: ON })

			expect(wrapper.findComponent(TimelineSwitcher).props('value')).toBe('interests')
			expect(wrapper.find('h1').text()).toBe('For you')
			expect(wrapper.find('.timeline-list-stub').exists()).toBe(true)
		})
	})

	describe('the still-learning note', () => {
		it('is only over For you', () => {
			expect(mountTimeline({ params: { type: 'interests' } }, { interests: ON }).find('.InterestsLearningBanner-stub').exists()).toBe(true)
			expect(mountTimeline({}, { interests: ON }).find('.InterestsLearningBanner-stub').exists()).toBe(false)
		})
	})

	describe('the first-use notice', () => {
		const notice = (route, interests) => mountTimeline(route, { interests }).find('.InterestsNotice-stub').exists()

		it('shows while learning is on and the reader has not answered it', () => {
			expect(notice({}, { ...ON, noticeAcknowledged: false })).toBe(true)
			expect(notice({ name: 'tags', params: { tag: 'film' } }, { ...ON, noticeAcknowledged: false })).toBe(true)
		})

		it('does not show once answered, while paused or opted out, or where nothing is learned', () => {
			expect(notice({}, ON)).toBe(false)
			expect(notice({}, { ...ON, noticeAcknowledged: false, paused: true })).toBe(false)
			expect(notice({}, { ...ON, noticeAcknowledged: false, learning: false })).toBe(false)
			expect(notice({ params: { type: 'bookmarks' } }, { ...ON, noticeAcknowledged: false })).toBe(false)
		})
	})
})
