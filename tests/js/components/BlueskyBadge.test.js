/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import BlueskyBadge from '../../../src/components/BlueskyBadge.vue'

describe('BlueskyBadge', () => {
	/** Whether the account is verified is the check beside it, `VerifiedBadge`. */
	it('is the butterfly alone, and says where the account is', () => {
		const wrapper = mount(BlueskyBadge)

		expect(wrapper.find('.bluesky-badge__butterfly').exists()).toBe(true)
		expect(wrapper.find('.bluesky-badge__check').exists()).toBe(false)
		expect(wrapper.attributes('title')).toBe('On Bluesky')
	})
})
