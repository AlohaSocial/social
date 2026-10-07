<!-- SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<section class="atproto-admin">
		<h2>{{ t('social', 'Bluesky (AT Protocol)') }}</h2>
		<p>{{ t('social', 'The native PDS uses this instance’s public HTTPS domain. Configure wildcard handle discovery and the Firehose reverse proxy before enabling it.') }}</p>
		<p><code>{{ endpoint }}</code></p>
		<p v-if="error" role="alert">
			{{ error }}
		</p>
		<NcCheckboxRadioSwitch v-model="enabled" :disabled="busy">
			{{ t('social', 'Enable AT Protocol for public posts') }}
		</NcCheckboxRadioSwitch>
		<NcButton :disabled="busy" @click="save">
			{{ t('social', 'Save') }}
		</NcButton>
		<dl>
			<template v-for="(count, name) in counts" :key="name">
				<dt>{{ name }}</dt><dd>{{ count }}</dd>
			</template>
		</dl>
	</section>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { useApi } from '../composables/useApi.js'
const api = useApi()
const enabled = ref(false)
const endpoint = ref('')
const counts = ref({})
const busy = ref(true)
const error = ref('')
onMounted(load)
/** Load the real administrator configuration and persisted queue counts. */
async function load() {
	try {
		const settings = await api.get('/api/admin/atproto/settings')
		enabled.value = settings.data.enabled
		endpoint.value = settings.data.endpoint
		const status = await api.get('/api/admin/atproto/status')
		counts.value = status.data.counts
	} catch {
		error.value = t('social', 'Could not load AT Protocol administration settings.')
	} finally {
		busy.value = false
	}
}
/** Persist the instance-wide setting using the authenticated administrator session. */
async function save() {
	busy.value = true
	error.value = ''
	try {
		await api.post('/api/admin/atproto/settings', { enabled: enabled.value })
		await load()
	} catch {
		error.value = t('social', 'Could not save AT Protocol administration settings.')
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.atproto-admin { max-width: 700px; padding: 24px; }
dt { font-weight: bold; }
dd { margin-bottom: 12px; }
</style>
