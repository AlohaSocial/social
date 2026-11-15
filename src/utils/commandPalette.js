/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { folded } from '../services/settingsSearch.js'

/**
 * How well a few typed letters name a text: 4 when the text starts with
 * them, 3 when a word in it does, 2 when they are somewhere inside it, 1
 * when they are only its letters in order ("bkm" for Bookmarks), 0 when
 * they are not in it at all. Case and accents do not count.
 *
 * @param {string} query what the reader typed
 * @param {string} text a command's name
 * @return {number} 0 to 4; an empty query names everything, at 1
 */
export function matchScore(query, text) {
	const needle = folded(query).trim().replace(/\s+/g, ' ')
	const haystack = folded(text)
	if (needle === '') {
		return 1
	}

	const at = haystack.indexOf(needle)
	if (at === 0) {
		return 4
	}
	if (at > 0) {
		return /[\s\-_/@#.]/.test(haystack[at - 1]) ? 3 : 2
	}

	let from = 0
	for (const char of needle.replace(/ /g, '')) {
		const found = haystack.indexOf(char, from)
		if (found === -1) {
			return 0
		}
		from = found + 1
	}

	return 1
}

/**
 * The commands that match, best first, in their own order where they match
 * equally well. A command matches by its title, or a step lower by its hint
 * (typing "settings" lists the settings sections after Settings itself).
 *
 * @template {{title: string, hint?: string}} T
 * @param {string} query what the reader typed
 * @param {T[]} commands everything there is
 * @return {T[]}
 */
export function rankCommands(query, commands) {
	return commands
		.map((command, order) => ({
			command,
			order,
			score: Math.max(matchScore(query, command.title), command.hint ? matchScore(query, command.hint) - 1 : 0),
		}))
		.filter(({ score }) => score > 0)
		.sort((a, b) => b.score - a.score || a.order - b.order)
		.map(({ command }) => command)
}
