/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import eventBus from './eventBus.js'

/**
 * Keyboard shortcuts, in the vocabulary every other Fediverse client uses:
 * j/k to move through posts, l/b/r to act on the one in focus, n to compose,
 * ? for the list.
 *
 * Nothing fires while the viewer is typing — in a field, a contenteditable
 * composer, or with a modifier held — because a shortcut that eats a keystroke
 * mid-sentence is worse than no shortcut at all.
 */

/** @type {Array<{keys: string[], event: string, label: string}>} */
export const SHORTCUTS = [
	{ keys: ['j'], event: 'shortcut:next', label: t('social', 'Next post') },
	{ keys: ['k'], event: 'shortcut:previous', label: t('social', 'Previous post') },
	{ keys: ['l', 'f'], event: 'shortcut:like', label: t('social', 'Like the post in focus') },
	{ keys: ['b'], event: 'shortcut:boost', label: t('social', 'Boost the post in focus') },
	{ keys: ['r'], event: 'shortcut:reply', label: t('social', 'Reply to the post in focus') },
	{ keys: ['o', 'Enter'], event: 'shortcut:open', label: t('social', 'Open the post in focus') },
	{ keys: ['n'], event: 'shortcut:compose', label: t('social', 'Write a new post') },
	{ keys: ['g'], event: 'shortcut:home', label: t('social', 'Go to the home timeline') },
	{ keys: ['?'], event: 'shortcut:help', label: t('social', 'Show these shortcuts') },
]

const EDITABLE = ['input', 'textarea', 'select']

/**
 * Elements that already do something with Enter and the space bar: pressing
 * either on one of these is how a keyboard user clicks it.
 */
const ACTIVATABLE_TAGS = ['button', 'a', 'summary', 'details', 'option', 'label']
const ACTIVATABLE_ROLES = [
	'button',
	'link',
	'menuitem',
	'menuitemcheckbox',
	'menuitemradio',
	'checkbox',
	'radio',
	'switch',
	'tab',
	'option',
	'treeitem',
]

/** Keys an element may own, as opposed to keys that are only ever ours. */
const ACTIVATION_KEYS = ['Enter', ' ', 'Spacebar']

/**
 * Whether a key press is the viewer typing rather than commanding.
 *
 * @param {KeyboardEvent} event the press
 * @return {boolean} true when the press must be left alone
 */
export function isTyping(event) {
	if (event.ctrlKey || event.metaKey || event.altKey) {
		return true
	}

	const target = /** @type {HTMLElement|null} */ (event.target)
	if (!target || typeof target.tagName !== 'string') {
		return false
	}

	return EDITABLE.includes(target.tagName.toLowerCase())
		|| target.isContentEditable === true
}

/**
 * Whether the focused element would act on this key itself.
 *
 * Enter on a focused button or link is not a shortcut, it is a click — the
 * only way to press one without a mouse. Taking it away leaves a keyboard
 * user unable to use the app at all, so an element that owns the key keeps it.
 *
 * @param {KeyboardEvent} event the press
 * @return {boolean} true when the key belongs to the element, not to us
 */
export function belongsToElement(event) {
	if (!ACTIVATION_KEYS.includes(event.key)) {
		return false
	}

	const target = /** @type {HTMLElement|null} */ (event.target)
	if (!target || typeof target.tagName !== 'string') {
		return false
	}

	if (ACTIVATABLE_TAGS.includes(target.tagName.toLowerCase())) {
		return true
	}

	const role = typeof target.getAttribute === 'function' ? target.getAttribute('role') : null

	// anything given a widget role is expected to answer to Enter as well
	return role !== null && ACTIVATABLE_ROLES.includes(role)
}

/**
 * @param {KeyboardEvent} event the press
 * @return {string} the event to publish, or '' when the key means nothing here
 */
export function eventFor(event) {
	if (isTyping(event) || belongsToElement(event)) {
		return ''
	}

	const shortcut = SHORTCUTS.find(({ keys }) => keys.includes(event.key))

	return shortcut === undefined ? '' : shortcut.event
}

/**
 * Starts listening for shortcuts.
 *
 * @return {() => void} call to stop listening again
 */
export function listenForShortcuts() {
	const handler = (event) => {
		const name = eventFor(event)
		if (name === '') {
			return
		}

		event.preventDefault()
		eventBus.emit(name)
	}

	window.addEventListener('keydown', handler)

	return () => window.removeEventListener('keydown', handler)
}
