/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'session-token' }))

describe('logging in through core\'s login form', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		document.body.innerHTML = ''
	})

	it('posts the login, the password, this session\'s token and the way back to core\'s login route', async () => {
		const submit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {})
		const { submitCoreLogin } = await import('../../../src/utils/coreLogin.js')

		const form = submitCoreLogin({ user: 'alice', password: 'long enough', redirectUrl: '/index.php/apps/social/oauth/authorize?client_id=app' })

		expect(submit).toHaveBeenCalledOnce()
		expect(form.method).toBe('post')
		expect(new URL(form.action).pathname).toBe('/index.php/login')
		expect(form.isConnected).toBe(true)
		const fields = Object.fromEntries(new FormData(form))
		expect(fields).toMatchObject({
			user: 'alice',
			password: 'long enough',
			requesttoken: 'session-token',
			redirect_url: '/index.php/apps/social/oauth/authorize?client_id=app',
		})
		expect(fields.timezone_offset).toMatch(/^-?\d+(\.\d+)?$/)
	})

	it('leaves the way back to core where there is none', async () => {
		vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {})
		const { submitCoreLogin } = await import('../../../src/utils/coreLogin.js')

		const form = submitCoreLogin({ user: 'alice', password: 'long enough' })

		expect(form.querySelector('[name="redirect_url"]')).toBeNull()
	})

	it('runs on a page that sends its origin with the POST', () => {
		// core's login refuses a POST whose Origin is not trusted, and the
		// server-wide `no-referrer` policy makes it `null`; core's own login
		// page lifts that with the same meta tag
		const source = readFileSync(resolve(process.cwd(), 'templates/signup.php'), 'utf8')

		expect(source).toMatch(/Util::addHeader\('meta', \['name' => 'referrer', 'content' => 'same-origin'\]\)/)
	})
})
