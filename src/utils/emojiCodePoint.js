/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The code points of an emoji, as the filename its image in `img/twemoji/` is
 * stored under. Surrogate pairs are combined; everything else, zero-width
 * joiners and variation selectors included, is kept as it stands.
 *
 * @param {string} emoji one emoji, composed or not
 * @param {string} separator between code points, `-` as the filenames use
 * @return {string} lowercase hex code points, e.g. `1f468-200d-1f469-200d-1f467`
 */
export function toCodePoint(emoji, separator = '-') {
	const points = []
	let high = 0

	for (let i = 0; i < emoji.length; i++) {
		const code = emoji.charCodeAt(i)

		if (high !== 0) {
			points.push((0x10000 + ((high - 0xD800) << 10) + (code - 0xDC00)).toString(16))
			high = 0
		} else if (code >= 0xD800 && code <= 0xDBFF) {
			high = code
		} else {
			points.push(code.toString(16))
		}
	}

	return points.join(separator)
}
