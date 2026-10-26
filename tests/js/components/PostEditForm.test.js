/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import PostEditForm from '../../../src/components/PostEditForm.vue'

describe('PostEditForm', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('saves edited event details as UTC ISO timestamps', () => {
		const wrapper = mount(PostEditForm, {
			props: {
				maxLength: 500,
				item: {
					id: '123',
					content: '<p>Before</p>',
					spoiler_text: '',
					sensitive: false,
					media_attachments: [],
					event: {
						name: 'Before',
						start_time: '2026-11-03T09:00:00Z',
						end_time: null,
						location: 'Old venue',
					},
				},
			},
			global: { stubs: { NcButton: true } },
		})

		wrapper.vm.editContent = 'Updated event announcement'
		wrapper.vm.editEvent.name = 'Updated gathering'
		wrapper.vm.editEvent.start_time = '2026-11-04T18:30'
		wrapper.vm.editEvent.end_time = '2026-11-04T20:00'
		wrapper.vm.editEvent.location = 'New venue'
		wrapper.vm.save()

		const [edit] = wrapper.emitted('save')[0]
		expect(edit.content).toBe('Updated event announcement')
		expect(edit.event).toEqual({
			name: 'Updated gathering',
			start_time: new Date('2026-11-04T18:30').toISOString(),
			end_time: new Date('2026-11-04T20:00').toISOString(),
			location: 'New venue',
		})
	})

	it('keeps an optional event end time empty when saving', () => {
		const wrapper = mount(PostEditForm, {
			props: {
				maxLength: 500,
				item: {
					id: '123',
					content: '<p>Before</p>',
					media_attachments: [],
					event: {
						name: 'Gathering',
						start_time: '2026-11-03T09:00:00Z',
						end_time: null,
						location: '',
					},
				},
			},
			global: { stubs: { NcButton: true } },
		})

		wrapper.vm.save()

		expect(wrapper.emitted('save')[0][0].event.end_time).toBeNull()
	})
	function form(item = {}, extraProps = {}) {
		return mount(PostEditForm, {
			props: { maxLength: 500, item: { id: '123', content: '<p>Before</p>', ...item }, ...extraProps },
		})
	}

	it('uses post text, never an author bio, and preserves line breaks and text direction', () => {
		const wrapper = form({ content: '<p>Line one</p><p>Line <b>two</b><br>three</p>' })
		expect(wrapper.find('.post-edit-textarea').element.value).toBe('Line one\nLine two\nthree')
		expect(wrapper.find('.post-edit-textarea').attributes('dir')).toBe('auto')
		expect(wrapper.find('.post-edit-warning').attributes('dir')).toBe('auto')
		expect(form({ content: '', account: { note: 'The bio' } }).vm.editContent).toBe('')
	})

	it('preserves warnings and sensitivity until explicitly changed, including clearing', async () => {
		const wrapper = form({ spoiler_text: 'politics', sensitive: true })
		await wrapper.find('.post-edit-textarea').setValue('  Typo fixed  ')
		await wrapper.find('.post-edit-textarea').trigger('keydown', { key: 'Enter', ctrlKey: true })
		expect(wrapper.emitted('save')[0][0]).toMatchObject({ content: 'Typo fixed', spoiler_text: 'politics', sensitive: true })
		await wrapper.find('.post-edit-warning').setValue('  ')
		await wrapper.find('.post-edit-sensitive input').setValue(false)
		wrapper.vm.save()
		expect(wrapper.emitted('save')[1][0]).toMatchObject({ spoiler_text: '', sensitive: false })
	})

	it('sends complete replacement media and edited or cleared descriptions', () => {
		const wrapper = form({ media_attachments: [{ id: '1', description: 'First' }, { id: '2', description: 'Second' }] })
		wrapper.vm.editMedia[0].description = ''
		wrapper.vm.editMedia[1].description = 'New description'
		wrapper.vm.save()
		expect(wrapper.emitted('save')[0][0].media_attributes).toEqual([{ id: '1', description: '' }, { id: '2', description: 'New description' }])
		wrapper.vm.removeEditMedia(1)
		wrapper.vm.save()
		expect(wrapper.emitted('save')[1][0].media_ids).toEqual(['1'])
	})

	it('rejects empty and oversized text, and prevents saving during uploads or a pending request', async () => {
		const wrapper = form({ content: '' })
		wrapper.vm.save()
		expect(wrapper.emitted('save')).toBeUndefined()
		await wrapper.find('.post-edit-textarea').setValue('x'.repeat(501))
		expect(wrapper.find('.post-edit-count').text()).toBe('1 character too many')
		wrapper.vm.save()
		expect(wrapper.emitted('save')).toBeUndefined()
		await wrapper.find('.post-edit-textarea').setValue('Valid')
		wrapper.vm.editMediaUploading = true
		wrapper.vm.save()
		expect(wrapper.emitted('save')).toBeUndefined()
		wrapper.vm.editMediaUploading = false
		await wrapper.setProps({ saving: true })
		wrapper.vm.save()
		expect(wrapper.find('fieldset').attributes('disabled')).toBeDefined()
		expect(wrapper.emitted('save')).toBeUndefined()
	})

	it('tracks unsaved edits without modifying the original post', async () => {
		const post = { content: '<p>Before</p>' }
		const wrapper = form(post)
		expect(wrapper.vm.isDirty).toBe(false)
		await wrapper.find('.post-edit-textarea').setValue('Unsaved')
		expect(wrapper.vm.isDirty).toBe(true)
		await wrapper.find('button[aria-label="Cancel"]').trigger('click')
		expect(wrapper.emitted('cancel')).toHaveLength(1)
		expect(post.content).toBe('<p>Before</p>')
	})
})
