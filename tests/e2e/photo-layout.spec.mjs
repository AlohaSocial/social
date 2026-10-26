/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { expect, test } from '@playwright/test'
import { APP, PASSWORD, USER, apiAs, ensureAccount, login, openApp, postStatus } from './helpers.mjs'

const PICTURE = 'iVBORw0KGgoAAAANSUhEUgAAAEAAAAAwCAIAAAAuKetIAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAyklEQVRo3u2Z3RFAQAyE3Y46PClICcpQjhKUoQjFKIAHLpuLjM2jMZv9JPfjrgzL3mUOdMlDAAIQgAAEYIreSfeY5uvDcVvpiQp3Jb717UqC9u5fvdmoAtWG7KVAoHtKKRDonqKAWPd2nR8vZMSZxKKGL7i3aGovlBHAo3+qldVCAhCAAPIBePwZViurhZICeHRRnSYa56Or/XsMsIpg0UFgbooCYr+fnZ95tPhqN89qP+Ygfu6JOIMVp2vWrKfT2koIQAACEECyOAGIe0GRnAAnVwAAAABJRU5ErkJggg=='

test('Photos excludes text-only posts and preserves the chosen presentation', async ({ page }) => {
	const api = await apiAs(USER, PASSWORD)
	await ensureAccount(api, USER)
	const upload = await api.post(`${APP}/api/v1/media`, { multipart: {
		file: { name: 'photo.png', mimeType: 'image/png', buffer: Buffer.from(PICTURE, 'base64') },
	} })
	expect(upload.ok()).toBe(true)
	const media = await upload.json()
	const photo = await postStatus(api, { status: `Photo ${Date.now()}`, media_ids: [media.id] })
	const text = await postStatus(api, { status: `Text-only ${Date.now()}` })
	try {
		await login(page)
		await openApp(page, '/timeline/photos?scope=home')
		const layout = page.getByRole('radiogroup', { name: 'Photo feed layout', exact: true })
		await layout.getByRole('radio', { name: 'Media feed', exact: true }).click()
		await expect(page.locator(`.timeline-entry[data-status-id="${photo.id}"]`)).toBeVisible()
		await expect(page.locator(`.timeline-entry[data-status-id="${text.id}"]`)).toHaveCount(0)
		await layout.getByRole('radio', { name: 'Tiles', exact: true }).click()
		await expect(page.locator(`.media-grid__link[data-status-id="${photo.id}"]`)).toBeVisible()
		await expect(page.locator(`.media-grid__link[data-status-id="${text.id}"]`)).toHaveCount(0)
		await page.reload()
		await expect(layout.getByRole('radio', { name: 'Tiles', exact: true })).toHaveAttribute('aria-checked', 'true')
	} finally {
		await api.delete(`${APP}/api/v1/statuses/${photo.id}`)
		await api.delete(`${APP}/api/v1/statuses/${text.id}`)
		await api.dispose()
	}
})
