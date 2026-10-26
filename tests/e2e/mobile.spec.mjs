/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { devices, expect, test } from '@playwright/test'
import { USER, login, navEntry, openApp } from './helpers.mjs'

// without its browser: `defaultBrowserType` cannot be set inside a describe
const phone = { ...devices['Pixel 7'] }
delete phone.defaultBrowserType

/**
 * The main pages on a phone-sized screen, in whichever browser the project
 * runs: a phone's viewport, touch and user agent rather than a desktop
 * window made narrow.
 *
 * What breaks there and nowhere else is width: a row that will not wrap, a
 * fixed-width panel, a table — and the page then scrolls sideways under the
 * reader's thumb. So each page is checked for drawing its own content and for
 * fitting the screen it is on.
 */
test.describe('on a phone', () => {
	test.use(phone)

	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	/** How far the page reaches past the right edge of the screen. */
	async function overflow(page) {
		return page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
	}

	test('the main pages draw and fit the screen', async ({ page }) => {
		const pages = [
			['/timeline', '.social__timeline'],
			['/timeline/notifications', '.social__pages'],
			['/timeline/direct', '.direct-messages'],
			['/discover', '.discover'],
			[`/@${USER}`, '.user-profile'],
			['/settings', '.social__pages'],
		]

		for (const [path, marker] of pages) {
			await openApp(page, path)
			await expect(page.locator(marker).first(), `${path} drew something`).toBeVisible()
			await expect(page.locator('.social__pages'), `${path} is not empty`).not.toBeEmpty()
			expect(await overflow(page), `${path} does not scroll sideways`).toBeLessThanOrEqual(1)
		}
	})

	test('the sidebar is folded away and opens from its toggle', async ({ page }) => {
		await openApp(page)

		const feed = navEntry(page, 'My Feed')
		// slid off the screen rather than removed, so it is out of the viewport
		await expect(feed, 'the sidebar does not take the screen').not.toBeInViewport()
		await page.getByRole('button', { name: /^Open navigation/ }).click()
		await expect(feed).toBeInViewport()

		await navEntry(page, 'Discover').click()
		await expect(page).toHaveURL(/\/discover/)
		await expect(page.locator('.discover')).toBeVisible()
	})

	test('a post can be written from the phone', async ({ page }) => {
		await openApp(page)
		const text = `Hello from a phone ${Date.now()}`

		await page.locator('.composer-line__expand').click()
		const composer = page.locator('.modal-composer')
		await composer.locator('.message').click()
		await page.keyboard.type(text)
		await composer.getByRole('button', { name: /^Post(?! as)/ }).click()

		await expect(page.locator('.social__timeline article').filter({ hasText: text }).first()).toBeVisible()
		expect(await overflow(page)).toBeLessThanOrEqual(1)
	})
})
