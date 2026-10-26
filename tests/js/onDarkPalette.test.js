/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * The surfaces drawn over video and on a brand tint keep their own on-dark
 * palette whatever the theme. It is one set of custom properties, defined
 * once, so a theme or a reader can reach all four components through it;
 * and the three Shorts surfaces answer a forced-colours (high contrast)
 * mode, which replaces every colour and takes the scrims away.
 */
const read = (path) => readFileSync(resolve(process.cwd(), path), 'utf8')
const STYLE_SOURCES = {
	'src/views/VideoShorts.vue': 'src/views/VideoShorts.scss',
}
const styleOf = (path) => STYLE_SOURCES[path]
	? read(STYLE_SOURCES[path])
	: read(path).slice(read(path).indexOf('<style'))

const ON_DARK = [
	'src/views/VideoShorts.vue',
	'src/components/ShortComposerDialog.vue',
	'src/components/DayShortPanel.vue',
	'src/views/SwitchWizard.vue',
]
const SHORTS = ON_DARK.slice(0, 3)

const defined = () => new Set(read('src/App.vue').match(/--social-on-dark-[a-z-]+(?=:)/g))
const used = (path) => new Set(styleOf(path).match(/--social-on-dark-[a-z-]+/g) ?? [])

describe('the on-dark palette', () => {
	it.each(ON_DARK)('%s writes no colour of its own, only the palette', (path) => {
		// a fallback inside var() is a Nextcloud colour's, not a colour of this app's
		const bare = styleOf(path).replace(/var\([^)]*\)/g, '')

		expect(bare.match(/#[0-9a-fA-F]{3,8}\b|rgba?\(/g)).toBeNull()
		expect(used(path).size).toBeGreaterThan(0)
	})

	it.each(ON_DARK)('%s reads only properties the palette defines', (path) => {
		const missing = [...used(path)].filter((name) => !defined().has(name))

		expect(missing).toEqual([])
	})

	it('is defined once, on the root, in App.vue', () => {
		expect(defined().size).toBeGreaterThan(0)
		expect(read('src/App.vue').match(/:root\s*\{[^}]*--social-on-dark-ground/)).not.toBeNull()
		for (const path of ON_DARK) {
			expect(read(path)).not.toMatch(/--social-on-dark-[a-z-]+:/)
		}
	})

	it.each(SHORTS)('%s answers a forced-colours mode with system colours', (path) => {
		const style = styleOf(path)
		const block = style.slice(style.indexOf('@media (forced-colors: active)'))

		expect(block.length).toBeGreaterThan(0)
		expect(block).toMatch(/\bCanvas\b|\bButtonFace\b/)
		expect(block).toMatch(/\bCanvasText\b|\bButtonText\b/)
	})
})
