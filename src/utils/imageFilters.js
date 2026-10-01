/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'

/**
 * The longest edge a picture is drawn at before it is filtered or uploaded.
 *
 * iOS refuses a canvas over 16 777 216 pixels (4096 × 4096) and says so only
 * by handing back no blob, so a 24 or 48 MP phone photo could not be drawn at
 * all. 4096 is also well past what any timeline shows a picture at.
 */
export const MAX_EDGE = 4096

/** how many smaller sizes are tried before a picture over the size limit is given up on */
const SHRINK_ATTEMPTS = 4

/** each of those tries is this much smaller than the one before */
const SHRINK_STEP = 0.75

/** rows handed to the per-pixel path at a time, so a 4096 px picture is not one 64 MB copy */
const BAND_ROWS = 256

/**
 * The filters the composer offers, as the CSS filter functions they are made
 * of, in order: `[function, amount]`, hue-rotate in degrees.
 *
 * Data rather than a CSS string, because the same list is used twice. The
 * preview is the CSS built from it — one `filter:` on the `<img>`, no canvas,
 * drawn on the GPU — and baking it in is either that same CSS handed to a
 * canvas context or, where the canvas has no working `filter` (WebKit), the
 * same steps worked out per pixel. Either way the picture uploaded is the
 * picture that was on screen.
 *
 * Only functions `STEPS` implements may appear here.
 *
 * They are deliberately mild. A filter that cannot be undone after upload should
 * not be the kind that ruins a photograph, and these are the adjustments people
 * actually reach for rather than the novelty ones.
 *
 * @type {Array<{id: string, steps: Array<[string, number]>}>}
 */
const FILTERS = [
	{ id: 'none', steps: [] },
	{ id: 'mono', steps: [['grayscale', 1]] },
	{ id: 'noir', steps: [['grayscale', 1], ['contrast', 1.3], ['brightness', 0.9]] },
	{ id: 'warm', steps: [['sepia', 0.35], ['saturate', 1.3], ['contrast', 1.05]] },
	{ id: 'cool', steps: [['hue-rotate', -12], ['saturate', 1.15], ['brightness', 1.05]] },
	{ id: 'vivid', steps: [['saturate', 1.6], ['contrast', 1.1]] },
	{ id: 'faded', steps: [['saturate', 0.75], ['contrast', 0.9], ['brightness', 1.1]] },
	{ id: 'sepia', steps: [['sepia', 0.8]] },
]

/**
 * A 3×3 colour matrix as a step.
 *
 * @param {number[][]} rows its three rows, red, green and blue out
 * @return {{matrix: number[]}} the nine coefficients, row-major
 */
function matrix(...rows) {
	return { matrix: rows.flat() }
}

/**
 * The Filter Effects spec's definition of each function the presets use, in
 * sRGB 0..1: a colour matrix (grayscale, sepia, saturate, hue-rotate, each
 * the spec's own coefficients) or a linear transfer `c × slope + intercept`
 * applied to each channel alike (brightness, contrast). Alpha is never
 * touched by any of them.
 *
 * grayscale and sepia clamp their amount to 0..1, as the spec does; the
 * others take what they are given.
 */
const STEPS = {
	grayscale: (amount) => {
		const a = 1 - Math.min(Math.max(amount, 0), 1)
		return matrix(
			[0.2126 + 0.7874 * a, 0.7152 - 0.7152 * a, 0.0722 - 0.0722 * a],
			[0.2126 - 0.2126 * a, 0.7152 + 0.2848 * a, 0.0722 - 0.0722 * a],
			[0.2126 - 0.2126 * a, 0.7152 - 0.7152 * a, 0.0722 + 0.9278 * a],
		)
	},
	sepia: (amount) => {
		const a = 1 - Math.min(Math.max(amount, 0), 1)
		return matrix(
			[0.393 + 0.607 * a, 0.769 - 0.769 * a, 0.189 - 0.189 * a],
			[0.349 - 0.349 * a, 0.686 + 0.314 * a, 0.168 - 0.168 * a],
			[0.272 - 0.272 * a, 0.534 - 0.534 * a, 0.131 + 0.869 * a],
		)
	},
	saturate: (s) => matrix(
		[0.213 + 0.787 * s, 0.715 - 0.715 * s, 0.072 - 0.072 * s],
		[0.213 - 0.213 * s, 0.715 + 0.285 * s, 0.072 - 0.072 * s],
		[0.213 - 0.213 * s, 0.715 - 0.715 * s, 0.072 + 0.928 * s],
	),
	'hue-rotate': (degrees) => {
		const radians = degrees * Math.PI / 180
		const cos = Math.cos(radians)
		const sin = Math.sin(radians)
		return matrix(
			[0.213 + cos * 0.787 - sin * 0.213, 0.715 - cos * 0.715 - sin * 0.715, 0.072 - cos * 0.072 + sin * 0.928],
			[0.213 - cos * 0.213 + sin * 0.143, 0.715 + cos * 0.285 + sin * 0.140, 0.072 - cos * 0.072 - sin * 0.283],
			[0.213 - cos * 0.213 - sin * 0.787, 0.715 - cos * 0.715 + sin * 0.715, 0.072 + cos * 0.928 + sin * 0.072],
		)
	},
	brightness: (b) => ({ slope: b, intercept: 0 }),
	contrast: (c) => ({ slope: c, intercept: 0.5 - 0.5 * c }),
}

/**
 * @param {string} name a filter function
 * @param {number} amount its argument
 * @return {string} it as CSS
 */
function cssStep(name, amount) {
	return name === 'hue-rotate' ? `${name}(${amount}deg)` : `${name}(${amount})`
}

/**
 * @param {string} id a filter id, or anything at all
 * @return {Array<[string, number]>} its steps; none for an id not in the list
 */
function stepsOf(id) {
	return FILTERS.find((filter) => filter.id === id)?.steps ?? []
}

/**
 * The name shown for a filter.
 *
 * Kept out of the table above so the strings are extracted at call time, with
 * the translations loaded — a module-level `t()` runs before they are.
 *
 * @param {string} id the filter id
 * @return {string} its translated name
 */
function filterName(id) {
	switch (id) {
		case 'none': return t('social', 'Original')
		case 'mono': return t('social', 'Mono')
		case 'noir': return t('social', 'Noir')
		case 'warm': return t('social', 'Warm')
		case 'cool': return t('social', 'Cool')
		case 'vivid': return t('social', 'Vivid')
		case 'faded': return t('social', 'Faded')
		case 'sepia': return t('social', 'Sepia')
		default: return t('social', 'Original')
	}
}

/** @return {Array<{id: string, css: string, name: string}>} every filter */
export function availableFilters() {
	return FILTERS.map((filter) => ({ id: filter.id, css: filterCss(filter.id), name: filterName(filter.id) }))
}

/**
 * @param {string} id a filter id, or anything at all
 * @return {string} the CSS for it, or '' for "no filter" and for one that is
 *                  not in the list — an unknown id must leave the picture alone
 *                  rather than fail.
 */
export function filterCss(id) {
	return stepsOf(id).map(([name, amount]) => cssStep(name, amount)).join(' ')
}

/** @param {string} id a filter id @return {boolean} whether it changes anything */
export function isFilterActive(id) {
	return stepsOf(id).length > 0
}

/**
 * Runs a filter's steps over RGBA pixels, in place.
 *
 * Each step is applied to the result of the one before and clamped to 0..1
 * before the next, which is what a browser does between the functions of a
 * `filter:` list; rounding to 8 bits happens once, at the end. Alpha is left
 * as it is.
 *
 * @param {Uint8ClampedArray} data RGBA bytes, as `getImageData()` gives them
 * @param {string} id the filter id
 */
export function filterPixels(data, id) {
	const steps = stepsOf(id).map(([name, amount]) => STEPS[name](amount))
	if (steps.length === 0) {
		return
	}

	const clamp = (value) => (value < 0 ? 0 : (value > 1 ? 1 : value))
	for (let i = 0; i < data.length; i += 4) {
		let r = data[i] / 255
		let g = data[i + 1] / 255
		let b = data[i + 2] / 255
		for (const step of steps) {
			if (step.matrix !== undefined) {
				const m = step.matrix
				const nr = m[0] * r + m[1] * g + m[2] * b
				const ng = m[3] * r + m[4] * g + m[5] * b
				const nb = m[6] * r + m[7] * g + m[8] * b
				r = clamp(nr)
				g = clamp(ng)
				b = clamp(nb)
			} else {
				r = clamp(r * step.slope + step.intercept)
				g = clamp(g * step.slope + step.intercept)
				b = clamp(b * step.slope + step.intercept)
			}
		}
		data[i] = Math.round(r * 255)
		data[i + 1] = Math.round(g * 255)
		data[i + 2] = Math.round(b * 255)
	}
}

/** whether a canvas context applies `filter`, once asked; null until then */
let canvasFilterWorks = null

/**
 * Whether this browser's 2D canvas really applies `filter`.
 *
 * Asked by drawing, not by looking for the property: some engines have it
 * and ignore it. One red pixel is drawn through `grayscale(1)` and read back;
 * a canvas that filtered it has r = g = b, one that did not still has red.
 * Asked once per page.
 *
 * @return {boolean}
 */
export function canvasFilterSupported() {
	if (canvasFilterWorks !== null) {
		return canvasFilterWorks
	}

	canvasFilterWorks = false
	try {
		const source = document.createElement('canvas')
		source.width = 1
		source.height = 1
		const sourceContext = source.getContext('2d')
		const target = document.createElement('canvas')
		target.width = 1
		target.height = 1
		const context = target.getContext('2d', { willReadFrequently: true })
		if (sourceContext === null || context === null || !('filter' in context)) {
			return canvasFilterWorks
		}

		sourceContext.fillStyle = '#ff0000'
		sourceContext.fillRect(0, 0, 1, 1)
		context.filter = 'grayscale(1)'
		context.drawImage(source, 0, 0)
		const [r, g, b] = context.getImageData(0, 0, 1, 1).data
		canvasFilterWorks = r > 0 && r === g && g === b
	} catch {
		canvasFilterWorks = false
	}

	return canvasFilterWorks
}

/** Forgets what the canvas probe found, for tests. */
export function resetCanvasFilterSupportForTests() {
	canvasFilterWorks = null
}

/**
 * The size a picture is drawn at so its longest edge is at most `edge`.
 *
 * @param {number} width its width
 * @param {number} height its height
 * @param {number} edge the longest edge allowed
 * @return {{width: number, height: number}} the size to draw it at; the same
 *         size when it already fits
 */
export function fitWithin(width, height, edge) {
	const longest = Math.max(width, height)
	if (longest <= edge) {
		return { width, height }
	}

	const scale = edge / longest
	return {
		width: Math.max(1, Math.round(width * scale)),
		height: Math.max(1, Math.round(height * scale)),
	}
}

/**
 * Decodes a picture the right way up.
 *
 * `imageOrientation: 'from-image'` applies the EXIF orientation, which a
 * canvas copy has to carry in its pixels since it carries no EXIF. Asked for
 * by name because it was not always the default; a browser that does not
 * know the option throws a TypeError and is asked again without it.
 *
 * @param {File} file the picture
 * @return {Promise<ImageBitmap>}
 */
async function decode(file) {
	try {
		return await createImageBitmap(file, { imageOrientation: 'from-image' })
	} catch (error) {
		if (error instanceof TypeError) {
			return await createImageBitmap(file)
		}
		throw error
	}
}

/**
 * Draws the picture at a size, through a filter, and encodes it.
 *
 * @param {ImageBitmap} bitmap the decoded picture
 * @param {{width: number, height: number}} size the size to draw it at
 * @param {string} filterId the filter
 * @param {string} type the mime to encode as
 * @return {Promise<Blob|null>} the encoded picture, or null when the canvas would not
 */
async function render(bitmap, size, filterId, type) {
	const canvas = document.createElement('canvas')
	canvas.width = size.width
	canvas.height = size.height

	const context = canvas.getContext('2d')
	if (context === null) {
		return null
	}

	const css = filterCss(filterId)
	const native = css !== '' && canvasFilterSupported()
	if (native) {
		context.filter = css
	}
	context.drawImage(bitmap, 0, 0, size.width, size.height)

	if (css !== '' && !native) {
		for (let y = 0; y < size.height; y += BAND_ROWS) {
			const rows = Math.min(BAND_ROWS, size.height - y)
			const band = context.getImageData(0, y, size.width, rows)
			filterPixels(band.data, filterId)
			context.putImageData(band, 0, y)
		}
	}

	return await new Promise((resolve) => {
		canvas.toBlob(resolve, type, 0.92)
	})
}

/**
 * Gets a picture ready to upload: drawn through a filter, and small enough
 * for the server and for the browser's canvas.
 *
 * - With no filter, a picture that is within `MAX_EDGE` and `sizeLimit` is
 *   handed back untouched: it is not re-encoded for nothing.
 * - Anything larger is drawn at a longest edge of `MAX_EDGE`, and smaller
 *   still, a step at a time, while the result is over `sizeLimit` — a 48 MP
 *   phone JPEG is shrunk here rather than refused by the server after the
 *   whole of it has been uploaded.
 *
 * JPEG in, JPEG out, at a quality chosen to be visually lossless. PNG is kept as
 * PNG so a screenshot or a picture with transparency does not gain a black
 * background where its alpha was. GIF and WebP are never touched, because a
 * canvas would keep only the first frame of an animated one. Anything that is
 * not an image the browser can decode — a video, an audio file, a HEIC the
 * browser cannot draw — comes back untouched, because there is nothing here
 * that could filter it.
 *
 * Never throws: a filter is a decoration, and losing somebody's upload because
 * a canvas would not cooperate is not a trade worth making. The original file is
 * returned instead, and the caller decides what to do with one that is still
 * over the limit.
 *
 * @param {File} file the picture as it was chosen
 * @param {object} [options] what to do with it
 * @param {string} [options.filter] which filter to bake in
 * @param {number} [options.sizeLimit] the most bytes the server takes, 0 for no limit
 * @return {Promise<File>} the prepared picture, or the original
 */
export async function prepareImage(file, { filter = 'none', sizeLimit = 0 } = {}) {
	if (!file || !file.type?.startsWith('image/')) {
		return file
	}

	if (file.type === 'image/gif' || file.type === 'image/webp') {
		return file
	}

	const filtering = isFilterActive(filter)
	const overLimit = sizeLimit > 0 && file.size > sizeLimit

	try {
		const bitmap = await decode(file)
		try {
			let size = fitWithin(bitmap.width, bitmap.height, MAX_EDGE)
			const shrinking = size.width !== bitmap.width || size.height !== bitmap.height
			if (!filtering && !shrinking && !overLimit) {
				return file
			}

			const type = (file.type === 'image/png') ? 'image/png' : 'image/jpeg'
			let blob = null
			for (let attempt = 0; attempt < SHRINK_ATTEMPTS; attempt++) {
				blob = await render(bitmap, size, filter, type)
				if (blob === null || sizeLimit <= 0 || blob.size <= sizeLimit) {
					break
				}
				size = fitWithin(size.width, size.height, Math.round(Math.max(size.width, size.height) * SHRINK_STEP))
			}

			if (blob === null) {
				return file
			}

			return new File([blob], renameFor(file.name, type), {
				type,
				lastModified: file.lastModified,
			})
		} finally {
			if (typeof bitmap.close === 'function') {
				bitmap.close()
			}
		}
	} catch {
		return file
	}
}

/**
 * The name the prepared copy carries.
 *
 * The extension has to follow the type: a JPEG called `.heic` is a file whose
 * name lies about it, and the mime is what everything downstream actually reads.
 *
 * @param {string} name the original name
 * @param {string} type the mime of the copy
 * @return {string} a name whose extension matches the type
 */
function renameFor(name, type) {
	const base = (name || 'image').replace(/\.[^.]+$/, '')
	const extension = (type === 'image/png') ? 'png' : 'jpg'

	return `${base}.${extension}`
}
