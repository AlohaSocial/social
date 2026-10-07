// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { ref, computed } from 'vue'
import { useApi } from './useApi.js'

/**
 * Composable for AT Protocol (Bluesky) related functionality
 */
export function useAtproto() {
	const api = useApi()
	
	// Check if current actor has Bluesky identity
	const hasAtprotoIdentity = (actor) => {
		if (!actor?.details?.atproto) return false
		return actor.details.atproto.did && actor.details.atproto.handle
	}
	
	// Get Bluesky handle from actor
	const getAtprotoHandle = (actor) => {
		if (!hasAtprotoIdentity(actor)) return null
		return actor.details.atproto.handle
	}
	
	// Get Bluesky DID from actor
	const getAtprotoDid = (actor) => {
		if (!hasAtprotoIdentity(actor)) return null
		return actor.details.atproto.did
	}
	
	// Get Bluesky profile URL
	const getAtprotoProfileUrl = (actor) => {
		const handle = getAtprotoHandle(actor)
		if (!handle) return null
		return `https://bsky.app/profile/${handle}`
	}
	
	// Get Bluesky post URL
	const getAtprotoPostUrl = (post) => {
		if (!post?.details?.atproto?.uri) return null
		const uri = post.details.atproto.uri
		// at://did:plc:xyz/app.bsky.feed.post/abc
		const match = uri.match(/at:\/\/([^\/]+)\/app\.bsky\.feed\.post\/([^\/]+)/)
		if (match) {
			const handle = getAtprotoHandle({ details: { atproto: { did: match[1] } } })
			if (handle) {
				return `https://bsky.app/profile/${handle}/post/${match[2]}`
			}
		}
		return null
	}
	
	// Check if a post is from Bluesky
	const isBlueskyPost = (post) => {
		return post?.details?.atproto?.uri?.startsWith('at://')
	}
	
	// Format Bluesky handle for display (@handle)
	const formatAtprotoHandle = (handle) => {
		if (!handle) return ''
		return `@${handle}`
	}
	
	return {
		hasAtprotoIdentity,
		getAtprotoHandle,
		getAtprotoDid,
		getAtprotoProfileUrl,
		getAtprotoPostUrl,
		isBlueskyPost,
		formatAtprotoHandle
	}
}

/**
 * Composable for Bluesky-specific actions (follow, like, repost, reply)
 */
export function useAtprotoActions() {
	const api = useApi()
	const loading = ref(new Set())
	
	const isLoading = (actionId) => loading.value.has(actionId)
	
	const setLoading = (actionId, value) => {
		if (value) {
			loading.value.add(actionId)
		} else {
			loading.value.delete(actionId)
		}
	}
	
	/**
	 * Follow a Bluesky account
	 */
	const followBluesky = async (actorId, targetDid) => {
		const actionId = `follow-${targetDid}`
		setLoading(actionId, true)
		try {
			await api.post('/api/atproto/follow', { targetDid })
			return { success: true }
		} catch (error) {
			return { success: false, error }
		} finally {
			setLoading(actionId, false)
		}
	}
	
	/**
	 * Unfollow a Bluesky account
	 */
	const unfollowBluesky = async (actorId, targetDid) => {
		const actionId = `unfollow-${targetDid}`
		setLoading(actionId, true)
		try {
			await api.post('/api/atproto/unfollow', { targetDid })
			return { success: true }
		} catch (error) {
			return { success: false, error }
		} finally {
			setLoading(actionId, false)
		}
	}
	
	/**
	 * Like a Bluesky post
	 */
	const likeBluesky = async (postUri, postCid) => {
		const actionId = `like-${postUri}`
		setLoading(actionId, true)
		try {
			await api.post('/api/atproto/like', { uri: postUri, cid: postCid })
			return { success: true }
		} catch (error) {
			return { success: false, error }
		} finally {
			setLoading(actionId, false)
		}
	}
	
	/**
	 * Unlike a Bluesky post
	 */
	const unlikeBluesky = async (postUri) => {
		const actionId = `unlike-${postUri}`
		setLoading(actionId, true)
		try {
			await api.post('/api/atproto/unlike', { uri: postUri })
			return { success: true }
		} catch (error) {
			return { success: false, error }
		} finally {
			setLoading(actionId, false)
		}
	}
	
	/**
	 * Repost (boost) a Bluesky post
	 */
	const repostBluesky = async (postUri, postCid) => {
		const actionId = `repost-${postUri}`
		setLoading(actionId, true)
		try {
			await api.post('/api/atproto/repost', { uri: postUri, cid: postCid })
			return { success: true }
		} catch (error) {
			return { success: false, error }
		} finally {
			setLoading(actionId, false)
		}
	}
	
	/**
	 * Undo repost a Bluesky post
	 */
	const undoRepostBluesky = async (postUri) => {
		const actionId = `undorepost-${postUri}`
		setLoading(actionId, true)
		try {
			await api.post('/api/atproto/undorepost', { uri: postUri })
			return { success: true }
		} catch (error) {
			return { success: false, error }
		} finally {
			setLoading(actionId, false)
		}
	}
	
	/**
	 * Reply to a Bluesky post
	 */
	const replyBluesky = async (text, rootUri, rootCid, parentUri, parentCid) => {
		const actionId = `reply-${rootUri}`
		setLoading(actionId, true)
		try {
			await api.post('/api/atproto/reply', {
				text,
				root: { uri: rootUri, cid: rootCid },
				parent: { uri: parentUri, cid: parentCid }
			})
			return { success: true }
		} catch (error) {
			return { success: false, error }
		} finally {
			setLoading(actionId, false)
		}
	}
	
	/**
	 * Quote a Bluesky post
	 */
	const quoteBluesky = async (text, quoteUri, quoteCid) => {
		const actionId = `quote-${quoteUri}`
		setLoading(actionId, true)
		try {
			await api.post('/api/atproto/quote', {
				text,
				quote: { uri: quoteUri, cid: quoteCid }
			})
			return { success: true }
		} catch (error) {
			return { success: false, error }
		} finally {
			setLoading(actionId, false)
		}
	}
	
	/**
	 * Search Bluesky actors
	 */
	const searchBlueskyActors = async (query) => {
		const actionId = `search-${query}`
		setLoading(actionId, true)
		try {
			const response = await api.get('/api/atproto/search/actors', { q: query })
			return response.data
		} catch (error) {
			return { actors: [], error }
		} finally {
			setLoading(actionId, false)
		}
	}
	
	/**
	 * Get Bluesky profile
	 */
	const getBlueskyProfile = async (handleOrDid) => {
		const actionId = `profile-${handleOrDid}`
		setLoading(actionId, true)
		try {
			const response = await api.get('/api/atproto/profile', { actor: handleOrDid })
			return response.data
		} catch (error) {
			return { profile: null, error }
		} finally {
			setLoading(actionId, false)
		}
	}
	
	/**
	 * Get Bluesky post thread
	 */
	const getBlueskyThread = async (postUri) => {
		const actionId = `thread-${postUri}`
		setLoading(actionId, true)
		try {
			const response = await api.get('/api/atproto/thread', { uri: postUri })
			return response.data
		} catch (error) {
			return { thread: null, error }
		} finally {
			setLoading(actionId, false)
		}
	}
	
	return {
		isLoading,
		followBluesky,
		unfollowBluesky,
		likeBluesky,
		unlikeBluesky,
		repostBluesky,
		undoRepostBluesky,
		replyBluesky,
		quoteBluesky,
		searchBlueskyActors,
		getBlueskyProfile,
		getBlueskyThread
	}
}