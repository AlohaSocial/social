/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PostEditForm from '../../../src/components/PostEditForm.vue'

const Composer = {
	name: 'Composer',
	props: ['editItem', 'editSaving', 'editMaxLength', 'listenForIntents'],
	emits: ['save', 'cancel'],
	data: () => ({ isEditDirty: false, hasPendingUploads: false }),
	template: '<div />',
}

describe('PostEditForm shared composer adapter', () => {
	it('passes the published post and saving state to the same composer and forwards events', async () => {
		const item = { id: '123', content: 'Original' }
		const wrapper = mount(PostEditForm, { props: { item, maxLength: 500 }, global: { stubs: { Composer } } })
		const editor = wrapper.findComponent(Composer)
		expect(editor.props()).toMatchObject({ editItem: item, editSaving: false, editMaxLength: 500, listenForIntents: false })
		expect(wrapper.vm.isDirty).toBe(false)
		editor.vm.isEditDirty = true
		editor.vm.hasPendingUploads = true
		await editor.vm.$nextTick()
		expect(wrapper.vm.isDirty).toBe(true)
		expect(wrapper.vm.editMediaUploading).toBe(true)
		editor.vm.$emit('save', { content: 'Changed' })
		editor.vm.$emit('cancel')
		expect(wrapper.emitted('save')[0]).toEqual([{ content: 'Changed' }])
		expect(wrapper.emitted('cancel')).toHaveLength(1)
		await wrapper.setProps({ saving: true })
		expect(editor.props('editSaving')).toBe(true)
		expect(wrapper.find('fieldset').attributes('disabled')).toBeDefined()
	})
})
