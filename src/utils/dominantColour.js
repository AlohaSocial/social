/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The prevailing colour of an image, to tint a profile by its banner. The
 * image is sampled once in a tiny canvas; anything that fails resolves to null.
 */

/** how many pixels a side; small enough to be free, large enough to average */
const SAMPLE = 8

/**
 * @param {string} url the image to sample
 * @return {Promise<{r: number, g: number, b: number}|null>} its average colour
 */
export function dominantColour(url) {
	return new Promise((resolve) => {
		if (!url || typeof document === 'undefined') {
			resolve(null)
			return
		}

		const image = new Image()
		image.crossOrigin = 'anonymous'
		image.addEventListener('error', () => resolve(null))
		image.addEventListener('load', () => {
			try {
				const canvas = document.createElement('canvas')
				canvas.width = SAMPLE
				canvas.height = SAMPLE
				const context = canvas.getContext('2d')
				if (context === null) {
					resolve(null)
					return
				}

				context.drawImage(image, 0, 0, SAMPLE, SAMPLE)
				const { data } = context.getImageData(0, 0, SAMPLE, SAMPLE)

				let r = 0
				let g = 0
				let b = 0
				let counted = 0
				for (let i = 0; i < data.length; i += 4) {
					// skip what is transparent enough not to be seen
					if (data[i + 3] < 128) {
						continue
					}
					r += data[i]
					g += data[i + 1]
					b += data[i + 2]
					counted++
				}

				if (counted === 0) {
					resolve(null)
					return
				}

				resolve({
					r: Math.round(r / counted),
					g: Math.round(g / counted),
					b: Math.round(b / counted),
				})
			} catch {
				// a canvas tainted by a cross-origin image throws on read
				resolve(null)
			}
		})

		image.src = url
	})
}

/**
 * The same colour at a saturation and lightness that reads as an accent on
 * light and dark surfaces; a grey banner stays grey.
 *
 * @param {{r: number, g: number, b: number}|null} rgb an averaged colour
 * @return {string} an hsl() colour, or '' when there was nothing to read
 */
export function asAccent(rgb) {
	if (rgb === null) {
		return ''
	}

	const r = rgb.r / 255
	const g = rgb.g / 255
	const b = rgb.b / 255
	const max = Math.max(r, g, b)
	const min = Math.min(r, g, b)
	const delta = max - min

	let hue = 0
	if (delta !== 0) {
		if (max === r) {
			hue = ((g - b) / delta) % 6
		} else if (max === g) {
			hue = (b - r) / delta + 2
		} else {
			hue = (r - g) / delta + 4
		}
		hue = Math.round(hue * 60)
		if (hue < 0) {
			hue += 360
		}
	}

	// keep whatever saturation the banner has, within bounds that read as an
	// accent rather than as a wash or a neon
	const saturation = Math.min(Math.max(Math.round(delta * 100), 15), 70)

	return `hsl(${hue}, ${saturation}%, 45%)`
}
