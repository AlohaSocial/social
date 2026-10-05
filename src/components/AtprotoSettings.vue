<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="atproto-settings">
		<NcLoadingIcon v-if="loading" :size="32" />

		<p v-else-if="!status.enabled" class="atproto-settings__hint">
			{{ t('social', 'This server has not enabled Bluesky yet. Your Fediverse account and posts are unchanged.') }}
		</p>

		<template v-else-if="status.account">
			<p class="atproto-settings__connected">
				{{ t('social', 'Connected to @{handle}', { handle: status.account.handle }) }}
			</p>
			<p class="atproto-settings__hint">
				{{ t('social', 'Public posts can be mirrored to Bluesky when this server enables mirroring. Direct, followers-only and unlisted posts never leave Aloha Social.') }}
			</p>
			<p v-if="status.account.lastError" class="atproto-settings__error" role="alert">
				{{ status.account.lastError }}
			</p>
			<NcButton variant="error" :disabled="saving" @click="unlink">
				{{ t('social', 'Disconnect Bluesky') }}
			</NcButton>
		</template>

		<form v-else @submit.prevent="link">
			<p class="atproto-settings__hint">
				{{ t('social', 'Link a Bluesky account to follow Bluesky profiles here and, if this server enables it, mirror public posts. Create an app password in Bluesky; never enter your normal password.') }}
			</p>
			<NcTextField
				v-model.trim="handle"
				:label="t('social', 'Bluesky username or handle')"
				placeholder="alice.bsky.social"
				:disabled="saving"
				required />
			<NcTextField
				v-model.trim="pds"
				:label="t('social', 'PDS server (optional)')"
				placeholder="https://bsky.social"
				:description="t('social', 'Leave empty to use the PDS resolved from the handle. Self-hosted PDS URLs can be entered separately.')"
				:disabled="saving" />
			<NcPasswordField
				v-model="appPassword"
				:label="t('social', 'Bluesky app password')"
				:disabled="saving"
				required />
			<p v-if="error" class="atproto-settings__error" role="alert">
				{{ error }}
			</p>
			<NcButton type="submit" variant="primary" :disabled="saving || handle === '' || appPassword === ''">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Connect Bluesky') }}
			</NcButton>
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import logger from '../services/logger.js'

export default {
	name: 'AtprotoSettings',
	components: { NcButton, NcLoadingIcon, NcPasswordField, NcTextField },
	data: () => ({ loading: true, saving: false, handle: '', pds: '', appPassword: '', error: '', status: { enabled: false, account: null } }),
	mounted() {
		this.load()
	},

	methods: {
		t,
		url(path = '') {
			return generateUrl('apps/social/api/v1/atproto' + path)
		},

		async load() {
			try {
				this.status = (await axios.get(this.url())).data ?? this.status
			} catch (error) {
				this.error = t('social', 'Could not load your Bluesky connection')
				logger.error('could not load AT-Proto status', { error })
			} finally {
				this.loading = false
			}
		},

		async link() {
			this.saving = true
			this.error = ''
			try {
				await axios.post(this.url('/link'), { handle: this.handle, pds: this.pds, appPassword: this.appPassword })
				this.appPassword = ''
				await this.load()
			} catch (error) {
				this.error = error?.response?.data?.message ?? t('social', 'Could not connect Bluesky')
			} finally {
				this.saving = false
			}
		},

		async unlink() {
			this.saving = true
			this.error = ''
			try {
				await axios.delete(this.url())
				this.status.account = null
			} catch (error) {
				this.error = error?.response?.data?.message ?? t('social', 'Could not disconnect Bluesky')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.atproto-settings__hint, .atproto-settings__connected, .atproto-settings__error { margin: 0 0 12px; }

.atproto-settings__error { color: var(--color-error); }
</style>
