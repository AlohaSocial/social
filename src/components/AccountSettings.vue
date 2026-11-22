<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="account-settings">
		<p v-if="!loaded" class="account-settings__loading">
			{{ t('social', 'Loading your account …') }}
		</p>
		<template v-else>
			<!-- not a field: the display name belongs to the Nextcloud account,
			     whose backend (LDAP, SAML) may own it -->
			<p class="account-settings__name">
				{{ t('social', 'You post as {name}.', { name: displayName }) }}
				<a class="account-settings__link" :href="personalSettings">
					{{ t('social', 'Change your name in your Nextcloud settings') }} ↗
				</a>
			</p>

			<!-- each switch says what it does; the Mastodon names (discoverable, indexable) mean little to readers -->

			<NcCheckboxRadioSwitch
				v-model="draft.discoverable"
				type="switch"
				class="account-settings__switch"
				:description="t('social', 'Lets other servers list you in their directories and recommend you to people who do not know you yet.')">
				{{ t('social', 'Suggest this account to others') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch
				v-model="draft.indexable"
				type="switch"
				class="account-settings__switch"
				:description="t('social', 'Allows search engines and full-text search on other servers to include what you post publicly.')">
				{{ t('social', 'Let search find your public posts') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch
				v-model="draft.hideCollections"
				type="switch"
				class="account-settings__switch"
				:description="t('social', 'Others still see how many followers you have and how many accounts you follow, but not who they are. Other servers are told the numbers only.')">
				{{ t('social', 'Hide my followers and following') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch
				v-model="draft.bot"
				type="switch"
				class="account-settings__switch"
				:description="t('social', 'Marks the account as run by a program rather than a person, so readers know not to expect an answer.')">
				{{ t('social', 'This is an automated account') }}
			</NcCheckboxRadioSwitch>

			<!-- the composer's four audiences, in its words -->
			<NcSelect
				v-model="draft.privacy"
				class="account-settings__privacy"
				:inputLabel="t('social', 'Who sees new posts')"
				:options="visibilities"
				:clearable="false"
				:searchable="false"
				label="text">
				<template #option="option">
					<span class="account-settings__privacy-option">
						<VisibilityIcon :visibility="option.id" :size="20" />
						<span>
							{{ option.text }}
							<span class="account-settings__hint">{{ option.description }}</span>
						</span>
					</span>
				</template>
			</NcSelect>
			<p class="account-settings__hint account-settings__hint--block">
				{{ t('social', 'The composer starts every new post with this audience. You can still change it for any single post.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { translate as t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import visibilitiesInfo from './Visibility/VisibilitiesInfos.js'
import VisibilityIcon from './Visibility/VisibilityIcon.vue'

/**
 * The account settings `update_credentials` takes: the four switches and the
 * default audience. The bio, banner and metadata fields are edited in the
 * profile's own editor.
 *
 * Each change is saved as it is made, and only what changed is sent, since the
 * route writes every field it is given.
 */
export default {
	name: 'AccountSettings',

	components: {
		NcCheckboxRadioSwitch,
		NcSelect,
		VisibilityIcon,
	},

	data() {
		return {
			visibilities: visibilitiesInfo,
			draft: {
				discoverable: false,
				indexable: false,
				hideCollections: false,
				bot: false,
				/** @type {import('./Visibility/VisibilitiesInfos.js').Visibility|null} */
				privacy: null,
			},

			saving: false,
		}
	},

	computed: {
		...mapStores(useAccountStore),

		/** @return {object|null} the CredentialAccount, once it has come */
		credentials() {
			return this.accountStore.credentials
		},

		/** @return {boolean} */
		loaded() {
			return this.credentials !== null
		},

		/**
		 * @return {string} the name this account posts under, falling back to
		 *                  the handle where Nextcloud holds no display name
		 */
		displayName() {
			return this.credentials?.display_name || this.credentials?.username || ''
		},

		/** @return {string} where the name actually lives */
		personalSettings() {
			return generateUrl('/settings/user')
		},

		/**
		 * The request as it would be sent now: the fields whose draft differs
		 * from what the server holds, in the route's own names.
		 *
		 * @return {object}
		 */
		payload() {
			if (!this.loaded) {
				return {}
			}
			const changes = {}
			const stored = this.credentials
			for (const flag of ['discoverable', 'indexable', 'bot']) {
				if (this.draft[flag] !== Boolean(stored[flag])) {
					changes[flag] = this.draft[flag]
				}
			}
			if (this.draft.hideCollections !== Boolean(stored.source?.hide_collections)) {
				changes.hide_collections = this.draft.hideCollections
			}
			const privacy = this.draft.privacy?.id
			if (privacy && privacy !== this.accountStore.defaultPostVisibility) {
				changes.source = { privacy }
			}

			return changes
		},

		/** @return {boolean} whether there is anything worth sending */
		changed() {
			return Object.keys(this.payload).length > 0
		},
	},

	watch: {
		// a change is saved as soon as it is made
		draft: {
			deep: true,
			handler() {
				this.save()
			},
		},

		// the draft follows the store, except while a save is on its way: its
		// answer predates any change made since and would throw that away
		credentials: {
			immediate: true,
			handler(credentials) {
				if (credentials && !this.saving) {
					this.reset(credentials)
				}
			},
		},
	},

	mounted() {
		if (!this.loaded) {
			this.accountStore.fetchCredentials()
		}
	},

	methods: {
		t,

		/**
		 * @param {object} credentials the CredentialAccount to start from
		 */
		reset(credentials) {
			const privacy = this.accountStore.defaultPostVisibility || 'public'
			this.draft = {
				discoverable: Boolean(credentials.discoverable),
				indexable: Boolean(credentials.indexable),
				hideCollections: Boolean(credentials.source?.hide_collections),
				bot: Boolean(credentials.bot),
				privacy: visibilitiesInfo.find(({ id }) => id === privacy) ?? null,
			}
		},

		/**
		 * Sends what changed. A change made while a save is on its way waits
		 * for that one to answer and goes next, so two requests never race;
		 * a refused change puts the switch back to what the server holds.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			if (!this.changed || this.saving) {
				return
			}
			this.saving = true
			try {
				const saved = await this.accountStore.updateCredentials(this.payload)
				if (!saved && this.credentials) {
					this.reset(this.credentials)
				}
			} finally {
				this.saving = false
			}
			if (this.changed) {
				await this.save()
			}
		},
	},
}
</script>

<style scoped lang="scss">
.account-settings {
	display: flex;
	flex-direction: column;
	gap: 8px;

	&__loading {
		color: var(--color-text-maxcontrast);
	}

	&__name {
		max-width: 420px;
		margin-bottom: 8px;
		color: var(--color-text-maxcontrast);
	}

	&__link {
		display: block;
		text-decoration: underline;
	}

	&__hint {
		display: block;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		font-weight: normal;

		&--block {
			margin: 4px 0 0;
		}
	}

	&__privacy {
		max-width: 420px;
		margin-top: 8px;
	}

	&__privacy-option {
		display: flex;
		align-items: center;
		gap: 8px;
		padding-block: 4px;
	}
}
</style>
