// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

export interface AtprotoIdentity {
	did: string
	handle: string
	state: 'active' | 'deactivated' | 'moved_away' | 'tombstoned'
}

export interface AtprotoProfile {
	did: string
	handle: string
	displayName?: string
	description?: string
	avatar?: string
	banner?: string
	followersCount: number
	followsCount: number
	postsCount: number
	indexedAt: string
	labels?: AtprotoLabel[]
}

export interface AtprotoLabel {
	val: string
	src: string
	cts?: string
}

export interface AtprotoPost {
	uri: string
	cid: string
	author: AtprotoProfile
	record: AtprotoPostRecord
	indexedAt: string
	likeCount: number
	repostCount: number
	replyCount: number
	viewer?: {
		like?: string
		repost?: string
	}
	labels?: AtprotoLabel[]
	embed?: AtprotoEmbed
}

export interface AtprotoPostRecord {
	$type: 'app.bsky.feed.post'
	text: string
	facets?: AtprotoFacet[]
	createdAt: string
	reply?: AtprotoReplyRef
	embed?: AtprotoEmbed
	langs?: string[]
	labels?: AtprotoLabel[]
}

export interface AtprotoFacet {
	index: {
		byteStart: number
		byteEnd: number
	}
	features: AtprotoFacetFeature[]
}

export interface AtprotoFacetFeature {
	$type: 'app.bsky.richtext.facet#link' | 'app.bsky.richtext.facet#mention' | 'app.bsky.richtext.facet#tag'
	uri?: string
	did?: string
	tag?: string
}

export interface AtprotoReplyRef {
	root: { uri: string; cid: string }
	parent: { uri: string; cid: string }
}

export interface AtprotoEmbed {
	$type: 'app.bsky.embed.images' | 'app.bsky.embed.external' | 'app.bsky.embed.record' | 'app.bsky.embed.recordWithMedia'
	images?: AtprotoImage[]
	external?: AtprotoExternal
	record?: AtprotoEmbedRecord
	media?: AtprotoEmbed
}

export interface AtprotoImage {
	alt: string
	aspectRatio?: { width: number; height: number }
	image: {
		$type: 'blob'
		ref: { $link: string }
		mimeType: string
		size: number
	}
}

export interface AtprotoExternal {
	uri: string
	title: string
	description: string
	thumb?: AtprotoImage['image']
}

export interface AtprotoEmbedRecord {
	$type: 'app.bsky.embed.record' | 'app.bsky.embed.recordWithMedia'
	record: {
		uri: string
		cid: string
		author: AtprotoProfile
		value: AtprotoPostRecord
		indexedAt: string
	}
}

export interface AtprotoNotification {
	uri: string
	cid: string
	author: AtprotoProfile
	reason: 'like' | 'repost' | 'follow' | 'reply' | 'mention' | 'quote'
	reasonSubject?: string
	indexedAt: string
	isRead: boolean
}

export interface AtprotoSession {
	accessJwt: string
	refreshJwt: string
	handle: string
	did: string
	email: string
	emailConfirmed: boolean
	active: boolean
}

export interface AtprotoServiceAuth {
	token: string
}

export interface AtprotoResolveHandleResponse {
	did: string
	handle: string
}

export interface AtprotoGetProfileResponse {
	did: string
	handle: string
	displayName?: string
	description?: string
	avatar?: string
	banner?: string
	followersCount: number
	followsCount: number
	postsCount: number
	indexedAt: string
	labels?: AtprotoLabel[]
	viewer?: {
		following?: string
		followedBy?: string
		blocking?: string
		blockingBy?: string
	}
}