/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * @param {string} acct a handle, with or without its host
 * @param {string} hostname this server's host
 * @return {string} the handle with its host, the way a mention is typed
 */
export function fullHandle(acct, hostname) {
	return acct.includes('@') ? acct : `${acct}@${hostname}`
}

/**
 * Everyone a reply to this post should reach: its author, then everyone it
 * mentioned, each once, and never the reader — a reply that addresses its own
 * author is talking to itself.
 *
 * @param {object} post the post being answered, as the timeline holds it
 * @param {string} selfUid the reader's user id
 * @param {string} hostname this server's host
 * @return {Array<{acct: string, url: string, avatar?: string}>}
 */
export function participantsOf(post, selfUid, hostname) {
	const self = `${selfUid}@${hostname}`.toLowerCase()
	const seen = new Set()

	return [post.account, ...(Array.isArray(post.mentions) ? post.mentions : [])]
		.filter((account) => typeof account?.acct === 'string' && account.acct !== '')
		.filter((account) => {
			const handle = fullHandle(account.acct, hostname).toLowerCase()
			if (handle === self || seen.has(handle)) {
				return false
			}

			seen.add(handle)

			return true
		})
}

/**
 * A mention pill per account, in the order given, each followed by a
 * non-breaking space: what the composer starts a reply with.
 *
 * @param {Array<{acct: string, url: string, avatar?: string}>} accounts who to address
 * @param {string} hostname this server's host
 * @return {Node[]} the nodes to put in the editable box
 */
export function mentionPills(accounts, hostname) {
	return accounts.flatMap((account) => {
		const mention = document.createElement('span')
		mention.className = 'mention'
		mention.contentEditable = 'false'

		const link = document.createElement('a')
		link.href = account.url
		link.target = '_blank'

		// a Mention entity off a post carries no picture; the pill then
		// carries none either rather than a broken one
		if (account.avatar) {
			const avatar = document.createElement('img')
			avatar.src = account.avatar
			link.append(avatar)
		}
		link.append(document.createTextNode(`@${fullHandle(account.acct, hostname)}`))
		mention.append(link)

		return [mention, document.createTextNode('\u00a0')]
	})
}
