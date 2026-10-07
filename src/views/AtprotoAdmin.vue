<!-- SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<template>
	<div class="atproto-admin">
		<div class="admin-section">
			<h2>{{ $t('AT Protocol (Bluesky) Settings') }}</h2>
			
			<div class="setting-row">
				<label>
					<input type="checkbox" v-model="settings.enabled" @change="saveSettings" />
					{{ $t('Enable AT Protocol integration') }}
				</label>
				<p class="setting-description">
					{{ $t('When enabled, every Social account gets a Bluesky identity (did:plc) with handle @username.your-instance.com. Public posts are automatically syndicated to Bluesky.') }}
				</p>
			</div>
			
			<div class="setting-row" v-if="settings.enabled">
				<label>
					{{ $t('Handle host') }}
					<input type="text" v-model="settings.handleHost" readonly />
				</label>
				<p class="setting-description">
					{{ $t('Handles will be @username.') }}{{ settings.handleHost }}
				</p>
			</div>
			
			<div class="setting-row" v-if="settings.enabled">
				<label>
					{{ $t('Relays') }}
					<textarea v-model="settings.relaysJson" @blur="saveRelays" rows="3" placeholder="https://bsky.network&#10;https://relay.example.com" />
				</label>
				<p class="setting-description">
					{{ $t('Comma or newline separated list of relay URLs. The relay crawls your firehose and makes posts available on Bluesky.') }}
				</p>
			</div>
			
			<div class="setting-row" v-if="settings.enabled">
				<label>
					{{ $t('Jetstream endpoint (optional)') }}
					<input type="text" v-model="settings.jetstream" @blur="saveSettings" placeholder="wss://jetstream1.us-east.bsky.network/subscribe" />
				</label>
				<p class="setting-description">
					{{ $t('Optional Jetstream WebSocket endpoint for real-time Bluesky updates. Requires running <code>occ social:atproto:listen</code> daemon.') }}
				</p>
			</div>
			
			<div class="setting-row" v-if="settings.enabled">
				<label>
					{{ $t('Sync ceiling') }}
					<input type="number" v-model="settings.syncCeiling" @blur="saveSettings" min="10" max="10000" />
				</label>
				<p class="setting-description">
					{{ $t('Maximum AppView requests per sync batch (default: 200).') }}
				</p>
			</div>
			
			<div class="setting-row" v-if="settings.enabled">
				<label>
					{{ $t('PLC Directory') }}
					<input type="text" v-model="settings.plcDirectory" @blur="saveSettings" />
				</label>
				<p class="setting-description">
					{{ $t('PLC directory URL (default: https://plc.directory). Use dev PLC for testing.') }}
				</p>
			</div>
			
			<div class="setting-row" v-if="settings.enabled">
				<label>
					{{ $t('AppView') }}
					<input type="text" v-model="settings.appview" @blur="saveSettings" />
				</label>
				<p class="setting-description">
					{{ $t('AppView URL for reads (default: https://public.api.bsky.app).') }}
				</p>
			</div>
			
			<div class="setting-row" v-if="settings.enabled">
				<button class="btn btn-primary" @click="rotateKey" :disabled="rotating">
					{{ rotating ? $t('Rotating...') : $t('Rotate Instance Key') }}
				</button>
				<p class="setting-description">
					{{ $t('Rotates the instance rotation key. This will update all account DIDs via PLC operations.') }}
				</p>
			</div>
			
			<div class="setting-row" v-if="settings.enabled">
				<button class="btn btn-secondary" @click="requestCrawl" :disabled="crawling">
					{{ crawling ? $t('Requesting...') : $t('Request Crawl') }}
				</button>
				<p class="setting-description">
					{{ $t('Request relay to crawl all repositories now.') }}
				</p>
			</div>
		</div>
		
		<div class="admin-section" v-if="settings.enabled">
			<h3>{{ $t('Status') }}</h3>
			
			<div class="status-grid">
				<div class="status-card">
					<h4>{{ $t('Identities') }}</h4>
					<p class="status-value">{{ status.counts?.identities || 0 }}</p>
				</div>
				<div class="status-card">
					<h4>{{ $t('Records') }}</h4>
					<p class="status-value">{{ status.counts?.records || 0 }}</p>
				</div>
				<div class="status-card">
					<h4>{{ $t('Blobs') }}</h4>
					<p class="status-value">{{ status.counts?.blobs || 0 }}</p>
				</div>
				<div class="status-card">
					<h4>{{ $t('Watches') }}</h4>
					<p class="status-value">{{ status.counts?.watches || 0 }}</p>
				</div>
			</div>
			
			<div class="status-detail">
				<h4>{{ $t('Firehose Daemon') }}</h4>
				<p v-if="status.firehose?.running">{{ $t('Running') }} - {{ status.firehose.lastFrame }}</p>
				<p v-else>{{ $t('Not running') }}</p>
			</div>
			
			<div class="status-detail">
				<h4>{{ $t('Jetstream Listener') }}</h4>
				<p v-if="status.listener?.running">{{ $t('Running') }} - {{ status.listener.lastEvent }}</p>
				<p v-else>{{ $t('Not running') }}</p>
			</div>
			
			<div class="status-detail">
				<h4>{{ $t('Service DID') }}</h4>
				<p>{{ status.serviceDid }}</p>
			</div>
			
			<div class="status-detail">
				<h4>{{ $t('Rotation Key Age') }}</h4>
				<p>{{ status.rotationKeyAge }}</p>
			</div>
		</div>
		
		<div class="admin-section" v-if="settings.enabled">
			<h3>{{ $t('Blocklist') }}</h3>
			
			<div class="blocklist-form">
				<select v-model="blocklistKind">
					<option value="host">{{ $t('PDS Host') }}</option>
					<option value="did">{{ $t('DID') }}</option>
				</select>
				<input type="text" v-model="blocklistValue" :placeholder="blocklistKind === 'host' ? 'example.com' : 'did:plc:...'" />
				<input type="text" v-model="blocklistReason" placeholder="{{ $t('Reason (optional)') }}" />
				<button class="btn btn-danger" @click="addToBlocklist">{{ $t('Block') }}</button>
			</div>
			
			<table class="blocklist-table">
				<thead>
					<tr>
						<th>{{ $t('Type') }}</th>
						<th>{{ $t('Value') }}</th>
						<th>{{ $t('Reason') }}</th>
						<th>{{ $t('Created') }}</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="item in status.blocklist" :key="item.kind + ':' + item.value">
						<td>{{ item.kind }}</td>
						<td>{{ item.value }}</td>
						<td>{{ item.reason }}</td>
						<td>{{ formatDate(item.created) }}</td>
						<td>
							<button class="btn btn-icon btn-danger" @click="removeFromBlocklist(item.kind, item.value)">
								<icon name="trash" />
							</button>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useApi } from '../composables/useApi.js'

const api = useApi()

const settings = reactive({
	enabled: false,
	relaysJson: '',
	jetstream: '',
	syncCeiling: 200,
	plcDirectory: 'https://plc.directory',
	appview: 'https://public.api.bsky.app',
	handleHost: ''
})

const status = ref({})
const loading = ref(false)
const rotating = ref(false)
const crawling = ref(false)

const blocklistKind = ref('host')
const blocklistValue = ref('')
const blocklistReason = ref('')

const relays = computed(() => {
	try {
		return JSON.parse(settings.relaysJson)
	} catch {
		return settings.relaysJson.split(/[\n,]+/).map(s => s.trim()).filter(Boolean)
	}
})

onMounted(async () => {
	await loadSettings()
	await loadStatus()
})

async function loadSettings() {
	try {
		const response = await api.get('/api/admin/atproto/settings')
		Object.assign(settings, response.data)
		settings.relaysJson = JSON.stringify(settings.relays || [], null, 2)
		settings.handleHost = response.data.handle_host || ''
	} catch (error) {
		console.error('Failed to load settings:', error)
	}
}

async function loadStatus() {
	try {
		const response = await api.get('/api/admin/atproto/status')
		status.value = response.data
	} catch (error) {
		console.error('Failed to load status:', error)
	}
}

async function saveSettings() {
	loading.value = true
	try {
		await api.post('/api/admin/atproto/settings', {
			enabled: settings.enabled,
			relays: relays.value,
			jetstream: settings.jetstream,
			syncCeiling: settings.syncCeiling,
			plcDirectory: settings.plcDirectory,
			appview: settings.appview
		})
		await loadStatus()
	} catch (error) {
		console.error('Failed to save settings:', error)
	} finally {
		loading.value = false
	}
}

async function saveRelays() {
	settings.relays = relays.value
	await saveSettings()
}

async function rotateKey() {
	rotating.value = true
	try {
		await api.post('/api/admin/atproto/rotate-key')
		await loadStatus()
	} catch (error) {
		console.error('Failed to rotate key:', error)
	} finally {
		rotating.value = false
	}
}

async function requestCrawl() {
	crawling.value = true
	try {
		await api.post('/api/admin/atproto/request-crawl')
		await loadStatus()
	} catch (error) {
		console.error('Failed to request crawl:', error)
	} finally {
		crawling.value = false
	}
}

async function addToBlocklist() {
	if (!blocklistValue.value) return
	try {
		if (blocklistKind.value === 'host') {
			await api.post('/api/admin/atproto/block-host', { host: blocklistValue.value })
		} else {
			await api.post('/api/admin/atproto/block-did', { did: blocklistValue.value })
		}
		blocklistValue.value = ''
		blocklistReason.value = ''
		await loadStatus()
	} catch (error) {
		console.error('Failed to add to blocklist:', error)
	}
}

async function removeFromBlocklist(kind, value) {
	try {
		await api.post('/api/admin/atproto/unblock', { kind, value })
		await loadStatus()
	} catch (error) {
		console.error('Failed to remove from blocklist:', error)
	}
}

const formatDate = (dateStr) => {
	if (!dateStr) return ''
	return new Date(dateStr).toLocaleString()
}
</script>

<style scoped>
.atproto-admin {
	max-width: 800px;
	margin: 0 auto;
	padding: 1rem;
}

.admin-section {
	background: var(--card-bg);
	border: 1px solid var(--border-color);
	border-radius: 8px;
	padding: 1.5rem;
	margin-bottom: 1.5rem;
}

.admin-section h2 {
	margin-top: 0;
	margin-bottom: 1rem;
}

.admin-section h3 {
	margin-top: 1.5rem;
	margin-bottom: 1rem;
	color: var(--text-secondary);
}

.setting-row {
	margin-bottom: 1.5rem;
}

.setting-row label {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	font-weight: 500;
}

.setting-row input[type="text"],
.setting-row input[type="number"],
.setting-row textarea {
	padding: 0.5rem;
	border: 1px solid var(--border-color);
	border-radius: 4px;
	font-family: inherit;
	width: 100%;
	max-width: 500px;
}

.setting-row input[readonly] {
	background: var(--bg-secondary);
	color: var(--text-muted);
}

.setting-description {
	margin: 0.25rem 0 0 0;
	font-size: 0.875rem;
	color: var(--text-muted);
	line-height: 1.5;
}

.status-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
	gap: 1rem;
	margin-bottom: 1.5rem;
}

.status-card {
	background: var(--bg-secondary);
	border-radius: 8px;
	padding: 1rem;
	text-align: center;
}

.status-card h4 {
	margin: 0 0 0.5rem 0;
	font-size: 0.875rem;
	color: var(--text-secondary);
}

.status-value {
	margin: 0;
	font-size: 2rem;
	font-weight: 700;
	color: var(--primary-color);
}

.status-detail {
	margin-bottom: 1rem;
	padding: 0.75rem;
	background: var(--bg-secondary);
	border-radius: 8px;
}

.status-detail h4 {
	margin: 0 0 0.25rem 0;
	font-size: 0.875rem;
}

.blocklist-form {
	display: flex;
	gap: 0.5rem;
	margin-bottom: 1rem;
	flex-wrap: wrap;
}

.blocklist-form select,
.blocklist-form input {
	padding: 0.5rem;
	border: 1px solid var(--border-color);
	border-radius: 4px;
}

.blocklist-form input[type="text"]:first-of-type {
	flex: 1;
	min-width: 200px;
}

.blocklist-table {
	width: 100%;
	border-collapse: collapse;
}

.blocklist-table th,
.blocklist-table td {
	padding: 0.75rem;
	text-align: left;
	border-bottom: 1px solid var(--border-color);
}

.blocklist-table th {
	font-weight: 600;
	color: var(--text-secondary);
}
</style>