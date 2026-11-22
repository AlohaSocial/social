/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, request } from '@playwright/test'

export const USER = process.env.E2E_USER ?? 'admin'
export const PASSWORD = process.env.E2E_PASSWORD ?? 'admin'

/**
 * The account on the other end of the flows that need two people: it
 * follows, is followed, likes, mentions and is written to. The
 * administrator, because every instance has one and its password is the one
 * the workflow installs with.
 */
export const OTHER_USER = process.env.E2E_ADMIN_USER ?? 'admin'
export const OTHER_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'admin'

/**
 * A bystander: an account that is neither end of a conversation, made by the
 * administrator through Nextcloud's provisioning API when it is missing.
 */
export const BYSTANDER = process.env.E2E_BYSTANDER ?? 'carol'
export const BYSTANDER_PASSWORD = process.env.E2E_BYSTANDER_PASSWORD ?? 'Browser-Test-2026!'

/** With a trailing slash, as playwright.config.mjs explains. */
export const BASE_URL = (process.env.E2E_BASE_URL ?? 'http://localhost:8080').replace(/\/*$/, '/')

/**
 * Whether to accept a certificate nobody vouches for, as an instance served
 * over HTTPS with a certificate made for the run has (E2E_IGNORE_HTTPS_ERRORS=1).
 * The browser's contexts take it from playwright.config.mjs; an API context
 * made with `request.newContext()` has to be given it.
 */
export const IGNORE_HTTPS_ERRORS = process.env.E2E_IGNORE_HTTPS_ERRORS === '1'

/** Pages whose Nextcloud first-run overlay has already been handled. */
const wizardHandled = new WeakSet()

/** Where the app lives, whether or not the instance has pretty URLs. */
export const APP = 'index.php/apps/social'

/**
 * Signs in through Nextcloud's own login form, the way a person does.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} user
 * @param {string} password
 */
export async function login(page, user = USER, password = PASSWORD) {
	await page.goto('index.php/login')
	await page.locator('#user').fill(user)
	await page.locator('#password').fill(password)
	await page.locator('form[name="login"] button[type="submit"], #submit-form, button[type="submit"]').first().click()
	// the dashboard, or wherever the instance sends a fresh session
	await expect(page).not.toHaveURL(/\/login/)
}

/**
 * Opens the app and waits for its sidebar, answering the setup screen and
 * closing the first-run introduction if this account has never been here
 * before.
 *
 * The setup screen comes first and there is no app behind it: an account is
 * made when somebody asks for one, so a Nextcloud user who has never opened
 * Aloha Social has no actor and no sidebar. Taking the handle it offers is what a
 * person does, and doing it here means the run exercises that path rather
 * than working around it.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} path inside the app, e.g. '/discover'
 */
export async function openApp(page, path = '/') {
	// Nextcloud's own first-run wizard is an overlay that covers the page on
	// an account nobody has opened yet, and it is drawn after the app's own
	// shell. Hand it to Playwright so any action that meets it removes it.
	if (!wizardHandled.has(page)) {
		wizardHandled.add(page)
		await page.addLocatorHandler(page.locator('#firstrunwizard[role="dialog"]'), async () => {
			await page.evaluate(() => document.querySelectorAll('#firstrunwizard').forEach((element) => element.remove()))
		})
	}
	await page.goto(APP + path)

	const create = page.getByRole('button', { name: 'Create account', exact: true })
	// the first paint of the app on a cold built-in server takes a while, and
	// this is the first of them: either screen appearing ends the wait
	await expect(create.or(page.locator('.app-navigation').first())).toBeVisible({ timeout: 45_000 })
	if (await create.isVisible().catch(() => false)) {
		await create.click()
		// the page reloads into the app once the account exists
		await expect(page.locator('.app-navigation').first()).toBeVisible({ timeout: 45_000 })
		if (path !== '/') {
			await page.goto(APP + path)
			await expect(page.locator('.app-navigation').first()).toBeVisible({ timeout: 45_000 })
		}
	}

	const skip = page.locator('.first-run__skip')
	if (await skip.isVisible().catch(() => false)) {
		await skip.click()
	}
}

/** The sidebar entry with this name. */
export function navEntry(page, name) {
	return page.locator('.app-navigation-entry').filter({ hasText: name }).first()
}

/**
 * The app's API as one account, for the seeding a test needs before it can
 * show anything: posts to act on, a follow to build a list from, a like that
 * makes a notification.
 *
 * Basic auth on every request, with the header that tells Nextcloud this is
 * an API call: a request without a session cookie needs no CSRF token, and
 * the app's routes take the Nextcloud user the same way the page does.
 *
 * @param {string} user
 * @param {string} password
 * @return {Promise<import('@playwright/test').APIRequestContext>}
 */
export async function apiAs(user, password) {
	return request.newContext({
		baseURL: BASE_URL,
		ignoreHTTPSErrors: IGNORE_HTTPS_ERRORS,
		httpCredentials: { username: user, password, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIREQUEST': 'true' },
	})
}

/**
 * The account behind this API context, made the way the setup screen makes
 * one if the Nextcloud user has never opened the app.
 *
 * @param {import('@playwright/test').APIRequestContext} api
 * @param {string} user the Nextcloud user id, taken as the handle
 * @return {Promise<object>} the Mastodon Account
 */
export async function ensureAccount(api, user) {
	const me = await api.get(`${APP}/api/v1/accounts/verify_credentials`)
	if (me.ok()) {
		return me.json()
	}

	const created = await api.post(`${APP}/api/v1/account/create`, { data: { username: user } })
	expect(created.ok(), `an account for ${user} could be made`).toBe(true)

	return (await created.json()).result.account
}

/**
 * A Nextcloud user, created by the administrator if it does not exist yet.
 *
 * @param {string} user
 * @param {string} password
 * @return {Promise<boolean>} whether the user exists now
 */
export async function ensureUser(user, password) {
	const admin = await apiAs(OTHER_USER, OTHER_PASSWORD)
	try {
		const response = await admin.post('ocs/v2.php/cloud/users?format=json', {
			form: { userid: user, password },
		})
		const body = await response.json().catch(() => null)
		// 102 is the provisioning API's "already exists"
		return response.ok() || body?.ocs?.meta?.statuscode === 102
	} finally {
		await admin.dispose()
	}
}

/**
 * A post written over the API, as the client API answers it.
 *
 * @param {import('@playwright/test').APIRequestContext} api
 * @param {object} status the body of `POST /api/v1/statuses`
 * @return {Promise<object>} the Mastodon Status
 */
export async function postStatus(api, status) {
	const response = await api.post(`${APP}/api/v1/statuses`, { data: { visibility: 'public', ...status } })
	expect(response.ok(), `the post was accepted: ${await response.text()}`).toBe(true)

	return response.json()
}

/**
 * Every page the sidebar leads to, and the account pages around them, each
 * with an element it draws once it is there: the pages a check that walks
 * the whole app walks. A post page needs a post, so it is not in here.
 *
 * @param {string} profile the handle whose profile page to include
 * @return {{name: string, path: string, marker: string}[]}
 */
export function appPages(profile = OTHER_USER) {
	return [
		{ name: 'my-feed', path: '/timeline', marker: '.social__timeline' },
		{ name: 'local', path: '/timeline/timeline', marker: '.social__timeline' },
		{ name: 'global', path: '/timeline/federated', marker: '.social__timeline' },
		{ name: 'photos', path: '/timeline/photos', marker: '.social__wrapper' },
		{ name: 'videos', path: '/timeline/videos', marker: '.social__wrapper' },
		{ name: 'shorts', path: '/shorts', marker: '.shorts' },
		{ name: 'activities', path: '/timeline/notifications', marker: '.social__wrapper' },
		{ name: 'direct-messages', path: '/timeline/direct', marker: '.direct-messages' },
		{ name: 'discover', path: '/discover', marker: '.discover' },
		{ name: 'profile', path: `/@${profile}`, marker: '.user-profile' },
		{ name: 'liked-posts', path: '/timeline/favourites', marker: '.social__wrapper' },
		{ name: 'bookmarks', path: '/timeline/bookmarks', marker: '.social__wrapper' },
		{ name: 'statistics', path: '/statistics', marker: '.stats' },
		{ name: 'blocking', path: '/blocked', marker: '.social__blocked' },
		{ name: 'migration', path: '/migration', marker: '.settings .migration' },
		{ name: 'settings', path: '/settings', marker: '.settings' },
		{ name: 'search', path: '/search/alohasearch', marker: '.social__wrapper' },
	]
}

/**
 * Opens one of `appPages()` and waits until it has stopped loading: its
 * marker drawn, the requests it made answered and no spinner left in the
 * app's content, so what is checked next is the page and not its skeleton.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{path: string, marker: string}} target
 */
export async function openAppPage(page, target) {
	await openApp(page, target.path)
	await expect(page.locator(target.marker).first(), `${target.path} drew its content`).toBeVisible()
	// a page that polls never goes quiet for long; what it loaded first has
	// arrived by then either way
	await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => {})
	await expect(page.locator('.social__pages .loading-icon, .social__pages .icon-loading'), `${target.path} finished loading`)
		.toHaveCount(0, { timeout: 15_000 })
}
