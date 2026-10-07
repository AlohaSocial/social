<!-- SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<template>
	<div class="bluesky-post-card" :class="{ 'is-bluesky': isBlueskyPost }">
		<div class="post-header">
			<div class="post-author">
				<Avatar :actor="post.attributed_to" size="32" />
				<div class="author-info">
					<div class="author-name-row">
						<DisplayName :actor="post.attributed_to" />
						<BlueskyBadge v-if="showBlueskyBadge" :actor="post.attributed_to" size="small" />
					</div>
					<div class="author-handle">{{ formatActorHandle(post.attributed_to) }}</div>
				</div>
			</div>
			<div class="post-meta">
				<RelativeTime :timestamp="post.published" />
				<PostMenu :post="post" />
			</div>
		</div>
		
		<div class="post-content" v-html="renderContent(post)"></div>
		
		<div class="post-embed" v-if="post.details?.atproto?.embed">
			<BlueskyEmbed :embed="post.details.atproto.embed" />
		</div>
		
		<div class="post-media" v-if="post.media_attachments?.length">
			<MediaGallery :attachments="post.media_attachments" />
		</div>
		
		<div class="post-actions">
			<ActionButton
				icon="reply"
				:count="post.replyCount || 0"
				@click="reply"
			/>
			<ActionButton
				icon="repost"
				:count="post.repostCount || 0"
				:active="post.viewer?.repost"
				@click="toggleRepost"
			/>
			<ActionButton
				icon="like"
				:count="post.likeCount || 0"
				:active="post.viewer?.like"
				@click="toggleLike"
			/>
			<ActionButton
				icon="quote"
				:count="post.quoteCount || 0"
				@click="quote"
			/>
			<ActionButton
				icon="share"
				@click="share"
			/>
		</div>
		
		<div class="post-labels" v-if="post.labels?.length">
			<span class="label" v-for="label in post.labels" :key="label.val">
				{{ formatLabel(label.val) }}
			</span>
		</div>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import { useAtproto, useAtprotoActions } from './useAtproto.js'
import { useBlueskyBadge } from './useBlueskyBadge.js'
import Avatar from './Avatar.vue'
import DisplayName from './DisplayName.vue'
import RelativeTime from './RelativeTime.vue'
import PostMenu from './PostMenu.vue'
import ActionButton from './ActionButton.vue'
import MediaGallery from './MediaGallery.vue'
import BlueskyBadge from './BlueskyBadge.vue'
import BlueskyEmbed from './BlueskyEmbed.vue'

const props = defineProps({
	post: {
		type: Object,
		required: true
	}
})

const { t } = useI18n()
const { isBlueskyPost, getAtprotoPostUrl } = useAtproto()
const { likeBluesky, unlikeBluesky, repostBluesky, undoRepostBluesky, replyBluesky, quoteBluesky } = useAtprotoActions()
const { showBadge } = useBlueskyBadge()

const isBlueskyPost = computed(() => isBlueskyPost(props.post))
const showBlueskyBadge = computed(() => isBlueskyPost.value && showBadge(props.post.attributed_to))

const formatActorHandle = (actor) => {
	if (!actor) return ''
	if (actor.details?.atproto?.handle) {
		return `@${actor.details.atproto.handle}`
	}
	return actor.preferredUsername ? `@${actor.preferredUsername}` : ''
}

const renderContent = (post) => {
	// Would render HTML content with facets
	return post.content || ''
}

const toggleLike = async () => {
	if (!isBlueskyPost.value) return
	
	const postUri = props.post.details.atproto.uri
	const postCid = props.post.details.atproto.cid
	
	if (props.post.viewer?.like) {
		await unlikeBluesky(postUri)
		props.post.viewer.like = null
		props.post.likeCount--
	} else {
		await likeBluesky(postUri, postCid)
		props.post.viewer.like = 'liked'
		props.post.likeCount++
	}
}

const toggleRepost = async () => {
	if (!isBlueskyPost.value) return
	
	const postUri = props.post.details.atproto.uri
	const postCid = props.post.details.atproto.cid
	
	if (props.post.viewer?.repost) {
		await undoRepostBluesky(postUri)
		props.post.viewer.repost = null
		props.post.repostCount--
	} else {
		await repostBluesky(postUri, postCid)
		props.post.viewer.repost = 'reposted'
		props.post.repostCount++
	}
}

const reply = () => {
	// Open reply composer
	if (isBlueskyPost.value) {
		// Would open composer with reply context
		const rootUri = props.post.details.atproto.uri
		const rootCid = props.post.details.atproto.cid
		const parentUri = props.post.details.atproto.reply?.parent?.uri || rootUri
		const parentCid = props.post.details.atproto.reply?.parent?.cid || rootCid
		
		// Emit event to open composer
		window.dispatchEvent(new CustomEvent('open-composer', {
			detail: { replyTo: { rootUri, rootCid, parentUri, parentCid, isBluesky: true } }
		}))
	}
}

const quote = () => {
	if (!isBlueskyPost.value) return
	
	const quoteUri = props.post.details.atproto.uri
	const quoteCid = props.post.details.atproto.cid
	
	window.dispatchEvent(new CustomEvent('open-composer', {
		detail: { quote: { uri: quoteUri, cid: quoteCid, isBluesky: true } }
	}))
}

const share = () => {
	const url = getAtprotoPostUrl(props.post)
	if (url && navigator.share) {
		navigator.share({ url })
	} else if (url) {
		navigator.clipboard.writeText(url)
	}
}

const formatLabel = (val) => {
	const labels = {
		'!warn': 'Content Warning',
		'porn': 'Adult Content',
		'sexual': 'Sexual Content',
		'nudity': 'Nudity',
		'graphic-media': 'Graphic Media'
	}
	return labels[val] || val
}
</script>

<style scoped>
.bluesky-post-card {
	background: var(--card-bg);
	border: 1px solid var(--border-color);
	border-radius: 12px;
	padding: 1rem;
	transition: box-shadow 0.2s;
}

.bluesky-post-card:hover {
	box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.bluesky-post-card.is-bluesky {
	border-left: 3px solid #0085ff;
}

.post-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 0.5rem;
}

.post-author {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.author-name-row {
	display: flex;
	align-items: center;
	gap: 0.25rem;
}

.author-handle {
	font-size: 0.875rem;
	color: var(--text-muted);
}

.post-meta {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.post-content {
	margin-bottom: 0.5rem;
	line-height: 1.5;
}

.post-actions {
	display: flex;
	gap: 0.5rem;
	padding-top: 0.5rem;
	border-top: 1px solid var(--border-color);
}

.post-labels {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem;
	margin-top: 0.5rem;
	padding-top: 0.5rem;
	border-top: 1px solid var(--border-color);
}

.label {
	background: var(--label-bg, #fff3cd);
	color: var(--label-text, #856404);
	padding: 0.125rem 0.5rem;
	border-radius: 9999px;
	font-size: 0.75rem;
}
</style>