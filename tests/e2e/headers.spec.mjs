/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, request, test } from '@playwright/test'
import { APP, BASE_URL, PASSWORD, USER, apiAs, ensureAccount, login, postStatus } from './helpers.mjs'

/**
 * The response headers a browser enforces, read off the real server.
 *
 * What the app's pages may run is the content security policy's to say, and
 * a policy that allows inline script or eval is no policy against injected
 * markup. A browser must never sniff a type past the one stated, least of all
 * for a file somebody else uploaded. Cross-origin reads are opened where a
 * browser client of another origin needs them — the atproto XRPC surface and
 * its well-known documents, and the Mastodon client API a bearer token reaches
 * — never with credentials, and a session cookie from elsewhere signs nobody in.
 * A stored file is served so that it cannot become a page on this origin: a
 * picture, a video or a sound, or a sandboxed download.
 *
 * Nextcloud itself writes two inline scripts into every page (the import map
 * and its configuration block), both carrying the page's nonce; the app adds
 * none, and every script on the page carries that nonce.
 */
test.describe('response headers', () => {
	/** @type {object} */
	let post
	/** @type {object} */
	let media

	/** A 1×1 PNG. */
	const PIXEL = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64')

	test.beforeAll(async () => {
		const api = await apiAs(USER, PASSWORD)
		await ensureAccount(api, USER)
		post = await postStatus(api, { status: `Headers are read here ${Date.now()}` })

		const uploaded = await api.post(`${APP}/api/v2/media`, {
			multipart: { file: { name: 'pixel.png', mimeType: 'image/png', buffer: PIXEL } },
		})
		expect(uploaded.ok(), 'a picture can be uploaded').toBe(true)
		media = await uploaded.json()
		await api.dispose()
	})

	/**
	 * The directives of a Content-Security-Policy header, by name.
	 *
	 * @param {string} header
	 * @return {Map<string, string[]>}
	 */
	function directives(header) {
		const parsed = new Map()
		for (const part of header.split(';')) {
			const [name, ...values] = part.trim().split(/\s+/)
			if (name) {
				parsed.set(name.toLowerCase(), values)
			}
		}
		return parsed
	}

	/** The app's pages, as nobody and as the signed-in reader see them. */
	function pages() {
		return [
			{ name: 'the app, signed out', path: `${APP}/`, signedIn: false },
			{ name: 'a public profile', path: `${APP}/@${USER}`, signedIn: false },
			{ name: 'a public post', path: `${APP}/@${USER}/${post.id}`, signedIn: false },
			{ name: 'the app, signed in', path: `${APP}/`, signedIn: true },
			{ name: 'a post, signed in', path: `${APP}/@${USER}/${post.id}`, signedIn: true },
		]
	}

	/** A request context that is signed in with the reader's password, or nobody. */
	async function client(signedIn) {
		return request.newContext({
			baseURL: BASE_URL,
			...(signedIn ? { httpCredentials: { username: USER, password: PASSWORD, send: 'always' } } : {}),
		})
	}

	test('every page of the app has a policy that runs no inline script and no eval', async () => {
		for (const page of pages()) {
			const context = await client(page.signedIn)
			const response = await context.get(page.path)
			expect(response.status(), page.name).toBe(200)

			const header = response.headers()['content-security-policy'] ?? ''
			expect(header, `${page.name} has a content security policy`).not.toBe('')
			const policy = directives(header)
			for (const name of ['script-src', 'script-src-elem']) {
				const sources = policy.get(name) ?? policy.get('default-src') ?? []
				expect(sources.length, `${page.name}: ${name} names its sources`).toBeGreaterThan(0)
				expect(sources, `${page.name}: ${name}`).not.toContain("'unsafe-inline'")
				expect(sources, `${page.name}: ${name}`).not.toContain("'unsafe-eval'")
			}
			expect(policy.get('frame-ancestors') ?? [], `${page.name} may not be framed by another site`).not.toContain('*')
			await context.dispose()
		}
	})

	test('every script on a page carries its nonce, and the app adds no inline script or handler', async () => {
		for (const page of pages()) {
			const context = await client(page.signedIn)
			const response = await context.get(page.path)
			const html = await response.text()
			const nonce = /'nonce-([^']+)'/.exec(response.headers()['content-security-policy'] ?? '')?.[1]
			expect(nonce, `${page.name} has a nonce`).toBeTruthy()

			const scripts = [...html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)]
			expect(scripts.length, `${page.name} loads scripts`).toBeGreaterThan(0)
			for (const [, attributes, body] of scripts) {
				expect(attributes, `${page.name}: a script without the page's nonce`).toContain(`nonce="${nonce}"`)
				if (!/\bsrc=/.test(attributes)) {
					// Nextcloud's import map and its configuration block, and nothing else
					const nextcloud = /type="importmap"/.test(attributes) || /^\s*var _oc_/.test(body)
					expect(nextcloud, `${page.name}: an inline script that is not Nextcloud's own: ${body.trim().slice(0, 80)}`).toBe(true)
				}
			}

			const handlers = [...html.matchAll(/<[a-z][^>]*\son[a-z]+\s*=/gi)].map((match) => match[0].slice(0, 80))
			expect(handlers, `${page.name}: inline event handlers`).toEqual([])
			expect(html, `${page.name}: a javascript: address`).not.toMatch(/(?:href|src|action)\s*=\s*["']\s*javascript:/i)
			await context.dispose()
		}
	})

	test('nothing the app serves may be sniffed past its stated type', async () => {
		const anonymous = await client(false)
		const api = await apiAs(USER, PASSWORD)
		const answers = [
			['a page', await anonymous.get(`${APP}/@${USER}`)],
			['the public timeline', await anonymous.get(`${APP}/api/v1/timelines/public`)],
			['the instance', await anonymous.get(`${APP}/api/v1/instance`)],
			['the RSS feed', await anonymous.get(`${APP}/@${USER}.rss`)],
			['an ActivityPub actor', await anonymous.get(`${APP}/users/${USER}`, { headers: { Accept: 'application/activity+json' } })],
			['an XRPC answer', await anonymous.get(`${APP}/xrpc/com.atproto.server.describeServer`)],
			['a signed-in API answer', await api.get(`${APP}/api/v1/accounts/verify_credentials`)],
			['an uploaded picture', await anonymous.get(media.url)],
			['a missing route', await anonymous.get(`${APP}/api/v1/statuses/1`)],
		]
		for (const [name, response] of answers) {
			expect(response.headers()['x-content-type-options'], name).toBe('nosniff')
			expect(response.headers()['content-type'] ?? '', `${name} states a type`).not.toBe('')
		}
		await anonymous.dispose()
		await api.dispose()
	})

	test('cross-origin reads are open on the XRPC surface and the client API, and nowhere a session counts', async () => {
		const anonymous = await client(false)
		const origin = { Origin: 'https://elsewhere.example' }

		const xrpc = await anonymous.get(`${APP}/xrpc/com.atproto.server.describeServer`, { headers: origin })
		expect(xrpc.headers()['access-control-allow-origin'], 'XRPC is read by browser clients of any origin').toBe('*')
		const preflights = []
		for (const requestOrigin of ['https://bsky.app', 'https://other-bluesky-client.example']) {
			const preflight = await anonymous.fetch(`${APP}/xrpc/com.atproto.repo.createRecord`, {
				method: 'OPTIONS',
				headers: {
					Origin: requestOrigin,
					'Access-Control-Request-Method': 'POST',
					'Access-Control-Request-Headers': 'authorization, content-type, dpop, x-atproto-session-id, x-atproto-device-id, x-atproto-bsky-topics, x-bsky-topics, atproto-accept-labelers, atproto-proxy',
				},
			})
			expect(preflight.status(), `the preflight is answered for ${requestOrigin}`).toBeLessThan(300)
			expect(preflight.headers()['access-control-allow-origin'], `the origin ${requestOrigin} is allowed`).toBe('*')
			expect(preflight.headers()['access-control-allow-headers'] ?? '').toMatch(/DPoP/)
			expect(preflight.headers()['access-control-allow-headers'] ?? '').toMatch(/X-Atproto-Session-Id/i)
			for (const header of ['Content-Type', 'X-Atproto-Device-Id', 'X-Atproto-Bsky-Topics', 'X-Bsky-Topics', 'atproto-accept-labelers', 'atproto-proxy']) {
				expect(preflight.headers()['access-control-allow-headers'] ?? '').toMatch(new RegExp(header, 'i'))
			}
			preflights.push(preflight)
		}

		// what Elk or Phanpy calls from a page of its own origin
		const open = [
			['the public timeline', await anonymous.get(`${APP}/api/v1/timelines/public`, { headers: origin })],
			['the instance', await anonymous.get(`${APP}/api/v2/instance`, { headers: origin })],
			['a refused bearer token', await anonymous.get(`${APP}/api/v1/accounts/verify_credentials`, { headers: { ...origin, Authorization: 'Bearer not-a-token' } })],
			['the token endpoint', await anonymous.post(`${APP}/oauth/token`, { headers: origin, data: { grant_type: 'authorization_code' } })],
			['an endpoint this server does not have', await anonymous.get(`${APP}/api/v1/no_such_endpoint`, { headers: origin })],
		]
		for (const [name, response] of open) {
			expect(response.headers()['access-control-allow-origin'], `${name} is read by browser clients of any origin`).toBe('*')
		}
		expect(open[0][1].headers()['access-control-expose-headers'] ?? '', 'a client reads the next page and its budget').toMatch(/Link/)
		expect(open[0][1].status(), 'the public timeline, without the trailing slash its route has').toBe(200)
		expect(open[4][1].status(), 'a missing endpoint is a 404, not a 405').toBe(404)
		const apiPreflight = await anonymous.fetch(`${APP}/api/v1/statuses`, {
			method: 'OPTIONS',
			headers: { ...origin, 'Access-Control-Request-Method': 'POST', 'Access-Control-Request-Headers': 'authorization, content-type, idempotency-key' },
		})
		expect(apiPreflight.status(), 'the client API preflight is answered').toBe(204)
		expect(apiPreflight.headers()['access-control-allow-origin']).toBe('*')
		expect(apiPreflight.headers()['access-control-allow-headers'] ?? '').toMatch(/Authorization/)
		expect(apiPreflight.headers()['access-control-allow-headers'] ?? '').not.toMatch(/OCS-APIRequest|requesttoken/i)

		const closed = [
			['a page', `${APP}/@${USER}`],
			['the app', `${APP}/`],
			['a web-app session route', `${APP}/api/v1/global/tags/search?search=x`],
			['an ActivityPub actor', `${APP}/users/${USER}`],
			['the RSS feed', `${APP}/@${USER}.rss`],
		]
		for (const [name, path] of closed) {
			const response = await anonymous.get(path, { headers: origin })
			expect(response.headers()['access-control-allow-origin'], `${name} answers no other origin`).toBeUndefined()
		}

		// '*' is never combined with credentials, which is what would let
		// another site read a signed-in answer
		for (const response of [xrpc, ...preflights, apiPreflight, ...open.map(([, each]) => each)]) {
			expect(response.headers()['access-control-allow-credentials']).toBeUndefined()
		}
		await anonymous.dispose()
	})

	test('a session cookie sent from another origin without the CSRF token signs nobody in', async ({ page }) => {
		await login(page)
		// every cookie of the signed-in browser, and no request token
		const response = await page.request.get(`${APP}/api/v1/accounts/verify_credentials`, {
			headers: { Origin: 'https://elsewhere.example' },
		})
		expect(response.status(), 'the session does not count without its CSRF token').toBe(401)
		expect(response.headers()['access-control-allow-credentials']).toBeUndefined()
	})

	test('an uploaded file is served as a picture, a video or a sound, or as a sandboxed download', async () => {
		const anonymous = await client(false)
		for (const url of [media.url, media.preview_url].filter(Boolean)) {
			const response = await anonymous.get(url)
			expect(response.status(), url).toBe(200)
			const headers = response.headers()
			const type = (headers['content-type'] ?? '').split(';')[0].trim().toLowerCase()
			const renderable = /^(image|video|audio)\//.test(type) && !/svg|xml/.test(type)
			if (!renderable) {
				expect(headers['content-disposition'] ?? '', `${url} is a download`).toMatch(/^attachment/)
				expect(headers['content-security-policy'] ?? '', `${url} is sandboxed`).toMatch(/sandbox/)
			}
			expect(type, `${url} is the picture that was uploaded`).toBe('image/png')
			expect(headers['x-content-type-options']).toBe('nosniff')
			// opened on its own, a picture is a document that may load and run nothing
			expect(directives(headers['content-security-policy'] ?? '').get('default-src') ?? [], `${url} runs nothing`).toEqual(["'none'"])
		}
		await anonymous.dispose()
	})
})
