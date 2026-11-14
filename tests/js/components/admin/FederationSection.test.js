/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import FederationSection from '../../../../src/components/admin/FederationSection.vue'
import { showError, showSuccess } from '../../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

/**
 * `FederationHealthService::summary()`, with nothing wrong.
 *
 * @param {object} overrides what this instance is seeing
 * @param {boolean} administrator whether the viewer administers the server
 * @return {object} the mounted section
 */
function mountFederation(overrides = {}, administrator = false) {
	return mount(FederationSection, {
		props: {
			administrator,
			federation: {
				waiting: 0,
				running: 0,
				failing: 0,
				atRisk: 0,
				abandoned: 0,
				maxTries: 15,
				truncated: false,
				abandonedTruncated: false,
				retentionDays: 7,
				stuckSince: 0,
				instances: [],
				givenUp: [],
				...overrides,
			},
		},
	})
}

describe('the federation health section', () => {
	it('says a healthy queue is healthy instead of showing an empty table', () => {
		const wrapper = mountFederation({ waiting: 4 })

		expect(wrapper.text()).toContain('4 deliveries waiting to be sent.')
		expect(wrapper.text()).toContain('Nothing is failing to deliver.')
		expect(wrapper.text()).not.toContain('Waiting deliveries')
	})

	it('names a failing instance with its attempts and its last try', () => {
		const wrapper = mountFederation({
			waiting: 30,
			failing: 6,
			atRisk: 2,
			instances: [{ host: 'gone.example', requests: 5, tries: 14, last: 1_700_000_000 }],
		})

		expect(wrapper.text()).toContain('gone.example')
		expect(wrapper.text()).toContain('14 / 15')
		expect(wrapper.text()).toContain('2023-11-14 22:13')
		expect(wrapper.text()).toContain('6 deliveries have failed at least once.')
		expect(wrapper.text()).toContain('2 of them are close to being given up on.')
		expect(wrapper.text()).toContain('A delivery is abandoned after 15 attempts.')
	})

	it('says never rather than showing the epoch', () => {
		const wrapper = mountFederation({
			failing: 1,
			instances: [{ host: 'gone.example', requests: 1, tries: 3, last: 0 }],
		})

		expect(wrapper.text()).toContain('never')
		expect(wrapper.text()).not.toContain('1970-01-01')
	})

	it('says how long the queue has been stuck, as a span rather than a date', () => {
		const threeDays = Math.floor(Date.now() / 1000) - (3 * 86400)
		const wrapper = mountFederation({ failing: 2, stuckSince: threeDays })

		expect(wrapper.text()).toContain('The longest-failing one was last tried 3 days ago.')
	})

	it('rounds a fresh problem to hours and minutes', () => {
		const now = Math.floor(Date.now() / 1000)

		expect(mountFederation({ failing: 1, stuckSince: now - (5 * 3600) }).text())
			.toContain('last tried 5 hours ago')
		expect(mountFederation({ failing: 1, stuckSince: now - 90 }).text())
			.toContain('last tried 1 minute ago')
	})

	it('says nothing about being stuck when nothing has been tried yet', () => {
		expect(mountFederation({ failing: 1, stuckSince: 0 }).text())
			.not.toContain('longest-failing')
	})

	it('says when a count stopped short', () => {
		expect(mountFederation({ failing: 500, truncated: true }).text())
			.toContain('only the first few hundred were counted')
	})

	it('gives the instances given up on a table of their own', () => {
		const wrapper = mountFederation({
			abandoned: 12,
			givenUp: [{ host: 'gone.example', requests: 12, tries: 16, last: 1_700_000_000 }],
		})

		expect(wrapper.text()).toContain('Given up on')
		expect(wrapper.text()).toContain('12 deliveries were given up on')
		expect(wrapper.text()).toContain('Deliveries given up on')
		expect(wrapper.text()).not.toContain('occ')
	})

	it('says nothing has been given up on when nothing has', () => {
		const wrapper = mountFederation({ waiting: 4 })

		expect(wrapper.text()).toContain('Nothing has been given up on in the last 7 days.')
		expect(wrapper.text()).not.toContain('Deliveries given up on')
	})

	it('counts what is being sent right now only when something is', () => {
		expect(mountFederation({ waiting: 4, running: 2 }).text())
			.toContain('2 are being sent right now.')
		expect(mountFederation({ waiting: 4 }).text())
			.not.toContain('being sent right now')
	})

	describe('retrying the deliveries given up on', () => {
		const givenUp = { abandoned: 12, givenUp: [{ host: 'gone.example', requests: 12, tries: 16, last: 1_700_000_000 }] }

		beforeEach(() => {
			vi.clearAllMocks()
		})

		it('offers a delegate no button, and says an administrator can', () => {
			const wrapper = mountFederation(givenUp)

			expect(wrapper.find('button').exists()).toBe(false)
			expect(wrapper.text()).toContain('an administrator can queue them again')
		})

		it('queues one instance again from the button in its row, and says how many', async () => {
			axios.post.mockResolvedValue({ data: { instance: 'gone.example', queued: 12, limited: false } })
			const wrapper = mountFederation(givenUp, true)

			await wrapper.find('button').trigger('click')
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith('/index.php/apps/social/admin/federation/retry', { instance: 'gone.example' })
			expect(showSuccess).toHaveBeenCalledWith('12 deliveries queued again.')
			expect(wrapper.text()).toContain('12 deliveries queued again')
			expect(wrapper.find('button').exists()).toBe(false)
		})

		it('says when a press stopped at the limit', async () => {
			axios.post.mockResolvedValue({ data: { instance: 'gone.example', queued: 500, limited: true } })
			const wrapper = mountFederation(givenUp, true)

			await wrapper.vm.retry('gone.example')

			expect(showSuccess).toHaveBeenCalledWith('500 deliveries queued again; press again for the rest.')
		})

		it('says to wait when the rate limit refused it, and keeps the button', async () => {
			axios.post.mockRejectedValue({ response: { status: 429, data: {} } })
			const wrapper = mountFederation(givenUp, true)

			await wrapper.vm.retry('gone.example')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('There were too many tries for now. Wait a while and try again.')
			expect(wrapper.find('button').exists()).toBe(true)
			expect(wrapper.vm.retrying).toBe('')
		})
	})
})
