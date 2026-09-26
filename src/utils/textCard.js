/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * Words on colour, drawn to a picture: a text story, a short post as a card,
 * and the stickers on a picture story all end here.
 *
 * Drawn to a canvas and uploaded as an ordinary picture rather than invented
 * as a new kind of post, so it federates as what it looks like. A Mastodon
 * reader sees a picture with the words on it; the words themselves travel as
 * the picture's description (and, for a post, as the post), so they are still
 * searchable and still read aloud.
 */

/**
 * The backgrounds, as two colour stops each and the ink that reads on them.
 * `account` is filled in from the writer's own hue at the time of drawing.
 *
 * @type {Array<{ id: string, from: string, to: string, ink: string }>}
 */
const GRADIENTS = [
	{ id: 'account', from: '', to: '', ink: '#ffffff' },
	{ id: 'sunset', from: '#ff7a59', to: '#e8367f', ink: '#ffffff' },
	{ id: 'ocean', from: '#1c92d2', to: '#0b3c8c', ink: '#ffffff' },
	{ id: 'forest', from: '#2bb673', to: '#0d5c46', ink: '#ffffff' },
	{ id: 'night', from: '#3b3f7a', to: '#111228', ink: '#ffffff' },
	{ id: 'lemon', from: '#ffe36e', to: '#ffa24c', ink: '#3a2600' },
]

/**
 * @param {string} id a gradient id
 * @return {string} what it is called, for a screen reader and a tooltip
 */
export function gradientName(id) {
	switch (id) {
		case 'account': return t('social', 'Your colour')
		case 'sunset': return t('social', 'Sunset')
		case 'ocean': return t('social', 'Ocean')
		case 'forest': return t('social', 'Forest')
		case 'night': return t('social', 'Night')
		case 'lemon': return t('social', 'Lemon')
		default: return t('social', 'Your colour')
	}
}

/**
 * Every background, with its colours resolved.
 *
 * @param {number} [hue] the writer's hue, for the one that is theirs
 * @return {Array<{ id: string, from: string, to: string, ink: string, name: string }>}
 */
export function cardGradients(hue = 210) {
	return GRADIENTS.map((gradient) => (gradient.id === 'account'
		? { ...gradient, from: `hsl(${hue} 72% 58%)`, to: `hsl(${(hue + 40) % 360} 65% 32%)`, name: gradientName('account') }
		: { ...gradient, name: gradientName(gradient.id) }))
}

/**
 * @param {string} id a gradient id
 * @param {number} [hue] the writer's hue
 * @return {{ id: string, from: string, to: string, ink: string, name: string }} that gradient, or the first
 */
export function findGradient(id, hue = 210) {
	const all = cardGradients(hue)

	return all.find((gradient) => gradient.id === id) ?? all[0]
}

/**
 * @param {{ from: string, to: string }} gradient the background
 * @return {string} the same background as CSS, for a preview
 */
export function gradientCss(gradient) {
	return `linear-gradient(160deg, ${gradient.from}, ${gradient.to})`
}

/**
 * Breaks text into lines that fit.
 *
 * A line break the writer typed is always kept. Within a paragraph words are
 * packed greedily, and a word longer than the whole line -- a URL, a long
 * hashtag -- is cut where it has to be rather than left to run off the card.
 *
 * @param {string} text what to lay out
 * @param {(line: string) => number} measure how wide a line is drawn
 * @param {number} maxWidth how wide a line may be
 * @return {string[]} the lines
 */
export function wrapLines(text, measure, maxWidth) {
	const lines = []

	for (const paragraph of String(text ?? '').split('\n')) {
		const words = paragraph.split(/\s+/).filter((word) => word !== '')
		if (words.length === 0) {
			lines.push('')
			continue
		}

		let line = ''
		for (const word of words) {
			const candidate = line === '' ? word : `${line} ${word}`
			if (measure(candidate) <= maxWidth) {
				line = candidate
				continue
			}

			if (line !== '') {
				lines.push(line)
			}

			// a word wider than the card on its own is cut into pieces that fit
			let rest = word
			while (measure(rest) > maxWidth && rest.length > 1) {
				let cut = rest.length - 1
				while (cut > 1 && measure(rest.slice(0, cut)) > maxWidth) {
					cut--
				}
				lines.push(rest.slice(0, cut))
				rest = rest.slice(cut)
			}
			line = rest
		}
		lines.push(line)
	}

	return lines
}

/**
 * Finds the largest type the text fits the box at.
 *
 * Short text is drawn big and long text small, which is what makes a text
 * card look designed: three words should fill it, and three sentences should
 * still fit.
 *
 * @param {string} text what to lay out
 * @param {(line: string, size: number) => number} measure how wide a line is at a size
 * @param {{ width: number, height: number }} box the room there is
 * @param {object} [range] the sizes to try
 * @param {number} [range.max] the biggest
 * @param {number} [range.min] the smallest, used even if it does not fit
 * @param {number} [range.lineHeight] line height as a multiple of the size
 * @return {{ size: number, lines: string[], lineHeight: number }} what to draw
 */
export function fitText(text, measure, box, { max = 140, min = 28, lineHeight = 1.25 } = {}) {
	for (let size = max; size >= min; size -= 4) {
		const lines = wrapLines(text, (line) => measure(line, size), box.width)
		if (lines.length * size * lineHeight <= box.height) {
			return { size, lines, lineHeight }
		}
	}

	return { size: min, lines: wrapLines(text, (line) => measure(line, min), box.width), lineHeight }
}

/** @return {string} the font the page uses, for the canvas to draw in */
function pageFont() {
	try {
		return window.getComputedStyle(document.body).fontFamily || 'sans-serif'
	} catch {
		return 'sans-serif'
	}
}

/**
 * @param {HTMLCanvasElement} canvas what was drawn
 * @param {string} name the file's name
 * @return {Promise<File|null>} it as a PNG, or null where the browser would not
 */
async function toFile(canvas, name) {
	const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))

	return blob ? new File([blob], name, { type: 'image/png', lastModified: Date.now() }) : null
}

/**
 * Draws words on a background and hands back the picture.
 *
 * @param {string} text the words
 * @param {{ from: string, to: string, ink: string }} gradient the background
 * @param {object} [size] the picture
 * @param {number} [size.width] in pixels
 * @param {number} [size.height] in pixels
 * @return {Promise<File|null>} a PNG, or null where there is no canvas to draw on
 */
export async function renderTextCard(text, gradient, { width = 1080, height = 1080 } = {}) {
	try {
		const canvas = document.createElement('canvas')
		canvas.width = width
		canvas.height = height
		const context = canvas.getContext('2d')
		if (context === null) {
			return null
		}

		const fill = context.createLinearGradient(0, 0, width * 0.35, height)
		fill.addColorStop(0, gradient.from)
		fill.addColorStop(1, gradient.to)
		context.fillStyle = fill
		context.fillRect(0, 0, width, height)

		const family = pageFont()
		const margin = Math.round(width * 0.1)
		const box = { width: width - margin * 2, height: height - margin * 2 }
		const measure = (line, size) => {
			context.font = `700 ${size}px ${family}`
			return context.measureText(line).width
		}
		const layout = fitText(text, measure, box, { max: Math.round(width * 0.13), min: Math.round(width * 0.035) })

		context.font = `700 ${layout.size}px ${family}`
		context.fillStyle = gradient.ink
		context.textAlign = 'center'
		context.textBaseline = 'middle'
		const step = layout.size * layout.lineHeight
		const top = height / 2 - ((layout.lines.length - 1) * step) / 2
		layout.lines.forEach((line, index) => {
			context.fillText(line, width / 2, top + index * step)
		})

		return await toFile(canvas, 'card.png')
	} catch {
		return null
	}
}

/**
 * Draws stickers onto a picture and hands back the result.
 *
 * Each sticker is an emoji or a line of text at a position given as fractions
 * of the picture, so what was placed over a preview of any size lands in the
 * same place on the full picture.
 *
 * Never throws and never loses the upload: a picture the browser cannot draw
 * comes back as it went in.
 *
 * @param {File} file the picture
 * @param {Array<{ kind: string, value: string, x: number, y: number, scale?: number }>} stickers what to draw, and where
 * @return {Promise<File>} the picture with the stickers on it
 */
export async function bakeStickers(file, stickers) {
	if (!Array.isArray(stickers) || stickers.length === 0 || !file?.type?.startsWith('image/')) {
		return file
	}

	try {
		const bitmap = await createImageBitmap(file)
		const canvas = document.createElement('canvas')
		canvas.width = bitmap.width
		canvas.height = bitmap.height
		const context = canvas.getContext('2d')
		if (context === null) {
			return file
		}

		context.drawImage(bitmap, 0, 0)
		bitmap.close?.()

		const family = pageFont()
		const edge = Math.min(canvas.width, canvas.height)
		context.textAlign = 'center'
		context.textBaseline = 'middle'
		for (const sticker of stickers) {
			const x = sticker.x * canvas.width
			const y = sticker.y * canvas.height
			const scale = sticker.scale ?? 1
			if (sticker.kind === 'emoji') {
				context.font = `${Math.round(edge * 0.16 * scale)}px ${family}`
				context.fillText(sticker.value, x, y)
				continue
			}

			// text sits on a rounded pill of its own, so it reads on any picture
			const size = Math.round(edge * 0.055 * scale)
			context.font = `700 ${size}px ${family}`
			const width = context.measureText(sticker.value).width
			const padX = size * 0.6
			const padY = size * 0.4
			context.fillStyle = 'rgba(255, 255, 255, 0.92)'
			roundedRect(context, x - width / 2 - padX, y - size / 2 - padY, width + padX * 2, size + padY * 2, size * 0.5)
			context.fill()
			context.fillStyle = '#111111'
			context.fillText(sticker.value, x, y)
		}

		const type = file.type === 'image/png' ? 'image/png' : 'image/jpeg'
		const blob = await new Promise((resolve) => canvas.toBlob(resolve, type, 0.92))
		if (!blob) {
			return file
		}

		const base = (file.name || 'story').replace(/\.[^.]+$/, '')

		return new File([blob], `${base}.${type === 'image/png' ? 'png' : 'jpg'}`, { type, lastModified: file.lastModified })
	} catch {
		return file
	}
}

/**
 * @param {CanvasRenderingContext2D} context where to draw
 * @param {number} x left
 * @param {number} y top
 * @param {number} width width
 * @param {number} height height
 * @param {number} radius corner radius
 */
function roundedRect(context, x, y, width, height, radius) {
	context.beginPath()
	context.moveTo(x + radius, y)
	context.arcTo(x + width, y, x + width, y + height, radius)
	context.arcTo(x + width, y + height, x, y + height, radius)
	context.arcTo(x, y + height, x, y, radius)
	context.arcTo(x, y, x + width, y, radius)
	context.closePath()
}
