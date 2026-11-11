/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import TimelineSkeleton from '../../../src/components/TimelineSkeleton.vue'

const source = readFileSync(resolve(process.cwd(), 'src/components/TimelineSkeleton.vue'), 'utf8')

/**
 * @param {string} selector the rule's selector, as written
 * @return {string} the rule's body
 */
function rule(selector) {
	const start = source.indexOf(selector + ' {')
	expect(start, selector).toBeGreaterThan(-1)

	return source.slice(start, source.indexOf('\n}', start))
}

describe('the loading timeline', () => {
	it('draws three rows unless it is told otherwise', () => {
		expect(mount(TimelineSkeleton).findAll('.skeleton__row')).toHaveLength(3)
	})

	it('draws as many as it is asked for', () => {
		expect(mount(TimelineSkeleton, { props: { count: 8 } }).findAll('.skeleton__row'))
			.toHaveLength(8)
	})

	/**
	 * It stands in for text that has not arrived. A screen reader announcing
	 * the shape of it announces nothing, repeatedly, over whatever the reader
	 * was listening to.
	 */
	it('is not announced, in any of its shapes', () => {
		for (const variant of ['rows', 'grid', 'page']) {
			expect(mount(TimelineSkeleton, { props: { variant } }).attributes('aria-hidden')).toBe('true')
		}
	})

	it('draws nothing when there is nothing to wait for', () => {
		expect(mount(TimelineSkeleton, { props: { count: 0 } }).findAll('.skeleton__row'))
			.toHaveLength(0)
	})

	describe('rows', () => {
		/**
		 * The point of it over a spinner: the posts land where the
		 * placeholders were. A row is the flat row of the feed, not a card.
		 */
		it('has the shape of the row that will replace it', () => {
			const row = mount(TimelineSkeleton, { props: { count: 1 } }).find('.skeleton__row')

			expect(row.find('.skeleton__avatar').exists()).toBe(true)
			expect(row.find('.skeleton__name').exists()).toBe(true)
			expect(row.find('.skeleton__time').exists()).toBe(true)
			expect(row.findAll('.skeleton__line')).toHaveLength(3)
			// no boxed card any more, and no action row a flat row does not show at rest
			expect(row.find('.skeleton__action').exists()).toBe(false)
		})

		it('measures its row off the feed: padding, gap, hairline and a 40-pixel face', () => {
			const row = rule('.skeleton__row')

			expect(row).toContain('padding-block: 14px 13px')
			expect(row).toContain('padding-inline: 8px')
			expect(row).toContain('margin-bottom: 13px')
			expect(row).toContain('gap: 10px')
			expect(row).toMatch(/&:not\(:last-child\)::after \{[^}]*inset-block-end: -7px[^}]*block-size: 1px[^}]*background: var\(--color-border\)/s)
			expect(row).toMatch(/\.skeleton__avatar \{[^}]*inline-size: 40px;[^}]*block-size: 40px;[^}]*margin-top: 2px/s)
			expect(row).not.toMatch(/border: 1px solid/)
		})

		it('matches the feed with the same numbers TimelineEntry uses', () => {
			const entry = readFileSync(resolve(process.cwd(), 'src/components/TimelineEntry.vue'), 'utf8')
			const flat = entry.slice(entry.indexOf('.timeline-entry:not(.timeline-entry--direct) {\n\tposition: relative;'))

			expect(entry).toContain('const LIST_AVATAR = 40')
			expect(flat).toContain('padding-block: 14px 13px')
			expect(flat).toContain('padding-inline: 8px')
			expect(flat).toContain('margin-bottom: 13px')
			expect(flat).toContain('inset-block-end: -7px')
		})

		it('puts the face in the row\'s corner on a phone, as the feed does', () => {
			const row = rule('.skeleton__row')
			const phone = row.slice(row.indexOf('@include layout.below(layout.$phone)'))

			expect(phone).toMatch(/inline-size: 36px;[^}]*block-size: 36px/s)
			expect(phone).toContain('padding-inline-start: 46px')
			expect(phone).toContain('padding-block-start: 16px')
		})
	})

	describe('grid', () => {
		it('draws square tiles in the photo grid\'s columns', () => {
			const wrapper = mount(TimelineSkeleton, { props: { variant: 'grid' } })

			expect(wrapper.findAll('.skeleton__cell .skeleton__tile')).toHaveLength(12)
			expect(wrapper.find('.skeleton__row').exists()).toBe(false)
			expect(rule('.skeleton__grid')).toContain('grid-template-columns: repeat(auto-fill, minmax(140px, 1fr))')
			expect(rule('.skeleton__grid')).toContain('gap: 2px')
			expect(rule('.skeleton__tile')).toContain('aspect-ratio: 1 / 1')
		})

		it('draws 16:9 cards with two lines of title for videos', () => {
			const wrapper = mount(TimelineSkeleton, { props: { variant: 'grid', shape: 'video' } })

			expect(wrapper.classes()).toContain('skeleton--video')
			const cells = wrapper.findAll('.skeleton__cell')
			expect(cells).toHaveLength(8)
			expect(cells[0].find('.skeleton__thumb').exists()).toBe(true)
			expect(cells[0].findAll('.skeleton__line')).toHaveLength(2)
			expect(rule('.skeleton__thumb')).toContain('aspect-ratio: 16 / 9')
			expect(rule('.skeleton--video .skeleton__grid')).toContain('repeat(var(--video-columns, 4), minmax(0, 1fr))')
		})

		it('takes a count like the rows do', () => {
			expect(mount(TimelineSkeleton, { props: { variant: 'grid', shape: 'video', count: 3 } }).findAll('.skeleton__cell'))
				.toHaveLength(3)
		})
	})

	describe('page', () => {
		it('is a heading and a few neutral blocks, with no post in it', () => {
			const wrapper = mount(TimelineSkeleton, { props: { variant: 'page' } })

			expect(wrapper.find('.skeleton__heading').exists()).toBe(true)
			expect(wrapper.findAll('.skeleton__block')).toHaveLength(3)
			expect(wrapper.find('.skeleton__row').exists()).toBe(false)
			expect(wrapper.find('.skeleton__avatar').exists()).toBe(false)
		})
	})

	describe('standing in for a whole page', () => {
		it('takes the column and a heading where the page title goes', () => {
			const wrapper = mount(TimelineSkeleton, { props: { variant: 'rows', standalone: true } })

			expect(wrapper.classes()).toContain('skeleton--standalone')
			expect(wrapper.find('.skeleton__heading').exists()).toBe(true)
			expect(source).toMatch(/&--standalone \{[^}]*max-width: var\(--social-column\);[^}]*margin-inline: auto/s)
		})

		it('has no heading of its own inside a list', () => {
			expect(mount(TimelineSkeleton).find('.skeleton__heading').exists()).toBe(false)
		})

		it('reads the feed column for rows and the wide one for videos', () => {
			expect(source).toMatch(/&--standalone\.skeleton--rows \{\s*--social-column: 616px;/)
			expect(source).toMatch(/&--standalone\.skeleton--video \{\s*--social-column: 1440px;/)
		})
	})

	it('stops the sheen for a reader who asked for less motion', () => {
		expect(source).toMatch(/@media \(prefers-reduced-motion: reduce\) \{\s*\.skeleton__shape \{\s*animation: none;/)
	})
})
