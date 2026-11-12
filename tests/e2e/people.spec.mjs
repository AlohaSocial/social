/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, request, test } from '@playwright/test'
import {
	APP,
	BASE_URL,
	BYSTANDER,
	BYSTANDER_PASSWORD,
	IGNORE_HTTPS_ERRORS,
	OTHER_PASSWORD,
	OTHER_USER,
	PASSWORD,
	USER,
	apiAs,
	ensureAccount,
	ensureUser,
	login,
	openApp,
	postStatus,
} from './helpers.mjs'

/**
 * The flows that need a second person: following somebody and letting them
 * go, writing to them privately, hearing from them, finding them, and
 * putting them in a list. The second person acts over the API; the reader
 * acts in the browser, and every result is checked from the other side too.
 */
test.describe('other people, in a browser', () => {
	/** @type {import('@playwright/test').APIRequestContext} */
	let me
	/** @type {import('@playwright/test').APIRequestContext} */
	let other
	/** @type {object} the second person's account */
	let otherAccount

	test.beforeAll(async () => {
		test.skip(USER === OTHER_USER, 'these flows need E2E_USER to be somebody other than the administrator')

		me = await apiAs(USER, PASSWORD)
		await ensureAccount(me, USER)
		other = await apiAs(OTHER_USER, OTHER_PASSWORD)
		otherAccount = await ensureAccount(other, OTHER_USER)
	})

	test.afterAll(async () => {
		await me?.dispose()
		await other?.dispose()
	})

	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	/** Whether the reader follows the second person, as the server says. */
	async function following() {
		const response = await me.get(`${APP}/api/v1/accounts/relationships?id[]=${otherAccount.id}`)
		expect(response.ok()).toBe(true)

		return (await response.json())[0]?.following === true
	}

	/** The reader follows the second person, or does not, before the test starts. */
	async function setFollowing(follow) {
		const response = await me.post(`${APP}/api/v1/accounts/${otherAccount.id}/${follow ? 'follow' : 'unfollow'}`)
		expect(response.ok()).toBe(true)
		expect(await following()).toBe(follow)
	}

	test('following somebody from their profile, and letting them go again', async ({ page }) => {
		await setFollowing(false)
		// the page follows by handle: PUT to follow, DELETE to let go
		const answered = (method) => page.waitForResponse((response) => response.url().includes('/api/v1/current/follow?')
			&& response.request().method() === method)

		await openApp(page, `/@${OTHER_USER}`)
		const profile = page.locator('.user-profile').first()
		let response = answered('PUT')
		await profile.getByRole('button', { name: 'Follow', exact: true }).click()
		expect((await response).ok()).toBe(true)
		const unfollow = profile.getByRole('button', { name: `Unfollow ${OTHER_USER}` })
		await expect(unfollow).toBeVisible()
		expect(await following(), 'the server has the follow').toBe(true)

		await page.reload()
		await expect(profile.getByRole('button', { name: `Unfollow ${OTHER_USER}` }), 'and the page reads it back').toBeVisible()

		// letting somebody go is asked about first
		await profile.getByRole('button', { name: `Unfollow ${OTHER_USER}` }).click()
		const dialog = page.getByRole('dialog', { name: `Unfollow ${OTHER_USER}?` })
		await expect(dialog).toBeVisible()
		response = answered('DELETE')
		await dialog.getByRole('button', { name: 'Unfollow', exact: true }).click()
		expect((await response).ok()).toBe(true)
		await expect(profile.getByRole('button', { name: 'Follow', exact: true })).toBeVisible()
		expect(await following(), 'the server let them go').toBe(false)
	})

	/**
	 * A direct message is the one post whose whole point is who cannot see
	 * it, so the check is made from three places: the recipient, somebody
	 * else on the same server, and nobody at all.
	 */
	test('a direct message reaches its recipient and nobody else', async ({ page }) => {
		test.skip(!await ensureUser(BYSTANDER, BYSTANDER_PASSWORD) && !process.env.CI, 'needs the administrator to create a bystander account')
		const bystander = await apiAs(BYSTANDER, BYSTANDER_PASSWORD)
		await ensureAccount(bystander, BYSTANDER)
		const text = `Just between us ${Date.now()}`

		await openApp(page, '/timeline/direct')
		await page.getByRole('button', { name: 'New message' }).first().click()
		await page.getByRole('searchbox', { name: 'Search for a person by name or @username' }).fill(OTHER_USER)
		await page.getByRole('button', { name: new RegExp(`^Start a conversation with ${OTHER_USER}`) }).first().click()
		await page.getByRole('textbox', { name: 'Write a message…' }).fill(text)
		await page.getByRole('button', { name: 'Send', exact: true }).click()
		await expect(page.locator('.direct-messages__thread, .direct-messages__message').filter({ hasText: text }).first())
			.toBeVisible()

		// the recipient has it, as a conversation with the sender
		const conversations = await (await other.get(`${APP}/api/v1/conversations`)).json()
		const conversation = conversations.find((entry) => entry.last_status?.content.includes(text))
		expect(conversation, 'the recipient has the conversation').toBeTruthy()
		expect(conversation.accounts.map((account) => account.username)).toContain(USER)
		const id = conversation.last_status.id
		expect(conversation.last_status.visibility).toBe('direct')

		// somebody else on the server has not, and cannot open it
		const theirs = await (await bystander.get(`${APP}/api/v1/conversations`)).json()
		expect(theirs.some((entry) => entry.last_status?.id === id), 'not in a bystander\'s conversations').toBe(false)
		expect((await bystander.get(`${APP}/api/v1/statuses/${id}`)).status(), 'nor readable by id').toBe(404)
		await bystander.dispose()

		// and neither has the internet: no session, no message
		const anonymous = await request.newContext({ baseURL: BASE_URL, ignoreHTTPSErrors: IGNORE_HTTPS_ERRORS })
		expect((await anonymous.get(`${APP}/api/v1/statuses/${id}`)).status()).toBe(404)
		expect((await anonymous.get(`${APP}/@${USER}/${id}`)).status()).toBe(404)
		await anonymous.dispose()
	})

	test('a like and a mention from somebody else arrive as notifications', async ({ page }) => {
		// from somebody the reader has let through: an account younger than a
		// month is held back as a request by default, which is another page
		const accepted = await me.post(`${APP}/api/v1/notifications/requests/${otherAccount.id}/accept`)
		expect(accepted.ok()).toBe(true)
		const mine = await postStatus(me, { status: `Something worth liking ${Date.now()}` })
		const liked = await other.post(`${APP}/api/v1/statuses/${mine.id}/favourite`)
		expect(liked.ok()).toBe(true)
		const mention = `@${USER} have you seen this ${Date.now()}`
		await postStatus(other, { status: mention })

		await openApp(page, '/timeline/notifications')
		const notifications = page.locator('.social__pages')
		await expect(notifications.getByText(mention.replace(`@${USER} `, '')).first(), 'the mention arrived').toBeVisible()
		await expect(notifications.getByText(mine.content.replace(/<[^>]+>/g, '')).first(), 'and the like, with the post it was for')
			.toBeVisible()
	})

	/**
	 * The hashtag is the one the workflow seeds. Hashtags are found from the
	 * table the twelve-minute cron fills, not from the posts, so a tag
	 * written during the run would not be found until that cron had run; the
	 * workflow writes a post with it and runs the refresh before the tests.
	 */
	test('an account and a hashtag can be searched for', async ({ page }) => {
		const tag = process.env.E2E_HASHTAG ?? 'alohasearch'
		const known = await (await me.get(`${APP}/api/v2/search?q=${encodeURIComponent(`#${tag}`)}`)).json()
		test.skip(known.hashtags.length === 0 && !process.env.CI, `seed #${tag} with occ social:debug:note:create and occ social:cache:refresh, as the workflow does`)

		await openApp(page)
		const search = page.getByRole('searchbox', { name: 'Search …' })
		const section = (name) => page.locator('.social__search-section').filter({ has: page.getByRole('heading', { name }) })

		await search.fill(OTHER_USER)
		await expect(page).toHaveURL(new RegExp(`/search/${OTHER_USER}$`))
		await expect(section('People'), 'the account is found').toContainText(OTHER_USER)

		await search.fill(`#${tag}`)
		const found = section('Hashtags').getByRole('link', { name: `#${tag}` })
		await expect(found, 'the hashtag is found').toBeVisible()
		await found.click()
		await expect(page).toHaveURL(new RegExp(`/timeline/tags/${tag}$`))
		await expect(page.locator('.social__timeline article').first(), 'and leads to the posts that carry it').toContainText(`#${tag}`)
	})

	test('a list is made, renamed, given a member, and deleted', async ({ page }) => {
		// only somebody the reader follows can be put in a list
		await setFollowing(true)
		const name = `Close friends ${Date.now()}`
		const renamed = `${name} (renamed)`
		const lists = async () => (await me.get(`${APP}/api/v1/lists`)).json()

		await openApp(page, '/settings#lists')
		const section = page.locator('.lists-settings')
		await expect(section).toBeVisible()

		await section.getByRole('textbox', { name: 'New list' }).fill(name)
		await section.getByRole('button', { name: 'Create', exact: true }).click()
		const row = section.locator('.lists-settings__item').filter({ hasText: name })
		await expect(row).toBeVisible()
		const list = (await lists()).find((entry) => entry.title === name)
		expect(list, 'the server has the list').toBeTruthy()

		await row.getByRole('button', { name: `More actions for ${name}` }).click()
		await page.getByRole('menuitem', { name: 'Rename' }).click()
		const title = section.getByRole('textbox', { name: 'Name of the list' })
		await title.fill(renamed)
		await section.getByRole('button', { name: 'Save', exact: true }).click()
		const renamedRow = section.locator('.lists-settings__item').filter({ hasText: renamed })
		await expect(renamedRow).toBeVisible()
		expect((await lists()).find((entry) => entry.id === list.id)?.title).toBe(renamed)

		await renamedRow.getByRole('button', { name: `Members of ${renamed}` }).click()
		await renamedRow.getByRole('textbox', { name: 'Add somebody you follow' }).fill(OTHER_USER)
		await renamedRow.locator('.lists-settings__result').filter({ hasText: `@${OTHER_USER}` }).first().click()
		await expect(renamedRow.locator('.lists-settings__member').filter({ hasText: `@${OTHER_USER}` })).toBeVisible()
		const members = await (await me.get(`${APP}/api/v1/lists/${list.id}/accounts`)).json()
		expect(members.map((account) => account.username), 'the server has the member').toContain(OTHER_USER)

		await renamedRow.getByRole('button', { name: `More actions for ${renamed}` }).click()
		await page.getByRole('menuitem', { name: 'Delete' }).click()
		await page.getByRole('dialog').getByRole('button', { name: 'Delete', exact: true }).click()
		await expect(section.locator('.lists-settings__item').filter({ hasText: renamed })).toHaveCount(0)
		expect((await lists()).some((entry) => entry.id === list.id), 'the server let it go').toBe(false)
	})
})
