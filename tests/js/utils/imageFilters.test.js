/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	MAX_EDGE,
	availableFilters,
	canvasFilterSupported,
	filterCss,
	filterPixels,
	fitWithin,
	isFilterActive,
	prepareImage,
	resetCanvasFilterSupportForTests,
} from '../../../src/utils/imageFilters.js'

vi.mock('@nextcloud/l10n', () => ({ t: (app, text) => text }))

/**
 * A canvas with just enough of a 2D context for these tests.
 *
 * `mode` is what its `filter` does: `works` applies it (the probe reads back
 * grey), `ignored` has the property and does nothing with it (the probe reads
 * back red, as WebKit's does), `missing` has no property at all. Every pixel
 * read outside the probe is `pixel`; `toBlob` answers with a blob of
 * `blobSize(width, height)` bytes, or null when `blobSize` is null.
 */
class FakeCanvas {
	constructor({ mode, pixel, blobSize, log }) {
		this.width = 0
		this.height = 0
		this.mode = mode
		this.pixel = pixel
		this.blobSize = blobSize
		this.log = log
		log.canvases.push(this)
	}

	getContext() {
		const canvas = this
		const context = {
			fillStyle: '',
			drawn: [],
			put: [],
			fillRect() {},
			drawImage(...args) {
				context.drawn.push(args)
			},
			getImageData(x, y, width, height) {
				if (canvas.width === 1 && canvas.height === 1) {
					const grey = canvas.mode === 'works' && context.filter === 'grayscale(1)'
					return { data: new Uint8ClampedArray(grey ? [54, 54, 54, 255] : [255, 0, 0, 255]) }
				}
				const data = new Uint8ClampedArray(width * height * 4)
				for (let i = 0; i < data.length; i += 4) {
					data.set(canvas.pixel, i)
				}
				return { data, width, height }
			},
			putImageData(image, x, y) {
				context.put.push({ image, x, y })
			},
		}
		if (this.mode !== 'missing') {
			context.filter = 'none'
		}
		this.context = context

		return context
	}

	toBlob(callback, type) {
		this.log.types.push(type)
		callback(this.blobSize === null ? null : new Blob([new Uint8Array(this.blobSize(this.width, this.height))], { type }))
	}
}

/**
 * Replaces every canvas the module makes with a FakeCanvas.
 *
 * @param {object} [options] how the canvases behave
 * @return {{canvases: FakeCanvas[], types: string[]}} every canvas made, and every type encoded
 */
function fakeCanvases({ mode = 'works', pixel = [200, 100, 50, 128], blobSize = () => 10 } = {}) {
	const log = { canvases: [], types: [] }
	const create = document.createElement.bind(document)
	vi.spyOn(document, 'createElement').mockImplementation((tag) => (tag === 'canvas'
		? new FakeCanvas({ mode, pixel, blobSize, log })
		: create(tag)))

	return log
}

/** @return {FakeCanvas[]} the canvases a picture was drawn on, the probe's left out */
function drawnOn(log) {
	return log.canvases.filter((canvas) => !(canvas.width === 1 && canvas.height === 1))
}

/**
 * @param {number} width the decoded width
 * @param {number} height the decoded height
 * @return {import('vitest').Mock} a createImageBitmap answering with a picture that size
 */
function decodesAs(width, height) {
	const decode = vi.fn().mockResolvedValue({ width, height, close: vi.fn() })
	vi.stubGlobal('createImageBitmap', decode)

	return decode
}

const png = () => new File(['x'], 'cat.png', { type: 'image/png' })
const jpeg = (bytes = 1) => new File([new Uint8Array(bytes)], 'holiday.jpeg', { type: 'image/jpeg' })

describe('imageFilters', () => {
	beforeEach(() => {
		resetCanvasFilterSupportForTests()
	})

	afterEach(() => {
		vi.restoreAllMocks()
		vi.unstubAllGlobals()
	})

	describe('filterCss', () => {
		it('gives no filter for the original', () => {
			expect(filterCss('none')).toBe('')
			expect(isFilterActive('none')).toBe(false)
		})

		/**
		 * An id this build does not know -- an old draft, a hand-edited store --
		 * has to leave the picture alone rather than throw or produce `filter:
		 * undefined`, which would blank the preview.
		 */
		it('leaves a picture alone for an id it does not know', () => {
			for (const id of ['nonsense', '', null, undefined, 42]) {
				expect(filterCss(id)).toBe('')
				expect(isFilterActive(id)).toBe(false)
			}
		})

		it('gives real CSS for a real filter', () => {
			expect(filterCss('mono')).toBe('grayscale(1)')
			expect(isFilterActive('mono')).toBe(true)
		})

		/** The preview is built from the same steps the fallback runs. */
		it('builds the CSS from the steps, hue-rotate in degrees', () => {
			expect(filterCss('noir')).toBe('grayscale(1) contrast(1.3) brightness(0.9)')
			expect(filterCss('cool')).toBe('hue-rotate(-12deg) saturate(1.15) brightness(1.05)')
		})
	})

	describe('availableFilters', () => {
		it('offers the original first, so the default is the one that changes nothing', () => {
			expect(availableFilters()[0].id).toBe('none')
		})

		it('names every filter', () => {
			for (const filter of availableFilters()) {
				expect(filter.name).toBeTruthy()
				expect(typeof filter.css).toBe('string')
			}
		})

		it('has no duplicate ids', () => {
			const ids = availableFilters().map((filter) => filter.id)
			expect(new Set(ids).size).toBe(ids.length)
		})
	})

	/**
	 * The expected colours are the Filter Effects spec's own formulas worked
	 * through by hand for three sample pixels: each function applied in turn
	 * in sRGB 0..1, clamped after each, rounded to 8 bits at the end. For
	 * mono, (200, 100, 50) is 0.2126·200 + 0.7152·100 + 0.0722·50 = 117.65.
	 * White through warm shows the clamp between steps: sepia(0.35) takes
	 * its red row to 1.12, which is clamped to 1 before saturate sees it.
	 */
	describe('filterPixels', () => {
		const expected = {
			mono: [[118, 118, 118], [255, 255, 255], [162, 162, 162]],
			noir: [[103, 103, 103], [230, 230, 230], [156, 156, 156]],
			warm: [[209, 112, 52], [255, 255, 254], [46, 204, 220]],
			cool: [[238, 96, 64], [255, 255, 255], [0, 224, 224]],
			vivid: [[255, 86, 0], [255, 255, 255], [0, 232, 255]],
			faded: [[192, 117, 80], [255, 255, 255], [62, 203, 232]],
			sepia: [[172, 137, 101], [255, 255, 242], [164, 185, 161]],
		}
		const samples = [[200, 100, 50], [255, 255, 255], [10, 200, 240]]

		it('covers every filter there is', () => {
			expect(Object.keys(expected).sort()).toEqual(availableFilters().map((filter) => filter.id).filter((id) => id !== 'none').sort())
		})

		it.each(Object.entries(expected))('draws %s as the spec says', (id, colours) => {
			const data = new Uint8ClampedArray(samples.flatMap((sample, index) => [...sample, 60 + index]))

			filterPixels(data, id)

			colours.forEach((colour, index) => {
				expect(Array.from(data.slice(index * 4, index * 4 + 3))).toEqual(colour)
				// alpha is never touched
				expect(data[index * 4 + 3]).toBe(60 + index)
			})
		})

		it('leaves the pixels alone for no filter', () => {
			const data = new Uint8ClampedArray([200, 100, 50, 255])
			filterPixels(data, 'none')
			expect(Array.from(data)).toEqual([200, 100, 50, 255])
		})
	})

	describe('canvasFilterSupported', () => {
		it('says yes when the canvas really filters', () => {
			fakeCanvases({ mode: 'works' })
			expect(canvasFilterSupported()).toBe(true)
		})

		/** WebKit: the property is there and the pixel comes back red. */
		it('says no when the canvas has the property and ignores it', () => {
			fakeCanvases({ mode: 'ignored' })
			expect(canvasFilterSupported()).toBe(false)
		})

		it('says no when the canvas has no filter at all', () => {
			fakeCanvases({ mode: 'missing' })
			expect(canvasFilterSupported()).toBe(false)
		})

		it('asks once per page', () => {
			const log = fakeCanvases({ mode: 'works' })
			canvasFilterSupported()
			canvasFilterSupported()
			expect(log.canvases).toHaveLength(2)
		})
	})

	describe('fitWithin', () => {
		it('leaves a picture that fits as it is', () => {
			expect(fitWithin(4000, 3000, MAX_EDGE)).toEqual({ width: 4000, height: 3000 })
			expect(fitWithin(4096, 4096, MAX_EDGE)).toEqual({ width: 4096, height: 4096 })
		})

		/** a 48 MP phone photo, landscape and portrait */
		it('brings the longest edge down to the limit, keeping the shape', () => {
			expect(fitWithin(8064, 6048, MAX_EDGE)).toEqual({ width: 4096, height: 3072 })
			expect(fitWithin(6048, 8064, MAX_EDGE)).toEqual({ width: 3072, height: 4096 })
		})

		/** what iOS will still draw: 4096 × 4096 is its ceiling of 16.7 M pixels */
		it('keeps every result within the iOS canvas ceiling', () => {
			const { width, height } = fitWithin(12000, 9000, MAX_EDGE)
			expect(width * height).toBeLessThanOrEqual(16777216)
		})
	})

	describe('prepareImage', () => {
		it('hands back the same file when there is no filter to apply', async () => {
			const file = png()
			decodesAs(800, 600)
			const log = fakeCanvases()

			expect(await prepareImage(file)).toBe(file)
			expect(await prepareImage(file, { filter: 'none', sizeLimit: 100 })).toBe(file)
			expect(log.canvases).toHaveLength(0)
		})

		it('hands back the same file for something that is not a picture', async () => {
			const video = new File(['x'], 'clip.mp4', { type: 'video/mp4' })
			expect(await prepareImage(video, { filter: 'mono' })).toBe(video)
		})

		/**
		 * A canvas takes the first frame, which is not what anybody meant by
		 * "apply a filter" to an animation, nor by making it smaller.
		 */
		it('leaves an animated picture alone rather than flattening it to one frame', async () => {
			const decode = decodesAs(9000, 9000)
			const gif = new File([new Uint8Array(50)], 'wave.gif', { type: 'image/gif' })
			const webp = new File([new Uint8Array(50)], 'wave.webp', { type: 'image/webp' })

			expect(await prepareImage(gif, { filter: 'mono', sizeLimit: 10 })).toBe(gif)
			expect(await prepareImage(webp, { filter: 'mono', sizeLimit: 10 })).toBe(webp)
			expect(decode).not.toHaveBeenCalled()
		})

		/**
		 * A filter is a decoration. Losing somebody's upload because a canvas
		 * would not cooperate is not a trade worth making, so every failure
		 * path returns the original rather than throwing.
		 */
		it('returns the original when the browser cannot decode the picture', async () => {
			const file = png()
			vi.stubGlobal('createImageBitmap', vi.fn().mockRejectedValue(new Error('nope')))

			await expect(prepareImage(file, { filter: 'mono' })).resolves.toBe(file)
		})

		it('returns the original when the canvas produces no blob', async () => {
			const file = png()
			decodesAs(2, 2)
			fakeCanvases({ blobSize: null })

			await expect(prepareImage(file, { filter: 'mono' })).resolves.toBe(file)
		})

		it('returns the original when there is no 2D context', async () => {
			const file = png()
			decodesAs(2, 2)
			vi.spyOn(document, 'createElement').mockReturnValue({
				width: 0,
				height: 0,
				getContext: () => null,
				toBlob: vi.fn(),
			})

			await expect(prepareImage(file, { filter: 'mono' })).resolves.toBe(file)
		})

		it('lets go of the decoded picture', async () => {
			const decode = decodesAs(2, 2)
			fakeCanvases()

			await prepareImage(png(), { filter: 'mono' })

			const bitmap = await decode.mock.results[0].value
			expect(bitmap.close).toHaveBeenCalled()
		})

		/** A canvas copy carries no EXIF, so the rotation has to be in its pixels. */
		it('decodes the picture the right way up', async () => {
			const decode = decodesAs(2, 2)
			fakeCanvases()

			await prepareImage(png(), { filter: 'mono' })

			expect(decode).toHaveBeenCalledWith(expect.any(File), { imageOrientation: 'from-image' })
		})

		it('asks again without the option when the browser does not know it', async () => {
			const decode = vi.fn()
				.mockRejectedValueOnce(new TypeError('unknown imageOrientation'))
				.mockResolvedValue({ width: 2, height: 2, close: vi.fn() })
			vi.stubGlobal('createImageBitmap', decode)
			const log = fakeCanvases()

			const out = await prepareImage(png(), { filter: 'mono' })

			expect(decode).toHaveBeenCalledTimes(2)
			expect(decode.mock.calls[1]).toHaveLength(1)
			expect(out.type).toBe('image/png')
			expect(drawnOn(log)).toHaveLength(1)
		})

		it('keeps a PNG a PNG, so transparency does not turn black', async () => {
			decodesAs(2, 2)
			const log = fakeCanvases()

			const out = await prepareImage(png(), { filter: 'mono' })

			expect(log.types).toEqual(['image/png'])
			expect(out.type).toBe('image/png')
			expect(out.name).toBe('cat.png')
		})

		/** The extension has to follow the type, or the name lies about the file. */
		it('renames a transcoded picture so its extension matches its type', async () => {
			decodesAs(2, 2)
			fakeCanvases()

			const out = await prepareImage(jpeg(), { filter: 'warm' })

			expect(out.type).toBe('image/jpeg')
			expect(out.name).toBe('holiday.jpg')
		})

		it('hands the filter to a canvas that applies it, and touches no pixel itself', async () => {
			decodesAs(2, 2)
			const log = fakeCanvases({ mode: 'works' })

			await prepareImage(png(), { filter: 'sepia' })

			const [canvas] = drawnOn(log)
			expect(canvas.context.filter).toBe(filterCss('sepia'))
			expect(canvas.context.put).toHaveLength(0)
		})

		it.each(['ignored', 'missing'])('works the filter out per pixel when the canvas filter is %s', async (mode) => {
			decodesAs(3, 2)
			const log = fakeCanvases({ mode, pixel: [200, 100, 50, 128] })

			await prepareImage(png(), { filter: 'mono' })

			const [canvas] = drawnOn(log)
			// never asked to filter, so nothing is applied twice where the
			// property is there and happens to work partly
			expect(canvas.context.filter === undefined || canvas.context.filter === 'none').toBe(true)
			expect(canvas.context.put).toHaveLength(1)
			const { image, x, y } = canvas.context.put[0]
			expect([x, y]).toEqual([0, 0])
			for (let i = 0; i < image.data.length; i += 4) {
				expect(Array.from(image.data.slice(i, i + 4))).toEqual([118, 118, 118, 128])
			}
		})

		it('works a tall picture through in bands, every row once', async () => {
			decodesAs(2, 600)
			const log = fakeCanvases({ mode: 'ignored' })

			await prepareImage(png(), { filter: 'mono' })

			const [canvas] = drawnOn(log)
			const rows = canvas.context.put.map(({ image, y }) => [y, image.height])
			expect(rows).toEqual([[0, 256], [256, 256], [512, 88]])
		})

		it('draws a photo too large for an iPhone canvas at 4096 px, filter or not', async () => {
			for (const filter of ['none', 'mono']) {
				decodesAs(8064, 6048)
				const log = fakeCanvases()

				const out = await prepareImage(jpeg(), { filter })

				const [canvas] = drawnOn(log)
				expect([canvas.width, canvas.height]).toEqual([4096, 3072])
				expect(canvas.context.drawn[0].slice(1)).toEqual([0, 0, 4096, 3072])
				expect(out.type).toBe('image/jpeg')
				vi.restoreAllMocks()
			}
		})

		/** a picture that fits the canvas but not the server's size limit */
		it('shrinks a picture over the size limit a step at a time until it fits', async () => {
			decodesAs(2000, 1000)
			// the encoded size follows the pixel count: 2 MB, 1.1 MB, 0.6 MB
			const log = fakeCanvases({ blobSize: (width, height) => width * height })

			const out = await prepareImage(jpeg(1500000), { sizeLimit: 1000000 })

			expect(drawnOn(log).map((canvas) => canvas.width)).toEqual([2000, 1500, 1125])
			expect(out.size).toBeLessThanOrEqual(1000000)
		})

		it('gives up after a few sizes and hands back the smallest it made', async () => {
			decodesAs(2000, 1000)
			const log = fakeCanvases({ blobSize: () => 5000000 })

			const out = await prepareImage(jpeg(6000000), { sizeLimit: 1000000 })

			expect(drawnOn(log)).toHaveLength(4)
			expect(out.size).toBe(5000000)
		})

		it('leaves a picture within both limits exactly as it is', async () => {
			decodesAs(4000, 3000)
			const log = fakeCanvases()
			const file = jpeg(900000)

			expect(await prepareImage(file, { sizeLimit: 1000000 })).toBe(file)
			expect(log.canvases).toHaveLength(0)
		})
	})
})
