<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="contact-settings">
		<NcCheckboxRadioSwitch
			type="switch"
			:modelValue="locked"
			:disabled="!loaded || saving"
			:description="t('social', 'Somebody who wants to follow you asks first, and waits at the top of Activities until you answer.')"
			@update:modelValue="setLocked">
			{{ t('social', 'Approve who follows you') }}
		</NcCheckboxRadioSwitch>
		<div id="direct-messages" class="contact-settings__part">
			<h5 class="contact-settings__title">
				{{ t('social', 'Direct messages') }}
			</h5>
			<DirectMessagesSettings />
		</div>
		<div id="notification-policy" class="contact-settings__part">
			<h5 class="contact-settings__title">
				{{ t('social', 'Mentions, likes and follows') }}
			</h5>
			<NotificationPolicySettings />
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { mapStores } from 'pinia'
import DirectMessagesSettings from './DirectMessagesSettings.vue'
import NotificationPolicySettings from './NotificationPolicySettings.vue'
import { useAccountStore } from '../store/account.js'

/**
 * Who may reach the reader, in one place: following them, writing to them
 * directly, and reaching their Activities without waiting for review.
 */
export default {
	name: 'ContactSettings',

	components: {
		DirectMessagesSettings,
		NcCheckboxRadioSwitch,
		NotificationPolicySettings,
	},

	data() {
		return {
			saving: false,
		}
	},

	computed: {
		...mapStores(useAccountStore),

		loaded() {
			return this.accountStore.credentials !== null
		},

		locked() {
			return Boolean(this.accountStore.credentials?.locked)
		},
	},

	mounted() {
		if (!this.loaded) {
			this.accountStore.fetchCredentials()
		}
	},

	methods: {
		t,

		/** @param {boolean} locked whether new followers need approval */
		async setLocked(locked) {
			this.saving = true
			try {
				await this.accountStore.updateCredentials({ locked })
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.contact-settings__part {
	margin-block-start: 20px;
}

.contact-settings__title {
	margin: 0 0 4px;
	font-weight: 600;
}
</style>
