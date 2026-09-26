/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { login, navEntry, openApp } from './helpers.mjs'

/**
 * What a person does in their first five minutes, against a real server:
 * open the app, read the feed, write a post, look around. The unit and
 * integration suites cannot see a page that stays empty because a bundle
 * did not load or a route returned the wrong shape; this can.
 */
test.describe('Social, in a browser', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	test('opens with its sidebar and a feed', async ({ page }) => {
		await openApp(page)

		// Activities is in the account menu at the foot, not at the top level
		for (const name of ['My Feed', 'Photos', 'Videos', 'Direct messages', 'Discover']) {
			await expect(navEntry(page, name), `${name} is in the sidebar`).toBeVisible()
		}
		await expect(page.locator('.social__timeline')).toBeVisible()
		// the New post button is the one thing on the page that is always there
		await expect(page.locator('.navigation__compose')).toBeVisible()
	})

	test('a post written in the dialog shows up in My Feed', async ({ page }) => {
		await openApp(page)
		const text = `Hello from the browser test ${Date.now()}`

		await page.locator('.navigation__compose').click()
		const composer = page.locator('.modal-composer')
		await expect(composer).toBeVisible()
		await composer.locator('.message').click()
		await page.keyboard.type(text)
		await composer.getByRole('button', { name: /^Post(?! as)/ }).click()

		// the dialog closes on success, and the post is the newest thing in the feed
		await expect(composer).toBeHidden()
		await expect(page.locator('.social__timeline article').filter({ hasText: text }).first()).toBeVisible()
	})

	/**
	 * The poll's three controls are one sentence — add an option, say whether
	 * more than one may be picked, say how long it runs — and they have to read
	 * as one line.
	 *
	 * They did not. The label around the checkbox was an inline box, so the
	 * checkbox sat with its bottom edge on the words' baseline and the words
	 * dropped eleven pixels below everything beside them. Nothing catches that
	 * but a browser: every one of these elements is `align-items: center` and
	 * reports a centred *box*, while the ink inside one of them is not.
	 *
	 * Measured as ink, therefore: the words are a Range over the text node, not
	 * the element that holds them.
	 */
	test('the poll settings read as one line', async ({ page }) => {
		await openApp(page)

		await page.locator('.navigation__compose').click()
		const composer = page.locator('.modal-composer')
		await expect(composer).toBeVisible()
		await composer.locator('.message').click()
		await composer.getByRole('button', { name: 'Add poll' }).click()

		const settings = composer.locator('.poll-editor__settings')
		await expect(settings).toBeVisible()

		const middles = await settings.evaluate((row) => {
			const mid = (rect) => rect.top + rect.height / 2
			const label = row.querySelector('label')
			const words = [...label.childNodes].find((node) => node.nodeType === 3 && node.textContent.trim())
			const range = document.createRange()
			range.selectNodeContents(words)

			return {
				addOption: mid(row.querySelector('button').getBoundingClientRect()),
				checkbox: mid(row.querySelector('input[type=checkbox]').getBoundingClientRect()),
				words: mid(range.getBoundingClientRect()),
				duration: mid(row.querySelector('select').getBoundingClientRect()),
			}
		})

		const values = Object.values(middles)
		const drift = Math.max(...values) - Math.min(...values)
		expect(drift, `the four controls sit at ${JSON.stringify(middles)}`).toBeLessThanOrEqual(2)
	})

	/**
	 * The box opens on a click and stays open for as long as it holds
	 * anything — so before the close button, a reader who had typed a word and
	 * changed their mind had to delete the word to get their feed back. Closing
	 * it keeps the word: what was asked for is the feed, not a blank page.
	 */
	test('the composer in the feed closes without losing what was written', async ({ page }) => {
		await openApp(page)

		const composer = page.locator('.social__wrapper .new-post').first()
		await composer.locator('.message').click()
		await page.keyboard.type('half a thought')
		await expect(composer).not.toHaveClass(/new-post--collapsed/)

		await composer.getByRole('button', { name: 'Close the composer' }).click()
		await expect(composer).toHaveClass(/new-post--collapsed/)
		await expect(composer.locator('.message')).toHaveText('half a thought')

		// and it is all still there when the box is asked for again
		await composer.locator('.message').click()
		await expect(composer).not.toHaveClass(/new-post--collapsed/)
		await expect(composer.locator('.message')).toHaveText('half a thought')

		// down to the next visit to the page, which is what the draft is for
		await openApp(page)
		await expect(page.locator('.social__wrapper .new-post .message').first())
			.toHaveText('half a thought')

		// leave the feed as it was found
		await page.locator('.social__wrapper .new-post .message').first().click()
		await page.keyboard.press('ControlOrMeta+A')
		await page.keyboard.press('Backspace')
	})

	/**
	 * Each was opened by a button in the toolbar and could be shut only by
	 * finding that same button and pressing it again — a row further down, and
	 * a thing to work out rather than to see.
	 */
	test('each composer panel closes from itself', async ({ page }) => {
		await openApp(page)
		const composer = page.locator('.social__wrapper .new-post').first()
		await composer.locator('.message').click()

		await composer.getByRole('button', { name: 'Add poll' }).click()
		await expect(composer.locator('.poll-editor')).toBeVisible()
		await composer.locator('.poll-editor__remove').click()
		await expect(composer.locator('.poll-editor')).toBeHidden()

		await composer.getByRole('button', { name: 'Add content warning' }).click()
		await composer.locator('.content-warning').fill('spoilers')
		await composer.locator('.content-warning-row__remove').click()
		await expect(composer.locator('.content-warning')).toBeHidden()

		await composer.getByRole('button', { name: 'Preview this post' }).click()
		await expect(composer.locator('.composer-preview')).toBeVisible()
		await composer.locator('.composer-preview__close').click()
		await expect(composer.locator('.composer-preview')).toBeHidden()

		// and the box itself still closes, with nothing left behind in it
		await composer.getByRole('button', { name: 'Close the composer' }).click()
		await expect(composer).toHaveClass(/new-post--collapsed/)
	})

	/**
	 * The picker was rendered inside the toolbar row, which hides its
	 * overflow: the suggestions and the country box under them were cut off,
	 * so a place the instance had not seen before could not be named at all.
	 */
	test('the place picker has room for the box that names a new place', async ({ page }) => {
		await openApp(page)
		const composer = page.locator('.social__wrapper .new-post').first()
		await composer.locator('.message').click()
		await composer.getByRole('button', { name: 'Say where this was taken' }).click()

		const picker = composer.locator('.place-picker')
		await picker.locator('input').first().fill('Nowhere-on-Sea')
		const country = picker.locator('.place-picker__country input')
		await expect(country).toBeInViewport()

		const box = await country.boundingBox()
		const panel = await picker.boundingBox()
		expect(box.y + box.height).toBeLessThanOrEqual(panel.y + panel.height + 1)

		await picker.locator('.place-picker__close').click()
		await expect(picker).toBeHidden()
	})

	/**
	 * `@nextcloud/dialogs` renders no confirm button unless the builder is
	 * given one, so a file could be selected and there was nothing to press.
	 */
	test('the Files dialog has a button to confirm the pick', async ({ page }) => {
		await openApp(page)
		const composer = page.locator('.social__wrapper .new-post').first()
		await composer.locator('.message').click()
		await composer.getByRole('button', { name: 'Add from Files' }).click()

		const dialog = page.getByRole('dialog')
		await expect(dialog.getByRole('button', { name: 'Attach' })).toBeVisible()
		await page.keyboard.press('Escape')
	})

	/** Reference at the end, and the one thing that cannot be undone last. */
	test('the settings page ends with the shortcuts above the deletion', async ({ page }) => {
		await openApp(page, '/settings')
		const headings = page.locator('.settings__section-heading')
		await expect(headings.first()).toBeVisible()

		expect((await headings.allInnerTexts()).slice(-2))
			.toEqual(['Keyboard shortcuts', 'Delete your Social account'])
	})

	test('Discover has its sections', async ({ page }) => {
		await openApp(page, '/discover')

		await expect(page.locator('.discover')).toBeVisible()
		const switcher = page.locator('.discover .switcher')
		await expect(switcher).toBeVisible()
		for (const option of ['People', 'Starter packs']) {
			await expect(switcher.getByRole('radio', { name: option })).toBeVisible()
		}

		// the People tab leads with the question its own suggestions cannot
		// answer: who is out there, on servers this one may never have met
		await expect(page.locator('.finder input')).toBeVisible()
	})

	test('the timeline switcher moves between the three scopes', async ({ page }) => {
		await openApp(page)
		const switcher = page.locator('.switcher').first()
		await expect(switcher).toBeVisible()

		await switcher.getByRole('radio', { name: /Global/ }).click()
		await expect(page).toHaveURL(/federated/)
		await switcher.getByRole('radio', { name: /Local/ }).click()
		await expect(page).toHaveURL(/timeline\/timeline/)
	})

	test('a Nextcloud group the account is in is a list in the sidebar', async ({ page }) => {
		const group = process.env.E2E_GROUP
		test.skip(!group, 'set E2E_GROUP to the display name of a group this account is in')

		await openApp(page)
		// The group is a list because an administrator chose it -- the
		// workflow sets `group_lists` for exactly this test. Nothing becomes a
		// list on its own any more, so a run against an instance where that
		// setting is empty will find no list and should.
		//
		// The lists live inside Explore, beside the hashtags the account
		// follows, so the entry has to be there and open before the list is.
		const explore = page.locator('.navigation__explore').first()
		await expect(explore).toBeVisible()

		const list = page.locator('.navigation__list').filter({ hasText: group }).first()
		if (!await list.isVisible()) {
			// it is open by default, but a previous run may have folded it
			await explore.getByRole('button').first().click()
		}
		await expect(list).toBeVisible()

		await list.click()
		await expect(page).toHaveURL(/timeline\/list\/\d+/)
		await expect(page.locator('h1.timeline-heading')).toHaveText(group)
	})
})
