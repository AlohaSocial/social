/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { expect, test } from '@playwright/test'
import { APP, PASSWORD, USER, apiAs, ensureAccount, login, openApp, postStatus } from './helpers.mjs'

test('feed explanations and profile activity are optional cards', async ({ page }) => {
	const api = await apiAs(USER, PASSWORD)
	await ensureAccount(api, USER)
	const post = await postStatus(api, { status: `Information card check ${Date.now()}` })
	try {
		await login(page)
		await openApp(page)
		const help = page.locator('.timeline-scope-description')
		await expect(help).toBeVisible()
		await help.getByRole('button', { name: 'Hide About this feed', exact: true }).click()
		await expect(help).toHaveCount(0)
		await page.reload()
		await expect(page.getByRole('radiogroup', { name: 'Which posts to show', exact: true })).toBeVisible()
		await expect(help).toHaveCount(0)
		await openApp(page, `/@${USER}?media=posts`)
		const activity = page.locator('.profile-highlights.info-card')
		await expect(activity).toBeVisible()
		await activity.getByRole('button', { name: 'Hide Posting activity', exact: true }).click()
		await expect(activity).toHaveCount(0)
		const refreshed = page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/highlights'))
		await page.reload()
		await refreshed
		await expect(activity).toHaveCount(0)
	} finally {
		await api.delete(`${APP}/api/v1/statuses/${post.id}`)
		await api.dispose()
	}
})
