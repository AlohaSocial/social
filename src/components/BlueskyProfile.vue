<!-- SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<template>
	<div class="bluesky-profile" v-if="profile">
		<div class="bluesky-profile-header">
			<div class="bluesky-avatar" v-if="profile.avatar">
				<img :src="profile.avatar" :alt="profile.displayName || profile.handle" />
			</div>
			<div class="bluesky-profile-info">
				<div class="bluesky-profile-name-row">
					<h2 class="bluesky-display-name">{{ profile.displayName || profile.handle }}</h2>
					<BlueskyBadge :actor="{ details: { atproto: { did: profile.did, handle: profile.handle } } }" size="normal" />
				</div>
				<p class="bluesky-handle">{{ formatHandle(profile.handle) }}</p>
				<p class="bluesky-bio" v-if="profile.description">{{ profile.description }}</p>
				<div class="bluesky-profile-stats">
					<div class="stat">
						<span class="stat-value">{{ formatNumber(profile.postsCount) }}</span>
						<span class="stat-label">{{ $t('Posts') }}</span>
					</div>
					<div class="stat">
						<span class="stat-value">{{ formatNumber(profile.followersCount) }}</span>
						<span class="stat-label">{{ $t('Followers') }}</span>
					</div>
					<div class="stat">
						<span class="stat-value">{{ formatNumber(profile.followsCount) }}</span>
						<span class="stat-label">{{ $t('Following') }}</span>
					</div>
				</div>
			</div>
		</div>
		
		<div class="bluesky-profile-actions">
			<button 
				v-if="!isOwnProfile && !viewer.following" 
				class="btn btn-primary"
				@click="follow"
				:disabled="loading"
			>
				{{ $t('Follow') }}
			</button>
			<button 
				v-if="!isOwnProfile && viewer.following" 
				class="btn btn-secondary"
				@click="unfollow"
				:disabled="loading"
			>
				{{ $t('Following') }}
			</button>
			<a v-if="isOwnProfile" :href="blueskyProfileUrl" target="_blank" class="btn btn-primary">
				{{ $t('View on Bluesky') }}
			</a>
		</div>
		
		<div class="bluesky-profile-posts" v-if="posts.length > 0">
			<h3>{{ $t('Posts') }}</h3>
			<div class="posts-grid">
				<PostCard v-for="post in posts" :key="post.uri" :post="post" />
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAtproto, useAtprotoActions } from './useAtproto.js'
import { useBlueskyBadge } from './useBlueskyBadge.js'
import BlueskyBadge from './BlueskyBadge.vue'
import PostCard from './PostCard.vue'

const props = defineProps({
	actorId: {
		type: [String, Number],
		required: true
	}
})

const { t } = useI18n()
const { searchBlueskyActors, getBlueskyProfile, getBlueskyThread } = useAtprotoActions()
const { formatAtprotoHandle } = useAtproto()
const { formatNumber } = useNumber()

const profile = ref(null)
const posts = ref([])
const loading = ref(false)
const viewer = ref({ following: false })
const isOwnProfile = ref(false)
const blueskyProfileUrl = computed(() => profile.value ? `https://bsky.app/profile/${profile.value.handle}` : '')

const formatHandle = (handle) => `@${handle}`

async function loadProfile() {
	loading.value = true
	try {
		const { profile: profileData, error } = await getBlueskyProfile(props.actorId)
		if (error) throw error
		profile.value = profileData
		viewer.value = profileData.viewer || {}
		isOwnProfile.value = false // Would check against current user
		
		// Load posts
		await loadPosts()
	} catch (error) {
		console.error('Failed to load Bluesky profile:', error)
	} finally {
		loading.value = false
	}
}

async function loadPosts() {
	// Would fetch author feed
}

async function follow() {
	const { success, error } = await followBluesky(currentUserId, profile.value.did)
	if (success) {
		viewer.value.following = profile.value.did
	}
}

async function unfollow() {
	const { success, error } = await unfollowBluesky(currentUserId, profile.value.did)
	if (success) {
		viewer.value.following = null
	}
}

onMounted(() => {
	loadProfile()
})
</script>

<style scoped>
.bluesky-profile {
	padding: 1rem;
}

.bluesky-profile-header {
	display: flex;
	gap: 1rem;
	margin-bottom: 1rem;
}

.bluesky-avatar img {
	width: 80px;
	height: 80px;
	border-radius: 50%;
	object-fit: cover;
}

.bluesky-profile-name-row {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 0.25rem;
}

.bluesky-display-name {
	font-size: 1.25rem;
	font-weight: 600;
	margin: 0;
}

.bluesky-handle {
	color: var(--text-muted);
	margin: 0 0 0.5rem 0;
}

.bluesky-bio {
	margin: 0.5rem 0;
}

.bluesky-profile-stats {
	display: flex;
	gap: 1.5rem;
	margin-top: 0.5rem;
}

.stat {
	display: flex;
	flex-direction: column;
	align-items: center;
}

.stat-value {
	font-size: 1.125rem;
	font-weight: 600;
}

.stat-label {
	font-size: 0.75rem;
	color: var(--text-muted);
}

.bluesky-profile-actions {
	display: flex;
	gap: 0.5rem;
	margin-bottom: 1rem;
}

.posts-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
	gap: 1rem;
}
</style>