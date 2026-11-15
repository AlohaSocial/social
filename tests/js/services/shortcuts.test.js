/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { PALETTE_EVENT, SHORTCUTS, belongsToElement, eventFor, isDialogOpen, isPaletteKey, isTyping, listenForShortcuts } from '../../../src/services/shortcuts.js'
import eventBus from '../../../src/services/eventBus.js'

function press(key, target = document.body, extra = {}) {
	return {
		key,
		target,
		preventDefault: vi.fn(),
		ctrlKey: false,
		metaKey: false,
		altKey: false,
		...extra,
	}
}

describe('isTyping', () => {
	it('leaves fields alone', () => {
		for (const tag of ['input', 'textarea', 'select']) {
			expect(isTyping(press('j', document.createElement(tag)))).toBe(true)
		}
	})

	it('leaves the composer alone, which is a contenteditable div', () => {
		const composer = document.createElement('div')
		composer.isContentEditable = true

		expect(isTyping(press('j', composer))).toBe(true)
	})

	it('leaves anything with a modifier to the browser', () => {
		expect(isTyping(press('j', document.body, { ctrlKey: true }))).toBe(true)
		expect(isTyping(press('j', document.body, { metaKey: true }))).toBe(true)
		expect(isTyping(press('j', document.body, { altKey: true }))).toBe(true)
	})

	it('treats a press on the page itself as a command', () => {
		expect(isTyping(press('j'))).toBe(false)
	})
})

describe('belongsToElement', () => {
	const withRole = (role) => {
		const element = document.createElement('div')
		element.setAttribute('role', role)

		return element
	}

	it('leaves Enter to anything that is clicked with it', () => {
		// pressing Enter on a focused button or link IS the click; taking it
		// away is what leaves a keyboard user unable to use the app at all
		for (const tag of ['button', 'a', 'summary', 'details', 'option', 'label']) {
			expect(belongsToElement(press('Enter', document.createElement(tag))), tag).toBe(true)
		}
	})

	it('leaves the space bar to them as well', () => {
		expect(belongsToElement(press(' ', document.createElement('button')))).toBe(true)
	})

	it('leaves Enter to anything given a widget role', () => {
		for (const role of ['button', 'link', 'menuitem', 'checkbox', 'tab', 'switch']) {
			expect(belongsToElement(press('Enter', withRole(role))), role).toBe(true)
		}
	})

	it('keeps the keys that no element answers to', () => {
		expect(belongsToElement(press('j', document.createElement('button')))).toBe(false)
		expect(belongsToElement(press('o', document.createElement('a')))).toBe(false)
	})

	it('keeps Enter when nothing in particular is focused', () => {
		expect(belongsToElement(press('Enter'))).toBe(false)
		expect(belongsToElement(press('Enter', withRole('article')))).toBe(false)
	})
})

describe('eventFor', () => {
	it('maps the keys other clients use', () => {
		expect(eventFor(press('j'))).toBe('shortcut:next')
		expect(eventFor(press('k'))).toBe('shortcut:previous')
		expect(eventFor(press('l'))).toBe('shortcut:like')
		expect(eventFor(press('f'))).toBe('shortcut:like')
		expect(eventFor(press('b'))).toBe('shortcut:boost')
		expect(eventFor(press('r'))).toBe('shortcut:reply')
		expect(eventFor(press('n'))).toBe('shortcut:compose')
		expect(eventFor(press('?'))).toBe('shortcut:help')
	})

	it('ignores keys it does not claim', () => {
		expect(eventFor(press('q'))).toBe('')
		expect(eventFor(press('Escape'))).toBe('')
	})

	it('does not take Enter away from a focused button or link', () => {
		expect(eventFor(press('Enter', document.createElement('button')))).toBe('')
		expect(eventFor(press('Enter', document.createElement('a')))).toBe('')
	})

	it('still opens the post in focus when Enter is nobody else\'s', () => {
		expect(eventFor(press('Enter'))).toBe('shortcut:open')
	})

	it('ignores everything while typing', () => {
		expect(eventFor(press('j', document.createElement('input')))).toBe('')
	})

	it('gives every shortcut a label to show in the help sheet', () => {
		for (const shortcut of SHORTCUTS) {
			expect(shortcut.label.length).toBeGreaterThan(0)
			expect(shortcut.keys.length).toBeGreaterThan(0)
		}
	})
})

describe('the help sheet', () => {
	it('offers nothing that no key produces', () => {
		for (const shortcut of SHORTCUTS) {
			for (const key of shortcut.keys) {
				// a chord is shown as the platform writes it, and pressed as K with a modifier
				const event = shortcut.chord ? press(key.slice(-1).toLowerCase(), document.body, { ctrlKey: true }) : press(key)
				expect(eventFor(event), key).toBe(shortcut.event)
			}
		}
	})
})

describe('the command palette chord', () => {
	afterEach(() => {
		eventBus.all.clear()
	})

	it('is ⌘K or Ctrl+K and nothing else', () => {
		expect(isPaletteKey(press('k', document.body, { metaKey: true }))).toBe(true)
		expect(isPaletteKey(press('k', document.body, { ctrlKey: true }))).toBe(true)
		expect(isPaletteKey(press('K', document.body, { ctrlKey: true, shiftKey: true }))).toBe(false)
		expect(isPaletteKey(press('k', document.body, { ctrlKey: true, altKey: true }))).toBe(false)
		expect(isPaletteKey(press('k'))).toBe(false)
	})

	it('opens the palette from the page, but not from a field, where it may be the editor\'s', () => {
		expect(eventFor(press('k', document.body, { metaKey: true }))).toBe(PALETTE_EVENT)
		expect(eventFor(press('k', document.createElement('textarea'), { ctrlKey: true }))).toBe('')
	})

	it('leaves a plain k to the shortcut it already is', () => {
		expect(eventFor(press('k'))).toBe('shortcut:previous')
	})

	it('is taken from the browser, and obeys the same off switches as every shortcut', () => {
		const heard = vi.fn()
		eventBus.on(PALETTE_EVENT, heard)
		let enabled = true
		const stop = listenForShortcuts(() => enabled)

		try {
			const event = new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, cancelable: true })
			window.dispatchEvent(event)
			expect(heard).toHaveBeenCalledTimes(1)
			expect(event.defaultPrevented).toBe(true)

			enabled = false
			window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }))
			expect(heard).toHaveBeenCalledTimes(1)

			enabled = true
			window.OCP.Accessibility = { disableKeyboardShortcuts: () => true }
			window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }))
			expect(heard).toHaveBeenCalledTimes(1)
		} finally {
			delete window.OCP.Accessibility
			stop()
		}
	})
})

describe('listenForShortcuts', () => {
	let stop

	afterEach(() => {
		stop?.()
		eventBus.all.clear()
	})

	it('publishes the shortcut and takes the key', () => {
		const heard = vi.fn()
		eventBus.on('shortcut:like', heard)
		stop = listenForShortcuts()

		const event = new KeyboardEvent('keydown', { key: 'l', cancelable: true })
		window.dispatchEvent(event)

		expect(heard).toHaveBeenCalledTimes(1)
		expect(event.defaultPrevented).toBe(true)
	})

	it('leaves a key it does not claim to the page', () => {
		stop = listenForShortcuts()

		const event = new KeyboardEvent('keydown', { key: 'q', cancelable: true })
		window.dispatchEvent(event)

		expect(event.defaultPrevented).toBe(false)
	})

	it('does nothing while a dialog is over the page, and the key stays the dialog\'s', () => {
		const heard = vi.fn()
		eventBus.on('shortcut:boost', heard)
		stop = listenForShortcuts()
		const viewer = document.createElement('div')
		viewer.setAttribute('role', 'dialog')
		viewer.setAttribute('aria-modal', 'true')
		document.body.appendChild(viewer)

		const event = new KeyboardEvent('keydown', { key: 'b', cancelable: true })
		window.dispatchEvent(event)
		viewer.remove()

		expect(heard).not.toHaveBeenCalled()
		expect(event.defaultPrevented).toBe(false)
	})

	it('does nothing while the reader has them turned off, and asks at every press', () => {
		const heard = vi.fn()
		eventBus.on('shortcut:like', heard)
		let enabled = false
		stop = listenForShortcuts(() => enabled)

		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'l' }))
		expect(heard).not.toHaveBeenCalled()

		enabled = true
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'l' }))
		expect(heard).toHaveBeenCalledTimes(1)
	})

	it('does nothing while the reader turned shortcuts off for all of Nextcloud', () => {
		const heard = vi.fn()
		eventBus.on('shortcut:like', heard)
		stop = listenForShortcuts()
		window.OCP.Accessibility = { disableKeyboardShortcuts: () => true }

		try {
			const event = new KeyboardEvent('keydown', { key: 'l', cancelable: true })
			window.dispatchEvent(event)

			expect(heard).not.toHaveBeenCalled()
			expect(event.defaultPrevented).toBe(false)
		} finally {
			delete window.OCP.Accessibility
		}
	})

	it('stops listening when told to', () => {
		const heard = vi.fn()
		eventBus.on('shortcut:next', heard)
		listenForShortcuts()()

		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'j' }))

		expect(heard).not.toHaveBeenCalled()
	})
})

describe('isDialogOpen', () => {
	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('is false with nothing open', () => {
		expect(isDialogOpen()).toBe(false)
	})

	it('sees a modal dialog', () => {
		document.body.innerHTML = '<div class="modal-mask" role="dialog" aria-modal="true"></div>'

		expect(isDialogOpen()).toBe(true)
	})

	it('does not count a modal that is drawn but hidden', () => {
		document.body.innerHTML = '<div class="modal-mask" role="dialog" aria-modal="true" style="display: none;"></div>'
			+ '<div hidden><div role="dialog" aria-modal="true"></div></div>'

		expect(isDialogOpen()).toBe(false)
	})

	it('does not count a card that is a dialog without being modal', () => {
		document.body.innerHTML = '<div role="dialog"></div>'

		expect(isDialogOpen()).toBe(false)
	})
})
