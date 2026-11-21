/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { PASSWORD, USER, apiAs, ensureAccount, login, openApp, postStatus } from './helpers.mjs'

/**
 * The pages a reader spends their time on, and the two failure modes the unit
 * suites cannot see: a page that stays empty because a bundle did not load,
 * and a page that draws but whose numbers never arrive.
 *
 * Every bug this app shipped in a month of work was one of those two — the
 * `serverData` crash on Discover, the entries that silently lost their shared
 * chunk, the statistics section that rendered with no data. None of them could
 * fail a PHP test.
 */
test.describe('reading, in a browser', () => {
	// My Feed with one of the account's own posts in it, whatever ran before
	test.beforeAll(async () => {
		const api = await apiAs(USER, PASSWORD)
		await ensureAccount(api, USER)
		await postStatus(api, { status: `Something to read ${Date.now()}` })
		await api.dispose()
	})

	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	/**
	 * A view that throws leaves the app's own container empty while the
	 * Nextcloud chrome around it looks perfectly fine, which is why "the page
	 * loaded" is not the assertion.
	 */
	test('every page in the sidebar draws its own content', async ({ page }) => {
		// the paths the sidebar's own entries go to, which are
		// `/timeline/:type` and not one route per kind
		const pages = [
			['/timeline', '.social__timeline'],
			['/timeline/photos', '.social__pages'],
			['/timeline/videos', '.social__pages'],
			['/timeline/news', '.social__pages'],
			['/timeline/notifications', '.social__pages'],
			['/timeline/direct', '.social__pages'],
			['/discover', '.discover'],
			['/statistics', '.stats'],
			['/settings', '.social__pages'],
		]

		for (const [path, marker] of pages) {
			await openApp(page, path)
			await expect(page.locator(marker).first(), `${path} drew something`).toBeVisible()
			await expect(page.locator('.social__pages'), `${path} is not empty`).not.toBeEmpty()
		}
	})

	/**
	 * The statistics page is all numbers fetched after the paint: it draws
	 * fine with none of them, which is exactly how it broke unnoticed before.
	 */
	test('the statistics page fills in its figures', async ({ page }) => {
		await openApp(page, '/statistics')

		await expect(page.locator('.stats__kpi').first()).toBeVisible()
		// the account's own figures, which every instance has
		await expect(page.locator('.stats').first()).toContainText(/post/i)
	})

	/**
	 * Switching timelines is the most-used control in the app and the one
	 * that regressed into a flicker: what matters is that the list is never
	 * replaced by nothing.
	 */
	test('switching between the three scopes keeps a list on the page', async ({ page }) => {
		await openApp(page, '/timeline')
		const switcher = page.locator('.switcher').first()
		await expect(switcher).toBeVisible()

		for (const scope of [/Local/, /Global/, /My Feed/]) {
			await switcher.getByRole('radio', { name: scope }).click()
			// the container stays; only its contents change
			await expect(page.locator('.social__timeline')).toBeVisible()
		}
	})

	/** A profile is the page every federated link in the app points at. */
	test('an account page draws its header and its posts', async ({ page }) => {
		await openApp(page, '/timeline')
		const author = page.locator('.social__timeline article .post-author-wrapper a').first()
		await expect(author, 'My Feed holds the account\'s own post').toBeVisible()
		const name = (await author.locator('.post-author').innerText()).trim()

		await author.click()
		// a local author's page is their Nextcloud profile, with the app's
		// section in it; anybody else's is the app's own profile view
		await expect(page).toHaveURL(/\/(u\/|@)[^/]+$/)
		await expect(page.getByRole('heading', { name, exact: true }).first()).toBeVisible()
		await expect(page.locator('article').first()).toBeVisible()
	})

	/**
	 * Discover is the page that broke twice: once on a property the server
	 * does not send, once on a tab that was empty by design.
	 */
	test('Discover opens each tab without emptying the page', async ({ page }) => {
		await openApp(page, '/discover')

		for (const tab of ['People', 'Starter packs', 'Photos', 'Videos', 'Hashtags']) {
			await page.locator('.discover .switcher').getByRole('radio', { name: tab }).click()
			await expect(page.locator('.discover'), `${tab} kept the page`).toBeVisible()
			await expect(page.locator('.discover')).not.toBeEmpty()
		}
	})
})
