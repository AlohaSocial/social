/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { countableLength, entitiesIn, linkifyRuns } from '../../../src/utils/linkify.js'

/**
 * These are the cases `tests/Service/LinkifyServiceTest.php` runs, in the same
 * words. The composer's preview is only worth showing while the two agree, so
 * a change to the server's rules that is not made here should fail here.
 */
describe('entitiesIn — the same answers the server gives', () => {
	const boundaries = [
		['a full stop ends the sentence, not the URL', 'https://example.invalid/a.', 'https://example.invalid/a'],
		['a comma likewise', 'https://example.invalid/a,', 'https://example.invalid/a'],
		['an unopened bracket is not part of it', '(https://example.invalid/a)', 'https://example.invalid/a'],
		['a bracket the URL opened is', 'https://example.invalid/a_(b)', 'https://example.invalid/a_(b)'],
		['a query string is', 'https://example.invalid/a?b=c&d=e', 'https://example.invalid/a?b=c&d=e'],
		['and so is a fragment', 'https://example.invalid/a#section', 'https://example.invalid/a#section'],
	]

	it.each(boundaries)('a URL ends where the sentence does: %s', (_name, text, expected) => {
		const urls = entitiesIn(text).filter((entity) => entity.type === 'url')

		expect(urls).toHaveLength(1)
		expect(urls[0].name).toBe(expected)
	})

	it('finds nothing inside a URL', () => {
		const entities = entitiesIn('https://example.invalid/@bob#section')

		expect(entities).toHaveLength(1)
		expect(entities[0].type).toBe('url')
	})

	it('links only http(s) URLs', () => {
		expect(entitiesIn('javascript:alert(1) ftp://example.invalid/a')).toEqual([])
	})

	const entities = [
		['a mention and a hashtag', 'hi @bob@remote.example #Nextcloud', [['mention', 'bob@remote.example'], ['hashtag', 'Nextcloud']]],
		['a local handle has no domain', '@alice', [['mention', 'alice']]],
		['an email address is not a mention', 'write to frank@example.org', []],
		['a hash inside a word is not a tag', 'issue#42 is fixed', []],
		['an html entity is not a tag', 'a &#39; b', []],
		['a sentence may end after a handle', 'ask @bob@remote.example.', [['mention', 'bob@remote.example']]],
		['a handle in brackets is still a handle', '(@bob@remote.example)', [['mention', 'bob@remote.example']]],
		['nothing at all', 'plain words', []],
	]

	it.each(entities)('entities are found where they are: %s', (_name, text, expected) => {
		expect(entitiesIn(text).map((entity) => [entity.type, entity.name])).toEqual(expected)
	})

	it('names its own place in the text', () => {
		const text = 'hi @bob@remote.example!'
		const entity = entitiesIn(text)[0]

		expect(text.slice(entity.offset, entity.offset + entity.text.length)).toBe(entity.text)
		expect(entity.text).toBe('@bob@remote.example')
	})

	it('answers for nothing at all instead of throwing', () => {
		expect(entitiesIn('')).toEqual([])
		expect(entitiesIn(undefined)).toEqual([])
		expect(entitiesIn(null)).toEqual([])
	})
})

describe('linkifyRuns', () => {
	it('gives back exactly what was typed, in order', () => {
		const text = 'hi @bob@remote.example, see https://example.invalid/a #Nextcloud'

		expect(linkifyRuns(text).map((run) => run.text).join('')).toBe(text)
	})

	it('marks each run for what it is', () => {
		const runs = linkifyRuns('hi @alice #tag')

		expect(runs.map((run) => run.type)).toEqual(['text', 'mention', 'text', 'hashtag'])
	})

	it('is one run when there is nothing to mark', () => {
		expect(linkifyRuns('plain words')).toEqual([{ type: 'text', text: 'plain words' }])
	})

	it('has no runs for an empty post', () => {
		expect(linkifyRuns('')).toEqual([])
	})

	// the trailing full stop is not part of the link, so it has to come back
	// out as text or the preview would quietly eat a character
	it('keeps the punctuation a URL gave up', () => {
		const runs = linkifyRuns('see https://example.invalid/a.')

		expect(runs.map((run) => run.text).join('')).toBe('see https://example.invalid/a.')
		expect(runs[runs.length - 1]).toEqual({ type: 'text', text: '.' })
	})
})

/** The cases of `LinkifyServiceTest::countedLengths()`, so the two cannot drift. */
describe('countableLength — the same count the server holds a post to', () => {
	const long = 'https://example.org/' + 'a'.repeat(80)
	it.each([
		['plain text', 'hello there', 11],
		['a long URL counts as 23', 'see ' + long, 4 + 23],
		['and so does a short one', 'see https://a.io', 4 + 23],
		['a URL closing a sentence keeps its full stop', 'see ' + long + '.', 4 + 23 + 1],
		['a remote mention counts as its name', 'hi @bob@remote.example', 3 + 4],
		['a local mention counts as written', 'hi @bob', 7],
		['multi-byte characters are characters', 'héllo 🐈', 7],
	])('%s', (name, text, expected) => {
		expect(countableLength(text)).toBe(expected)
	})
})
