/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { showError, showInfo, showSuccess } from '../services/toast.js'
import { translate as t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

/**
 * How many lists are held aside at once.
 *
 * Five covers the switcher — My Feed, For you, Local, Global — plus whatever
 * the reader came from, which is the round trip that used to cost a request
 * and a skeleton every time. The status index is pruned to what these lists
 * still name, so this is a memory number as much as a UX one.
 */
const REMEMBERED = 5

/**
 * How long a page fetched ahead stands in for the list it was fetched for, in
 * milliseconds. Older than this, a switch asks the server as if nothing had
 * been fetched: a feed read ten minutes ago is not news.
 */
const PREFETCH_FRESH = 3 * 60 * 1000

/** The identities being fetched ahead right now, so none is asked for twice. */
const prefetching = new Set()

import logger from '../services/logger.js'
import { isNewerId, newerId } from '../utils/snowflake.js'
import { noteTimelineRequest } from '../services/boot.js'
import { excludeTypesFor } from '../services/notifications.js'
import { useAccountStore } from './account.js'

/** Where the browser remembers that this reader's first post was celebrated. */
export const FIRST_POST_KEY = 'social.firstPostCelebrated'

/**
 * Whether this browser has already seen the celebration.
 *
 * Reading localStorage throws outright in a private window and wherever site
 * data is blocked, which is why this answers rather than raises: an
 * unreadable store means this guard is simply gone, and the account's own post
 * count below is the one that actually keeps an established reader from being
 * congratulated.
 *
 * @return {boolean} whether the flag is set
 */
function alreadyCelebrated() {
	try {
		return window.localStorage.getItem(FIRST_POST_KEY) !== null
	} catch {
		return false
	}
}

/**
 * Remembers that it happened, if the browser will remember anything.
 */
function rememberCelebrated() {
	try {
		window.localStorage.setItem(FIRST_POST_KEY, String(Date.now()))
	} catch {
		// nothing to do about it: `firstPostCelebrated` in the state still
		// stops a second celebration for as long as this page is open
	}
}

/**
 * What this store holds, for the helpers and getters that are handed it.
 *
 * Written down because they are plain functions taking the state as an
 * argument: without it every one of them read `state.statuses` off an untyped
 * `object`, which TypeScript 7 no longer lets through.
 *
 * @typedef {object} TimelineState
 * @property {Record<string, import('../types/Mastodon.js').Status>} statuses every status seen, by id
 * @property {string[]} timeline the ids on screen, newest first
 * @property {string[]} parentsTimeline the ids above a post being read in its thread
 * @property {Record<string, string>} removedFrom which list a removed status came from
 * @property {string} type which timeline this is
 * @property {Array|null} seededPage the first screenful the server rendered with the page
 * @property {{tag?: string, id?: string, account?: string, scope?: string, media?: string, filter?: string, url?: string, singlePost?: string}} params what the current list was asked for
 * @property {string} account whose timeline, where it is somebody's
 * @property {{identity: string, timeline: string[], parentsTimeline: string[], removedFrom: object, fetchedAt?: number}[]} remembered the lists lately visited
 * @property {boolean} restored whether the list was put back rather than loaded
 * @property {Object<string, true>} filled the lists that have shown a post this session, by identity
 * @property {boolean} composerDisplayStatus whether the composer is open
 * @property {string} searchQuery what is being searched for
 * @property {boolean} firstPostCelebration whether the celebration is on screen
 * @property {boolean} firstPostCelebrated whether this session has celebrated already
 * @property {string} arrivedId the post this reader has just published, while it makes its entrance
 * @property {string} homeReadUpTo the server's `home` read marker as last read or reported; '0' until known
 */

/**
 * What a list holds: a status, or a notification, which carries the post it
 * is about under `status` and is indexed under its own id like a status.
 *
 * @typedef {import('../types/Mastodon.js').Status & {status?: import('../types/Mastodon.js').Status|null}} IndexedEntry
 */

/**
 * Indexes a status, and the status it boosts, by id.
 *
 * A notification is indexed under its own id like anything else a list
 * holds, and so is the post it is about: the card reads that post from the
 * index, which is where a like or a boost on it lands.
 *
 * @param {TimelineState} state the store state
 * @param {IndexedEntry|null|undefined} status the entry to index
 */
function indexStatus(state, status) {
	if (status === undefined || status === null || status.id === undefined) {
		return
	}

	// assigned in place: replacing the whole map per status meant fifteen full
	// copies of every status in memory for each page that loaded
	state.statuses[status.id] = status
	if (status.reblog !== undefined && status.reblog !== null) {
		state.statuses[status.reblog.id] = status.reblog
	}
	if (status.type !== undefined) {
		indexStatus(state, status.status)
	}
}

/**
 * Drops from the index every status that no kept list names.
 *
 * The index is one map shared by the list on screen and the remembered ones,
 * so that a like, an edit or a delete reaches every list that shows the post
 * rather than the copy of the list it happened in. What keeps that from being
 * the leak `resetTimeline()` was written to stop is this: a status is kept
 * while some list the reader can come back to names it, and goes when none
 * does. The status a boost wraps and the post a notification is about are
 * indexed under their own ids and read by them, so they are kept with their
 * entry.
 *
 * @param {TimelineState} state the store state
 */
function pruneIndex(state) {
	const keep = new Set()
	const lists = [state.timeline, state.parentsTimeline]
	for (const held of state.remembered) {
		lists.push(held.timeline, held.parentsTimeline)
	}
	for (const list of lists) {
		for (const id of list) {
			keep.add(id)
			const entry = /** @type {IndexedEntry|undefined} */ (state.statuses[id])
			if (entry?.reblog) {
				keep.add(entry.reblog.id)
			}
			if (entry?.status) {
				keep.add(entry.status.id)
				if (entry.status.reblog) {
					keep.add(entry.status.reblog.id)
				}
			}
		}
	}

	/** @type {Record<string, import('../types/Mastodon.js').Status>} */
	const statuses = {}
	for (const id of keep) {
		if (state.statuses[id] !== undefined) {
			statuses[id] = state.statuses[id]
		}
	}
	state.statuses = statuses
}

/**
 * Whether the list is a ranking rather than a timeline: For you, whole or as
 * the For you scope of Photos and Videos, and a Bluesky feed or list, whose
 * order is the feed's own.
 *
 * @param {{type: string, params?: {scope?: string}}} state the store state, or anything with its type and params
 * @return {boolean}
 */
export function isRanked(state) {
	return state.type === 'interests'
		|| state.type === 'bluesky'
		|| (['photos', 'videos'].includes(state.type) && state.params?.scope === 'interests')
}

/**
 * @param {TimelineState} state the store state
 * @param {string[]} ids the ids of one of the two lists
 * @return {object[]} the statuses those ids name, newest first
 */
function sortedByDate(state, ids) {
	// Date.parse() in the comparator ran twice per comparison — about 2n·log n
	// string parses every time the getter recomputed, which is every time a page
	// arrived. Each date is parsed once here and the comparator only subtracts.
	return ids
		.map((statusId) => state.statuses[statusId])
		.filter(Boolean)
		.map((status) => ({ status, at: Date.parse(status.created_at) }))
		.sort((a, b) => b.at - a.at)
		.map((entry) => entry.status)
}

/**
 * Appends the statuses a list does not already hold, in order.
 *
 * The membership test was `list.indexOf(id) === -1` per incoming status, which
 * is a full walk of a reactive array for each one — quadratic in the length of
 * a timeline that pages. It also ran before any of the pushes, so a page that
 * carried the same status twice appended it twice; a Set that grows as the list
 * does catches that as well.
 *
 * @param {string[]} list the id list to append to, in place
 * @param {import('../types/Mastodon.js').Status[]} statuses what arrived
 */
function appendNew(list, statuses) {
	const known = new Set(list)
	for (const status of statuses) {
		if (known.has(status.id)) {
			continue
		}
		known.add(status.id)
		list.push(status.id)
	}
}

/**
 * Where a list is asked for, and what the request has to add to say which.
 *
 * Shared by the list on screen and by the ones fetched ahead of a switch, so
 * that a page fetched ahead is the page the list would have asked for.
 *
 * @param {{type: string, account: string, params: object}} list which list
 * @param {object} params the query, completed in place
 * @return {string} the url
 */
function timelineRequest(list, params) {
	let url
	switch (list.type) {
		case 'account':
			url = generateUrl(`apps/social/api/v1/accounts/${list.account}/statuses`)
			// the profile's Photos and Videos tabs. `media_type` is a
			// Social extension; `only_media` is Mastodon's own and
			// says what the two have in common, so a client that knows
			// neither still gets a sensible answer to the first.
			if (list.params.media === 'image' || list.params.media === 'video') {
				params.only_media = true
				params.media_type = list.params.media
			}
			break
		case 'tags':
			url = generateUrl(`apps/social/api/v1/timelines/tag/${list.params.tag}`)
			break
		case 'list':
			url = generateUrl(`apps/social/api/v1/timelines/list/${list.params.id}`)
			break
		case 'single-post':
			url = generateUrl(`apps/social/api/v1/statuses/${list.params.id}/context`)
			break
		case 'timeline':
			url = generateUrl('apps/social/api/v1/timelines/public')
			params.local = true
			break
		case 'federated':
			url = generateUrl('apps/social/api/v1/timelines/public')
			break
		case 'interests':
			url = generateUrl('apps/social/api/v1/timelines/interests')
			// narrowed to one kind, a ranking of its own on the server
			if (list.params.media === 'photos' || list.params.media === 'videos') {
				params.media = list.params.media
			}
			break
		case 'bluesky':
			// the feed's `at://` URI rides in the query, as a link does
			url = generateUrl('apps/social/api/v1/timelines/bluesky')
			params.feed = list.params.feed
			break
		case 'photos':
		case 'videos':
		// a timeline with the text-only posts left out: what people
		// showed rather than what they said. Which people is the scope
		// the switcher sets — the ones you follow by default, this
		// instance, or everywhere — so this is the same three feeds
		// above, one predicate narrower. Or For you narrowed to the
		// same kind, which is a ranking rather than a circle of people
		// and asks nothing about attachments: the server narrows it.
			if (list.params.scope === 'interests') {
				url = generateUrl('apps/social/api/v1/timelines/interests')
				params.media = list.type
				break
			}
			if (list.params.scope === 'timeline' || list.params.scope === 'federated') {
				url = generateUrl('apps/social/api/v1/timelines/public')
				if (list.params.scope === 'timeline') {
					params.local = true
				}
			} else {
				url = generateUrl('apps/social/api/v1/timelines/home')
			}
			// `only_media` is Mastodon's question — "does this post
			// carry an attachment" — and it is not the question either
			// of these pages is asking: it answered Photos with every
			// video on the instance and Videos with every photograph.
			// `media_type` and `only_video` are this app's own and name
			// the kind. All of them go out, so a server that has not
			// been upgraded yet still answers with media rather than
			// with everything.
			params.only_media = true
			if (list.type === 'videos') {
				params.only_video = true
			} else {
				// a post carrying both is `mixed` and is in both pages,
				// which is what `limitToMediaType()` already says
				params.media_type = 'image'
			}
			break
		case 'link':
		// everything said here about one article. The link is the
		// subject, so it rides in the query rather than the path: a URL
		// inside a path segment is a URL that has to survive two rounds
		// of encoding and one web server's idea of what a slash means.
			url = generateUrl('apps/social/api/v1/timelines/link')
			params.url = list.params.url ?? ''
			break
		case 'notifications': {
			url = generateUrl('apps/social/api/v1/notifications')
			// the page's filter, as the server takes it: what to leave
			// out. Part of the params, so changing it is a different
			// timeline and refetches rather than filtering the page
			const excluded = excludeTypesFor(list.params.filter ?? 'all')
			if (excluded.length > 0) {
				params.exclude_types = excluded
			}
			break
		}
		case 'bookmarks':
		// the only timeline the server serves without a trailing slash
			url = generateUrl('apps/social/api/v1/bookmarks')
			break
		default:
			url = generateUrl(`apps/social/api/v1/timelines/${list.type}`)
	}

	return url
}

/**
 * The list currently on screen: which one it is, what it holds, and everything
 * the reader does to a post in it.
 */
/** How long after a conversation is opened its replies from elsewhere are asked for again. */
const THREAD_REFETCH_MS = 6000

export const useTimelineStore = defineStore('timeline', {
	state: () => ({
		statuses: {},
		timeline: [],
		parentsTimeline: [],
		/** which list a removed status came from, so a rollback restores it there */
		removedFrom: {},
		type: 'home',

		/**
		 * The first screenful of the home timeline, put in the document by the
		 * server that rendered it — see `NavigationController::provideFirstPage()`.
		 * Read once and then dropped; null on every page that was not rendered
		 * with one.
		 *
		 * @type {Array|null}
		 */
		seededPage: loadState('social', 'firstPage', null),
		/** @type {{tag?: string, id?: string, account?: string, scope?: string, media?: string, filter?: string, url?: string, singlePost?: string}} */
		params: {},
		account: '',
		/**
		 * The lists the reader has been in lately, kept so that coming back to
		 * one finds the pages they had loaded.
		 *
		 * A few, not one, and not all of them. One was enough for Back out of a
		 * post, and wrong for the thing people actually do: My Feed, Local,
		 * Global and back is four switches, and with a single slot three of
		 * them threw the list away and asked the server again — 150 ms of
		 * skeleton where content had been. All of them would be the leak
		 * `resetTimeline()` was written to stop, because the index keeps every
		 * status they name, so this is capped at `REMEMBERED` and the oldest
		 * goes.
		 *
		 * Only the ids are held. The statuses themselves stay in the one
		 * `statuses` map, so a like or an edit in one list is seen in every
		 * list that shows the post; a copy per list showed the reader the post
		 * as it was when they left.
		 *
		 * A list fetched ahead of a switch is held here too, marked with when
		 * it was fetched, so that it is only put back while still fresh.
		 *
		 * @type {{identity: string, timeline: string[], parentsTimeline: string[], removedFrom: object, fetchedAt?: number}[]}
		 */
		remembered: [],
		/**
		 * Whether the list on screen was put back rather than loaded: what tells
		 * the view that it already holds its pages and must not ask for another
		 * one on top of them.
		 */
		restored: false,
		/**
		 * The lists that have shown at least one post this session, by
		 * identity. A switch holds the list being left on screen only while
		 * the next one is known to have something to replace it with; a feed
		 * opened for the first time, or one that was empty last time, shows
		 * its own loading state rather than somebody else's posts that then
		 * vanish.
		 *
		 * @type {Object<string, true>}
		 */
		filled: {},
		composerDisplayStatus: false,
		searchQuery: '',
		/** whether the one-time first-post celebration is on screen right now */
		firstPostCelebration: false,
		/**
		 * whether this session has already celebrated — the guard that still holds
		 * when the browser refuses to remember anything
		 */
		firstPostCelebrated: false,
		/**
		 * The post this reader has just published, for the moment it takes to
		 * make its entrance at the top of the timeline; '' the rest of the time.
		 */
		arrivedId: '',
		/**
		 * The `home` read marker as the server last stated it or was told it:
		 * the position every Mastodon client shares. '0' until known.
		 */
		homeReadUpTo: '0',
	}),

	getters: {
		/**
		 * @param {TimelineState} state the store state
		 * @return {(identity: string) => boolean} whether that list has had a post this session
		 */
		wasFilled(state) {
			return (identity) => state.filled[identity] === true
		},
		/**
		 * @param {TimelineState} state the store state
		 * @return {boolean} whether the composer is open
		 */
		getComposerDisplayStatus(state) {
			return state.composerDisplayStatus
		},
		/**
		 * The timeline, newest first.
		 *
		 * This used to also filter by `searchQuery` with String.includes over the
		 * ~15 statuses that happened to be loaded, which answered "No posts match
		 * your search" for posts the instance was holding. Searching now asks the
		 * server (`/api/v2/search`), so the timeline is only the timeline.
		 *
		 * @param {TimelineState} state the store state
		 * @return {object[]} the statuses
		 */
		getTimeline(state) {
			// For you is ranked, not chronological: the server's order is
			// the point of it, and sorting it by date would undo the ranking
			if (isRanked(state)) {
				return state.timeline.map((statusId) => state.statuses[statusId]).filter(Boolean)
			}

			return sortedByDate(state, state.timeline)
		},
		/**
		 * @param {TimelineState} state the store state
		 * @return {object[]} the ancestors of the post on screen, newest first
		 */
		getParentsTimeline(state) {
			return sortedByDate(state, state.parentsTimeline)
		},
		/**
		 * @param {TimelineState} state the store state
		 * @return {string} what the reader searched for
		 */
		getSearchQuery(state) {
			return state.searchQuery
		},
		/**
		 * @param {TimelineState} state the store state
		 * @return {boolean} whether the celebration is on screen
		 */
		isCelebratingFirstPost(state) {
			return state.firstPostCelebration
		},
		/**
		 * What list the store is currently holding, as one comparable value.
		 *
		 * The router-view is no longer keyed on the full path, so a view — and the
		 * TimelineList inside it — is reused across a navigation. This is what
		 * tells the list that the thing it is showing has been swapped underneath
		 * it, whether by a type, a tag, an account or a single post.
		 *
		 * @param {TimelineState} state the store state
		 * @return {string} the identity of the current timeline
		 */
		getTimelineIdentity(state) {
			return JSON.stringify([state.type, state.account, state.params])
		},
		/**
		 * @param {TimelineState} state the store state
		 * @return {(statusId: string) => object|undefined} id -> status
		 */
		getStatus(state) {
			return (statusId) => state.statuses[statusId]
		},
		/**
		 * @param {TimelineState} state the store state
		 * @return {object|undefined} the post a single-post view is showing
		 */
		getSinglePost(state) {
			return state.statuses[state.params.singlePost]
		},
		/**
		 * @param {TimelineState} state the store state
		 * @return {(statusId: string) => object|undefined} id -> status, with a log line when there is none
		 */
		getPostFromTimeline(state) {
			return (statusId) => {
				if (state.statuses[statusId] !== undefined) {
					return state.statuses[statusId]
				} else {
					logger.warn('Could not find status in timeline', { statusId })
				}
			}
		},
	},

	actions: {
		/**
		 * Where this reader had got to on the home timeline, from the server.
		 *
		 * The `home` marker of `GET /api/v1/markers` is the one every Mastodon
		 * client reads and writes, so a feed read on the phone moves the line
		 * here and the other way round. Asked when the home timeline opens.
		 * A failure answers null rather than a guess: the list is still
		 * readable, and the line falls back to what this browser remembers.
		 *
		 * @return {Promise<{id: string, at: number}|null>} the marker and when it was last moved; null when the server has none, or did not answer
		 */
		async fetchHomeMarker() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/markers'), {
					params: { timeline: ['home'] },
				})
				const id = String(data?.home?.last_read_id ?? '')
				if (!isNewerId(id, '0')) {
					return null
				}
				this.homeReadUpTo = newerId(id, this.homeReadUpTo)

				return { id, at: Date.parse(data.home.updated_at ?? '') || 0 }
			} catch (error) {
				logger.error('Failed to read the home marker', { error })

				return null
			}
		},

		/**
		 * Tells the server the home timeline has been read up to `id`.
		 *
		 * Said once per position: the server never moves a marker backwards,
		 * so there is nothing to say about an id it already holds or has
		 * passed. Quiet on failure — a place not saved is a line drawn a
		 * little low next time, not something to interrupt reading for.
		 *
		 * @param {string|number} id the newest post the reader has had on screen
		 */
		async markHomeRead(id) {
			const newest = String(id ?? '')
			if (!isNewerId(newest, this.homeReadUpTo)) {
				return
			}
			this.homeReadUpTo = newest

			try {
				await axios.post(generateUrl('apps/social/api/v1/markers'), {
					home: { last_read_id: newest },
				})
			} catch (error) {
				logger.error('Failed to move the home marker', { error })
			}
		},

		addToStatuses(status) {
			indexStatus(this, status)
		},

		/**
		 * Records that the list named has shown a post.
		 *
		 * @param {string} identity the list, as `getTimelineIdentity` names it
		 */
		markFilled(identity) {
			if (!this.filled[identity]) {
				this.filled = { ...this.filled, [identity]: true }
			}
		},

		/**
		 * The first page the server put in the document, if this request is
		 * the one it was rendered for.
		 *
		 * Consumed rather than read: it answers the first fetch of the home
		 * timeline and nothing afterwards, because it is a snapshot of one
		 * moment and a second request means the reader asked for something
		 * else — a cursor, a filter, a refresh.
		 *
		 * @param {object} params what the fetch was going to ask for
		 * @return {Array|null} the posts, or null to go and ask
		 */
		takeSeededPage(params) {
			if (this.seededPage === null || this.type !== 'home') {
				return null
			}

			const page = this.seededPage
			this.seededPage = null

			// a cursor, a narrowing, or any other question the seeded page was
			// not the answer to
			const asked = { ...params }
			delete asked.limit
			if (Object.keys(asked).length > 0) {
				return null
			}

			return page
		},
		addToTimeline(data) {
			if (Array.isArray(data)) {
				data.forEach((status) => indexStatus(this, status))
				appendNew(this.timeline, data)
			} else {
				data.descendants.forEach((status) => indexStatus(this, status))
				data.ancestors.forEach((status) => indexStatus(this, status))

				appendNew(this.timeline, data.descendants)
				appendNew(this.parentsTimeline, data.ancestors)
			}
		},
		/**
		 * Takes a status off the list on screen.
		 *
		 * @param {import('../types/Mastodon.js').Status} status the status to take off
		 * @param {boolean} [gone] whether the status itself is gone — deleted,
		 *                         archived — and leaves the index too, so a
		 *                         remembered list drops it on return. One that
		 *                         is only off this list, unliked out of the
		 *                         likes or unbookmarked out of the bookmarks,
		 *                         stays known to the lists that still show it.
		 */
		removeStatus(status, gone = true) {
			const timelineIndex = this.timeline.indexOf(status.id)
			if (timelineIndex !== -1) {
				this.timeline.splice(timelineIndex, 1)
			}
			const parentsTimelineIndex = this.parentsTimeline.indexOf(status.id)
			if (parentsTimelineIndex !== -1) {
				this.parentsTimeline.splice(parentsTimelineIndex, 1)
			}
			// which list it came from, so a failed delete puts it back where it was
			this.removedFrom = { ...this.removedFrom, [status.id]: parentsTimelineIndex !== -1 ? 'parents' : 'timeline' }
			if (gone) {
				delete this.statuses[status.id]
			}
		},
		/**
		 * Puts a status back after a delete the server refused. `addToTimeline`
		 * always appended to `timeline`, so a failed delete of an ancestor
		 * reappeared among the replies.
		 *
		 * @param {import('../types/Mastodon.js').Status} status the status that could not be deleted
		 * @param {number} [index] where in the list it was, for a list whose
		 *                         order is not its dates (For you)
		 */
		restoreStatus(status, index = -1) {
			indexStatus(this, status)
			const list = this.removedFrom?.[status.id] === 'parents' ? 'parentsTimeline' : 'timeline'
			if (this[list].indexOf(status.id) === -1) {
				if (index >= 0 && index <= this[list].length) {
					this[list].splice(index, 0, status.id)
				} else {
					this[list].push(status.id)
				}
			}
			const removedFrom = { ...this.removedFrom }
			delete removedFrom[status.id]
			this.removedFrom = removedFrom
		},
		/**
		 * Drops the "where it came from" hint for a removal that is final. Only
		 * restoreStatus cleared it, so unliking from the liked timeline and
		 * un-bookmarking from the bookmarks left a hint behind for a status that
		 * is not coming back — and the next rollback of that id read it.
		 *
		 * @param {import('../types/Mastodon.js').Status} status the status whose removal stands
		 */
		forgetRemoval(status) {
			if (this.removedFrom[status.id] === undefined) {
				return
			}
			const removedFrom = { ...this.removedFrom }
			delete removedFrom[status.id]
			this.removedFrom = removedFrom
		},
		removeStatusesByActor(accountId) {
			const id = String(accountId)
			const isByActor = (status) => String(status?.account?.id) === id
				|| (status?.reblog && String(status.reblog.account?.id) === id)
			const removed = new Set(Object.values(this.statuses).filter(isByActor).map((status) => status.id))
			if (removed.size === 0) {
				return
			}
			this.timeline = this.timeline.filter((statusId) => !removed.has(statusId))
			this.parentsTimeline = this.parentsTimeline.filter((statusId) => !removed.has(statusId))
			const statuses = { ...this.statuses }
			removed.forEach((statusId) => delete statuses[statusId])
			this.statuses = statuses
		},
		resetTimeline() {
			this.timeline = []
			this.parentsTimeline = []
			this.removedFrom = {}
			// the id lists used to be the only thing cleared, so `statuses` grew
			// for the whole session: every page of every timeline ever opened.
			// Pruned rather than emptied, because the remembered lists read it
			pruneIndex(this)
		},
		setTimelineType(type) {
			this.type = type
		},
		setTimelineParams(params) {
			this.params = params
		},
		setComposerDisplayStatus(status) {
			this.composerDisplayStatus = status
		},
		setAccount(account) {
			this.account = account
		},
		setSearchQuery(query) {
			this.searchQuery = query
		},
		/**
		 * Marks a post as the one just published, and unmarks it once its
		 * entrance is over -- so a timeline that is reloaded a minute later
		 * does not play it a second time.
		 *
		 * @param {string} id the new post's id
		 * @param {number} [lasts] how long the entrance runs, in milliseconds
		 */
		markArrived(id, lasts = 2400) {
			this.arrivedId = String(id ?? '')
			window.setTimeout(() => {
				if (this.arrivedId === String(id ?? '')) {
					this.arrivedId = ''
				}
			}, lasts)
		},
		startFirstPostCelebration() {
			this.firstPostCelebration = true
			this.firstPostCelebrated = true
		},
		likeStatus({ status }) {
			const known = this.statuses[status.id]
			if (known !== undefined) {
				this.statuses[status.id] = { ...known, favourited: true, favourites_count: (known.favourites_count ?? 0) + 1 }
			}
		},
		unlikeStatus({ status }) {
			const known = this.statuses[status.id]
			if (known !== undefined) {
				this.statuses[status.id] = { ...known, favourited: false, favourites_count: Math.max((known.favourites_count ?? 0) - 1, 0) }
			}
		},
		boostStatus({ status }) {
			const known = this.statuses[status.id]
			if (known !== undefined) {
				this.statuses[status.id] = { ...known, reblogged: true, reblogs_count: (known.reblogs_count ?? 0) + 1 }
			}
		},
		unboostStatus({ status }) {
			const known = this.statuses[status.id]
			if (known !== undefined) {
				this.statuses[status.id] = { ...known, reblogged: false, reblogs_count: Math.max((known.reblogs_count ?? 0) - 1, 0) }
			}
		},
		/**
		 * A vote reaches every view of the same post, not just the component that
		 * cast it.
		 *
		 * @param {object} payload the status and its new poll
		 * @param {string} payload.statusId the status that carries the poll
		 * @param {object} payload.poll the poll as the server returned it
		 */
		/**
		 * Whether a post of the reader's own is put away.
		 *
		 * In the store rather than on the card: the same post is often drawn
		 * twice — a thread and the timeline behind it — and a card that flipped
		 * its own copy would leave the other one saying the opposite.
		 *
		 * @param {object} root0 the post and its new state
		 * @param {string} root0.statusId the post
		 * @param {boolean} root0.archived whether it is now archived
		 */
		updateStatusArchived({ statusId, archived }) {
			const known = this.statuses[statusId]
			if (known !== undefined) {
				this.statuses[statusId] = { ...known, archived }
			}
		},
		/**
		 * The people named in a post's pictures, after somebody changed them.
		 *
		 * In the store for the same reason as the archive flag: one post is
		 * often drawn twice, and a card that edited its own copy would leave
		 * the other one still carrying a name that has been taken off.
		 *
		 * @param {object} root0 the post and its new list
		 * @param {string} root0.statusId the post
		 * @param {Array} root0.taggedPeople who it names now
		 */
		updateStatusTagged({ statusId, taggedPeople }) {
			const known = this.statuses[statusId]
			if (known !== undefined) {
				this.statuses[statusId] = { ...known, tagged_people: taggedPeople }
			}
		},
		/**
		 * Who may reply to a post, after its author changed it.
		 *
		 * @param {object} root0 the post and its new policy
		 * @param {string} root0.statusId the post
		 * @param {string} root0.replyPolicy who may reply now
		 */
		updateStatusReplyPolicy({ statusId, replyPolicy }) {
			const known = this.statuses[statusId]
			if (known !== undefined) {
				this.statuses[statusId] = { ...known, reply_policy: replyPolicy }
			}
		},
		updateStatusPoll({ statusId, poll }) {
			const known = this.statuses[statusId]
			if (known !== undefined) {
				this.statuses[statusId] = { ...known, poll }
			}
		},
		/**
		 * The reaction bar of a post, as the server rebuilt it.
		 *
		 * Kept in the store rather than on the card, so the same post shown
		 * twice — a thread and the timeline behind it — does not end up with
		 * two different bars.
		 *
		 * @param {object} root0 the arguments
		 * @param {string} root0.statusId which post
		 * @param {Array<{name: string, count: number, me: boolean}>} root0.reactions the bar
		 */
		updateStatusReactions({ statusId, reactions }) {
			const known = this.statuses[statusId]
			if (known !== undefined) {
				this.statuses[statusId] = { ...known, reactions }
			}
		},
		bookmarkStatus({ status, bookmarked }) {
			if (this.statuses[status.id] !== undefined) {
				this.statuses[status.id] = { ...this.statuses[status.id], bookmarked }
			}
		},
		/**
		 * Flips the mute on the store's copy of a post, taking the post into
		 * the store when it is not there yet: a notification's card draws the
		 * store's copy where there is one, its own snapshot otherwise.
		 *
		 * @param {object} payload which post, and which way
		 * @param {import('../types/Mastodon.js').Status} payload.status the post
		 * @param {boolean} payload.muted whether its conversation is muted
		 */
		muteConversationOf({ status, muted }) {
			this.statuses[status.id] = { ...(this.statuses[status.id] ?? status), muted }
		},
		pinStatus({ status, pinned }) {
			if (this.statuses[status.id] !== undefined) {
				this.statuses[status.id] = { ...this.statuses[status.id], pinned }
			}
		},
		updateStatus(updatedStatus) {
			if (this.statuses[updatedStatus.id] !== undefined) {
				this.statuses[updatedStatus.id] = updatedStatus
			}
		},
		/**
		 * Decides whether the post that just went out is the first this reader has
		 * ever published, and starts the celebration if it is.
		 *
		 * Two guards, because neither is right on its own. The stored flag is what
		 * stops the second post of the same account being celebrated, and it is
		 * cheap — no request, no endpoint. The account's own `statuses_count` is
		 * what stops a reader of five years who cleared their browser storage from
		 * being congratulated on post number 1001; it is already in the store,
		 * fetched once when the page opened, so reading it costs nothing and it
		 * does not yet count the post that just went out.
		 *
		 * An account that has not loaded has an unknown count, and unknown is not
		 * proof of a first post: nothing happens.
		 *
		 * @return {boolean} whether the celebration was started
		 */
		celebrateFirstPost() {
			if (this.firstPostCelebration || this.firstPostCelebrated) {
				return false
			}
			if (useAccountStore().currentAccount?.statuses_count !== 0) {
				return false
			}
			if (alreadyCelebrated()) {
				return false
			}

			rememberCelebrated()
			this.startFirstPostCelebration()
			return true
		},
		endFirstPostCelebration() {
			this.firstPostCelebration = false
		},
		changeTimelineType({ type, params }) {
			this.switchTimeline(type, params, '')
		},
		/**
		 * @param {string} account whose posts to show
		 * @param {string} media which kind of attachment to keep, '' for all
		 */
		changeTimelineTypeAccount(account, media = '') {
			// the media kind is part of what identifies this timeline, so that
			// changing the tab asks the server again rather than filtering the
			// page on screen
			this.switchTimeline('account', media === '' ? {} : { media }, account)
		},
		/**
		 * Points the store at a list, keeping what is loaded when it can.
		 *
		 * Opening a post and pressing Back mounts the timeline view again,
		 * which comes through here. It used to throw the whole index away every
		 * time: the reader came back to the first fifteen posts of a list they
		 * had read four pages into, so the browser had nothing to put the
		 * scroll offset back on and clamped it to the bottom of what little was
		 * there. The list is now only cleared when it is genuinely a different
		 * list, and the one just left is remembered so that coming straight
		 * back to it — which is what Back out of a post is — finds it whole.
		 *
		 * An explicit reset is still `resetTimeline()`, and a reload of the
		 * page is still a reload.
		 *
		 * @param {string} type which timeline
		 * @param {object} params what narrows it: a tag, a list id, a post
		 * @param {string} account whose posts, for a profile
		 */
		switchTimeline(type, params, account) {
			const left = {
				identity: this.getTimelineIdentity,
				timeline: this.timeline,
				parentsTimeline: this.parentsTimeline,
				removedFrom: this.removedFrom,
			}

			this.setTimelineType(type)
			this.setTimelineParams(params)
			this.setAccount(account)

			if (this.getTimelineIdentity === left.identity) {
				return
			}

			const wanted = this.getTimelineIdentity
			const held = this.remembered.find((entry) => entry.identity === wanted) ?? null
			const returning = held !== null && (held.fetchedAt === undefined || Date.now() - held.fetchedAt < PREFETCH_FRESH) ? held : null
			// the one being left goes to the front, the one being returned to
			// is taken out, and the oldest falls off the end
			this.remembered = [left, ...this.remembered.filter((held) => held.identity !== wanted && held.identity !== left.identity)].slice(0, REMEMBERED)
			this.restored = returning !== null

			if (returning === null) {
				this.resetTimeline()
				return
			}

			// the ids, read back against the shared index: a post deleted
			// while the reader was in another list has left it, and the list
			// drops it rather than naming a post that is not there. The
			// index is then pruned of whatever the list that fell off the
			// shelf was the last to name
			const stillKnown = (statusId) => this.statuses[statusId] !== undefined
			this.timeline = returning.timeline.filter(stillKnown)
			this.parentsTimeline = returning.parentsTimeline.filter(stillKnown)
			this.removedFrom = returning.removedFrom
			pruneIndex(this)
		},
		/**
		 * Tells the server what an attachment shows, so it federates as alt text.
		 *
		 * @param {object} media the attachment
		 * @param {string} media.id its id on this server
		 * @param {string} media.description what it shows
		 */
		/**
		 * Fetches the first page of a list the reader is likely to switch to,
		 * and holds it with the remembered lists, so that the switch puts it
		 * on screen at once instead of waiting for the server.
		 *
		 * Quiet: a failure is the switch asking for itself, as it always has.
		 * Put in front of the lists the reader has left, because from the feed
		 * the next list is most likely another scope of it.
		 *
		 * @param {object} list which list
		 * @param {string} list.type the timeline type, as the store names it
		 * @param {object} [list.params] what narrows it
		 */
		async prefetchTimeline({ type, params = {} }) {
			const identity = JSON.stringify([type, '', params])
			const held = this.remembered.find((entry) => entry.identity === identity)
			if (identity === this.getTimelineIdentity
				|| prefetching.has(identity)
				|| (held !== undefined && (held.fetchedAt === undefined || Date.now() - held.fetchedAt < PREFETCH_FRESH))) {
				return
			}

			const query = { limit: 15 }
			const url = timelineRequest({ type, account: '', params }, query)
			prefetching.add(identity)
			let data
			try {
				({ data } = await axios.get(url, { params: query }))
			} catch (error) {
				logger.debug('Could not fetch a timeline ahead', { identity, error })
				return
			} finally {
				prefetching.delete(identity)
			}

			// the reader got there first, and the list's own request answers
			if (!Array.isArray(data) || identity === this.getTimelineIdentity) {
				return
			}

			data.forEach((status) => indexStatus(this, status))
			const ids = []
			appendNew(ids, data)
			if (ids.length > 0) {
				this.markFilled(identity)
			}

			this.remembered = [
				{ identity, timeline: ids, parentsTimeline: [], removedFrom: {}, fetchedAt: Date.now() },
				...this.remembered.filter((entry) => entry.identity !== identity),
			].slice(0, REMEMBERED)
			pruneIndex(this)
		},
		async describeMedia({ id, description }) {
			try {
				await axios.put(generateUrl('apps/social/api/v1/media/' + id), { description })
			} catch (error) {
				// the post itself is still worth sending; say so and carry on
				showError(t('social', 'Could not save the description of an attachment'))
				logger.error('Failed to describe a media', { error })
			}
		},

		/**
		 * Tells the server where the subject of an attachment is, so a square
		 * crop — the profile grid here, the timeline on Mastodon — keeps it in
		 * frame. The same `PUT` the description travels by; it federates as
		 * `focalPoint`.
		 *
		 * @param {object} media the attachment
		 * @param {string} media.id its id on this server
		 * @param {string} media.focus Mastodon's `x,y`, each from -1 to 1
		 */
		async focusMedia({ id, focus }) {
			try {
				await axios.put(generateUrl('apps/social/api/v1/media/' + id), { focus })
			} catch (error) {
				// the picture is still attached and still centred, which is
				// what it was before the point was set
				showError(t('social', 'Could not save the focal point of an attachment'))
				logger.error('Failed to set the focal point of a media', { error })
			}
		},

		/**
		 * Uploads one attachment.
		 *
		 * @param {File|{file: File, onProgress?: (fraction: number) => void, thumbnail?: Blob|null}} payload the file, or `{file, onProgress, thumbnail}`, the thumbnail being a chosen cover for a video
		 * @return {Promise<object|undefined>} the media entity, or undefined when the server refused
		 */
		async createMedia(payload) {
			const file = payload instanceof File ? payload : payload.file
			const onProgress = payload instanceof File ? undefined : payload.onProgress
			// a cover for a video, sent as Mastodon names it
			const thumbnail = payload instanceof File ? null : (payload.thumbnail ?? null)
			try {
				const formData = new FormData()
				formData.append('file', file)
				if (thumbnail instanceof Blob) {
					formData.append('thumbnail', thumbnail, 'cover.jpg')
				}
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/media'),
					formData,
					{
						headers: {
							'Content-Type': 'multipart/form-data',
						},
						onUploadProgress: typeof onProgress === 'function'
							? (event) => onProgress(event.total ? Math.min(event.loaded / event.total, 1) : 0)
							: undefined,
					},
				)
				logger.info('Media created with id ' + data.id)
				return data
			} catch (error) {
				// a refusal says why — too large, a kind the server does not
				// take — and the reason is worth more than a fixed line; a
				// network failure or a 5xx has no reason worth repeating
				const status = error.response?.status ?? 0
				const reason = error.response?.data?.error
				showError(status >= 400 && status < 500 && typeof reason === 'string' && reason !== ''
					? reason
					: t('social', 'Could not upload the attachment'))
				logger.error('Failed to create a media', { error })
			}
		},
		/**
		 * Attaches a file the reader already keeps in Nextcloud.
		 *
		 * The bytes never leave the server: the path is all the browser sends, and
		 * what comes back is the same attachment an upload would have produced.
		 *
		 * @param {object} media what to attach
		 * @param {string} media.path the file, relative to the reader's own files
		 * @param {string} [media.description] what it shows
		 * @return {Promise<object|undefined>} the media entity, or undefined when the server refused
		 */
		/**
		 * Copies a picture out of the instance's shared library onto a post.
		 *
		 * @param {object} root0 the arguments
		 * @param {string} root0.slug which library picture
		 * @param {string} [root0.description] alt text; the picture's own title is used when empty
		 * @return {Promise<object|undefined>} the attachment, or undefined when it was refused
		 */
		async createMediaFromGif({ slug, description = '' }) {
			try {
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/media/from-gif'),
					{ slug, description },
				)
				logger.info('Media created from the library picture ' + slug + ' with id ' + data.id)
				return data
			} catch (error) {
				showError(t('social', 'Could not attach {file}', { file: slug }))
				logger.error('Failed to attach a picture from the library', { error })
			}
		},
		async createMediaFromFile({ path, description = '' }) {
			try {
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/media/from-file'),
					{ path, description },
				)
				logger.info('Media created from ' + path + ' with id ' + data.id)
				return data
			} catch (error) {
				// named, because a picker run attaches several at once and "it
				// failed" would not say which one to try again
				showError(t('social', 'Could not attach {file}', { file: path }))
				logger.error('Failed to attach a file from Nextcloud', { error })
			}
		},
		/**
		 * Sends a status.
		 *
		 * Resolves with what the server created and with `undefined` when it
		 * refused, so the composer can tell the two apart: it used to clear itself
		 * either way, and an offline moment threw away what was typed.
		 *
		 * @param {object} status the status to send
		 * @return {Promise<object|undefined>} the created status, or undefined
		 */
		async post(status) {
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/statuses'), status)
				logger.info('Post created', data.id)
				return data
			} catch (error) {
				// A post the server held for a moderator is not a failure: it
				// has been kept, and writing it again would only put a second
				// copy in the queue. Said as information, and the box is
				// cleared, because there is nothing left for the author to do.
				if (error.response?.data?.held_for_review === true) {
					showInfo(t('social', 'Your post is waiting for a moderator to look at it. It has been kept — there is no need to write it again.'))
					logger.info('Post held for review')
					return { held_for_review: true }
				}
				showError(t('social', 'Could not send the post'))
				logger.error('Failed to create a status', { error })
			}
		},
		async postEdit({ status, content, spoiler_text, sensitive }) {
			try {
				const response = await axios.put(
					generateUrl(`apps/social/api/v1/statuses/${status.id}`),
					{ status: content, spoiler_text, sensitive },
				)
				this.updateStatus(response.data)
				logger.info('Post edited', response.data.id)
				return response
			} catch (error) {
				showError(t('social', 'Could not save the changes to the post'))
				logger.error('Failed to edit the status', { error })
			}
		},
		async postDelete(status) {
			try {
				this.removeStatus(status)
				const response = await axios.delete(generateUrl(`apps/social/api/v1/post?id=${status.uri}`))
				logger.info('Post deleted with token ' + response.data.result.token)
			} catch (error) {
				// restoreStatus puts it back in the list it came from; addToTimeline
				// always appended to the replies, so a failed delete of an ancestor
				// reappeared among its own answers
				this.restoreStatus(status)
				showError(t('social', 'Could not delete the post'))
				logger.error('Failed to delete the status', { error })
			}
		},
		/**
		 * One status by its id, for a page that has to draw a post nothing has
		 * loaded yet: a link somebody sent, a reload, or a tile on Discover,
		 * whose posts are the view's own and never went through here.
		 *
		 * `/context` answers with the posts around one, never the post itself,
		 * so without this the page had nothing to draw and said the post did
		 * not exist.
		 *
		 * @param {string} id the status id
		 * @return {Promise<object|null>} the status, or null if it is not there
		 */
		async fetchStatus(id) {
			try {
				const response = await axios.get(generateUrl(`apps/social/api/v1/statuses/${id}`))
				this.addToStatuses(response.data)

				return response.data
			} catch (error) {
				// a deleted post and one this server never had look the same
				// from here, and the page says so either way
				logger.debug('Could not load a single status', { error, id })

				return null
			}
		},
		async postLike({ status }) {
			try {
				this.likeStatus({ status })
				const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/favourite`))
				logger.info('Post liked')
				this.addToStatuses(response.data)
				return response
			} catch (error) {
				this.unlikeStatus({ status })
				showError(t('social', 'Could not like the post'))
				logger.error('Failed to like status', { error })
			}
		},
		/**
		 * PeerTube's other counter, which only a video carries.
		 *
		 * No optimistic update: unlike a like, nothing on the page depends on
		 * it being instant, and the count that matters is the author's
		 * server's. The answer carries the new one.
		 *
		 * @param {object} root0 the status to dislike
		 * @param {import('../types/Mastodon.js').Status} root0.status that status
		 * @return {Promise<object|undefined>} the updated status
		 */
		async postDislike({ status }) {
			try {
				const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/dislike`))
				this.addToStatuses(response.data)
				return response
			} catch (error) {
				showError(t('social', 'Could not dislike the video'))
				logger.error('Failed to dislike status', { error })
			}
		},

		/**
		 * @param {object} root0 the status to stop disliking
		 * @param {import('../types/Mastodon.js').Status} root0.status that status
		 * @return {Promise<object|undefined>} the updated status
		 */
		async postUndislike({ status }) {
			try {
				const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/undislike`))
				this.addToStatuses(response.data)
				return response
			} catch (error) {
				showError(t('social', 'Could not take the dislike back'))
				logger.error('Failed to undislike status', { error })
			}
		},

		async postUnlike({ status }) {
			try {
				if (this.type === 'favourites') {
					// off this list, not gone: the home timeline behind it
					// still shows the post
					this.removeStatus(status, false)
				}
				this.unlikeStatus({ status })
				const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/unfavourite`))
				logger.info('Post unliked')
				this.addToStatuses(response.data)
				this.forgetRemoval(status)
				return response
			} catch (error) {
				if (this.type === 'favourites') {
					// restoreStatus puts back the caller's pre-unlike copy of the
					// status (favourited, original count) — a likeStatus on top of
					// that would count the like twice.
					this.restoreStatus(status)
				} else {
					this.likeStatus({ status })
				}
				showError(t('social', 'Could not remove the like'))
				logger.error('Failed to unlike status', { error })
			}
		},
		async postBoost({ status }) {
			try {
				this.boostStatus({ status })
				const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/reblog`))
				logger.info('Post boosted')
				this.addToStatuses(response.data)
				return response
			} catch (error) {
				this.unboostStatus({ status })
				showError(t('social', 'Could not boost the post'))
				logger.error('Failed to create a boost status', { error })
			}
		},
		async postUnBoost({ status }) {
			try {
				this.unboostStatus({ status })
				const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/unreblog`))
				logger.info('Boost deleted')
				this.addToStatuses(response.data)
				return response
			} catch (error) {
				this.boostStatus({ status })
				showError(t('social', 'Could not undo the boost'))
				logger.error('Failed to delete the boost', { error })
			}
		},
		/**
		 * Mutes or unmutes the conversation a post belongs to, for this reader.
		 *
		 * The thread stays on every timeline; only what it would tell the
		 * reader stops. The server keeps the mute against the thread's root,
		 * so the flag the server answers with is the one to trust — a reply
		 * elsewhere in the same thread is muted too, and this one copy is
		 * what is flipped at once so the menu answers.
		 *
		 * @param {object} payload which post, and which way
		 * @param {import('../types/Mastodon.js').Status} payload.status the post
		 * @param {boolean} payload.muted whether the conversation is to be muted
		 */
		async postMuteConversation({ status, muted }) {
			this.muteConversationOf({ status, muted })
			try {
				const action = muted ? 'mute' : 'unmute'
				const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/${action}`))
				this.addToStatuses(response.data)
				showSuccess(muted
					? t('social', 'Conversation muted — you won\'t be notified about it')
					: t('social', 'Conversation unmuted'))

				return response
			} catch (error) {
				this.muteConversationOf({ status, muted: !muted })
				showError(muted
					? t('social', 'Could not mute the conversation')
					: t('social', 'Could not unmute the conversation'))
				logger.error('Failed to change the conversation mute', { error })
			}
		},

		async postBookmark({ status, bookmarked }) {
			// the flag flips first so the button answers at once, and is put back
			// if the server refuses
			this.bookmarkStatus({ status, bookmarked })
			try {
				const action = bookmarked ? 'bookmark' : 'unbookmark'
				const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/${action}`))
				logger.info(bookmarked ? 'Post bookmarked' : 'Bookmark removed')
				this.addToStatuses(response.data)
				if (!bookmarked && this.type === 'bookmarks') {
					// off this list, not gone: the other lists still show it
					this.removeStatus(status, false)
					this.forgetRemoval(status)
				}
				return response
			} catch (error) {
				this.bookmarkStatus({ status, bookmarked: !bookmarked })
				showError(bookmarked
					? t('social', 'Could not bookmark the post')
					: t('social', 'Could not remove the bookmark'))
				logger.error('Failed to change the bookmark', { error })
			}
		},
		async postPin({ status, pinned }) {
			// the flag is flipped first so the menu answers at once, and rolled
			// back if the server refuses (somebody else's post, or the pin limit)
			this.pinStatus({ status, pinned })
			try {
				const action = pinned ? 'pin' : 'unpin'
				const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/${action}`))
				logger.info(pinned ? 'Post pinned' : 'Post unpinned')
				this.addToStatuses(response.data)
				return response
			} catch (error) {
				this.pinStatus({ status, pinned: !pinned })
				showError(pinned
					? t('social', 'Could not pin the post')
					: t('social', 'Could not unpin the post'))
				logger.error('Failed to change the pinned state', { error })
			}
		},
		refreshTimeline() {
			return this.fetchTimeline()
		},
		async fetchTimeline(params = {}) {
			if (params.limit === undefined) {
				params.limit = 15
			}

			// The page was rendered for this reader and carries the first
			// screenful of their home timeline with it, so the first fetch has
			// nothing to go and ask for. Without this the first screen is a
			// staircase: fetch the bundle, mount, *then* ask the server —
			// a second round trip and a full Nextcloud boot before anything a
			// person came to read is on screen.
			//
			// Once only, and only for the list it was rendered for: a seeded
			// page handed to a second request would be a stale answer to a
			// different question.
			const seeded = this.takeSeededPage(params)
			if (seeded !== null) {
				this.addToTimeline(seeded)

				return seeded
			}

			const url = timelineRequest(this, params)

			// which list this page was asked for, so an answer that arrives after
			// the reader has moved on is dropped instead of being committed under
			// the new heading
			const identity = this.getTimelineIdentity
			const request = axios.get(url, { params })
			// the sidebar's own requests wait for the first of these
			noteTimelineRequest(request)
			const response = await request

			if (this.getTimelineIdentity !== identity) {
				logger.debug('Dropped a page that belongs to a timeline no longer on screen', { identity })
				return []
			}

			this.addToTimeline(response.data)
			// more of it is being read from elsewhere: the rest of a
			// conversation, or a hashtag's posts beyond this server
			if (response.headers?.['x-social-thread-filling'] === '1' || response.headers?.['x-social-filling'] === '1') {
				this.refetchThreadLater(url, params, identity)
			}

			return response.data
		},

		/**
		 * The rest of a page is being read from the servers it lives on: asked
		 * for once more a little later, and what came in meanwhile added to
		 * what is already shown.
		 *
		 * @param {string} url the context the page was read from
		 * @param {object} params its query
		 * @param {string} identity the timeline it belongs to
		 */
		refetchThreadLater(url, params, identity) {
			setTimeout(async () => {
				if (this.getTimelineIdentity !== identity) {
					return
				}
				try {
					const { data } = await axios.get(url, { params })
					if (this.getTimelineIdentity === identity) {
						this.addToTimeline(data)
					}
				} catch (error) {
					logger.debug('The rest of the conversation was not read again', { error })
				}
			}, THREAD_REFETCH_MS)
		},
	},
})
