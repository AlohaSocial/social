/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import TimelinePostHeader from '../../../src/components/TimelinePostHeader.vue'

describe('the post header', () => {
	it('renders a status without an account without throwing', () => {
		const wrapper = mount(TimelinePostHeader, {
			props: { item: { created_at: '2026-10-25T12:00:00Z' } },
		})

		expect(wrapper.find('.post-author-wrapper').exists()).toBe(false)
		expect(wrapper.find('.post-timestamp').exists()).toBe(true)
	})
})
