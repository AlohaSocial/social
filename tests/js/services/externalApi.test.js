/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it } from 'vitest'
import { confirmPassword, externalUrl, invitesUrl, signupUrl } from '../../../src/services/externalApi.js'

describe('the external users routes', () => {
	afterEach(() => {
		delete window.OC.PasswordConfirmation
	})

	it('spells each route once', () => {
		expect(externalUrl('/users')).toBe('/index.php/apps/social/admin/external/users')
		expect(signupUrl()).toBe('/index.php/apps/social/signup')
		expect(invitesUrl('/3')).toBe('/index.php/apps/social/invites/3')
	})

	it('asks for the password through Nextcloud\'s own dialog', async () => {
		let asked = 0
		window.OC.PasswordConfirmation = {
			requirePasswordConfirmation: (callback) => {
				asked++
				callback()
			},
		}

		await confirmPassword()

		expect(asked).toBe(1)
	})

	it('gives up when the dialog is dismissed', async () => {
		window.OC.PasswordConfirmation = {
			requirePasswordConfirmation: (callback, options, rejected) => rejected(),
		}

		await expect(confirmPassword()).rejects.toThrow()
	})
})
