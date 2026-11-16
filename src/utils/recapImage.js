/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { APP_NAME } from './appName.js'

/**
 * The weekly recap as a picture somebody can post.
 *
 * Square, because that is what every client on the network crops a single
 * picture to anyway; drawn on a canvas in the theme's own accent, so the card
 * looks like the instance it came from. The words on it are the recap's own
 * sentences, already translated — the picture says nothing the card did not.
 */

/** Width and height of the picture, in pixels. */
export const RECAP_SIZE = 1080

/** The petal of the app's flower, pointing up from the centre, 30 units long. */
const PETAL = 'M0 -4C9 -10 11 -24 0 -30C-11 -24 -9 -10 0 -4Z'

/**
 * Reads a colour the theme defines, for a canvas that cannot read CSS itself.
 *
 * @param {string} property a custom property, e.g. `--color-primary-element`
 * @param {string} fallback what to use where the theme does not say
 * @return {string} the colour
 */
export function themeColour(property, fallback) {
	try {
		const value = window.getComputedStyle(document.body).getPropertyValue(property).trim()
		return value !== '' ? value : fallback
	} catch {
		return fallback
	}
}

/**
 * Splits a sentence into lines no wider than the canvas allows.
 *
 * @param {CanvasRenderingContext2D} context measures the words
 * @param {string} text the sentence
 * @param {number} width the widest a line may be
 * @return {string[]} the lines
 */
export function wrapLines(context, text, width) {
	const lines = []
	let line = ''
	for (const word of String(text).split(/\s+/).filter(Boolean)) {
		const candidate = line === '' ? word : line + ' ' + word
		if (line !== '' && context.measureText(candidate).width > width) {
			lines.push(line)
			line = word
		} else {
			line = candidate
		}
	}
	if (line !== '') {
		lines.push(line)
	}

	return lines
}

/**
 * Draws the app's flower.
 *
 * @param {CanvasRenderingContext2D} context where
 * @param {number} x the centre
 * @param {number} y the centre
 * @param {number} scale how many pixels a petal unit is
 * @param {string} colour the petals
 */
function drawFlower(context, x, y, scale, colour) {
	const petal = typeof Path2D === 'function' ? new Path2D(PETAL) : null
	context.save()
	context.translate(x, y)
	context.scale(scale, scale)
	context.fillStyle = colour
	for (let index = 0; index < 5; index++) {
		if (petal !== null) {
			context.fill(petal)
		}
		context.rotate((Math.PI * 2) / 5)
	}
	context.restore()
}

/**
 * Draws the recap.
 *
 * @param {CanvasRenderingContext2D} context a square canvas, `RECAP_SIZE` across
 * @param {object} recap what to say
 * @param {number} recap.count posts this week
 * @param {string} recap.headline the recap's sentence
 * @param {string} recap.aside the week before, or ''
 * @param {string} recap.instance where the account lives
 * @param {string} recap.accent the theme's accent colour
 * @param {string} recap.onAccent the text colour the theme puts on it
 * @param {string} recap.font the font family
 */
export function drawRecap(context, { count, headline, aside, instance, accent, onAccent, font }) {
	const size = RECAP_SIZE
	const middle = size / 2

	context.fillStyle = accent
	context.fillRect(0, 0, size, size)

	// a soft light from the top corner, so the accent is a surface rather
	// than a flat fill whichever colour the theme picked
	const light = context.createLinearGradient(0, 0, size, size)
	light.addColorStop(0, 'rgba(255, 255, 255, .22)')
	light.addColorStop(1, 'rgba(0, 0, 0, .18)')
	context.fillStyle = light
	context.fillRect(0, 0, size, size)

	context.globalAlpha = 0.12
	drawFlower(context, size - 140, 150, 9, onAccent)
	drawFlower(context, 120, size - 110, 6, onAccent)
	context.globalAlpha = 1

	context.fillStyle = onAccent
	context.textAlign = 'center'
	context.textBaseline = 'alphabetic'

	drawFlower(context, middle, 150, 1.6, onAccent)
	context.font = `600 40px ${font}`
	context.fillText(APP_NAME, middle, 250)

	context.font = `700 300px ${font}`
	context.fillText(String(count), middle, 600)

	context.font = `600 52px ${font}`
	let y = 720
	for (const line of wrapLines(context, headline, size - 200)) {
		context.fillText(line, middle, y)
		y += 66
	}

	if (aside !== '') {
		context.globalAlpha = 0.8
		context.font = `400 40px ${font}`
		context.fillText(aside, middle, y + 10)
		context.globalAlpha = 1
	}

	context.globalAlpha = 0.85
	context.font = `500 36px ${font}`
	context.fillText(instance, middle, size - 80)
	context.globalAlpha = 1
}

/**
 * The recap as a PNG file, ready to attach to a post.
 *
 * @param {object} recap what to say; see `drawRecap`, without the colours
 * @param {number} recap.count posts this week
 * @param {string} recap.headline the recap's sentence
 * @param {string} recap.aside the week before, or ''
 * @param {string} recap.instance where the account lives
 * @return {Promise<File|null>} the picture, or null where the browser cannot draw one
 */
export async function renderRecapImage(recap) {
	const canvas = document.createElement('canvas')
	canvas.width = RECAP_SIZE
	canvas.height = RECAP_SIZE
	let context
	try {
		context = canvas.getContext('2d')
	} catch {
		context = null
	}
	if (!context) {
		return null
	}

	drawRecap(context, {
		...recap,
		accent: themeColour('--color-primary-element', '#00679e'),
		onAccent: themeColour('--color-primary-element-text', '#ffffff'),
		font: themeColour('--font-face', 'system-ui, sans-serif'),
	})

	const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))
	if (!blob) {
		return null
	}

	return new File([blob], 'weekly-recap.png', { type: 'image/png' })
}
