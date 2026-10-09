/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import VerifiedBadge from '../../../src/components/VerifiedBadge.vue'

const BLUESKY = 'did:plc:z72i7hdynmk6r22z27h6tvur'
const OURS = 'did:plc:ourverifier'
const here = { by: 'Example Inc', issuer: OURS, created_at: '2026-10-09T10:00:00.000Z' }
const badge = (account) => mount(VerifiedBadge, { props: { account } })

/**
 * One check for every way an account is verified, the instance's own and
 * the verifiers Bluesky trusts alike, and a label saying who.
 */
describe('VerifiedBadge', () => {
	it('draws nothing for an account nobody verified', () => {
		expect(badge(null).find('.verified-badge').exists()).toBe(false)
		expect(badge({ verification: null, bluesky: { verified: false } }).find('.verified-badge').exists()).toBe(false)
	})

	it('names who this instance verified the account in the name of', () => {
		const wrapper = badge({ verification: here, bluesky: null })

		expect(wrapper.find('.verified-badge__check').exists()).toBe(true)
		expect(wrapper.attributes('title')).toBe('Verified by Example Inc')
		expect(wrapper.attributes('aria-label')).toBe('Verified by Example Inc')
	})

	it('names the instance once when Bluesky reports its own record back', () => {
		expect(badge({ verification: here, bluesky: { verified: true, verified_by: [OURS], verified_by_bluesky: false } }).attributes('title'))
			.toBe('Verified by Example Inc')
	})

	it('adds the other verifiers Bluesky trusts', () => {
		expect(badge({ verification: here, bluesky: { verified: true, verified_by: [OURS, BLUESKY], verified_by_bluesky: true } }).attributes('title'))
			.toBe('Verified by Example Inc and by Bluesky')
		expect(badge({ verification: here, bluesky: { verified: true, verified_by: [OURS, 'did:plc:nyt', 'did:plc:wapo'], verified_by_bluesky: false } }).attributes('title'))
			.toBe('Verified by Example Inc and 2 other trusted verifiers')
	})

	it('shows the check of the verifiers Bluesky trusts on its own', () => {
		expect(badge({ bluesky: { verified: true, verified_by: [BLUESKY], verified_by_bluesky: true } }).attributes('title'))
			.toBe('Verified by Bluesky')
		expect(badge({ bluesky: { verified: true, verified_by: ['did:plc:a', 'did:plc:b'], verified_by_bluesky: false } }).attributes('title'))
			.toBe('Verified by 2 trusted verifiers')
		expect(badge({ bluesky: { verified: true, verified_by: [], verified_by_bluesky: false } }).attributes('title'))
			.toBe('Verified by 1 trusted verifier')
	})
})
