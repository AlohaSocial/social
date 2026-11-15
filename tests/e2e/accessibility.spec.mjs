/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { OTHER_PASSWORD, OTHER_USER, apiAs, appPages, ensureAccount, login, openAppPage, postStatus } from './helpers.mjs'

/**
 * Every page of the app, scanned with axe-core for the WCAG 2.x A and AA
 * rules, failing on a `serious` or `critical` violation that is not already
 * known.
 *
 * What is known is `a11y-baseline.json`: per rule, the kinds of element that
 * violate it (see elementKind), each with the pages it was seen on. Not per
 * page: which pages show a post, and so the post's buttons, depends on what
 * the tests before have written, and a known fault in a component is known
 * wherever the component is drawn. A violation on a kind of element the
 * baseline does not name fails the test; an entry in the baseline that no
 * page violates any more is reported in the test's annotations and the log,
 * so the baseline can shrink.
 *
 * To write the baseline from what the pages show now, after fixing some or
 * when a change has to be accepted:
 *
 *   A11Y_UPDATE_BASELINE=1 npm run test:e2e -- accessibility.spec.mjs
 *
 * against an instance seeded the way e2e.yml seeds one.
 */

const BASELINE = fileURLToPath(new URL('./a11y-baseline.json', import.meta.url))
const UPDATE = process.env.A11Y_UPDATE_BASELINE === '1'
const BLOCKING = new Set(['serious', 'critical'])
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

/** @typedef {Record<string, Record<string, string[]>>} Findings rule → element kind → pages */

/** @return {Findings} */
function readBaseline() {
	try {
		return JSON.parse(readFileSync(BASELINE, 'utf8'))
	} catch {
		return {}
	}
}

/** @param {Findings} findings */
function writeBaseline(findings) {
	const sorted = Object.fromEntries(Object.keys(findings).sort().map((rule) => [
		rule,
		Object.fromEntries(Object.keys(findings[rule]).sort().map((kind) => [kind, [...findings[rule][kind]].sort()])),
	]))
	writeFileSync(BASELINE, JSON.stringify(sorted, null, '\t') + '\n')
}

/**
 * What kind of element violates a rule, in words that stay the same from
 * one run and one build to the next: its tag and classes, and if it has no
 * class, the nearest ancestor that does. Not axe's own selector, which is
 * the shortest one unique on the page, so the same button is
 * `.account-hover__trigger` beside one post and a path through
 * `li[data-status-id]` and Vue's per-build `data-v-…` attributes beside two.
 * Classes with digits in them are left out: they carry ids.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string[]} target axe's selector, one entry per frame or shadow root
 * @return {Promise<string>}
 */
async function elementKind(page, target) {
	const fallback = target.join(' >>> ').replace(/\[data-v-[^\]]*\]/g, '').replace(/\d+/g, 'N')
	if (target.length !== 1) {
		return fallback
	}

	const kind = await page.evaluate((selector) => {
		const describe = (element) => [
			element.tagName.toLowerCase(),
			...[...element.classList].filter((name) => !/\d/.test(name)).sort(),
		].join('.')
		const element = document.querySelector(selector)
		if (element === null) {
			return null
		}
		if (element.classList.length > 0) {
			return describe(element)
		}
		const ancestor = element.parentElement?.closest('[class]')
		return ancestor ? `${describe(ancestor)} ${describe(element)}` : describe(element)
	}, target[0])

	return kind ?? fallback
}

/**
 * The page's blocking violations, added to `findings`, and those the
 * baseline does not know, described for the failure message.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} name the page, as the findings record it
 * @param {Findings} findings
 * @param {Findings} known the baseline
 * @return {Promise<string[]>}
 */
async function scan(page, name, findings, known) {
	// Content that has just arrived fades in from opacity 0, and axe measures
	// contrast through opacity: a scan during the fade reports text no reader
	// is left looking at. Spinners run forever and are not waited for.
	await page.waitForFunction(() => document.getAnimations().every((animation) => animation.playState !== 'running'
		|| animation.effect?.getComputedTiming().iterations === Infinity), null, { timeout: 10_000 })
	const results = await new AxeBuilder({ page }).withTags(TAGS).analyze()

	const fresh = []
	for (const violation of results.violations) {
		for (const node of violation.nodes) {
			if (!BLOCKING.has(node.impact ?? violation.impact ?? '')) {
				continue
			}
			const kind = await elementKind(page, node.target.map(String))
			const pages = ((findings[violation.id] ??= {})[kind] ??= [])
			if (!pages.includes(name)) {
				pages.push(name)
			}
			if (known[violation.id]?.[kind] === undefined) {
				fresh.push(`${violation.id} (${node.impact}): ${violation.help}\n    ${kind}\n    ${node.target.join(' >>> ')}\n    ${node.html.slice(0, 200)}`)
			}
		}
	}

	return fresh
}

test.describe('accessibility', () => {
	/** @type {string} */
	let postPath

	test.beforeAll(async () => {
		const other = await apiAs(OTHER_USER, OTHER_PASSWORD)
		try {
			await ensureAccount(other, OTHER_USER)
			const status = await postStatus(other, { status: `A post to read with a screen reader #alohasearch ${Date.now()}` })
			postPath = `/@${OTHER_USER}/${status.id}`
		} finally {
			await other.dispose()
		}
	})

	test('no page has a serious or critical violation the baseline does not know', async ({ page }) => {
		const pages = [...appPages(), { name: 'post', path: postPath, marker: 'article' }]
		test.setTimeout(pages.length * 30_000)
		const known = readBaseline()
		/** @type {Findings} */
		const findings = {}

		await login(page)
		for (const target of pages) {
			await test.step(target.name, async () => {
				await openAppPage(page, target)
				const fresh = await scan(page, target.name, findings, known)
				if (!UPDATE) {
					expect.soft(fresh, `${target.name}: serious or critical violations not in a11y-baseline.json`).toEqual([])
				}
			})
		}

		if (UPDATE) {
			writeBaseline(findings)
			return
		}

		const fixed = Object.entries(known).flatMap(([rule, kinds]) => Object.keys(kinds)
			.filter((kind) => findings[rule]?.[kind] === undefined)
			.map((kind) => `${rule} ${kind}`))
		if (fixed.length > 0) {
			const message = `no page violates these any more; remove them from a11y-baseline.json:\n  ${fixed.join('\n  ')}`
			test.info().annotations.push({ type: 'a11y-fixed', description: message })
			// eslint-disable-next-line no-console -- the run's log is where a reader of CI sees it
			console.log(message)
		}
	})
})
