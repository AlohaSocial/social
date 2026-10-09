/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { showError } from '../../../src/services/toast.js'

import { useAccountStore } from '../../../src/store/account.js'
import { useErrorsStore } from '../../../src/store/errors.js'
import { useTimelineStore } from '../../../src/store/timeline.js'
import logger from '../../../src/services/logger.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

const alice = { id: '11', acct: 'alice', username: 'alice', display_name: 'Alice', url: 'https://cloud.example.org/@alice' }
const bob = { id: '22', acct: 'bob@remote.tld', username: 'bob', display_name: 'Bob', url: 'https://remote.tld/@bob' }
const carol = { id: '33', acct: 'carol@remote.tld', username: 'carol', display_name: 'Carol', url: 'https://remote.tld/@carol' }
const ALICE = 'alice@cloud.example.org'

function freshState() {
	return {
		currentAccountHandle: '',
		credentials: null,
		accounts: {},
		accountsFollowers: {},
		accountsFollowings: {},
		accountsRelationships: {},
		accountIdMap: {},
		accountsFollowersMaxId: {},
		accountsFollowingsMaxId: {},
		accountsFollowersLoading: {},
		accountsFollowingsLoading: {},
		accountsFollowersAllLoaded: {},
		accountsFollowingsAllLoaded: {},
		accountsFollowersFailed: {},
		accountsFollowingsFailed: {},
		accountsFollowersFilling: {},
		accountsFollowingsFilling: {},
	}
}

function defaultRelationship(id, following) {
	return {
		id,
		following,
		// the server sends both on every relationship it answers with, so the
		// placeholder the store invents has to carry them too
		note: '',
		languages: [],
		showing_reblogs: false,
		notifying: false,
		followed_by: false,
		blocking: false,
		blocked_by: false,
		muting: false,
		muting_notifications: false,
		requested: false,
		domain_blocking: false,
		endorsed: false,
	}
}

let store
let errorsStore

beforeEach(() => {
	vi.resetAllMocks()
	vi.spyOn(console, 'debug').mockImplementation(() => {})
	vi.spyOn(console, 'error').mockImplementation(() => {})
	setActivePinia(createPinia())
	store = useAccountStore()
	errorsStore = useErrorsStore()
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('account store state', () => {
	it('starts with empty maps and no current account', () => {
		expect(store.$state).toEqual(freshState())
	})
})

describe('account store mutations and getters', () => {
	it('addAccount indexes a local account by actor url and maps acct@host to it', () => {
		store.addAccount({ actorId: alice.url, data: alice })

		const state = store.$state
		expect(state.accounts[alice.url]).toEqual(alice)
		expect(state.accountIdMap).toEqual({ [ALICE]: alice.url })
		expect(state.accountsFollowers[alice.url]).toEqual([])
		expect(state.accountsFollowings[alice.url]).toEqual([])
	})

	it('addAccount keeps a remote acct as the map key and merges partial updates', () => {
		store.addAccount({ actorId: bob.url, data: bob })
		store.addAccount({ actorId: bob.url, data: { id: '22', acct: bob.acct, url: bob.url, note: '<p>hi</p>' } })

		expect(store.accountIdMap).toEqual({ 'bob@remote.tld': bob.url })
		expect(store.accounts[bob.url]).toEqual({ ...bob, note: '<p>hi</p>' })
	})

	it('addAccount maps a Bluesky account by its bare handle, which is what every lookup asks by', () => {
		const dana = { id: '33', acct: 'dana.bsky.social', username: 'dana.bsky.social', url: 'https://bsky.app/profile/dana.bsky.social', bluesky: { handle: 'dana.bsky.social', did: 'did:plc:d', url: 'https://bsky.app/profile/dana.bsky.social', native: true } }
		store.addAccount({ actorId: dana.url, data: dana })

		expect(store.accountIdMap).toEqual({ 'dana.bsky.social': dana.url })
		expect(store.getAccount('dana.bsky.social')).toEqual(dana)
	})

	it('addAccount without an acct stores the account but adds no handle mapping', () => {
		store.addAccount({ actorId: 'https://x.tld/y', data: { id: '1', url: 'https://x.tld/y' } })

		expect(store.accounts['https://x.tld/y']).toEqual({ id: '1', url: 'https://x.tld/y' })
		expect(store.accountIdMap).toEqual({})
	})

	it('getAccount, getAllAccounts and getActorIdForAccount resolve a handle through the map', () => {
		store.addAccount({ actorId: alice.url, data: alice })

		expect(store.getAccount(ALICE)).toEqual(alice)
		expect(store.getAccount('nobody@remote.tld')).toBeUndefined()
		expect(store.getAllAccounts()).toEqual({ [alice.url]: alice })
		expect(store.getActorIdForAccount(ALICE)).toBe(alice.url)
	})

	it('currentAccount is the account named by setCurrentAccount', () => {
		store.addAccount({ actorId: alice.url, data: alice })
		expect(store.currentAccount).toBeUndefined()

		store.setCurrentAccount(ALICE)

		expect(store.currentAccountHandle).toBe(ALICE)
		expect(store.currentAccount).toEqual(alice)
	})

	it('addRelationship stores a relationship by account id', () => {
		const relationship = { id: '22', following: true, followed_by: false }

		store.addRelationship({ actorId: '22', data: relationship })

		expect(store.getRelationshipWith('22')).toEqual(relationship)
		expect(store.getRelationshipWith('11')).toBeUndefined()
	})

	it('isFollowingUser says yes for the handle of an account the user follows', () => {
		store.addAccount({ actorId: bob.url, data: bob })
		store.addRelationship({ actorId: bob.id, data: { id: bob.id, following: true } })

		expect(store.isFollowingUser(bob.acct)).toBe(true)
	})

	it('isFollowingUser says no for the handle of an account the user does not follow', () => {
		store.addAccount({ actorId: bob.url, data: bob })
		store.addRelationship({ actorId: bob.id, data: { id: bob.id, following: false } })

		expect(store.isFollowingUser(bob.acct)).toBe(false)
	})

	it('isFollowingUser also answers for the plain account id, which the remote follow dialog passes', () => {
		store.addRelationship({ actorId: bob.id, data: { id: bob.id, following: true } })

		expect(store.isFollowingUser(bob.id)).toBe(true)
	})

	it('isFollowingUser says no while the relationship of a known account has not been loaded', () => {
		store.addAccount({ actorId: bob.url, data: bob })

		expect(store.isFollowingUser(bob.acct)).toBe(false)
	})

	it('isFollowingUser says no for a handle that is unknown, instead of failing', () => {
		expect(() => store.isFollowingUser('nobody@remote.tld')).not.toThrow()
		expect(store.isFollowingUser('nobody@remote.tld')).toBe(false)
	})

	it('addFollowers replaces the list, indexes each follower and remembers the last id', () => {
		store.addAccount({ actorId: alice.url, data: alice })

		store.addFollowers({ account: ALICE, data: [bob, carol] })

		expect(store.getAccountFollowers(ALICE)).toEqual([bob, carol])
		expect(store.getAccount('carol@remote.tld')).toEqual(carol)
		expect(store.accountsFollowersMaxId[alice.url]).toBe('33')
		expect(store.accountsFollowersAllLoaded[alice.url]).toBe(false)

		store.addFollowers({ account: ALICE, data: [carol] })
		expect(store.getAccountFollowers(ALICE)).toEqual([carol])
	})

	it('addFollowersAppend extends the list with the next page', () => {
		store.addAccount({ actorId: alice.url, data: alice })
		store.addFollowers({ account: ALICE, data: [bob] })

		store.addFollowersAppend({ account: ALICE, data: [carol] })

		expect(store.getAccountFollowers(ALICE)).toEqual([bob, carol])
		expect(store.accountsFollowersMaxId[alice.url]).toBe('33')
	})

	it('addFollowing and addFollowingAppend maintain the following list the same way', () => {
		store.addAccount({ actorId: alice.url, data: alice })

		store.addFollowing({ account: ALICE, data: [bob] })
		expect(store.getAccountFollowing(ALICE)).toEqual([bob])
		expect(store.accountsFollowingsMaxId[alice.url]).toBe('22')
		expect(store.accountsFollowingsAllLoaded[alice.url]).toBe(false)

		store.addFollowingAppend({ account: ALICE, data: [carol] })
		expect(store.getAccountFollowing(ALICE)).toEqual([bob, carol])
		expect(store.accountsFollowingsMaxId[alice.url]).toBe('33')

		store.addFollowing({ account: ALICE, data: [] })
		expect(store.getAccountFollowing(ALICE)).toEqual([])
	})

	it('follower lists use the raw key for accounts that are not loaded', () => {
		store.addFollowers({ account: 'dave@remote.tld', data: [bob] })

		expect(store.accountsFollowers['dave@remote.tld']).toEqual([bob.url])
		expect(store.getAccountFollowers('dave@remote.tld')).toEqual([bob])
		expect(store.getAccountFollowers('nobody@remote.tld')).toEqual([])
	})
})

describe('account store actions', () => {
	describe('fetchAccountInfo', () => {
		it('GETs the global account info and indexes the result', async () => {
			axios.get.mockResolvedValue({ data: bob })

			const result = await store.fetchAccountInfo(bob.acct)

			expect(axios.get).toHaveBeenCalledWith(`${API}/global/account/info`, { params: { account: 'bob@remote.tld' } })
			expect(result).toEqual(bob)
			expect(store.getAccount(bob.acct)).toEqual(bob)
		})

		// A handle is somebody else's text. Interpolated into the query string
		// it took the request apart: everything after a '&' or a '#' in it
		// became another parameter, or nothing at all.
		it('lets the request builder escape what was typed', async () => {
			axios.get.mockResolvedValue({ data: bob })

			await store.fetchAccountInfo('we&you#me@remote.tld')

			expect(axios.get).toHaveBeenCalledWith(
				`${API}/global/account/info`,
				{ params: { account: 'we&you#me@remote.tld' } },
			)
		})

		it('records an app error instead of throwing when the lookup fails', async () => {
			axios.get.mockRejectedValue(new Error('unreachable'))

			await expect(store.fetchAccountInfo(bob.acct)).resolves.toBeUndefined()

			expect(errorsStore.appErrors).toEqual([expect.objectContaining({
				title: 'Account lookup failed',
				message: 'Could not load account bob@remote.tld. The remote server may be unreachable.',
			})])
			expect(logger.error).toHaveBeenCalledWith('Failed to load account details', { error: expect.any(Error) })
			expect(store.getAccount(bob.acct)).toBeUndefined()
		})
	})

	describe('an account nobody holds', () => {
		const notFound = () => Object.assign(new Error('Request failed with status code 404'), { response: { status: 404 } })

		it('is not an app error on the global lookup', async () => {
			axios.get.mockRejectedValue(notFound())

			await expect(store.fetchAccountInfo('nobody-here')).resolves.toBeUndefined()

			expect(errorsStore.appErrors).toEqual([])
			expect(logger.error).not.toHaveBeenCalled()
			expect(store.getAccount('nobody-here')).toBeUndefined()
		})

		it('is not an app error on the public lookup', async () => {
			axios.get.mockRejectedValue(notFound())

			await expect(store.fetchPublicAccountInfo('nobody-here')).resolves.toBeUndefined()

			expect(errorsStore.appErrors).toEqual([])
		})

		it('leaves a server that failed an app error', async () => {
			axios.get.mockRejectedValue(Object.assign(new Error('bad gateway'), { response: { status: 502 } }))

			await store.fetchAccountInfo(bob.acct)

			expect(errorsStore.appErrors).toEqual([expect.objectContaining({ title: 'Account lookup failed' })])
		})
	})

	describe('fetchPublicAccountInfo', () => {
		it('GETs the public info of a local user and indexes the result', async () => {
			axios.get.mockResolvedValue({ data: alice })

			const result = await store.fetchPublicAccountInfo('alice')

			expect(axios.get).toHaveBeenCalledWith(`${API}/account/alice/info`)
			expect(result).toEqual(alice)
			expect(store.getAccount(ALICE)).toEqual(alice)
		})

		it('escapes a uid before putting it in the path', async () => {
			axios.get.mockResolvedValue({ data: alice })

			await store.fetchPublicAccountInfo('a/b c')

			expect(axios.get).toHaveBeenCalledWith(`${API}/account/a%2Fb%20c/info`)
		})

		it('records an app error naming the uid when the lookup fails', async () => {
			axios.get.mockRejectedValue(new Error('boom'))

			await expect(store.fetchPublicAccountInfo('alice')).resolves.toBeUndefined()

			expect(errorsStore.appErrors).toEqual([expect.objectContaining({
				title: 'Account lookup failed',
				message: 'Could not load account alice. The remote server may be unreachable.',
			})])
		})
	})

	describe('fetchAccountRelationshipInfo', () => {
		it('GETs the relationships for the given ids and stores each one by id', async () => {
			const relationships = [{ id: '11', following: false }, { id: '22', following: true }]
			axios.get.mockResolvedValue({ data: relationships })

			const result = await store.fetchAccountRelationshipInfo(['11', '22'])

			expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/relationships`, { params: { id: ['11', '22'] } })
			expect(result).toEqual(relationships)
			expect(store.getRelationshipWith('11')).toEqual(relationships[0])
			expect(store.getRelationshipWith('22')).toEqual(relationships[1])
		})

		it('shows an error and resolves to undefined when the request fails', async () => {
			axios.get.mockRejectedValue(new Error('boom'))

			await expect(store.fetchAccountRelationshipInfo(['11'])).resolves.toBeUndefined()

			expect(showError).toHaveBeenCalledWith('Could not load the relationship with this account')
			expect(store.accountsRelationships).toEqual({})
		})
	})

	it('fetchCurrentAccountInfo remembers the current account and loads its info', async () => {
		axios.get.mockResolvedValue({ data: alice })

		await store.fetchCurrentAccountInfo(ALICE)
		await flushPromises()

		expect(store.currentAccountHandle).toBe(ALICE)
		expect(axios.get).toHaveBeenCalledWith(`${API}/global/account/info`, { params: { account: ALICE } })
		expect(store.currentAccount).toEqual(alice)
	})

	describe('the account’s own settings', () => {
		const credentials = (privacy = 'private') => ({
			id: '1',
			acct: 'alice',
			username: 'alice',
			display_name: 'Alice',
			url: alice.url,
			locked: false,
			source: { privacy },
		})

		it('reads them off verify_credentials, which is where source lives', async () => {
			axios.get.mockResolvedValue({ data: credentials() })

			await store.fetchCredentials()

			expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/verify_credentials`)
			expect(store.credentials).toEqual(credentials())
		})

		it('files the account where the rest of the app reads it', async () => {
			axios.get.mockResolvedValue({ data: credentials() })

			await store.fetchCredentials()

			expect(store.getAccount(ALICE)).toMatchObject({ display_name: 'Alice' })
		})

		it('says nothing about a default nobody has asked for yet', () => {
			expect(store.defaultPostVisibility).toBe('')
		})

		it.each([
			['private', 'followers'],
			['public', 'public'],
			['unlisted', 'unlisted'],
			['direct', 'direct'],
			// a visibility from a server this app does not know
			['local-only', ''],
		])('reads %s off the wire as %s', (privacy, expected) => {
			store.setCredentials(credentials(privacy))

			expect(store.defaultPostVisibility).toBe(expected)
		})

		it('sends only the fields it was given', async () => {
			axios.patch.mockResolvedValue({ data: credentials('public') })

			await store.updateCredentials({ source: { privacy: 'public' } })

			expect(axios.patch).toHaveBeenCalledWith(
				`${API}/accounts/update_credentials`,
				{ source: { privacy: 'public' } },
			)
			expect(store.defaultPostVisibility).toBe('public')
		})

		it('says so and answers with nothing when the save was refused', async () => {
			axios.patch.mockRejectedValue({ response: { data: { error: 'Your name is set elsewhere' } } })

			await expect(store.updateCredentials({ display_name: 'Alice A.' })).resolves.toBeUndefined()

			expect(showError).toHaveBeenCalledWith('Your name is set elsewhere')
		})

		it('keeps nothing from an answer that is not an account', () => {
			store.setCredentials({})

			expect(store.credentials).toBeNull()
		})
	})

	describe('followAccount', () => {
		const requested = { ...defaultRelationship(bob.id, false), requested: true }

		/**
		 * What the three requests a follow makes answer, in the order the
		 * store sends them: the account, the relationship, the credentials.
		 *
		 * @param {object} relationship what the server says the relationship is now
		 */
		function serverAnswers(relationship = requested) {
			axios.get.mockImplementation(async (url) => {
				if (url.includes('/global/account/info')) {
					return { data: { ...bob, followers_count: 8 } }
				}
				if (url.includes('/accounts/relationships')) {
					return { data: [relationship] }
				}

				return { data: { ...alice, following_count: 4 } }
			})
		}

		beforeEach(() => {
			store.addAccount({ actorId: bob.url, data: bob })
			serverAnswers()
		})

		it('PUTs to the follow endpoint with the encoded handle', async () => {
			const response = { data: { status: 1, result: [] } }
			axios.put.mockResolvedValue(response)

			await expect(store.followAccount({ accountToFollow: bob.acct })).resolves.toBe(response)

			expect(axios.put).toHaveBeenCalledWith(`${API}/current/follow?account=bob%40remote.tld`)
			expect(showError).not.toHaveBeenCalled()
		})

		it('reads the relationship back rather than assuming the follow landed', async () => {
			// `PUT /current/follow` answers success([]) and a follow of a
			// locked or remote account stays pending until the Accept comes
			// back, so writing `following: true` here showed "Following" where
			// the server says "Requested"
			axios.put.mockResolvedValue({ data: { status: 1, result: [] } })

			await store.followAccount({ accountToFollow: bob.acct })

			expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/relationships`, { params: { id: [bob.id] } })
			expect(store.getRelationshipWith(bob.id)).toEqual(requested)
			expect(store.getRelationshipWith(bob.id).following).toBe(false)
		})

		it('reads the counts back, on the target and on the reader', async () => {
			axios.put.mockResolvedValue({ data: { status: 1, result: [] } })

			await store.followAccount({ accountToFollow: bob.acct })

			expect(store.getAccount(bob.acct).followers_count).toBe(8)
			expect(store.credentials.following_count).toBe(4)
		})

		it('leaves the reader following lists of other accounts alone', async () => {
			// the handle used to be pushed into the *followed* account's own
			// following list, keyed by actor URL and holding actor URLs, so it
			// was dropped again on the way out of the getter
			axios.put.mockResolvedValue({ data: { status: 1, result: [] } })

			await store.followAccount({ accountToFollow: bob.acct })

			// the getter filters the handle out again, so the raw list is what
			// shows the push
			expect(store.accountsFollowings[bob.url]).toEqual([])
			expect(store.getAccountFollowing(bob.acct)).toEqual([])
		})

		it('says so, rather than rejecting silently, when the server reports status -1', async () => {
			// `return Promise.reject()` from inside the try resolved after the
			// frame had popped, so this action's own catch never ran: no toast,
			// no log, and an unhandled rejection at the caller
			const response = { data: { status: -1, message: 'nope' } }
			axios.put.mockResolvedValue(response)

			await expect(store.followAccount({ accountToFollow: bob.acct })).resolves.toBeUndefined()

			expect(store.getRelationshipWith(bob.id)).toBeUndefined()
			expect(showError).toHaveBeenCalledWith('Could not follow bob@remote.tld')
			expect(logger.error).toHaveBeenCalledWith('Failed to follow user bob@remote.tld', { error: expect.any(Error) })
		})

		it('shows an error and resolves to undefined on a network failure', async () => {
			axios.put.mockRejectedValue(new Error('boom'))

			await expect(store.followAccount({ accountToFollow: bob.acct })).resolves.toBeUndefined()

			expect(showError).toHaveBeenCalledWith('Could not follow bob@remote.tld')
			expect(logger.error).toHaveBeenCalledWith('Failed to follow user bob@remote.tld', { error: expect.any(Error) })
			expect(store.getRelationshipWith(bob.id)).toBeUndefined()
		})

		it('says why when the account has blocked the person', async () => {
			axios.put.mockRejectedValue({ response: { status: 403, data: { status: -1, error: 'This account has blocked you', blocked_by: true } } })

			await expect(store.followAccount({ accountToFollow: bob.acct })).resolves.toBeUndefined()

			expect(showError).toHaveBeenCalledWith('This account has blocked you')
			expect(store.getRelationshipWith(bob.id)).toBeUndefined()
		})

		it('asks about an account it has never loaded once the info comes back', async () => {
			store.$patch(freshState())
			axios.put.mockResolvedValue({ data: { status: 1, result: [] } })

			await store.followAccount({ accountToFollow: bob.acct })

			expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/relationships`, { params: { id: [bob.id] } })
		})
	})

	describe('unfollowAccount', () => {
		const gone = defaultRelationship(bob.id, false)

		beforeEach(() => {
			store.addAccount({ actorId: bob.url, data: bob })
			store.addRelationship({ actorId: bob.id, data: { ...defaultRelationship(bob.id, true), requested: true } })
			axios.get.mockImplementation(async (url) => {
				if (url.includes('/global/account/info')) {
					return { data: bob }
				}
				if (url.includes('/accounts/relationships')) {
					return { data: [gone] }
				}

				return { data: alice }
			})
		})

		it('DELETEs the follow and reads the relationship back', async () => {
			const response = { data: { status: 1, result: [] } }
			axios.delete.mockResolvedValue(response)

			await expect(store.unfollowAccount({ accountToUnfollow: bob.acct })).resolves.toBe(response)

			expect(axios.delete).toHaveBeenCalledWith(`${API}/current/follow?account=bob%40remote.tld`)
			// `requested` was left standing when only `following` was flipped,
			// so cancelling a pending request still said "Requested"
			expect(store.getRelationshipWith(bob.id)).toEqual(gone)
		})

		it('says so, rather than rejecting silently, when the server reports status -1', async () => {
			const response = { data: { status: -1, message: 'nope' } }
			axios.delete.mockResolvedValue(response)

			await expect(store.unfollowAccount({ accountToUnfollow: bob.acct })).resolves.toBeInstanceOf(Error)

			expect(store.getRelationshipWith(bob.id)).toMatchObject({ following: true })
			expect(showError).toHaveBeenCalledWith('Could not unfollow bob@remote.tld')
		})

		it('shows an error and resolves with the error on a network failure', async () => {
			const error = new Error('boom')
			axios.delete.mockRejectedValue(error)

			await expect(store.unfollowAccount({ accountToUnfollow: bob.acct })).resolves.toBe(error)

			expect(showError).toHaveBeenCalledWith('Could not unfollow bob@remote.tld')
			expect(store.getRelationshipWith(bob.id)).toMatchObject({ following: true })
		})
	})

	describe('blockAccount, unblockAccount, muteAccount and unmuteAccount', () => {
		// These actions also purge the timeline, so they need the timeline module.
		let timelineStore
		const statusBy = (id, author) => ({
			id,
			content: `<p>post ${id}</p>`,
			created_at: '2026-01-01T10:00:00.000Z',
			account: { id: author.id, acct: author.acct },
		})

		beforeEach(() => {
			timelineStore = useTimelineStore()
			store.addAccount({ actorId: bob.url, data: bob })
			timelineStore.addToTimeline([statusBy('1', bob), statusBy('2', carol)])
		})

		it('blockAccount POSTs to the block endpoint, stores the returned relationship and purges the actor\'s posts', async () => {
			const relationship = { ...defaultRelationship(bob.id, false), blocking: true }
			axios.post.mockResolvedValue({ data: relationship })

			await expect(store.blockAccount({ id: bob.id })).resolves.toEqual(relationship)

			expect(axios.post).toHaveBeenCalledWith(`${API}/accounts/${bob.id}/block`)
			expect(store.getRelationshipWith(bob.id)).toEqual(relationship)
			expect(timelineStore.timeline).toEqual(['2'])
			expect(timelineStore.statuses['1']).toBeUndefined()
			expect(showError).not.toHaveBeenCalled()
		})

		it('unblockAccount POSTs to the unblock endpoint and updates the relationship without purging', async () => {
			store.addRelationship({ actorId: bob.id, data: { ...defaultRelationship(bob.id, false), blocking: true } })
			const relationship = defaultRelationship(bob.id, false)
			axios.post.mockResolvedValue({ data: relationship })

			await expect(store.unblockAccount({ id: bob.id })).resolves.toEqual(relationship)

			expect(axios.post).toHaveBeenCalledWith(`${API}/accounts/${bob.id}/unblock`)
			expect(store.getRelationshipWith(bob.id)).toEqual(relationship)
			expect(timelineStore.timeline).toEqual(['1', '2'])
		})

		it('muteAccount mutes for good and hides the notifications when it is asked for nothing else, and purges', async () => {
			const relationship = { ...defaultRelationship(bob.id, true), muting: true, muting_notifications: true }
			axios.post.mockResolvedValue({ data: relationship })

			await expect(store.muteAccount({ id: bob.id })).resolves.toEqual(relationship)

			expect(axios.post).toHaveBeenCalledTimes(1)
			// said in full rather than left to the server's defaults
			expect(axios.post).toHaveBeenCalledWith(
				`${API}/accounts/${bob.id}/mute`,
				{ notifications: true, duration: 0 },
			)
			expect(store.getRelationshipWith(bob.id)).toEqual(relationship)
			expect(timelineStore.timeline).toEqual(['2'])
		})

		it('muteAccount sends the duration and the notification choice it was given', async () => {
			axios.post.mockResolvedValue({ data: { ...defaultRelationship(bob.id, true), muting: true } })

			await store.muteAccount({ id: bob.id, notifications: false, duration: 3600 })

			expect(axios.post).toHaveBeenCalledWith(
				`${API}/accounts/${bob.id}/mute`,
				{ notifications: false, duration: 3600 },
			)
		})

		it('unmuteAccount POSTs to the unmute endpoint and updates the relationship without purging', async () => {
			const relationship = defaultRelationship(bob.id, true)
			axios.post.mockResolvedValue({ data: relationship })

			await expect(store.unmuteAccount({ id: bob.id })).resolves.toEqual(relationship)

			expect(axios.post).toHaveBeenCalledWith(`${API}/accounts/${bob.id}/unmute`)
			expect(store.getRelationshipWith(bob.id)).toEqual(relationship)
			expect(timelineStore.timeline).toEqual(['1', '2'])
		})

		it('stores nothing when the backend answers without a relationship entity', async () => {
			// relationshipAction returns [] when it cannot find the relationship
			axios.post.mockResolvedValue({ data: [] })

			await expect(store.blockAccount({ id: bob.id })).resolves.toEqual([])

			expect(store.accountsRelationships).toEqual({})
			expect(timelineStore.timeline).toEqual(['1', '2'])
		})

		it.each([
			['blockAccount', 'Failed to block the account'],
			['unblockAccount', 'Failed to unblock the account'],
			['muteAccount', 'Failed to mute the account'],
			['unmuteAccount', 'Failed to unmute the account'],
		])('%s shows an error and changes nothing when the request fails', async (action, message) => {
			axios.post.mockRejectedValue(new Error('boom'))

			await expect(store[action]({ id: bob.id })).resolves.toBeUndefined()

			expect(showError).toHaveBeenCalledWith(message)
			expect(logger.error).toHaveBeenCalledWith(message, { error: expect.any(Error) })
			expect(store.accountsRelationships).toEqual({})
			expect(timelineStore.timeline).toEqual(['1', '2'])
		})
	})

	describe('fetchAccountFollowers', () => {
		beforeEach(() => {
			store.addAccount({ actorId: alice.url, data: alice })
		})

		it('loads the first page, marks a short page as complete and clears the loading flag', async () => {
			let loadingDuringRequest
			axios.get.mockImplementation(async () => {
				loadingDuringRequest = store.accountsFollowersLoading[alice.url]
				return { data: [bob, carol] }
			})

			const result = await store.fetchAccountFollowers({ account: ALICE })

			expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/${ALICE}/followers`, { params: { limit: 20 } })
			expect(result).toEqual([bob, carol])
			expect(loadingDuringRequest).toBe(true)
			expect(store.getAccountFollowers(ALICE)).toEqual([bob, carol])
			expect(store.accountsFollowersAllLoaded[alice.url]).toBe(true)
			expect(store.accountsFollowersLoading[alice.url]).toBe(false)
		})

		it('passes max_id for the next page, appends it and keeps paging while pages are full', async () => {
			store.addFollowers({ account: ALICE, data: [bob] })
			const page = Array.from({ length: 20 }, (_, i) => ({
				id: String(100 + i),
				acct: `u${i}@remote.tld`,
				url: `https://remote.tld/@u${i}`,
			}))
			axios.get.mockResolvedValue({ data: page })

			await store.fetchAccountFollowers({ account: ALICE, maxId: '22' })

			expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/${ALICE}/followers`, { params: { limit: 20, max_id: '22' } })
			expect(store.getAccountFollowers(ALICE)).toHaveLength(21)
			expect(store.accountsFollowersMaxId[alice.url]).toBe('119')
			expect(store.accountsFollowersAllLoaded[alice.url]).toBe(false)
		})

		// #2330: the list was short on an earlier visit and the account has
		// gained followers since. The refreshed first page is full, so there
		// is more to page through and `allLoaded` must not still say there is
		// not.
		it('stops saying the list is complete when a refreshed first page is full', async () => {
			axios.get.mockResolvedValue({ data: [bob, carol] })
			await store.fetchAccountFollowers({ account: ALICE })
			expect(store.accountsFollowersAllLoaded[alice.url]).toBe(true)

			const full = Array.from({ length: 20 }, (unused, i) => ({
				id: String(200 + i),
				acct: `v${i}@remote.tld`,
				url: `https://remote.tld/@v${i}`,
			}))
			axios.get.mockResolvedValue({ data: full })
			await store.fetchAccountFollowers({ account: ALICE })

			expect(store.accountsFollowersAllLoaded[alice.url]).toBe(false)
			expect(store.accountsFollowersMaxId[alice.url]).toBe('219')

			// and the next page can actually be asked for
			axios.get.mockClear()
			axios.get.mockResolvedValue({ data: [bob] })
			await store.fetchAccountFollowers({ account: ALICE, maxId: store.accountsFollowersMaxId[alice.url] })
			expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/${ALICE}/followers`, { params: { limit: 20, max_id: '219' } })
		})

		it('remembers whether the server is reading more of the list from elsewhere', async () => {
			axios.get.mockResolvedValue({ data: [bob], headers: { 'x-social-filling': '1' } })
			await store.fetchAccountFollowers({ account: ALICE })
			expect(store.accountsFollowersFilling[alice.url]).toBe(true)

			axios.get.mockResolvedValue({ data: [bob, carol], headers: {} })
			await store.fetchAccountFollowers({ account: ALICE })
			expect(store.accountsFollowersFilling[alice.url]).toBe(false)

			axios.get.mockResolvedValue({ data: [carol], headers: { 'x-social-filling': '1' } })
			await store.fetchAccountFollowing({ account: ALICE })
			expect(store.accountsFollowingsFilling[alice.url]).toBe(true)
		})

		it('does not start a second request while one is running', async () => {
			store.setFollowersLoading({ actorId: alice.url, loading: true })

			await expect(store.fetchAccountFollowers({ account: ALICE })).resolves.toBeUndefined()

			expect(axios.get).not.toHaveBeenCalled()
		})

		it('shows an error and clears the loading flag when the request fails', async () => {
			axios.get.mockRejectedValue(new Error('boom'))

			await expect(store.fetchAccountFollowers({ account: ALICE })).resolves.toBeUndefined()

			expect(showError).toHaveBeenCalledWith('Could not load the list of followers')
			expect(store.accountsFollowersLoading[alice.url]).toBe(false)
			expect(store.getAccountFollowers(ALICE)).toEqual([])
		})
	})

	describe('fetchAccountFollowing', () => {
		beforeEach(() => {
			store.addAccount({ actorId: alice.url, data: alice })
		})

		it('loads the first page into the following list and marks a short page as complete', async () => {
			axios.get.mockResolvedValue({ data: [bob] })

			const result = await store.fetchAccountFollowing({ account: ALICE })

			expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/${ALICE}/following`, { params: { limit: 20 } })
			expect(result).toEqual([bob])
			expect(store.getAccountFollowing(ALICE)).toEqual([bob])
			expect(store.accountsFollowingsAllLoaded[alice.url]).toBe(true)
			expect(store.accountsFollowingsLoading[alice.url]).toBe(false)
		})

		// the same guarantee as the followers list above, and worth pinning
		// twice: the reset lives in `addFollowing`, not in the fetch, so
		// nothing beside the fetch says it has to happen
		it('stops saying the list is complete when a refreshed first page is full', async () => {
			axios.get.mockResolvedValue({ data: [bob] })
			await store.fetchAccountFollowing({ account: ALICE })
			expect(store.accountsFollowingsAllLoaded[alice.url]).toBe(true)

			const full = Array.from({ length: 20 }, (unused, i) => ({
				id: String(300 + i),
				acct: `w${i}@remote.tld`,
				url: `https://remote.tld/@w${i}`,
			}))
			axios.get.mockResolvedValue({ data: full })
			await store.fetchAccountFollowing({ account: ALICE })

			expect(store.accountsFollowingsAllLoaded[alice.url]).toBe(false)
			expect(store.accountsFollowingsMaxId[alice.url]).toBe('319')
		})
		it('appends the next page when max_id is given', async () => {
			store.addFollowing({ account: ALICE, data: [bob] })
			axios.get.mockResolvedValue({ data: [carol] })

			await store.fetchAccountFollowing({ account: ALICE, maxId: '22' })

			expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/${ALICE}/following`, { params: { limit: 20, max_id: '22' } })
			expect(store.getAccountFollowing(ALICE)).toEqual([bob, carol])
		})

		it('skips the request while one is running and reports failures', async () => {
			store.setFollowingsLoading({ actorId: alice.url, loading: true })
			await store.fetchAccountFollowing({ account: ALICE })
			expect(axios.get).not.toHaveBeenCalled()

			store.setFollowingsLoading({ actorId: alice.url, loading: false })
			axios.get.mockRejectedValue(new Error('boom'))
			await store.fetchAccountFollowing({ account: ALICE })

			expect(showError).toHaveBeenCalledWith('Could not load the list of followed accounts')
			expect(store.accountsFollowingsLoading[alice.url]).toBe(false)
		})
	})

	/**
	 * Following is two round trips — the follow, then the relationship and the
	 * credentials it refreshes — and the button said "Follow" for both of
	 * them. On a fediverse round trip that is a second of a button that looks
	 * broken, and what people do with those is press them again.
	 */
	describe('the relationship a button draws while the server is thinking', () => {
		/**
		 * @param {object} store the account store
		 */
		function knownBob(store) {
			store.addAccount({ actorId: 'https://remote.example/users/bob', data: { id: '42', acct: 'bob@remote.example', url: 'https://remote.example/users/bob' } })
			store.addRelationship({ actorId: '42', data: { id: '42', following: false } })
		}

		it('shows the follow before the server has confirmed it', async () => {
			const store = useAccountStore()
			knownBob(store)
			let resolve
			axios.put.mockReturnValue(new Promise((r) => {
				resolve = r
			}))

			const pending = store.followAccount({ accountToFollow: 'https://remote.example/users/bob' })
			expect(store.accountsRelationships['42'].following).toBe(true)

			resolve({ data: { status: 1 } })
			await pending
		})

		it('puts it back when the server refuses', async () => {
			const store = useAccountStore()
			knownBob(store)
			axios.put.mockResolvedValue({ data: { status: -1 } })

			await store.followAccount({ accountToFollow: 'https://remote.example/users/bob' })

			expect(store.accountsRelationships['42'].following).toBe(false)
		})

		it('does the same for unfollowing', async () => {
			const store = useAccountStore()
			knownBob(store)
			store.addRelationship({ actorId: '42', data: { id: '42', following: true } })
			axios.delete.mockRejectedValue(new Error('gone'))

			await store.unfollowAccount({ accountToUnfollow: 'https://remote.example/users/bob' })

			expect(store.accountsRelationships['42'].following).toBe(true)
		})

		/**
		 * A stranger has no relationship row yet, and inventing one would draw
		 * a button state the server never confirmed.
		 */
		it('invents nothing for an account it has never heard of', async () => {
			const store = useAccountStore()
			axios.put.mockResolvedValue({ data: { status: 1 } })

			await store.followAccount({ accountToFollow: 'nobody@elsewhere.example' })

			expect(store.accountsRelationships['nobody@elsewhere.example']).toBeUndefined()
		})
	})
})
