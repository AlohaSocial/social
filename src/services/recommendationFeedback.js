/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { uuid } from '../utils/uuid.js'

/** @param {string} path API suffix @return {string} generated API URL */
function url(path) {
	return generateUrl('apps/social/api/v1/recommendations' + path)
}

/** @param {string|number} postId a local stream identifier @return {Promise<object>} */
export async function fetchRecommendationContext(postId) {
	const { data } = await axios.get(url('/context/' + encodeURIComponent(postId)))
	return data
}

/**
 * @param {string|number} postId a local stream identifier
 * @param {string} kind one of the server's explicit feedback operations
 * @return {Promise<object>} the saved action and its undo token
 */
export async function recordRecommendationFeedback(postId, kind) {
	const { data } = await axios.post(url('/feedback'), {
		event_id: uuid(),
		post_id: String(postId),
		kind,
	})
	return data
}

/** @param {string} feedbackId the owner-bound undo token @return {Promise<void>} */
export async function undoRecommendationFeedback(feedbackId) {
	await axios.delete(url('/feedback/' + encodeURIComponent(feedbackId)))
}
