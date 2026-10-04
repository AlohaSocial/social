/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'
import { lessLikeThis, undoLessLikeThis } from './interests.js'
import logger from './logger.js'
import { showError, showSuccess, showUndo } from './toast.js'
import { useTimelineStore } from '../store/timeline.js'

/**
 * "Less like this", from a post's menu.
 *
 * Here rather than in TimelinePost, which is shared by two bundles and carries
 * as little as it can: the post only says it was asked.
 *
 * On For you the post leaves the feed at once, because that is the feed
 * the reader just said it does not belong in; the toast's Undo puts it back
 * where it was. Anywhere else the post stays — it is in that timeline for a
 * reason that has nothing to do with interests — and the reader is only told
 * the feed will take note.
 *
 * @param {import('../types/Mastodon.js').Status} status the post the reader sees
 * @param {string} type the timeline it is shown in
 * @return {Promise<void>}
 */
export async function lessLikeThisFromPost(status, type) {
	const store = useTimelineStore()

	if (type !== 'interests') {
		try {
			await lessLikeThis(status.id)
			showSuccess(t('social', 'For you will show you fewer posts like this'))
		} catch (error) {
			logger.error('Could not send less like this', { error })
			showError(t('social', 'Could not tell For you about this post'))
		}

		return
	}

	// the list holds entries, and a boost is an entry of its own around the
	// post the reader was shown
	const entryId = store.timeline.find((id) => id === status.id || store.statuses[id]?.reblog?.id === status.id)
	const entry = entryId === undefined ? null : (store.statuses[entryId] ?? null)
	const index = entryId === undefined ? -1 : store.timeline.indexOf(entryId)
	if (entry !== null) {
		// off For you, not gone: the other lists still show it
		store.removeStatus(entry, false)
	}

	// an Undo pressed after the reader moved on to another timeline must not
	// put the post into that one
	const identity = store.getTimelineIdentity
	const putBack = () => {
		if (entry !== null && store.getTimelineIdentity === identity) {
			store.restoreStatus(entry, index)
		}
	}

	try {
		await lessLikeThis(status.id)
	} catch (error) {
		logger.error('Could not send less like this', { error })
		putBack()
		showError(t('social', 'Could not tell For you about this post'))

		return
	}

	if (entry !== null) {
		store.forgetRemoval(entry)
	}
	showUndo(t('social', 'Post hidden. You will see fewer posts like it.'), async () => {
		try {
			await undoLessLikeThis(status.id)
			putBack()
		} catch (error) {
			logger.error('Could not undo less like this', { error })
			showError(t('social', 'Could not bring the post back'))
		}
	})
}
