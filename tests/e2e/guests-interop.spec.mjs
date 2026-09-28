/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { execFileSync } from 'node:child_process'
import { expect, test } from '@playwright/test'
import { APP, PASSWORD, login, openApp } from './helpers.mjs'

test('Guests accounts can enter Social only when the Guests allowlist permits it', async ({ page }) => {
	test.skip(process.env.E2E_GUEST !== 'guest', 'the Guests app is installed in the Nextcloud 35 compatibility job')

	await login(page, 'guest', PASSWORD)

	const blocked = await page.goto(APP)
	expect(blocked?.status()).toBe(403)

	// Change the real Guests app configuration as an administrator would. The
	// app's always-available settings/core routes keep the guest session alive.
	execFileSync('php', ['../../occ', 'config:app:set', 'guests', 'whitelist', '--value=social'])
	await openApp(page)
	await expect(page.locator('.app-navigation').first()).toBeVisible()
})
