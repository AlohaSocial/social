/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// what the module registered on import, kept outside any mock's call history
const { registered } = vi.hoisted(() => ({ registered: [] }))
vi.mock('@nextcloud/files', () => ({
	FileType: { File: 'file', Folder: 'folder' },
	registerFileAction: (action) => registered.push(action),
}))

import { composeUrl, isShareable, shareAction, shareToSocial } from '../../src/filesAction.js'
import { DEFAULT_LIMITS, loadLimits, resetLimitsForTests } from '../../src/services/instanceLimits.js'

const MAX_ATTACHMENTS = DEFAULT_LIMITS.maxAttachments

/** what the server answers to GET /api/v1/instance, as fetch() hands it over */
function instanceSaying(maxMediaAttachments) {
	return vi.fn(async () => ({
		ok: true,
		json: async () => ({ configuration: { statuses: { max_media_attachments: maxMediaAttachments } } }),
	}))
}

const file = (path, mime = 'image/jpeg', type = 'file') => ({ path, mime, type, basename: path.split('/').pop() })

describe('Share to Aloha Social in the Files app', () => {
	it('registers itself once, under a stable id', () => {
		expect(registered).toEqual([shareAction])
		expect(shareAction.id).toBe('social-share')
		expect(shareAction.displayName({ nodes: [] })).toBe('Share to Aloha Social')
		expect(shareAction.iconSvgInline({ nodes: [] })).toMatch(/^<svg/)
	})

	it('is offered for pictures and videos, and for nothing else', () => {
		expect(isShareable(file('/Photos/beach.jpg'))).toBe(true)
		expect(isShareable(file('/Videos/talk.mp4', 'video/mp4'))).toBe(true)
		// a post cannot carry these
		expect(isShareable(file('/Documents/notes.pdf', 'application/pdf'))).toBe(false)
		expect(isShareable(file('/Photos', 'httpd/unix-directory', 'folder'))).toBe(false)
		expect(isShareable({ path: '/x', type: 'file' })).toBe(false)
	})

	it('is offered for a selection only when every file qualifies and a post can carry them all', () => {
		expect(shareAction.enabled({ nodes: [file('/a.jpg')] })).toBe(true)
		expect(shareAction.enabled({ nodes: [file('/a.jpg'), file('/b.mp4', 'video/mp4')] })).toBe(true)
		// one document in the selection and the whole action goes: an action
		// that would have to refuse half of what was picked is worse than none
		expect(shareAction.enabled({ nodes: [file('/a.jpg'), file('/c.pdf', 'application/pdf')] })).toBe(false)
		expect(shareAction.enabled({ nodes: [] })).toBe(false)
		const tooMany = Array.from({ length: MAX_ATTACHMENTS + 1 }, (_, i) => file(`/${i}.jpg`))
		expect(shareAction.enabled({ nodes: tooMany })).toBe(false)
		expect(shareAction.enabled({ nodes: tooMany.slice(0, MAX_ATTACHMENTS) })).toBe(true)
	})

	describe('how many files a post can carry', () => {
		// the tests above already asked, against a fetch nobody answered
		beforeEach(resetLimitsForTests)

		afterEach(() => {
			resetLimitsForTests()
			vi.unstubAllGlobals()
		})

		it('takes the ceiling from the server once it has answered, and the old constant until then', async () => {
			vi.stubGlobal('fetch', instanceSaying(4))
			const five = Array.from({ length: 5 }, (_, i) => file(`/${i}.jpg`))

			// the first answer cannot wait for the server; it is the fallback
			expect(shareAction.enabled({ nodes: five })).toBe(true)
			expect(fetch).toHaveBeenCalledWith(expect.stringContaining('/api/v1/instance/'), expect.anything())
			// the same request the action sent; awaiting it is awaiting the answer
			await loadLimits()

			// and once the server has said four, five is too many
			expect(shareAction.enabled({ nodes: five })).toBe(false)
			expect(shareAction.enabled({ nodes: five.slice(0, 4) })).toBe(true)
		})

		it('asks the server once, and not for a single file', () => {
			vi.stubGlobal('fetch', instanceSaying(10))

			// one picture is a post whatever the ceiling: no request for that
			shareAction.enabled({ nodes: [file('/a.jpg')] })
			expect(fetch).not.toHaveBeenCalled()

			shareAction.enabled({ nodes: [file('/a.jpg'), file('/b.jpg')] })
			shareAction.enabled({ nodes: [file('/a.jpg'), file('/b.jpg'), file('/c.jpg')] })
			expect(fetch).toHaveBeenCalledTimes(1)
		})

		it('keeps the old constant when the server cannot be asked', async () => {
			vi.stubGlobal('fetch', vi.fn(async () => {
				throw new Error('offline')
			}))
			const ten = Array.from({ length: MAX_ATTACHMENTS }, (_, i) => file(`/${i}.jpg`))

			shareAction.enabled({ nodes: ten })
			await loadLimits()

			expect(shareAction.enabled({ nodes: ten })).toBe(true)
			expect(shareAction.enabled({ nodes: [...ten, file('/more.jpg')] })).toBe(false)
		})
	})

	it('sends the browser to the composer with one attach parameter per file', () => {
		const url = composeUrl([file('/Photos/beach day.jpg'), file('/Videos/talk.mp4', 'video/mp4')])

		expect(url.startsWith('/index.php/apps/social/timeline/home?')).toBe(true)
		const query = new URL(url, 'https://cloud.example.org').searchParams
		expect(query.getAll('attach')).toEqual(['/Photos/beach day.jpg', '/Videos/talk.mp4'])
	})

	it('answers the Files app silently, once per file, after leaving', async () => {
		const navigate = vi.fn()

		expect(shareToSocial([file('/a.jpg'), file('/b.jpg')], navigate)).toEqual([null, null])
		expect(navigate).toHaveBeenCalledWith(composeUrl([file('/a.jpg'), file('/b.jpg')]))

		// the Files app calls one or the other depending on the selection
		expect(await shareAction.execBatch({ nodes: [] })).toEqual([])
	})
})
