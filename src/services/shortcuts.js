/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import eventBus from './eventBus.js'

/**
 * Keyboard shortcuts, in the vocabulary other Fediverse clients use: j/k to
 * move through posts, l/b/r to act on the one in focus, n to compose, ? for
 * the list. Nothing fires while the viewer is typing (in a field, a
 * contenteditable, or with a modifier held) or while a dialog is open, and the
 * reader can turn them off here or in Nextcloud's Accessibility settings.
 */

/** The event the command palette opens on. */
export const PALETTE_EVENT = 'shortcut:palette'

/** ⌘ where the platform has one, Ctrl elsewhere; both work everywhere. */
const PALETTE_KEY = /Mac|iPhone|iPad|iPod/.test(globalThis.navigator?.platform ?? '') ? '⌘K' : 'Ctrl+K'

/**
 * `chord`: held with ⌘ or Ctrl, the one shortcut that is. A chord is not a
 * keystroke anybody types, so it may be pressed outside a field even though
 * the other shortcuts ignore anything with a modifier.
 *
 * @type {Array<{keys: string[], event: string, label: string, chord?: boolean}>}
 */
export const SHORTCUTS = [
	{ keys: [PALETTE_KEY], event: PALETTE_EVENT, label: t('social', 'Jump to a page, action or account'), chord: true },
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

/**
 * The keys of the video player, YouTube's: on a video's own page wherever the
 * focus is outside a text field, and in the small player while it has the
 * focus. The volume keys only while the player has the focus, so the arrows
 * still scroll the page. Listed here for the `?` dialog and the settings; the
 * player itself is `VideoPlayer.vue`.
 *
 * @type {Array<{keys: string[], label: string}>}
 */
export const PLAYER_SHORTCUTS = [
	{ keys: ['k', 'Space'], label: t('social', 'Play or pause the video') },
	{ keys: ['j', 'l'], label: t('social', 'Go back or forward 10 seconds') },
	{ keys: ['←', '→'], label: t('social', 'Go back or forward 5 seconds') },
	{ keys: ['↑', '↓'], label: t('social', 'Louder or quieter, while the player has the focus') },
	{ keys: ['m'], label: t('social', 'Sound off or on') },
	{ keys: ['f'], label: t('social', 'Full screen') },
	{ keys: ['c'], label: t('social', 'Subtitles on or off') },
	{ keys: ['<', '>'], label: t('social', 'Slower or faster') },
	{ keys: ['0', '…', '9'], label: t('social', 'Jump to the start, or to 10% to 90% of the video') },
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

	return isEditable(event.target)
}

/**
 * @param {EventTarget|null} target where a key was pressed
 * @return {boolean} whether it is a field or an editor
 */
function isEditable(target) {
	const element = /** @type {HTMLElement|null} */ (target)
	if (!element || typeof element.tagName !== 'string') {
		return false
	}

	return EDITABLE.includes(element.tagName.toLowerCase())
		|| element.isContentEditable === true
}

/**
 * Whether a key press is ⌘K or Ctrl+K, and not a variation of it.
 *
 * @param {KeyboardEvent} event the press
 * @return {boolean}
 */
export function isPaletteKey(event) {
	return (event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey
		&& String(event.key).toLowerCase() === 'k'
}

/**
 * Whether the focused element would act on this key itself: Enter on a button
 * or link is a keyboard user's click, so the element keeps it.
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
	// in a field it may be the editor's own: Ctrl+K inserts a link in many
	if (isPaletteKey(event)) {
		return isEditable(event.target) ? '' : PALETTE_EVENT
	}

	if (isTyping(event) || belongsToElement(event)) {
		return ''
	}

	const shortcut = SHORTCUTS.find(({ keys, chord }) => !chord && keys.includes(event.key))

	return shortcut === undefined ? '' : shortcut.event
}

/**
 * Whether a modal dialog is open — the image viewer, a confirmation, the
 * reaction picker. A modal that is drawn but hidden is not one.
 *
 * @return {boolean}
 */
export function isDialogOpen() {
	return [...document.querySelectorAll('[aria-modal="true"]')]
		.some((dialog) => dialog.closest('[hidden], [style*="display: none"]') === null)
}

/**
 * Whether the reader turned keyboard shortcuts off for all of Nextcloud, in
 * its Accessibility settings. Read the way the Nextcloud components read it.
 *
 * @return {boolean}
 */
export function offInNextcloud() {
	return window.OCP?.Accessibility?.disableKeyboardShortcuts?.() === true
}

/**
 * Starts listening for shortcuts.
 *
 * @param {() => boolean} [isEnabled] whether the reader has them on, asked at every press
 * @return {() => void} call to stop listening again
 */
export function listenForShortcuts(isEnabled = () => true) {
	const handler = (event) => {
		if (!isEnabled() || offInNextcloud() || isDialogOpen()) {
			return
		}

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
