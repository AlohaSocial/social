/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { APP, OTHER_PASSWORD, OTHER_USER, PASSWORD, USER, apiAs, ensureAccount, login, openApp, postStatus } from './helpers.mjs'

/** A 64×48 PNG: a yellow disc on blue, small enough to inline. */
const PICTURE = 'iVBORw0KGgoAAAANSUhEUgAAAEAAAAAwCAIAAAAuKetIAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAyklEQVRo3u2Z3RFAQAyE3Y46PClICcpQjhKUoQjFKIAHLpuLjM2jMZv9JPfjrgzL3mUOdMlDAAIQgAAEYIreSfeY5uvDcVvpiQp3Jb717UqC9u5fvdmoAtWG7KVAoHtKKRDonqKAWPd2nR8vZMSZxKKGL7i3aGovlBHAo3+qldVCAhCAAPIBePwZViurhZICeHRRnSYa56Or/XsMsIpg0UFgbooCYr+fnZ95tPhqN89qP+Ygfu6JOIMVp2vWrKfT2koIQAACEECyOAGIe0GRnAAnVwAAAABJRU5ErkJggg=='

/**
 * Writing a post with more in it than words, and the three things a reader
 * does to somebody else's: votes in it, answers it, passes it on. Each is
 * checked twice — on the page, and in what the server says afterwards — since
 * a button that changes colour proves only that the page believed it.
 */
test.describe('posting, and acting on a post', () => {
	/** @type {import('@playwright/test').APIRequestContext} */
	let me
	/** @type {import('@playwright/test').APIRequestContext} */
	let other

	test.beforeAll(async () => {
		me = await apiAs(USER, PASSWORD)
		await ensureAccount(me, USER)
		other = await apiAs(OTHER_USER, OTHER_PASSWORD)
		await ensureAccount(other, OTHER_USER)
	})

	test.afterAll(async () => {
		await me?.dispose()
		await other?.dispose()
	})

	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	/** The same status, read back by the account of this API context. */
	async function statusAs(api, id) {
		const response = await api.get(`${APP}/api/v1/statuses/${id}`)
		expect(response.ok()).toBe(true)

		return response.json()
	}

	/** One post on the page, by the words in it. */
	function postWith(page, text) {
		return page.locator('article').filter({ hasText: text }).first()
	}

	test('a picture is uploaded, described, and posted with its description', async ({ page }) => {
		await openApp(page)
		const text = `A picture from the browser test ${Date.now()}`
		const alt = `A yellow disc on blue ${Date.now()}`

		await page.locator('.navigation__compose').click()
		const composer = page.locator('.modal-composer')
		await expect(composer).toBeVisible()
		await composer.locator('.message').click()
		await page.keyboard.type(text)

		await composer.locator('input[type="file"]').setInputFiles({
			name: 'e2e-picture.png',
			mimeType: 'image/png',
			buffer: Buffer.from(PICTURE, 'base64'),
		})
		const description = composer.getByLabel('Describe this for people who cannot see it')
		await expect(description).toBeVisible()
		// the filters are offered once the upload has landed
		await expect(composer.locator('.preview-item__filters')).toBeVisible()
		const preview = composer.locator('.preview-item img.attachment__preview').first()
		await expect(preview, 'the picture itself is drawn, not only its filter swatches').toBeVisible()
		await description.fill(alt)
		await composer.getByRole('button', { name: /^Post(?! as)/ }).click()
		await expect(composer).toBeHidden()

		const post = postWith(page, text)
		await expect(post.locator(`img[alt="${alt}"]`).first(), 'the picture is shown with its description').toBeVisible()

		const id = await post.getAttribute('data-social-status')
		const status = await statusAs(me, id)
		expect(status.media_attachments).toHaveLength(1)
		expect(status.media_attachments[0].type).toBe('image')
		expect(status.media_attachments[0].description, 'the server kept the description').toBe(alt)
	})

	test('a vote in a poll is counted, and stays counted', async ({ page }) => {
		const question = `Tea or coffee? ${Date.now()}`
		const status = await postStatus(other, {
			status: question,
			poll: { options: ['Tea', 'Coffee'], expires_in: 86400 },
		})

		await openApp(page, `/@${OTHER_USER}/${status.id}`)
		const poll = postWith(page, question).locator('.poll')
		await expect(poll.getByRole('button', { name: 'Vote' })).toBeDisabled()
		await poll.getByLabel('Coffee').check()
		await poll.getByRole('button', { name: 'Vote' }).click()

		const coffee = poll.locator('.poll__result').filter({ hasText: 'Coffee' })
		await expect(coffee).toContainText('100%')
		await expect(poll.locator('.poll__footer')).toContainText('1 vote')

		await page.reload()
		const counted = postWith(page, question).locator('.poll__result').filter({ hasText: 'Coffee' })
		await expect(counted, 'the vote came back from the server').toContainText('100%')

		const seen = await statusAs(other, status.id)
		expect(seen.poll.votes_count, 'the author sees the vote').toBe(1)
		expect(seen.poll.options.map((option) => option.votes_count)).toEqual([0, 1])
	})

	test('a reply is written from the post, and shows under it in the thread', async ({ page }) => {
		const question = `Who is reading this? ${Date.now()}`
		const parent = await postStatus(other, { status: question })
		const answer = `I am, from the browser ${Date.now()}`

		await openApp(page, `/@${OTHER_USER}/${parent.id}`)
		await postWith(page, question).getByRole('button', { name: 'Reply', exact: true }).click()
		// The common modal receives the reply context before its lazy editor loads.
		const composer = page.locator('.modal-composer .new-post').filter({ hasText: `@${OTHER_USER}` })
		await expect(composer).toBeVisible()
		// pressing Reply puts the caret in the box, after the mention
		await expect(composer.locator('.message')).toBeFocused()
		await page.keyboard.type(answer)
		await composer.getByRole('button', { name: /^(Post|Reply)(?! as)/ }).click()
		// The reply box remains on the post page as a cleared one-line entry
		// point; only the expanded modal closes after a successful submission.
		await expect(page.locator('.modal-composer')).toBeHidden()
		await expect(page.locator('.main-post__under .new-post .message')).toBeEmpty()

		// the thread as anybody opening the post gets it
		await page.reload()
		await expect(postWith(page, question)).toBeVisible()
		await expect(postWith(page, answer), 'the reply is in the thread').toBeVisible()

		const context = await (await other.get(`${APP}/api/v1/statuses/${parent.id}/context`)).json()
		const reply = context.descendants.find((status) => status.content.includes(answer))
		expect(reply, 'the server threads the reply under its parent').toBeTruthy()
		expect(reply.in_reply_to_id).toBe(parent.id)
		expect(reply.account.username).toBe(USER)
	})

	test('a boost sticks, and can be taken back', async ({ page }) => {
		const text = `Worth passing on ${Date.now()}`
		const status = await postStatus(other, { status: text })
		const answered = (path) => page.waitForResponse((response) => response.url().endsWith(`/statuses/${status.id}/${path}`)
			&& response.request().method() === 'POST')
		/** the button, with the pointer on the post that shows it */
		const button = async (name) => {
			const post = postWith(page, text)
			await post.hover()
			return post.getByRole('button', { name, exact: true })
		}

		await openApp(page, `/@${OTHER_USER}/${status.id}`)
		let response = answered('reblog')
		await (await button('Boost')).click()
		expect((await response).ok()).toBe(true)
		await expect(await button('Undo boost')).toHaveAttribute('aria-pressed', 'true')

		await page.reload()
		await expect(await button('Undo boost'), 'the server kept the boost').toHaveAttribute('aria-pressed', 'true')
		expect((await statusAs(me, status.id)).reblogged).toBe(true)
		// the author can see who passed it on; the count itself is hidden by
		// default, so it is not the thing to read
		const boosters = await (await other.get(`${APP}/api/v1/statuses/${status.id}/reblogged_by`)).json()
		expect(boosters.map((account) => account.username)).toContain(USER)

		response = answered('unreblog')
		await (await button('Undo boost')).click()
		expect((await response).ok()).toBe(true)
		await expect(await button('Boost')).toHaveAttribute('aria-pressed', 'false')

		await page.reload()
		await expect(await button('Boost'), 'and the server let it go').toHaveAttribute('aria-pressed', 'false')
		expect((await statusAs(me, status.id)).reblogged).toBe(false)
	})
})
