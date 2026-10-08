/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A Bluesky app signing in with Bluesky sign-in (OAuth), made with the
 * official client library, for the interop job. Two runs, as the person's
 * browser sits between them:
 *
 * - `start <did>` resolves the account, finds this app's authorization
 *   server, pushes the request and prints the address the person would be
 *   sent to;
 * - `finish <callback query>` takes what the server sent back, exchanges
 *   the code, calls the PDS with the session, refreshes it, calls again,
 *   signs out, and prints what it saw as JSON.
 *
 * The library's state between the runs is kept in files under
 * OAUTH_STATE_DIR. PLC_URL is the development network's directory.
 */
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { NodeOAuthClient, buildAtprotoLoopbackClientMetadata, requestLocalLock } from '@atproto/oauth-client-node'

const DIR = process.env.OAUTH_STATE_DIR ?? '/tmp/atproto-oauth'
const SCOPE = process.env.OAUTH_SCOPE || 'atproto transition:generic'
mkdirSync(DIR, { recursive: true })

function fileStore(name) {
	const path = (key) => `${DIR}/${name}-${Buffer.from(key).toString('hex')}.json`
	return {
		async get(key) {
			return existsSync(path(key)) ? JSON.parse(readFileSync(path(key), 'utf8')) : undefined
		},
		async set(key, value) {
			writeFileSync(path(key), JSON.stringify(value))
		},
		async del(key) {
			rmSync(path(key), { force: true })
		},
	}
}

const client = new NodeOAuthClient({
	clientMetadata: buildAtprotoLoopbackClientMetadata({ scope: SCOPE, redirect_uris: ['http://127.0.0.1/callback'] }),
	stateStore: fileStore('state'),
	sessionStore: fileStore('session'),
	plcDirectoryUrl: process.env.PLC_URL ?? 'http://127.0.0.1:2582',
	requestLock: requestLocalLock,
})

const [command, argument] = process.argv.slice(2)
if (command === 'start') {
	const url = await client.authorize(argument, { scope: SCOPE, state: 'interop' })
	console.log(url.toString())
} else if (command === 'finish') {
	const { session } = await client.callback(new URLSearchParams(argument))
	const call = async (path, init) => {
		const response = await session.fetchHandler(path, init)
		return { status: response.status, body: await response.json().catch(() => null) }
	}
	const who = await call('/xrpc/com.atproto.server.getSession')
	const timeline = await call('/xrpc/app.bsky.feed.getTimeline?limit=1')
	const posted = await call('/xrpc/com.atproto.repo.createRecord', {
		method: 'POST',
		headers: { 'content-type': 'application/json' },
		body: JSON.stringify({
			repo: session.did,
			collection: 'app.bsky.feed.post',
			record: { $type: 'app.bsky.feed.post', text: process.env.OAUTH_POST_TEXT ?? 'Posted with Bluesky sign-in', createdAt: new Date().toISOString() },
		}),
	})
	const liked = await call('/xrpc/com.atproto.repo.createRecord', {
		method: 'POST',
		headers: { 'content-type': 'application/json' },
		body: JSON.stringify({
			repo: session.did,
			collection: 'app.bsky.feed.like',
			record: { $type: 'app.bsky.feed.like', subject: { uri: posted.body?.uri ?? '', cid: posted.body?.cid ?? '' }, createdAt: new Date().toISOString() },
		}),
	})
	const first = await session.getTokenInfo(false)
	const refreshed = await session.getTokenInfo(true)
	const again = await call('/xrpc/com.atproto.server.getSession')
	await session.signOut()
	console.log(JSON.stringify({
		did: session.did,
		scope: refreshed.scope,
		issuer: first.iss,
		refreshedAt: refreshed.expiresAt,
		who,
		timeline: { status: timeline.status },
		posted,
		liked: { status: liked.status, error: liked.body?.error ?? '' },
		again: { status: again.status },
	}))
} else {
	console.error('usage: oauth-client.mjs start <did> | finish <callback query>')
	process.exit(2)
}
