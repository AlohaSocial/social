/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

const { serverData, listeners, unsubscribe } = vi.hoisted(() => ({ serverData: { timeZone: 'Europe/Berlin' }, listeners: new Set(), unsubscribe: vi.fn() }))
vi.mock('../../../src/services/clock.js', () => ({ onTick: (listener) => {
	listeners.add(listener)
	return () => {
		listeners.delete(listener)
		unsubscribe()
	}
} }))
vi.mock('../../../src/composables/useServerData.js', () => ({ useServerData: () => ({ serverData }) }))

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/' + path }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({ default: { error: vi.fn() } }))

import TimelineEventCard from '../../../src/components/TimelineEventCard.vue'
import axios from '@nextcloud/axios'

afterEach(() => {
	vi.useRealTimers()
	vi.clearAllMocks()
})

const calendarButtonStub = {
	props: { disabled: Boolean },
	emits: ['click'],
	template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
}

describe('the event card time', () => {
	it('omits a missing end time instead of showing the Unix epoch', () => {
		const wrapper = mount(TimelineEventCard, {
			props: {
				event: { name: 'Community meetup', start_time: '2026-10-25T12:00:00Z', end_time: null },
				statusId: '123',
			},
			global: { stubs: { NcButton: true, CalendarMonthOutline: true, MapMarkerOutline: true } },
		})

		expect(wrapper.text()).not.toContain('1970')
		expect(wrapper.text()).not.toContain(' – ')
	})

	it('omits event times when the start is missing', () => {
		const wrapper = mount(TimelineEventCard, {
			props: {
				event: { name: 'Community meetup', start_time: null, end_time: '2026-10-25T13:00:00Z' },
				statusId: '123',
			},
			global: { stubs: { NcButton: true, CalendarMonthOutline: true, MapMarkerOutline: true } },
		})

		expect(wrapper.text()).not.toContain('1970')
		expect(wrapper.text()).not.toContain('2026')
	})
})

describe('the event card calendar import', () => {
	it('shows the imported calendar and prevents duplicate clicks after success', async () => {
		vi.mocked(axios.post).mockResolvedValueOnce({ data: { imported: true, calendar: 'Personal' } })
		const wrapper = mount(TimelineEventCard, {
			props: {
				event: { name: 'Community meetup', start_time: '2026-10-25T12:00:00Z', end_time: null },
				statusId: '123',
			},
			global: { stubs: { NcButton: calendarButtonStub, CalendarMonthOutline: true, MapMarkerOutline: true } },
		})

		await wrapper.find('button').trigger('click')
		await flushPromises()

		const button = wrapper.find('button')
		expect(wrapper.find('[role="status"]').text()).toContain('Event added to Personal')
		expect(wrapper.find('a.post-event__calendar--download').exists()).toBe(true)
		expect(button.attributes('disabled')).toBeDefined()
		expect(axios.post).toHaveBeenCalledTimes(1)
		await wrapper.vm.importEventToCalendar()
		expect(axios.post).toHaveBeenCalledTimes(1)
	})

	it('shows the existing-calendar state when the server reports a prior import', async () => {
		vi.mocked(axios.post).mockResolvedValueOnce({ data: { imported: true, already_imported: true } })
		const wrapper = mount(TimelineEventCard, {
			props: {
				event: { name: 'Community meetup', start_time: '2026-10-25T12:00:00Z', end_time: null },
				statusId: '123',
			},
			global: { stubs: { NcButton: calendarButtonStub, CalendarMonthOutline: true, MapMarkerOutline: true } },
		})

		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="status"]').text()).toContain('This event is already in your calendar')
		expect(wrapper.find('button').attributes('disabled')).toBeDefined()
	})
})

function card(event = {}, props = {}) {
	return mount(TimelineEventCard, {
		props: { event: { name: 'Meetup', start_time: '2026-11-01T12:00:00Z', ...event }, statusId: '123', ...props },
		global: { stubs: { NcButton: calendarButtonStub, CalendarMonthOutline: true, MapMarkerOutline: true } },
	})
}

describe('event states and safe calendar actions', () => {
	it('uses the Nextcloud timezone and exposes a safe original link', () => {
		const wrapper = card({}, { originalUrl: 'https://events.example/meetup' })
		expect(wrapper.vm.eventTimeLabel).toBe(new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Europe/Berlin' }).format(new Date('2026-11-01T12:00:00Z')))
		expect(wrapper.text()).toContain('Europe/Berlin')
		expect(wrapper.find('a[target="_blank"]').attributes('rel')).toBe('noopener noreferrer')
		wrapper.unmount()
	})

	it('falls back safely when the configured timezone is invalid', () => {
		serverData.timeZone = 'Invalid/Timezone'
		const wrapper = card()
		expect(wrapper.vm.eventTimeZone).toBe(new Intl.DateTimeFormat().resolvedOptions().timeZone)
		wrapper.unmount()
		serverData.timeZone = 'Europe/Berlin'
	})

	it.each(['javascript:alert(1)', 'https://user:secret@events.example', 'not a URL'])('rejects unsafe original links: %s', (originalUrl) => {
		const wrapper = card({}, { originalUrl })
		expect(wrapper.find('a[target="_blank"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('moves from upcoming to ongoing to past with the shared clock', async () => {
		vi.useFakeTimers()
		vi.setSystemTime('2026-11-01T11:59:45Z')
		const wrapper = card({ end_time: '2026-11-01T12:01:00Z' })
		expect(wrapper.text()).toContain('Upcoming')
		for (const listener of listeners) {
			listener(Date.parse('2026-11-01T12:00:15Z'))
		}
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain('Ongoing')
		for (const listener of listeners) {
			listener(Date.parse('2026-11-01T12:01:15Z'))
		}
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain('Past')
		wrapper.unmount()
		expect(unsubscribe).toHaveBeenCalled()
	})

	it('does not invent an end for events without an end time', async () => {
		const wrapper = card()
		await wrapper.setData({ now: Date.parse('2027-01-01T00:00:00Z') })
		expect(wrapper.text()).toContain('Started')
		expect(wrapper.text()).not.toContain('Past')
		wrapper.unmount()
	})

	it('shows cancellation and updates independently of the event time', () => {
		const wrapper = card({ status: 'cancelled' }, { updatedAt: '2026-10-26T10:00:00Z' })
		expect(wrapper.text()).toContain('Cancelled')
		expect(wrapper.text()).toContain('Event updated')
		wrapper.unmount()
	})

	it('does not claim success for an unconfirmed import and retains recovery actions', async () => {
		vi.mocked(axios.post).mockResolvedValueOnce({ data: { imported: false } })
		const wrapper = card()
		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(wrapper.find('[role="status"]').text()).toContain('Could not add this event')
		expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
		expect(wrapper.find('.post-event__calendar--download').exists()).toBe(true)
		wrapper.unmount()
	})

	it('never offers import for guests or malformed dates', async () => {
		const wrapper = card({ start_time: 'bad date' })
		expect(wrapper.find('button').exists()).toBe(false)
		expect(wrapper.find('.post-event__calendar--download').exists()).toBe(false)
		await wrapper.vm.importEventToCalendar()
		expect(axios.post).not.toHaveBeenCalled()
		wrapper.unmount()
		const guest = card({}, { isPublic: true })
		expect(guest.find('button').exists()).toBe(false)
		expect(guest.find('.post-event__calendar--download').exists()).toBe(true)
		await guest.vm.importEventToCalendar()
		expect(axios.post).not.toHaveBeenCalled()
		guest.unmount()
	})
})
