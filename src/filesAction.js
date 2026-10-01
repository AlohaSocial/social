/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * "Share to Social" in the Files app.
 *
 * The composer could already attach a picture that is on the server, through
 * its own file picker. This is the same road walked from the other end: a
 * reader looking at the picture in Files, where the thought "I want to post
 * this" actually occurs, gets an action that carries it into the composer.
 *
 * Deliberately small. The Files page is opened far more often than a post is
 * written from it, and this script is loaded on every one of those pages, so
 * it registers the action and nothing else: no framework, no composer, no
 * store. The work happens in the app, which is where the composer lives; this
 * only hands over the paths, in the URL, and the app's navigation reads them.
 */

import { FileType, registerFileAction } from '@nextcloud/files'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { knownLimits, loadLimits } from './services/instanceLimits.js'

/**
 * How many files a post may carry: the server's number once it has been
 * asked, and the number it used to hard-code until then.
 *
 * `enabled()` is synchronous and is asked whenever the selection changes, so
 * it cannot wait for the server. The first time it is asked with more than
 * one file it sends for the real ceiling -- one request, on the first
 * occasion a Files page has any use for it rather than on every Files page --
 * and answers from the fallback meanwhile.
 *
 * @param {number} selected how many files are picked
 * @return {number} the ceiling
 */
export function maxAttachments(selected = 0) {
	if (selected > 1) {
		loadLimits()
	}

	return knownLimits().maxAttachments
}

/** what the composer's picker offers, and what `POST /media/from-file` accepts */
const SHAREABLE = /^(?:image|video)\//

/** the app's own mark, as `img/social.svg` draws it, on the current colour */
const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="32" height="32"><path fill="currentColor" transform="matrix(0.25 0 0 -0.25 0 512)" d="M990 1948 c-68 -11 -127 -44 -179 -99 -71 -77 -119 -196 -126 -314 -1 -26 -4 -39 -7 -39 -2 0 -13 3 -24 6 -68 20 -177 24 -248 11 -86 -17 -145 -47 -198 -101 -53 -53 -76 -107 -76 -180 0 -50 9 -86 34 -138 52 -108 161 -198 299 -247 6 -2 5 -5 -8 -24 -34 -51 -68 -132 -84 -199 -10 -46 -9 -148 2 -190 42 -151 169 -231 325 -204 63 11 133 41 192 82 30 22 91 79 117 112 l15 18 13 -16 c115 -143 287 -223 424 -196 186 35 270 234 195 458 -13 40 -44 103 -65 135 -13 19 -14 22 -8 24 26 8 87 37 112 53 88 52 151 118 189 198 23 48 32 85 32 133 0 74 -23 128 -76 181 -53 54 -109 82 -196 100 -71 15 -180 10 -250 -10 -11 -3 -22 -6 -24 -6 -3 0 -6 13 -7 39 -11 170 -99 325 -221 386 -47 24 -107 34 -152 27z m100 -720 c44 -11 71 -25 100 -52 42 -40 62 -89 62 -155 0 -84 -46 -145 -120 -161 -51 -11 -104 22 -104 66 0 10 -1 11 -11 7 -59 -18 -113 25 -113 90 0 30 8 50 30 76 27 32 77 46 106 31 11 -6 13 -6 18 0 4 3 12 6 19 6 29 2 42 -2 45 -13 1 -5 -3 -49 -9 -97 -7 -48 -11 -90 -9 -92 6 -5 31 2 44 13 20 16 29 40 29 77 0 44 -9 69 -35 94 -57 58 -187 58 -237 0 -23 -26 -30 -45 -32 -85 -3 -65 19 -113 61 -133 11 -5 32 -10 55 -12 43 -5 47 -8 47 -39 0 -31 -8 -37 -45 -37 -111 0 -187 76 -194 193 -3 48 3 80 21 117 27 55 84 98 148 111 24 6 95 3 124 -5z M990 1050 c-12 -12 -13 -27 -2 -38 11 -11 26 -8 39 6 25 28 -11 58 -37 32z"/></svg>'

/**
 * @param {import('@nextcloud/files').INode} node a row of the file list
 * @return {boolean} whether it is something a post can carry
 */
export function isShareable(node) {
	return node.type === FileType.File && SHAREABLE.test(node.mime ?? '')
}

/**
 * Where the composer is opened with these files already attached: the app's
 * home timeline, with one `attach` query parameter per path. The paths are
 * relative to the reader's own folder, which is the only place the server
 * resolves them in.
 *
 * @param {import('@nextcloud/files').INode[]} nodes the files picked
 * @return {string} the URL to send the browser to
 */
export function composeUrl(nodes) {
	const query = new URLSearchParams(nodes.map((node) => ['attach', node.path]))

	return `${generateUrl('/apps/social/timeline/home')}?${query}`
}

/**
 * Carries the files into the composer.
 *
 * @param {import('@nextcloud/files').INode[]} nodes the files picked
 * @param {(url: string) => void} navigate how to leave the page; the default
 *   is the browser's, and a test passes its own
 * @return {null[]} one silent answer per node, as the Files app expects
 */
export function shareToSocial(nodes, navigate = (url) => window.location.assign(url)) {
	navigate(composeUrl(nodes))

	return nodes.map(() => null)
}

/** The action as the Files app sees it; exported so the tests can hold it. */
export const shareAction = {
	id: 'social-share',
	displayName: () => t('social', 'Share to Aloha Social'),
	title: ({ nodes }) => n('social', 'Post this picture on Aloha Social', 'Post these %n pictures on Aloha Social', nodes.length),
	iconSvgInline: () => ICON,
	// every one of them has to be something a post can carry, and no more of
	// them than a post can carry: the composer would have to refuse the rest,
	// and an action that half works is worse than one that is not offered
	enabled: ({ nodes }) => nodes.length > 0
		&& nodes.length <= maxAttachments(nodes.length)
		&& nodes.every(isShareable),
	async exec({ nodes }) {
		return shareToSocial(nodes)[0]
	},
	async execBatch({ nodes }) {
		return shareToSocial(nodes)
	},
	// after the built-in ones — open, download, share — and before the
	// long tail of everything else
	order: 25,
}

registerFileAction(shareAction)
