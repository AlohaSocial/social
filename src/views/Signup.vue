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

		<section v-else-if="!showForm" class="signup__landing" aria-labelledby="signup-introduction-title">
			<div class="signup__brand" aria-hidden="true">
				<span class="signup__brand-mark">A</span>
				<span>Aloha Social</span>
			</div>
			<h3 id="signup-introduction-title" class="signup__subheading">
				{{ t('social', 'An Aloha Social account') }}
			</h3>
			<p class="signup__intro">
				{{ reach }}
			</p>
			<ol class="signup__steps" :aria-label="t('social', 'Create an account')">
				<li class="signup__step">
					<span class="signup__step-number">1</span>
					<p>{{ lede }}</p>
				</li>
				<li class="signup__step">
					<span class="signup__step-number">2</span>
					<p>{{ registrationPath }}</p>
				</li>
				<li class="signup__step">
					<span class="signup__step-number">3</span>
					<p>{{ t('social', 'After joining, you can finish the introduction, find people and any starter packs this server offers, write posts, and manage your profile and account settings. If you have an export from a compatible service, you can import your follow list.') }}</p>
				</li>
			</ol>
			<p class="signup__scope">
				{{ t('social', 'This account is for Aloha Social only. It does not give you access to Files, Talk, WebDAV or other Nextcloud apps on this server.') }}
			</p>
			<p v-if="state.twoFactorRequired">
				{{ t('social', 'This server requires two-factor authentication. You will need to set it up when you first sign in.') }}
			</p>
			<p class="signup__federation-note">
				{{ t('social', 'Public posts may be delivered to other servers. Other servers may keep copies, so deleting a post here cannot guarantee that every copy elsewhere is removed.') }}
			</p>
			<p v-if="state.signupNotice" class="signup__instance-notice">
				{{ state.signupNotice }}
			</p>
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
			<div class="signup__actions">
				<NcButton variant="primary" wide @click="showForm = true">
					{{ t('social', 'Continue to registration') }}
				</NcButton>
				<NcButton :href="state.loginUrl" wide>
					{{ t('social', 'I already have an account') }}
				</NcButton>
			</div>
		</section>

		<form
			v-else
			class="signup__form"
			novalidate
			@submit.prevent="submit">
			<NcButton
				class="signup__back"
				type="button"
				size="small"
				variant="tertiary"
				@click="showForm = false">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('social', 'Back to the overview') }}
			</NcButton>
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
			<p v-if="state.signupNotice" class="signup__instance-notice">
				{{ state.signupNotice }}
			</p>
			<NcCheckboxRadioSwitch
				v-if="state.signupNoticeRequired"
				v-model="noticeAccepted"
				class="signup__check"
				:class="{ 'signup__check--error': field === 'notice' }">
				{{ t('social', 'I have read and accept this registration notice') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch v-model="rules" class="signup__check" :class="{ 'signup__check--error': field === 'rules' }">
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
				class="signup__check"
				:class="{ 'signup__check--error': field === 'age' }">
				{{ t('social', 'I am at least {age} years old', { age: state.minAge }) }}
			</NcCheckboxRadioSwitch>

			<NcNoteCard v-if="message && !['handle', 'email', 'password'].includes(field)" type="error">
				{{ message }}
			</NcNoteCard>

			<p v-if="solving" class="signup__progress" role="status">
				{{ solvingText }}
			</p>

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
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import { signupUrl } from '../services/externalApi.js'
import { fetchChallenge, startSolving } from '../services/proofOfWork.js'
import { progressOf } from '../utils/proofOfWork.js'
import { blueskyHandle } from '../utils/blueskyHandle.js'

/** What `ExternalSignupService::pageState()` provides when it provides nothing. */
const CLOSED = {
	open: false,
	reason: '',
	mode: 'approval',
	invited: false,
	inviteToken: '',
	approval: true,
	verifyEmail: true,
	twoFactorRequired: false,
	minAge: 0,
	rules: [],
	privacyUrl: '',
	legalUrl: '',
	signupNotice: '',
	signupNoticeRequired: false,
	signupNoticeVersion: '',
	proofOfWork: false,
	domain: '',
	bluesky: { enabled: false, host: '' },
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
		ArrowLeft,
	},

	data() {
		const state = { ...CLOSED, ...loadState('social', 'signup', {}) }

		return {
			state,
			handle: '',
			email: '',
			password: '',
			rules: false,
			noticeAccepted: false,
			age: false,
			website: '',
			sending: false,
			/** the field the server refused, or '' */
			field: '',
			message: '',
			showForm: false,
			/** the proof of work under way, see preparePow() */
			powTask: null,
			powAttempts: 0,
			powBits: 0,
			/** whether a submitted form waits for the proof of work */
			solving: false,
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
				return t('social', 'You were invited to join {domain}. Your account can be used for Aloha Social, and nothing else on this server.', { domain: this.state.domain })
			}
			if (this.state.approval) {
				return t('social', 'Join {domain}. Your account can be used for Aloha Social, and nothing else on this server. An administrator looks at every registration before it becomes an account.', { domain: this.state.domain })
			}

			return t('social', 'Join {domain}. Your account can be used for Aloha Social, and nothing else on this server.', { domain: this.state.domain })
		},

		/** @return {string} registration steps configured for this instance */
		registrationPath() {
			if (this.state.invited) {
				return t('social', 'Your invitation lets you register now. {emailStep}', { emailStep: this.state.verifyEmail ? t('social', 'You will first confirm your email address.') : '' })
			}
			if (this.state.mode === 'invite') {
				return t('social', 'This server accepts registrations by invitation only.')
			}
			if (this.state.approval) {
				return t('social', 'After you submit, an administrator will review your request. {emailStep}', { emailStep: this.state.verifyEmail ? t('social', 'You will first confirm your email address.') : '' })
			}

			return this.state.verifyEmail
				? t('social', 'After you submit, confirm your email address to activate your account.')
				: t('social', 'After you submit, your account will be ready to use.')
		},

		/** @return {boolean} whether an account made here gets a Bluesky handle too */
		blueskyEnabled() {
			return this.state.bluesky?.enabled === true && Boolean(this.state.bluesky?.host)
		},

		/** @return {string} who the account reaches, and the addresses it will have */
		reach() {
			const username = t('social', 'username')
			if (this.blueskyEnabled) {
				return t('social', 'Your address will look like @{username}@{domain}, and @{bluesky} on Bluesky. You can follow and talk with people on Mastodon, Bluesky, Pixelfed and more.', {
					username,
					domain: this.state.domain,
					bluesky: blueskyHandle(username, this.state.bluesky.host),
				})
			}

			return t('social', 'Your address will look like @{username}@{domain}. You can follow and talk with people on Mastodon, Pixelfed, PeerTube and more.', { username, domain: this.state.domain })
		},

		/** @return {string} the address the handle will be */
		address() {
			const handle = this.handle.trim().replace(/^@/, '').toLowerCase() || t('social', 'username')
			const address = '@' + handle + '@' + this.state.domain
			if (this.blueskyEnabled) {
				return t('social', 'Your address will be {address}, and {bluesky} on Bluesky', { address, bluesky: '@' + blueskyHandle(handle, this.state.bluesky.host) })
			}

			return t('social', 'Your address will be {address}', { address })
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

		/** @return {string} what the proof of work is doing, and how far along */
		solvingText() {
			return t('social', 'Your browser is solving a small puzzle that keeps automated registrations out: {progress}%', { progress: progressOf(this.powAttempts, this.powBits) })
		},
	},

	watch: {
		showForm(shown) {
			if (shown && this.state.proofOfWork && this.powTask === null) {
				this.preparePow()
			}
		},
	},

	beforeUnmount() {
		this.powTask?.cancel()
	},

	methods: {
		t,

		/**
		 * Fetches a challenge and starts solving it in the background, so it
		 * is mostly done by the time the form is sent. A challenge is good
		 * for one registration, so this runs again after every answer.
		 */
		preparePow() {
			this.powTask?.cancel()
			this.powAttempts = 0
			const task = {
				cancelled: false,
				solver: null,
				cancel: () => {
					task.cancelled = true
					task.solver?.cancel()
				},
			}
			task.result = (async () => {
				const issued = await fetchChallenge()
				if (task.cancelled) {
					return null
				}
				this.powBits = issued.bits
				task.solver = startSolving(issued, (attempts) => {
					if (this.powTask === task) {
						this.powAttempts = attempts
					}
				})
				const nonce = await task.solver.nonce

				return nonce === null ? null : { challenge: issued.challenge, nonce }
			})()
			// a failure is reported when the form is sent
			task.result.catch(() => {})
			this.powTask = task
		},

		/** @return {Promise<{challenge: string, nonce: string}|null>} the solved challenge */
		async proofOfWork() {
			if (this.powTask === null) {
				this.preparePow()
			}
			const task = this.powTask
			this.solving = true
			try {
				return await task.result
			} catch {
				return null
			} finally {
				this.solving = false
				if (this.powTask === task) {
					this.powTask = null
				}
			}
		},

		/** @return {Promise<void>} */
		async submit() {
			this.sending = true
			this.field = ''
			this.message = ''
			let proof = null
			if (this.state.proofOfWork) {
				proof = await this.proofOfWork()
				if (proof === null) {
					this.message = t('social', 'The check of this browser did not succeed. Please try again.')
					this.sending = false
					this.preparePow()
					return
				}
			}
			try {
				const { data } = await axios.post(signupUrl(), {
					handle: this.handle,
					email: this.email,
					password: this.password,
					rules: this.rules,
					notice: this.noticeAccepted,
					noticeVersion: this.state.signupNoticeVersion,
					age: this.age,
					invite: this.state.inviteToken,
					website: this.website,
					...(proof === null ? {} : proof),
				})
				this.password = ''
				this.outcome = data
			} catch (error) {
				if (this.state.proofOfWork) {
					this.preparePow()
				}
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
	width: min(520px, 100%);
	text-align: start;
	padding: clamp(20px, 5vw, 36px);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);

	&__heading {
		margin-block-end: calc(var(--default-grid-baseline) * 3);
		text-align: center;
	}

	&__lede {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__landing {
		line-height: 1.6;

		p {
			margin-block: 0 calc(var(--default-grid-baseline) * 3);
		}
	}

	&__brand {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 10px;
		margin-block: 0 22px;
		color: var(--color-primary-element);
		font-size: 14px;
		font-weight: 700;
		letter-spacing: .03em;
	}

	&__brand-mark {
		display: grid;
		place-items: center;
		width: 36px;
		height: 36px;
		border-radius: 12px;
		background: var(--color-primary-element);
		color: var(--color-primary-element-text);
		font-size: 20px;
	}

	&__subheading {
		margin: 0;
		font-size: 1.4rem;
		line-height: 1.25;
		text-align: center;
	}

	&__intro {
		margin-block: 12px 22px !important;
		color: var(--color-text-maxcontrast);
		text-align: center;
	}

	&__steps {
		display: grid;
		gap: 10px;
		margin-block: 20px 24px;
		padding: 0;
		list-style: none;
	}

	&__step {
		display: flex;
		align-items: flex-start;
		gap: 12px;
		padding: 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background: var(--color-background-hover);

		p {
			margin: 0;
		}
	}

	&__step-number {
		display: grid;
		flex: 0 0 26px;
		place-items: center;
		width: 26px;
		height: 26px;
		border-radius: 50%;
		background: var(--color-primary-element);
		color: var(--color-primary-element-text);
		font-size: 13px;
		font-weight: 700;
	}

	&__scope {
		padding: 12px 14px;
		border-inline-start: 3px solid var(--color-primary-element);
		border-radius: 0 var(--border-radius-large) var(--border-radius-large) 0;
		background: var(--color-background-hover);
		font-size: 13px;
	}

	&__federation-note {
		padding: 12px 14px;
		border-inline-start: 3px solid var(--color-border);
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	&__form {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__back {
		align-self: flex-start;
		margin-inline-start: calc(var(--default-grid-baseline) * -2);
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

	&__instance-notice {
		padding: calc(var(--default-grid-baseline) * 3);
		border-inline-start: 3px solid var(--color-primary-element);
		background: var(--color-background-hover);
		white-space: pre-wrap;
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

	// Nextcloud's checkbox icon sits a few pixels above its label baseline;
	// align the small signup confirmations with the first line of their text.
	&__check :deep(.checkbox-radio-switch__icon) {
		transform: translateY(0.25em);
	}

	&__actions {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
		margin-block-start: calc(var(--default-grid-baseline) * 3);
	}

	&__progress {
		margin: 0;
		color: var(--color-text-maxcontrast);
		text-align: center;
	}

	&__login {
		text-align: center;

		a {
			text-decoration: underline;
		}
	}
}
</style>
