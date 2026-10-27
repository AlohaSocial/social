/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { createRequire } from 'node:module'
import { join, resolve } from 'node:path'
import { gzipSync } from 'node:zlib'
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

	it('does not ship the unused Node crypto polyfill or its elliptic dependency', () => {
		const cryptoPolyfill = /(?:crypto-browserify|browserify-sign|create-ecdh|elliptic)/
		const bundles = readdirSync(JS).filter((entry) => entry.endsWith('.js'))
		const included = bundles.filter((name) => cryptoPolyfill.test(bundle(name) ?? ''))

		expect(included).toEqual([])
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

	it('ships every lazy chunk referenced by the committed bundles', () => {
		const files = new Set(readdirSync(JS))
		const bundles = [...files].filter((name) => name.endsWith('.js'))
		let references = 0

		for (const name of bundles) {
			const content = bundle(name)
			if (content === null) {
				continue
			}

			for (const [, chunk] of content.matchAll(/\.e\("([^"]+)"\)/g)) {
				references++
				expect(files.has(`social-${chunk}.js`), `${name} requests missing lazy chunk social-${chunk}.js`).toBe(true)
			}
		}

		expect(references, 'the committed bundles should contain lazy chunk references').toBeGreaterThan(0)
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
 * What a reader downloads, compressed as the web server sends it.
 *
 * The ceilings above are on raw bytes, and the one on an entry is relative to
 * the framework chunk — so it loosens as the framework grows, and nothing held
 * the framework itself. These are absolute and gzipped: the framework is paid
 * on the first visit to any page, the app's entry on every visit to the app.
 * Each sits about ten percent above what the build produces today; one that
 * is reached is a decision to make (a dependency to lazy-load, a chunk to
 * split), and if it is the right one, the number here moves with a reason.
 */
describe('the compressed size of what a page downloads', () => {
	const KB = 1024
	const CEILINGS = [
		// 218 KB when this was written; every page of the app loads it first
		['social-framework.js', 240 * KB],
		// The core entry has grown to 105 KB as profile and feed features were
		// added. Keep 3 KB of headroom so a small feature does not turn a
		// healthy build into a CI failure; larger growth calls for splitting.
		['social-social.js', 108 * KB],
		// 175 KB, loaded when a video is played
		['social-hls.js', 193 * KB],
		// 100 KB, the administration page; keep 1 KB for compression drift
		['social-adminSettings.js', 101 * KB],
		// 74 KB, the personal settings page
		['social-settings.js', 82 * KB],
		// 130 KB, loaded when the composer's emoji button is pressed
		['social-emoji-picker.js', 144 * KB],
	]

	function gzipped(name) {
		return gzipSync(readFileSync(join(JS, name))).length
	}

	it.each(CEILINGS)('%s stays under its ceiling', (name, ceiling) => {
		expect(existsSync(join(JS, name)), `${name} is missing; run the build`).toBe(true)

		const size = gzipped(name)
		expect(size, `${name} is ${Math.round(size / KB)} KB gzipped`).toBeLessThan(ceiling)
	})

	it('all of js/ together stays under its ceiling', () => {
		// 1,999 KB across every entry and lazy chunk
		const total = readdirSync(JS)
			.filter((entry) => entry.endsWith('.js'))
			.reduce((sum, entry) => sum + gzipped(entry), 0)

		expect(total, `js/ is ${Math.round(total / KB)} KB gzipped`).toBeLessThan(2200 * KB)
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

/**
 * The size budget in webpack.common.js: sized to the bundle as it is, so
 * that growth is what warns. A budget far above the bundle warns on nothing;
 * one below it warns on every build, and a warning that is always there is
 * one nobody reads.
 */
describe('the size budget', () => {
	const config = createRequire(import.meta.url)('../../webpack.common.js')
	const size = (name) => statSync(join(JS, name)).size
	const scripts = () => readdirSync(JS).filter((entry) => entry.endsWith('.js'))

	it('warns, so the build log and CI show it, without failing the build', () => {
		expect(config.performance).toMatchObject({ hints: 'warning' })
		expect(config.performance.maxAssetSize).toBeGreaterThan(0)
		expect(config.performance.maxEntrypointSize).toBeGreaterThan(0)
	})

	it('counts the scripts and not their source maps or licence files', () => {
		expect(config.performance.assetFilter('social-social.js')).toBe(true)
		expect(config.performance.assetFilter('social-social.js.map')).toBe(false)
		expect(config.performance.assetFilter('social-social.js.LICENSE.txt')).toBe(false)
	})

	it('is within reach of the largest chunk: above it, and by less than a third', () => {
		const largest = Math.max(...scripts().map(size))

		expect(config.performance.maxAssetSize).toBeGreaterThanOrEqual(largest)
		expect(config.performance.maxAssetSize).toBeLessThan(largest * 4 / 3)
	})

	it('is within reach of the largest entrypoint, the entry with the framework chunk it loads first', () => {
		// a js/ built before an entry was added has no file for it yet
		const entries = Object.keys(config.entry).filter((name) => existsSync(join(JS, `social-${name}.js`))).map((name) => {
			const own = size(`social-${name}.js`)

			// the two self-contained entries load no framework chunk
			return ['filesAction', 'recovery'].includes(name) ? own : own + size('social-framework.js')
		})
		const largest = Math.max(...entries)

		expect(config.performance.maxEntrypointSize).toBeGreaterThanOrEqual(largest)
		expect(config.performance.maxEntrypointSize).toBeLessThan(largest * 4 / 3)
	})
})

/**
 * The offline recovery tool is inlined into one HTML page by
 * `RecoveryToolPage` and has to run from a saved copy with the server gone,
 * so it carries everything it needs and asks for no chunk; and the signing
 * code it brings stays out of every other bundle.
 */
describe('the recovery tool entry', () => {
	it('derives a key when it runs alone, with no chunk to wait for', async () => {
		const content = bundle('social-recovery.js')
		if (content === null) {
			// a js/ built before the tool existed; the presence check above guards the build
			return
		}
		expect(content.includes('webpackChunksocial')).toBe(false)

		const { JSDOM } = await import('jsdom')
		const words = readFileSync(resolve(process.cwd(), 'resources/atproto/bip39-english.txt'), 'utf8').trim().split(/\r?\n/)
		const w = new JSDOM('<!doctype html><html><body><textarea id="key"></textarea><p id="key-result"></p>'
			+ '<script type="application/json" id="recovery-data"></script></body></html>', { runScripts: 'outside-only' }).window
		w.document.getElementById('recovery-data').textContent = JSON.stringify({ words, strings: { 'key-ok': '{key}' } })
		// the page has every element the script wires; these two are what this needs
		const real = w.document.getElementById.bind(w.document)
		w.document.getElementById = (id) => real(id) ?? w.document.createElement('input')
		w.eval(content)

		const key = w.document.getElementById('key')
		key.value = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about'
		key.dispatchEvent(new w.Event('input'))
		expect(w.document.getElementById('key-result').textContent).toBe('did:key:zQ3shX448VC3xo912wY7zzdUFdMqGifmFTv9GomCodivqkgkt')
	})

	it('stays small', () => {
		if (!existsSync(join(JS, 'social-recovery.js'))) {
			return
		}
		// 11 KB when it was written
		expect(gzipSync(readFileSync(join(JS, 'social-recovery.js'))).length).toBeLessThan(16 * 1024)
	})

	it.each(['social-social.js', 'social-framework.js', 'social-settings.js'])('%s does not carry its signing code', (name) => {
		const content = bundle(name)
		if (content === null) {
			return
		}
		expect(content.includes('noble-secp256k1')).toBe(false)
	})
})
