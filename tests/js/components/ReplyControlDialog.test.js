/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ReplyControlDialog from '../../../src/components/ReplyControlDialog.vue'

const { get, put } = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, put } }))
const { showError } = vi.hoisted(() => ({ showError: vi.fn() }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const NcDialogStub = {
	name: 'NcDialog',
	props: ['name', 'open', 'size'],
	template: '<div class="dialog-stub"><slot /></div>',
}

function mountDialog(replyPolicy = 'everyone') {
	return mount(ReplyControlDialog, {
		props: { nid: 7, replyPolicy },
		global: { stubs: { NcDialog: NcDialogStub } },
	})
}

describe('who can reply', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: [{ id: '12', title: 'Close friends' }] })
		put.mockReset().mockResolvedValue({ data: {} })
		showError.mockReset()
	})

	it('offers anybody, nobody, each kind of people and each of the reader\'s lists', async () => {
		const wrapper = mountDialog('followers,list:12')
		await flushPromises()

		for (const label of ['Anybody', 'Nobody', 'People who follow me', 'People I follow', 'People I mention', 'People on Close friends']) {
			expect(wrapper.text()).toContain(label)
		}
		expect(wrapper.vm.mode).toBe('some')
		expect(wrapper.vm.chosen).toEqual(['followers', 'list:12'])
	})

	it('adds a part to the rule and writes it', async () => {
		const wrapper = mountDialog('followers')
		await flushPromises()

		await wrapper.vm.setPolicy(wrapper.vm.withPart('followers', 'mentioned', true))

		expect(put).toHaveBeenCalledWith(expect.stringContaining('/api/v1/statuses/7/interaction_policy'), { reply_policy: 'followers,mentioned' })
		expect(wrapper.emitted('changed')).toEqual([['followers,mentioned']])
	})

	it('puts the choice back when the server refuses', async () => {
		put.mockRejectedValue(new Error('nope'))
		const wrapper = mountDialog('nobody')

		await wrapper.vm.setPolicy('everyone')
		await flushPromises()

		expect(wrapper.vm.policy).toBe('nobody')
		expect(wrapper.emitted('changed')).toBeUndefined()
		expect(showError).toHaveBeenCalled()
	})
})
