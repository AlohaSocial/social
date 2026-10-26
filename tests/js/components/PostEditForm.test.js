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
})
