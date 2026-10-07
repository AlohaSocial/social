/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import EmptyContent from '../../../src/components/EmptyContent.vue'

const mountEmpty = (item) => mount(EmptyContent, { props: { item } })

describe('EmptyContent', () => {
	it('renders the description', () => {
		const wrapper = mountEmpty({ title: 'No posts', description: 'Follow somebody to fill your timeline', scene: 'tide' })
		expect(wrapper.find('.empty-content__description').text()).toBe('Follow somebody to fill your timeline')
	})

	it('passes the title through as the name heading of NcEmptyContent', () => {
		const wrapper = mountEmpty({ title: 'No posts', description: 'x', scene: 'tide' })
		// NcEmptyContent 9 renamed the prop to `name`; the text must reach the heading,
		// not leak onto the root as a `title=` attribute.
		expect(wrapper.find('.empty-content__name').text()).toBe('No posts')
		expect(wrapper.find('.empty-content').attributes('title')).toBeUndefined()
	})

	it('draws the scene a state asks for, in the icon area and drawn rather than fetched', () => {
		const wrapper = mountEmpty({ description: 'x', scene: 'tide' })
		const scene = wrapper.findComponent({ name: 'AlohaScene' })

		expect(scene.exists()).toBe(true)
		expect(scene.props('scene')).toBe('tide')
		expect(scene.element.closest('.empty-content__icon')).not.toBeNull()
		expect(wrapper.find('img').exists()).toBe(false)
	})

	it('draws nothing for a scene it does not know', () => {
		const wrapper = mountEmpty({ title: 'No posts', scene: 'not-a-scene' })

		expect(wrapper.findComponent({ name: 'AlohaScene' }).exists()).toBe(false)
		expect(wrapper.find('.empty-content__icon').exists()).toBe(false)
	})

	it('is a line of text rather than a screenful when there is no illustration', () => {
		// most of the height is room for the picture; "No replies yet" under
		// every post with no replies held 60% of the window open and read as a
		// page still loading
		const bare = mountEmpty({ title: 'No replies yet' })
		const illustrated = mountEmpty({ title: 'No posts', scene: 'tide' })

		expect(bare.classes()).toContain('timeline-empty--bare')
		expect(illustrated.classes()).not.toContain('timeline-empty--bare')
	})

	it('omits the icon area when the item has no drawing', () => {
		const wrapper = mountEmpty({ description: 'Nothing here' })
		expect(wrapper.find('img').exists()).toBe(false)
		expect(wrapper.find('.empty-content__icon').exists()).toBe(false)
		expect(wrapper.find('.empty-content__description').text()).toBe('Nothing here')
	})

	it('omits the description paragraph when there is none', () => {
		const wrapper = mountEmpty({ scene: 'tide' })
		expect(wrapper.find('.empty-content__description').exists()).toBe(false)
	})

	it('draws the small illustration a state asks for, without holding the page open for it', () => {
		const wrapper = mountEmpty({ title: 'No replies yet', illustration: 'no-replies' })

		expect(wrapper.findComponent({ name: 'NoReplies' }).exists()).toBe(true)
		expect(wrapper.find('img').exists()).toBe(false)
		// small enough to stay in the compact layout the bare states use
		expect(wrapper.classes()).toContain('timeline-empty--bare')
	})

	it('draws nothing rather than an empty icon area for a name it does not know', () => {
		const wrapper = mountEmpty({ title: 'No replies yet', illustration: 'not-a-drawing' })

		expect(wrapper.find('.empty-content__icon').exists()).toBe(false)
	})

	it.each([
		['quiet-timeline', 'QuietTimeline'],
		['nobody-yet', 'NobodyYet'],
		['no-messages', 'NoMessages'],
	])('knows the drawing named %s', (name, component) => {
		const wrapper = mountEmpty({ title: 'Nothing here', illustration: name })

		expect(wrapper.findComponent({ name: component }).exists()).toBe(true)
	})

	describe('the next step', () => {
		// a page that only says it is empty leaves the reader to work out what
		// to do about it
		it('offers the action a state carries', () => {
			const wrapper = mountEmpty({
				title: 'Your timeline is quiet',
				action: { label: 'Find people to follow', to: { name: 'discover' } },
			})

			const button = wrapper.find('.empty-content__action button, .empty-content__action a')

			expect(wrapper.text()).toContain('Find people to follow')
			expect(button.exists()).toBe(true)
		})

		// where there is no obvious next step, one invented for the sake of
		// having a button would be worse than none
		it('offers nothing when the state carries no action', () => {
			const wrapper = mountEmpty({ title: 'No posts found for this tag' })

			expect(wrapper.find('.empty-content__action').exists()).toBe(false)
		})
	})

	it('hands an action with no route of its own back to the page', async () => {
		const wrapper = mountEmpty({ title: 'Start a private chat', action: { label: 'New message' } })
		await wrapper.find('.empty-content__action button').trigger('click')

		expect(wrapper.emitted('action')).toHaveLength(1)
	})
})
