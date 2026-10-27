/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { expect, test } from '@playwright/test'
import { APP, PASSWORD, USER, apiAs, login, openApp } from './helpers.mjs'

test('profile settings control the default without breaking explicit tabs on mobile', async ({ page }) => {
	await login(page)
	await openApp(page, '/settings#profile-layout')
	const form = page.locator('.profile-layout-settings')
	await expect(form.getByLabel('Open my profile on')).toBeVisible()
	const original = await form.getByLabel('Open my profile on').inputValue()
	const photoSwitch = form.getByRole('switch', { name: 'Photos', exact: true })
	const hadPhotos = await photoSwitch.isChecked()
	const api = await apiAs(USER, PASSWORD)
	try {
		await photoSwitch.check()
		await form.getByLabel('Open my profile on').selectOption('photos')
		await form.getByRole('button', { name: 'Save', exact: true }).click()
		await expect(form.getByRole('status')).toHaveText('Profile layout saved')
		const me = await api.get(`${APP}/api/v1/accounts/verify_credentials`)
		expect(me.ok()).toBe(true)
		const account = await me.json()
		await page.setViewportSize({ width: 375, height: 812 })
		await openApp(page, `/@${encodeURIComponent(account.acct)}`)
		await expect(page).toHaveURL(/media=image/)
		await page.getByRole('radio', { name: 'Posts', exact: true }).click()
		await expect(page).toHaveURL(/media=posts/)
		expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
	} finally {
		try {
			await openApp(page, '/settings#profile-layout')
			await form.getByLabel('Open my profile on').selectOption(original)
			if (!hadPhotos) {
				await form.getByRole('switch', { name: 'Photos', exact: true }).uncheck()
			}
			await form.getByRole('button', { name: 'Save', exact: true }).click()
			await expect(form.getByRole('status')).toHaveText('Profile layout saved')
		} finally {
			await api.dispose()
		}
	}
})
