/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import InterestsSection from '../../../../src/components/admin/InterestsSection.vue'

const { get, post, put } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post, put } }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../../src/services/toast.js', () => ({ showError, showSuccess }))

const ROUTE = '/index.php/apps/social/admin/interests'
const RECOMMENDATIONS_ROUTE = '/index.php/apps/social/admin/recommendations'
const DISCOVERY_ROUTE = '/index.php/apps/social/admin/recommendations/discovery'
const DEFAULTS = { enabled: true, learningDefault: true, halfLife: 30, threshold: 3, cap: 30, window: 7 }
const HEALTH = {
	window_hours: 24,
	sources: {
		activitypub: { state: 'healthy', calls: 2, requests: 4, failed_requests: 0, rate_limited: 0, posts_seen: 8, posts_stored: 5, average_duration_ms: 125, failure_rate: 0, last_attempt: 2_000_000_000, last_success: 2_000_000_000 },
		atproto: { state: 'no_data', calls: 0, requests: 0, failed_requests: 0, rate_limited: 0, posts_seen: 0, posts_stored: 0, average_duration_ms: null, failure_rate: null, last_attempt: 0, last_success: 0 },
	},
}

/**
 * @param {object} settings `adminSettings.interests`
 * @return {object} the mounted card
 */
function mountCard(settings = DEFAULTS) {
	return mount(InterestsSection, { props: { settings } })
}

const switches = (wrapper) => wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
const fields = (wrapper) => wrapper.findAllComponents({ name: 'NcTextField' })
const saveButton = (wrapper) => wrapper.findAllComponents({ name: 'NcButton' }).find((button) => button.text().trim() === 'Save')

/**
 * @param {object} wrapper the mounted card
 * @param {number} index which of the four numbers
 * @param {string} value what to type into it
 */
async function type(wrapper, index, value) {
	await fields(wrapper)[index].find('input').setValue(value)
}

describe('the For you card', () => {
	beforeEach(() => {
		post.mockReset().mockImplementation((url, body) => Promise.resolve({ data: body }))
		put.mockReset().mockImplementation((url, body) => Promise.resolve({ data: body }))
		get.mockReset().mockResolvedValue({ data: HEALTH })
		showError.mockReset()
		showSuccess.mockReset()
	})

	it('shows what stands now', () => {
		const wrapper = mountCard({ ...DEFAULTS, learningDefault: false, threshold: 2.5 })

		expect(switches(wrapper).map((box) => box.props('modelValue'))).toEqual([false, false, false, false, true, false])
		expect(fields(wrapper).map((field) => field.props('modelValue'))).toEqual(['30', '2.5', '30', '7'])
	})

	it('says hashtag learning is opt-in and that switching it off keeps what was learned', () => {
		const wrapper = mountCard()

		expect(wrapper.text()).toContain('Learn hashtag interests for users who have not chosen')
		expect(wrapper.text()).toContain('Off by default: hashtag learning is opt-in')
		expect(wrapper.text()).toContain('turning learning off keeps what was already learned')
		expect(wrapper.text()).not.toContain('unless a user turns it off')
	})

	it('describes automatic ranking, separate learning consent and bounded discovery accurately', () => {
		const wrapper = mountCard()

		expect(wrapper.text()).toContain('For You recommendations')
		expect(wrapper.text()).not.toContain('4.0')
		expect(wrapper.text()).toContain('Readers do not choose topics')
		expect(wrapper.text()).toContain('This enables automatic ranking in the existing For You feed')
		expect(wrapper.text()).toContain('Activity learning stays off until each reader separately enables it')
		expect(wrapper.text()).toContain('Discovery uses bounded background jobs and eligible public posts')
		expect(wrapper.text()).not.toContain('recommendations and behavioral learning remain unavailable')
	})

	it('fills in the defaults for an instance that never saved the card', () => {
		const wrapper = mountCard({})

		// learning from browsing is opt-in: off for an instance that never chose
		expect(switches(wrapper).map((box) => box.props('modelValue'))).toEqual([false, false, false, false, true, false])
		expect(fields(wrapper).map((field) => field.props('modelValue'))).toEqual(['30', '3', '30', '7'])
	})

	it('saves all six values in one request, the numbers as numbers', async () => {
		const wrapper = mountCard()
		await switches(wrapper)[5].vm.$emit('update:modelValue', false)
		await type(wrapper, 0, '60')
		await type(wrapper, 1, '4.5')

		await saveButton(wrapper).trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(ROUTE, {
			enabled: true,
			learningDefault: false,
			halfLife: 60,
			threshold: 4.5,
			cap: 30,
			window: 7,
		})
		expect(showSuccess).toHaveBeenCalledWith('Saved')
	})

	it('saves the independent v4 kill switch separately and keeps it off by default', async () => {
		const wrapper = mountCard()
		expect(switches(wrapper)[0].props('modelValue')).toBe(false)
		await switches(wrapper)[0].vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(put).toHaveBeenCalledWith(RECOMMENDATIONS_ROUTE, { enabled: true })
		expect(switches(wrapper)[0].props('modelValue')).toBe(true)
	})

	it.each(['remoteDiscoveryPaused', 'learningPaused', 'forceLegacyFeed'])('saves the %s fallback independently', async (key) => {
		const wrapper = mountCard()
		const index = { remoteDiscoveryPaused: 1, learningPaused: 2, forceLegacyFeed: 3 }[key]
		await switches(wrapper)[index].vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(put).toHaveBeenCalledWith(RECOMMENDATIONS_ROUTE, { [key]: true })
		expect(wrapper.vm.form[key]).toBe(true)
	})

	it('shows provider discovery health and refreshes it on demand', async () => {
		const wrapper = mountCard()
		await vi.dynamicImportSettled()
		await flushPromises()

		expect(get).toHaveBeenCalledWith(DISCOVERY_ROUTE)
		expect(wrapper.text()).toContain('Fediverse / ActivityPub')
		expect(wrapper.text()).toContain('Bluesky / ATProto')
		expect(wrapper.text()).toContain('8 / 5')
		expect(wrapper.text()).toContain('125 ms')
		expect(wrapper.text()).toContain('Failure rate')
		expect(wrapper.text()).toContain('Average discovery batch duration')
		expect(wrapper.text()).toContain('Last attempt')
		expect(wrapper.text()).toContain('No recent data')

		const callsBeforeRefresh = get.mock.calls.length
		await wrapper.findAllComponents({ name: 'NcButton' })[0].trigger('click')
		await flushPromises()
		expect(get.mock.calls.length).toBeGreaterThan(callsBeforeRefresh)
	})

	it('draws what the server saved', async () => {
		post.mockResolvedValue({ data: { ...DEFAULTS, cap: 40 } })
		const wrapper = mountCard()
		await type(wrapper, 2, '41')

		await saveButton(wrapper).trigger('click')
		await flushPromises()

		expect(fields(wrapper)[2].props('modelValue')).toBe('40')
	})

	it.each([
		[0, '6', 'A whole number from 7 to 180'],
		[0, '181', 'A whole number from 7 to 180'],
		[0, '30.5', 'A whole number from 7 to 180'],
		[1, '0.4', 'A number from 0.5 to 20'],
		[1, '21', 'A number from 0.5 to 20'],
		[2, '4', 'A whole number from 5 to 100'],
		[2, '101', 'A whole number from 5 to 100'],
		[3, '0', 'A whole number from 1 to 30'],
		[3, '31', 'A whole number from 1 to 30'],
		[3, '', 'A whole number from 1 to 30'],
	])('refuses %s = "%s" before asking the server', async (index, value, said) => {
		const wrapper = mountCard()
		await type(wrapper, index, value)

		expect(fields(wrapper)[index].props('error')).toBe(true)
		expect(fields(wrapper)[index].props('helperText')).toBe(said)
		expect(saveButton(wrapper).props('disabled')).toBe(true)

		await wrapper.vm.save()
		expect(post).not.toHaveBeenCalled()
	})

	it('takes the edges of every range', async () => {
		const wrapper = mountCard()
		await type(wrapper, 0, '7')
		await type(wrapper, 1, '20')
		await type(wrapper, 2, '100')
		await type(wrapper, 3, '1')

		expect(fields(wrapper).every((field) => field.props('error') === false)).toBe(true)
		expect(saveButton(wrapper).props('disabled')).toBe(false)
	})

	it('shows the server\'s reason when it refuses', async () => {
		post.mockRejectedValue({ response: { data: { error: 'halfLife out of range' } } })
		const wrapper = mountCard()

		await saveButton(wrapper).trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith({ text: 'Could not save the For you settings', details: 'halfLife out of range' })
	})
})
