/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { expect, test } from '@playwright/test'
import { APP, PASSWORD, USER, apiAs, ensureAccount, login, openApp, postStatus } from './helpers.mjs'

test('editing uses the shared dialog and retains changes after a failed save', async ({ page }) => {
	const api = await apiAs(USER, PASSWORD)
	await ensureAccount(api, USER)
	const original = `Composer edit ${Date.now()}`
	const status = await postStatus(api, { status: original })
	try {
		await login(page)
		await openApp(page, `/@${USER}/${status.id}`)
		const post = page.locator('article').filter({ hasText: original }).first()
		await post.hover()
		const menu = post.locator('button.action-item__menutoggle').first()
		await menu.click()
		await page.getByRole('menuitem', { name: 'Edit', exact: true }).click()
		const dialog = page.locator('.modal-composer')
		const text = dialog.locator('.message')
		await expect(text).toHaveText(original)
		await text.fill(`${original} updated`)
		const endpoint = `**/api/v1/statuses/${status.id}`
		await page.route(endpoint, (route) => route.request().method() === 'PUT'
			? route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ error: 'Temporary test failure' }) })
			: route.continue())
		const failed = page.waitForResponse((response) => response.url().endsWith(`/api/v1/statuses/${status.id}`) && response.request().method() === 'PUT')
		await dialog.getByRole('button', { name: 'Save', exact: true }).click()
		expect((await failed).status()).toBe(503)
		await expect(dialog.getByRole('button', { name: 'Save', exact: true })).toBeEnabled()
		await expect(text).toHaveText(`${original} updated`)
		await text.press('Escape')
		const discard = page.getByRole('dialog', { name: 'Discard these changes?' })
		await expect(discard).toBeVisible()
		await discard.getByRole('button', { name: 'Cancel', exact: true }).click()
		await expect(text).toHaveText(`${original} updated`)
		await page.unroute(endpoint)
		await text.press('Control+Enter')
		await expect(dialog).toBeHidden()
		const saved = await api.get(`${APP}/api/v1/statuses/${status.id}`)
		expect((await saved.json()).content).toContain(`${original} updated`)
	} finally {
		await api.delete(`${APP}/api/v1/statuses/${status.id}`)
		await api.dispose()
	}
})

test('mobile composer traps focus, keeps the draft on Escape and does not overflow', async ({ page }) => {
	await page.setViewportSize({ width: 375, height: 812 })
	await login(page)
	await openApp(page)
	await page.locator('.composer-line__expand').click()
	const dialog = page.locator('.modal-composer')
	await dialog.locator('.message').fill('Draft on mobile')
	await expect(page.getByRole('dialog').filter({ has: dialog })).not.toHaveClass(/fade-enter-active/)
	for (let index = 0; index < 20; index++) {
		await page.keyboard.press('Tab')
		expect(await page.evaluate(() => document.activeElement.closest('[role="dialog"]') !== null)).toBe(true)
	}
	expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
	await page.keyboard.press('Escape')
	await expect(dialog).toBeHidden()
	await page.locator('.composer-line__expand').click()
	await expect(dialog.locator('.message')).toHaveText('Draft on mobile')
	await dialog.locator('.message').fill('')
	await page.keyboard.press('Escape')
})
