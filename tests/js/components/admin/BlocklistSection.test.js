/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import BlocklistSection from '../../../../src/components/admin/BlocklistSection.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const BLOCKLIST = '/index.php/apps/social/moderation/fediverse/blocklist'

const sources = [
	{ id: 'mastodon.social', label: 'mastodon.social', url: 'https://mastodon.social/api/v1/instance/domain_blocks', format: 'mastodon', enabled: false, lastRun: 0, lastResult: {} },
	{ id: 'thebad.space', label: 'The Bad Space (80% agreement)', url: 'https://tweaking.thebad.space/exports/mastodon/80', format: 'csv', enabled: false, lastRun: 0, lastResult: {} },
]

/**
 * @return {Promise<object>} the mounted section, with its sources loaded
 */
async function mountBlocklist() {
	axios.get.mockResolvedValue({ data: { sources: structuredClone(sources) } })
	const wrapper = mount(BlocklistSection)
	await flushPromises()

	return wrapper
}

describe('the block list section', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('offers the published lists this instance can follow', async () => {
		const wrapper = await mountBlocklist()

		expect(axios.get).toHaveBeenCalledWith(`${BLOCKLIST}/sources`)
		expect(wrapper.text()).toContain('mastodon.social')
		expect(wrapper.text()).toContain('The Bad Space')
	})

	/**
	 * A server's moderation is its own, and following somebody else's deletes
	 * what this one holds of every server on it. Nothing is on until an
	 * administrator says so.
	 */
	it('has every source off to begin with', async () => {
		const wrapper = await mountBlocklist()

		expect(wrapper.findAll('input[type="checkbox"]').every((box) => !box.element.checked)).toBe(true)
	})

	/**
	 * The number in front of an administrator before they agree is the whole
	 * point: a file naming two hundred servers is not a thing to apply and
	 * then read.
	 */
	it('says what an uploaded list would do before it does it', async () => {
		const wrapper = await mountBlocklist()
		axios.post.mockResolvedValue({
			data: {
				blocked: 2,
				silenced: 1,
				alreadyBlocked: 0,
				alreadySilenced: 0,
				entries: [
					{ domain: 'one.example', severity: 'suspend' },
					{ domain: 'two.example', severity: 'suspend' },
					{ domain: 'three.example', severity: 'silence' },
				],
				rejected: [],
				skipped: 0,
			},
		})

		await wrapper.vm.read({ target: { files: [{ size: 40, text: async () => '#domain\none.example\n' }] } })
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${BLOCKLIST}/preview`, { csv: '#domain\none.example\n' })
		expect(wrapper.text()).toContain('2 servers would be blocked')
		expect(wrapper.text()).toContain('three.example')
		// and nothing is applied by looking at it
		expect(axios.post).not.toHaveBeenCalledWith(`${BLOCKLIST}/import`, expect.anything())
	})

	it('applies the list it showed, and only when asked', async () => {
		const wrapper = await mountBlocklist()
		axios.post.mockResolvedValue({
			data: { blocked: 1, silenced: 0, alreadyBlocked: 0, alreadySilenced: 0, entries: [{ domain: 'one.example', severity: 'suspend' }], rejected: [], skipped: 0 },
		})
		await wrapper.vm.read({ target: { files: [{ size: 40, text: async () => 'one.example\n' }] } })
		await flushPromises()

		const blocks = [{ domain: 'one.example', severity: 'suspend', private_comment: null, public_comment: null }]
		axios.post.mockResolvedValue({ data: { blocked: 1, silenced: 0, list: ['one.example'], blocks } })
		await wrapper.find('.blocklist__preview button').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenLastCalledWith(`${BLOCKLIST}/import`, { csv: 'one.example\n' })
		expect(wrapper.emitted('changed').at(-1)).toEqual([['one.example'], blocks])
	})

	/** A row that names no server stops the whole file, as the command does. */
	it('refuses a file with a row that names no server', async () => {
		const wrapper = await mountBlocklist()
		axios.post.mockResolvedValue({
			data: { blocked: 0, silenced: 0, alreadyBlocked: 0, alreadySilenced: 0, entries: [], rejected: ['com'], skipped: 0 },
		})

		await wrapper.vm.read({ target: { files: [{ size: 40, text: async () => 'com\n' }] } })
		await flushPromises()

		expect(wrapper.find('.server-error').text()).toContain('com')
		expect(wrapper.find('.blocklist__preview').exists()).toBe(false)
	})

	it('does not read a file past the size the server would take', async () => {
		const wrapper = await mountBlocklist()

		await wrapper.vm.read({ target: { files: [{ size: 9 * 1024 * 1024, text: async () => '' }] } })
		await flushPromises()

		expect(wrapper.find('.server-error').text()).toContain('8 MB')
		expect(axios.post).not.toHaveBeenCalled()
	})

	/**
	 * A source that is off is asked what it *would* do, so an administrator
	 * can read a list before following it.
	 */
	it('asks a source it does not follow what it would do', async () => {
		const wrapper = await mountBlocklist()
		axios.post.mockResolvedValue({
			data: { result: { dryRun: true, blocked: 12, silenced: 30, read: 42 }, sources: structuredClone(sources), list: [] },
		})

		await wrapper.findAll('.blocklist__source button')[0].trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${BLOCKLIST}/fetch`, { id: 'mastodon.social', dryRun: true })
		expect(wrapper.text()).toContain('Would block 12 and silence 30 of 42 servers.')
	})

	/**
	 * Switching a source on means applying it now: a dry run there leaves
	 * "Would block …" under a source the administrator thinks is applied.
	 */
	it('applies a source when it is switched on, rather than asking what it would do', async () => {
		const wrapper = await mountBlocklist()
		const on = structuredClone(sources)
		on[0].enabled = true
		axios.post
			.mockResolvedValueOnce({ data: { sources: on } })
			.mockResolvedValueOnce({
				data: { result: { dryRun: false, blocked: 12, silenced: 3, read: 200 }, sources: on, list: [] },
			})

		await wrapper.vm.toggle(wrapper.vm.sources[0], true)
		await flushPromises()

		expect(axios.post).toHaveBeenNthCalledWith(1, `${BLOCKLIST}/sources`, { id: 'mastodon.social', enabled: true })
		expect(axios.post).toHaveBeenNthCalledWith(2, `${BLOCKLIST}/fetch`, { id: 'mastodon.social', dryRun: false })
		expect(wrapper.text()).not.toContain('Would block')
	})

	/** A server that publishes no list is a thing to say, not a failure. */
	it('says when a server does not publish a list', async () => {
		const wrapper = await mountBlocklist()
		axios.post.mockResolvedValue({
			data: { result: { error: 'that server did not hand over a list' }, sources: structuredClone(sources), list: [] },
		})

		await wrapper.findAll('.blocklist__source button')[0].trigger('click')
		await flushPromises()

		expect(wrapper.find('.blocklist__source-result--error').text()).toContain('did not hand over a list')
	})
})
