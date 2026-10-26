/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { OTHER_PASSWORD, OTHER_USER, apiAs, ensureAccount, login, openApp, postStatus } from './helpers.mjs'

/**
 * The things a person does *to* their account rather than reads: liking,
 * boosting, following, changing a setting. Each is a round trip whose answer
 * the page has to apply — and the failures live in the applying, not in the
 * request: a button that goes back to how it was, a counter that does not
 * move, a setting that says it saved and did not.
 */
test.describe('writing, in a browser', () => {
	// a local timeline with something on it, whatever ran before: an empty
	// one is a broken page, not a reason to skip
	test.beforeAll(async () => {
		const other = await apiAs(OTHER_USER, OTHER_PASSWORD)
		await ensureAccount(other, OTHER_USER)
		await postStatus(other, { status: `Something to like ${Date.now()}` })
		await other.dispose()
	})

	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	/** The post the run acts on: the newest on the local timeline. */
	async function firstPost(page) {
		await openApp(page, '/timeline/timeline')
		const post = page.locator('.social__timeline article').first()
		await expect(post, 'the local timeline shows the public posts').toBeVisible()

		return post
	}

	test('a like sticks, and can be taken back', async ({ page }) => {
		const post = await firstPost(page)
		// `aria-pressed` is the state the button publishes, and the one a
		// screen reader is given; the label changes with it
		const like = post.locator('.post-action-group--like button[aria-pressed]').first()
		await expect(like).toBeVisible()

		const before = await like.getAttribute('aria-pressed')
		// the page flips the button before the server answers, and a reload
		// before the answer would cancel the request it is checking
		const saved = page.waitForResponse((response) => /\/(un)?favourite$/.test(response.url()))
		await like.click()
		await expect(like).toHaveAttribute('aria-pressed', before === 'true' ? 'false' : 'true')
		expect((await saved).ok()).toBe(true)

		await page.reload()
		const after = page.locator('.social__timeline article').first()
			.locator('.post-action-group--like button[aria-pressed]').first()
		await expect(after, 'the server kept it, not just the page')
			.toHaveAttribute('aria-pressed', before === 'true' ? 'false' : 'true')

		// and back, so the run leaves the instance as it found it
		await after.click()
		await expect(after).toHaveAttribute('aria-pressed', before ?? 'false')
	})

	/**
	 * A setting that says it saved and did not is the worst kind of bug on a
	 * settings page, because nobody checks.
	 *
	 * The account switches are saved as they are flipped, so this drives the
	 * switch, waits for the save, and checks what the server hands back on the
	 * next load.
	 */
	test('an account setting survives the reload after it is saved', async ({ page }) => {
		const saved = () => page.waitForResponse((response) => response.url().includes('/accounts/update_credentials')
			&& response.request().method() === 'PATCH')
		const firstSwitch = () => page.locator('.account-settings .account-settings__switch').first()

		await openApp(page, '/settings')
		await expect(page.locator('.account-settings')).toBeVisible()

		const box = firstSwitch().locator('input[type="checkbox"]')
		const before = await box.isChecked()

		let response = saved()
		await firstSwitch().click()
		await expect(box).toBeChecked({ checked: !before })
		expect((await response).ok()).toBe(true)

		await page.reload()
		await expect(firstSwitch().locator('input[type="checkbox"]'), 'the server kept it, not just the page')
			.toBeChecked({ checked: !before })

		// put it back, so the run leaves the account as it found it
		response = saved()
		await firstSwitch().click()
		expect((await response).ok()).toBe(true)
		await expect(firstSwitch().locator('input[type="checkbox"]')).toBeChecked({ checked: before })
	})

	/**
	 * The composer is the one screen where losing what somebody typed is
	 * unforgivable: closing it must keep the draft.
	 */
	test('the composer keeps a draft when it is closed', async ({ page }) => {
		await openApp(page)
		const text = `draft that must survive ${Date.now()}`

		// a draft an earlier run left in this browser's storage would be put
		// back into the box and replace it while it is typed into: the test
		// starts from none. The composer reads it when it opens, below
		await page.evaluate(() => {
			for (const key of Object.keys(window.localStorage)) {
				if (key.startsWith('social.composer.draft')) {
					window.localStorage.removeItem(key)
				}
			}
		})

		await page.locator('.navigation__compose').click()
		const composer = page.locator('.modal-composer')
		await expect(composer).toBeVisible()
		await expect(composer.locator('.message')).toBeEmpty()
		await composer.locator('.message').click()
		await page.keyboard.type(text)

		// the dialog closes only after its guard allows it
		await page.locator('.modal-container button.modal-container__close, .modal-header button')
			.first()
			.click()
		await expect(composer).toBeHidden()

		await page.locator('.navigation__compose').click()
		const reopened = page.locator('.modal-composer .message').first()
		await expect(reopened).toContainText(text)

		// leave nothing behind: the draft is per account and would otherwise
		// greet the next run. Focused rather than clicked — the box is
		// replaced once as the stored draft is applied, and a click races that
		await page.locator('.modal-composer .message').first().focus()
		await page.keyboard.press('ControlOrMeta+A')
		await page.keyboard.press('Backspace')
		await expect(page.locator('.modal-composer .message').first()).toBeEmpty()
	})
})
