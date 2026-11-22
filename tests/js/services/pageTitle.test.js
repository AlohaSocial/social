/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeAll, describe, expect, it } from 'vitest'
import { setPageTitle, titleForRoute } from '../../../src/services/pageTitle.js'

describe('the page title', () => {
	beforeAll(() => {
		// what the server renders the page with, before any view has named itself
		document.title = 'Aloha - Nextcloud'
	})

	it('names the page in front of the title the server set, and keeps that tail', () => {
		setPageTitle('Settings')
		expect(document.title).toBe('Settings - Aloha - Nextcloud')

		setPageTitle('Bob')
		expect(document.title).toBe('Bob - Aloha - Nextcloud')

		setPageTitle('')
		expect(document.title).toBe('Aloha - Nextcloud')
	})

	it.each([
		['settings', 'Settings'],
		['discover', 'Discover'],
		['shorts', 'Shorts'],
		['search', 'Search'],
		['not-found', 'Page not found'],
	])('knows the %s page by its route alone', (name, title) => {
		expect(titleForRoute({ name })).toBe(title)
	})

	// what they show has to arrive first
	it.each(['timeline', 'tags', 'list', 'profile', 'single-post', undefined])('leaves the %s page to name itself', (name) => {
		expect(titleForRoute({ name })).toBeNull()
	})
})
