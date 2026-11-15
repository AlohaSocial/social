/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { RECAP_SIZE, drawRecap, renderRecapImage, themeColour, wrapLines } from '../../../src/utils/recapImage.js'

/**
 * A 2D context that remembers what was asked of it, measuring every
 * character as 10 pixels.
 *
 * @return {object} the context, with `texts` and `fills` collected
 */
function recordingContext() {
	const texts = []
	const fills = []
	const context = {
		texts,
		fills,
		font: '',
		fillStyle: '',
		globalAlpha: 1,
		measureText: (text) => ({ width: text.length * 10 }),
		fillText: (text, x, y) => texts.push({ text, x, y, font: context.font, fillStyle: context.fillStyle }),
		fillRect: () => fills.push(context.fillStyle),
		createLinearGradient: () => ({ addColorStop: vi.fn() }),
		save: vi.fn(),
		restore: vi.fn(),
		translate: vi.fn(),
		scale: vi.fn(),
		rotate: vi.fn(),
		fill: vi.fn(),
	}

	return context
}

const RECAP = {
	count: 7,
	headline: 'You posted 7 times this week.',
	aside: '3 the week before.',
	instance: 'cloud.example',
	accent: 'rgb(0, 103, 158)',
	onAccent: '#ffffff',
	font: 'system-ui',
}

describe('the weekly recap as a picture', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		document.body.style.removeProperty('--color-primary-element')
	})

	it('is drawn on the theme accent, with the count, the sentences and the instance', () => {
		const context = recordingContext()

		drawRecap(context, RECAP)

		expect(context.fills[0]).toBe('rgb(0, 103, 158)')
		const said = context.texts.map(({ text }) => text)
		expect(said).toEqual(expect.arrayContaining(['Aloha Social', '7', 'You posted 7 times this week.', '3 the week before.', 'cloud.example']))
		for (const { fillStyle } of context.texts) {
			expect(fillStyle).toBe('#ffffff')
		}
	})

	it('leaves the week before out when there is none', () => {
		const context = recordingContext()

		drawRecap(context, { ...RECAP, aside: '' })

		expect(context.texts.map(({ text }) => text)).not.toContain('')
	})

	it('wraps a sentence that is wider than the picture', () => {
		const lines = wrapLines(recordingContext(), 'one two three four five six', 120)

		expect(lines).toEqual(['one two', 'three four', 'five six'])
		expect(wrapLines(recordingContext(), '', 120)).toEqual([])
	})

	it('reads the accent from the theme, and has one where the theme says nothing', () => {
		expect(themeColour('--color-primary-element', '#00679e')).toBe('#00679e')

		document.body.style.setProperty('--color-primary-element', '#ff0000')
		expect(themeColour('--color-primary-element', '#00679e')).toBe('#ff0000')
	})

	it('comes out as a square PNG file', async () => {
		const context = recordingContext()
		const canvas = {
			width: 0,
			height: 0,
			getContext: () => context,
			toBlob: (done, type) => done(new Blob(['png'], { type })),
		}
		const create = document.createElement.bind(document)
		vi.spyOn(document, 'createElement').mockImplementation((tag) => (tag === 'canvas' ? canvas : create(tag)))

		const file = await renderRecapImage(RECAP)

		expect(canvas.width).toBe(RECAP_SIZE)
		expect(canvas.height).toBe(RECAP_SIZE)
		expect(file).toBeInstanceOf(File)
		expect(file.type).toBe('image/png')
		expect(file.name).toBe('weekly-recap.png')
	})

	it('gives up quietly where the browser cannot draw', async () => {
		const create = document.createElement.bind(document)
		vi.spyOn(document, 'createElement').mockImplementation((tag) => (tag === 'canvas' ? { getContext: () => null } : create(tag)))

		expect(await renderRecapImage(RECAP)).toBeNull()
	})
})
