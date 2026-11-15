/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** What the server takes in one poll option (`PostService::POLL_MAX_CHARACTERS_PER_OPTION`). */
export const POLL_MAX_CHARACTERS_PER_OPTION = 100

/**
 * Whether a poll can be sent as it is: two options at least that say
 * something, none longer than the server takes. An incomplete poll is not
 * left out of the post, it holds the post back.
 *
 * @param {string[]} options the options as typed
 * @return {boolean} whether it is complete
 */
export function pollIsComplete(options) {
	const filled = options.map((option) => option.trim()).filter((option) => option !== '')
	return filled.length >= 2 && filled.every((option) => [...option].length <= POLL_MAX_CHARACTERS_PER_OPTION)
}

/**
 * The body of `POST /api/v1/statuses` for what the composer holds.
 *
 * Only what was actually chosen goes in: a post written as oneself carries
 * no `post_as`, a post without a place no place fields, and a video post
 * only the video fields that were filled in — the server falls back to the
 * first line of the post for a title nobody gave.
 *
 * @param {object} post what the composer holds
 * @param {string} post.text the words, with any games already played
 * @param {string} post.warning the content warning, '' for none
 * @param {string[]} post.mediaIds the uploads the server took
 * @param {string} [post.inReplyToId] the post this answers
 * @param {string} [post.quoteId] the post this quotes
 * @param {string} post.visibility who it is for
 * @param {string} post.postAs the team it is written as, '' for oneself
 * @param {string} [post.replyPolicy] who may reply, `everyone` when not narrowed
 * @param {string} post.language what it is written in
 * @param {{title: string, category: string, licence: string}|null} post.video what the poster said about the video, null when it is not a video post
 * @param {{id?: string, name?: string, country?: string}|null} post.place where it was taken
 * @param {Date|null} post.scheduledAt when it is to go out, null for now
 * @param {{options: string[], expiresIn: number, multiple: boolean}|null} post.poll the poll, null for none
 * @param {{name: string, start_time: string, end_time: string|null, location: string}|null} post.event the Fediverse event, null for an ordinary post
 * @return {object} the request body
 */
export function statusPayload(post) {
	const body = {
		content_type: '',
		media_ids: post.mediaIds,
		// a warning means the body is hidden until asked for, which is what
		// `sensitive` says about the post as a whole
		sensitive: post.warning !== '',
		spoiler_text: post.warning,
		status: post.text,
		in_reply_to_id: post.inReplyToId,
		quote_id: post.quoteId,
		visibility: post.visibility,
		...(post.postAs === '' ? {} : { post_as: post.postAs }),
		...(!post.replyPolicy || post.replyPolicy === 'everyone' ? {} : { reply_policy: post.replyPolicy }),
		// always, so the post is never without one: the server would fill in
		// the same default, but what the poster saw is what goes
		language: post.language,
		...(post.video === null ? {} : videoFields(post.video)),
		...(post.event === null ? {} : { event: post.event }),
	}

	// where it was taken, only ever as the poster said: a known place by its
	// id, a new one by its name
	if (post.place?.id) {
		body.place_id = post.place.id
	} else if (post.place?.name) {
		body.place_name = post.place.name
		if (post.place.country) {
			body.place_country = post.place.country
		}
	}

	// ISO 8601 in UTC, which is what `scheduled_at` takes; the picker works in
	// the reader's zone and the Date carries the conversion
	if (post.scheduledAt instanceof Date) {
		body.scheduled_at = post.scheduledAt.toISOString()
	}

	const options = (post.poll?.options ?? []).map((option) => option.trim()).filter((option) => option !== '')
	if (post.poll !== null && options.length >= 2) {
		body.poll = {
			options,
			expires_in: post.poll.expiresIn,
			multiple: post.poll.multiple,
		}
	}

	return body
}

/**
 * @param {{title: string, category: string, licence: string}} video what the poster said
 * @return {object} the fields that were filled in
 */
function videoFields(video) {
	const fields = {}
	for (const [key, value] of [
		['video_title', video.title],
		['video_category', video.category],
		['video_licence', video.licence],
	]) {
		if (value.trim() !== '') {
			fields[key] = value.trim()
		}
	}

	return fields
}
