<!-- SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<template>
	<div class="atproto-settings">
		<h2>{{ $t('Bluesky (AT Protocol)') }}</h2>
		
		<div class="setting-card" v-if="identity">
			<h3>{{ $t('Your Bluesky Identity') }}</h3>
			
			<div class="identity-info">
				<div class="info-row">
					<label>{{ $t('Handle') }}</label>
					<div class="value-with-copy">
						<span>@{{ identity.handle }}</span>
						<button class="btn btn-icon btn-sm" @click="copyToClipboard(identity.handle)">
							<icon name="copy" />
						</button>
					</div>
				</div>
				<div class="info-row">
					<label>{{ $t('DID') }}</label>
					<div class="value-with-copy">
						<code>{{ identity.did }}</code>
						<button class="btn btn-icon btn-sm" @click="copyToClipboard(identity.did)">
							<icon name="copy" />
						</button>
					</div>
				</div>
				<div class="info-row">
					<label>{{ $t('Profile') }}</label>
					<a :href="blueskyProfileUrl" target="_blank" class="btn btn-secondary btn-sm">
						{{ $t('View on Bluesky') }}
					</a>
				</div>
			</div>
			
			<div class="recovery-section" v-if="showRecovery">
				<h4>{{ $t('Recovery Phrase') }}</h4>
				<p class="warning">{{ $t('Save this recovery phrase in a safe place. It can be used to recover your Bluesky identity if this server becomes unavailable.') }}</p>
				<div class="recovery-phrase">
					<code>{{ recoveryPhrase }}</code>
					<button class="btn btn-icon btn-sm" @click="copyToClipboard(recoveryPhrase)">
						<icon name="copy" />
					</button>
				</div>
				<button class="btn btn-secondary btn-sm" @click="regenerateRecovery">
					{{ $t('Regenerate (requires password)') }}
				</button>
			</div>
			
			<button class="btn btn-secondary" @click="showRecovery = !showRecovery">
				{{ showRecovery ? $t('Hide recovery phrase') : $t('Show recovery phrase') }}
			</button>
		</div>
		
		<div class="setting-card" v-else>
			<p>{{ $t('Your Bluesky identity will be created automatically when you make your first public post or follow a Bluesky account.') }}</p>
		</div>
		
		<div class="setting-card">
			<h3>{{ $t('Bluesky Settings') }}</h3>
			
			<div class="setting-toggle">
				<label>
					<input type="checkbox" v-model="settings.syncPosts" />
					{{ $t('Sync public posts to Bluesky') }}
				</label>
				<p class="setting-hint">{{ $t('Your public posts will be automatically published to Bluesky. Unlisted, followers-only, and direct posts are never synced.') }}</p>
			</div>
			
			<div class="setting-toggle">
				<label>
					<input type="checkbox" v-model="settings.syncInteractions" />
					{{ $t('Sync likes and reposts to Bluesky') }}
				</label>
				<p class="setting-hint">{{ $t('When you like or repost a Bluesky post, the action is recorded on Bluesky.') }}</p>
			</div>
			
			<div class="setting-toggle">
				<label>
					<input type="checkbox" v-model="settings.showBadge" />
					{{ $t('Show Bluesky badge on profile') }}
				</label>
				<p class="setting-hint">{{ $t('Display a Bluesky badge next to your name on your profile and posts.') }}</p>
			</div>
		</div>
		
		<div class="setting-card">
			<h3>{{ $t('Labelers (Content Filtering)') }}</h3>
			<p>{{ $t('Subscribe to moderation labelers to filter content on Bluesky.') }}</p>
			
			<div class="labelers-list">
				<div class="labeler-item" v-for="labeler in labelers" :key="labeler.did">
					<div class="labeler-info">
						<label>
							<input type="checkbox" v-model="labeler.subscribed" @change="updateLabeler(labeler)" />
							<span class="labeler-name">{{ labeler.name || labeler.did }}</span>
						</label>
						<span class="labeler-did">{{ labeler.did }}</span>
					</div>
					<div class="labeler-settings" v-if="labeler.subscribed">
						<select v-model="labeler.setting" @change="updateLabeler(labeler)">
							<option value="ignore">{{ $t('Ignore') }}</option>
							<option value="warn">{{ $t('Warn') }}</option>
							<option value="hide">{{ $t('Hide') }}</option>
						</select>
					</div>
				</div>
			</div>
			
			<div class="add-labeler">
				<input type="text" v-model="newLabelerDid" placeholder="did:plc:... or handle" />
				<button class="btn btn-secondary btn-sm" @click="addLabeler">{{ $t('Add labeler') }}</button>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useApi } from '../composables/useApi.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'

const { currentUser } = useCurrentUser()
const api = useApi()

const identity = ref(null)
const recoveryPhrase = ref('')
const showRecovery = ref(false)
const settings = reactive({
	syncPosts: true,
	syncInteractions: true,
	showBadge: true
})
const labelers = ref([])
const newLabelerDid = ref('')

const blueskyProfileUrl = computed(() => identity.value ? `https://bsky.app/profile/${identity.value.handle}` : '')

onMounted(async () => {
	await loadIdentity()
	await loadSettings()
	await loadLabelers()
})

async function loadIdentity() {
	try {
		const response = await api.get('/api/atproto/identity')
		identity.value = response.data
	} catch (error) {
		// No identity yet
	}
}

async function loadSettings() {
	try {
		const response = await api.get('/api/atproto/settings')
		Object.assign(settings, response.data)
	} catch (error) {
		// Use defaults
	}
}

async function loadLabelers() {
	try {
		const response = await api.get('/api/atproto/labelers')
		labelers.value = response.data
	} catch (error) {
		// Default labeler
		labelers.value = [
			{ did: 'did:plc:ar7c4by46qjdydhdevvrndac', name: 'Bluesky Moderation', subscribed: true, setting: 'warn' }
		]
	}
}

async function updateLabeler(labeler) {
	try {
		await api.post('/api/atproto/labelers', labeler)
	} catch (error) {
		console.error('Failed to update labeler:', error)
	}
}

async function addLabeler() {
	if (!newLabelerDid.value) return
	try {
		const response = await api.post('/api/atproto/labelers/add', { did: newLabelerDid.value })
		labelers.value.push({ ...response.data, subscribed: true, setting: 'warn' })
		newLabelerDid.value = ''
	} catch (error) {
		console.error('Failed to add labeler:', error)
	}
}

const copyToClipboard = async (text) => {
	await navigator.clipboard.writeText(text)
	// Show toast
}

async function regenerateRecovery() {
	// Would require password confirmation
}
</script>

<style scoped>
.atproto-settings {
	max-width: 600px;
	margin: 0 auto;
	padding: 1rem;
}

.setting-card {
	background: var(--card-bg);
	border: 1px solid var(--border-color);
	border-radius: 8px;
	padding: 1.5rem;
	margin-bottom: 1.5rem;
}

.setting-card h3 {
	margin: 0 0 1rem 0;
	font-size: 1rem;
}

.setting-card h4 {
	margin: 1rem 0 0.5rem 0;
	font-size: 0.875rem;
}

.identity-info {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.info-row {
	display: flex;
	align-items: center;
	gap: 1rem;
}

.info-row label {
	min-width: 120px;
	font-weight: 500;
	color: var(--text-secondary);
}

.value-with-copy {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	flex: 1;
}

.value-with-copy code {
	background: var(--bg-secondary);
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.8125rem;
	word-break: break-all;
}

.recovery-section {
	margin-top: 1.5rem;
	padding-top: 1.5rem;
	border-top: 1px solid var(--border-color);
}

.warning {
	background: #fff3cd;
	border: 1px solid #ffc107;
	color: #856404;
	padding: 0.75rem;
	border-radius: 4px;
	font-size: 0.8125rem;
	margin-bottom: 1rem;
}

.recovery-phrase {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	background: var(--bg-secondary);
	padding: 0.75rem;
	border-radius: 4px;
	font-size: 0.8125rem;
	word-break: break-all;
}

.setting-toggle {
	margin-bottom: 1rem;
}

.setting-toggle label {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	cursor: pointer;
}

.setting-hint {
	margin: 0.25rem 0 0 1.5rem;
	font-size: 0.8125rem;
	color: var(--text-muted);
}

.labelers-list {
	margin-bottom: 1rem;
}

.labeler-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 0.75rem;
	background: var(--bg-secondary);
	border-radius: 4px;
	margin-bottom: 0.5rem;
}

.labeler-info {
	display: flex;
	align-items: center;
	gap: 0.75rem;
}

.labeler-info label {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	cursor: pointer;
}

.labeler-name {
	font-weight: 500;
}

.labeler-did {
	font-size: 0.75rem;
	color: var(--text-muted);
	font-family: monospace;
}

.labeler-settings {
	margin-left: 2rem;
}

.labeler-settings select {
	padding: 0.25rem 0.5rem;
	border: 1px solid var(--border-color);
	border-radius: 4px;
	font-size: 0.8125rem;
}

.add-labeler {
	display: flex;
	gap: 0.5rem;
}

.add-labeler input {
	flex: 1;
	padding: 0.5rem;
	border: 1px solid var(--border-color);
	border-radius: 4px;
	font-family: monospace;
}
</style>