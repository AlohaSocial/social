/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The Bluesky development network for the interop job: the official PLC
 * directory, PDS and AppView from `@atproto/dev-env`, on fixed ports, with
 * two things the job needs on top:
 *
 * - handles under the app's host resolve against the app, where dev-env
 *   would send every `.test` handle to its own PDS. The runner has no
 *   wildcard DNS, so the probe asks the app's own host and names the handle
 *   in the query, which the app accepts for exactly this;
 * - the AppView also subscribes to the app's firehose, so what the PDS
 *   under test publishes is indexed the way a relay's stream would be.
 *
 * Writes the addresses to NETWORK_FILE and keeps running until stopped.
 * SOCIAL_URL is the app's origin (https://nextcloud.test); SOCIAL_HOST the
 * host its handles live under.
 */
import { writeFileSync } from 'node:fs'
import { TestNetwork } from '@atproto/dev-env'
import { RepoSubscription } from '@atproto/bsky'

const SOCIAL_URL = (process.env.SOCIAL_URL ?? 'https://nextcloud.test').replace(/\/$/, '')
const SOCIAL_HOST = process.env.SOCIAL_HOST ?? new URL(SOCIAL_URL).host
const NETWORK_FILE = process.env.NETWORK_FILE ?? '/tmp/atproto-network.json'
const PLC_PORT = 2582
const PDS_PORT = 2583
const BSKY_PORT = 2584
const OZONE_PORT = 2587
const INTROSPECT_PORT = 2581

async function resolveSocialHandle(handle) {
	const res = await fetch(`${SOCIAL_URL}/.well-known/atproto-did?handle=${encodeURIComponent(handle)}`, {
		headers: { accept: 'text/plain' },
		redirect: 'follow',
	})
	const body = (await res.text()).trim()
	if (res.status !== 200 || !body.startsWith('did:')) {
		return undefined
	}
	return body
}

function patchHandleResolver(idResolver) {
	const original = idResolver.handle.resolve.bind(idResolver.handle)
	idResolver.handle.resolve = async (handle) => {
		if (handle.endsWith('.' + SOCIAL_HOST)) {
			return resolveSocialHandle(handle)
		}
		return original(handle)
	}
}

const network = await TestNetwork.create({
	dbPostgresSchema: 'interop',
	plc: { port: PLC_PORT },
	pds: { port: PDS_PORT, hostname: 'localhost' },
	bsky: { port: BSKY_PORT, publicUrl: `http://localhost:${BSKY_PORT}`, dbPostgresSchema: 'bsky' },
	ozone: { port: OZONE_PORT },
	introspect: { port: INTROSPECT_PORT },
})

for (const resolver of [network.pds.ctx.idResolver, network.bsky.ctx.idResolver, network.bsky.dataplane.idResolver]) {
	patchHandleResolver(resolver)
}

const socialFirehose = new RepoSubscription({
	service: SOCIAL_URL.replace(/^http/, 'ws'),
	db: network.bsky.db,
	idResolver: network.bsky.dataplane.idResolver,
})
void socialFirehose.start()

const addresses = {
	plc: network.plc.url,
	pds: network.pds.url,
	bsky: network.bsky.url,
	bskyDid: network.bsky.serverDid,
	pdsDid: network.pds.ctx.cfg.service.did,
}
writeFileSync(NETWORK_FILE, JSON.stringify(addresses, null, 2))
console.log('dev network up', addresses)

const stop = async () => {
	await socialFirehose.destroy()
	await network.close()
	process.exit(0)
}
process.on('SIGTERM', stop)
process.on('SIGINT', stop)
setInterval(() => {}, 60000)
