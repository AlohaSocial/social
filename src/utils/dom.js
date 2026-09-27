/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What the field an input or change event came from holds, for a template
 * handler: `$event.target` is only an `EventTarget` to the type checker.
 *
 * @param {Event} event an event from an input, textarea or select
 * @return {string} its value
 */
export function valueOf(event) {
	return /** @type {HTMLInputElement} */ (event.target).value
}

/**
 * Whether the checkbox a change event came from is ticked.
 *
 * @param {Event} event a change event from a checkbox
 * @return {boolean}
 */
export function checkedOf(event) {
	return /** @type {HTMLInputElement} */ (event.target).checked
}
