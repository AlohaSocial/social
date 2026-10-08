<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<p class="social-admin__hint">
			{{ t('social', 'They sign in on the normal login page and can use Aloha Social and nothing else: no Files, no Talk, and nobody outside Aloha Social can find them.') }}
		</p>
		<NcNoteCard v-if="settings.enabled && !settings.restrictionIncludesExternals" type="warning">
			<p>{{ t('social', 'Aloha Social is limited to some groups, and external users are not in them, so they could register and then not open Aloha Social.') }}</p>
			<NcButton :disabled="saving" @click="allowInRestriction">
				{{ t('social', 'Let external users open Aloha Social') }}
			</NcButton>
		</NcNoteCard>

		<p class="external__count">
			{{ n('social', '%n external account', '%n external accounts', settings.count) }}
			<span class="external__of">{{ t('social', 'of at most {max}', { max: form.max }) }}</span>
		</p>

		<form class="external__form" @submit.prevent="save">
			<NcCheckboxRadioSwitch v-model="form.enabled" type="switch">
				{{ t('social', 'Let people register') }}
			</NcCheckboxRadioSwitch>

			<div class="external__numbers">
				<NcTextField
					v-model="form.max"
					type="number"
					min="0"
					max="1000000"
					:label="t('social', 'Maximum number of external accounts')"
					:helperText="t('social', 'Registration closes when this many exist.')" />
				<NcTextField
					v-model="form.quota"
					type="number"
					min="0"
					:label="t('social', 'Media each may upload (MB)')"
					:helperText="t('social', 'Pictures and videos they post. 0 is no limit.')" />
				<NcTextField
					v-model="form.minAge"
					type="number"
					min="0"
					max="99"
					:label="t('social', 'Minimum age')"
					:helperText="t('social', 'People confirm they are at least this old. 0 asks nothing.')" />
			</div>

			<fieldset class="external__mode">
				<legend>{{ t('social', 'Who gets an account') }}</legend>
				<NcCheckboxRadioSwitch
					v-model="form.mode"
					type="radio"
					value="open"
					name="external-mode">
					{{ t('social', 'Anybody who registers') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="form.mode"
					type="radio"
					value="approval"
					name="external-mode">
					{{ t('social', 'Anybody an administrator approves') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="form.mode"
					type="radio"
					value="invite"
					name="external-mode">
					{{ t('social', 'Only people with an invitation link') }}
				</NcCheckboxRadioSwitch>
				<p class="external__hint">
					{{ t('social', 'An invitation link admits its holder whichever you choose, without approval.') }}
				</p>
			</fieldset>

			<NcCheckboxRadioSwitch v-model="form.verifyEmail" type="switch">
				{{ t('social', 'Confirm the email address before the account is made') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="form.userInvites" type="switch">
				{{ t('social', 'Let everybody here send invitation links') }}
			</NcCheckboxRadioSwitch>

			<NcTextArea
				v-model="form.reserved"
				:label="t('social', 'Usernames nobody may register, one per line')"
				rows="3" />
			<NcTextArea
				v-model="form.signupNotice"
				:label="t('social', 'Additional registration and privacy information')"
				:helperText="t('social', 'Shown before people enter their details. Plain text only; adapt this information to your instance. It is not legal advice. When acceptance is required, changing this text creates a new notice version.')"
				rows="5"
				maxlength="4000" />
			<NcCheckboxRadioSwitch v-model="form.signupNoticeRequired" type="switch">
				{{ t('social', 'Require registrants to accept this notice') }}
			</NcCheckboxRadioSwitch>
			<p class="external__hint">
				{{ t('social', 'The accepted notice version and time are kept with the account until the account is deleted. This records acceptance; it does not make the notice legally sufficient.') }}
			</p>

			<div class="external__actions">
				<NcButton type="submit" variant="primary" :disabled="saving">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('social', 'Save') }}
				</NcButton>
			</div>
		</form>

		<div class="external__twofactor">
			<NcCheckboxRadioSwitch
				type="switch"
				:modelValue="twoFactor.enforced"
				:disabled="saving"
				@update:modelValue="setTwoFactor">
				{{ t('social', 'Require two-factor authentication') }}
			</NcCheckboxRadioSwitch>
			<p class="external__hint">
				{{ twoFactor.everybody
					? t('social', 'This server requires two-factor authentication of everybody. Switching this off exempts external users.')
					: t('social', 'External users then set up an authenticator app or a security key when they first log in. This is Nextcloud\'s own setting, the same as in Administration → Security.') }}
			</p>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { confirmPassword, externalUrl } from '../../services/externalApi.js'
import { showError, showSuccess } from '../../services/toast.js'

/**
 * Whether people without an account may register one for Social, how many,
 * with how much room for media, and how they are admitted.
 */
export default {
	name: 'ExternalUsersSection',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** `ExternalAdminState::current()` */
		external: {
			type: Object,
			required: true,
		},
	},

	emits: ['changed'],

	data() {
		return {
			form: this.formOf(this.external.settings),
			saving: false,
		}
	},

	computed: {
		/** @return {object} the settings as they now stand */
		settings() {
			return this.external.settings
		},

		/** @return {{enforced: boolean, everybody: boolean}} */
		twoFactor() {
			return this.external.twoFactor ?? { enforced: false, everybody: false }
		},
	},

	methods: {
		t,
		n,

		/**
		 * @param {object} settings what the server says
		 * @return {object} the same, as the form edits it
		 */
		formOf(settings) {
			return {
				enabled: settings.enabled,
				max: String(settings.max),
				quota: String(settings.quota),
				mode: settings.mode,
				verifyEmail: settings.verifyEmail,
				minAge: String(settings.minAge),
				reserved: (settings.reserved ?? []).join('\n'),
				userInvites: settings.userInvites,
				signupNotice: settings.signupNotice ?? '',
				signupNoticeRequired: Boolean(settings.signupNoticeRequired),
			}
		},

		/** @return {Promise<void>} */
		async save() {
			this.saving = true
			try {
				const { data } = await axios.post(externalUrl(), {
					enabled: this.form.enabled,
					max: parseInt(this.form.max, 10) || 0,
					quota: parseInt(this.form.quota, 10) || 0,
					mode: this.form.mode,
					verifyEmail: this.form.verifyEmail,
					minAge: parseInt(this.form.minAge, 10) || 0,
					reserved: this.form.reserved.split('\n').map((line) => line.trim()).filter(Boolean),
					userInvites: this.form.userInvites,
					signupNotice: this.form.signupNotice,
					signupNoticeRequired: this.form.signupNoticeRequired,
				})
				this.form = this.formOf(data)
				this.$emit('changed', { ...this.external, settings: data })
				showSuccess(t('social', 'Saved'))
			} catch (error) {
				showError(error?.response?.data?.message || t('social', 'Could not save the settings'))
			} finally {
				this.saving = false
			}
		},

		/** @return {Promise<void>} */
		async allowInRestriction() {
			this.saving = true
			try {
				const { data } = await axios.post(externalUrl('/restriction'))
				this.$emit('changed', { ...this.external, settings: data })
			} catch {
				showError(t('social', 'Could not change who may open Aloha Social'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * @param {boolean} enforced what the switch was moved to
		 * @return {Promise<void>}
		 */
		async setTwoFactor(enforced) {
			try {
				await confirmPassword()
			} catch {
				return
			}

			this.saving = true
			try {
				const { data } = await axios.post(externalUrl('/two-factor'), { enforced })
				this.$emit('changed', { ...this.external, twoFactor: data })
			} catch {
				showError(t('social', 'Could not change the two-factor setting'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.external {
	&__count {
		margin-block-end: calc(var(--default-grid-baseline) * 3);
		font-size: 1.2em;
		font-weight: bold;
	}

	&__of {
		font-weight: normal;
		color: var(--color-text-maxcontrast);
	}

	&__form {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 3);
		max-width: 720px;
	}

	&__numbers {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
		gap: calc(var(--default-grid-baseline) * 3);
	}

	&__mode {
		legend {
			font-weight: bold;
			margin-block-end: calc(var(--default-grid-baseline) * 1);
		}
	}

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__twofactor {
		max-width: 720px;
		margin-block-start: calc(var(--default-grid-baseline) * 6);
		padding-block-start: calc(var(--default-grid-baseline) * 4);
		border-block-start: 1px solid var(--color-border);
	}
}
</style>
