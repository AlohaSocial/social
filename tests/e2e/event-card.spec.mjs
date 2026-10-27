/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { expect, test } from '@playwright/test'
import { APP, PASSWORD, USER, apiAs, ensureAccount, login, openApp, postStatus } from './helpers.mjs'

test('event details respect warnings and failed imports retain recovery actions', async ({ page }) => {
	const api = await apiAs(USER, PASSWORD)
	await ensureAccount(api, USER)
	const post = await postStatus(api, {
		status: 'A community event',
		spoiler_text: 'Event warning',
		event: { name: `Event ${Date.now()}`, start_time: '2027-01-01T12:00:00Z', location: 'Community hall' },
	})
	await page.route('**/api/v1/statuses/*/calendar/import', (route) => route.fulfill({
		status: 409,
		contentType: 'application/json',
		body: JSON.stringify({ error: 'No writable calendar is available' }),
	}))
	try {
		await page.setViewportSize({ width: 375, height: 812 })
		await login(page)
		await openApp(page, `/@${USER}?media=posts`)
		const entry = page.locator(`.timeline-entry[data-status-id="${post.id}"]`)
		await expect(entry.locator('.post-event')).toHaveCount(0)
		await entry.getByRole('button', { name: 'Show more', exact: true }).click()
		const card = entry.locator('.post-event')
		await expect(card).toContainText('Community hall')
		await card.getByRole('button', { name: 'Add to Nextcloud Calendar', exact: true }).click()
		await expect(card.getByRole('status')).toContainText('No writable calendar is available')
		await expect(card.getByRole('link', { name: 'Download calendar file' })).toBeVisible()
		await expect(card).toContainText('does not confirm attendance')
		expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
	} finally {
		await api.delete(`${APP}/api/v1/statuses/${post.id}`)
		await api.dispose()
	}
})
