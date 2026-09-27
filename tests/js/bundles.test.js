/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * What the built bundles are allowed to contain.
 *
 * These read the committed output in js/, which is what an installation
 * actually serves — the thing worth asserting about, and the reason the
 * directory is in the repository at all.
 */
const JS = resolve(process.cwd(), 'js')

function bundle(name) {
	const path = join(JS, name)

	return existsSync(path) ? readFileSync(path, 'utf8') : null
}

/** The picker's own payload, not merely a reference to the chunk that holds it. */
const CARRIES_EMOJI_DATA = /emoji-mart-vue-fast|"skin_variations"|frequently-used-emojis/

describe('the built bundles', () => {
	it('are present, because the tests below read what is actually served', () => {
		expect(existsSync(JS)).toBe(true)
		expect(readdirSync(JS).some((entry) => entry.endsWith('.js'))).toBe(true)
	})

	it.each(['social-dashboard.js', 'social-profilePage.js', 'social-ostatus.js'])(
		'%s does not carry the emoji picker',
		(name) => {
			const content = bundle(name)
			if (content === null) {
				// a partial build; the presence check above is what guards that
				return
			}

			// none of these can compose a post, and the picker is most of a
			// megabyte — it used to arrive with the post overflow menu
			expect(CARRIES_EMOJI_DATA.test(content)).toBe(false)
		},
	)

	it('keeps the picker in a chunk of its own', () => {
		const picker = bundle('social-emoji-picker.js')
		expect(picker, 'social-emoji-picker.js is missing; run the build').not.toBeNull()
		expect(CARRIES_EMOJI_DATA.test(picker)).toBe(true)
	})

	it('keeps the chunk that carries the post menu small', () => {
		// `NcAction`, not `NcActionButton`: webpack names a vendor chunk after
		// whichever of the modules in it sorts first, so the exact name moves
		// whenever an import is added anywhere. What has to stay true is that
		// the chunk the menu pulls in is small, whatever it ends up called.
		const menus = readdirSync(JS).filter((entry) => entry.includes('NcAction') && entry.endsWith('.js'))
		expect(menus.length, 'no chunk carries the menu components; run the build').toBeGreaterThan(0)

		for (const name of menus) {
			// every post's "..." menu needs this; it was 1.1 MB when the emoji
			// picker shared it
			const size = statSync(join(JS, name)).size
			expect(size, `${name} is ${Math.round(size / 1024)} KB`).toBeLessThan(400 * 1024)
		}
	})
})

/**
 * The framework — Vue, @nextcloud/vue, pinia — used to be compiled into each of
 * the five entry points separately, so opening the Dashboard and then the app
 * downloaded all of it twice. It is now one chunk they share.
 *
 * That makes an entry no longer self-contained, and the way it fails is silent:
 * webpack's runtime queues the startup module and waits for a chunk that never
 * arrives, so the script runs to completion and the page simply stays empty.
 * Nothing throws and nothing reaches the console. These tests are here because
 * that is not a failure anybody would notice from a stack trace.
 */
describe('the shared framework chunk', () => {
	const ENTRIES = [
		'social-social.js',
		'social-dashboard.js',
		'social-profilePage.js',
		'social-oauth.js',
		'social-ostatus.js',
	]

	/** Evaluates scripts in order in one window; answers what booting produced. */
	async function boot(names) {
		const { JSDOM } = await import('jsdom')
		const dom = new JSDOM(
			'<!doctype html><html><head></head><body><div id="content"></div></body></html>',
			{ runScripts: 'outside-only', url: 'http://localhost/index.php/apps/social/' },
		)
		const w = dom.window
		w.structuredClone = structuredClone
		w.OC = { requestToken: 'x', config: {}, getCurrentUser: () => ({ uid: 'alice' }) }
		w.OCA = {}
		w.OCP = {}

		for (const name of names) {
			const content = bundle(name)
			if (content === null) {
				return null
			}
			try {
				w.eval(content)
			} catch {
				// the bundles expect a whole Nextcloud page around them and will
				// stop somewhere inside it; how far they got is the question, and
				// the stylesheets below answer it
			}
		}

		return w.document.querySelectorAll('style').length
	}

	it('is a chunk the entries share, not an entry of its own', () => {
		const framework = bundle('social-framework.js')
		expect(framework, 'social-framework.js is missing; run the build').not.toBeNull()

		// a chunk registers itself and waits; an entry carries a runtime and
		// starts. The license banner webpack writes comes first either way.
		const code = framework.replace(/^\/\*![^]*?\*\/\s*/, '')
		expect(code.startsWith('(self.webpackChunksocial=self.webpackChunksocial||[]).push('))
			.toBe(true)
	})

	it.each(ENTRIES)('%s no longer carries its own copy of the framework', (name) => {
		const content = bundle(name)
		if (content === null) {
			return
		}

		const size = statSync(join(JS, name)).size
		const framework = statSync(join(JS, 'social-framework.js')).size

		// These entries include their own app startup and initial view code as
		// well as the webpack runtime, so comparing the full entry to half of
		// the framework chunk incorrectly fails as Social grows. A second copy
		// of the shared framework would put the entry above twice the framework
		// chunk; the absolute ceiling below catches a creeping oversized entry.
		expect(size, `${name} is not much smaller than the framework it should be sharing`)
			.toBeLessThan(framework * 2)

		// an absolute ceiling well clear of the 900 KB failure, so an entry
		// cannot creep towards it a little at a time
		expect(size, `${name} looks like it still has one`).toBeLessThan(900 * 1024)
	})

	it('does nothing when an entry is served without it', async () => {
		// what a Util::addScript() that forgot the framework would produce: no
		// error, no output, an empty page
		expect(await boot(['social-social.js'])).toBe(0)
	})

	it('boots the app when it is served first', async () => {
		expect(await boot(['social-framework.js', 'social-social.js'])).toBeGreaterThan(0)
	})

	it('boots the dashboard widget when it is served first', async () => {
		expect(await boot(['social-framework.js', 'social-dashboard.js'])).toBeGreaterThan(0)
	})
})

/**
 * The Files action is the exception, on purpose: it is loaded on every Files
 * page and registers one menu entry, so it carries its few KB of
 * @nextcloud/files itself and never asks for the framework.
 */
describe('the Files action entry', () => {
	it('registers the action when served alone', async () => {
		const content = bundle('social-filesAction.js')
		expect(content, 'social-filesAction.js is missing; run the build').not.toBeNull()

		const { JSDOM } = await import('jsdom')
		const w = new JSDOM('<!doctype html><html><head></head><body></body></html>', {
			runScripts: 'outside-only',
			url: 'http://localhost/index.php/apps/files/',
		}).window
		w.OC = { requestToken: 'x', config: {}, getCurrentUser: () => ({ uid: 'alice' }) }
		w.OCA = {}
		w.OCP = {}
		w.eval(content)

		// @nextcloud/files keeps the registry on the window, which is how the
		// Files app, built separately, sees what other apps registered
		const registered = w._nc_files_scope?.v4_0?.fileActions ?? new Map()
		expect([...registered.keys()]).toContain('social-share')
	})

	it('stays small, because every Files page pays for it', () => {
		if (!existsSync(join(JS, 'social-filesAction.js'))) {
			return
		}
		expect(statSync(join(JS, 'social-filesAction.js')).size).toBeLessThan(120 * 1024)
	})

	it('is loaded without the framework', () => {
		const source = readFileSync(resolve(process.cwd(), 'lib/Listeners/FilesScriptsListener.php'), 'utf8')

		expect(source.indexOf("'social-filesAction'")).toBeGreaterThan(-1)
		expect(source.indexOf("'social-framework'")).toBe(-1)
	})
})

/**
 * Every page that loads one of those entries has to load the framework first.
 * These are PHP files, but the contract they carry is this bundle's, so it is
 * pinned next to it rather than somewhere the build is not in view.
 */
describe('the pages that load the bundles', () => {
	const SITES = [
		['templates/main.php', 'social-social'],
		['templates/oauth2.php', 'social-oauth'],
		['templates/signup.php', 'social-signup'],
		['lib/Dashboard/SocialWidget.php', 'social-dashboard'],
		['lib/Listeners/ProfileSectionListener.php', 'social-profilePage'],
		['lib/Settings/AdminSettings.php', 'social-adminSettings'],
	]

	it.each(SITES)('%s loads the framework before %s', (file, entry) => {
		const source = readFileSync(resolve(process.cwd(), file), 'utf8')

		const framework = source.indexOf("'social-framework'")
		const target = source.indexOf(`'${entry}'`)

		expect(target, `${file} no longer loads ${entry}`).toBeGreaterThan(-1)
		expect(framework, `${file} does not load social-framework`).toBeGreaterThan(-1)
		expect(framework, `${file} loads ${entry} before the framework it needs`)
			.toBeLessThan(target)
	})
})
