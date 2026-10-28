/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { expect, test } from '@playwright/test'
import { APP, OTHER_PASSWORD, OTHER_USER, PASSWORD, USER, apiAs, ensureAccount, ensureUser, login, openApp, postStatus } from './helpers.mjs'

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

test('learning notices retain a compact centered card on desktop and mobile', async ({ page }) => {
	const user = `notice-${Date.now()}`
	const password = 'Cards-Test-2026!'
	const admin = await apiAs(OTHER_USER, OTHER_PASSWORD)
	const api = await apiAs(user, password)
	let created = false
	try {
		created = await ensureUser(user, password)
		expect(created).toBe(true)
		await ensureAccount(api, user)
		const state = await api.get(`${APP}/api/v1/interests`)
		test.skip(state.status() === 404, 'Recommendations are disabled by the administrator')
		expect(state.ok()).toBe(true)
		const saved = await api.put(`${APP}/api/v1/interests/settings`, { data: { learning: true, paused: false } })
		expect(saved.ok()).toBe(true)
		await login(page, user, password)
		await openApp(page)
		const notice = page.locator('.interests-notice.feed-notice')
		await expect(notice).toBeVisible()
		for (const width of [1280, 320]) {
			await page.setViewportSize({ width, height: 900 })
			await expect(async () => {
				const layout = await notice.evaluate((element) => {
					const style = getComputedStyle(element)
					const box = element.getBoundingClientRect()
					const parent = element.parentElement.getBoundingClientRect()
					const text = element.querySelector('.interests-notice__text')
					const range = document.createRange()
					range.selectNodeContents(text)
					return {
						border: parseFloat(style.borderTopWidth),
						background: style.backgroundColor,
						overflow: element.scrollWidth - element.clientWidth,
						left: box.left, right: box.right, viewport: window.innerWidth,
						centerOffset: Math.abs(box.x + box.width / 2 - parent.x - parent.width / 2),
						extraTextSpace: text.getBoundingClientRect().height - range.getBoundingClientRect().height,
						lineHeight: parseFloat(getComputedStyle(text).lineHeight),
					}
				})
				expect(layout.border).toBeGreaterThan(0)
				expect(layout.background).not.toBe('rgba(0, 0, 0, 0)')
				expect(layout.overflow).toBeLessThanOrEqual(1)
				expect(layout.left).toBeGreaterThanOrEqual(0)
				expect(layout.right).toBeLessThanOrEqual(layout.viewport)
				expect(layout.centerOffset).toBeLessThanOrEqual(1)
				expect(layout.extraTextSpace).toBeLessThanOrEqual(layout.lineHeight)
			}).toPass()
		}
		await notice.getByRole('button', { name: 'Got it', exact: true }).click()
		await expect(notice).toHaveCount(0)
	} finally {
		if (created) {
			await admin.delete(`ocs/v2.php/cloud/users/${user}?format=json`)
		}
		await api.dispose()
		await admin.dispose()
	}
})

test('a failed feed has a readable recovery card and retries the real endpoint', async ({ page }) => {
	const api = await apiAs(USER, PASSWORD)
	try {
		await ensureAccount(api, USER)
		await login(page)
		await page.route('**/api/v1/timelines/home*', (route) => route.fulfill({
			status: 503,
			contentType: 'application/json',
			body: JSON.stringify({ error: 'Temporarily unavailable' }),
		}))
		await openApp(page)
		const card = page.locator('.timeline-error')
		await expect(card.getByRole('heading', { name: /^Could not load (more )?posts$/ })).toBeVisible()
		for (const width of [1280, 320]) {
			await page.setViewportSize({ width, height: 900 })
			await expect(card.getByRole('button', { name: 'Try again', exact: true })).toBeVisible()
			const overflow = await card.evaluate((element) => element.scrollWidth - element.clientWidth)
			expect(overflow).toBeLessThanOrEqual(1)
		}
		await page.unroute('**/api/v1/timelines/home*')
		const recovered = page.waitForResponse((response) => response.url().includes('/api/v1/timelines/home') && response.ok())
		await card.getByRole('button', { name: 'Try again', exact: true }).click()
		await recovered
		await expect(card).toHaveCount(0)
	} finally {
		await api.dispose()
	}
})
