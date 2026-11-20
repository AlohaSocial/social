/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { showError, showInfo, showSuccess } from '../services/toast.js'
import { errorOf } from '../utils/serverError.js'
import { translate as t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

/**
 * How many lists are held aside at once: the feed switcher's four plus the one
 * the reader came from. The status index is pruned to what these lists name, so
 * this also bounds memory.
 */
const REMEMBERED = 5

/** How long, in ms, a page fetched ahead stands in for its list before a switch asks the server again. */
const PREFETCH_FRESH = 3 * 60 * 1000

/** The identities being fetched ahead right now, so none is asked for twice. */
const prefetching = new Set()

/**
 * The page requests in flight, by list identity, so a switch can cancel the
 * ones for the list being left.
 *
 * @type {Map<string, Set<AbortController>>}
 */
const inFlight = new Map()

/**
 * @param {string} identity the list a request is for
 * @return {AbortController} the controller, registered under that list
 */
function startRequest(identity) {
	const controller = new AbortController()
	if (!inFlight.has(identity)) {
		inFlight.set(identity, new Set())
	}
	inFlight.get(identity).add(controller)

	return controller
}

/**
 * @param {string} identity the list a request was for
 * @param {AbortController} controller its controller
 */
function endRequest(identity, controller) {
	const controllers = inFlight.get(identity)
	controllers?.delete(controller)
	if (controllers?.size === 0) {
		inFlight.delete(identity)
	}
}

/** @param {string} identity the list whose page requests are cancelled */
function abortRequests(identity) {
	for (const controller of inFlight.get(identity) ?? []) {
		controller.abort()
	}
	inFlight.delete(identity)
}

import logger from '../services/logger.js'
import { isNewerId, newerId } from '../utils/snowflake.js'
import { isBlockedBy } from '../utils/blockedBy.js'
import { noteTimelineRequest } from '../services/boot.js'
import { excludeTypesFor } from '../services/notifications.js'
import { useAccountStore } from './account.js'

/** Where the browser remembers that this reader's first post was celebrated. */
export const FIRST_POST_KEY = 'social.firstPostCelebrated'

/**
 * Whether this browser has already seen the celebration. Unreadable storage
 * (private windows, blocked site data) answers false; the account's post count
 * is the guard that keeps established readers from being congratulated.
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
		// `firstPostCelebrated` in the state still prevents a second celebration on this page
	}
}

/**
 * What this store holds, for the helpers and getters that are handed it.
 *
 * @typedef {object} TimelineState
 * @property {Record<string, import('../types/Mastodon.js').Status>} statuses every status seen, by id
 * @property {string[]} timeline the ids on screen, newest first
 * @property {string[]} parentsTimeline the ids above a post being read in its thread
 * @property {Record<string, string>} removedFrom which list a removed status came from
 * @property {Record<string, Array<{status: object, list: string, index: number}>>} removedBoosts the boosts of a removed status that left the lists with it
 * @property {string} type which timeline this is
 * @property {Array|null} seededPage the first screenful the server rendered with the page
 * @property {{tag?: string, id?: string, account?: string, scope?: string, media?: string, filter?: string, url?: string, singlePost?: string}} params what the current list was asked for
 * @property {string} account whose timeline, where it is somebody's
 * @property {{identity: string, timeline: string[], parentsTimeline: string[], removedFrom: object, fetchedAt?: number}[]} remembered the lists lately visited
 * @property {boolean} restored whether the list was put back rather than loaded
 * @property {Object<string, true>} filled the lists that have shown a post this session, by identity
 * @property {Record<string, Record<string, object|null>>} recommendationReasons explanation snapshots by feed and status
 * @property {Record<string, string|null>} recommendationCursors opaque continuation cursors for ranked For You timelines, by identity
 * @property {Record<string, {state: string, known_eligible: number|null, returned: number, remote_refresh_queued: boolean}>} recommendationCoverage ranked-feed coverage, by identity
 * @property {boolean} timelinePageFilling whether the current page can grow
 * @property {boolean} profilePageFilling whether profile content is pending
 * @property {boolean} remoteProfilePageFilling whether an outbox import is pending
 * @property {string} profileSyncState pending, failed, stalled, idle or unknown
 * @property {number|null} backgroundRefetchTimer scheduled background check
 * @property {number} backgroundRefetchGeneration rejects checks from a previous view
 * @property {boolean} composerDisplayStatus whether the full composer is open
 * @property {boolean} composerSubmitting whether a composer is currently publishing
 * @property {string} searchQuery what is being searched for
 * @property {boolean} firstPostCelebration whether the celebration is on screen
 * @property {boolean} firstPostCelebrated whether this session has celebrated already
 * @property {string} arrivedId the post this reader has just published, while it makes its entrance
 * @property {string} homeReadUpTo the server's `home` read marker as last read or reported; '0' until known
 * @property {Object<string, true>} acting the like, boost and bookmark requests in flight, by `id:kind`
 */

/**
 * What a list holds: a status, or a notification, which carries the post it
 * is about under `status` and is indexed under its own id like a status.
 *
 * @typedef {import('../types/Mastodon.js').Status & {status?: import('../types/Mastodon.js').Status|null}} IndexedEntry
 */

/**
 * @param {TimelineState} state the active timeline
 * @param {string} status safe UI state, never a raw remote exception
 */
function stopProfileRefresh(state, status) {
	state.profileSyncState = status
	if (state.backgroundRefetchTimer !== null) {
		window.clearTimeout(state.backgroundRefetchTimer)
		state.backgroundRefetchTimer = null
	}
	state.profilePageFilling = false
	state.remoteProfilePageFilling = false
	state.timelinePageFilling = false
}

/**
 * @param {TimelineState} state the active timeline
 * @param {Record<string, unknown>} headers safe response metadata
 */
function readProfileSyncState(state, headers = {}) {
	const raw = headers['x-social-profile-sync-state']
	const explicit = typeof raw === 'string' ? raw : ''
	state.profileSyncState = state.type === 'account'
		? (['pending', 'failed', 'idle'].includes(explicit) ? explicit : (state.profilePageFilling ? 'pending' : ''))
		: ''
	if (state.profileSyncState === 'failed') {
		stopProfileRefresh(state, 'failed')
	}
}

/**
 * Indexes a status, the status it boosts and, for a notification, the post it
 * is about, by id.
 *
 * @param {TimelineState} state the store state
 * @param {IndexedEntry|null|undefined} status the entry to index
 */
function indexStatus(state, status) {
	if (status === undefined || status === null || status.id === undefined) {
		return
	}

	// assigned in place: replacing the map would copy every status for each one indexed
	state.statuses[status.id] = status
	if (status.reblog !== undefined && status.reblog !== null) {
		state.statuses[status.reblog.id] = status.reblog
	}
	if (status.type !== undefined) {
		indexStatus(state, status.status)
	}
}

/**
 * Snapshot metadata belongs to a feed, not the shared status that polling updates.
 *
 * @param {TimelineState} state the store state
 * @param {IndexedEntry[]} items a page returned by the personalized timeline
 * @param {string} identity the feed that requested the page
 */
function rememberRecommendationReasons(state, items, identity) {
	const reasons = { ...(state.recommendationReasons[identity] ?? {}) }
	for (const status of items) {
		reasons[status.id] = status.interest ?? null
	}
	state.recommendationReasons[identity] = reasons
}

/**
 * Drops from the index every status that no kept list names.
 *
 * The index is shared by the list on screen and the remembered ones, so a
 * change to a post reaches every list showing it; pruning keeps it bounded.
 * Boosted statuses and notification subjects are kept with their entry.
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
	const currentIdentity = JSON.stringify([state.type, state.account, state.params])
	const keptFeeds = new Map([[currentIdentity, state.timeline]])
	for (const held of state.remembered) {
		keptFeeds.set(held.identity, held.timeline)
	}
	for (const identity of Object.keys(state.recommendationReasons)) {
		const ids = keptFeeds.get(identity)
		if (!ids) {
			delete state.recommendationReasons[identity]
			continue
		}
		const visible = new Set(ids)
		for (const id of Object.keys(state.recommendationReasons[identity])) {
			if (!visible.has(id)) {
				delete state.recommendationReasons[identity][id]
			}
		}
	}
}

/** @param {{type: string, params?: {scope?: string}}} state timeline identity @return {boolean} */
export function isPersonalizedFeed(state) {
	return state.type === 'interests'
		|| (['photos', 'videos', 'shorts'].includes(state.type) && state.params?.scope === 'interests')
}

/**
 * Whether the list is a ranking rather than a timeline: For you (whole or as
 * the scope of Photos, Videos and Shorts) and Bluesky feeds and lists.
 *
 * @param {{type: string, params?: {scope?: string}}} state the store state, or anything with its type and params
 * @return {boolean}
 */
export function isRanked(state) {
	return isPersonalizedFeed(state) || state.type === 'bluesky'
}

/**
 * @param {TimelineState} state the store state
 * @param {string[]} ids the ids of one of the two lists
 * @return {object[]} the statuses those ids name, newest first
 */
function sortedByDate(state, ids) {
	// each date is parsed once rather than on every comparison
	return ids
		.map((statusId) => state.statuses[statusId])
		.filter(Boolean)
		.map((status) => ({ status, at: Date.parse(status.created_at) }))
		.sort((a, b) => b.at - a.at)
		.map((entry) => entry.status)
}

/**
 * Appends the statuses a list does not already hold, in order, skipping
 * duplicates within the page too.
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
 * Whether a timeline request asks for what is newer than the list holds, as
 * the poll does, rather than for a page further down.
 *
 * @param {object} params the query
 * @return {boolean} whether it pages forward
 */
export function isForwardRequest(params) {
	return (params.min_id !== undefined && params.min_id !== null)
		|| (params.since_id !== undefined && params.since_id !== null)
}

/** @param {{type: string, account?: string, params: object}} list the timeline */
function usesV4RecommendationTimeline(list) {
	return isPersonalizedFeed(list) && loadState('social', 'serverData', {
		public: false,
		interests: { recommendationsEnabled: false },
	})?.interests?.recommendationsEnabled === true
}

/**
 * Where a list is asked for, and what the request has to add to say which.
 * Shared with prefetching, so a page fetched ahead is the page the list would
 * have asked for.
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
			// the profile's Photos and Videos tabs: `media_type` is a Social extension,
			// `only_media` is Mastodon's fallback for clients that do not know it
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
			url = generateUrl(usesV4RecommendationTimeline(list)
				? 'apps/social/api/v1/recommendations/timeline'
				: 'apps/social/api/v1/timelines/interests')
			// Each media kind has its own signed snapshot.
			if (usesV4RecommendationTimeline(list)) {
				if (['photos', 'videos'].includes(list.params.media)) {
					params.media = list.params.media
				}
			} else if (list.params.media === 'photos' || list.params.media === 'videos') {
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
		case 'shorts':
		// a timeline without text-only posts, in the scope the switcher sets
		// (following, local or global), or For you narrowed to the same kind by the server
			if (list.params.scope === 'interests') {
				url = generateUrl(usesV4RecommendationTimeline(list)
					? 'apps/social/api/v1/recommendations/timeline'
					: 'apps/social/api/v1/timelines/interests')
				if (usesV4RecommendationTimeline(list)) {
					params.media = list.type === 'photos' ? 'media' : list.type
				} else if (list.type === 'shorts') {
					params.only_short = true
				} else {
					params.media = list.type === 'photos' ? 'media' : list.type
				}
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
			// Photos: images and videos only, filtered before pagination; `only_media`
			// is for older servers
			params.only_media = true
			if (list.type === 'shorts') {
				// the Shorts stack, by the rule the server keeps in `Stream::shortKindOf()`
				params.only_short = true
			} else if (list.type === 'videos') {
				params.only_video = true
			} else {
				// Includes mixed and native PeerTube video objects.
				params.media_type = 'visual'
			}
			break
		case 'link':
		// everything said about one article; the link rides in the query, since a URL
		// in a path segment would have to survive double encoding
			url = generateUrl('apps/social/api/v1/timelines/link')
			params.url = list.params.url ?? ''
			break
		case 'notifications': {
			url = generateUrl('apps/social/api/v1/notifications')
			// what the page's filter leaves out; part of the params, so changing it refetches
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

/** How long after a conversation is opened its replies from elsewhere are asked for again. */
const THREAD_REFETCH_MS = 1000
const THREAD_REFETCH_MAX_MS = 5000
const MAX_THREAD_REFETCHES = 30
const PROFILE_REFETCH_MS = 2000
// Remote profile posts are imported by a queued outbox job that cron can be
// slow to run, so it is checked for up to five minutes, backing off after the
// first few checks.
const REMOTE_PROFILE_REFETCH_MS = 5000
const MAX_REMOTE_PROFILE_REFETCHES = 60
const STATUS_REFETCH_MS = 1000
const STATUS_REFETCH_MAX_MS = 3000
// Counts back off for fifteen seconds after a failed request, so the opened
// post is refreshed long enough to cross that cooldown, within a bound.
const MAX_STATUS_REFETCHES = 10
/** the most posts one counts request asks about, as the server caps it */
export const COUNTS_BATCH = 20
/** what a counts answer carries for each post */
const COUNT_FIELDS = ['replies_count', 'reblogs_count', 'favourites_count', 'quotes_count', 'dislikes_count']

export const useTimelineStore = defineStore('timeline', {
	state: () => ({
		statuses: {},
		statusRefetching: {},
		/** Whether the current page is still being read from its remote source. */
		timelinePageFilling: false,
		/** Whether an account profile has another Bluesky AppView page to read. */
		profilePageFilling: false,
		/** Whether a remote ActivityPub outbox is being imported in the background. */
		remoteProfilePageFilling: false,
		profileSyncState: '',
		backgroundRefetchTimer: null,
		backgroundRefetchGeneration: 0,
		/** Whether the last page read for the current timeline had no visible posts. */
		timelinePageEmpty: false,
		timeline: [],
		parentsTimeline: [],
		/** which list a removed status came from, so a rollback restores it there */
		removedFrom: {},
		/** the boosts that went with a removed status, so a rollback brings them back */
		removedBoosts: {},
		/** how many replies of the open conversation its author hid, and that are not shown */
		hiddenByAuthor: 0,
		type: 'home',

		/**
		 * The first screenful of the home timeline, embedded by the server (see
		 * `NavigationController::provideFirstPage()`). Read once, then dropped.
		 *
		 * @type {Array|null}
		 */
		seededPage: loadState('social', 'firstPage', null),
		/** @type {{tag?: string, id?: string, account?: string, scope?: string, media?: string, filter?: string, url?: string, singlePost?: string}} */
		params: {},
		account: '',
		/**
		 * The lists the reader has been in lately, so that coming back to one finds
		 * the pages they had loaded. Capped at `REMEMBERED`, oldest first out, since
		 * the index keeps every status they name.
		 *
		 * Only ids are held; statuses stay in the shared `statuses` map so a change in
		 * one list shows in all. A list fetched ahead is held here too, with
		 * `fetchedAt`, and only put back while fresh.
		 *
		 * @type {{identity: string, timeline: string[], parentsTimeline: string[], removedFrom: object, fetchedAt?: number}[]}
		 */
		remembered: [],
		/** Opaque ranked-feed cursors keyed by the same identity as the timeline lists. */
		recommendationCursors: {},
		/** Explanations survive ordinary status refreshes, only within their feed. */
		recommendationReasons: {},
		/** Coverage returned by the ranked-feed endpoint, keyed by timeline identity. */
		recommendationCoverage: {},
		/** Whether the list on screen was put back rather than loaded, so the view does not fetch another page on top of it. */
		restored: false,
		/**
		 * The lists that have shown at least one post this session, by identity. A
		 * switch keeps the list being left on screen only while the next one is known
		 * to have something to replace it with.
		 *
		 * @type {Object<string, true>}
		 */
		filled: {},
		composerDisplayStatus: false,
		composerSubmitting: false,
		searchQuery: '',
		/** whether the one-time first-post celebration is on screen right now */
		firstPostCelebration: false,
		/** whether this session has already celebrated, which holds when the browser cannot remember */
		firstPostCelebrated: false,
		/** The post this reader has just published, while it makes its entrance; '' otherwise. */
		arrivedId: '',
		/**
		 * The `home` read marker as the server last stated it or was told it:
		 * the position every Mastodon client shares. '0' until known.
		 */
		homeReadUpTo: '0',
		/**
		 * The like, boost and bookmark requests in flight, by `id:kind`. A second
		 * press while one is pending is ignored, so racing requests cannot leave the
		 * count off by one.
		 *
		 * @type {Object<string, true>}
		 */
		acting: {},
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
		 * @return {(statusId: string, kind: string) => boolean} whether that request is still on its way
		 */
		isActing(state) {
			return (statusId, kind) => state.acting[`${statusId}:${kind}`] === true
		},
		/**
		 * @param {TimelineState} state the store state
		 * @return {boolean} whether the composer is open
		 */
		getComposerDisplayStatus(state) {
			return state.composerDisplayStatus
		},
		/**
		 * @param {TimelineState} state the store state
		 * @return {(statusId: string) => object|null|undefined} the active snapshot's explanation
		 */
		getRecommendationReason(state) {
			const identity = JSON.stringify([state.type, state.account, state.params])
			return (statusId) => isPersonalizedFeed(state)
				? state.recommendationReasons[identity]?.[statusId]
				: undefined
		},

		/**
		 * The timeline, newest first.
		 *
		 * @param {TimelineState} state the store state
		 * @return {object[]} the statuses
		 */
		getTimeline(state) {
			// ranked lists keep the server's order
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
		 * What list the store is currently holding, as one comparable value. Views are
		 * reused across navigations, so this is what tells a list it has been swapped
		 * underneath it.
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
		 * The posts of a list that is not on screen but held: left lately, or
		 * fetched ahead by `prefetchTimeline()`.
		 *
		 * @param {TimelineState} state the store state
		 * @return {(identity: string) => object[]} identity -> its posts, [] when it is not held
		 */
		getHeldTimeline(state) {
			return (identity) => (state.remembered.find((entry) => entry.identity === identity)?.timeline ?? [])
				.map((statusId) => state.statuses[statusId])
				.filter(Boolean)
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
		 * Where this reader had got to on the home timeline, from the `home` marker of
		 * `GET /api/v1/markers` that all Mastodon clients share. A failure answers
		 * null, and the line falls back to what this browser remembers.
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
		 * Tells the server the home timeline has been read up to `id`. Sent once per
		 * position, since the server never moves a marker backwards; quiet on failure.
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
		 * The first page the server put in the document, if this request is the one
		 * it was rendered for. Consumed by the first plain fetch of the home timeline;
		 * any later request is a different question.
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

			// a cursor, a narrowing, or any other question the seeded page does not answer
			const asked = { ...params }
			delete asked.limit
			if (Object.keys(asked).length > 0) {
				return null
			}

			return page
		},
		addToTimeline(data) {
			if (Array.isArray(data)) {
				if (isPersonalizedFeed(this)) {
					rememberRecommendationReasons(this, data, this.getTimelineIdentity)
				}
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
		 * @param {boolean} [gone] whether the status itself is gone (deleted,
		 *                         archived) and leaves the index too; one only off
		 *                         this list stays known to the lists still showing it
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
				// a boost is listed under its own id and would stay as an empty entry
				const boosts = []
				for (const list of ['timeline', 'parentsTimeline']) {
					for (let index = this[list].length - 1; index >= 0; index--) {
						const listed = this.statuses[this[list][index]]
						if (listed?.reblog?.id === status.id) {
							boosts.unshift({ status: listed, list, index })
							this[list].splice(index, 1)
							delete this.statuses[listed.id]
						}
					}
				}
				if (boosts.length > 0) {
					this.removedBoosts = { ...this.removedBoosts, [status.id]: boosts }
					if (timelineIndex === -1 && parentsTimelineIndex === -1) {
						// listed only as boosts: a rollback brings those back, not the post
						this.removedFrom = { ...this.removedFrom, [status.id]: 'boosts' }
					}
				}
				delete this.statuses[status.id]
			}
		},
		/**
		 * Puts a status back, in the list it came from, after a delete the server
		 * refused.
		 *
		 * @param {import('../types/Mastodon.js').Status} status the status that could not be deleted
		 * @param {number} [index] where in the list it was, for a list whose
		 *                         order is not its dates (For you)
		 */
		restoreStatus(status, index = -1) {
			indexStatus(this, status)
			const list = this.removedFrom?.[status.id] === 'parents' ? 'parentsTimeline' : 'timeline'
			if (this.removedFrom?.[status.id] !== 'boosts' && this[list].indexOf(status.id) === -1) {
				if (index >= 0 && index <= this[list].length) {
					this[list].splice(index, 0, status.id)
				} else {
					this[list].push(status.id)
				}
			}
			const removedFrom = { ...this.removedFrom }
			delete removedFrom[status.id]
			this.removedFrom = removedFrom
			for (const boost of this.removedBoosts[status.id] ?? []) {
				indexStatus(this, boost.status)
				if (this[boost.list].indexOf(boost.status.id) === -1) {
					this[boost.list].splice(Math.min(boost.index, this[boost.list].length), 0, boost.status.id)
				}
			}
			this.forgetBoosts(status)
		},
		/**
		 * @param {import('../types/Mastodon.js').Status} status the status whose boosts are not coming back
		 */
		forgetBoosts(status) {
			if (this.removedBoosts[status.id] === undefined) {
				return
			}
			const removedBoosts = { ...this.removedBoosts }
			delete removedBoosts[status.id]
			this.removedBoosts = removedBoosts
		},
		/**
		 * Drops the "where it came from" hint for a removal that is final, so a later
		 * rollback of the same id does not read it.
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
			this.cancelBackgroundRefetch()
			delete this.recommendationReasons[this.getTimelineIdentity]
			if (usesV4RecommendationTimeline(this)) {
				const cursors = { ...this.recommendationCursors }
				delete cursors[this.getTimelineIdentity]
				this.recommendationCursors = cursors
				const coverage = { ...this.recommendationCoverage }
				delete coverage[this.getTimelineIdentity]
				this.recommendationCoverage = coverage
			}
			this.timeline = []
			this.parentsTimeline = []
			this.removedFrom = {}
			this.removedBoosts = {}
			this.hiddenByAuthor = 0
			this.timelinePageFilling = false
			this.profilePageFilling = false
			this.remoteProfilePageFilling = false
			this.profileSyncState = ''
			this.timelinePageEmpty = false
			// pruned rather than emptied, because the remembered lists read the index
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
		 * Marks a post as the one just published, and unmarks it once its entrance is
		 * over, so a reload does not play it again.
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
		/**
		 * Claims a like, boost or bookmark request for a post.
		 *
		 * @param {string} statusId the post
		 * @param {string} kind 'like', 'boost' or 'bookmark'
		 * @return {boolean} false when one is already on its way
		 */
		startActing(statusId, kind) {
			const key = `${statusId}:${kind}`
			if (this.acting[key] === true) {
				return false
			}
			this.acting = { ...this.acting, [key]: true }

			return true
		},
		/**
		 * @param {string} statusId the post
		 * @param {string} kind 'like', 'boost' or 'bookmark'
		 */
		stopActing(statusId, kind) {
			const rest = { ...this.acting }
			delete rest[`${statusId}:${kind}`]
			this.acting = rest
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
		 * Whether a post of the reader's own is put away. Kept in the store because
		 * the same post is often drawn twice, in a thread and the timeline behind it.
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
		 * The people named in a post's photos, after somebody changed them. Kept in
		 * the store for the same reason as the archive flag.
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
		/**
		 * A vote reaches every view of the same post, not just the component that
		 * cast it.
		 *
		 * @param {object} payload the status and its new poll
		 * @param {string} payload.statusId the status that carries the poll
		 * @param {object} payload.poll the poll as the server returned it
		 */
		updateStatusPoll({ statusId, poll }) {
			const known = this.statuses[statusId]
			if (known !== undefined) {
				this.statuses[statusId] = { ...known, poll }
			}
		},
		/**
		 * The reaction bar of a post, as the server rebuilt it, kept in the store so
		 * every copy of the post shows the same bar.
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
				indexStatus(this, updatedStatus)
			}
		},
		/**
		 * Decides whether the post that just went out is the first this reader has
		 * ever published, and starts the celebration if it is.
		 *
		 * The stored flag stops a second celebration cheaply; the account's
		 * `statuses_count`, loaded with the page and not yet counting the new post,
		 * stops one for a long-time reader who cleared their storage. An account not
		 * loaded yet is not proof of a first post, so nothing happens.
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
			// the media kind is part of the timeline identity, so changing the tab refetches
			this.switchTimeline('account', media === '' ? {} : { media }, account)
		},
		/**
		 * Points the store at a list, keeping what is loaded when it can.
		 *
		 * The list is only cleared when it is a different list, and the one left is
		 * remembered, so Back out of a post finds the list whole and the scroll
		 * position can be restored. `resetTimeline()` clears explicitly.
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
			this.timelinePageFilling = false
			this.profilePageFilling = false
			this.remoteProfilePageFilling = false
			this.profileSyncState = ''
			this.timelinePageEmpty = false

			// cancel pages still on their way for the list being left
			abortRequests(left.identity)
			this.cancelBackgroundRefetch()

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

			// ids read back against the shared index drop posts deleted meanwhile; the
			// index is then pruned of what only the list that fell off named
			const stillKnown = (statusId) => this.statuses[statusId] !== undefined
			this.timeline = returning.timeline.filter(stillKnown)
			this.parentsTimeline = returning.parentsTimeline.filter(stillKnown)
			this.removedFrom = returning.removedFrom
			pruneIndex(this)
		},
		/**
		 * Fetches the first page of a list the reader is likely to switch to and
		 * holds it with the remembered lists, so the switch shows it at once. Quiet
		 * on failure: the switch then asks for itself.
		 *
		 * @param {object} list which list
		 * @param {string} list.type the timeline type, as the store names it
		 * @param {string} [list.account] whose posts, for a profile
		 * @param {object} [list.params] what narrows it
		 */
		async prefetchTimeline({ type, account = '', params = {} }) {
			const identity = JSON.stringify([type, account, params])
			const held = this.remembered.find((entry) => entry.identity === identity)
			if (identity === this.getTimelineIdentity
				|| prefetching.has(identity)
				|| (held !== undefined && (held.fetchedAt === undefined || Date.now() - held.fetchedAt < PREFETCH_FRESH))) {
				return
			}

			const query = { limit: 15 }
			const url = timelineRequest({ type, account, params }, query)
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
			const items = Array.isArray(data) ? data : (Array.isArray(data?.items) ? data.items : null)
			if (items === null || identity === this.getTimelineIdentity) {
				return
			}
			if (!Array.isArray(data) && usesV4RecommendationTimeline({ type, account, params })) {
				this.recommendationCursors = { ...this.recommendationCursors, [identity]: data.next_cursor ?? null }
				this.recommendationCoverage = { ...this.recommendationCoverage, [identity]: data.coverage ?? null }
			}

			if (isPersonalizedFeed({ type, params })) {
				delete this.recommendationReasons[identity]
				rememberRecommendationReasons(this, items, identity)
			}
			items.forEach((status) => indexStatus(this, status))
			const ids = []
			appendNew(ids, items)
			if (ids.length > 0) {
				this.markFilled(identity)
			}

			this.remembered = [
				{ identity, timeline: ids, parentsTimeline: [], removedFrom: {}, fetchedAt: Date.now() },
				...this.remembered.filter((entry) => entry.identity !== identity),
			].slice(0, REMEMBERED)
			pruneIndex(this)
		},
		/**
		 * The first page of another list, for a shelf beside the one on screen (the
		 * Shorts and followed channels above the Videos grid). Not held: the list on
		 * screen and the remembered lists are left as they were.
		 *
		 * @param {object} list which list
		 * @param {string} list.type the timeline type, as the store names it
		 * @param {object} [list.params] what narrows it
		 * @param {number} [limit] how many posts
		 * @return {Promise<import('../types/Mastodon.js').Status[]>} the posts; [] when the server would not say
		 */
		async fetchSideList({ type, params = {} }, limit = 12) {
			const query = { limit }
			const url = timelineRequest({ type, account: '', params }, query)
			try {
				const { data } = await axios.get(url, { params: query })

				return Array.isArray(data) ? data : (Array.isArray(data?.items) ? data.items : [])
			} catch (error) {
				logger.debug('Could not fetch a list for a shelf', { type, params, error })

				return []
			}
		},
		/**
		 * Tells the server what an attachment shows, so it federates as alt text.
		 *
		 * @param {object} media the attachment
		 * @param {string} media.id its id on this server
		 * @param {string} media.description what it shows
		 */
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
		 * Tells the server where the subject of an attachment is, so square crops
		 * keep it in frame. It federates as `focalPoint`.
		 *
		 * @param {object} media the attachment
		 * @param {string} media.id its id on this server
		 * @param {string} media.focus Mastodon's `x,y`, each from -1 to 1
		 */
		async focusMedia({ id, focus }) {
			try {
				await axios.put(generateUrl('apps/social/api/v1/media/' + id), { focus })
			} catch (error) {
				// the photo is still attached, just centred
				showError(t('social', 'Could not save the focal point of an attachment'))
				logger.error('Failed to set the focal point of a media', { error })
			}
		},

		/**
		 * One of the reader's own uploads as the server holds it now, as a restored
		 * draft asks about each attachment.
		 *
		 * @param {string} id the upload's id
		 * @return {Promise<object|null|undefined>} the media entity; null when
		 *         the server says there is no such upload; undefined when it
		 *         could not be asked
		 */
		async fetchMedia(id) {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/media/{id}', { id }))
				return data
			} catch (error) {
				if (error.response?.status === 404) {
					return null
				}
				logger.warn('Could not check an attachment of a draft', { error })
				return undefined
			}
		},

		/**
		 * The bytes of an upload as a file, so a filter can be baked in when a
		 * restored draft is sent.
		 *
		 * @param {object} media the upload
		 * @param {string} media.url where it is
		 * @param {string} [media.media_type] what it is
		 * @return {Promise<File|undefined>} the file, or undefined when it could not be had
		 */
		async fetchMediaFile(media) {
			try {
				const { data } = await axios.get(media.url, { responseType: 'blob' })
				const type = data.type || media.media_type || ''
				return new File([data], media.url.split('/').pop() || 'attachment', { type })
			} catch (error) {
				logger.warn('Could not fetch an attachment of a draft', { error })
				return undefined
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
				// the server's reason (too large, an unsupported kind) stays behind "Details"
				showError(errorOf(error, t('social', 'Could not upload the attachment')))
				logger.error('Failed to create a media', { error })
			}
		},
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
		/**
		 * Attaches a file the reader already keeps in Nextcloud. Only the path is
		 * sent; the server returns the same attachment an upload would.
		 *
		 * @param {object} media what to attach
		 * @param {string} media.path the file, relative to the reader's own files
		 * @param {string} [media.description] what it shows
		 * @return {Promise<object|undefined>} the media entity, or undefined when the server refused
		 */
		async createMediaFromFile({ path, description = '' }) {
			try {
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/media/from-file'),
					{ path, description },
				)
				logger.info('Media created from ' + path + ' with id ' + data.id)
				return data
			} catch (error) {
				// named, because a picker attaches several files at once
				showError(t('social', 'Could not attach {file}', { file: path }))
				logger.error('Failed to attach a file from Nextcloud', { error })
			}
		},
		/**
		 * Sends a status. Resolves with `undefined` when the server refused, so the
		 * composer keeps what was typed.
		 *
		 * @param {object} status the status to send
		 * @param {object} [options] how it is sent
		 * @param {string} [options.idempotencyKey] the key a retry of the same post shares
		 * @return {Promise<object|undefined>} the created status, or undefined
		 */
		async post(status, { idempotencyKey = '' } = {}) {
			try {
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/statuses'),
					status,
					...(idempotencyKey === '' ? [] : [{ headers: { 'Idempotency-Key': idempotencyKey } }]),
				)
				logger.info('Post created', data.id)
				return data
			} catch (error) {
				// a post held for a moderator has been kept, so it is reported as information
				// and the composer is cleared
				if (error.response?.data?.held_for_review === true) {
					showInfo(t('social', 'Your post is waiting for a moderator to look at it. It has been kept — there is no need to write it again.'))
					logger.info('Post held for review')
					return { held_for_review: true }
				}
				// a reply to or quote of an account that has blocked the author
				showError(isBlockedBy(error)
					? t('social', 'This account has blocked you')
					: errorOf(error, t('social', 'Could not send the post')))
				logger.error('Failed to create a status', { error })
			}
		},
		async postEdit({ status, content, spoiler_text, sensitive, language = undefined, event = undefined, media_attributes: mediaAttributes = [], media_ids: mediaIds = undefined }) {
			try {
				const response = await axios.put(
					generateUrl(`apps/social/api/v1/statuses/${status.id}`),
					{
						status: content,
						...(language !== undefined ? { language } : {}),
						spoiler_text,
						sensitive,
						...(mediaAttributes.length > 0 ? { media_attributes: mediaAttributes } : {}),
						...(mediaIds !== undefined ? { media_ids: mediaIds } : {}),
						...(event !== undefined ? { event } : {}),
					},
				)
				this.updateStatus(response.data)
				logger.info('Post edited', response.data.id)
				return response
			} catch (error) {
				// the server says why it refused, e.g. a post that became too long
				showError(errorOf(error, t('social', 'Could not save the changes to the post')))
				logger.error('Failed to edit the status', { error })
			}
		},
		async postDelete(status) {
			try {
				this.removeStatus(status)
				const response = await axios.delete(generateUrl(`apps/social/api/v1/post?id=${status.uri}`))
				logger.info('Post deleted with token ' + response.data.result.token)
				this.forgetBoosts(status)
				return true
			} catch (error) {
				// back into the list it came from
				this.restoreStatus(status)
				showError(t('social', 'Could not delete the post'))
				logger.error('Failed to delete the status', { error })
				return false
			}
		},
		/**
		 * One status by its id, for a post nothing has loaded yet: a shared link, a
		 * reload, or a Discover tile. `/context` never includes the post itself.
		 *
		 * Only a 404 or 410 means the post is not there; anything else is thrown so
		 * the page can offer to retry. The server counts this as a view; counts of
		 * posts merely on screen come from `refreshCounts()`.
		 *
		 * @param {string} id the status id
		 * @param {boolean} [forceCounts] ask the origin even when the last count refresh was recent
		 * @return {Promise<object|null>} the status, or null if it is not there
		 */
		async fetchStatus(id, forceCounts = false) {
			try {
				const url = generateUrl(`apps/social/api/v1/statuses/${id}`)
				const get = (includeForceCounts = forceCounts) => includeForceCounts
					? axios.get(url, { params: { force_counts: true } })
					: axios.get(url)
				const response = await get()
				this.addToStatuses(response.data)
				// its counts are being fetched from its origin: read again a little later
				if (response.headers?.['x-social-filling'] === '1' && !this.statusRefetching[id]) {
					this.statusRefetching = { ...this.statusRefetching, [id]: true }
					const refetch = (attempt) => setTimeout(async () => {
						try {
							const refreshed = await get(false)
							this.addToStatuses(refreshed.data)
							if (attempt < MAX_STATUS_REFETCHES && refreshed.headers?.['x-social-filling'] === '1') {
								refetch(attempt + 1)
							} else {
								const refetching = { ...this.statusRefetching }
								delete refetching[id]
								this.statusRefetching = refetching
							}
						} catch (error) {
							const refetching = { ...this.statusRefetching }
							delete refetching[id]
							this.statusRefetching = refetching
							logger.debug('The counts of a status were not read again', { error, id })
						}
					}, Math.min(STATUS_REFETCH_MS * (2 ** (attempt - 1)), STATUS_REFETCH_MAX_MS))
					refetch(1)
				}

				return response.data
			} catch (error) {
				const status = error?.response?.status
				if (status !== 404 && status !== 410) {
					throw error
				}
				// a deleted post and one this server never had look the same from here
				logger.debug('A single status is not there', { error, id })

				return null
			}
		},
		/**
		 * The current counts of posts on screen, written onto the stored statuses.
		 * Records no view; the server refreshes counts from the network once they
		 * are older than the post's age allows.
		 *
		 * @param {string[]} ids the ids of the posts, at most `COUNTS_BATCH`
		 * @return {Promise<void>}
		 */
		async refreshCounts(ids) {
			const wanted = [...new Set(ids.map(String))].slice(0, COUNTS_BATCH)
			if (wanted.length === 0) {
				return
			}
			const response = await axios.get(generateUrl('apps/social/api/v1/social/status_counts'), {
				params: { ids: wanted, _social_refresh: Date.now() },
			})
			for (const counts of Array.isArray(response.data) ? response.data : []) {
				const status = this.statuses[String(counts?.id)]
				if (!status) {
					continue
				}
				for (const field of COUNT_FIELDS) {
					if (field in counts) {
						status[field] = counts[field]
					}
				}
			}
		},
		/**
		 * Each of the like and boost actions answers the updated status, nothing when
		 * the server refused and the change was rolled back, and null when a request
		 * for the same post was already in flight.
		 *
		 * @param {object} root0 the status to like
		 * @param {import('../types/Mastodon.js').Status} root0.status that status
		 * @return {Promise<object|null|undefined>}
		 */
		async postLike({ status }) {
			if (!this.startActing(status.id, 'like')) {
				return null
			}
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
			} finally {
				this.stopActing(status.id, 'like')
			}
		},
		/**
		 * PeerTube's dislike counter, which only a video carries. Not optimistic: the
		 * server's answer carries the new count.
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
			if (!this.startActing(status.id, 'like')) {
				return null
			}
			try {
				if (this.type === 'favourites') {
					// off this list, not gone: other lists still show the post
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
					// restores the caller's pre-unlike copy, so no likeStatus on top of it
					this.restoreStatus(status)
				} else {
					this.likeStatus({ status })
				}
				showError(t('social', 'Could not remove the like'))
				logger.error('Failed to unlike status', { error })
			} finally {
				this.stopActing(status.id, 'like')
			}
		},
		async postBoost({ status }) {
			if (!this.startActing(status.id, 'boost')) {
				return null
			}
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
			} finally {
				this.stopActing(status.id, 'boost')
			}
		},
		async postUnBoost({ status }) {
			if (!this.startActing(status.id, 'boost')) {
				return null
			}
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
			} finally {
				this.stopActing(status.id, 'boost')
			}
		},
		/**
		 * Mutes or unmutes the conversation a post belongs to, for this reader.
		 *
		 * The server keeps the mute against the thread's root, so its answer is the
		 * one to trust; this copy is flipped at once so the menu responds.
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
			if (!this.startActing(status.id, 'bookmark')) {
				return null
			}
			// flipped first so the button responds at once, and put back if the server refuses
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
			} finally {
				this.stopActing(status.id, 'bookmark')
			}
		},
		async postPin({ status, pinned }) {
			// flipped first and rolled back if the server refuses (somebody else's post, or the pin limit)
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

			// The page arrives with the first screenful of the home timeline, saving a
			// round trip before anything is on screen. Used once, and only for the request
			// it was rendered for.
			const seeded = this.takeSeededPage(params)
			if (seeded !== null) {
				this.timelinePageFilling = false
				this.profilePageFilling = false
				this.remoteProfilePageFilling = false
				this.profileSyncState = ''
				this.timelinePageEmpty = seeded.length === 0
				this.addToTimeline(seeded)

				return seeded
			}

			const v4Timeline = usesV4RecommendationTimeline(this)
			const identity = this.getTimelineIdentity
			if (v4Timeline) {
				const requestingNextPage = params.max_id !== undefined || params.offset !== undefined
				if (requestingNextPage) {
					const cursor = this.recommendationCursors[identity]
					if (!cursor) {
						this.timelinePageEmpty = true
						return []
					}
					params.cursor = cursor
					delete params.max_id
					delete params.offset
				} else if (params.cursor === undefined) {
					params.cursor = ''
				}
			}
			const url = timelineRequest(this, params)

			// which list this page was asked for, so a late answer is dropped rather than
			// committed under the list the reader has moved to
			const controller = startRequest(identity)
			const request = axios.get(url, { params, signal: controller.signal })
			// the sidebar's own requests wait for the first of these
			noteTimelineRequest(request)
			let response
			try {
				response = await request
			} finally {
				endRequest(identity, controller)
			}

			if (this.getTimelineIdentity !== identity) {
				logger.debug('Dropped a page that belongs to a timeline no longer on screen', { identity })
				return []
			}
			const data = response.data
			const timelineData = v4Timeline && !Array.isArray(data)
				? (Array.isArray(data?.items) ? data.items : [])
				: data
			if (v4Timeline && !Array.isArray(data)) {
				this.recommendationCursors = { ...this.recommendationCursors, [identity]: data?.next_cursor ?? null }
				this.recommendationCoverage = { ...this.recommendationCoverage, [identity]: data?.coverage ?? null }
			}
			// an empty answer to a request for newer posts says nothing about the end of
			// the list, so the page flags are left to the paging requests
			if (isForwardRequest(params)) {
				this.addToTimeline(timelineData)
				return timelineData
			}
			this.timelinePageFilling = response.headers?.['x-social-filling'] === '1'
				|| response.headers?.['x-social-thread-filling'] === '1'
			this.profilePageFilling = response.headers?.['x-social-profile-filling'] === '1'
			this.remoteProfilePageFilling = response.headers?.['x-social-remote-profile-filling'] === '1'
			readProfileSyncState(this, response.headers)
			this.timelinePageEmpty = Array.isArray(timelineData)
				? timelineData.length === 0
				: this.type === 'single-post' && Array.isArray(timelineData?.descendants) && timelineData.descendants.length === 0

			this.addToTimeline(timelineData)
			if (this.type === 'single-post') {
				this.hiddenByAuthor = Number(response.headers?.['x-social-hidden-replies'] ?? 0) || 0
			}
			// more of it is being read from elsewhere: the rest of a
			// conversation, or a hashtag's posts beyond this server
			if (this.profileSyncState !== 'failed' && (response.headers?.['x-social-thread-filling'] === '1' || response.headers?.['x-social-filling'] === '1')) {
				this.refetchThreadLater(url, params, identity)
			}

			return timelineData
		},

		/**
		 * The replies of the open conversation its author hid, shown after all,
		 * marked as hidden, below the others.
		 */
		async showHiddenReplies() {
			const identity = this.getTimelineIdentity
			const { data } = await axios.get(timelineRequest(this, {}), { params: { with_hidden: true } })
			if (this.getTimelineIdentity === identity) {
				this.addToTimeline(data)
				this.hiddenByAuthor = 0
			}
		},

		/**
		 * A reply hidden or shown by the author of its thread.
		 *
		 * @param {object} root0 the reply and whether it is hidden now
		 * @param {string} root0.statusId the reply
		 * @param {boolean} root0.hidden whether it is hidden
		 */
		updateStatusHiddenByAuthor({ statusId, hidden }) {
			const known = this.statuses[statusId]
			if (known !== undefined) {
				this.statuses[statusId] = { ...known, hidden_by_author: hidden }
			}
		},

		/** Cancel the previous feed's scheduled check before replacing it. */
		cancelBackgroundRefetch() {
			this.backgroundRefetchGeneration++
			if (this.backgroundRefetchTimer !== null) {
				window.clearTimeout(this.backgroundRefetchTimer)
				this.backgroundRefetchTimer = null
			}
		},

		markProfileSyncFailed() {
			stopProfileRefresh(this, 'failed')
		},

		/**
		 * The rest of a page is being read from the servers it lives on: asks again a
		 * little later and adds what came in to what is shown.
		 *
		 * @param {string} url the context the page was read from
		 * @param {object} params its query
		 * @param {string} identity the timeline it belongs to
		 * @param {number} attempt the retry number, starting at zero
		 */
		refetchThreadLater(url, params, identity, attempt = 0) {
			if (this.backgroundRefetchTimer !== null) {
				return
			}
			this.cancelBackgroundRefetch()
			const generation = this.backgroundRefetchGeneration
			const remoteProfileFill = this.remoteProfilePageFilling
			const delay = remoteProfileFill
				? (attempt < 3 ? PROFILE_REFETCH_MS : REMOTE_PROFILE_REFETCH_MS)
				: this.profilePageFilling
					? PROFILE_REFETCH_MS
					: Math.min(THREAD_REFETCH_MS * (2 ** attempt), THREAD_REFETCH_MAX_MS)
			this.backgroundRefetchTimer = window.setTimeout(async () => {
				this.backgroundRefetchTimer = null
				if (this.getTimelineIdentity !== identity || this.backgroundRefetchGeneration !== generation) {
					return
				}
				try {
					// a unique parameter, so an ETag cannot answer the refresh with a 304
					const response = await axios.get(url, { params: { ...params, _social_refresh: Date.now() } })
					if (this.getTimelineIdentity === identity && this.backgroundRefetchGeneration === generation) {
						this.timelinePageFilling = response.headers?.['x-social-filling'] === '1'
							|| response.headers?.['x-social-thread-filling'] === '1'
						this.profilePageFilling = response.headers?.['x-social-profile-filling'] === '1'
						this.remoteProfilePageFilling = response.headers?.['x-social-remote-profile-filling'] === '1'
						readProfileSyncState(this, response.headers)
						this.timelinePageEmpty = Array.isArray(response.data)
							? response.data.length === 0
							: this.type === 'single-post' && Array.isArray(response.data?.descendants) && response.data.descendants.length === 0
						this.addToTimeline(response.data)
						const remoteProfileFilling = response.headers?.['x-social-remote-profile-filling'] === '1'
						const maxRefetches = remoteProfileFilling ? MAX_REMOTE_PROFILE_REFETCHES : MAX_THREAD_REFETCHES
						if (this.profileSyncState !== 'failed'
							&& (response.headers?.['x-social-thread-filling'] === '1' || response.headers?.['x-social-filling'] === '1')
							&& attempt + 1 < maxRefetches) {
							this.refetchThreadLater(url, params, identity, attempt + 1)
						} else if (this.profilePageFilling) {
							stopProfileRefresh(this, 'stalled')
						}
					}
				} catch (error) {
					if (this.getTimelineIdentity === identity && this.backgroundRefetchGeneration === generation && this.type === 'account') {
						stopProfileRefresh(this, 'failed')
					}
					logger.debug('The background timeline refresh failed', { error })
				}
			}, delay)
		},
	},
})
