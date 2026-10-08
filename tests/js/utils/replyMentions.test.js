/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { fullHandle, mentionPills, participantsOf } from '../../../src/utils/replyMentions.js'

describe('fullHandle', () => {
	it('adds this server to a local handle', () => {
		expect(fullHandle('alice', 'cloud.example')).toBe('alice@cloud.example')
	})

	it('leaves a remote handle as it is', () => {
		expect(fullHandle('bob@remote.example', 'cloud.example')).toBe('bob@remote.example')
	})

	it('takes the account itself, and never adds this server to a Bluesky handle', () => {
		expect(fullHandle({ acct: 'alice' }, 'cloud.example')).toBe('alice@cloud.example')
		expect(fullHandle({ acct: 'dana.bsky.social', bluesky: { native: true } }, 'cloud.example')).toBe('dana.bsky.social')
		expect(fullHandle({ acct: 'alice', bluesky: { native: false, active: true } }, 'cloud.example')).toBe('alice@cloud.example')
	})
})

describe('participantsOf', () => {
	const post = {
		account: { acct: 'bob@remote.example', url: 'https://remote.example/@bob' },
		mentions: [
			{ acct: 'alice', url: 'https://cloud.example/@alice' },
			{ acct: 'carol@third.example', url: 'https://third.example/@carol' },
			{ acct: 'Carol@third.example', url: 'https://third.example/@carol' },
			{ acct: '', url: 'https://nowhere.example' },
		],
	}

	it('is the author, then everyone mentioned, each once and never the reader', () => {
		expect(participantsOf(post, 'alice', 'cloud.example').map((account) => account.acct))
			.toEqual(['bob@remote.example', 'carol@third.example'])
	})

	it('copes with a post that carries no mentions', () => {
		expect(participantsOf({ account: post.account }, 'alice', 'cloud.example')).toHaveLength(1)
	})
})

describe('mentionPills', () => {
	it('is a pill and a non-breaking space per account, in order', () => {
		const nodes = mentionPills([
			{ acct: 'bob@remote.example', url: 'https://remote.example/@bob', avatar: 'https://remote.example/bob.png' },
			{ acct: 'alice', url: 'https://cloud.example/@alice' },
		], 'cloud.example')

		expect(nodes).toHaveLength(4)
		const [bob, space, alice] = /** @type {HTMLElement[]} */ (nodes)
		expect(bob.className).toBe('mention')
		expect(bob.contentEditable).toBe('false')
		expect(bob.textContent).toBe('@bob@remote.example')
		expect(bob.querySelector('img')?.getAttribute('src')).toBe('https://remote.example/bob.png')
		expect(space.textContent).toBe('\u00a0')
		expect(alice.textContent).toBe('@alice@cloud.example')
		expect(alice.querySelector('img')).toBeNull()
	})

	it('writes a Bluesky mention as the bare handle', () => {
		const [dana] = /** @type {HTMLElement[]} */ (mentionPills([
			{ acct: 'dana.bsky.social', url: 'https://bsky.app/profile/dana.bsky.social', bluesky: { native: true } },
		], 'cloud.example'))

		expect(dana.textContent).toBe('@dana.bsky.social')
	})
})
