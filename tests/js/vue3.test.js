/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Guards against Vue 2 idioms drifting back into the source.
 *
 * Every pattern here was really in the tree and silently did nothing on Vue 3:
 * a `-enter` transition class that never matched (Vue 3 names it `-enter-from`),
 * `:prop.sync` on a component whose update event was therefore never handled.
 * The compiler does not complain about any of them, and a reviewer has to know
 * the Vue 2 spelling to notice, so they are asserted instead.
 */
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { SHORTCUTS } from '../../src/services/shortcuts.js'

// vitest runs from the project root; import.meta.url is not a file url here
const SRC = resolve(process.cwd(), 'src')

/** @return {string[]} every .vue and .js file under src/ */
function sourceFiles(dir = SRC, found = []) {
	for (const entry of readdirSync(dir)) {
		const path = join(dir, entry)
		if (statSync(path).isDirectory()) {
			sourceFiles(path, found)
		} else if (/\.(vue|js)$/.test(entry)) {
			found.push(path)
		}
	}
	return found
}

/** A comment mentioning prefers-reduced-motion is not a guard. */
/**
 * What a file styles: the <style> blocks of a component, or the whole of a
 * stylesheet. The motion checks are about CSS, and a script that names an
 * OAuth scope such as `transition:generic` is not a transition.
 *
 * @param {string} name the file's path
 * @param {string} content its text
 * @return {string}
 */
function stylesOf(name, content) {
	if (!name.endsWith('.vue')) {
		return content
	}
	return [...content.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map((match) => match[1]).join('\n')
}

function withoutComments(content) {
	return content
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/^\s*\/\/.*$/gm, '')
}

/**
 * Everything inside `@media (prefers-reduced-motion: reduce)` blocks, so the
 * check can ask what those blocks actually turn off rather than whether the
 * words appear somewhere in the file.
 *
 * @param {string} source a stylesheet or component, comments already stripped
 * @return {string} the guarded declarations, concatenated
 */
function reducedMotionBlocks(source) {
	let guarded = ''
	const opener = /@media[^{]*prefers-reduced-motion[^{]*\{/g
	let match
	while ((match = opener.exec(source)) !== null) {
		let depth = 1
		let i = match.index + match[0].length
		const start = i
		while (i < source.length && depth > 0) {
			if (source[i] === '{') {
				depth++
			} else if (source[i] === '}') {
				depth--
			}
			i++
		}
		guarded += source.slice(start, i)
	}

	return guarded
}

const STYLES = resolve(process.cwd(), 'css')
const shippedStyles = (existsSync(STYLES) ? readdirSync(STYLES) : [])
	.filter((entry) => entry.endsWith('.css') || entry.endsWith('.scss'))
	.map((entry) => ({ name: 'css/' + entry, content: readFileSync(join(STYLES, entry), 'utf8') }))

const files = sourceFiles().map((path) => ({
	name: relative(SRC, path),
	content: readFileSync(path, 'utf8'),
}))

/**
 * @param {RegExp} pattern what a Vue 2 leftover looks like
 * @return {string[]} `file:line` for every hit
 */
function hits(pattern) {
	return files.flatMap(({ name, content }) => content.split('\n')
		.map((line, index) => (pattern.test(line) ? `${name}:${index + 1}` : null))
		.filter(Boolean))
}

describe('the frontend is Vue 3, not Vue 2 with a Vue 3 runtime', () => {
	it('names transition classes the way Vue 3 does', () => {
		// `.list-enter` matched nothing on Vue 3, so the fade never ran
		expect(hits(/^\s*\.[\w-]+-enter\s*[,{]/)).toEqual([])
		expect(hits(/^\s*\.[\w-]+-leave\s*[,{]/)).toEqual([])
	})

	it('defines a -enter-from for every -enter-active', () => {
		for (const { name, content } of files) {
			const active = [...content.matchAll(/\.([\w-]+)-enter-active/g)].map((match) => match[1])
			for (const transition of new Set(active)) {
				expect(
					content.includes(`.${transition}-enter-from`),
					`${name}: .${transition}-enter-active has no .${transition}-enter-from, so entering is not animated`,
				).toBe(true)
			}
		}
	})

	it('uses v-model arguments instead of the .sync modifier', () => {
		expect(hits(/\.sync\s*=/)).toEqual([])
	})

	it('uses the Vue 3 lifecycle hook names', () => {
		expect(hits(/\bbeforeDestroy\s*\(|\bdestroyed\s*\(/)).toEqual([])
	})

	it('does not reach for the reactivity helpers Vue 3 dropped', () => {
		expect(hits(/this\.\$set\(|this\.\$delete\(|Vue\.set\(|Vue\.delete\(/)).toEqual([])
	})

	it('does not use the APIs Vue 3 removed', () => {
		expect(hits(/\$listeners|\$scopedSlots|\$children|\bnew Vue\(|Vue\.component\(/)).toEqual([])
	})

	it('uses named slots the Vue 3 way', () => {
		// slot="name" and slot-scope were replaced by v-slot
		expect(hits(/\sslot\s*=\s*"|slot-scope\s*=/)).toEqual([])
	})

	it('does not use event modifiers Vue 3 removed', () => {
		expect(hits(/@[\w:]+\.native|@key(?:up|down)\.\d+/)).toEqual([])
	})

	it('declares the events every component emits', () => {
		for (const { name, content } of files) {
			// `$emit(` is a component event; `eventBus.emit(` is the app-wide bus
			if (!name.endsWith('.vue') || !/\$emit\(/.test(content)) {
				continue
			}
			expect(
				/\n\temits: \[/.test(content),
				`${name}: emits events without declaring them, so they also land on the root element as attributes`,
			).toBe(true)
		}
	})

	it('guards its animations behind prefers-reduced-motion', () => {
		// Counted, not merely looked for. The old check asked whether the string
		// "prefers-reduced-motion" appeared anywhere in the file, which one
		// guard, one comment or one variable name satisfied for a file with a
		// dozen animations.
		const offenders = []
		for (const { name, content } of files) {
			const source = withoutComments(stylesOf(name, content))
			const moves = [...source.matchAll(/(?:transition|animation|transition-property|animation-name|scroll-behavior)\s*:\s*(?!none|auto|initial|unset)/g)].length
			if (moves === 0) {
				continue
			}

			// how much of the file the reduced-motion blocks actually cover
			const guarded = reducedMotionBlocks(source)
			const movesGuarded = [...guarded.matchAll(/(?:transition|animation|transition-property|animation-name|scroll-behavior)\s*:/g)].length

			if (guarded === '') {
				offenders.push(`${name}: ${moves} animated properties, no prefers-reduced-motion block`)
			} else if (movesGuarded === 0) {
				offenders.push(`${name}: has a prefers-reduced-motion block that turns nothing off`)
			}
		}

		expect(offenders).toEqual([])
	})

	it('scans the stylesheets that ship outside src/ as well', () => {
		// the scanner roots at src/, so anything in css/ was never looked at
		for (const { name, content } of shippedStyles) {
			const source = withoutComments(content)
			if (!/(?:transition|animation)\s*:\s*(?!none|auto|initial|unset)/.test(source)) {
				continue
			}
			expect(
				source.includes('prefers-reduced-motion'),
				`${name}: animates without a prefers-reduced-motion escape hatch`,
			).toBe(true)
		}
	})

	it('shows no message to a user that a translator never saw', () => {
		// showError('…') puts English in front of everyone; the string has to
		// go through t()/translate() to be extracted and translated at all
		const offenders = []
		for (const { name, content } of files) {
			for (const [, call, argument] of content.matchAll(/\b(showError|showSuccess|showWarning|showInfo)\(\s*([^\n]*)/g)) {
				// a variable or a helper is fine — it is the literal that is not
				if (/^['"`]/.test(argument.trim())) {
					offenders.push(`${name}: ${call}(${argument.trim().slice(0, 48)}`)
				}
			}
		}

		expect(offenders).toEqual([])
	})

	it('has somebody listening for every shortcut it advertises', () => {
		// a key in the help sheet that nothing answers is a promise to a user
		// who has no way of discovering it was never kept
		const advertised = SHORTCUTS.map(({ event }) => event)
		const listened = files
			.flatMap(({ content }) => [...content.matchAll(/eventBus\.on\(\s*'(shortcut:[a-z]+)'/g)])
			.map(([, event]) => event)

		expect(advertised.filter((event) => !listened.includes(event))).toEqual([])
	})

	it('does not put a click handler on something nobody can focus', () => {
		// a div that reacts to a click is invisible to the keyboard and to a
		// screen reader; the fix is a <button>, or a role plus tabindex plus a
		// key handler if it truly cannot be one
		const offenders = []
		for (const { name, content } of files) {
			for (const [tag] of content.matchAll(/<(?:div|span|li|img|p)\b[^>]*@click[^>]*>/g)) {
				const excused = /\brole=|\btabindex=|@keydown|@keyup|aria-hidden="true"/.test(tag)
				if (!excused) {
					offenders.push(`${name}: ${tag.replace(/\s+/g, ' ').slice(0, 70)}`)
				}
			}
		}

		expect(offenders).toEqual([])
	})

	it('suppresses no focus outline without putting one back', () => {
		for (const { name, content } of files) {
			// :focus-visible with an outline of its own is the replacement; a
			// bare `outline: none` leaves a keyboard user with no idea where
			// they are
			expect(/outline:\s*none/.test(content), `${name}: removes a focus outline`).toBe(false)
		}
	})
})

describe('cards agree on how far off the page they sit', () => {
	const app = files.find(({ name }) => name === 'App.vue').content

	it('defines the two elevations once', () => {
		expect(app).toContain('--social-elevation-resting:')
		expect(app).toContain('--social-elevation-raised:')
	})

	it('thins Nextcloud\'s shadow colour instead of using it raw', () => {
		// --color-box-shadow is built for modals: half-opaque grey in the light
		// theme and solid black in the dark one. Straight under a timeline it
		// puts a slab beneath every post.
		const raw = app.match(/--social-elevation-[\w-]+:[^;]+;/g) ?? []
		expect(raw.length).toBe(2)
		for (const declaration of raw) {
			expect(declaration).toContain('color-mix(in srgb, var(--color-box-shadow)')
		}
	})

	it('gives every card the shared elevation rather than a shadow of its own', () => {
		// one hand-rolled rgba here and the timeline stops looking like one surface
		const offenders = files
			.filter(({ name }) => name !== 'App.vue')
			.flatMap(({ name, content }) => content.split('\n')
				.map((line, index) => (
					/box-shadow:\s*[^;]*\b\d+px[^;]*\b(rgba?|hsla?)\(/.test(line) ? `${name}:${index + 1}` : null
				))
				.filter(Boolean))

		expect(offenders).toEqual([])
	})

	/**
	 * Movement is a choice the reader has already made in their system
	 * settings, and a component that animates without reading it overrules
	 * them. Every file that animates anything answers it in the same file.
	 */
	it('lets a reader turn off whatever moves', () => {
		const offenders = files
			.filter(({ name, content }) => /@keyframes|animation:|transition:/.test(stylesOf(name, content)))
			.filter(({ content }) => !content.includes('prefers-reduced-motion'))
			.map(({ name }) => name)

		expect(offenders).toEqual([])
	})

	it('raises a card on hover rather than only bordering it', () => {
		const post = files.find(({ name }) => name === 'components/TimelinePost.vue').content

		expect(post).toContain('box-shadow: var(--social-elevation-resting)')
		expect(post).toContain('box-shadow: var(--social-elevation-raised)')
	})
})
