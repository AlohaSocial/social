/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import EventEditor from '../../../src/components/Composer/EventEditor.vue'

const mounted = []

function mountEditor(props = {}) {
	const wrapper = mount(EventEditor, { props: { valid: false, ...props }, attachTo: document.body })
	mounted.push(wrapper)
	return wrapper
}

const error = (wrapper) => wrapper.find('.event-editor__error')

describe('EventEditor', () => {
	afterEach(() => {
		mounted.splice(0).forEach((wrapper) => wrapper.unmount())
	})

	it('says nothing about an incomplete event before anything was done', () => {
		expect(error(mountEditor()).exists()).toBe(false)
	})

	it('waits while the reader moves between its fields', async () => {
		const wrapper = mountEditor()
		const [title, start] = wrapper.findAll('input')

		await title.trigger('focusout', { relatedTarget: start.element })
		expect(error(wrapper).exists()).toBe(false)
	})

	it('says what is missing once the reader leaves it incomplete', async () => {
		const wrapper = mountEditor()

		await wrapper.find('input').trigger('focusout', { relatedTarget: document.body })
		expect(error(wrapper).text()).toBe('Add a title and start time; the end must be after the start.')
	})

	it('says what is missing once sending was tried', () => {
		expect(error(mountEditor({ attempted: true })).exists()).toBe(true)
	})

	it('says nothing about a complete event', async () => {
		const wrapper = mountEditor({ valid: true, attempted: true })
		await wrapper.find('input').trigger('focusout', { relatedTarget: null })
		expect(error(wrapper).exists()).toBe(false)
	})

	it('describes what other apps show without naming a network', () => {
		const hint = mountEditor({ reachesBluesky: true }).text()
		expect(hint).toContain('Some apps and networks show only the post text, without the event dates or location.')
		expect(hint).not.toMatch(/Fediverse|Bluesky|ActivityPub/)
	})
})
