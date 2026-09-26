/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { commandsIn, MAX_OPTIONS, MAX_SIDES, parseOptions, resolveCommands, tumble } from '../../../src/utils/composerCommands.js'

/** a source of numbers that says the same thing every time */
const always = (value) => () => value

describe('resolveCommands', () => {
	it('rolls a six-sided die and says so in plain text', () => {
		expect(resolveCommands('Who buys lunch? /dice', always(0.5))).toEqual({
			text: 'Who buys lunch? 🎲 4',
			results: [{ kind: 'dice', result: '4', sides: 6 }],
		})
	})

	it('rolls a die with as many sides as it is given, and names the die', () => {
		expect(resolveCommands('/dice 20', always(0.999)).text).toBe('🎲 20 (d20)')
		expect(resolveCommands('/dice d100', always(0)).text).toBe('🎲 1 (d100)')
	})

	it('takes /roll as another name for /dice', () => {
		expect(resolveCommands('/roll', always(0)).text).toBe('🎲 1')
	})

	it('keeps a die between two and the most sides allowed', () => {
		expect(resolveCommands('/dice 1', always(0.99)).results[0].sides).toBe(2)
		expect(resolveCommands('/dice 9999', always(0)).results[0].sides).toBe(MAX_SIDES)
	})

	it('flips a coin either way', () => {
		expect(resolveCommands('/flip', always(0.1)).text).toBe('🪙 heads')
		expect(resolveCommands('/flip', always(0.9)).text).toBe('🪙 tails')
	})

	it('picks one of the options on the rest of the line', () => {
		const { text, results } = resolveCommands('Dinner:\n/pick pizza, pasta, sushi\nsee you', always(0.5))

		expect(text).toBe('Dinner:\n🎯 pasta (out of pizza, pasta, sushi)\nsee you')
		expect(results).toEqual([{ kind: 'pick', result: 'pasta', options: ['pizza', 'pasta', 'sushi'] }])
	})

	it('picks between options joined by "or" when there are no commas', () => {
		expect(resolveCommands('/pick tea or coffee', always(0.9)).results[0].result).toBe('coffee')
	})

	it('leaves /pick alone when there is nothing to choose between', () => {
		expect(resolveCommands('/pick pizza', always(0))).toEqual({ text: '/pick pizza', results: [] })
		expect(resolveCommands('/pick', always(0))).toEqual({ text: '/pick', results: [] })
	})

	it('plays every command in a post, in order', () => {
		const { results } = resolveCommands('/flip then /dice', always(0))

		expect(results.map((played) => played.kind)).toEqual(['flip', 'dice'])
	})

	/** a link is not a command because it happens to contain one */
	it('leaves a command inside a word or a link alone', () => {
		const text = 'see https://example.org/dice and foo/flip'

		expect(resolveCommands(text, always(0))).toEqual({ text, results: [] })
	})

	it('answers an empty post with an empty post', () => {
		expect(resolveCommands('', always(0))).toEqual({ text: '', results: [] })
		expect(resolveCommands(null, always(0))).toEqual({ text: '', results: [] })
	})
})

describe('parseOptions', () => {
	it('trims and drops empty options', () => {
		expect(parseOptions(' a , ,b ,  c ')).toEqual(['a', 'b', 'c'])
	})

	it('takes no more than the most options allowed', () => {
		const many = Array.from({ length: 30 }, (_, index) => `o${index}`).join(', ')

		expect(parseOptions(many)).toHaveLength(MAX_OPTIONS)
	})
})

describe('commandsIn', () => {
	it('names each kind once, for the hint under the box', () => {
		expect(commandsIn('/dice and /roll and /flip and /pick a, b')).toEqual(['dice', 'flip', 'pick'])
	})

	it('does not offer a /pick that has nothing to pick from', () => {
		expect(commandsIn('/pick lonely')).toEqual([])
	})
})

describe('tumble', () => {
	it('shows something each game could land on', () => {
		expect(tumble({ kind: 'dice', sides: 20 }, always(0.999))).toBe('20')
		expect(tumble({ kind: 'flip' }, always(0.9))).toBe('tails')
		expect(tumble({ kind: 'pick', options: ['a', 'b'] }, always(0.9))).toBe('b')
	})
})
