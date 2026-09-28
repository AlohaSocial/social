/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The feed made of the reader's hashtags is called "For you", in German "Für
 * dich". Its old name stays out of everything a reader can see: a string left
 * behind in one corner of the settings would be a feature with two names.
 * The code under it keeps its internal names (`interests`), which are an API
 * and stored data rather than words on screen.
 */

import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

// vitest runs from the project root; import.meta.url is not a file url here
const ROOT = process.cwd()
const OLD = /My interests|Meine Interessen/

/**
 * @param {string} dir where to start
 * @return {string[]} every file under it
 */
function filesUnder(dir) {
	return readdirSync(dir).flatMap((entry) => {
		const path = join(dir, entry)

		return statSync(path).isDirectory() ? filesUnder(path) : [path]
	})
}

describe('the feed is called For you', () => {
	it('says its old name nowhere in the web interface', () => {
		const offenders = filesUnder(resolve(ROOT, 'src'))
			.filter((path) => OLD.test(readFileSync(path, 'utf8')))
			.map((path) => relative(ROOT, path))

		expect(offenders).toEqual([])
	})

	it.each(['de.json', 'de_DE.json'])('has no old key or translation left in %s, and the new one is there', (file) => {
		const catalogue = JSON.parse(readFileSync(resolve(ROOT, 'l10n', file), 'utf8')).translations
		const entries = Object.entries(catalogue).filter(([key, value]) => OLD.test(key) || OLD.test(String(value)))

		expect(entries).toEqual([])
		expect(catalogue['For you']).toBe('Für dich')
	})
})
