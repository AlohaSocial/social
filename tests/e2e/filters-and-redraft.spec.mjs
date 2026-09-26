/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { login, openApp } from './helpers.mjs'

/**
 * The two things a Mastodon user reaches for in Settings and in a post's menu:
 * filtering a word, and correcting a post by deleting it and writing it again.
 *
 * Both are worth a browser test rather than a unit test because both are a
 * page talking to a route: the unit suite can prove the component asks, and
 * only this can prove the server answers.
 */
test.describe('filters, and writing a post again', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	test('Blocking can add and remove a filtered word', async ({ page }) => {
		// with the accounts and servers a reader silences, rather than in
		// Settings: filtering a word is the same decision aimed at a word
		await openApp(page, '/blocked')

		const section = page.locator('#filters')
		await expect(section).toBeVisible()
		await expect(section.getByRole('heading', { name: 'Filtered words' })).toBeVisible()

		const word = `spoilers-${Date.now()}`
		await section.getByRole('button', { name: 'Add a filter' }).click()
		await section.getByLabel('Name of the filter').fill(word)
		await section.getByLabel('Word or phrase').first().fill(word)
		// A new filter applies nowhere until somewhere is ticked, and the form
		// refuses to save until it does.
		//
		// Ticked by clicking the label rather than the input, which is what a
		// person does and the only thing that works: the input is under its
		// own label, so a click aimed at the input never reaches it. And the
		// label is matched by a prefix because each one carries a sentence of
		// explanation after the name.
		await section.getByText(/^My Feed/).first().click()
		await section.getByRole('button', { name: 'Create filter' }).click()

		const row = section.locator('.filters__item', { hasText: word })
		await expect(row).toBeVisible()

		// And take it away again, so the box is left as it was found. Delete is
		// a button on the row rather than an entry in a menu: an NcActions
		// holding one action renders it inline, with no toggle to open. The
		// confirmation's Delete is the last one on the page.
		await row.getByRole('button', { name: 'Delete', exact: true }).click()
		await page.getByRole('button', { name: 'Delete', exact: true }).last().click()
		await expect(section.locator('.filters__item', { hasText: word })).toHaveCount(0)
	})

	test('a post of your own offers Delete & re-draft', async ({ page }) => {
		await openApp(page)
		const text = `Redraft check ${Date.now()}`

		await page.locator('.navigation__compose').click()
		const composer = page.locator('.modal-composer')
		await composer.locator('.message').click()
		await page.keyboard.type(text)
		await composer.getByRole('button', { name: /^Post(?! as)/ }).click()

		const post = page.locator('.timeline-entry', { hasText: text }).first()
		await expect(post).toBeVisible({ timeout: 20_000 })
		await post.hover()
		await post.locator('button.action-item__menutoggle').first().click()
		await expect(page.getByRole('menuitem', { name: 'Delete & re-draft' })).toBeVisible()
	})
})
