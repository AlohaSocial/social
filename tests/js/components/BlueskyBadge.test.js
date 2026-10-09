/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import BlueskyBadge from '../../../src/components/BlueskyBadge.vue'

const badge = (bluesky) => mount(BlueskyBadge, { props: { account: bluesky === undefined ? null : { bluesky } } })

describe('BlueskyBadge', () => {
	it('is the butterfly alone for an account Bluesky does not show as verified', () => {
		const wrapper = badge({ verified: false })

		expect(wrapper.find('.bluesky-badge__check').exists()).toBe(false)
		expect(wrapper.attributes('title')).toBe('On Bluesky')
		expect(badge().attributes('title')).toBe('On Bluesky')
	})

	it('adds Bluesky\'s check, and says who verified the account', () => {
		const byBluesky = badge({ verified: true, verified_by: ['did:plc:z72i7hdynmk6r22z27h6tvur'], verified_by_bluesky: true })
		expect(byBluesky.find('.bluesky-badge__check').exists()).toBe(true)
		expect(byBluesky.attributes('title')).toBe('On Bluesky, verified by Bluesky')

		expect(badge({ verified: true, verified_by: ['did:plc:a', 'did:plc:b'], verified_by_bluesky: false }).attributes('title'))
			.toBe('On Bluesky, verified by 2 trusted verifiers')
	})
})
