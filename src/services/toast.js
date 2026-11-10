/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Toasts, fetched when something actually has to be said.
 *
 * The toasts come from `@nextcloud/dialogs/toast`, the entry point that holds
 * only them; the package's main entry also carries the file picker, the
 * conflict picker and the dialog builder.
 *
 * Nothing waits for a toast, so nothing here needs to be awaited: each call
 * returns a promise that the caller is free to ignore, and the library arrives
 * in its own chunk the first time one is shown. The import is memoised by the
 * module system, so the second toast is immediate.
 *
 * The signatures are `@nextcloud/dialogs`' own, forwarded unchanged, except
 * that `showError` also takes a sentence with the server's own words beside it.
 */

import { t } from '@nextcloud/l10n'

/**
 * The toast entry imports its own stylesheet, so both arrive in the `toast`
 * chunk, out of the entry this file was split out of to keep small.
 *
 * @return {Promise<typeof import('@nextcloud/dialogs/toast')>} the toast module, loaded once
 */
function dialogs() {
	return import(/* webpackChunkName: "toast" */ '@nextcloud/dialogs/toast')
}

/**
 * The sentence, and the server's own words under a "Details" the person can
 * open. Built from text nodes, so nothing the server said becomes markup.
 *
 * @param {{text: string, details: string}} error as utils/serverError.js makes it
 * @return {HTMLElement} the toast's content
 */
function withDetails(error) {
	const content = document.createElement('div')
	content.append(error.text)

	const details = document.createElement('details')
	const summary = document.createElement('summary')
	summary.textContent = t('social', 'Details')
	const said = document.createElement('code')
	said.textContent = error.details
	said.style.display = 'block'
	said.style.whiteSpace = 'pre-wrap'
	said.style.overflowWrap = 'anywhere'
	details.append(summary, said)
	content.append(details)

	return content
}

/**
 * @param {string|{text: string, details: string}} text what went wrong, or that
 *        and what the server said, as utils/serverError.js makes it; the
 *        server's words go behind "Details"
 * @param {object} [options] as `@nextcloud/dialogs` takes them
 * @return {Promise<object>} the toast, for a caller that wants to hide it
 */
export async function showError(text, options) {
	const toasts = await dialogs()
	if (typeof text === 'string') {
		return toasts.showError(text, options)
	}
	if (!text.details || text.details === text.text) {
		return toasts.showError(text.text, options)
	}

	return toasts.showMessage(withDetails(text), { ...options, type: toasts.ToastType.ERROR })
}

/**
 * @param {string} text what worked
 * @param {object} [options] as `@nextcloud/dialogs` takes them
 * @return {Promise<object>} the toast
 */
export async function showSuccess(text, options) {
	return (await dialogs()).showSuccess(text, options)
}

/**
 * @param {string} text what the reader should know about
 * @param {object} [options] as `@nextcloud/dialogs` takes them
 * @return {Promise<object>} the toast
 */
export async function showWarning(text, options) {
	return (await dialogs()).showWarning(text, options)
}

/**
 * @param {string} text what happened
 * @param {object} [options] as `@nextcloud/dialogs` takes them
 * @return {Promise<object>} the toast
 */
export async function showInfo(text, options) {
	return (await dialogs()).showInfo(text, options)
}

/**
 * A toast with an Undo button, which stays for the ten seconds
 * `@nextcloud/dialogs` gives an undo.
 *
 * @param {string} text what was just done
 * @param {(event: MouseEvent) => void} onUndo called when Undo is pressed
 * @param {object} [options] as `@nextcloud/dialogs` takes them
 * @return {Promise<object>} the toast
 */
export async function showUndo(text, onUndo, options) {
	return (await dialogs()).showUndo(text, onUndo, options)
}
