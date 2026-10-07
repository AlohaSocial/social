<!-- SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<template>
	<div class="bluesky-search" v-if="showBlueskySearch">
		<div class="search-header">
			<h3>{{ $t('Search Bluesky') }}</h3>
			<button class="btn btn-icon" @click="closeSearch">
				<icon name="close" />
			</button>
		</div>
		
		<div class="search-input-wrapper">
			<input
				type="text"
				v-model="query"
				:placeholder="$t('Search Bluesky users...')"
				@input="debouncedSearch"
				@keydown.enter="search"
				autofocus
			/>
			<icon v-if="query" name="search" class="search-icon" />
			<div v-if="loading" class="search-spinner" />
		</div>
		
		<div class="search-results" v-if="results.length > 0">
			<div class="result-item" v-for="actor in results" :key="actor.did" @click="selectActor(actor)">
				<Avatar :actor="{ details: { atproto: { did: actor.did, handle: actor.handle } }, displayName: actor.displayName, avatar: actor.avatar }" size="40" />
				<div class="result-info">
					<div class="result-name">{{ actor.displayName || actor.handle }}</div>
					<div class="result-handle">{{ formatHandle(actor.handle) }}</div>
					<BlueskyBadge :actor="{ details: { atproto: { did: actor.did, handle: actor.handle } } }" size="small" />
				</div>
				<button class="btn btn-sm btn-primary" @click.stop="followActor(actor)" :disabled="actor.viewer?.following">
					{{ actor.viewer?.following ? $t('Following') : $t('Follow') }}
				</button>
			</div>
		</div>
		
		<div class="search-empty" v-if="!loading && query && results.length === 0">
			{{ $t('No users found') }}
		</div>
	</div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useAtproto, useAtprotoActions } from './useAtproto.js'
import { useBlueskyBadge } from './useBlueskyBadge.js'
import Avatar from './Avatar.vue'
import BlueskyBadge from './BlueskyBadge.vue'

const props = defineProps({
	modelValue: {
		type: Boolean,
		default: false
	}
})

const emit = defineEmits(['update:modelValue', 'select'])

const { searchBlueskyActors, followBluesky, unfollowBluesky } = useAtprotoActions()
const { formatAtprotoHandle } = useAtproto()

const showBlueskySearch = computed({
	get: () => props.modelValue,
	set: (value) => emit('update:modelValue', value)
})

const query = ref('')
const results = ref([])
const loading = ref(false)
let debounceTimer = null

const formatHandle = (handle) => `@${handle}`

const debouncedSearch = () => {
	clearTimeout(debounceTimer)
	debounceTimer = setTimeout(() => {
		search()
	}, 300)
}

const search = async () => {
	if (!query.value.trim()) return
	
	loading.value = true
	try {
		const { actors, error } = await searchBlueskyActors(query.value)
		if (error) throw error
		results.value = actors || []
	} catch (error) {
		console.error('Bluesky search failed:', error)
		results.value = []
	} finally {
		loading.value = false
	}
}

const selectActor = (actor) => {
	emit('select', actor)
}

const followActor = async (actor) => {
	if (actor.viewer?.following) {
		const { success } = await unfollowBluesky(currentUserId, actor.did)
		if (success) {
			actor.viewer.following = null
		}
	} else {
		const { success } = await followBluesky(currentUserId, actor.did)
		if (success) {
			actor.viewer.following = actor.did
		}
	}
}

const closeSearch = () => {
	showBlueskySearch.value = false
	query.value = ''
	results.value = []
}
</script>

<style scoped>
.bluesky-search {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: var(--bg-primary);
	z-index: 1000;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.search-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 1rem;
	border-bottom: 1px solid var(--border-color);
}

.search-header h3 {
	margin: 0;
}

.search-input-wrapper {
	position: relative;
	padding: 1rem;
	border-bottom: 1px solid var(--border-color);
}

.search-input-wrapper input {
	width: 100%;
	padding: 0.75rem 1rem;
	padding-right: 3rem;
	border: 1px solid var(--border-color);
	border-radius: 8px;
	font-size: 1rem;
	background: var(--bg-secondary);
	color: var(--text-primary);
}

.search-icon {
	position: absolute;
	right: 2rem;
	top: 50%;
	transform: translateY(-50%);
	color: var(--text-muted);
	pointer-events: none;
}

.search-spinner {
	position: absolute;
	right: 2rem;
	top: 50%;
	transform: translateY(-50%);
	width: 20px;
	height: 20px;
	border: 2px solid var(--border-color);
	border-top-color: var(--primary-color);
	border-radius: 50%;
	animation: spin 1s linear infinite;
}

@keyframes spin {
	to { transform: translateY(-50%) rotate(360deg); }
}

.search-results {
	flex: 1;
	overflow-y: auto;
	padding: 1rem;
}

.result-item {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 0.75rem;
	border-radius: 8px;
	background: var(--bg-secondary);
	margin-bottom: 0.5rem;
	cursor: pointer;
	transition: background 0.15s;
}

.result-item:hover {
	background: var(--bg-tertiary);
}

.result-info {
	flex: 1;
	min-width: 0;
}

.result-name {
	font-weight: 500;
	font-size: 0.9375rem;
}

.result-handle {
	font-size: 0.8125rem;
	color: var(--text-muted);
	margin-top: 0.125rem;
	display: flex;
	align-items: center;
	gap: 0.375rem;
}

.search-empty {
	padding: 2rem;
	text-align: center;
	color: var(--text-muted);
}
</style>