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
const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32"><path fill="currentColor" d="m12 21-1.45-1.3c-1.683-1.517-3.075-2.825-4.175-3.925-1.1-1.1-1.975-2.087-2.625-2.962-.65-.875-1.104-1.68-1.362-2.413A6.706 6.706 0 0 1 2 8.15c0-1.567.525-2.875 1.575-3.925C4.625 3.175 5.933 2.65 7.5 2.65c.867 0 1.692.183 2.475.55A5.936 5.936 0 0 1 12 4.75a5.936 5.936 0 0 1 2.025-1.55 5.762 5.762 0 0 1 2.475-.55c1.567 0 2.875.525 3.925 1.575C21.475 5.275 22 6.583 22 8.15a6.73 6.73 0 0 1-.387 2.25c-.259.733-.713 1.538-1.363 2.413-.65.875-1.525 1.862-2.625 2.962s-2.492 2.408-4.175 3.925L12 21Z" transform="matrix(1.4 0 0 1.4 -.8 -.41)"/></svg>'

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
