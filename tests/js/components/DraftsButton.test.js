/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount, flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia } from 'pinia'
import DraftsButton from '../../../src/components/DraftsButton.vue'
import { loadDraft, saveDraft } from '../../../src/services/draft.js'
import { useTimelineStore } from '../../../src/store/timeline.js'
import eventBus from '../../../src/services/eventBus.js'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'alice' }) }))

function mountDrafts() {
	const pinia = createPinia()
	const wrapper = mount(DraftsButton, { global: {
		plugins: [pinia],
		stubs: { NcModal: { template: '<div role="dialog"><slot /></div>' } },
	} })
	return { wrapper, store: useTimelineStore(pinia) }
}

beforeEach(() => {
	localStorage.clear()
	eventBus.all.clear()
})

describe('draft list entry', () => {
	it('does not offer an empty list', () => {
		expect(mountDrafts().wrapper.find('button').exists()).toBe(false)
	})

	it('lists and reopens a saved draft in the common composer without deleting it', async () => {
		saveDraft({ text: 'Half written photo post' })
		const { wrapper } = mountDrafts()
		const open = vi.fn()
		eventBus.on('composer-open', open)
		await wrapper.find('button').trigger('click')
		expect(wrapper.find('[role="dialog"]').text()).toContain('Half written photo post')
		await wrapper.find('.drafts-list__item').trigger('click')
		await flushPromises()
		expect(open).toHaveBeenCalledWith(expect.objectContaining({ context: '', draft: expect.objectContaining({ text: 'Half written photo post' }) }))
		expect(loadDraft().text).toBe('Half written photo post')
	})

	it.each(['reply', 'quote'])('preserves the %s target', async (kind) => {
		saveDraft({ text: 'Context matters' }, `${kind}.42`)
		const { wrapper, store } = mountDrafts()
		const target = { id: '42', account: { acct: 'remote@example.org' } }
		store.fetchStatus = vi.fn().mockResolvedValue(target)
		const open = vi.fn()
		eventBus.on('composer-open', open)
		await wrapper.find('button').trigger('click')
		await wrapper.find('.drafts-list__item').trigger('click')
		await flushPromises()
		expect(store.fetchStatus).toHaveBeenCalledWith('42')
		expect(open).toHaveBeenCalledWith(expect.objectContaining({ [kind]: target, context: `${kind}.42` }))
	})

	it('keeps an unavailable reply draft instead of reopening it as a standalone post', async () => {
		saveDraft({ text: 'Keep this answer' }, 'reply.42')
		const { wrapper, store } = mountDrafts()
		store.fetchStatus = vi.fn().mockResolvedValue(null)
		const open = vi.fn()
		eventBus.on('composer-open', open)
		await wrapper.find('button').trigger('click')
		await wrapper.find('.drafts-list__item').trigger('click')
		await flushPromises()
		expect(wrapper.find('[role="alert"]').text()).toContain('Your draft has been kept')
		expect(open).not.toHaveBeenCalled()
		expect(loadDraft('reply.42').text).toBe('Keep this answer')
	})

	it('does not open a delayed reply after the list was closed', async () => {
		saveDraft({ text: 'Keep this answer' }, 'reply.42')
		const { wrapper, store } = mountDrafts()
		let finish
		store.fetchStatus = vi.fn(() => new Promise((resolve) => {
			finish = resolve
		}))
		const open = vi.fn()
		eventBus.on('composer-open', open)
		await wrapper.find('button').trigger('click')
		await wrapper.find('.drafts-list__item').trigger('click')
		wrapper.vm.close()
		finish({ id: '42' })
		await flushPromises()
		expect(open).not.toHaveBeenCalled()
		expect(loadDraft('reply.42')).not.toBeNull()
	})

	it('refreshes after the full composer closes', async () => {
		const { wrapper } = mountDrafts()
		await wrapper.setProps({ disabled: true })
		saveDraft({ text: 'Saved in the popup' })
		await wrapper.setProps({ disabled: false })
		expect(wrapper.find('button').text()).toBe('Drafts (1)')
	})
})
