/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * @param {string} value text to put inside a double-quoted HTML attribute
 * @return {string}
 */
function attribute(value) {
	return value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

/**
 * The HTML another site pastes to show a post.
 *
 * @param {string} src the post's embed address
 * @param {string} title what a screen reader announces for the frame, in the copier's language
 * @return {string}
 */
export function embedCode(src, title) {
	return `<iframe src="${attribute(src)}" width="550" height="480" loading="lazy" frameborder="0" style="max-width:100%;border:0;border-radius:12px" title="${attribute(title)}"></iframe>`
}
