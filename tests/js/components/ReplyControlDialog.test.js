/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ReplyControlDialog from '../../../src/components/ReplyControlDialog.vue'

const { put } = vi.hoisted(() => ({ put: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { put } }))
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
		put.mockReset().mockResolvedValue({ data: {} })
		showError.mockReset()
	})

	it('starts on the rule the post carries, and offers every one', () => {
		const wrapper = mountDialog('mentioned')

		expect(wrapper.vm.policy).toBe('mentioned')
		for (const label of ['Anybody', 'People who follow me', 'People I follow', 'Only people I mention', 'Nobody']) {
			expect(wrapper.text()).toContain(label)
		}
	})

	it('writes the rule alone through the interaction_policy route', async () => {
		const wrapper = mountDialog()

		await wrapper.vm.setPolicy('followers')

		expect(put).toHaveBeenCalledWith(expect.stringContaining('/api/v1/statuses/7/interaction_policy'), { reply_policy: 'followers' })
		expect(wrapper.emitted('changed')).toEqual([['followers']])
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
