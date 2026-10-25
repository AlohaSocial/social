/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/' + path }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({ default: { error: vi.fn() } }))

import TimelineEventCard from '../../../src/components/TimelineEventCard.vue'

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
