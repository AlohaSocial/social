/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A post's HTML as the words that were typed. Block elements and `<br>` become
 * newlines, as `LinkifyService` made them; everything else contributes only
 * its text, so a mention gives back its handle.
 *
 * @param {string} html the post's content
 * @return {string} the words, without the markup
 */
export function htmlToPlainText(html) {
	const parser = new DOMParser()
	const dom = parser.parseFromString(`<div id="rootwrapper">${html}</div>`, 'text/html')
	const root = dom.getElementById('rootwrapper')
	if (!root) {
		return ''
	}

	return nodeToPlainText(root).trim()
}

/**
 * What the composer's editable box says, as the text the post is sent with.
 * An emoji drawn as an image counts as its alt text; text nodes hold decoded
 * characters, so a typed `&amp;` stays as typed.
 *
 * @param {HTMLElement|null|undefined} element the editable element
 * @return {string} the text, trimmed
 */
export function editableToPlainText(element) {
	if (element === undefined || element === null) {
		return ''
	}

	const copy = /** @type {HTMLElement} */ (element.cloneNode(true))
	Array.from(copy.getElementsByClassName('emoji')).forEach((emoji) => {
		emoji.replaceWith(document.createTextNode(emoji.getAttribute('alt') ?? ''))
	})

	return nodeToPlainText(copy).trim()
}

/**
 * @param {Node} node the element to read
 * @return {string} its text, with the block structure as newlines
 */
function nodeToPlainText(node) {
	let text = ''
	for (const child of Array.from(node.childNodes)) {
		if (child.nodeType === Node.TEXT_NODE) {
			text += child.textContent || ''
			continue
		}

		if (child.nodeType !== Node.ELEMENT_NODE) {
			continue
		}

		// the guard above established that it is an Element
		const element = /** @type {Element} */ (child)
		if (element.tagName === 'BR') {
			text += '\n'
			continue
		}

		const isBlock = ['DIV', 'P', 'LI', 'BLOCKQUOTE', 'PRE'].includes(element.tagName)
		if (isBlock && text !== '' && !text.endsWith('\n')) {
			text += '\n'
		}

		text += nodeToPlainText(element)
		if (isBlock && !text.endsWith('\n')) {
			text += '\n'
		}
	}

	return text
}
