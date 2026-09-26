/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * The three little games a post can play: `/dice`, `/flip` and `/pick`.
 *
 * Each is replaced by its result before the post is sent, so what travels is
 * plain text -- "🎲 4", "🪙 heads", "🎯 pizza" -- and a Mastodon reader sees
 * exactly what a reader here sees. Nothing is rolled on the server and nothing
 * can be re-rolled afterwards: the result is part of what was posted.
 *
 * A command is only a command at the start of a line or after a space, so a
 * link that happens to contain `/dice` is left alone.
 */

/** the most sides a die may have: enough for any game, too few to be a lottery */
export const MAX_SIDES = 1000

/** the most options `/pick` takes */
export const MAX_OPTIONS = 20

/**
 * Where each command may start and what it takes.
 *
 * A die takes an optional number of sides; a coin takes nothing. Only `/pick`
 * runs to the end of its line, since its options are the rest of it -- so
 * `/flip then /dice` is two games, and neither swallows the other.
 *
 * Groups: 1 what came before, 2 dice|roll, 3 its sides, 4 flip, 5 pick, 6 its options.
 */
const COMMAND = /(^|\s)\/(?:(dice|roll)(\s+d?\d{1,4}\b)?|(flip)|(pick)([^\n]*))(?![\w/])/gi

/**
 * @param {number} sides how many sides
 * @param {() => number} random a source of numbers in [0, 1)
 * @return {number} a face, 1 to sides
 */
function rollDie(sides, random) {
	return Math.floor(random() * sides) + 1
}

/**
 * @param {string|undefined} argument what followed `/dice`, like " 20" or " d20"
 * @return {number} how many sides the die has
 */
function sidesOf(argument) {
	const digits = /\d+/.exec(argument ?? '')
	if (digits === null) {
		return 6
	}

	return Math.min(MAX_SIDES, Math.max(2, Number(digits[0])))
}

/**
 * @param {string} rest what came after `/pick` on the line
 * @return {string[]} the options, split on commas, or on " or " when there are none
 */
export function parseOptions(rest) {
	const line = rest.trim()
	if (line === '') {
		return []
	}

	const parts = line.includes(',') ? line.split(',') : line.split(/\s+or\s+/i)

	return parts
		.map((part) => part.trim())
		.filter((part) => part !== '')
		.slice(0, MAX_OPTIONS)
}

/**
 * What a post says once its commands have been played.
 *
 * @param {string} text the post as typed
 * @param {() => number} [random] a source of numbers in [0, 1); injected so a
 *                                test can know what the dice will say
 * @return {{ text: string, results: Array<{ kind: string, result: string, options?: string[], sides?: number }> }}
 *         the text to send, and what was played in it, in order
 */
export function resolveCommands(text, random = Math.random) {
	/** @type {Array<{ kind: string, result: string, options?: string[], sides?: number }>} */
	const results = []

	const resolved = String(text ?? '').replace(COMMAND, (whole, lead, dice, argument, flip, pick, rest) => {
		if (dice !== undefined) {
			const sides = sidesOf(argument)
			const face = String(rollDie(sides, random))
			results.push({ kind: 'dice', result: face, sides })

			return lead + (sides === 6 ? `🎲 ${face}` : `🎲 ${face} (d${sides})`)
		}

		if (flip !== undefined) {
			const result = random() < 0.5 ? t('social', 'heads') : t('social', 'tails')
			results.push({ kind: 'flip', result })

			return `${lead}🪙 ${result}`
		}

		// pick: the options are the rest of the line
		const options = parseOptions(rest)
		if (options.length < 2) {
			// nothing to choose between is not a game; leave it as typed
			return whole
		}

		const choice = options[Math.floor(random() * options.length)]
		results.push({ kind: 'pick', result: choice, options })

		return `${lead}🎯 ${t('social', '{choice} (out of {options})', { choice, options: options.join(', ') })}`
	})

	return { text: resolved, results }
}

/**
 * Whether a post has anything to play, for the hint under the box.
 *
 * @param {string} text the post as typed
 * @return {string[]} the kinds found, in order, without repeats
 */
export function commandsIn(text) {
	const kinds = []
	for (const match of String(text ?? '').matchAll(COMMAND)) {
		const kind = match[2] !== undefined ? 'dice' : (match[4] !== undefined ? 'flip' : 'pick')
		if (kind === 'pick' && parseOptions(match[6]).length < 2) {
			continue
		}
		if (!kinds.includes(kind)) {
			kinds.push(kind)
		}
	}

	return kinds
}

/**
 * A stand-in result while the dice are in the air.
 *
 * @param {{ kind: string, options?: string[], sides?: number }} played what is being played
 * @param {() => number} [random] a source of numbers in [0, 1)
 * @return {string} something it could land on
 */
export function tumble(played, random = Math.random) {
	if (played.kind === 'dice') {
		return String(rollDie(played.sides ?? 6, random))
	}
	if (played.kind === 'flip') {
		return random() < 0.5 ? t('social', 'heads') : t('social', 'tails')
	}

	const options = played.options ?? []

	return options[Math.floor(random() * options.length)] ?? ''
}
