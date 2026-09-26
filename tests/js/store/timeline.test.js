/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { toRaw } from 'vue'
import axios from '@nextcloud/axios'
import { showError } from '../../../src/services/toast.js'

import { useTimelineStore } from '../../../src/store/timeline.js'
import logger from '../../../src/services/logger.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

function makeStatus(id, extra = {}) {
	return {
		id,
		uri: `https://cloud.example.org/users/alice/statuses/${id}`,
		content: `<p>post ${id}</p>`,
		created_at: '2026-01-01T10:00:00.000Z',
		favourited: false,
		favourites_count: 0,
		reblogged: false,
		reblogs_count: 0,
		account: { acct: 'alice', display_name: 'Alice' },
		...extra,
	}
}

describe('timeline store state changes', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useTimelineStore()
	})

	/**
	 * The post a reader has just published makes an entrance, once: the
	 * mark comes off by itself, so a reload a minute later does not replay it.
	 */
	it('marks a post as just arrived, and unmarks it when the entrance is over', () => {
		vi.useFakeTimers()
		store.markArrived('9', 1000)
		expect(store.arrivedId).toBe('9')

		vi.advanceTimersByTime(999)
		expect(store.arrivedId).toBe('9')
		vi.advanceTimersByTime(1)
		expect(store.arrivedId).toBe('')
		vi.useRealTimers()
	})

	it('does not unmark a newer post when an older entrance ends', () => {
		vi.useFakeTimers()
		store.markArrived('9', 1000)
		vi.advanceTimersByTime(500)
		store.markArrived('10', 1000)
		vi.advanceTimersByTime(500)

		expect(store.arrivedId).toBe('10')
		vi.useRealTimers()
	})

	it('addToStatuses indexes a status by id and also the boosted status of a boost wrapper', () => {
		const inner = makeStatus('1')
		const wrapper = makeStatus('2', { reblog: inner, content: '' })

		store.addToStatuses(wrapper)

		expect(toRaw(store.statuses['2'])).toBe(wrapper)
		expect(toRaw(store.statuses['1'])).toBe(inner)
		expect(store.timeline).toEqual([])
	})

	/**
	 * A boost carries no text of its own, so the server matches the keyword
	 * filters against the post it boosts and marks both copies. The card draws
	 * the boosted post *out of the store*, so the cover only lands on the right
	 * thing while the indexed copy keeps what the server put on it.
	 */
	it('keeps the filter results the server put on a boosted post', () => {
		const matched = [{ filter: { id: '1', title: 'Politics', filter_action: 'warn' }, keyword_matches: ['election'], status_matches: [] }]
		const inner = makeStatus('1', { filtered: matched })
		const wrapper = makeStatus('2', { reblog: inner, content: '', filtered: matched })

		store.addToStatuses(wrapper)

		expect(store.getStatus('1').filtered).toEqual(matched)
	})

	it('addToTimeline appends ids in the order given and indexes every status', () => {
		const [a, b, c] = [makeStatus('1'), makeStatus('2'), makeStatus('3')]

		store.addToTimeline([b, a, c])

		expect(store.timeline).toEqual(['2', '1', '3'])
		expect(Object.keys(store.statuses).sort()).toEqual(['1', '2', '3'])
		expect(store.parentsTimeline).toEqual([])
	})

	it('addToTimeline de-duplicates ids but refreshes the stored status', () => {
		store.addToTimeline([makeStatus('1'), makeStatus('2')])
		const edited = makeStatus('2', { content: '<p>edited</p>' })

		store.addToTimeline([edited, makeStatus('3')])

		expect(store.timeline).toEqual(['1', '2', '3'])
		expect(toRaw(store.statuses['2'])).toBe(edited)
	})

	it('addToTimeline de-duplicates within one page as well as against the list', () => {
		// a timeline that is being paged while posts arrive can send the same
		// status twice in one answer; it used to be appended twice, and the
		// duplicate key made Vue drop one of the two entries
		const twice = makeStatus('2')

		store.addToTimeline([makeStatus('1'), twice, twice, makeStatus('3')])

		expect(store.timeline).toEqual(['1', '2', '3'])
	})

	it('addToTimeline with a status context puts ancestors and descendants in separate lists', () => {
		const parent = makeStatus('1')
		const reply = makeStatus('3')

		store.addToTimeline({ ancestors: [parent], descendants: [reply] })

		expect(store.parentsTimeline).toEqual(['1'])
		expect(store.timeline).toEqual(['3'])
		expect(toRaw(store.statuses['1'])).toBe(parent)
		expect(toRaw(store.statuses['3'])).toBe(reply)

		store.addToTimeline({ ancestors: [parent], descendants: [reply, makeStatus('4')] })

		expect(store.parentsTimeline).toEqual(['1'])
		expect(store.timeline).toEqual(['3', '4'])
	})

	it('removeStatus drops the id from the timeline and from the index', () => {
		store.addToTimeline([makeStatus('1'), makeStatus('2'), makeStatus('3')])

		store.removeStatus({ id: '2' })

		expect(store.timeline).toEqual(['1', '3'])
		// the object used to be left behind, so the index only ever grew
		expect(store.statuses['2']).toBeUndefined()
	})

	it('restoreStatus puts a status back into the list it came from', () => {
		const parent = makeStatus('1')
		const reply = makeStatus('2')
		store.addToTimeline({ ancestors: [parent], descendants: [reply] })

		store.removeStatus(parent)
		store.removeStatus(reply)
		expect(store.parentsTimeline).toEqual([])
		expect(store.timeline).toEqual([])

		store.restoreStatus(parent)
		store.restoreStatus(reply)

		// an ancestor goes back among the ancestors: addToTimeline always
		// appended to store.timeline, so a failed delete of a parent
		// reappeared among its own replies
		expect(store.parentsTimeline).toEqual(['1'])
		expect(store.timeline).toEqual(['2'])
		expect(toRaw(store.statuses['1'])).toBe(parent)
	})

	it('removeStatus ignores ids that are not in the timeline', () => {
		store.addToTimeline({ ancestors: [makeStatus('1')], descendants: [makeStatus('2')] })

		store.removeStatus({ id: 'missing' })

		expect(store.timeline).toEqual(['2'])
		expect(store.parentsTimeline).toEqual(['1'])
	})

	it('removeStatus leaves a non-empty parentsTimeline intact when the status is only in the timeline', () => {
		store.addToTimeline({
			ancestors: [makeStatus('1'), makeStatus('2')],
			descendants: [makeStatus('3')],
		})

		store.removeStatus({ id: '3' })

		expect(store.timeline).toEqual([])
		expect(store.parentsTimeline).toEqual(['1', '2'])
	})

	it('removeStatus drops the status from both lists when it is in both', () => {
		store.addToTimeline({ ancestors: [makeStatus('1')], descendants: [makeStatus('2')] })
		// the same status also sits in the timeline
		store.timeline.push('1')

		store.removeStatus({ id: '1' })

		expect(store.timeline).toEqual(['2'])
		expect(store.parentsTimeline).toEqual([])
	})

	it('removeStatusesByActor drops exactly that actor\'s statuses from both lists and the index', () => {
		const byBob = (id) => makeStatus(id, { account: { id: '22', acct: 'bob@remote.tld' } })
		const byCarol = (id) => makeStatus(id, { account: { id: '33', acct: 'carol@remote.tld' } })
		store.addToTimeline({
			ancestors: [byBob('1'), byCarol('2')],
			descendants: [byBob('3'), byCarol('4')],
		})

		store.removeStatusesByActor('22')

		expect(store.timeline).toEqual(['4'])
		expect(store.parentsTimeline).toEqual(['2'])
		expect(Object.keys(store.statuses).sort()).toEqual(['2', '4'])
	})

	it('removeStatusesByActor also matches a numeric account id against string status ids', () => {
		store.addToTimeline([makeStatus('1', { account: { id: '22', acct: 'bob@remote.tld' } })])

		store.removeStatusesByActor(22)

		expect(store.timeline).toEqual([])
		expect(store.statuses['1']).toBeUndefined()
	})

	it('removeStatusesByActor drops boosts that wrap a status of the actor', () => {
		const inner = makeStatus('1', { account: { id: '22', acct: 'bob@remote.tld' } })
		const boost = makeStatus('2', { reblog: inner, content: '', account: { id: '33', acct: 'carol@remote.tld' } })
		store.addToTimeline([boost, makeStatus('3', { account: { id: '33', acct: 'carol@remote.tld' } })])

		store.removeStatusesByActor('22')

		expect(store.timeline).toEqual(['3'])
		expect(store.statuses['1']).toBeUndefined()
		expect(store.statuses['2']).toBeUndefined()
		expect(store.statuses['3']).toBeDefined()
	})

	it('removeStatusesByActor leaves the state untouched when the actor has no statuses', () => {
		store.addToTimeline([makeStatus('1', { account: { id: '33', acct: 'carol@remote.tld' } })])
		const statusesBefore = toRaw(store.statuses)

		store.removeStatusesByActor('22')

		expect(store.timeline).toEqual(['1'])
		expect(toRaw(store.statuses)).toBe(statusesBefore)
	})

	it('resetTimeline empties both id lists and prunes the index, keeping the type', () => {
		store.type = 'tags'
		store.addToTimeline({ ancestors: [makeStatus('1')], descendants: [makeStatus('2')] })

		store.resetTimeline()

		expect(store.timeline).toEqual([])
		expect(store.parentsTimeline).toEqual([])
		// the id lists used to be the only thing cleared, so every page of
		// every timeline ever opened stayed in memory for the session
		expect(store.statuses).toEqual({})
		expect(store.type).toBe('tags')
	})

	it('setters replace their field', () => {
		store.setTimelineType('federated')
		store.setTimelineParams({ tag: 'nextcloud' })
		store.setAccount('bob@remote.tld')
		store.setSearchQuery('hello')
		store.setComposerDisplayStatus(true)

		expect(store.$state).toMatchObject({
			type: 'federated',
			params: { tag: 'nextcloud' },
			account: 'bob@remote.tld',
			searchQuery: 'hello',
			composerDisplayStatus: true,
		})
	})

	it('likeStatus marks the status favourited and bumps the counter without touching the payload object', () => {
		const original = makeStatus('1', { favourites_count: 4 })
		store.addToStatuses(original)

		store.likeStatus({ status: original })

		expect(store.statuses['1']).toMatchObject({ favourited: true, favourites_count: 5 })
		expect(original).toMatchObject({ favourited: false, favourites_count: 4 })
	})

	it('unlikeStatus reverses a like', () => {
		store.addToStatuses(makeStatus('1', { favourited: true, favourites_count: 1 }))

		store.unlikeStatus({ status: { id: '1' } })

		expect(store.statuses['1']).toMatchObject({ favourited: false, favourites_count: 0 })
	})

	it('boostStatus and unboostStatus toggle reblogged and the reblogs counter', () => {
		store.addToStatuses(makeStatus('1', { reblogs_count: 2 }))

		store.boostStatus({ status: { id: '1' } })
		expect(store.statuses['1']).toMatchObject({ reblogged: true, reblogs_count: 3 })

		store.unboostStatus({ status: { id: '1' } })
		expect(store.statuses['1']).toMatchObject({ reblogged: false, reblogs_count: 2 })
	})

	it('like and boost are no-ops for statuses that are not indexed', () => {
		store.likeStatus({ status: { id: 'x' } })
		store.unlikeStatus({ status: { id: 'x' } })
		store.boostStatus({ status: { id: 'x' } })
		store.unboostStatus({ status: { id: 'x' } })

		expect(store.statuses).toEqual({})
	})

	it('like and boost only touch the entry whose id is given, for boost wrappers and boosted statuses alike', () => {
		const inner = makeStatus('1', { favourites_count: 1, reblogs_count: 1 })
		const wrapper = makeStatus('2', { reblog: inner, content: '' })
		store.addToStatuses(wrapper)

		store.likeStatus({ status: inner })
		expect(store.statuses['1']).toMatchObject({ favourited: true, favourites_count: 2 })
		expect(store.statuses['2']).toMatchObject({ favourited: false, favourites_count: 0 })

		store.boostStatus({ status: wrapper })
		expect(store.statuses['2']).toMatchObject({ reblogged: true, reblogs_count: 1 })
		expect(store.statuses['1']).toMatchObject({ reblogged: false, reblogs_count: 1 })
	})

	it('updateStatus replaces an indexed status and ignores unknown ones', () => {
		store.addToStatuses(makeStatus('1'))
		const edited = makeStatus('1', { content: '<p>edited</p>' })

		store.updateStatus(edited)
		store.updateStatus(makeStatus('9'))

		expect(toRaw(store.statuses['1'])).toBe(edited)
		expect(store.statuses['9']).toBeUndefined()
	})
})

describe('timeline store getters', () => {
	let store

	beforeEach(() => {
		vi.clearAllMocks()
		setActivePinia(createPinia())
		store = useTimelineStore()
	})

	it('getTimeline returns statuses newest first regardless of insertion order and skips unknown ids', () => {
		const older = makeStatus('1', { created_at: '2026-01-01T10:00:00.000Z' })
		const newer = makeStatus('2', { created_at: '2026-01-02T10:00:00.000Z' })
		store.addToTimeline([older, newer])
		store.timeline.push('ghost')

		expect(store.getTimeline).toEqual([newer, older])
	})

	it('getTimeline is the timeline, not a client-side search over it', () => {
		store.addToTimeline([
			makeStatus('1', { created_at: '2026-01-03T10:00:00.000Z', content: '<p>Hello Fediverse</p>' }),
			makeStatus('2', { created_at: '2026-01-02T10:00:00.000Z', content: '<p>nothing</p>', account: { acct: 'bob@remote.tld', display_name: 'Bob' } }),
			makeStatus('3', { created_at: '2026-01-01T10:00:00.000Z', content: '<p>nothing</p>', account: { acct: 'carol', display_name: 'Carol FEDI' } }),
		])

		// filtering the ~15 loaded statuses with String.includes answered
		// "No posts match your search" for posts the instance was holding;
		// searching asks /api/v2/search now, and the timeline stays whole
		store.searchQuery = 'fedi'
		expect(store.getTimeline.map((s) => s.id)).toEqual(['1', '2', '3'])
	})

	it('getParentsTimeline sorts and filters the ancestors the same way', () => {
		store.addToTimeline({
			ancestors: [
				makeStatus('1', { created_at: '2026-01-01T10:00:00.000Z', content: '<p>root</p>' }),
				makeStatus('2', { created_at: '2026-01-02T10:00:00.000Z', content: '<p>middle</p>' }),
			],
			descendants: [makeStatus('3', { content: '<p>root reply</p>' })],
		})

		expect(store.getParentsTimeline.map((s) => s.id)).toEqual(['2', '1'])

		store.searchQuery = 'root'
		expect(store.getParentsTimeline.map((s) => s.id)).toEqual(['2', '1'])
	})

	it('getStatus, getSinglePost, getSearchQuery and getComposerDisplayStatus read from the state', () => {
		const post = makeStatus('7')
		store.addToStatuses(post)
		store.params = { singlePost: '7' }
		store.searchQuery = 'q'
		store.composerDisplayStatus = true

		expect(toRaw(store.getStatus('7'))).toBe(post)
		expect(store.getStatus('8')).toBeUndefined()
		expect(toRaw(store.getSinglePost)).toBe(post)
		expect(store.getSearchQuery).toBe('q')
		expect(store.getComposerDisplayStatus).toBe(true)
	})

	it('getPostFromTimeline returns the status, or warns and returns undefined', () => {
		const post = makeStatus('7')
		store.addToStatuses(post)

		expect(toRaw(store.getPostFromTimeline('7'))).toBe(post)
		expect(logger.warn).not.toHaveBeenCalled()

		expect(store.getPostFromTimeline('8')).toBeUndefined()
		expect(logger.warn).toHaveBeenCalledWith('Could not find status in timeline', { statusId: '8' })
	})
})

describe('timeline store actions', () => {
	let store
	const tl = () => store.$state

	beforeEach(() => {
		vi.resetAllMocks()
		// a Pinia store's state is built by a factory, so every test gets its own
		setActivePinia(createPinia())
		store = useTimelineStore()
	})

	it('changeTimelineType resets the lists and stores type and params', async () => {
		store.addToTimeline({ ancestors: [makeStatus('1')], descendants: [makeStatus('2')] })
		store.setAccount('bob@remote.tld')

		await store.changeTimelineType({ type: 'tags', params: { tag: 'nextcloud' } })

		expect(tl()).toMatchObject({
			timeline: [],
			parentsTimeline: [],
			type: 'tags',
			params: { tag: 'nextcloud' },
			account: '',
		})
		expect(tl().statuses).toEqual({})
	})

	it('changeTimelineTypeAccount switches to the statuses of one account', async () => {
		store.addToTimeline([makeStatus('1')])

		await store.changeTimelineTypeAccount('bob@remote.tld')

		expect(tl()).toMatchObject({ timeline: [], type: 'account', account: 'bob@remote.tld' })
	})

	it('changeTimelineType keeps what is loaded when it is the same timeline', async () => {
		await store.changeTimelineType({ type: 'home', params: {} })
		store.addToTimeline([makeStatus('1'), makeStatus('2')])

		// the view mounts again — a navigation that ends up where it started —
		// and asks for the list it is already showing
		await store.changeTimelineType({ type: 'home', params: {} })

		expect(tl().timeline).toEqual(['1', '2'])
		expect(Object.keys(tl().statuses)).toEqual(['1', '2'])
	})

	it('changeTimelineType puts the timeline back when the reader comes straight back to it', async () => {
		await store.changeTimelineType({ type: 'home', params: {} })
		store.addToTimeline([makeStatus('1'), makeStatus('2'), makeStatus('3')])

		// into a post, and back out of it: this is what Back does, and what
		// used to leave the reader on the first page of a list they had read
		// four pages into
		await store.changeTimelineType({ type: 'single-post', params: { id: '2', singlePost: '2' } })
		expect(tl().timeline).toEqual([])

		await store.changeTimelineType({ type: 'home', params: {} })

		expect(tl().timeline).toEqual(['1', '2', '3'])
		expect(tl().restored).toBe(true)
	})

	it('changeTimelineType clears the timeline when it is a different one', async () => {
		await store.changeTimelineType({ type: 'home', params: {} })
		store.addToTimeline([makeStatus('1'), makeStatus('2')])

		await store.changeTimelineType({ type: 'federated', params: {} })

		expect(tl().timeline).toEqual([])
		expect(tl().statuses).toEqual({})
		expect(tl().restored).toBe(false)
	})

	/**
	 * The switcher above the posts is three places, and a reader moves between
	 * them all day. With one slot, two of every three switches threw the list
	 * away and asked the server again — which on screen is the content
	 * replaced by a skeleton for as long as the round trip takes.
	 */
	it('changeTimelineType brings back a list from further than one switch ago', async () => {
		await store.changeTimelineType({ type: 'home', params: {} })
		store.addToTimeline([makeStatus('1')])
		await store.changeTimelineType({ type: 'federated', params: {} })
		store.addToTimeline([makeStatus('2')])
		await store.changeTimelineType({ type: 'timeline', params: {} })
		store.addToTimeline([makeStatus('3')])

		// My Feed is two switches back and is still there
		await store.changeTimelineType({ type: 'home', params: {} })
		expect(tl().timeline).toEqual(['1'])
		expect(tl().restored).toBe(true)

		await store.changeTimelineType({ type: 'federated', params: {} })
		expect(tl().timeline).toEqual(['2'])
	})

	it('changeTimelineType forgets the oldest rather than every timeline ever opened', async () => {
		// each held list carries its own status index, so the number of them
		// is a memory ceiling and not only a convenience
		// six visits means five departures, so the first list has fallen off a
		// shelf that holds four
		const visited = ['home', 'federated', 'timeline', 'direct', 'notifications', 'liked']
		for (const [index, type] of visited.entries()) {
			await store.changeTimelineType({ type, params: {} })
			store.addToTimeline([makeStatus(String(index))])
		}

		// the oldest departure is gone; the rest are still there
		await store.changeTimelineType({ type: 'home', params: {} })
		expect(tl().timeline).toEqual([])

		await store.changeTimelineType({ type: 'direct', params: {} })
		expect(tl().timeline).toEqual(['3'])
	})

	it('changeTimelineTypeAccount tells a profile from a tab of the same profile', async () => {
		await store.changeTimelineTypeAccount('bob@remote.tld')
		store.addToTimeline([makeStatus('1')])

		await store.changeTimelineTypeAccount('bob@remote.tld', 'image')
		expect(tl().timeline).toEqual([])

		await store.changeTimelineTypeAccount('bob@remote.tld')
		expect(tl().timeline).toEqual(['1'])
	})

	it('addToTimeline stores the given statuses', async () => {
		await store.addToTimeline([makeStatus('1'), makeStatus('2')])

		expect(tl().timeline).toEqual(['1', '2'])
	})

	describe('fetchStatus', () => {
		it('asks for one post by its id and indexes it', async () => {
			axios.get.mockResolvedValue({ data: makeStatus('42') })

			const status = await store.fetchStatus('42')

			expect(axios.get).toHaveBeenCalledWith(`${API}/statuses/42`)
			expect(status.id).toBe('42')
			expect(tl().statuses['42']).toBeDefined()
		})

		it('answers null for a post this server does not have', async () => {
			// a deleted post and one that never arrived look the same from here
			axios.get.mockRejectedValue(new Error('gone'))

			expect(await store.fetchStatus('42')).toBeNull()
			expect(tl().statuses['42']).toBeUndefined()
		})
	})

	describe('createMedia', () => {
		it('uploads the file as multipart form data and returns the media entity', async () => {
			const file = new File(['png'], 'cat.png', { type: 'image/png' })
			const media = { id: '42', url: 'https://cloud.example.org/media/42' }
			axios.post.mockResolvedValue({ data: media })

			const result = await store.createMedia(file)

			expect(result).toEqual(media)
			const [url, body, config] = axios.post.mock.calls[0]
			expect(url).toBe(`${API}/media`)
			expect(body).toBeInstanceOf(FormData)
			expect(body.get('file')).toBe(file)
			expect([...body.keys()]).toEqual(['file'])
			expect(config.headers['Content-Type']).toBe('multipart/form-data')
		})

		it('shows an error and resolves to undefined when the upload fails', async () => {
			axios.post.mockRejectedValue(new Error('boom'))

			await expect(store.createMedia(new File(['x'], 'x.txt'))).resolves.toBeUndefined()

			expect(showError).toHaveBeenCalledWith('Could not upload the attachment')
			expect(logger.error).toHaveBeenCalledWith('Failed to create a media', { error: expect.any(Error) })
		})

		it('reports how far the upload has got, so the bar is real', async () => {
			const file = new File(['png'], 'cat.png', { type: 'image/png' })
			axios.post.mockResolvedValue({ data: { id: '42' } })
			const onProgress = vi.fn()

			await store.createMedia({ file, onProgress })

			const [, , config] = axios.post.mock.calls[0]
			config.onUploadProgress({ loaded: 50, total: 200 })
			config.onUploadProgress({ loaded: 200, total: 200 })
			// the composer used to pass :upload-progress="0.4" behind a v-if="false"
			expect(onProgress.mock.calls.map(([fraction]) => fraction)).toEqual([0.25, 1])
		})

		it('reports nothing rather than dividing by zero for a size the browser does not know', async () => {
			axios.post.mockResolvedValue({ data: { id: '42' } })
			const onProgress = vi.fn()

			await store.createMedia({ file: new File(['x'], 'x.txt'), onProgress })

			const [, , config] = axios.post.mock.calls[0]
			config.onUploadProgress({ loaded: 10, total: undefined })
			expect(onProgress).toHaveBeenCalledWith(0)
		})
	})

	describe('createMediaFromFile', () => {
		it('sends the path of a file the reader already has, and nothing else', async () => {
			const media = { id: '42', url: 'https://cloud.example.org/media/42' }
			axios.post.mockResolvedValue({ data: media })

			const result = await store.createMediaFromFile({ path: '/Photos/beach.jpg' })

			expect(result).toEqual(media)
			expect(axios.post).toHaveBeenCalledWith(
				`${API}/media/from-file`,
				{ path: '/Photos/beach.jpg', description: '' },
			)
		})

		it('carries a description straight into the request', async () => {
			axios.post.mockResolvedValue({ data: { id: '42' } })

			await store.createMediaFromFile({ path: '/Photos/beach.jpg', description: 'The sea' })

			expect(axios.post).toHaveBeenCalledWith(
				`${API}/media/from-file`,
				{ path: '/Photos/beach.jpg', description: 'The sea' },
			)
		})

		it('names the file it could not attach and resolves to undefined', async () => {
			axios.post.mockRejectedValue(new Error('nope'))

			await expect(store.createMediaFromFile({ path: '/Photos/beach.jpg' }))
				.resolves.toBeUndefined()

			// a picker run attaches several at once: "it failed" would not say
			// which one to try again
			expect(showError).toHaveBeenCalledWith('Could not attach /Photos/beach.jpg')
			expect(logger.error).toHaveBeenCalledWith('Failed to attach a file from Nextcloud', { error: expect.any(Error) })
		})
	})

	describe('describeMedia', () => {
		it('tells the server what an attachment shows', async () => {
			axios.put.mockResolvedValue({ data: {} })

			await store.describeMedia({ id: '7', description: 'a cat' })

			expect(axios.put).toHaveBeenCalledWith(
				expect.stringContaining('/api/v1/media/7'),
				{ description: 'a cat' },
			)
		})

		it('reports a failure without taking the post down with it', async () => {
			axios.put.mockRejectedValue(new Error('nope'))

			await expect(store.describeMedia({ id: '7', description: 'a cat' }))
				.resolves.toBeUndefined()
			expect(showError).toHaveBeenCalled()
		})
	})

	describe('post', () => {
		it('POSTs the status payload to /statuses and answers with what was created', async () => {
			axios.post.mockResolvedValue({ data: { id: '1' } })
			const payload = { status: 'hello', visibility: 'public', spoiler_text: '', media_ids: ['42'] }

			// the composer cannot tell success from failure without this: it
			// used to clear itself either way
			await expect(store.post(payload)).resolves.toEqual({ id: '1' })

			expect(axios.post).toHaveBeenCalledWith(`${API}/statuses`, payload)
			expect(showError).not.toHaveBeenCalled()
		})

		it('reports a failure instead of throwing', async () => {
			axios.post.mockRejectedValue(new Error('boom'))

			await expect(store.post({ status: 'x' })).resolves.toBeUndefined()

			expect(showError).toHaveBeenCalledWith('Could not send the post')
			expect(logger.error).toHaveBeenCalledWith('Failed to create a status', { error: expect.any(Error) })
		})
	})

	describe('updateStatusPoll', () => {
		it('carries a vote into the store, where every other view reads it', () => {
			const status = makeStatus('1', { poll: { id: 'p1', voted: false, votes_count: 0 } })
			store.addToTimeline([status])

			store.updateStatusPoll({
				statusId: '1',
				poll: { id: 'p1', voted: true, votes_count: 1, own_votes: [0] },
			})

			// the vote used to live only in the component's own copy, so
			// navigating away and back showed the poll unvoted again
			expect(tl().statuses['1'].poll).toEqual({ id: 'p1', voted: true, votes_count: 1, own_votes: [0] })
		})

		it('ignores a poll for a status the store does not hold', () => {
			store.updateStatusPoll({ statusId: 'missing', poll: { id: 'p1' } })
			expect(tl().statuses.missing).toBeUndefined()
		})
	})

	describe('postEdit', () => {
		it('PUTs the new content and stores the status returned by the server', async () => {
			const status = makeStatus('1')
			store.addToTimeline([status])
			const edited = makeStatus('1', { content: '<p>edited</p>' })
			axios.put.mockResolvedValue({ data: edited })

			const response = await store.postEdit({ status, content: 'edited', spoiler_text: 'cw', sensitive: true })

			expect(axios.put).toHaveBeenCalledWith(`${API}/statuses/1`, { status: 'edited', spoiler_text: 'cw', sensitive: true })
			expect(tl().statuses['1']).toEqual(edited)
			expect(response.data).toEqual(edited)
		})

		it('leaves the status untouched and shows an error when editing fails', async () => {
			const status = makeStatus('1')
			store.addToTimeline([status])
			axios.put.mockRejectedValue(new Error('boom'))

			await expect(store.postEdit({ status, content: 'x', spoiler_text: '', sensitive: false })).resolves.toBeUndefined()

			expect(tl().statuses['1']).toEqual(status)
			expect(showError).toHaveBeenCalledWith('Could not save the changes to the post')
		})
	})

	describe('postDelete', () => {
		it('removes the post from the timeline before sending DELETE with the post uri', async () => {
			const status = makeStatus('1')
			store.addToTimeline([status, makeStatus('2')])
			let timelineDuringRequest
			axios.delete.mockImplementation(async () => {
				timelineDuringRequest = [...tl().timeline]
				return { data: { status: 1, result: [] } }
			})

			await store.postDelete(status)

			expect(axios.delete).toHaveBeenCalledWith(`${API}/post?id=${status.uri}`)
			expect(timelineDuringRequest).toEqual(['2'])
			expect(tl().timeline).toEqual(['2'])
			expect(showError).not.toHaveBeenCalled()
		})

		it('re-indexes the status, restores it to the timeline and shows an error when the deletion fails', async () => {
			const status = makeStatus('1')
			store.addToTimeline([status, makeStatus('2')])
			axios.delete.mockRejectedValue(new Error('boom'))

			await store.postDelete(status)

			expect(tl().statuses['1']).toEqual(status)
			expect(tl().timeline).toContain('1')
			expect(tl().timeline).toEqual(['2', '1'])
			expect(showError).toHaveBeenCalledWith('Could not delete the post')
		})
	})

	describe.each([
		['postLike', 'favourite', 'favourited', 'favourites_count', false, 1, 'Could not like the post'],
		['postUnlike', 'unfavourite', 'favourited', 'favourites_count', true, -1, 'Could not remove the like'],
		['postBoost', 'reblog', 'reblogged', 'reblogs_count', false, 1, 'Could not boost the post'],
		['postUnBoost', 'unreblog', 'reblogged', 'reblogs_count', true, -1, 'Could not undo the boost'],
	])('%s', (action, endpoint, flag, counter, initialFlag, delta, errorMessage) => {
		const initial = () => makeStatus('1', { [flag]: initialFlag, [counter]: 3 })

		it(`applies the change optimistically, POSTs to /statuses/:id/${endpoint} and stores the server copy`, async () => {
			const status = initial()
			store.addToTimeline([status])
			const serverCopy = makeStatus('1', { [flag]: !initialFlag, [counter]: 3 + delta, content: '<p>from server</p>' })
			let duringRequest
			axios.post.mockImplementation(async () => {
				duringRequest = { ...tl().statuses['1'] }
				return { data: serverCopy }
			})

			const response = await store[action]({ status })

			expect(axios.post).toHaveBeenCalledWith(`${API}/statuses/1/${endpoint}`)
			expect(duringRequest).toMatchObject({ [flag]: !initialFlag, [counter]: 3 + delta })
			expect(tl().statuses['1']).toEqual(serverCopy)
			expect(response.data).toEqual(serverCopy)
			expect(showError).not.toHaveBeenCalled()
		})

		it('rolls the optimistic change back and shows an error when the request fails', async () => {
			const status = initial()
			store.addToTimeline([status])
			axios.post.mockRejectedValue(new Error('boom'))

			await expect(store[action]({ status })).resolves.toBeUndefined()

			expect(tl().statuses['1']).toMatchObject({ [flag]: initialFlag, [counter]: 3 })
			expect(tl().timeline).toEqual(['1'])
			expect(showError).toHaveBeenCalledWith(errorMessage)
		})
	})

	describe.each([
		['bookmark', true, 'Could not bookmark the post'],
		['unbookmark', false, 'Could not remove the bookmark'],
	])('postBookmark (%s)', (endpoint, bookmarked, errorMessage) => {
		it(`flips the flag, POSTs to /statuses/:id/${endpoint} and stores the server copy`, async () => {
			const status = makeStatus('1', { bookmarked: !bookmarked })
			store.addToTimeline([status])
			const serverCopy = makeStatus('1', { bookmarked, content: '<p>from server</p>' })
			let duringRequest
			axios.post.mockImplementation(async () => {
				duringRequest = { ...tl().statuses['1'] }
				return { data: serverCopy }
			})

			const response = await store.postBookmark({ status, bookmarked })

			expect(axios.post).toHaveBeenCalledWith(`${API}/statuses/1/${endpoint}`)
			expect(duringRequest).toMatchObject({ bookmarked })
			expect(tl().statuses['1']).toEqual(serverCopy)
			expect(response.data).toEqual(serverCopy)
			expect(showError).not.toHaveBeenCalled()
		})

		it('puts the flag back and reports when the server refuses', async () => {
			const status = makeStatus('1', { bookmarked: !bookmarked })
			store.addToTimeline([status])
			axios.post.mockRejectedValue(new Error('boom'))

			await expect(store.postBookmark({ status, bookmarked })).resolves.toBeUndefined()

			expect(tl().statuses['1']).toMatchObject({ bookmarked: !bookmarked })
			expect(showError).toHaveBeenCalledWith(errorMessage)
		})
	})

	describe('unbookmarking on the bookmarks timeline', () => {
		it('takes the post off the list it is no longer on', async () => {
			axios.post.mockResolvedValue({ data: makeStatus('1', { bookmarked: false }) })
			store.setTimelineType('bookmarks')
			store.addToTimeline([
				makeStatus('1', { bookmarked: true }),
				makeStatus('2', { bookmarked: true }),
			])

			await store.postBookmark({ status: makeStatus('1', { bookmarked: true }), bookmarked: false })

			expect(tl().timeline).toEqual(['2'])
			expect(tl().removedFrom).toEqual({})
		})
	})

	describe.each([
		['pin', true, 'Could not pin the post'],
		['unpin', false, 'Could not unpin the post'],
	])('postPin (%s)', (endpoint, pinned, errorMessage) => {
		it(`flips the flag optimistically, POSTs to /statuses/:id/${endpoint} and stores the server copy`, async () => {
			const status = makeStatus('1', { pinned: !pinned })
			store.addToTimeline([status])
			const serverCopy = makeStatus('1', { pinned, content: '<p>from server</p>' })
			let duringRequest
			axios.post.mockImplementation(async () => {
				duringRequest = { ...tl().statuses['1'] }
				return { data: serverCopy }
			})

			const response = await store.postPin({ status, pinned })

			expect(axios.post).toHaveBeenCalledWith(`${API}/statuses/1/${endpoint}`)
			expect(duringRequest).toMatchObject({ pinned })
			expect(tl().statuses['1']).toEqual(serverCopy)
			expect(response.data).toEqual(serverCopy)
			expect(showError).not.toHaveBeenCalled()
		})

		it('rolls the flag back and reports when the server refuses', async () => {
			const status = makeStatus('1', { pinned: !pinned })
			store.addToTimeline([status])
			axios.post.mockRejectedValue(new Error('too many pins'))

			await expect(store.postPin({ status, pinned })).resolves.toBeUndefined()

			expect(tl().statuses['1']).toMatchObject({ pinned: !pinned })
			expect(showError).toHaveBeenCalledWith(errorMessage)
		})
	})

	describe('postUnlike on the Liked timeline', () => {
		const liked = () => makeStatus('1', { favourited: true, favourites_count: 3 })

		it('takes the post off the list of liked posts', async () => {
			axios.post.mockResolvedValue({ data: makeStatus('1', { favourited: false, favourites_count: 2 }) })
			store.setTimelineType('favourites')
			store.addToTimeline([liked(), makeStatus('2', { favourited: true })])

			await store.postUnlike({ status: liked() })

			expect(tl().timeline).toEqual(['2'])
			expect(tl().statuses['1']).toMatchObject({ favourited: false })
		})

		it('forgets where the post came from once the unlike stands', async () => {
			// only restoreStatus cleared the hint, so a rollback of the same id
			// later on would still be told it belonged to the parents list
			axios.post.mockResolvedValue({ data: makeStatus('1', { favourited: false }) })
			store.setTimelineType('favourites')
			store.addToTimeline([liked()])

			await store.postUnlike({ status: liked() })

			expect(tl().removedFrom).toEqual({})
		})

		it('leaves the post where it is on every other timeline', async () => {
			axios.post.mockResolvedValue({ data: makeStatus('1', { favourited: false, favourites_count: 2 }) })
			store.setTimelineType('home')
			store.addToTimeline([liked()])

			await store.postUnlike({ status: liked() })

			expect(tl().timeline).toEqual(['1'])
			expect(tl().statuses['1']).toMatchObject({ favourited: false })
		})

		it('puts the post back unchanged when the server refuses the unlike', async () => {
			axios.post.mockRejectedValue(new Error('boom'))
			store.setTimelineType('favourites')
			store.addToTimeline([liked()])

			await store.postUnlike({ status: liked() })

			expect(tl().timeline).toEqual(['1'])
			// exactly the pre-unlike state: restoring the status AND re-liking
			// it used to leave favourites_count one too high
			expect(tl().statuses['1']).toMatchObject({ favourited: true, favourites_count: 3 })
			expect(showError).toHaveBeenCalledWith('Could not remove the like')
		})
	})

	describe('fetchTimeline', () => {
		const statuses = [makeStatus('1'), makeStatus('2')]

		beforeEach(() => {
			axios.get.mockResolvedValue({ data: statuses })
		})

		it.each([
			['home', {}, `${API}/timelines/home`, { limit: 15 }],
			['direct', {}, `${API}/timelines/direct`, { limit: 15 }],
			['favourites', {}, `${API}/timelines/favourites`, { limit: 15 }],
			['notifications', {}, `${API}/notifications`, { limit: 15 }],
			['timeline', {}, `${API}/timelines/public`, { limit: 15, local: true }],
			['federated', {}, `${API}/timelines/public`, { limit: 15 }],
			['tags', { tag: 'nextcloud' }, `${API}/timelines/tag/nextcloud`, { limit: 15 }],
			['list', { id: '4' }, `${API}/timelines/list/4`, { limit: 15 }],
			// the same three feeds as above, with the text-only posts left out.
			// `media_type` as well as `only_media`, or the page answers with
			// every video on the instance: "carries an attachment" is not the
			// question a page called Photos is asking.
			['photos', {}, `${API}/timelines/home`, { limit: 15, only_media: true, media_type: 'image' }],
			['photos', { scope: 'timeline' }, `${API}/timelines/public`, { limit: 15, only_media: true, media_type: 'image', local: true }],
			['photos', { scope: 'federated' }, `${API}/timelines/public`, { limit: 15, only_media: true, media_type: 'image' }],
			// a scope from the address bar that names nothing is read as the default
			['photos', { scope: 'favourites' }, `${API}/timelines/home`, { limit: 15, only_media: true, media_type: 'image' }],
			// the same page again, one predicate narrower. `only_media` goes
			// out as well, so a server that has not been upgraded yet answers
			// with media rather than with everything.
			['videos', {}, `${API}/timelines/home`, { limit: 15, only_media: true, only_video: true }],
			['videos', { scope: 'timeline' }, `${API}/timelines/public`, { limit: 15, only_media: true, local: true, only_video: true }],
			['videos', { scope: 'federated' }, `${API}/timelines/public`, { limit: 15, only_media: true, only_video: true }],
			// everything said here about one article. The link rides in the
			// query, not the path: a URL inside a path segment has to survive
			// two rounds of encoding and one web server's idea of a slash.
			['link', { url: 'https://paper.example/piece' }, `${API}/timelines/link`, { limit: 15, url: 'https://paper.example/piece' }],
		])('requests the %s timeline from its endpoint and appends the result', async (type, params, url, query) => {
			await store.changeTimelineType({ type, params })

			const result = await store.fetchTimeline()

			expect(axios.get).toHaveBeenCalledTimes(1)
			expect(axios.get).toHaveBeenCalledWith(url, { params: query })
			expect(result).toEqual(statuses)
			expect(tl().timeline).toEqual(['1', '2'])
		})

		/**
		 * Photos asked `only_media`, which is Mastodon's question — "does this
		 * post carry an attachment" — and answered a page called Photos with
		 * every video on the instance. Measured against a real instance: forty
		 * posts carried twenty-five video attachments. The profile's Photos tab
		 * and Explore's Pictures tab were both already asking the narrower
		 * question; this one was not.
		 */
		it('asks Photos and Videos for different things', async () => {
			await store.changeTimelineType({ type: 'photos', params: {} })
			await store.fetchTimeline()
			const photos = axios.get.mock.calls[0][1].params

			axios.get.mockClear()
			await store.changeTimelineType({ type: 'videos', params: {} })
			await store.fetchTimeline()
			const videos = axios.get.mock.calls[0][1].params

			expect(photos.media_type).toBe('image')
			expect(photos.only_video).toBeUndefined()
			expect(videos.only_video).toBe(true)
			expect(videos.media_type).toBeUndefined()
		})

		it('asks for every kind of notification when nothing is filtered', async () => {
			await store.changeTimelineType({ type: 'notifications', params: { filter: 'all' } })

			await store.fetchTimeline()

			expect(axios.get).toHaveBeenCalledWith(`${API}/notifications`, { params: { limit: 15 } })
		})

		it('turns the page\'s filter into the exclusions the API takes', async () => {
			await store.changeTimelineType({ type: 'notifications', params: { filter: 'mentions' } })

			await store.fetchTimeline()

			const { params } = axios.get.mock.calls[0][1]
			expect(params.exclude_types).not.toContain('mention')
			expect(params.exclude_types).toContain('favourite')
			expect(params.exclude_types).toContain('reblog')
		})

		it('is a different timeline per filter, so changing one refetches', async () => {
			await store.changeTimelineType({ type: 'notifications', params: { filter: 'all' } })
			const everything = store.getTimelineIdentity

			await store.changeTimelineType({ type: 'notifications', params: { filter: 'boosts' } })

			expect(store.getTimelineIdentity).not.toBe(everything)
		})

		it('requests the statuses of one account for the account timeline', async () => {
			await store.changeTimelineTypeAccount('bob@remote.tld')

			await store.fetchTimeline()

			expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/bob@remote.tld/statuses`, { params: { limit: 15 } })
			expect(tl().timeline).toEqual(['1', '2'])
		})

		/**
		 * The profile's Photos and Videos tabs. `only_media` is Mastodon's own
		 * parameter and says what the two have in common; `media_type` is the
		 * Social extension that tells them apart.
		 */
		it.each([
			['image'],
			['video'],
		])('asks for only the %s posts of an account', async (media) => {
			await store.changeTimelineTypeAccount('bob@remote.tld', media)

			await store.fetchTimeline()

			expect(axios.get).toHaveBeenCalledWith(
				`${API}/accounts/bob@remote.tld/statuses`,
				{ params: { limit: 15, only_media: true, media_type: media } },
			)
		})

		/** A tab that asks for everything must not quietly filter. */
		it.each([
			[''],
			['photos'],
			['audio'],
		])('asks for every post of an account for the tab %s', async (media) => {
			await store.changeTimelineTypeAccount('bob@remote.tld', media)

			await store.fetchTimeline()

			expect(axios.get).toHaveBeenCalledWith(
				`${API}/accounts/bob@remote.tld/statuses`,
				{ params: { limit: 15 } },
			)
		})

		it('loads the context of a single post and splits it into parents and replies', async () => {
			const parent = makeStatus('1')
			const reply = makeStatus('3')
			axios.get.mockResolvedValue({ data: { ancestors: [parent], descendants: [reply] } })
			await store.changeTimelineType({ type: 'single-post', params: { id: '2', singlePost: '2' } })

			await store.fetchTimeline()

			expect(axios.get).toHaveBeenCalledWith(`${API}/statuses/2/context`, { params: { limit: 15 } })
			expect(tl().parentsTimeline).toEqual(['1'])
			expect(tl().timeline).toEqual(['3'])
		})

		it('forwards since, max_id and an explicit limit', async () => {
			await store.fetchTimeline({ since: '100', max_id: '50', limit: 30 })

			expect(axios.get).toHaveBeenCalledWith(`${API}/timelines/home`, { params: { since: '100', max_id: '50', limit: 30 } })
		})

		it('drops a page that belongs to a timeline the reader has left', async () => {
			// clicking Global while home's page is in flight used to commit
			// home's posts under the Global heading
			let answerHome
			axios.get.mockReturnValueOnce(new Promise((resolve) => {
				answerHome = resolve
			}))
			await store.changeTimelineType({ type: 'home', params: {} })
			const pending = store.fetchTimeline()

			await store.changeTimelineType({ type: 'federated', params: {} })
			answerHome({ data: statuses })

			await expect(pending).resolves.toEqual([])
			expect(tl().timeline).toEqual([])
			expect(tl().statuses).toEqual({})
		})

		it('does not swallow request errors and leaves the timeline untouched', async () => {
			axios.get.mockRejectedValue(new Error('boom'))

			await expect(store.fetchTimeline()).rejects.toThrow('boom')

			expect(tl().timeline).toEqual([])
			expect(showError).not.toHaveBeenCalled()
		})

		it('refreshTimeline re-fetches the current timeline', async () => {
			await store.changeTimelineType({ type: 'federated', params: {} })

			const result = await store.refreshTimeline()

			expect(axios.get).toHaveBeenCalledWith(`${API}/timelines/public`, { params: { limit: 15 } })
			expect(result).toEqual(statuses)
		})
	})
})
