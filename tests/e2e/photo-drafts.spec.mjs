/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { expect, test } from '@playwright/test'
import { APP, PASSWORD, USER, apiAs, ensureAccount, login, openApp, postStatus } from './helpers.mjs'

test('Photos lists browser drafts and resumes their original writing context', async ({ page }) => {
	const api = await apiAs(USER, PASSWORD)
	await ensureAccount(api, USER)
	const target = await postStatus(api, { status: `Original for draft ${Date.now()}` })
	try {
		await login(page)
		await openApp(page, '/timeline/photos?scope=home')
		await expect(page.getByRole('button', { name: 'Share photos or a video', exact: true })).toBeVisible()
		await page.evaluate(({ user, targetId }) => {
			const draft = { text: 'Photo draft to continue', savedAt: Date.now(), visibility: 'public' }
			localStorage.setItem(`social.composer.draft::${user}`, JSON.stringify(draft))
			localStorage.setItem(`social.composer.draft.reply.${targetId}::${user}`, JSON.stringify({ ...draft, text: 'Saved reply' }))
			window.dispatchEvent(new Event('storage'))
		}, { user: USER, targetId: target.id })
		const drafts = page.getByRole('button', { name: 'Drafts (2)', exact: true })
		await drafts.click()
		await expect(page.locator('.drafts-list__item')).toHaveCount(2)
		await page.locator('.drafts-list__item').filter({ hasText: 'Photo draft to continue' }).click()
		const composer = page.locator('.modal-composer')
		await expect(composer.locator('.message')).toHaveText('Photo draft to continue')
		await composer.locator('.message').fill('Continued draft')
		await page.keyboard.press('Escape')
		await expect(composer).toHaveCount(0)
		await drafts.click()
		await page.locator('.drafts-list__item').filter({ hasText: 'Saved reply' }).click()
		await expect(composer.locator('.message')).toHaveText('Saved reply')
		await expect(composer.locator('.reply-to')).toContainText(target.content.replace(/<[^>]*>/g, ''))
		await page.keyboard.press('Escape')
		await expect(composer).toHaveCount(0)
		expect(await page.evaluate((user) => JSON.parse(localStorage.getItem(`social.composer.draft::${user}`)).text, USER)).toBe('Continued draft')
	} finally {
		await api.delete(`${APP}/api/v1/statuses/${target.id}`)
		await api.dispose()
	}
})
