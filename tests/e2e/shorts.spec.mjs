/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { APP, BYSTANDER_PASSWORD, PASSWORD, USER, apiAs, ensureAccount, ensureUser, login, openApp, postStatus } from './helpers.mjs'

/** A Nextcloud user of this spec's own, who follows nobody and has posted nothing. */
const SHORTLESS = 'shortless'

/**
 * Small, checked-in test-pattern clips avoid generating media on the runner.
 *
 * @param {'portrait'|'landscape'} orientation
 * @return {Buffer}
 */
function clip(orientation) {
	return readFileSync(new URL(`../Interop/fixtures/shorts-${orientation}.mp4`, import.meta.url))
}

/**
 * The Shorts stack, read in a browser: an upright video of a few seconds is
 * a short and is on it, a landscape video and a post of text are not, and a
 * reader with no shorts in their feed is told what a short is.
 *
 * The checked-in videos are measured by the server, which needs ffmpeg and
 * ffprobe; when either is missing the test skips the media-specific checks.
 */
test.describe('Shorts, in a browser', () => {
	const run = Date.now()
	const markers = { short: `An upright short ${run}`, landscape: `A landscape video ${run}`, text: `Only words ${run}` }
	/** @type {string[]} */
	const posted = []
	/** @type {object|null} */
	let short = null
	let measured = true

	test.beforeAll(async () => {
		const upright = clip('portrait')
		const wide = clip('landscape')

		const api = await apiAs(USER, PASSWORD)
		await ensureAccount(api, USER)

		/**
		 * @param {Buffer} buffer
		 * @return {Promise<object>} the MediaAttachment
		 */
		const upload = async (buffer) => {
			const response = await api.post(`${APP}/api/v2/media`, {
				multipart: { file: { name: 'clip.mp4', mimeType: 'video/mp4', buffer } },
			})
			expect(response.ok(), `a video can be uploaded: ${await response.text()}`).toBe(true)
			return response.json()
		}

		const landscapeMedia = await upload(wide)
		const shortMedia = await upload(upright)
		measured = [landscapeMedia, shortMedia].every((media) => (media.meta?.duration ?? 0) > 0 && (media.meta?.original?.height ?? 0) > 0)

		// oldest first, so the short is the newest of the three
		posted.push((await postStatus(api, { status: markers.landscape, media_ids: [landscapeMedia.id] })).id)
		posted.push((await postStatus(api, { status: markers.text })).id)
		short = await postStatus(api, { status: markers.short, media_ids: [shortMedia.id] })
		posted.push(short.id)
		await api.dispose()
	})

	test.afterAll(async () => {
		if (posted.length === 0) {
			return
		}
		const api = await apiAs(USER, PASSWORD)
		for (const id of posted) {
			await api.delete(`${APP}/api/v1/statuses/${id}`)
		}
		await api.dispose()
	})

	test('an upright video is a short on the stack, a landscape video and a post of text are not', async ({ page }) => {
		test.skip(!measured, 'needs ffmpeg to make the videos and the server\'s ffprobe to measure them')

		await login(page)
		await openApp(page, '/shorts?scope=timeline')

		const slide = page.locator('article.short').filter({ hasText: markers.short })
		await expect(slide, 'the short is on the stack').toBeVisible()
		await expect(page.locator('article.short').first(), 'the newest short comes first').toContainText(markers.short)
		await expect(page.locator('video.short__video'), 'and it is the one in the player')
			.toHaveAttribute('src', short.media_attachments[0].url)

		await expect(page.locator('article.short').filter({ hasText: markers.landscape }), 'a landscape video is no short').toHaveCount(0)
		await expect(page.locator('article.short').filter({ hasText: markers.text }), 'nor is a post of text').toHaveCount(0)
	})

	test('visible controls retain the clip when paging fails', async ({ page }) => {
		test.skip(!measured, 'needs measured video fixtures')
		let first = true
		await page.route('**/api/v1/timelines/public*', (route) => {
			if (new URL(route.request().url()).searchParams.get('only_short') !== 'true') {
				return route.fallback()
			}
			if (first) {
				first = false
				return route.fulfill({ contentType: 'application/json', body: JSON.stringify([short]) })
			}
			return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ error: 'Temporary failure' }) })
		})
		await login(page)
		await openApp(page, '/shorts?scope=timeline')
		await expect(page.getByRole('group', { name: 'Short playback' })).toBeVisible()
		await expect(page.locator('.shorts__page-error')).toContainText('current short is still available')
		await expect(page.locator('article.short')).toHaveCount(1)
		await expect(page.locator('.shorts__page-error').getByRole('button', { name: 'Try again' })).toBeEnabled()
	})

	test('a reader with no shorts in their feed is told what a short is', async ({ page }) => {
		test.skip(!await ensureUser(SHORTLESS, BYSTANDER_PASSWORD) && !process.env.CI, 'needs the administrator to create an account of its own')
		const api = await apiAs(SHORTLESS, BYSTANDER_PASSWORD)
		await ensureAccount(api, SHORTLESS)
		await api.dispose()

		await login(page, SHORTLESS, BYSTANDER_PASSWORD)
		await openApp(page, '/shorts?scope=home')

		await expect(page.getByText('No shorts here yet.')).toBeVisible()
		await expect(page.getByText('A short is a video of up to three minutes, upright or square.')).toBeVisible()
		await expect(page.locator('article.short')).toHaveCount(0)
	})
})
