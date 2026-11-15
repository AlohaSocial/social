/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { compileString } from 'sass'
import { describe, expect, it } from 'vitest'

const read = (path) => readFileSync(resolve(process.cwd(), path), 'utf8')

/** @param {string} source a single-file component */
const styleOf = (source) => source.slice(source.indexOf('<style'))

/**
 * A link preview, a quoted post and an event are each something a post
 * carries inside it, and they share one frame rather than drawing three.
 */
describe('the embed frame', () => {
	it.each([
		['src/components/PostCard.vue', '.post-card'],
		['src/components/QuotedPost.vue', '.quoted-post'],
		['src/components/TimelineEventCard.vue', '.post-event'],
	])('frames %s with the shared mixin', (path, selector) => {
		const style = styleOf(read(path))

		expect(style).toContain('@use \'../styles/embed.scss\' as embed;')
		expect(style).toMatch(new RegExp(`\\${selector} \\{\\s*@include embed\\.frame`))
		// the frame owns its border, corners and surface; nobody redraws them
		const block = style.slice(style.indexOf(`${selector} {`))
		const own = block.slice(0, block.indexOf('\n}'))
		expect(own).not.toMatch(/^\tborder(-radius)?:/m)
		expect(own).not.toMatch(/^\tbackground:/m)
	})

	it('has a hover, a focus state and no motion for a reader who asked for none', () => {
		const { css } = compileString('@use "src/styles/embed.scss" as embed; .card { @include embed.frame; }', { loadPaths: [process.cwd()] })

		expect(css).toContain('.card:hover')
		expect(css).toMatch(/\.card:focus-visible, \.card:focus-within/)
		expect(css).toMatch(/@media \(prefers-reduced-motion: reduce\) \{\s*\.card \{\s*transition: none;/)
	})
})
