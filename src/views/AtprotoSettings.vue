<!-- SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<section class="atproto-settings">
		<h2>{{ t('social', 'Bluesky (AT Protocol)') }}</h2>
		<p>{{ t('social', 'When your administrator enables AT Protocol, public posts are published automatically. Unlisted, followers-only and direct posts stay private.') }}</p>
		<p v-if="error" role="alert">
			{{ error }}
		</p>
		<p v-if="loading">
			{{ t('social', 'Loading …') }}
		</p>
		<template v-else-if="identity?.did">
			<dl>
				<dt>{{ t('social', 'Handle') }}</dt><dd>@{{ identity.handle }}</dd>
				<dt>{{ t('social', 'DID') }}</dt><dd><code>{{ identity.did }}</code></dd>
				<dt>{{ t('social', 'Status') }}</dt><dd>{{ identity.state }}</dd>
			</dl>
			<a
				v-if="identity.state === 'active'"
				:href="identity.profileUrl"
				target="_blank"
				rel="noopener noreferrer">{{ t('social', 'View on Bluesky') }}</a>
			<p>{{ t('social', 'Your recovery phrase can be retrieved once. Store all 24 words securely before closing this page.') }}</p>
			<NcButton :disabled="busy || Boolean(recoveryPhrase)" @click="retrieveRecovery">
				{{ t('social', 'Retrieve recovery phrase') }}
			</NcButton>
			<p v-if="recoveryPhrase" class="recovery-phrase">
				<code>{{ recoveryPhrase }}</code>
			</p>
		</template>
		<p v-else>
			{{ t('social', 'Your identity is created when your first public post is processed. Registration may take a few minutes.') }}
		</p>
	</section>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { useApi } from '../composables/useApi.js'
const api = useApi()
/** @type {import('vue').Ref<{did?: string, handle?: string, state?: string, profileUrl?: string}|null>} */
const identity = ref(null)
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const recoveryPhrase = ref('')
onMounted(async () => {
	try {
		const response = await api.get('/api/atproto/identity')
		identity.value = response.data
	} catch {
		error.value = t('social', 'Could not load your AT Protocol identity.')
	} finally {
		loading.value = false
	}
})
/** Retrieve the one-time phrase only after an explicit user action. */
async function retrieveRecovery() {
	busy.value = true
	error.value = ''
	try {
		const response = await api.post('/api/atproto/identity/recovery', {})
		recoveryPhrase.value = response.data.recoveryPhrase
	} catch {
		error.value = t('social', 'The recovery phrase is unavailable or has already been retrieved.')
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.atproto-settings { max-width: 700px; padding: 24px; }
dd { margin-bottom: 12px; overflow-wrap: anywhere; }
dt { font-weight: bold; }
.recovery-phrase { padding: 16px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); }
</style>
