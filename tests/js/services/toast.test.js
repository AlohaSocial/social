/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'

/**
 * The toast service, read as source.
 *
 * What has to hold here is about the *build*, not about what the functions
 * return: the library is behind a dynamic import to keep it out of the entry,
 * and the thing that broke was which chunk its stylesheet ended up in. A test
 * that called `showError` and asserted it resolved would have passed
 * throughout.
 */
const SOURCE = readFileSync(resolve(process.cwd(), 'src/services/toast.js'), 'utf8')

describe('the toast service', () => {
	/**
	 * `@nextcloud/dialogs/toast` holds only the toasts and imports the
	 * stylesheet they are drawn with; the main entry would bring the file
	 * picker along.
	 */
	it('loads the toast entry point, lazily, in its own chunk', () => {
		expect(SOURCE).toMatch(/import\(\s*\/\* webpackChunkName: "toast" \*\/\s*'@nextcloud\/dialogs\/toast'\)/)
		expect(SOURCE).not.toMatch(/^import\s+.*from\s+'@nextcloud\/dialogs/m)
		expect(SOURCE).not.toMatch(/'@nextcloud\/dialogs'/)
	})

	/**
	 * Version 8 has no `style.css` export any more: importing it fails the
	 * build, and the toast entry brings its rules itself.
	 */
	it('imports no stylesheet of its own', () => {
		expect(SOURCE).not.toContain('style.css')
	})

	/**
	 * One static import of the package's main entry puts its toast chunk —
	 * about 58 KB gzipped, most of it the file picker's translations — on
	 * the path to the first screen. The file picker is imported where it is
	 * wanted, with `import()`.
	 */
	it('is the only way the app reaches the toasts', () => {
		const offenders = sourceFiles(resolve(process.cwd(), 'src'))
			.filter((file) => /^import\s[^;]*from\s+'@nextcloud\/dialogs(\/toast)?'/m.test(readFileSync(file, 'utf8')))
			.map((file) => file.slice(process.cwd().length + 1))

		expect(offenders).toEqual([])
	})

	it('forwards the four kinds of toast the app raises', () => {
		for (const fn of ['showError', 'showSuccess', 'showWarning', 'showInfo']) {
			expect(SOURCE).toContain(`export async function ${fn}(`)
		}
	})
})

describe('showError', () => {
	/**
	 * A refusal of a route with a code is a sentence in the person's
	 * language, and the server's own English goes behind "Details" for
	 * whoever helps them: as text, so nothing it says becomes markup.
	 */
	it('puts what the server said behind "Details", as text', async () => {
		const { dialogs, showError } = await loadToast()

		await showError({ text: 'That is under way already.', details: '<b>an import</b> is running' })

		expect(dialogs.showError).not.toHaveBeenCalled()
		const [content, options] = dialogs.showMessage.mock.calls[0]
		expect(options).toEqual({ type: 'toast-error' })
		expect(content.firstChild.textContent).toBe('That is under way already.')
		expect(content.querySelector('summary').textContent).toBe('Details')
		expect(content.querySelector('code').textContent).toBe('<b>an import</b> is running')
		expect(content.querySelector('b')).toBeNull()
	})

	it('is a plain toast when there is nothing behind the sentence', async () => {
		const { dialogs, showError } = await loadToast()

		await showError({ text: 'Could not save that', details: '' })
		await showError({ text: 'Could not save that', details: 'Could not save that' })
		await showError('Could not load the timeline', { timeout: 3000 })

		expect(dialogs.showMessage).not.toHaveBeenCalled()
		expect(dialogs.showError.mock.calls).toEqual([
			['Could not save that', undefined],
			['Could not save that', undefined],
			['Could not load the timeline', { timeout: 3000 }],
		])
	})
})

/**
 * The service with `@nextcloud/dialogs/toast` replaced, read fresh for each test.
 *
 * @return {Promise<{dialogs: object, showError: Function}>} the stand-in library, and the service's showError
 */
async function loadToast() {
	vi.resetModules()
	const dialogs = { showError: vi.fn(), showMessage: vi.fn(), ToastType: { ERROR: 'toast-error' } }
	vi.doMock('@nextcloud/dialogs/toast', () => dialogs)
	const { showError } = await import('../../../src/services/toast.js')

	return { dialogs, showError }
}

/**
 * @param {string} dir a directory
 * @return {string[]} every .js and .vue file under it that is not a test
 */
function sourceFiles(dir) {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const path = resolve(dir, entry.name)
		if (entry.isDirectory()) {
			return sourceFiles(path)
		}

		return /\.(js|vue)$/.test(entry.name) && !/\.test\.js$/.test(entry.name) ? [path] : []
	})
}
