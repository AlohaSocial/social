/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { expect, test } from '@playwright/test'
import { APP, PASSWORD, USER, apiAs, ensureAccount, login, navEntry, openApp, postStatus } from './helpers.mjs'

test('recommendation explanations survive an ordinary status response and stay out of My Feed', async ({ page }) => {
	const api = await apiAs(USER, PASSWORD)
	await ensureAccount(api, USER)
	const status = await postStatus(api, { status: `Persistent explanation ${Date.now()}` })
	const explained = { ...status, interest: { reason: 'automatic', topics: [{ id: 'technology', labels: { en: 'Technology' } }] } }
	// Deterministic ranking fixture; creation and the bookmark update use the real API.
	const ranked = async (route) => route.fulfill({
		contentType: 'application/json',
		body: JSON.stringify(route.request().url().includes('/recommendations/timeline')
			? { items: [explained], mode: 'RANKED', next_cursor: null, coverage: { state: 'GOOD' } }
			: [explained]),
	})
	await page.route('**/api/v1/recommendations/timeline*', ranked)
	await page.route('**/api/v1/timelines/interests*', ranked)
	try {
		await login(page)
		await openApp(page, '/timeline/interests')
		const entry = page.locator('.timeline-entry').filter({ hasText: status.content.replace(/<[^>]*>/g, '') }).first()
		const reason = entry.locator('.interest-reason')
		await expect(reason).toContainText('Based on your interests')
		const before = await reason.textContent()
		await entry.hover()
		await entry.locator('button.action-item__menutoggle').first().click()
		const refreshed = page.waitForResponse((response) => response.url().endsWith(`/statuses/${status.id}/bookmark`) && response.request().method() === 'POST')
		await page.getByRole('menuitem', { name: 'Bookmark', exact: true }).click()
		const response = await refreshed
		expect(response.ok()).toBe(true)
		expect((await response.json()).interest).toBeNull()
		await page.evaluate(() => new Promise((resolve) => window.requestAnimationFrame(resolve)))
		await expect(reason).toHaveText(before)
		await navEntry(page, 'My Feed').click()
		await expect(page.locator('.interest-reason')).toHaveCount(0)
	} finally {
		await api.delete(`${APP}/api/v1/statuses/${status.id}`)
		await api.dispose()
	}
})
