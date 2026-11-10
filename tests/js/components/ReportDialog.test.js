/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import ReportDialog from '../../../src/components/ReportDialog.vue'
import { showError, showSuccess } from '../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const NcDialogStub = {
	name: 'NcDialog',
	props: ['open', 'buttons', 'name'],
	emits: ['update:open'],
	template: '<div v-if="open" class="dialog" role="dialog" :aria-label="name">'
		+ '<button v-for="(button, index) in buttons" :key="index" :disabled="button.disabled"'
		+ ' :class="\'dialog__button--\' + index" @click="button.callback()">{{ button.label }}</button>'
		+ '<slot /></div>',
}

const local = { id: '7', acct: 'dave', username: 'dave', url: 'https://cloud.example/@dave' }
const remote = { id: '8', acct: 'erin@mastodon.example', username: 'erin', url: 'https://mastodon.example/@erin' }
const bluesky = {
	id: '9',
	acct: 'frank.bsky.social',
	username: 'frank.bsky.social',
	url: 'https://bsky.app/profile/frank.bsky.social',
	bluesky: { handle: 'frank.bsky.social', did: 'did:plc:frank', native: true },
}
const RULES = [{ id: '1', text: 'Be kind' }, { id: '2', text: 'No spam' }]

/**
 * @param {object} props the dialog's props
 * @return {Promise<object>} the mounted dialog, its rules loaded
 */
async function mountDialog(props) {
	const wrapper = mount(ReportDialog, {
		props: { open: true, ...props },
		global: { stubs: { NcDialog: NcDialogStub } },
	})
	await flushPromises()

	return wrapper
}

const radios = (wrapper) => wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).filter((box) => box.props('type') === 'radio')
const radio = (wrapper, label) => radios(wrapper).find((box) => box.text() === label)
const checkbox = (wrapper, label) => wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).find((box) => box.props('type') === 'checkbox' && box.text() === label)
const sendButton = (wrapper) => wrapper.find('.dialog__button--1')

describe('ReportDialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.get.mockResolvedValue({ data: RULES })
		axios.post.mockResolvedValue({ data: { id: '1' } })
	})

	it('is a labelled dialog whose choices are grouped under legends', async () => {
		const wrapper = await mountDialog({ account: local })

		expect(wrapper.find('[role="dialog"]').attributes('aria-label')).toBe('Report @dave')
		expect(wrapper.findAll('legend').map((legend) => legend.text())).toEqual(['What is wrong?'])
		expect(wrapper.find('fieldset').attributes('aria-describedby')).toBe(wrapper.find('.report-dialog__hint').attributes('id'))
		expect(wrapper.findComponent({ name: 'NcTextArea' }).props('label')).toBe('Anything the moderators should know (optional)')
	})

	it('offers the four categories the server knows, with the rules one only where there are rules', async () => {
		const wrapper = await mountDialog({ account: local })
		expect(radios(wrapper).map((box) => box.props('value'))).toEqual(['spam', 'violation', 'legal', 'other'])
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/instance/rules')

		axios.get.mockResolvedValue({ data: [] })
		const noRules = await mountDialog({ account: local })
		expect(radios(noRules).map((box) => box.props('value'))).toEqual(['spam', 'legal', 'other'])
	})

	it('reports a post under the chosen category', async () => {
		const status = { id: '101' }
		const wrapper = await mountDialog({ account: local, status })

		await radio(wrapper, 'It is illegal').vm.$emit('update:modelValue', 'legal')
		await wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', 'see the picture')
		await sendButton(wrapper).trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/reports', {
			account_id: '7',
			status_ids: ['101'],
			comment: 'see the picture',
			category: 'legal',
		})
		expect(showSuccess).toHaveBeenCalledWith('Post reported to the moderators')
		expect(wrapper.emitted('reported')).toEqual([[{ id: '1' }]])
	})

	it('asks which rules a violation breaks, and will not send without one', async () => {
		const wrapper = await mountDialog({ account: local })

		await radio(wrapper, 'It breaks the rules of this server').vm.$emit('update:modelValue', 'violation')
		expect(wrapper.findAll('legend').map((legend) => legend.text())).toContain('Which rules does it break?')
		expect(wrapper.text()).toContain('Choose at least one rule.')
		expect(sendButton(wrapper).attributes('disabled')).toBeDefined()

		await checkbox(wrapper, 'No spam').vm.$emit('update:modelValue', ['2'])
		expect(sendButton(wrapper).attributes('disabled')).toBeUndefined()
		await sendButton(wrapper).trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/reports', {
			account_id: '7',
			status_ids: [],
			comment: '',
			category: 'violation',
			rule_ids: ['2'],
		})
		expect(showSuccess).toHaveBeenCalledWith('Account reported to the moderators')
	})

	it('forgets the rules when another category is chosen', async () => {
		const wrapper = await mountDialog({ account: local })
		await radio(wrapper, 'It breaks the rules of this server').vm.$emit('update:modelValue', 'violation')
		await checkbox(wrapper, 'Be kind').vm.$emit('update:modelValue', ['1'])
		await radio(wrapper, 'Something else').vm.$emit('update:modelValue', 'other')
		await sendButton(wrapper).trigger('click')
		await flushPromises()

		expect(axios.post.mock.calls[0][1]).not.toHaveProperty('rule_ids')
		expect(axios.post.mock.calls[0][1].category).toBe('other')
	})

	it('does not offer forwarding for an account of this server', async () => {
		const wrapper = await mountDialog({ account: local })

		expect(wrapper.text()).not.toContain('Also send to the moderators of their server')
		await sendButton(wrapper).trigger('click')
		await flushPromises()
		expect(axios.post.mock.calls[0][1]).not.toHaveProperty('forward')
	})

	it.each([
		['a fediverse account', remote, '8'],
		['a Bluesky account', bluesky, '9'],
	])('offers to send the report on for %s, in the same words', async (label, account, id) => {
		const wrapper = await mountDialog({ account })
		const forward = checkbox(wrapper, 'Also send to the moderators of their server')

		expect(forward.props('modelValue')).toBe(true)
		expect(wrapper.text()).toContain('in its own name, not yours')

		await forward.vm.$emit('update:modelValue', false)
		await sendButton(wrapper).trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/reports', expect.objectContaining({ account_id: id, forward: false }))
	})

	it('stays open and says so when the report could not be sent', async () => {
		axios.post.mockRejectedValue(new Error('offline'))
		const wrapper = await mountDialog({ account: local })

		await sendButton(wrapper).trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('The report could not be sent')
		expect(wrapper.emitted('reported')).toBeUndefined()
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})

	it('starts afresh each time it is opened', async () => {
		const wrapper = await mountDialog({ account: local })
		await radio(wrapper, 'Something else').vm.$emit('update:modelValue', 'other')

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })
		await flushPromises()

		expect(radio(wrapper, 'It is spam').props('modelValue')).toBe('spam')
	})
})
