<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="delete-account">
		<p class="delete-account__what">
			{{ t('social', 'Everything you posted from here is deleted, your followers and the people you follow are let go, and every server that knew this account is told it is gone. It cannot be undone, and there is no way to get any of it back afterwards — take an archive from the Export button above first if you might want one.') }}
		</p>
		<p v-if="external" class="delete-account__what">
			{{ t('social', 'Your account on this server was made for Aloha Social, so it is deleted too, and you are signed out. The handle you are deleting is held for an hour so that nobody else can take it the moment you let it go.') }}
		</p>
		<p v-else class="delete-account__what">
			{{ t('social', 'Your Nextcloud account is not touched: you stay signed in to everything else, and you can make a new Aloha Social account straight away. The handle you are deleting is held for an hour so that nobody else can take it the moment you let it go, so a new account needs a different one.') }}
		</p>
		<p class="delete-account__what">
			{{ t('social', 'A post that already reached somebody else\'s server is deleted by asking that server to delete it. Almost all of them do; none of them can be made to.') }}
		</p>

		<NcButton v-if="!asking" variant="error" @click="asking = true">
			<template #icon>
				<IconDeleteOutline :size="20" />
			</template>
			{{ t('social', 'Delete my Aloha Social account') }}
		</NcButton>

		<template v-else>
			<p class="delete-account__confirm">
				{{ t('social', 'Type {handle} to confirm that this is the account you mean.', { handle: handle }) }}
			</p>
			<NcTextField
				v-model="typed"
				class="delete-account__field"
				:label="t('social', 'The handle of the account to delete')"
				:placeholder="handle"
				:disabled="deleting" />
			<div class="delete-account__actions">
				<NcButton
					variant="error"
					:disabled="deleting || typed.trim() === ''"
					@click="remove">
					<template #icon>
						<NcLoadingIcon v-if="deleting" :size="20" />
						<IconDeleteOutline v-else :size="20" />
					</template>
					{{ t('social', 'Delete it for good') }}
				</NcButton>
				<NcButton :disabled="deleting" @click="cancel">
					{{ t('social', 'Keep my account') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { mapStores } from 'pinia'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconDeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import { useAccountStore } from '../store/account.js'
import { useSettingsStore } from '../store/settings.js'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'

/**
 * Deleting your own Social account, and the hosting account when it exists
 * only to provide Social.
 *
 * `occ social:account:delete` was the only way to do this, so somebody who
 * wanted their fediverse presence gone had to ask an administrator — which
 * means explaining to a colleague why.
 *
 * Two things stand in front of it, and neither is a password: an account
 * signed in through SSO has none to give. The button asks first, and then the
 * handle has to be typed out. The page is reloaded afterwards rather than
 * patched, because what is left of this session is a client holding the state
 * of an account that no longer exists.
 */
export default {
	name: 'DeleteAccount',

	components: {
		IconDeleteOutline,
		NcButton,
		NcLoadingIcon,
		NcTextField,
	},

	data() {
		return {
			asking: false,
			typed: '',
			deleting: false,
		}
	},

	computed: {
		...mapStores(useAccountStore, useSettingsStore),

		/** @return {boolean} whether the reader is a self-registered external user */
		external() {
			return Boolean(this.settingsStore.getServerData?.externalMedia)
		},

		/** @return {string} the handle being deleted, as the server writes it */
		handle() {
			const credentials = this.accountStore.credentials
			if (!credentials) {
				return ''
			}

			return credentials.acct ?? credentials.username ?? ''
		},
	},

	methods: {
		t,

		cancel() {
			this.asking = false
			this.typed = ''
		},

		/** @return {Promise<void>} */
		async remove() {
			this.deleting = true
			try {
				const response = await axios.post(
					generateUrl('apps/social/api/v1/account/delete'),
					{ confirm: this.typed.trim() },
				)
				if (response.data?.result?.userDeleted) {
					// a self-registered external user: the whole account is
					// gone with the Social one, and so is the session
					window.location.assign(generateUrl('apps/social/account-deleted'))
					return
				}
				// not a redirect and not a state change: what is left here is a
				// client holding an account that no longer exists, and the
				// setup screen is what the app shows somebody without one
				window.location.reload()
			} catch (error) {
				logger.error('could not delete the account', { error })
				showError(error?.response?.data?.error || t('social', 'Could not delete your account'))
				this.deleting = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.delete-account__what {
	margin-block-end: 8px;
}

.delete-account__confirm {
	margin-block: 8px 4px;
	font-weight: bold;
}

.delete-account__field {
	max-width: 320px;
}

.delete-account__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-block-start: 8px;
}
</style>
