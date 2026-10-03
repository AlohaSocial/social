/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A post's HTML as the words that were typed.
 *
 * A post is written as plain text and published as HTML, and two places need
 * to go back the other way: the inline editor, and the re-draft that puts a
 * deleted post back in the composer. They have to agree — the same post edited
 * and re-drafted must come back as the same text — which is why this is one
 * function and not two.
 *
 * Block elements end in a newline and `<br>` is a newline, because that is
 * what `LinkifyService` made of the newlines on the way out. Everything else
 * contributes its text and nothing else: a mention arrives as a link around a
 * handle, and the handle is what was typed.
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
 *
 * An emoji drawn as a picture counts as its alt text. Entities are the
 * browser's to decode: the walk below reads text nodes, which hold the
 * characters and not the markup, so an `&amp;` the reader typed stays the
 * five characters they typed.
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

		// a ChildNode is not an Element until the node type says so, which the
		// guard above has just established
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
