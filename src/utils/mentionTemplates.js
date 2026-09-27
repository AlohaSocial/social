/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The HTML the composer's @ and # autocomplete (tributejs) draws.
 *
 * tributejs takes its menu rows and the chip it inserts as HTML strings and
 * sets them with `innerHTML`, so every value in them is escaped here: a
 * username, a profile address and an avatar come from whatever server the
 * account lives on, and the server keeps a display name as plain text on the
 * understanding that whatever renders it escapes it.
 */

const ENTITIES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }

/**
 * @param {unknown} value any value
 * @return {string} it as text that is safe inside an element or a quoted attribute
 */
export function escapeHtml(value) {
	return String(value ?? '').replace(/[&<>"']/g, (char) => ENTITIES[char])
}

/**
 * @param {unknown} url an address from somewhere else, or a path on this server
 * @return {string} it, escaped, when it is http, https or a path here; `#` otherwise
 */
export function safeHref(url) {
	const text = String(url ?? '')
	return /^(https?:\/\/|\/(?!\/))/i.test(text) ? escapeHtml(text) : '#'
}

/**
 * @param {{ key: string, value: string, avatar: string }} account a search result
 * @return {string} its row in the @ menu
 */
export function mentionMenuItem(account) {
	return `<img src="${safeHref(account.avatar)}" alt="" /><div>`
		+ `<span class="displayName">${escapeHtml(account.key)}</span>`
		+ `<span class="account">${escapeHtml(account.value)}</span>`
		+ '</div>'
}

/**
 * @param {{ value: string, url: string, avatar: string }} account the chosen account
 * @return {string} the mention chip put into the text
 */
export function mentionChip(account) {
	return '<span class="mention" contenteditable="false">'
		+ `<a href="${safeHref(account.url)}" target="_blank" rel="noopener noreferrer">`
		+ `<img src="${safeHref(account.avatar)}" alt="" />`
		+ `@${escapeHtml(account.value)}`
		+ '</a>'
		+ '</span>&nbsp;'
}

/**
 * @param {string} tag the hashtag, without its #
 * @param {string} href where its timeline is
 * @return {string} the hashtag chip put into the text
 */
export function hashtagChip(tag, href) {
	return '<span class="hashtag" contenteditable="false">'
		+ `<a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer">#${escapeHtml(tag)}</a></span>`
}
