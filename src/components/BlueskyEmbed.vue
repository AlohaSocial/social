<!-- SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<template>
	<div class="bluesky-embed" v-if="embed">
		<!-- Images embed -->
		<div v-if="embed.$type === 'app.bsky.embed.images'" class="embed-images">
			<div class="images-grid" :class="{ 'count-' + embed.images.length }">
				<div class="image-item" v-for="(image, index) in embed.images" :key="index">
					<img 
						:src="getBlobUrl(image.image.ref['$link'])" 
						:alt="image.alt"
						@click="openImage(index)"
					/>
				</div>
			</div>
		</div>
		
		<!-- External link card -->
		<div v-else-if="embed.$type === 'app.bsky.embed.external'" class="embed-external">
			<a :href="embed.external.uri" target="_blank" rel="noopener noreferrer" class="external-link">
				<div class="external-thumb" v-if="embed.external.thumb">
					<img :src="getBlobUrl(embed.external.thumb.ref['$link'])" :alt="embed.external.title" />
				</div>
				<div class="external-content">
					<h4>{{ embed.external.title }}</h4>
					<p>{{ embed.external.description }}</p>
					<span class="external-domain">{{ getDomain(embed.external.uri) }}</span>
				</div>
			</a>
		</div>
		
		<!-- Record embed (quote) -->
		<div v-else-if="embed.$type === 'app.bsky.embed.record'" class="embed-record">
			<div class="quoted-post" v-if="embed.record">
				<div class="quoted-author">
					<Avatar :actor="embed.record.author" size="24" />
					<div>
						<div class="quoted-name">{{ embed.record.author.displayName || embed.record.author.handle }}</div>
						<div class="quoted-handle">{{ formatHandle(embed.record.author.handle) }}</div>
					</div>
				</div>
				<div class="quoted-content" v-html="renderQuotedContent(embed.record.value)"></div>
				<div class="quoted-meta">
					<RelativeTime :timestamp="embed.record.indexedAt" />
				</div>
			</div>
		</div>
		
		<!-- Record with media -->
		<div v-else-if="embed.$type === 'app.bsky.embed.recordWithMedia'" class="embed-record-with-media">
			<BlueskyEmbed :embed="embed.record" />
			<BlueskyEmbed :embed="embed.media" />
		</div>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import { useAtproto } from './useAtproto.js'
import Avatar from './Avatar.vue'
import RelativeTime from './RelativeTime.vue'

const props = defineProps({
	embed: {
		type: Object,
		required: true
	}
})

const { getAtprotoHandle } = useAtproto()

const formatHandle = (handle) => handle ? `@${handle}` : ''

const getBlobUrl = (cid) => {
	// Would use the AppView CDN or proxy
	return `https://cdn.bsky.app/img/feed_thumbnail/plain/${cid}@jpeg`
}

const getDomain = (url) => {
	try {
		return new URL(url).hostname
	} catch {
		return ''
	}
}

const renderQuotedContent = (record) => {
	if (!record) return ''
	// Would render text with facets
	return record.text || ''
}
</script>

<style scoped>
.bluesky-embed {
	margin: 0.5rem 0;
	padding: 0.75rem;
	background: var(--embed-bg, #f8f9fa);
	border-radius: 8px;
	border: 1px solid var(--border-color);
}

.embed-images {
	margin: -0.75rem;
	border-radius: 8px;
	overflow: hidden;
}

.images-grid {
	display: grid;
	gap: 2px;
}

.images-grid.count-1 { grid-template-columns: 1fr; }
.images-grid.count-2 { grid-template-columns: 1fr 1fr; }
.images-grid.count-3 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; }
.images-grid.count-4 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; }

.images-grid.count-3 .image-item:first-child { grid-row: span 2; }
.images-grid.count-4 .image-item:first-child { grid-row: span 2; }

.image-item {
	position: relative;
	aspect-ratio: 1;
	overflow: hidden;
}

.image-item img {
	width: 100%;
	height: 100%;
	object-fit: cover;
	transition: transform 0.2s;
}

.image-item:hover img {
	transform: scale(1.05);
}

.embed-external {
	display: block;
}

.external-link {
	display: flex;
	gap: 0.75rem;
	text-decoration: none;
	color: inherit;
}

.external-thumb {
	width: 96px;
	height: 96px;
	border-radius: 8px;
	overflow: hidden;
	flex-shrink: 0;
}

.external-thumb img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.external-content {
	flex: 1;
	min-width: 0;
}

.external-content h4 {
	margin: 0 0 0.25rem 0;
	font-size: 0.875rem;
	font-weight: 600;
	line-height: 1.3;
}

.external-content p {
	margin: 0 0 0.25rem 0;
	font-size: 0.8125rem;
	color: var(--text-muted);
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.external-domain {
	font-size: 0.75rem;
	color: var(--text-muted);
}

.embed-record {
	background: var(--card-bg);
	border: 1px solid var(--border-color);
	border-radius: 8px;
	padding: 0.75rem;
	margin: -0.75rem;
}

.quoted-author {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 0.5rem;
}

.quoted-name {
	font-weight: 500;
	font-size: 0.875rem;
}

.quoted-handle {
	font-size: 0.75rem;
	color: var(--text-muted);
}

.quoted-content {
	font-size: 0.875rem;
	line-height: 1.5;
	margin-bottom: 0.5rem;
}

.quoted-meta {
	font-size: 0.75rem;
	color: var(--text-muted);
}
</style>