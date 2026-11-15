/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (path) => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('one motion scale', () => {
	it('names three durations, one curve and one spring', () => {
		const motion = read('src/styles/motion.css')

		for (const token of ['--social-motion-fast', '--social-motion-base', '--social-motion-slow', '--social-ease', '--social-ease-spring']) {
			expect(motion).toMatch(new RegExp(`${token}:`))
		}
	})

	// a token that is not defined makes the whole `transition` declaration
	// invalid, and whatever used it simply stops moving
	it.each(['main', 'signup', 'profile', 'dashboard', 'ostatus', 'oauth', 'adminSettings'])('is loaded by the %s entry point', (entry) => {
		expect(read(`src/${entry}.js`)).toContain("import './styles/motion.css'")
	})

	it('moves the switcher glider on the spring', () => {
		expect(read('src/components/TimelineSwitcher.vue')).toMatch(/transition: transform var\(--social-motion-slow\) var\(--social-ease-spring\)/)
	})
})
