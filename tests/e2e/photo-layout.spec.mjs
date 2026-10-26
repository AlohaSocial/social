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
		await expect(page.locator('.new-post--one-line')).toHaveCount(0)
		await expect(page.getByRole('button', { name: 'Share photos or a video' })).toBeVisible()
		const layout = page.getByRole('radiogroup', { name: 'Photo feed layout', exact: true })
		await layout.getByRole('radio', { name: 'Media feed', exact: true }).click()
		await expect(page.locator(`.timeline-entry[data-status-id="${photo.id}"]`)).toBeVisible()
		await expect(page.locator(`.timeline-entry[data-status-id="${text.id}"]`)).toHaveCount(0)
		await layout.getByRole('radio', { name: 'Tiles', exact: true }).click()
		await expect(page.locator(`.media-grid__link[data-status-id="${photo.id}"]`)).toBeVisible()
		await expect(page.locator(`.media-grid__link[data-status-id="${text.id}"]`)).toHaveCount(0)
		await page.reload()
		await expect(layout.getByRole('radio', { name: 'Tiles', exact: true })).toHaveAttribute('aria-checked', 'true')
		const picking = page.waitForEvent('filechooser')
		await page.getByRole('button', { name: 'Share photos or a video', exact: true }).click()
		const picker = await picking
		await picker.setFiles(['first.png', 'second.png'].map((name) => ({ name, mimeType: 'image/png', buffer: Buffer.from(PICTURE, 'base64') })))
		const modal = page.locator('.modal-composer')
		await expect(modal.locator('.preview-item')).toHaveCount(1)
		await expect(modal.locator('.preview-grid__navigation')).toContainText('1 of 2')
		await modal.locator('.message').fill('The post text stays')
		await modal.locator('.preview-item__description').fill('First image description')
		await modal.getByRole('button', { name: 'Next attachment', exact: true }).click()
		await modal.locator('.preview-item__description').fill('Second image description')
		await modal.getByRole('button', { name: 'Previous attachment', exact: true }).click()
		await expect(modal.locator('.preview-item__description')).toHaveValue('First image description')
		await expect(modal.locator('.message')).toHaveText('The post text stays')
		await expect(modal.locator('img.attachment__preview')).toHaveCSS('object-fit', 'contain')
	} finally {
		await api.delete(`${APP}/api/v1/statuses/${photo.id}`)
		await api.delete(`${APP}/api/v1/statuses/${text.id}`)
		await api.dispose()
	}
})
