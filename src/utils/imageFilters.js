/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'

/**
 * The filters the composer offers, as CSS filter functions.
 *
 * CSS rather than per-pixel arithmetic, for two reasons. The preview is then
 * one `filter:` on the `<img>` — no canvas, no copy of the picture, nothing to
 * recompute while somebody flicks through the list — and the browser draws it on
 * the GPU. And baking it in is the *same* declaration handed to a canvas
 * context, so what gets uploaded is what was on screen rather than a second
 * implementation that has to be kept in step with the first.
 *
 * They are deliberately mild. A filter that cannot be undone after upload should
 * not be the kind that ruins a photograph, and these are the adjustments people
 * actually reach for rather than the novelty ones.
 *
 * @type {Array<{id: string, css: string}>}
 */
const FILTERS = [
	{ id: 'none', css: '' },
	{ id: 'mono', css: 'grayscale(1)' },
	{ id: 'noir', css: 'grayscale(1) contrast(1.3) brightness(0.9)' },
	{ id: 'warm', css: 'sepia(0.35) saturate(1.3) contrast(1.05)' },
	{ id: 'cool', css: 'hue-rotate(-12deg) saturate(1.15) brightness(1.05)' },
	{ id: 'vivid', css: 'saturate(1.6) contrast(1.1)' },
	{ id: 'faded', css: 'saturate(0.75) contrast(0.9) brightness(1.1)' },
	{ id: 'sepia', css: 'sepia(0.8)' },
]

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
	return FILTERS.map((filter) => ({ ...filter, name: filterName(filter.id) }))
}

/**
 * @param {string} id a filter id, or anything at all
 * @return {string} the CSS for it, or '' for "no filter" and for one that is
 *                  not in the list — an unknown id must leave the picture alone
 *                  rather than fail.
 */
export function filterCss(id) {
	return FILTERS.find((filter) => filter.id === id)?.css ?? ''
}

/** @param {string} id a filter id @return {boolean} whether it changes anything */
export function isFilterActive(id) {
	return filterCss(id) !== ''
}

/**
 * Draws the file through the filter and hands back a new one.
 *
 * Called when the choice settles, not on every stop along the way: the preview
 * is CSS on an `<img>`, so flicking through eight filters costs nothing, and the
 * composer debounces before it gets here.
 *
 * JPEG in, JPEG out, at a quality chosen to be visually lossless. PNG is kept as
 * PNG so a screenshot or a picture with transparency does not gain a black
 * background where its alpha was. Anything that is not an image the browser can
 * decode — a video, an audio file, a HEIC the browser cannot draw — comes back
 * untouched, because there is nothing here that could filter it.
 *
 * Never throws: a filter is a decoration, and losing somebody's upload because
 * a canvas would not cooperate is not a trade worth making. The original file is
 * returned instead.
 *
 * @param {File} file the picture as it was chosen
 * @param {string} filterId which filter to bake in
 * @return {Promise<File>} the filtered picture, or the original
 */
export async function applyFilterToFile(file, filterId) {
	const css = filterCss(filterId)
	if (css === '' || !file || !file.type?.startsWith('image/')) {
		return file
	}

	// an animated picture would come back as its first frame, which is not what
	// anybody meant by "apply a filter"
	if (file.type === 'image/gif' || file.type === 'image/webp') {
		return file
	}

	try {
		const bitmap = await createImageBitmap(file)
		const canvas = document.createElement('canvas')
		canvas.width = bitmap.width
		canvas.height = bitmap.height

		const context = canvas.getContext('2d')
		if (context === null || !('filter' in context)) {
			return file
		}

		context.filter = css
		context.drawImage(bitmap, 0, 0)
		if (typeof bitmap.close === 'function') {
			bitmap.close()
		}

		const type = (file.type === 'image/png') ? 'image/png' : 'image/jpeg'
		const blob = await new Promise((resolve) => {
			canvas.toBlob(resolve, type, 0.92)
		})

		if (blob === null) {
			return file
		}

		return new File([blob], renameFor(file.name, type), {
			type,
			lastModified: file.lastModified,
		})
	} catch {
		return file
	}
}

/**
 * The name the filtered copy carries.
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
