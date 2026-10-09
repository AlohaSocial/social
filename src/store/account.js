/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { showError } from '../services/toast.js'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

import logger from '../services/logger.js'
import { useErrorsStore } from './errors.js'
import { useTimelineStore } from './timeline.js'
import { isBlueskyAccount } from '../utils/accountLocality.js'
import { isBlockedBy } from '../utils/blockedBy.js'

/**
 * How many followers or followed accounts one page holds.
 *
 * Asked for rather than assumed: the end of the list is read off the size of
 * the answer, which held only because the controller happened to default to
 * the same number.
 */
const FOLLOW_PAGE_SIZE = 20

/**
 * What this store holds, for the helpers and getters that are handed it.
 *
 * Written down because they are plain functions taking the state as an
 * argument: without it every one read `state.accounts` off an untyped
 * `object`, which TypeScript 7 no longer lets through.
 *
 * @typedef {object} AccountState
 * @property {string} currentAccountHandle the handle the reader is signed in as
 * @property {import('../types/Mastodon.js').Account & {source?: {privacy?: string, sensitive?: boolean, language?: string, note?: string, fields?: object[]}}|null} credentials the reader's own CredentialAccount
 * @property {Record<string, import('../types/Mastodon.js').Account>} accounts every account seen, by actor url
 * @property {Record<string, string[]>} accountsFollowers the actor urls of the followers loaded per account
 * @property {Record<string, string[]>} accountsFollowings the actor urls of the followed accounts loaded per account
 * @property {Record<string, import('../types/Mastodon.js').Relationship>} accountsRelationships the viewer's relationship per account id
 * @property {Record<string, string>} accountIdMap numeric account id -> actor url
 * @property {Record<string, string>} accountsFollowersMaxId where each followers page ended
 * @property {Record<string, string>} accountsFollowingsMaxId where each following page ended
 * @property {Record<string, boolean>} accountsFollowersLoading whether a followers page is in flight
 * @property {Record<string, boolean>} accountsFollowingsLoading whether a following page is in flight
 * @property {Record<string, boolean>} accountsFollowersAllLoaded whether the followers list is complete
 * @property {Record<string, boolean>} accountsFollowingsAllLoaded whether the following list is complete
 * @property {Record<string, boolean>} accountsFollowersFailed whether the followers list failed to load
 * @property {Record<string, boolean>} accountsFollowingsFailed whether the following list failed to load
 * @property {Record<string, boolean>} accountsFollowersFilling whether more followers are being read from where the account lives
 * @property {Record<string, boolean>} accountsFollowingsFilling whether more followed accounts are being read from where the account lives
 */

/**
 * The actor URL an account handle resolves to, or undefined.
 *
 * This used to read a module-level `state` object rather than the store's own,
 * which worked only because there happened to be exactly one store.
 *
 * @param {AccountState} state the store state
 * @param {string} account a handle, or an actor URL
 * @return {string|undefined}
 */
function actorIdFor(state, account) {
	return state.accountIdMap[account]
}

/**
 * @param {AccountState} state the store state
 * @param {string} account a handle, or an actor URL
 * @return {string} what the follower and following lists are keyed by
 */
function keyFor(state, account) {
	return actorIdFor(state, account) || account
}

/**
 * A visibility as Mastodon's `source.privacy` spells it, as this app's composer
 * spells it. The two agree except on `private`, which the composer and the
 * server's `CLIENT_VISIBILITIES` both know as `followers`.
 *
 * @param {unknown} privacy what the server said
 * @return {string} a composer visibility id, or '' for anything unknown
 */
function visibilityFromWire(privacy) {
	if (typeof privacy !== 'string') {
		return ''
	}
	const id = privacy === 'private' ? 'followers' : privacy

	return ['public', 'unlisted', 'followers', 'direct'].includes(id) ? id : ''
}

/**
 * Files an account under its actor URL, and remembers which handle names it.
 *
 * @param {AccountState} state the store state
 * @param {object} payload the account
 * @param {string} payload.actorId its actor URL
 * @param {import('../types/Mastodon.js').Account} payload.data the account itself
 */
function indexAccount(state, { actorId, data }) {
	state.accounts = { ...state.accounts, [actorId]: { ...state.accounts[actorId], ...data } }
	// The follower and following lists used to be reset to [] here, so any
	// refresh of the account — saving the profile fields, uploading a banner,
	// a search result arriving — emptied an open followers list under the
	// reader. They are seeded by addFollowers/addFollowing, which is the only
	// thing that knows whether they have been loaded at all.
	if (state.accountsFollowers[actorId] === undefined) {
		state.accountsFollowers = { ...state.accountsFollowers, [actorId]: [] }
	}
	if (state.accountsFollowings[actorId] === undefined) {
		state.accountsFollowings = { ...state.accountsFollowings, [actorId]: [] }
	}
	if (!data.acct) {
		return
	}
	// a Bluesky handle is whole without a host, and is what every lookup asks by
	const accountId = (data.acct.indexOf('@') === -1 && !isBlueskyAccount(data)) ? data.acct + '@' + new URL(data.url).hostname : data.acct
	state.accountIdMap = { ...state.accountIdMap, [accountId]: data.url }
}

/**
 * Collects a page of actors into one of the two lists.
 *
 * @param {AccountState} state the store state
 * @param {import('../types/Mastodon.js').Account[]} data the page
 * @return {{users: string[], lastId: string}} the actor URLs and the last id seen
 */
function collectActors(state, data) {
	const users = []
	// a string, like every id on the wire: `Relationship::jsonSerialize()` casts
	// it, and the only reader falls back to 0 for an empty page either way
	let lastId = ''
	for (const actor of data) {
		users.push(actor.url)
		indexAccount(state, { actorId: actor.url, data: actor })
		lastId = actor.id
	}

	return { users, lastId }
}

/**
 * Whether a lookup failed because nobody holds that handle. That is an answer
 * rather than a failure: the page that asked says "User not found" itself, and
 * an app error blaming a remote server would be wrong on both counts.
 *
 * @param {{response?: {status?: number}}} error what axios threw
 * @return {boolean}
 */
function isNoSuchAccount(error) {
	return error?.response?.status === 404
}

/**
 * How long to let relationship requests pile up before sending them as one.
 * Long enough for a page of UserEntry components to mount, short enough that
 * the follow buttons do not visibly lag.
 */
const RELATIONSHIP_BATCH_MS = 30

let pendingRelationshipIds = new Set()
let pendingRelationshipBatch = null

/**
 * Every account the page has heard of, and what the reader's relationship with
 * each of them is.
 */
export const useAccountStore = defineStore('account', {
	state: () => ({
		/** the handle of the account the reader is signed in as */
		currentAccountHandle: '',
		/**
		 * The reader's own CredentialAccount — `verify_credentials`' answer,
		 * the Account entity with `source` on it — or null before it came.
		 * The seeded `currentAccount` is the plain Account and has no
		 * `source`, so the default audience lives here and nowhere else.
		 *
		 * @type {import('../types/Mastodon.js').Account & {source?: object}|null}
		 */
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
		/**
		 * Which of these lists failed to load, keyed the same way. A list that
		 * could not be fetched is not a list with nobody in it, and the page
		 * said "No followers yet" for both.
		 */
		accountsFollowersFailed: {},
		accountsFollowingsFailed: {},
		/**
		 * Whether the server said, on the last page of a list, that it is
		 * reading more of it from where the account lives just now
		 * (`X-Social-Filling`), keyed the same way.
		 */
		accountsFollowersFilling: {},
		accountsFollowingsFilling: {},
	}),

	getters: {
		/**
		 * @param {AccountState} state the store state
		 * @return {() => Record<string, import('../types/Mastodon.js').Account>} every account, keyed by actor URL
		 */
		getAllAccounts(state) {
			return () => {
				return state.accounts
			}
		},
		/**
		 * The account a handle names, or undefined while it is still unknown —
		 * which is also the only thing the old `accountLoaded` getter answered,
		 * so there is one query here rather than two.
		 *
		 * @param {AccountState} state the store state
		 * @return {(account: string) => import('../types/Mastodon.js').Account|undefined} handle -> account
		 */
		getAccount(state) {
			return (account) => {
				return state.accounts[actorIdFor(state, account)]
			}
		},
		/**
		 * @param {AccountState} state the store state
		 * @return {(accountId: string) => import('../types/Mastodon.js').Relationship|undefined} numeric account id -> relationship
		 */
		getRelationshipWith(state) {
			return (accountId) => {
				return state.accountsRelationships[accountId]
			}
		},
		/**
		 * @return {import('../types/Mastodon.js').Account|undefined} the signed-in account
		 */
		currentAccount() {
			return this.getAccount(this.currentAccountHandle)
		},
		/**
		 * The audience the reader's posts go out with when nothing else names
		 * one, as the composer spells it. The wire says `private` where this
		 * app says `followers`; an account that has not been asked about yet,
		 * or a value this app does not know, is '' so the caller falls through
		 * to its next choice.
		 *
		 * @param {AccountState} state the store state
		 * @return {string} one of the composer's visibility ids, or ''
		 */
		defaultPostVisibility(state) {
			return visibilityFromWire(state.credentials?.source?.privacy)
		},
		/**
		 * @param {AccountState} state the store state
		 * @return {(id: string) => import('../types/Mastodon.js').Account[]} handle -> the accounts following it
		 */
		getAccountFollowers(state) {
			return (id) => (state.accountsFollowers[keyFor(state, id)] || []).map((actorId) => state.accounts[actorId]).filter(Boolean)
		},
		/**
		 * @param {AccountState} state the store state
		 * @return {(id: string) => import('../types/Mastodon.js').Account[]} handle -> the accounts it follows
		 */
		getAccountFollowing(state) {
			return (id) => (state.accountsFollowings[keyFor(state, id)] || []).map((actorId) => state.accounts[actorId]).filter(Boolean)
		},
		/**
		 * @param {AccountState} state the store state
		 * @return {(account: string) => string|undefined} handle -> actor URL
		 */
		getActorIdForAccount(state) {
			return (account) => actorIdFor(state, account)
		},
		/**
		 * @param {AccountState} state the store state
		 * @return {(followingAccount: string) => boolean} handle or numeric id -> whether the reader follows it
		 */
		isFollowingUser(state) {
			return (followingAccount) => {
				// Relationships are keyed by the Mastodon numeric id (see addRelationship).
				// Callers pass either a handle, which resolves through the actor URL, or that
				// id directly.
				const actorId = actorIdFor(state, followingAccount)
				const relationshipId = (actorId && state.accounts[actorId]?.id) || followingAccount

				return state.accountsRelationships[relationshipId]?.following || false
			}
		},
	},

	actions: {
		setCurrentAccount(account) {
			this.currentAccountHandle = account
		},
		addAccount({ actorId, data }) {
			indexAccount(this, { actorId, data })
		},
		addRelationship({ actorId, data }) {
			this.accountsRelationships = { ...this.accountsRelationships, [actorId]: data }
		},
		setFollowersLoading({ actorId, loading }) {
			this.accountsFollowersLoading = { ...this.accountsFollowersLoading, [actorId]: loading }
		},
		setFollowingsLoading({ actorId, loading }) {
			this.accountsFollowingsLoading = { ...this.accountsFollowingsLoading, [actorId]: loading }
		},
		setFollowersFailed({ actorId, failed }) {
			this.accountsFollowersFailed = { ...this.accountsFollowersFailed, [actorId]: failed }
		},
		setFollowingsFailed({ actorId, failed }) {
			this.accountsFollowingsFailed = { ...this.accountsFollowingsFailed, [actorId]: failed }
		},
		setFollowersAllLoaded({ actorId, loaded }) {
			this.accountsFollowersAllLoaded = { ...this.accountsFollowersAllLoaded, [actorId]: loaded }
		},
		setFollowingsAllLoaded({ actorId, loaded }) {
			this.accountsFollowingsAllLoaded = { ...this.accountsFollowingsAllLoaded, [actorId]: loaded }
		},
		/**
		 * Replaces page one. `allLoaded` is reset here, and this is the only
		 * place it is: the fetch above only ever sets it *true*, when a page
		 * comes back short. An account whose list was short on an earlier
		 * visit and has grown since would otherwise keep that `true` through
		 * the refresh, and the view stops paging the moment it reads it — so
		 * only the first page would be reachable until the page was reloaded.
		 * `addFollowersAppend` deliberately does not reset it: a later page is
		 * not a new list.
		 *
		 * @param {object} page one page of the list
		 * @param {string} page.account whose list it is
		 * @param {object[]} page.data the accounts on the page
		 */
		addFollowers({ account, data }) {
			const key = keyFor(this, account)
			const { users, lastId } = collectActors(this, data)
			this.accountsFollowers = { ...this.accountsFollowers, [key]: users }
			this.accountsFollowersMaxId = { ...this.accountsFollowersMaxId, [key]: lastId }
			this.accountsFollowersAllLoaded = { ...this.accountsFollowersAllLoaded, [key]: false }
		},
		addFollowersAppend({ account, data }) {
			const key = keyFor(this, account)
			const existing = [...(this.accountsFollowers[key] || [])]
			const { users, lastId } = collectActors(this, data)
			this.accountsFollowers = { ...this.accountsFollowers, [key]: [...existing, ...users] }
			this.accountsFollowersMaxId = { ...this.accountsFollowersMaxId, [key]: lastId }
		},
		/**
		 * Replaces page one; resets `allLoaded` for the reason above.
		 *
		 * @param {object} page one page of the list
		 * @param {string} page.account whose list it is
		 * @param {object[]} page.data the accounts on the page
		 */
		addFollowing({ account, data }) {
			const key = keyFor(this, account)
			const { users, lastId } = collectActors(this, data)
			this.accountsFollowings = { ...this.accountsFollowings, [key]: users }
			this.accountsFollowingsMaxId = { ...this.accountsFollowingsMaxId, [key]: lastId }
			this.accountsFollowingsAllLoaded = { ...this.accountsFollowingsAllLoaded, [key]: false }
		},
		addFollowingAppend({ account, data }) {
			const key = keyFor(this, account)
			const existing = [...(this.accountsFollowings[key] || [])]
			const { users, lastId } = collectActors(this, data)
			this.accountsFollowings = { ...this.accountsFollowings, [key]: [...existing, ...users] }
			this.accountsFollowingsMaxId = { ...this.accountsFollowingsMaxId, [key]: lastId }
		},
		async fetchAccountInfo(account) {
			try {
				const response = await axios.get(
					generateUrl('apps/social/api/v1/global/account/info'),
					{ params: { account } },
				)
				this.addAccount({ actorId: response.data.url, data: response.data })
				return response.data
			} catch (error) {
				if (isNoSuchAccount(error)) {
					return undefined
				}
				// the account handle is somebody's identity: it belongs in the
				// app log, not in every reader's browser console
				logger.error('Failed to load account details', { error })
				useErrorsStore().addAppError({
					title: t('social', 'Account lookup failed'),
					message: t('social', 'Could not load account {account}. The remote server may be unreachable.', { account }),
				})
			}
		},
		async fetchAccountRelationshipInfo(ids) {
			const wanted = (Array.isArray(ids) ? ids : [ids]).filter((id) => id !== undefined && id !== null)
			if (wanted.length === 0) {
				return []
			}

			try {
				logger.debug('Loading relationships', { count: wanted.length })
				const response = await axios.get(generateUrl('apps/social/api/v1/accounts/relationships'), { params: { id: wanted } })
				response.data.forEach((account) => {
					this.addRelationship({ actorId: account.id, data: account })
				})
				return response.data
			} catch (error) {
				logger.error('Failed to load relationship info', { error })
				showError(t('social', 'Could not load the relationship with this account'))
			}
		},
		/**
		 * Asks for one account's relationship, together with everybody else who
		 * asked in the same moment.
		 *
		 * Twenty followers on a page used to be twenty round-trips, because each
		 * UserEntry dispatched its own single-id request on mount — and the guard
		 * that was supposed to prevent that could never hold. The endpoint takes
		 * an array, so the ids are collected and sent once.
		 *
		 * @param {string} id the account id to ask about
		 * @return {Promise<object[]>} the relationships in the batch this joined
		 */
		fetchRelationship(id) {
			if (id === undefined || id === null) {
				return Promise.resolve([])
			}

			if (this.getRelationshipWith(id) !== undefined) {
				return Promise.resolve([])
			}

			pendingRelationshipIds.add(id)
			if (pendingRelationshipBatch === null) {
				pendingRelationshipBatch = new Promise((resolve) => {
					window.setTimeout(() => {
						const ids = [...pendingRelationshipIds]
						pendingRelationshipIds = new Set()
						pendingRelationshipBatch = null
						resolve(this.fetchAccountRelationshipInfo(ids))
					}, RELATIONSHIP_BATCH_MS)
				})
			}

			return pendingRelationshipBatch
		},
		async fetchPublicAccountInfo(uid) {
			try {
				const response = await axios.get(generateUrl(`apps/social/api/v1/account/${encodeURIComponent(uid)}/info`))
				this.addAccount({ actorId: response.data.url, data: response.data })
				return response.data
			} catch (error) {
				if (isNoSuchAccount(error)) {
					return undefined
				}
				logger.error('Failed to load public account details', { error })
				useErrorsStore().addAppError({
					title: t('social', 'Account lookup failed'),
					message: t('social', 'Could not load account {account}. The remote server may be unreachable.', { account: uid }),
				})
			}
		},
		fetchCurrentAccountInfo(account) {
			this.setCurrentAccount(account)
			this.fetchAccountInfo(account)
			// the settings on the account — the default audience among them —
			// are only on the credentials route, and the composer wants them
			// before the reader opens it rather than after
			this.fetchCredentials()
		},
		/**
		 * The reader's own account with its settings on it. Nothing waits for
		 * this: the composer reads the default audience off the store when it
		 * opens and follows it when it arrives, and the settings page shows
		 * its fields when they come.
		 *
		 * @return {Promise<object|undefined>} the CredentialAccount, or undefined when it could not be read
		 */
		async fetchCredentials() {
			try {
				const response = await axios.get(generateUrl('apps/social/api/v1/accounts/verify_credentials'))
				this.setCredentials(response.data)
				return response.data
			} catch (error) {
				logger.error('Failed to load the account settings', { error })
			}
		},
		/**
		 * Writes what a settings form changed, and keeps what the server
		 * answered with: it is the CredentialAccount as it now stands, so the
		 * form, the composer's default audience and the profile all see the
		 * change at once.
		 *
		 * Only what is passed is sent — `update_credentials` writes only the
		 * fields it was given — so a caller changing one thing leaves the rest
		 * alone. Failure is said out loud here and answered with undefined,
		 * the way the other account actions do it.
		 *
		 * @param {object} changes the fields as `update_credentials` names them
		 * @return {Promise<object|undefined>} the updated CredentialAccount, or undefined
		 */
		async updateCredentials(changes) {
			try {
				const response = await axios.patch(generateUrl('apps/social/api/v1/accounts/update_credentials'), changes)
				this.setCredentials(response.data)
				return response.data
			} catch (error) {
				logger.error('Failed to save the account settings', { error })
				showError(error?.response?.data?.error || t('social', 'Could not save your account settings'))
			}
		},
		/**
		 * @param {import('../types/Mastodon.js').Account & {source?: {privacy?: string, sensitive?: boolean, language?: string, note?: string, fields?: object[]}}} data a CredentialAccount as the credentials routes answer
		 */
		setCredentials(data) {
			if (!data?.id) {
				return
			}
			this.credentials = data
			// the same account the profile shows, so a changed display name
			// or flag reaches every place that reads it
			if (data.url) {
				this.addAccount({ actorId: data.url, data })
			}
		},
		/**
		 * Re-reads what the server now says about an account, after a follow
		 * or an unfollow changed it.
		 *
		 * `PUT /current/follow` answers `success([])` and says nothing about
		 * the state it left behind, and a follow of a locked or remote account
		 * is `requested` until the Accept arrives — never `following` — so
		 * nothing here can be worked out from the call having succeeded. The
		 * counts move too: the target's `followers_count` and the reader's own
		 * `following_count`.
		 *
		 * @param {string} account the handle that was followed or unfollowed
		 * @return {Promise<void>}
		 */
		async refreshFollowState(account) {
			const known = this.getAccount(account)?.id

			await Promise.all([
				this.fetchAccountInfo(account)
					.then((info) => this.fetchAccountRelationshipInfo(info?.id ?? known)),
				this.fetchCredentials(),
			])
		},
		/**
		 * Draws the relationship as it will be, before the server says so.
		 *
		 * Following somebody is two round trips — the follow, then the
		 * relationship and the credentials it refreshes — and the button sat
		 * saying "Follow" for both of them. On a fediverse round trip that is
		 * a second or more of a button that looks broken, and the usual
		 * outcome is somebody pressing it again.
		 *
		 * Only the flag the button reads is moved. Nothing else is guessed at:
		 * the follower counts, whether the follow is pending approval on a
		 * locked account, and everything else come back from the server a
		 * moment later and are the truth.
		 *
		 * @param {string} account the handle that was acted on
		 * @param {boolean} following what to show until the server answers
		 * @return {{id: string, data: import('../types/Mastodon.js').Relationship}|null} what the relationship was, to put back on failure
		 */
		assumeFollowing(account, following) {
			const actorId = this.accounts[account]?.url ?? account
			const id = this.accounts[actorId]?.id ?? this.accounts[account]?.id ?? account
			const known = this.accountsRelationships[id]
			if (known === undefined) {
				// nothing to move: a stranger has no relationship row yet, and
				// inventing one would draw a button state the server never
				// confirmed
				return null
			}

			this.addRelationship({ actorId: id, data: { ...known, following } })

			return { id, data: known }
		},

		/**
		 * @param {{id: string, data: import('../types/Mastodon.js').Relationship}|null} previous what `assumeFollowing()` handed back
		 */
		restoreFollowing(previous) {
			if (previous !== null) {
				this.addRelationship({ actorId: previous.id, data: previous.data })
			}
		},

		async followAccount({ accountToFollow }) {
			const previous = this.assumeFollowing(accountToFollow, true)
			try {
				const url = generateUrl('/apps/social/api/v1/current/follow?account=' + encodeURIComponent(accountToFollow))
				const response = await axios.put(url)
				if (response.data.status === -1) {
					// thrown rather than returned: a rejected thenable returned
					// from inside the try resolves only after this frame has
					// popped, so the catch below never saw it — a refusal was a
					// silent unhandled rejection, with no toast and no error state
					throw new Error('The server refused the follow')
				}
				await this.refreshFollowState(accountToFollow)
				return response
			} catch (error) {
				this.restoreFollowing(previous)
				showError(isBlockedBy(error)
					? t('social', 'This account has blocked you')
					: t('social', 'Could not follow {account}', { account: accountToFollow }))
				logger.error(`Failed to follow user ${accountToFollow}`, { error })
			}
		},
		async unfollowAccount({ accountToUnfollow }) {
			const previous = this.assumeFollowing(accountToUnfollow, false)
			try {
				const url = generateUrl('/apps/social/api/v1/current/follow?account=' + encodeURIComponent(accountToUnfollow))
				const response = await axios.delete(url)
				if (response.data.status === -1) {
					// see followAccount: returning a rejection from inside the try
					// escapes this function's own catch
					throw new Error('The server refused the unfollow')
				}
				await this.refreshFollowState(accountToUnfollow)
				return response
			} catch (error) {
				this.restoreFollowing(previous)
				showError(t('social', 'Could not unfollow {account}', { account: accountToUnfollow }))
				logger.error(`Failed to unfollow user ${accountToUnfollow}`, { error })
				return error
			}
		},
		async blockAccount({ id }) {
			try {
				const response = await axios.post(generateUrl(`apps/social/api/v1/accounts/${id}/block`))
				if (response.data?.id) {
					this.addRelationship({ actorId: response.data.id, data: response.data })
					useTimelineStore().removeStatusesByActor(response.data.id)
				}
				return response.data
			} catch (error) {
				showError(t('social', 'Failed to block the account'))
				logger.error('Failed to block the account', { error })
			}
		},
		async unblockAccount({ id }) {
			try {
				const response = await axios.post(generateUrl(`apps/social/api/v1/accounts/${id}/unblock`))
				if (response.data?.id) {
					this.addRelationship({ actorId: response.data.id, data: response.data })
				}
				return response.data
			} catch (error) {
				showError(t('social', 'Failed to unblock the account'))
				logger.error('Failed to unblock the account', { error })
			}
		},
		/**
		 * @param {object} options what to mute
		 * @param {string} options.id the account's numeric id
		 * @param {boolean} [options.notifications] whether their notifications go quiet too
		 * @param {number} [options.duration] seconds until the mute lifts itself, 0 for never
		 */
		async muteAccount({ id, notifications = true, duration = 0 }) {
			try {
				// said in full rather than left to the server's defaults, so a
				// reader who unticked the box gets what they asked for
				const response = await axios.post(generateUrl(`apps/social/api/v1/accounts/${id}/mute`), { notifications, duration })
				if (response.data?.id) {
					this.addRelationship({ actorId: response.data.id, data: response.data })
					useTimelineStore().removeStatusesByActor(response.data.id)
				}
				return response.data
			} catch (error) {
				showError(t('social', 'Failed to mute the account'))
				logger.error('Failed to mute the account', { error })
			}
		},
		async unmuteAccount({ id }) {
			try {
				const response = await axios.post(generateUrl(`apps/social/api/v1/accounts/${id}/unmute`))
				if (response.data?.id) {
					this.addRelationship({ actorId: response.data.id, data: response.data })
				}
				return response.data
			} catch (error) {
				showError(t('social', 'Failed to unmute the account'))
				logger.error('Failed to unmute the account', { error })
			}
		},
		/**
		 * The two switches that ride along with a follow: the bell, and
		 * whether this account's boosts belong in your timelines.
		 *
		 * Sent as a follow, which is what Mastodon's API offers — there is no
		 * route for either on its own, and re-following somebody you already
		 * follow changes nothing else. Only the switch named is sent: the
		 * server leaves the other alone, so setting one cannot silently reset
		 * the other.
		 *
		 * @param {object} options which switch, and which way
		 * @param {string} options.id the account's numeric id
		 * @param {boolean} [options.notify] ring the bell when they post
		 * @param {boolean} [options.reblogs] show their boosts
		 */
		async setFollowOptions({ id, notify, reblogs }) {
			const body = {}
			if (notify !== undefined) {
				body.notify = notify
			}
			if (reblogs !== undefined) {
				body.reblogs = reblogs
			}

			try {
				const response = await axios.post(generateUrl(`apps/social/api/v1/accounts/${id}/follow`), body)
				if (response.data?.id) {
					this.addRelationship({ actorId: response.data.id, data: response.data })
				}
				return response.data
			} catch (error) {
				showError(t('social', 'Could not change what you see from this account'))
				logger.error('Failed to change the follow options', { error })
			}
		},

		/**
		 * @param {{account?: string, maxId?: string}} options the account whose
		 * followers to load, and where the previous page ended
		 */
		async fetchAccountFollowers({ account, maxId } = {}) {
			const key = keyFor(this, account)
			if (this.accountsFollowersLoading[key]) {
				return
			}
			this.setFollowersLoading({ actorId: key, loading: true })
			try {
				const params = { limit: FOLLOW_PAGE_SIZE }
				if (maxId) {
					params.max_id = maxId
				}
				const response = await axios.get(generateUrl(`apps/social/api/v1/accounts/${account}/followers`), { params })
				if (!maxId) {
					this.addFollowers({ account, data: response.data })
				} else {
					this.addFollowersAppend({ account, data: response.data })
				}
				if (response.data.length < FOLLOW_PAGE_SIZE) {
					this.setFollowersAllLoaded({ actorId: key, loaded: true })
				}
				this.accountsFollowersFilling = { ...this.accountsFollowersFilling, [key]: response.headers?.['x-social-filling'] === '1' }
				this.setFollowersFailed({ actorId: key, failed: false })
				return response.data
			} catch (error) {
				this.setFollowersFailed({ actorId: key, failed: true })
				showError(t('social', 'Could not load the list of followers'))
				logger.error(`Failed to fetch followers list for user ${account}`, { error })
			} finally {
				this.setFollowersLoading({ actorId: key, loading: false })
			}
		},
		/**
		 * @param {{account?: string, maxId?: string}} options the account whose
		 * followed accounts to load, and where the previous page ended
		 */
		async fetchAccountFollowing({ account, maxId } = {}) {
			const key = keyFor(this, account)
			if (this.accountsFollowingsLoading[key]) {
				return
			}
			this.setFollowingsLoading({ actorId: key, loading: true })
			try {
				const params = { limit: FOLLOW_PAGE_SIZE }
				if (maxId) {
					params.max_id = maxId
				}
				const response = await axios.get(generateUrl(`apps/social/api/v1/accounts/${account}/following`), { params })
				if (!maxId) {
					this.addFollowing({ account, data: response.data })
				} else {
					this.addFollowingAppend({ account, data: response.data })
				}
				if (response.data.length < FOLLOW_PAGE_SIZE) {
					this.setFollowingsAllLoaded({ actorId: key, loaded: true })
				}
				this.accountsFollowingsFilling = { ...this.accountsFollowingsFilling, [key]: response.headers?.['x-social-filling'] === '1' }
				this.setFollowingsFailed({ actorId: key, failed: false })
				return response.data
			} catch (error) {
				this.setFollowingsFailed({ actorId: key, failed: true })
				showError(t('social', 'Could not load the list of followed accounts'))
				logger.error(`Failed to fetch following list for user ${account}`, { error })
			} finally {
				this.setFollowingsLoading({ actorId: key, loading: false })
			}
		},
	},
})
