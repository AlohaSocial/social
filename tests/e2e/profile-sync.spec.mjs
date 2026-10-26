/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { expect, test } from '@playwright/test'
import { APP, PASSWORD, USER, apiAs, ensureAccount, login, openApp, postStatus } from './helpers.mjs'

test('profile synchronization preserves posts through pending, failed and recovered responses', async ({ page }) => {
	const api = await apiAs(USER, PASSWORD)
	await ensureAccount(api, USER)
	const cached = await postStatus(api, { status: `Cached profile post ${Date.now()}` })
	const additional = await postStatus(api, { status: `New profile post ${Date.now()}` })
	let phase = 'pending'
	// Use real posts with deterministic remote-sync response metadata.
	await page.route('**/api/v1/accounts/*/statuses*', (route) => {
		if (new URL(route.request().url()).searchParams.get('pinned') === 'true') {
			return route.fallback()
		}
		return route.fulfill({
			contentType: 'application/json',
			headers: phase === 'pending'
				? { 'X-Social-Filling': '1', 'X-Social-Profile-Filling': '1', 'X-Social-Profile-Sync-State': 'pending' }
				: { 'X-Social-Profile-Sync-State': phase === 'failed' ? 'failed' : 'idle' },
			body: JSON.stringify(phase === 'recovered' ? [cached, additional] : [cached]),
		})
	})
	try {
		await page.setViewportSize({ width: 375, height: 812 })
		await login(page)
		await openApp(page, `/@${USER}?media=posts`)
		const notice = page.locator('.profile-sync-notice')
		await expect(notice).toContainText('Available posts are shown below')
		await expect(page.locator(`.timeline-entry[data-status-id="${cached.id}"]`)).toHaveCount(1)
		phase = 'failed'
		await expect(notice).toContainText('Previously loaded posts are still available')
		phase = 'recovered'
		await notice.getByRole('button', { name: 'Try again', exact: true }).click()
		await expect(notice).toBeHidden()
		await expect(page.locator(`.timeline-entry[data-status-id="${cached.id}"]`)).toHaveCount(1)
		await expect(page.locator(`.timeline-entry[data-status-id="${additional.id}"]`)).toHaveCount(1)
		expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
	} finally {
		await api.delete(`${APP}/api/v1/statuses/${cached.id}`)
		await api.delete(`${APP}/api/v1/statuses/${additional.id}`)
		await api.dispose()
	}
})
