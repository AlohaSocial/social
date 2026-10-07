// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { ref, computed, onMounted } from 'vue'
import { useAtproto } from './useAtproto.js'

/**
 * Composable for Bluesky badge display
 */
export function useBlueskyBadge() {
	const { hasAtprotoIdentity, getAtprotoHandle, getAtprotoProfileUrl, formatAtprotoHandle, isBlueskyPost } = useAtproto()
	
	// Bluesky butterfly icon SVG
	const blueskyIcon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13c0-.55-.45-1-1-1s-1 .45-1 1v4c0 .55.45 1 1 1s1-.45 1-1v-4zm0 6c0 .55-.45 1-1 1s-1-.45-1-1v-2c0-.55.45-1 1-1s1 .45 1 1v2z"/></svg>`
	
	const showBadge = (actor) => {
		return hasAtprotoIdentity(actor)
	}
	
	const getBadgeProps = (actor) => {
		const handle = getAtprotoHandle(actor)
		const profileUrl = getAtprotoProfileUrl(actor)
		
		return {
			handle: handle ? formatAtprotoHandle(handle) : '',
			profileUrl,
			icon: blueskyIcon,
			title: 'On Bluesky'
		}
	}
	
	const getPostBadgeProps = (post) => {
		if (!isBlueskyPost(post)) return null
		
		const actor = post.attributed_to
		return getBadgeProps(actor)
	}
	
	return {
		showBadge,
		getBadgeProps,
		getPostBadgeProps,
		blueskyIcon
	}
}