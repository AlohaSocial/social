/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/* eslint-disable no-console -- the console is what the logger writes to, and what this suite checks */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { getCurrentUser } = vi.hoisted(() => ({ getCurrentUser: vi.fn() }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser }))

async function loadLogger() {
	vi.resetModules()
	const { default: logger } = await import('../../../src/services/logger.js')
	return logger
}

describe('logger service', () => {
	beforeEach(() => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		vi.spyOn(console, 'warn').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('prefixes messages with the social app name', async () => {
		getCurrentUser.mockReturnValue(null)
		const logger = await loadLogger()

		logger.error('Failed to like status', { code: 42 })
		logger.warn('Could not find status in timeline', { statusId: '7' })

		expect(console.error).toHaveBeenCalledWith('[ERROR] social: Failed to like status', expect.objectContaining({ app: 'social', code: 42 }))
		expect(console.warn).toHaveBeenCalledWith('[WARN] social: Could not find status in timeline', expect.objectContaining({ app: 'social', statusId: '7' }))
	})

	it('adds the current user id to the logging context when someone is logged in', async () => {
		getCurrentUser.mockReturnValue({ uid: 'alice', displayName: 'Alice', isAdmin: false })
		const logger = await loadLogger()

		logger.error('boom')

		expect(console.error).toHaveBeenCalledWith('[ERROR] social: boom', expect.objectContaining({ app: 'social', uid: 'alice' }))
	})

	it('omits the user id on public pages', async () => {
		getCurrentUser.mockReturnValue(null)
		const logger = await loadLogger()

		logger.error('boom')

		const context = console.error.mock.calls[0][1]
		expect(context.app).toBe('social')
		expect(context).not.toHaveProperty('uid')
	})

	it('attaches an error passed as context to the log entry', async () => {
		getCurrentUser.mockReturnValue(null)
		const logger = await loadLogger()
		const error = new Error('network down')

		logger.error('Failed to create a status', { error })

		expect(console.error).toHaveBeenCalledWith('[ERROR] social: Failed to create a status', expect.objectContaining({ error }))
	})
})
