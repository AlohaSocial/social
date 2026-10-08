/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The Bluesky development network for the interop job: the official PLC
 * directory, PDS and AppView from `@atproto/dev-env`, on fixed ports, with
 * two things the job needs on top:
 *
 * - handles under the app's host resolve against the app. The runner has no
 *   wildcard DNS, so the probe asks the app's own host and names the handle
 *   in the query, which the app accepts for exactly this. The AppView and
 *   the PDS's own resolver take the patch; the PDS's `resolveHandle` does
 *   not reach it, as the PDS claims every `.test` handle by configuration
 *   and refuses the ones it lacks, so the job asks the AppView;
 * - the AppView also subscribes to the app's firehose, so what the PDS
 *   under test publishes is indexed the way a relay's stream would be;
 * - a video's playlist is named on https, as Bluesky's are, so the app
 *   streams it rather than linking to the post;
 * - the web server of a domain a person owns, for a custom handle: it
 *   answers `/.well-known/atproto-did` with the DID the test gives it
 *   (`POST /did`), behind the job's proxy as `https://me.handles.test`;
 * - a stand-in for Bluesky's video service, which does what that service
 *   does for the app: takes a video with the token the account signed,
 *   stores it in the account's repository on its own PDS with that token
 *   (`uploadBlob`), and answers the job with the blob. It makes no stream;
 *   nothing here plays one.
 *
 * Writes the addresses to NETWORK_FILE and keeps running until stopped.
 * SOCIAL_URL is the app's origin (https://nextcloud.test); SOCIAL_HOST the
 * host its handles live under.
 */
import { writeFileSync } from 'node:fs'
import { createServer } from 'node:http'
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
const VIDEO_PORT = 2590
const HANDLE_PORT = 2591
const VIDEO_HOST = 'https://video.interop.test'

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
	bsky: {
		port: BSKY_PORT,
		publicUrl: `http://localhost:${BSKY_PORT}`,
		dbPostgresSchema: 'bsky',
		videoPlaylistUrlPattern: `${VIDEO_HOST}/watch/%s/%s/playlist.m3u8`,
		videoThumbnailUrlPattern: `${VIDEO_HOST}/watch/%s/%s/thumbnail.jpg`,
	},
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

const videoJobs = new Map()
const videoService = createServer(async (req, res) => {
	const url = new URL(req.url ?? '/', `http://127.0.0.1:${VIDEO_PORT}`)
	const send = (status, body) => {
		res.writeHead(status, { 'content-type': 'application/json' })
		res.end(JSON.stringify(body))
	}
	try {
		switch (url.pathname) {
		case '/xrpc/app.bsky.video.getUploadLimits':
			return send(200, { canUpload: true, remainingDailyVideos: 25, remainingDailyBytes: 10000000000 })
		case '/xrpc/app.bsky.video.getJobStatus': {
			const job = videoJobs.get(url.searchParams.get('jobId'))
			return job ? send(200, { jobStatus: job }) : send(400, { error: 'InvalidRequest', message: 'no such job' })
		}
		case '/xrpc/app.bsky.video.uploadVideo': {
			const did = url.searchParams.get('did') ?? ''
			const chunks = []
			for await (const chunk of req) {
				chunks.push(chunk)
			}
			const { pds } = await network.bsky.dataplane.idResolver.did.resolveAtprotoData(did)
			const stored = await fetch(`${pds}/xrpc/com.atproto.repo.uploadBlob`, {
				method: 'POST',
				headers: { authorization: req.headers.authorization ?? '', 'content-type': req.headers['content-type'] ?? 'video/mp4' },
				body: Buffer.concat(chunks),
			})
			const answer = await stored.json()
			if (!stored.ok) {
				return send(stored.status, answer)
			}
			const jobId = `job-${videoJobs.size + 1}`
			videoJobs.set(jobId, { jobId, did, state: 'JOB_STATE_COMPLETED', blob: answer.blob })
			return send(200, { jobId, did, state: 'JOB_STATE_CREATED' })
		}
		}
		return send(404, { error: 'MethodNotImplemented' })
	} catch (error) {
		console.error('video service', error)
		return send(500, { error: 'InternalServerError', message: String(error) })
	}
})
videoService.listen(VIDEO_PORT, '127.0.0.1')

let handleDid = ''
const handleServer = createServer(async (req, res) => {
	if (req.method === 'POST' && req.url === '/did') {
		const chunks = []
		for await (const chunk of req) {
			chunks.push(chunk)
		}
		handleDid = Buffer.concat(chunks).toString().trim()
		res.writeHead(204)
		return res.end()
	}
	if (req.url === '/.well-known/atproto-did' && handleDid !== '') {
		res.writeHead(200, { 'content-type': 'text/plain' })
		return res.end(handleDid)
	}
	res.writeHead(404)
	res.end()
})
handleServer.listen(HANDLE_PORT, '127.0.0.1')

const addresses = {
	plc: network.plc.url,
	pds: network.pds.url,
	bsky: network.bsky.url,
	bskyDid: network.bsky.serverDid,
	pdsDid: network.pds.ctx.cfg.service.did,
	ozone: network.ozone?.url ?? '',
	ozoneDid: network.ozone?.ctx?.cfg?.service?.did ?? '',
	videoService: `http://127.0.0.1:${VIDEO_PORT}`,
	videoHost: VIDEO_HOST,
	handleServer: `http://127.0.0.1:${HANDLE_PORT}`,
	customHandle: 'me.handles.test',
}
writeFileSync(NETWORK_FILE, JSON.stringify(addresses, null, 2))
console.log('dev network up', addresses)

const stop = async () => {
	videoService.close()
	handleServer.close()
	await socialFirehose.destroy()
	await network.close()
	process.exit(0)
}
process.on('SIGTERM', stop)
process.on('SIGINT', stop)
setInterval(() => {}, 60000)
