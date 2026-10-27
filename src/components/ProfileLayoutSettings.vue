<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<form class="profile-layout-settings" @submit.prevent="save">
		<p v-if="!account" role="status">
			{{ t('social', 'Loading your account …') }}
		</p>
		<template v-else>
			<label for="profile-default-tab">{{ t('social', 'Open my profile on') }}</label>
			<select
				id="profile-default-tab"
				v-model="defaultTab"
				:disabled="saving"
				@change="saved = false">
				<option value="posts">
					{{ t('social', 'Posts') }}
				</option>
				<option v-if="tabs.includes('photos')" value="photos">
					{{ t('social', 'Photos') }}
				</option>
				<option v-if="tabs.includes('videos')" value="videos">
					{{ t('social', 'Videos') }}
				</option>
			</select>
			<fieldset :disabled="saving">
				<legend>{{ t('social', 'Profile tabs') }}</legend>
				<p>{{ t('social', 'Posts always stay available. Hiding a tab changes navigation, not who can read your posts.') }}</p>
				<NcCheckboxRadioSwitch
					v-for="option in optionalTabs"
					:key="option.id"
					:modelValue="tabs.includes(option.id)"
					type="switch"
					@update:modelValue="toggleTab(option.id, $event)">
					{{ option.label }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<p v-if="error" role="alert">
				{{ error }}
			</p>
			<p v-if="saved" role="status">
				{{ t('social', 'Profile layout saved') }}
			</p>
			<NcButton type="submit" variant="primary" :disabled="saving">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Save') }}
			</NcButton>
		</template>
	</form>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { useAccountStore } from '../store/account.js'
import { errorOf } from '../utils/serverError.js'

const ALL_TABS = ['posts', 'photos', 'videos', 'tagged', 'collections']

export default {
	name: 'ProfileLayoutSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon },
	data: () => ({ defaultTab: 'posts', tabs: [...ALL_TABS], saving: false, error: '', saved: false }),
	computed: {
		...mapStores(useAccountStore),
		account() { return this.accountStore.currentAccount },
		optionalTabs() {
			return [
				{ id: 'photos', label: t('social', 'Photos') },
				{ id: 'videos', label: t('social', 'Videos') },
				{ id: 'tagged', label: t('social', 'Tagged') },
				{ id: 'collections', label: t('social', 'Collections') },
			]
		},
	},

	watch: {
		'account.id': {
			immediate: true,
			handler() {
				const layout = this.account?.profile_layout
				this.defaultTab = layout?.default_tab || 'posts'
				this.tabs = Array.isArray(layout?.tabs) ? [...layout.tabs] : [...ALL_TABS]
			},
		},
	},

	methods: {
		t,
		toggleTab(id, enabled) {
			this.tabs = ALL_TABS.filter((tab) => tab === id ? enabled : this.tabs.includes(tab))
			if (!this.tabs.includes(this.defaultTab)) {
				this.defaultTab = 'posts'
			}
			this.saved = false
		},

		async save() {
			if (this.saving) {
				return
			}
			this.saving = true
			this.error = ''
			this.saved = false
			const account = this.account
			try {
				const { data } = await axios.put(generateUrl('apps/social/api/v1/account/profile-layout'), {
					layout: { default_tab: this.defaultTab, tabs: this.tabs },
				})
				if (!data?.result?.layout) {
					throw new Error(t('social', 'The profile layout was not saved'))
				}
				account.profile_layout = data.result.layout
				this.saved = true
			} catch (error) {
				this.error = errorOf(error, t('social', 'Could not save the profile layout')).text
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.profile-layout-settings {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 12px;

	select {
		max-width: 100%;
		min-width: min(240px, 100%);
	}

	fieldset {
		min-width: 0;
		width: 100%;
	}
}
</style>
