/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/' + path }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({ default: { error: vi.fn() } }))

import TimelineEventCard from '../../../src/components/TimelineEventCard.vue'
import axios from '@nextcloud/axios'

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
		expect(button.text()).toContain('Event added to Personal')
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

		expect(wrapper.find('button').text()).toContain('This event is already in your calendar')
		expect(wrapper.find('button').attributes('disabled')).toBeDefined()
	})
})
