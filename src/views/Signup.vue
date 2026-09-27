<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="guest-box signup">
		<h2 class="signup__heading">
			{{ heading }}
		</h2>

		<!-- what the confirmation link or the form came to -->
		<template v-if="outcome">
			<NcNoteCard :type="outcome.state === 'error' ? 'error' : 'success'">
				{{ outcomeText }}
			</NcNoteCard>
			<div class="signup__actions">
				<NcButton
					v-if="outcome.state === 'created'"
					variant="primary"
					:href="state.loginUrl"
					wide>
					{{ t('social', 'Log in') }}
				</NcButton>
				<NcButton v-else-if="outcome.state === 'error'" :href="signupPage" wide>
					{{ t('social', 'Register again') }}
				</NcButton>
			</div>
		</template>

		<template v-else-if="!state.open">
			<NcNoteCard type="info">
				{{ state.reason }}
			</NcNoteCard>
			<div class="signup__actions">
				<NcButton :href="state.loginUrl" wide>
					{{ t('social', 'Back to the login page') }}
				</NcButton>
			</div>
		</template>

		<form
			v-else
			class="signup__form"
			novalidate
			@submit.prevent="submit">
			<p class="signup__lede">
				{{ lede }}
			</p>

			<NcTextField
				v-model="handle"
				:label="t('social', 'Username')"
				:error="field === 'handle'"
				:helperText="field === 'handle' ? message : address"
				autocomplete="username"
				autocapitalize="none"
				spellcheck="false"
				:maxlength="64"
				required />

			<NcTextField
				v-model="email"
				type="email"
				:label="t('social', 'Email address')"
				:error="field === 'email'"
				:helperText="field === 'email' ? message : (state.verifyEmail ? t('social', 'We send a link to this address to confirm it.') : '')"
				autocomplete="email"
				required />

			<NcPasswordField
				v-model="password"
				:label="t('social', 'Password')"
				:error="field === 'password'"
				:helperText="field === 'password' ? message : t('social', 'At least 8 characters.')"
				autocomplete="new-password"
				required />

			<!-- nobody sees this field; a form-filling bot fills it in -->
			<div class="signup__trap" aria-hidden="true">
				<label for="social-signup-website">Website</label>
				<input
					id="social-signup-website"
					v-model="website"
					type="text"
					name="website"
					tabindex="-1"
					autocomplete="off">
			</div>

			<div v-if="state.rules.length > 0" class="signup__rules">
				<h3 class="signup__rules-heading">
					{{ t('social', 'Server rules') }}
				</h3>
				<ol class="signup__rules-list">
					<li v-for="(rule, index) in state.rules" :key="index">
						{{ rule }}
					</li>
				</ol>
			</div>

			<NcCheckboxRadioSwitch v-model="rules" :class="{ 'signup__check--error': field === 'rules' }">
				{{ t('social', 'I accept the server rules') }}
			</NcCheckboxRadioSwitch>
			<p v-if="state.privacyUrl || state.legalUrl" class="signup__legal">
				<a
					v-if="state.privacyUrl"
					:href="state.privacyUrl"
					target="_blank"
					rel="noopener noreferrer">{{ t('social', 'Privacy policy') }}</a>
				<a
					v-if="state.legalUrl"
					:href="state.legalUrl"
					target="_blank"
					rel="noopener noreferrer">{{ t('social', 'Legal notice') }}</a>
			</p>

			<NcCheckboxRadioSwitch
				v-if="state.minAge > 0"
				v-model="age"
				:class="{ 'signup__check--error': field === 'age' }">
				{{ t('social', 'I am at least {age} years old', { age: state.minAge }) }}
			</NcCheckboxRadioSwitch>

			<NcNoteCard v-if="message && !['handle', 'email', 'password'].includes(field)" type="error">
				{{ message }}
			</NcNoteCard>

			<NcButton
				type="submit"
				variant="primary"
				:disabled="sending"
				wide>
				<template v-if="sending" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Create account') }}
			</NcButton>

			<p class="signup__login">
				<a :href="state.loginUrl">{{ t('social', 'I already have an account') }}</a>
			</p>
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { signupUrl } from '../services/externalApi.js'

/** What `ExternalSignupService::pageState()` provides when it provides nothing. */
const CLOSED = {
	open: false,
	reason: '',
	mode: 'approval',
	invited: false,
	inviteToken: '',
	approval: true,
	verifyEmail: true,
	minAge: 0,
	rules: [],
	privacyUrl: '',
	legalUrl: '',
	domain: '',
	loginUrl: '/login',
	result: null,
}

/**
 * Registering an account that reaches Social only.
 *
 * Everything the page may say comes from the server: whether registration is
 * open, the rules, the minimum age, and the reasons a registration is turned
 * down, already translated. The page only arranges it.
 */
export default {
	name: 'Signup',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcTextField,
	},

	data() {
		const state = { ...CLOSED, ...loadState('social', 'signup', {}) }

		return {
			state,
			handle: '',
			email: '',
			password: '',
			rules: false,
			age: false,
			website: '',
			sending: false,
			/** the field the server refused, or '' */
			field: '',
			message: '',
			/** @type {{state: string, handle?: string, message?: string}|null} */
			outcome: state.result ?? null,
		}
	},

	computed: {
		/** @return {string} */
		heading() {
			if (this.outcome?.state === 'created') {
				return t('social', 'Your account is ready')
			}
			if (this.outcome?.state === 'verify') {
				return t('social', 'Check your email')
			}
			if (this.outcome?.state === 'approval') {
				return t('social', 'Thank you')
			}

			return t('social', 'Create an account')
		},

		/** @return {string} */
		lede() {
			if (this.state.invited) {
				return t('social', 'You were invited to join {domain}. Your account can be used for Social, and nothing else on this server.', { domain: this.state.domain })
			}
			if (this.state.approval) {
				return t('social', 'Join {domain}. Your account can be used for Social, and nothing else on this server. An administrator looks at every registration before it becomes an account.', { domain: this.state.domain })
			}

			return t('social', 'Join {domain}. Your account can be used for Social, and nothing else on this server.', { domain: this.state.domain })
		},

		/** @return {string} the address the handle will be */
		address() {
			const handle = this.handle.trim().replace(/^@/, '').toLowerCase()

			return t('social', 'Your address will be {address}', { address: '@' + (handle || t('social', 'username')) + '@' + this.state.domain })
		},

		/** @return {string} */
		outcomeText() {
			switch (this.outcome?.state) {
				case 'verify':
					return t('social', 'We sent a link to {email}. Follow it within 24 hours to confirm your address.', { email: this.email || t('social', 'your email address') })
				case 'approval':
					return t('social', 'Your registration is waiting for an administrator. You get an email once it has been looked at.')
				case 'created':
					return t('social', 'Log in as {handle} with the password you chose.', { handle: this.outcome.handle ?? '' })
				default:
					return this.outcome?.message ?? t('social', 'Something went wrong.')
			}
		},

		/** @return {string} */
		signupPage() {
			return signupUrl()
		},
	},

	methods: {
		t,

		/** @return {Promise<void>} */
		async submit() {
			this.sending = true
			this.field = ''
			this.message = ''
			try {
				const { data } = await axios.post(signupUrl(), {
					handle: this.handle,
					email: this.email,
					password: this.password,
					rules: this.rules,
					age: this.age,
					invite: this.state.inviteToken,
					website: this.website,
				})
				this.password = ''
				this.outcome = data
			} catch (error) {
				this.field = error?.response?.data?.field ?? ''
				this.message = error?.response?.data?.message
					|| (error?.response?.status === 429
						? t('social', 'Too many registrations right now, please try again later.')
						: t('social', 'The registration could not be sent. Please try again.'))
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.signup {
	width: min(440px, 100%);
	text-align: start;

	&__heading {
		margin-block-end: calc(var(--default-grid-baseline) * 3);
		text-align: center;
	}

	&__lede {
		margin-block-end: calc(var(--default-grid-baseline) * 3);
		color: var(--color-text-maxcontrast);
	}

	&__form {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 3);
	}

	// out of sight and out of the tab order, but still in the form
	&__trap {
		position: absolute;
		inset-inline-start: -10000px;
		width: 1px;
		height: 1px;
		overflow: hidden;
	}

	&__rules {
		padding: calc(var(--default-grid-baseline) * 3);
		border-radius: var(--border-radius-large);
		background: var(--color-background-hover);
	}

	&__rules-heading {
		margin-block: 0 calc(var(--default-grid-baseline) * 2);
		font-size: 1em;
	}

	&__rules-list {
		padding-inline-start: 1.5em;
		list-style: decimal;
	}

	&__legal {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 4);
		margin-block-start: calc(var(--default-grid-baseline) * -2);

		a {
			text-decoration: underline;
		}
	}

	&__check--error {
		color: var(--color-error-text, var(--color-error));
	}

	&__actions {
		margin-block-start: calc(var(--default-grid-baseline) * 3);
	}

	&__login {
		text-align: center;

		a {
			text-decoration: underline;
		}
	}
}
</style>
