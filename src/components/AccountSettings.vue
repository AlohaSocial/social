<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<form class="account-settings" @submit.prevent="save">
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
			<NcCheckboxRadioSwitch v-model="draft.locked" type="switch" class="account-settings__switch">
				{{ t('social', 'Approve who follows you') }}
				<span class="account-settings__hint">
					{{ t('social', 'Somebody who wants to follow you asks first, and waits under Follow requests until you answer.') }}
				</span>
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch v-model="draft.discoverable" type="switch" class="account-settings__switch">
				{{ t('social', 'Suggest this account to others') }}
				<span class="account-settings__hint">
					{{ t('social', 'Lets other servers list you in their directories and recommend you to people who do not know you yet.') }}
				</span>
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch v-model="draft.indexable" type="switch" class="account-settings__switch">
				{{ t('social', 'Let search find your public posts') }}
				<span class="account-settings__hint">
					{{ t('social', 'Allows search engines and full-text search on other servers to include what you post publicly.') }}
				</span>
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch v-model="draft.bot" type="switch" class="account-settings__switch">
				{{ t('social', 'This is an automated account') }}
				<span class="account-settings__hint">
					{{ t('social', 'Marks the account as run by a program rather than a person, so readers know not to expect an answer.') }}
				</span>
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

			<!-- PeerTube's three NSFW policies, which Mastodon's own reading
			     preference already has names for. Saved on its own the moment
			     it changes: it is a fact about reading and has nothing to do
			     with the account fields above, which go out as one Mastodon
			     credentials update. -->
			<NcSelect
				v-model="sensitiveChoice"
				class="account-settings__privacy"
				:inputLabel="t('social', 'Media marked sensitive')"
				:options="sensitiveOptions"
				:clearable="false"
				:searchable="false"
				:disabled="savingSensitive"
				label="text"
				@update:modelValue="saveSensitive" />
			<p class="account-settings__hint account-settings__hint--block">
				{{ t('social', 'A content warning is a different thing: it is the author saying something about the whole post in their own words, and it always covers its post.') }}
			</p>

			<div class="account-settings__actions">
				<NcButton
					variant="primary"
					type="submit"
					:disabled="!changed || saving">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ saving ? t('social', 'Saving …') : t('social', 'Save') }}
				</NcButton>
			</div>
		</template>
	</form>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import { translate as t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useServerData } from '../composables/useServerData.js'
import { showError, showSuccess } from '../services/toast.js'
import visibilitiesInfo from './Visibility/VisibilitiesInfos.js'
import VisibilityIcon from './Visibility/VisibilityIcon.vue'

/**
 * The account as a form: the settings `update_credentials` takes that are
 * about the account rather than the profile. The name, the four flags and the
 * default audience are here; the bio, the banner and the metadata fields stay
 * in the profile's own editor, because they are what a visitor reads and are
 * edited where they are seen.
 *
 * Only what changed is sent. The route writes only the fields it was given,
 * and a form that sent everything back would re-save a display name into a
 * backend that owns it (LDAP, say) and be refused for a switch it did not
 * mean to touch.
 */
export default {
	name: 'AccountSettings',

	components: {
		ContentSave,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
		VisibilityIcon,
	},

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	data() {
		const serverData = useServerData().serverData.value

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

			/**
			 * What this account chose, or '' for "follow the instance".
			 *
			 * Out of the page rather than a request: the effective policy is
			 * already there because the timeline needs it before it draws, and
			 * the choice behind it travels with it.
			 */
			sensitive: serverData?.nsfwChoice ?? '',
			savingSensitive: false,

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

		/**
		 * The three policies, plus the fourth thing that is not one of them:
		 * following the instance, which is different from choosing whatever
		 * the instance happens to do today.
		 *
		 * @return {Array<{id: string, text: string}>}
		 */
		sensitiveOptions() {
			return [
				{ id: '', text: t('social', 'Whatever this server does') },
				{ id: 'show_all', text: t('social', 'Show it like anything else') },
				{ id: 'default', text: t('social', 'Cover it, one press away') },
				{ id: 'hide_all', text: t('social', 'Do not show it; I will open the post') },
			]
		},

		/** @return {{id: string, text: string}} */
		sensitiveChoice: {
			get() {
				return this.sensitiveOptions.find((option) => option.id === this.sensitive)
					?? this.sensitiveOptions[0]
			},

			set(option) {
				this.sensitive = option?.id ?? ''
			},
		},
	},

	watch: {
		// the form follows the store: what the server holds is the truth the
		// draft starts from, and again after a save answers
		credentials: {
			immediate: true,
			handler(credentials) {
				if (credentials) {
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
		 * Writes the reading preference by itself.
		 *
		 * It takes effect on the next page rather than at once: the policy is
		 * read out of the page's own initial state, because it decides what
		 * the first screenful looks like and a timeline that uncovered itself
		 * a moment after drawing would be worse than either policy.
		 *
		 * @return {Promise<void>}
		 */
		async saveSensitive() {
			this.savingSensitive = true
			try {
				await axios.put(generateUrl('apps/social/api/v1/preferences'), {
					expandMedia: this.sensitive,
				})
				showSuccess(t('social', 'Saved. It applies the next time this page loads.'))
			} catch {
				showError(t('social', 'Could not save that setting'))
			} finally {
				this.savingSensitive = false
			}
		},

		async save() {
			if (!this.changed || this.saving) {
				return
			}
			this.saving = true
			try {
				const saved = await this.accountStore.updateCredentials(this.payload)
				// the store said what went wrong; a success is this form's to say
				if (saved) {
					showSuccess(t('social', 'Your account settings have been saved'))
				}
			} finally {
				this.saving = false
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

	&__switch {
		// the sentence under each switch is part of its label, so a click on
		// it flips the switch too; it only has to look like the fine print
		:deep(.checkbox-radio-switch__content) {
			flex-direction: column;
			align-items: flex-start;
			gap: 0;
		}
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

	&__actions {
		display: flex;
		justify-content: flex-end;
		margin-top: 8px;
	}
}
</style>
