/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { PASSWORD, USER, apiAs, ensureAccount, login, openApp } from './helpers.mjs'

/**
 * The notification filter in the reading column.
 *
 * Seven translated labels do not fit the 616px column, and a wide window does
 * not give the column more room. The control gives up the icons, then the
 * words, and scrolls only if even the icons do not fit, rather than shrinking
 * the options until their words run into one another. German is where the
 * labels are longest, which is where the defect was seen.
 */
test.describe('the notification filter in the reading column', () => {
	/** @type {import('@playwright/test').APIRequestContext} */
	let api
	/** the language the account had before this test, put back afterwards */
	let language

	test.beforeEach(async ({ page }) => {
		api = await apiAs(USER, PASSWORD)
		await ensureAccount(api, USER)

		const me = await api.get('ocs/v2.php/cloud/user?format=json')
		language = (await me.json()).ocs.data.language || 'en'
		const changed = await api.put(`ocs/v2.php/cloud/users/${USER}`, { form: { key: 'language', value: 'de' } })
		expect(changed.ok(), 'the account could be read in German').toBe(true)

		await login(page)
		await openApp(page, '/timeline/notifications')
	})

	test.afterEach(async () => {
		if (api !== undefined && language !== undefined) {
			await api.put(`ocs/v2.php/cloud/users/${USER}`, { form: { key: 'language', value: language } })
		}
		await api?.dispose()
	})

	test('keeps long translated labels apart and reachable in a narrow column', async ({ page }) => {
		const switcher = page.locator('.notifications-filter')
		await expect(switcher).toBeVisible()

		// the window is wide; the column it draws the control in is not
		const viewport = page.viewportSize()
		const column = await switcher.evaluate((element) => element.closest('.social__wrapper').getBoundingClientRect().width)
		expect(viewport.width).toBeGreaterThan(column + 200)

		const options = switcher.locator('.switcher__option')
		await expect(options).toHaveCount(7)
		await expect(switcher.getByText('Weiterverbreitete Beiträge')).toBeVisible()

		// whatever is drawn of an option stays inside its button, and each
		// button starts after the one before it ends
		const boxes = await options.evaluateAll((elements) => elements.map((option) => {
			const button = option.getBoundingClientRect()
			const drawn = [...option.children]
				.map((child) => child.getBoundingClientRect())
				.filter((box) => box.width > 1 && box.height > 1)
				.map((box) => ({ left: box.left, right: box.right }))
			return { left: button.left, right: button.right, drawn }
		}))
		boxes.forEach((box, index) => {
			expect(box.drawn.length, 'every option shows something').toBeGreaterThan(0)
			for (const part of box.drawn) {
				expect(part.left, 'what it shows is inside its option').toBeGreaterThanOrEqual(box.left - 0.5)
				expect(part.right, 'what it shows is inside its option').toBeLessThanOrEqual(box.right + 0.5)
			}
			if (index > 0) {
				expect(box.left, 'options do not overlap').toBeGreaterThanOrEqual(boxes[index - 1].right - 0.5)
			}
		})

		// the page itself does not scroll sideways
		expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1)

		// every option is reachable: choosing the last one brings it into view
		await options.last().click()
		await expect(options.last()).toBeInViewport({ ratio: 1 })
		await expect(options.last()).toHaveAttribute('aria-checked', 'true')
	})
})
