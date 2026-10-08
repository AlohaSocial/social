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
			<!--
				Not a field. The display name belongs to the Nextcloud account
				and the actor copies it, so editing it here was a remote
				control for a setting that lives somewhere else -- one that
				silently did nothing on the accounts whose backend owns the
				name, which is every LDAP or SAML instance.
			-->
			<p class="account-settings__name">
				{{ t('social', 'You post as {name}.', { name: displayName }) }}
				<a class="account-settings__link" :href="personalSettings">
					{{ t('social', 'Change your name in your Nextcloud settings') }} ↗
				</a>
			</p>

			<!-- the settings that shape how others reach the account; each is
			     one sentence about what it does, since the Mastodon names
			     (locked, discoverable, indexable) mean nothing to anybody -->
			<NcCheckboxRadioSwitch
				v-model="draft.locked"
				type="switch"
				class="account-settings__switch"
				:description="t('social', 'Somebody who wants to follow you asks first, and waits under Follow requests until you answer.')">
				{{ t('social', 'Approve who follows you') }}
			</NcCheckboxRadioSwitch>

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
				v-model="draft.bot"
				type="switch"
				class="account-settings__switch"
				:description="t('social', 'Marks the account as run by a program rather than a person, so readers know not to expect an answer.')">
				{{ t('social', 'This is an automated account') }}
			</NcCheckboxRadioSwitch>

			<!-- the same four audiences the composer offers, in its words, so
			     what is chosen here is what its button will say -->
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
 * The account as a form: the settings `update_credentials` takes that are
 * about the account rather than the profile. The name, the four flags and the
 * default audience are here; the bio, the banner and the metadata fields stay
 * in the profile's own editor, because they are what a visitor reads and are
 * edited where they are seen.
 *
 * Each change is saved as it is made, like every other setting on the page;
 * a Save button here alone was a form that looked saved and was not. Only
 * what changed is sent. The route writes only the fields it was given, and a
 * form that sent everything back would re-save a display name into a backend
 * that owns it (LDAP, say) and be refused for a switch it did not mean to
 * touch.
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
				locked: false,
				discoverable: false,
				indexable: false,
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
			for (const flag of ['locked', 'discoverable', 'indexable', 'bot']) {
				if (this.draft[flag] !== Boolean(stored[flag])) {
					changes[flag] = this.draft[flag]
				}
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

		// the form follows the store: what the server holds is the truth the
		// draft starts from. Not while a save is on its way: its answer is
		// the server's copy from before any change made since, and taking it
		// would throw that change away.
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
				locked: Boolean(credentials.locked),
				discoverable: Boolean(credentials.discoverable),
				indexable: Boolean(credentials.indexable),
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
