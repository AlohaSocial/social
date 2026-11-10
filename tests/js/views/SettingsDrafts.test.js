/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Settings from '../../../src/views/Settings.vue'
import DraftsButton from '../../../src/components/DraftsButton.vue'
import { loadDraft, saveDraft } from '../../../src/services/draft.js'
import eventBus from '../../../src/services/eventBus.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'alice' }) }))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn().mockResolvedValue({ data: [] }), delete: vi.fn() },
}))

const stub = (name) => ({ name, template: `<section class="${name}-stub" />` })

// every other section reads the server on mount or arrives in an async chunk
const stubs = Object.fromEntries([
	'AccountSettings',
	'ProfileLayoutSettings',
	'DirectMessagesSettings',
	'FeaturedTagsSettings',
	'PortfolioSettings',
	'ScheduledPosts',
	'HeldPosts',
	'ArchivedPosts',
	'FilesCommentsSettings',
].map((name) => [name, stub(name)]))

const NcDialogStub = {
	name: 'NcDialog',
	props: ['open', 'name', 'buttons'],
	template: '<div v-if="open" role="alertdialog"><button v-for="button in buttons" :key="button.label" class="dialog-button" @click="button.callback()">{{ button.label }}</button></div>',
}

/**
 * @return {Promise<object>} the page opened at the Drafts section
 */
async function mountDrafts() {
	const wrapper = mount(Settings, {
		global: {
			stubs: { ...stubs, NcDialog: NcDialogStub },
			mocks: { $route: { hash: '#drafts' }, $router: { replace: vi.fn().mockResolvedValue() } },
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	setActivePinia(createPinia())
	localStorage.clear()
	eventBus.all.clear()
})

describe('the Drafts section of Settings', () => {
	it('is in Your posts and lists the saved drafts with the composer\'s list', async () => {
		saveDraft({ text: 'A post for later' })
		saveDraft({ text: 'An answer for later' }, 'reply.42')
		const wrapper = await mountDrafts()

		expect(wrapper.find('.settings__group-heading').text()).toBe('Your posts')
		const section = wrapper.find('section.settings__section#drafts')
		expect(section.find('.settings__section-heading').text()).toBe('Drafts')
		expect(section.findComponent(DraftsButton).props('inline')).toBe(true)
		const rows = section.findAll('.drafts-list__item')
		expect(rows.map((row) => row.find('strong').text()).sort()).toEqual(['A post for later', 'An answer for later'])
		// the list is on the page, not behind a button and a dialog
		expect(section.find('.drafts-entry > button').exists()).toBe(false)
	})

	it('leaves out the drafts of direct messages', async () => {
		saveDraft({ text: 'A post for later' })
		saveDraft({ text: 'A private word' }, 'dm.7')
		saveDraft({ text: 'A new conversation' }, 'dm.new')
		const wrapper = await mountDrafts()

		const text = wrapper.find('#drafts').text()
		expect(text).toContain('A post for later')
		expect(text).not.toContain('A private word')
		expect(text).not.toContain('A new conversation')
		expect(loadDraft('dm.7').text).toBe('A private word')
	})

	it('says so when there are none', async () => {
		const wrapper = await mountDrafts()

		expect(wrapper.find('#drafts [role="status"]').text()).toBe('No drafts are available.')
	})

	it('opens the composer with the draft and keeps the list on the page', async () => {
		saveDraft({ text: 'A post for later' })
		const wrapper = await mountDrafts()
		const open = vi.fn()
		eventBus.on('composer-open', open)

		const row = wrapper.find('#drafts .drafts-list__item')
		await row.trigger('click')
		await flushPromises()

		expect(open).toHaveBeenCalledWith(expect.objectContaining({
			context: '',
			draft: expect.objectContaining({ text: 'A post for later' }),
			opener: row.element,
		}))
		expect(wrapper.find('#drafts .drafts-list__item').exists()).toBe(true)
		expect(loadDraft().text).toBe('A post for later')
	})

	it('opens a reply draft with the post it answers', async () => {
		saveDraft({ text: 'An answer for later' }, 'reply.42')
		const wrapper = await mountDrafts()
		const target = { id: '42', account: { acct: 'remote@example.org' } }
		useTimelineStore().fetchStatus = vi.fn().mockResolvedValue(target)
		const open = vi.fn()
		eventBus.on('composer-open', open)

		await wrapper.find('#drafts .drafts-list__item').trigger('click')
		await flushPromises()

		expect(open).toHaveBeenCalledWith(expect.objectContaining({ reply: target, context: 'reply.42' }))
	})

	it('deletes a draft after asking', async () => {
		saveDraft({ text: 'A post for later' })
		saveDraft({ text: 'An answer for later' }, 'reply.42')
		const wrapper = await mountDrafts()

		const row = wrapper.findAll('#drafts .drafts-list__row')
			.find((one) => one.text().includes('An answer for later'))
		await row.find('[aria-label="Delete draft: An answer for later"]').trigger('click')
		const confirm = wrapper.findAll('[role="alertdialog"] .dialog-button').find((button) => button.text() === 'Delete draft')
		await confirm.trigger('click')
		await flushPromises()

		expect(loadDraft('reply.42')).toBeNull()
		expect(loadDraft().text).toBe('A post for later')
		expect(wrapper.find('#drafts').text()).not.toContain('An answer for later')
	})

	it('shows what the composer kept once it closes', async () => {
		const wrapper = await mountDrafts()
		const store = useTimelineStore()

		store.setComposerDisplayStatus(true)
		await flushPromises()
		saveDraft({ text: 'Written in the composer' })
		store.setComposerDisplayStatus(false)
		await flushPromises()

		expect(wrapper.find('#drafts').text()).toContain('Written in the composer')
	})

	it('is found by searching for it', async () => {
		const wrapper = await mountDrafts()
		await wrapper.find('.settings__search input').setValue('drafts')
		await flushPromises()

		expect(wrapper.findAll('.settings__section-heading').map((heading) => heading.text())).toContain('Drafts')
	})
})
