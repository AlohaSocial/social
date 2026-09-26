/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { bakeStickers, cardGradients, findGradient, fitText, gradientCss, renderTextCard, wrapLines } from '../../../src/utils/textCard.js'

/** every character is ten pixels wide, so the arithmetic is easy to read */
const tenEach = (line) => line.length * 10

describe('wrapLines', () => {
	it('packs words onto a line until the next would not fit', () => {
		expect(wrapLines('one two three four', tenEach, 90)).toEqual(['one two', 'three', 'four'])
	})

	it('keeps the line breaks the writer typed, empty lines included', () => {
		expect(wrapLines('a\n\nb', tenEach, 100)).toEqual(['a', '', 'b'])
	})

	/** a URL wider than the card on its own must not run off the edge */
	it('cuts a word that is wider than the whole line', () => {
		const lines = wrapLines('abcdefghij', tenEach, 40)

		expect(lines).toEqual(['abcd', 'efgh', 'ij'])
		expect(lines.every((line) => tenEach(line) <= 40)).toBe(true)
	})

	it('answers nothing with one empty line', () => {
		expect(wrapLines('', tenEach, 100)).toEqual([''])
		expect(wrapLines(null, tenEach, 100)).toEqual([''])
	})
})

describe('fitText', () => {
	const measure = (line, size) => line.length * size * 0.5

	it('draws three words big', () => {
		const short = fitText('Hello there friend', measure, { width: 800, height: 800 })

		expect(short.size).toBe(140)
	})

	it('draws a paragraph smaller, so it still fits', () => {
		const text = 'This is a much longer piece of writing that has to be made smaller to fit the card it is drawn on.'
		const long = fitText(text, measure, { width: 800, height: 800 })

		expect(long.size).toBeLessThan(140)
		expect(long.lines.length * long.size * long.lineHeight).toBeLessThanOrEqual(800)
	})

	it('stops at the smallest size rather than going on shrinking', () => {
		const huge = fitText('word '.repeat(2000), measure, { width: 200, height: 200 }, { max: 60, min: 20 })

		expect(huge.size).toBe(20)
	})
})

describe('the backgrounds', () => {
	it('offers six, the writer\'s own first', () => {
		const all = cardGradients(120)

		expect(all).toHaveLength(6)
		expect(all[0].id).toBe('account')
		expect(all[0].from).toContain('hsl(120')
		expect(all.every((gradient) => gradient.name !== '')).toBe(true)
	})

	it('falls back to the first for an id it does not know', () => {
		expect(findGradient('nope').id).toBe('account')
		expect(findGradient('ocean').id).toBe('ocean')
	})

	it('draws the same background in CSS for the preview', () => {
		expect(gradientCss({ from: '#000', to: '#fff' })).toBe('linear-gradient(160deg, #000, #fff)')
	})
})

describe('drawing, where there is nothing to draw on', () => {
	it('answers null for a card rather than throwing', async () => {
		expect(await renderTextCard('hi', findGradient('ocean'))).toBeNull()
	})

	it('hands the picture back untouched when stickers cannot be baked in', async () => {
		const file = new File(['x'], 'p.jpg', { type: 'image/jpeg' })

		expect(await bakeStickers(file, [{ kind: 'emoji', value: '🔥', x: 0.5, y: 0.5 }])).toBe(file)
		expect(await bakeStickers(file, [])).toBe(file)
	})
})
