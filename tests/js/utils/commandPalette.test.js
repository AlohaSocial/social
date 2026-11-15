/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { matchScore, rankCommands } from '../../../src/utils/commandPalette.js'

describe('matchScore', () => {
	it('ranks the start of a name over a word in it over the middle of one', () => {
		expect(matchScore('book', 'Bookmarks')).toBe(4)
		expect(matchScore('feed', 'My Feed')).toBe(3)
		expect(matchScore('mark', 'Bookmarks')).toBe(2)
	})

	it('finds the letters of a name in order, and nothing out of order', () => {
		expect(matchScore('bkm', 'Bookmarks')).toBe(1)
		expect(matchScore('mkb', 'Bookmarks')).toBe(0)
		expect(matchScore('xyz', 'Bookmarks')).toBe(0)
	})

	it('ignores case, accents and extra spaces', () => {
		expect(matchScore('EINSTELL', 'Einstellungen')).toBe(4)
		expect(matchScore('ecole', 'École')).toBe(4)
		expect(matchScore('  my   feed ', 'My Feed')).toBe(4)
	})

	it('names everything with an empty query', () => {
		expect(matchScore('', 'Anything')).toBe(1)
	})
})

describe('rankCommands', () => {
	const commands = [
		{ title: 'My Feed' },
		{ title: 'Bookmarks' },
		{ title: 'Settings' },
		{ title: 'Lists', hint: 'Settings' },
		{ title: 'Liked posts' },
	]

	it('keeps the order it was given for an empty query', () => {
		expect(rankCommands('', commands)).toEqual(commands)
	})

	it('puts the best match first and drops the rest', () => {
		expect(rankCommands('li', commands).map(({ title }) => title)).toEqual(['Lists', 'Liked posts'])
	})

	it('finds a command by its hint, after the command named that', () => {
		expect(rankCommands('settings', commands).map(({ title }) => title)).toEqual(['Settings', 'Lists'])
	})
})
