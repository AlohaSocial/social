<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- the same account as the other network sees it. Nothing here is a
	     setting: the identity exists because this server offers one, and
	     the one thing the person can take away is the phrase that proves
	     it is theirs without this server -->
	<div class="bluesky-settings">
		<p v-if="blueskyError" class="bluesky-settings__hint">
			{{ blueskyError }}
		</p>
		<p v-else-if="!bluesky" class="bluesky-settings__hint">
			{{ t('social', 'Loading …') }}
		</p>
		<template v-else>
			<dl class="bluesky-settings__identity">
				<div class="bluesky-settings__identity-row">
					<dt>{{ t('social', 'Handle') }}</dt>
					<dd>
						<a
							class="bluesky-settings__code"
							:href="bluesky.url"
							target="_blank"
							rel="noopener">@{{ bluesky.handle }}</a>
						<NcButton
							variant="tertiary"
							:title="blueskyCopied === 'handle' ? t('social', 'Copied') : t('social', 'Copy')"
							:aria-label="blueskyCopied === 'handle' ? t('social', 'Copied') : t('social', 'Copy the Bluesky handle')"
							@click="copyBluesky('handle', '@' + bluesky.handle)">
							<template #icon>
								<Check v-if="blueskyCopied === 'handle'" :size="16" />
								<ContentCopy v-else :size="16" />
							</template>
						</NcButton>
					</dd>
				</div>
				<div class="bluesky-settings__identity-row">
					<dt>{{ t('social', 'DID') }}</dt>
					<dd>
						<code class="bluesky-settings__code">{{ bluesky.did }}</code>
						<NcButton
							variant="tertiary"
							:title="blueskyCopied === 'did' ? t('social', 'Copied') : t('social', 'Copy')"
							:aria-label="blueskyCopied === 'did' ? t('social', 'Copied') : t('social', 'Copy the DID')"
							@click="copyBluesky('did', bluesky.did)">
							<template #icon>
								<Check v-if="blueskyCopied === 'did'" :size="16" />
								<ContentCopy v-else :size="16" />
							</template>
						</NcButton>
					</dd>
				</div>
			</dl>
			<!-- off, the account stays and so does its address; nothing
			     new goes out until it is on again -->
			<NcCheckboxRadioSwitch
				:modelValue="blueskyActive"
				type="switch"
				class="bluesky-settings__switch"
				:disabled="switchingBluesky"
				@update:modelValue="setBlueskyActive">
				{{ t('social', 'Show my posts on Bluesky') }}
			</NcCheckboxRadioSwitch>
			<p v-if="bluesky.active === false" class="bluesky-settings__hint">
				{{ t('social', 'This account is paused on Bluesky: nothing new is published there until it is switched back on.') }}
			</p>
			<div class="bluesky-settings__recovery">
				<NcButton :disabled="recovering" @click="createRecoveryPhrase">
					<template #icon>
						<NcLoadingIcon v-if="recovering" :size="20" />
						<KeyOutline v-else :size="20" />
					</template>
					{{ bluesky.recovery_key ? t('social', 'Create a new recovery phrase') : t('social', 'Create recovery phrase') }}
				</NcButton>
				<p class="bluesky-settings__hint">
					{{ bluesky.recovery_key
						? t('social', 'The phrase you have stops working the moment a new one is made.')
						: t('social', 'Twelve words that prove this Bluesky identity is yours even without this server. They are shown once, for you to write down.') }}
				</p>
			</div>
			<!-- the domain only has to name the DID: the account, its
			     followers and the assigned handle stay as they are -->
			<div v-if="bluesky.active !== false && bluesky.assigned_handle" class="bluesky-settings__custom-handle">
				<h5 class="bluesky-settings__title">
					{{ t('social', 'Your own domain as your handle') }}
				</h5>
				<p class="bluesky-settings__hint">
					{{ t('social', 'If you own a domain, it can be your Bluesky handle. Your followers stay with you; {assigned} keeps working too.', { assigned: bluesky.assigned_handle }) }}
				</p>
				<template v-if="bluesky.custom_handle">
					<p class="bluesky-settings__custom-handle-current">
						{{ t('social', 'Your handle is {handle}.', { handle: bluesky.custom_handle }) }}
					</p>
					<NcNoteCard v-if="bluesky.custom_handle_broken" type="warning">
						{{ t('social', '{handle} no longer names your account. Bluesky shows it as invalid until the DNS record or file is back.', { handle: bluesky.custom_handle }) }}
					</NcNoteCard>
					<div v-if="confirmingAssignedHandle" class="bluesky-settings__app-password-actions">
						<p class="bluesky-settings__hint">
							{{ t('social', 'Bluesky shows {assigned} as your handle again.', { assigned: bluesky.assigned_handle }) }}
						</p>
						<NcButton
							variant="primary"
							:disabled="changingHandle"
							@click="useAssignedHandle">
							{{ t('social', 'Use it again') }}
						</NcButton>
						<NcButton :disabled="changingHandle" @click="confirmingAssignedHandle = false">
							{{ t('social', 'Keep my domain') }}
						</NcButton>
					</div>
					<div v-else class="bluesky-settings__app-password-actions">
						<NcButton @click="confirmingAssignedHandle = true">
							{{ t('social', 'Use {assigned} again', { assigned: bluesky.assigned_handle }) }}
						</NcButton>
					</div>
				</template>
				<template v-else>
					<NcTextField
						v-model="customHandle"
						class="bluesky-settings__custom-handle-field"
						:label="t('social', 'Domain to use as your handle')"
						placeholder="alice.example.org"
						:error="customHandleError !== ''"
						:helperText="customHandleError"
						maxlength="253"
						:showTrailingButton="false"
						@update:modelValue="customHandleError = ''"
						@keydown.enter.prevent="setCustomHandle" />
					<div v-if="customHandleDomain !== ''" class="bluesky-settings__custom-handle-steps">
						<p class="bluesky-settings__hint">
							{{ t('social', 'Add one of these, then check it:') }}
						</p>
						<p class="bluesky-settings__custom-handle-option">
							{{ t('social', 'A DNS TXT record') }}
						</p>
						<dl class="bluesky-settings__identity">
							<div class="bluesky-settings__identity-row">
								<dt>{{ t('social', 'Name') }}</dt>
								<dd>
									<code class="bluesky-settings__code">{{ dnsRecordName }}</code>
									<NcButton
										variant="tertiary"
										:title="blueskyCopied === 'dns-name' ? t('social', 'Copied') : t('social', 'Copy')"
										:aria-label="blueskyCopied === 'dns-name' ? t('social', 'Copied') : t('social', 'Copy the record name')"
										@click="copyBluesky('dns-name', dnsRecordName)">
										<template #icon>
											<Check v-if="blueskyCopied === 'dns-name'" :size="16" />
											<ContentCopy v-else :size="16" />
										</template>
									</NcButton>
								</dd>
							</div>
							<div class="bluesky-settings__identity-row">
								<dt>{{ t('social', 'Value') }}</dt>
								<dd>
									<code class="bluesky-settings__code">{{ dnsRecordValue }}</code>
									<NcButton
										variant="tertiary"
										:title="blueskyCopied === 'dns-value' ? t('social', 'Copied') : t('social', 'Copy')"
										:aria-label="blueskyCopied === 'dns-value' ? t('social', 'Copied') : t('social', 'Copy the record value')"
										@click="copyBluesky('dns-value', dnsRecordValue)">
										<template #icon>
											<Check v-if="blueskyCopied === 'dns-value'" :size="16" />
											<ContentCopy v-else :size="16" />
										</template>
									</NcButton>
								</dd>
							</div>
						</dl>
						<p class="bluesky-settings__custom-handle-option">
							{{ t('social', 'Or a file on the domain’s web server') }}
						</p>
						<dl class="bluesky-settings__identity">
							<div class="bluesky-settings__identity-row">
								<dt>{{ t('social', 'Address') }}</dt>
								<dd>
									<code class="bluesky-settings__code">{{ didFileAddress }}</code>
									<NcButton
										variant="tertiary"
										:title="blueskyCopied === 'file-address' ? t('social', 'Copied') : t('social', 'Copy')"
										:aria-label="blueskyCopied === 'file-address' ? t('social', 'Copied') : t('social', 'Copy the file address')"
										@click="copyBluesky('file-address', didFileAddress)">
										<template #icon>
											<Check v-if="blueskyCopied === 'file-address'" :size="16" />
											<ContentCopy v-else :size="16" />
										</template>
									</NcButton>
								</dd>
							</div>
							<div class="bluesky-settings__identity-row">
								<dt>{{ t('social', 'Content') }}</dt>
								<dd>
									<code class="bluesky-settings__code">{{ bluesky.did }}</code>
									<NcButton
										variant="tertiary"
										:title="blueskyCopied === 'file-content' ? t('social', 'Copied') : t('social', 'Copy')"
										:aria-label="blueskyCopied === 'file-content' ? t('social', 'Copied') : t('social', 'Copy the file content')"
										@click="copyBluesky('file-content', bluesky.did)">
										<template #icon>
											<Check v-if="blueskyCopied === 'file-content'" :size="16" />
											<ContentCopy v-else :size="16" />
										</template>
									</NcButton>
								</dd>
							</div>
						</dl>
						<p class="bluesky-settings__hint">
							{{ t('social', 'The file holds only the DID, as plain text.') }}
						</p>
					</div>
					<NcButton
						class="bluesky-settings__custom-handle-check"
						:disabled="customHandleDomain === '' || changingHandle"
						@click="setCustomHandle">
						<template #icon>
							<NcLoadingIcon v-if="changingHandle" :size="20" />
							<Check v-else :size="20" />
						</template>
						{{ t('social', 'Check and use it') }}
					</NcButton>
				</template>
			</div>
			<!-- what a Bluesky app signs in with: this server is its
			     hosting provider, and the Nextcloud password is never
			     handed to it -->
			<div v-if="bluesky.active !== false && !appPasswordsHidden" class="bluesky-settings__app-passwords">
				<h5 class="bluesky-settings__title">
					{{ t('social', 'App passwords for Bluesky apps') }}
				</h5>
				<p class="bluesky-settings__hint">
					{{ t('social', 'To use a Bluesky app with this account, sign in there with your Bluesky handle and an app password made here, and choose this server as your hosting provider: {server}.', { server: origin }) }}
				</p>
				<p v-if="appPasswordsError" class="bluesky-settings__hint">
					{{ appPasswordsError }}
				</p>
				<p v-else-if="appPasswords === null" class="bluesky-settings__hint">
					{{ t('social', 'Loading …') }}
				</p>
				<template v-else>
					<div v-if="newAppPassword" class="bluesky-settings__new-password" role="status">
						<p class="bluesky-settings__new-password-title">
							{{ t('social', 'New app password for {name}', { name: newAppPassword.name }) }}
						</p>
						<p class="bluesky-settings__new-password-value">
							<code class="bluesky-settings__code">{{ newAppPassword.password }}</code>
							<NcButton
								variant="tertiary"
								:title="blueskyCopied === 'password' ? t('social', 'Copied') : t('social', 'Copy')"
								:aria-label="blueskyCopied === 'password' ? t('social', 'Copied') : t('social', 'Copy the app password')"
								@click="copyBluesky('password', newAppPassword.password)">
								<template #icon>
									<Check v-if="blueskyCopied === 'password'" :size="16" />
									<ContentCopy v-else :size="16" />
								</template>
							</NcButton>
						</p>
						<p class="bluesky-settings__hint">
							{{ t('social', 'Copy it now: it is not shown again.') }}
						</p>
						<NcButton class="bluesky-settings__new-password-done" @click="newAppPassword = null">
							{{ t('social', 'Done') }}
						</NcButton>
					</div>
					<ul v-if="appPasswords.length > 0" class="bluesky-settings__app-password-list">
						<li v-for="appPassword in appPasswords" :key="appPassword.id" class="bluesky-settings__app-password">
							<span class="bluesky-settings__app-password-name">{{ appPassword.name }}</span>
							<span class="bluesky-settings__hint">
								{{ t('social', 'Made {date}', { date: madeOn(appPassword.creation) }) }}
								·
								{{ appPassword.last_used > 0
									? t('social', 'Last used {when}', { when: lastUsed(appPassword.last_used) })
									: t('social', 'Never used') }}
							</span>
							<div v-if="confirmingRevoke === appPassword.id" class="bluesky-settings__app-password-actions">
								<p class="bluesky-settings__hint">
									{{ t('social', 'Every app signed in with this password is signed out and cannot use it again.') }}
								</p>
								<NcButton
									variant="error"
									:disabled="revoking === appPassword.id"
									@click="revokeAppPassword(appPassword)">
									{{ t('social', 'Revoke it') }}
								</NcButton>
								<NcButton :disabled="revoking === appPassword.id" @click="confirmingRevoke = 0">
									{{ t('social', 'Keep it') }}
								</NcButton>
							</div>
							<div v-else class="bluesky-settings__app-password-actions">
								<NcButton @click="confirmingRevoke = appPassword.id">
									{{ t('social', 'Revoke') }}
								</NcButton>
							</div>
						</li>
					</ul>
					<div class="bluesky-settings__app-password-create">
						<NcTextField
							v-model="appPasswordName"
							class="bluesky-settings__app-password-field"
							:label="t('social', 'Name of the new app password')"
							:placeholder="t('social', 'The app it is for')"
							:error="appPasswordNameError !== ''"
							:helperText="appPasswordNameError"
							maxlength="64"
							:showTrailingButton="false"
							@update:modelValue="appPasswordNameError = ''"
							@keydown.enter.prevent="createAppPassword" />
						<NcButton
							:disabled="appPasswordName.trim() === '' || makingAppPassword"
							@click="createAppPassword">
							<template #icon>
								<NcLoadingIcon v-if="makingAppPassword" :size="20" />
								<KeyOutline v-else :size="20" />
							</template>
							{{ t('social', 'Make an app password') }}
						</NcButton>
					</div>
				</template>
			</div>
			<!-- a Bluesky app that signed in through the OAuth consent
			     page holds a token of its own, not an app password -->
			<div v-if="bluesky.active !== false && !oauthSessionsHidden" class="bluesky-settings__oauth-sessions">
				<h5 class="bluesky-settings__title">
					{{ t('social', 'Apps signed in with Bluesky sign-in') }}
				</h5>
				<p class="bluesky-settings__hint">
					{{ t('social', 'Bluesky apps that sign in with your account here, without an app password. Signing one out stops it at once.') }}
				</p>
				<p v-if="oauthSessionsError" class="bluesky-settings__hint">
					{{ oauthSessionsError }}
				</p>
				<p v-else-if="oauthSessions === null" class="bluesky-settings__hint">
					{{ t('social', 'Loading …') }}
				</p>
				<p v-else-if="oauthSessions.length === 0" class="bluesky-settings__hint">
					{{ t('social', 'No app has signed in this way.') }}
				</p>
				<ul v-else class="bluesky-settings__app-password-list">
					<li v-for="session in oauthSessions" :key="session.id" class="bluesky-settings__oauth-session">
						<span class="bluesky-settings__app-password-name">{{ session.client }}</span>
						<!-- the client_id is the only part of the app that is checked -->
						<span class="bluesky-settings__hint bluesky-settings__oauth-client-id">{{ session.client_id }}</span>
						<span class="bluesky-settings__hint">
							{{ t('social', 'Signed in {date}', { date: madeOn(session.created) }) }}
							·
							{{ session.last_used > 0
								? t('social', 'Last used {when}', { when: lastUsed(session.last_used) })
								: t('social', 'Never used') }}
						</span>
						<ul v-if="sessionGrants(session).length > 0" class="bluesky-settings__oauth-grants">
							<li v-for="grant in sessionGrants(session)" :key="grant">
								{{ grant }}
							</li>
						</ul>
						<div v-if="confirmingSignOut === session.id" class="bluesky-settings__app-password-actions">
							<p class="bluesky-settings__hint">
								{{ t('social', 'This app is signed out and has to ask you again to sign in.') }}
							</p>
							<NcButton
								variant="error"
								:disabled="signingOut === session.id"
								@click="signOutSession(session)">
								{{ t('social', 'Sign it out') }}
							</NcButton>
							<NcButton :disabled="signingOut === session.id" @click="confirmingSignOut = 0">
								{{ t('social', 'Keep it') }}
							</NcButton>
						</div>
						<div v-else class="bluesky-settings__app-password-actions">
							<NcButton @click="confirmingSignOut = session.id">
								{{ t('social', 'Sign out') }}
							</NcButton>
						</div>
					</li>
				</ul>
			</div>
		</template>
		<NcDialog
			:open="phrase !== ''"
			:name="t('social', 'Your recovery phrase')"
			:buttons="phraseButtons"
			@update:open="closePhrase">
			<NcNoteCard type="warning">
				{{ t('social', 'These twelve words are shown once and never again. Write them down and keep them where only you can find them.') }}
			</NcNoteCard>
			<p class="bluesky-settings__phrase">
				<code>{{ phrase }}</code>
			</p>
		</NcDialog>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Check from 'vue-material-design-icons/Check.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import KeyOutline from 'vue-material-design-icons/KeyOutline.vue'
import { translate as t } from '@nextcloud/l10n'
import { confirmPassword } from '../services/externalApi.js'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { fromNow, fullDate } from '../utils/relativeTime.js'

/**
 * The account's Bluesky identity: its handle and DID, and the recovery
 * phrase that proves the identity is the person's without this server.
 *
 * Settings shows this section only where the instance gives every account a
 * Bluesky identity; the identity is made on first asking.
 */
export default {
	name: 'BlueskySettings',

	components: {
		Check,
		ContentCopy,
		KeyOutline,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			/**
			 * The Bluesky identity, once asked for; null until it comes
			 *
			 * @type {{handle: string, did: string, url: string, state: string, recovery_key: boolean, active: boolean, assigned_handle?: string, custom_handle?: string, custom_handle_broken?: boolean}|null}
			 */
			bluesky: null,
			blueskyError: '',
			/** the pause switch: it moves at once, and comes back if the server refuses */
			blueskyActive: true,
			switchingBluesky: false,
			/** what was just copied: one of the copyBluesky names, or '' */
			blueskyCopied: '',
			blueskyCopyTimer: null,
			recovering: false,
			/** the twelve words, for as long as the dialog shows them */
			phrase: '',
			/** the domain as typed into the own-handle field */
			customHandle: '',
			/** what the server said about the domain, under the field */
			customHandleError: '',
			/** a handle change is out */
			changingHandle: false,
			/** going back to the assigned handle is asking to be confirmed */
			confirmingAssignedHandle: false,

			/**
			 * The app passwords for Bluesky apps; null until they come
			 *
			 * @type {Array<{id: number, name: string, creation: number, last_used: number}>|null}
			 */
			appPasswords: null,
			/** the server has no such route: Bluesky is off there */
			appPasswordsHidden: false,
			appPasswordsError: '',
			appPasswordName: '',
			/** what the server said about the name, under the field */
			appPasswordNameError: '',
			makingAppPassword: false,
			/**
			 * The password just made, until it is dismissed; it is never
			 * fetched again
			 *
			 * @type {{name: string, password: string}|null}
			 */
			newAppPassword: null,
			/** the row asking to be confirmed, or 0 */
			confirmingRevoke: 0,
			/** the row a revocation is out for, or 0 */
			revoking: 0,

			/**
			 * The Bluesky apps signed in through OAuth; null until they come
			 *
			 * @type {Array<{id: number, client_id: string, client: string, scopes: string[], created: number, last_used: number}>|null}
			 */
			oauthSessions: null,
			/** the server has no such route: Bluesky is off there */
			oauthSessionsHidden: false,
			oauthSessionsError: '',
			/** the session asking to be confirmed, or 0 */
			confirmingSignOut: 0,
			/** the session a sign-out is out for, or 0 */
			signingOut: 0,
		}
	},

	computed: {
		/** @return {string} the address a Bluesky app is given as the hosting provider */
		origin() {
			return window.location.origin
		},

		/** @return {boolean} whether the identity is here and live, which app passwords need */
		blueskyLive() {
			return this.bluesky !== null && this.bluesky.active !== false
		},

		/** @return {string} the typed domain the way a handle is written: trimmed, lower case, without the leading at sign */
		customHandleDomain() {
			return this.customHandle.trim().replace(/^@/, '').toLowerCase()
		},

		/** @return {string} the TXT record the domain names the DID with */
		dnsRecordName() {
			return '_atproto.' + this.customHandleDomain
		},

		/** @return {string} */
		dnsRecordValue() {
			return 'did=' + (this.bluesky?.did ?? '')
		},

		/** @return {string} the file that names the DID instead of a record */
		didFileAddress() {
			return 'https://' + this.customHandleDomain + '/.well-known/atproto-did'
		},

		/** @return {import('../types/Nextcloud.js').DialogButton[]} */
		phraseButtons() {
			return [
				{
					label: t('social', 'Copy the words'),
					callback: () => this.copyBluesky('phrase', this.phrase),
				},
				{
					label: t('social', 'I have written them down'),
					variant: 'primary',
					callback: () => this.closePhrase(),
				},
			]
		},
	},

	watch: {
		// asked for the first time the identity is live, whether on load or
		// when it is switched back on
		blueskyLive(live) {
			if (live && this.appPasswords === null && !this.appPasswordsHidden) {
				this.loadAppPasswords()
			}
			if (live && this.oauthSessions === null && !this.oauthSessionsHidden) {
				this.loadOAuthSessions()
			}
		},
	},

	mounted() {
		this.loadBluesky()
	},

	beforeUnmount() {
		window.clearTimeout(this.blueskyCopyTimer)
	},

	methods: {
		t,

		/**
		 * Asks for the identity, which the server makes on first asking.
		 *
		 * @return {Promise<void>}
		 */
		async loadBluesky() {
			this.blueskyError = ''
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/social/bluesky/identity'))
				this.takeIdentity(data)
			} catch (error) {
				logger.debug('Could not load the Bluesky identity', { error })
				this.blueskyError = t('social', 'Could not read your Bluesky identity right now.')
			}
		},

		/**
		 * @param {'handle'|'did'|'phrase'|'password'|'dns-name'|'dns-value'|'file-address'|'file-content'} which what was asked for
		 * @param {string} text what goes onto the clipboard
		 */
		async copyBluesky(which, text) {
			try {
				await navigator.clipboard.writeText(text)
				this.blueskyCopied = which
				window.clearTimeout(this.blueskyCopyTimer)
				this.blueskyCopyTimer = window.setTimeout(() => {
					this.blueskyCopied = ''
				}, 2000)
			} catch (error) {
				logger.debug('Could not copy', { error })
				showError(t('social', 'Could not copy — select the address and copy it yourself'))
			}
		},

		/**
		 * Makes the phrase, after the password: a new key replaces the old one
		 * on the directory, so whoever asks for it has to be the person.
		 *
		 * @return {Promise<void>}
		 */
		async createRecoveryPhrase() {
			try {
				await confirmPassword()
			} catch {
				return
			}
			this.recovering = true
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/social/bluesky/recovery'))
				const { phrase, ...identity } = data
				this.takeIdentity(identity)
				this.phrase = phrase
			} catch (error) {
				// the password confirmation of a moment ago has run out, which
				// the server answers with 403 rather than a dialog
				showError(error?.response?.status === 403
					? t('social', 'Confirm your password again and retry.')
					: t('social', 'Could not create a recovery phrase'))
			} finally {
				this.recovering = false
			}
		},

		closePhrase() {
			this.phrase = ''
		},

		/**
		 * Makes the typed domain the handle, after the password: the server
		 * checks that the domain names the DID before it takes it.
		 *
		 * @return {Promise<void>}
		 */
		async setCustomHandle() {
			const handle = this.customHandleDomain
			if (handle === '' || this.changingHandle) {
				return
			}
			try {
				await confirmPassword()
			} catch {
				return
			}
			this.changingHandle = true
			this.customHandleError = ''
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/social/bluesky/handle'), { handle })
				this.takeIdentity(data)
				this.customHandle = ''
				showSuccess(t('social', 'Your handle is now {handle}', { handle: data.handle }))
			} catch (error) {
				const status = error?.response?.status
				if (status === 422) {
					this.customHandleError = error.response.data?.error || t('social', 'Could not set the handle')
				} else {
					showError(status === 403
						? t('social', 'Confirm your password again and retry.')
						: t('social', 'Could not set the handle'))
				}
			} finally {
				this.changingHandle = false
			}
		},

		/** @return {Promise<void>} */
		async useAssignedHandle() {
			this.changingHandle = true
			try {
				const { data } = await axios.delete(generateUrl('apps/social/api/v1/social/bluesky/handle'))
				this.takeIdentity(data)
				this.confirmingAssignedHandle = false
			} catch (error) {
				logger.debug('Could not go back to the assigned handle', { error })
				showError(t('social', 'Could not change the handle'))
			} finally {
				this.changingHandle = false
			}
		},

		/**
		 * @param {object} identity what the identity routes answer
		 */
		takeIdentity(identity) {
			this.bluesky = identity
			this.blueskyActive = identity?.active !== false
		},

		/**
		 * @param {number} seconds a unix timestamp, as the server writes them
		 * @return {string} the day it stands for
		 */
		madeOn(seconds) {
			return fullDate(seconds * 1000)
		},

		/**
		 * @param {number} seconds a unix timestamp, as the server writes them
		 * @return {string} how long ago that was
		 */
		lastUsed(seconds) {
			return fromNow(seconds * 1000)
		},

		/** @return {Promise<void>} */
		async loadAppPasswords() {
			this.appPasswordsError = ''
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/social/bluesky/app-passwords'))
				this.appPasswords = data?.app_passwords ?? []
			} catch (error) {
				if (error?.response?.status === 404) {
					this.appPasswordsHidden = true
					return
				}
				logger.debug('Could not load the app passwords', { error })
				this.appPasswordsError = t('social', 'Could not read your app passwords right now.')
			}
		},

		/**
		 * Makes an app password, after the Nextcloud password: whoever holds
		 * one can post as this account.
		 *
		 * @return {Promise<void>}
		 */
		async createAppPassword() {
			const name = this.appPasswordName.trim()
			if (name === '' || this.makingAppPassword) {
				return
			}
			try {
				await confirmPassword()
			} catch {
				return
			}
			this.makingAppPassword = true
			this.appPasswordNameError = ''
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/social/bluesky/app-passwords'), { name })
				this.appPasswords = data.app_passwords
				this.newAppPassword = { name: data.name, password: data.password }
				this.appPasswordName = ''
			} catch (error) {
				const status = error?.response?.status
				if (status === 422) {
					this.appPasswordNameError = error.response.data?.error || t('social', 'Could not make an app password with that name')
				} else {
					showError(status === 403
						? t('social', 'Confirm your password again and retry.')
						: t('social', 'Could not make an app password'))
				}
			} finally {
				this.makingAppPassword = false
			}
		},

		/**
		 * @param {{id: number}} appPassword the row to revoke
		 * @return {Promise<void>}
		 */
		async revokeAppPassword(appPassword) {
			this.revoking = appPassword.id
			try {
				const { data } = await axios.delete(generateUrl('apps/social/api/v1/social/bluesky/app-passwords/{id}', { id: appPassword.id }))
				this.appPasswords = data.app_passwords
				this.confirmingRevoke = 0
			} catch (error) {
				logger.debug('Could not revoke an app password', { error })
				showError(t('social', 'Could not revoke that app password'))
			} finally {
				this.revoking = 0
			}
		},

		/** @return {Promise<void>} */
		async loadOAuthSessions() {
			this.oauthSessionsError = ''
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/social/bluesky/oauth-sessions'))
				this.oauthSessions = data?.sessions ?? []
			} catch (error) {
				if (error?.response?.status === 404) {
					this.oauthSessionsHidden = true
					return
				}
				logger.debug('Could not load the signed-in apps', { error })
				this.oauthSessionsError = t('social', 'Could not read your signed-in apps right now.')
			}
		},

		/**
		 * What a session's scopes let the app do, in words; scopes without
		 * a meaning to a person are left out.
		 *
		 * @param {{scopes: string[]}} session one signed-in app
		 * @return {string[]}
		 */
		sessionGrants(session) {
			const scopes = session.scopes ?? []
			const grants = []
			if (scopes.includes('transition:generic')) {
				grants.push(t('social', 'Post, like, follow and read as you'))
			} else if (scopes.includes('atproto')) {
				grants.push(t('social', 'Know who you are'))
			}
			if (scopes.includes('transition:email')) {
				grants.push(t('social', 'See your e-mail address'))
			}

			return grants
		},

		/**
		 * @param {{id: number}} session the signed-in app to sign out
		 * @return {Promise<void>}
		 */
		async signOutSession(session) {
			this.signingOut = session.id
			try {
				const { data } = await axios.delete(generateUrl('apps/social/api/v1/social/bluesky/oauth-sessions/{id}', { id: session.id }))
				this.oauthSessions = data.sessions
				this.confirmingSignOut = 0
			} catch (error) {
				logger.debug('Could not sign an app out', { error })
				showError(t('social', 'Could not sign the app out'))
			} finally {
				this.signingOut = 0
			}
		},

		/**
		 * Pauses or resumes the account on Bluesky; the block follows what
		 * the server answered, so the switch never says what did not happen.
		 *
		 * @param {boolean} active whether the account should be live there
		 * @return {Promise<void>}
		 */
		async setBlueskyActive(active) {
			this.blueskyActive = active
			this.switchingBluesky = true
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/social/bluesky/state'), { active })
				this.takeIdentity(data)
			} catch (error) {
				logger.debug('Could not change the Bluesky state', { error })
				showError(t('social', 'Could not change whether your posts show on Bluesky'))
				this.blueskyActive = this.bluesky?.active !== false
			} finally {
				this.switchingBluesky = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.bluesky-settings {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 420px;

	&__hint {
		margin: 4px 0 0;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	&__identity {
		display: flex;
		flex-direction: column;
		gap: 2px;
		margin: 0;
	}

	&__identity-row {
		display: flex;
		align-items: center;
		gap: 8px;

		dt {
			flex: 0 0 56px;
			color: var(--color-text-maxcontrast);
			font-size: 13px;
		}

		dd {
			display: flex;
			align-items: center;
			gap: 2px;
			min-width: 0;
			margin: 0;
		}
	}

	&__code {
		font-family: var(--font-face-monospace, monospace);
		font-size: 13px;
		overflow-wrap: anywhere;
		user-select: all;
	}

	&__recovery {
		margin-top: 4px;
	}

	&__custom-handle,
	&__app-passwords,
	&__oauth-sessions {
		margin-top: 12px;
	}

	&__custom-handle-current {
		margin: 8px 0 0;
		overflow-wrap: anywhere;
	}

	&__custom-handle-field {
		margin-top: 8px;
	}

	&__custom-handle-steps {
		margin-top: 8px;
	}

	&__custom-handle-option {
		margin: 8px 0 0;
		font-weight: bold;
	}

	&__custom-handle-check {
		margin-top: 8px;
	}

	&__new-password {
		margin-top: 8px;
		padding: 12px;
		border: 2px solid var(--color-warning);
		border-radius: var(--border-radius-element, 8px);
		background: var(--color-background-dark);
	}

	&__new-password-title {
		margin: 0;
		font-weight: bold;
	}

	&__new-password-value {
		display: flex;
		align-items: center;
		gap: 2px;
		margin: 4px 0 0;

		code {
			font-size: 16px;
		}
	}

	&__new-password-done {
		margin-top: 8px;
	}

	&__app-password-list {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin: 8px 0 0;
		padding: 0;
		list-style: none;
	}

	&__app-password,
	&__oauth-session {
		padding: 8px 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-element, 8px);
	}

	&__app-password-name {
		font-weight: bold;
		overflow-wrap: anywhere;
	}

	&__oauth-client-id {
		font-size: 12px;
		overflow-wrap: anywhere;
	}

	&__oauth-grants {
		margin: 4px 0 0;
		padding-inline-start: 20px;
		font-size: 13px;
		list-style: disc;
	}

	&__app-password-actions {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		margin-top: 8px;

		p {
			flex-basis: 100%;
			margin: 0;
		}
	}

	&__app-password-create {
		display: flex;
		align-items: flex-end;
		gap: 8px;
		margin-top: 8px;
	}

	&__app-password-field {
		flex: 1 1 auto;
	}

	&__title {
		margin: 0;
		font-size: 15px;
		font-weight: bold;
	}

	&__phrase {
		margin: 0 12px 12px;
		padding: 12px;
		border-radius: var(--border-radius-element, 8px);
		background: var(--color-background-dark);
		font-size: 16px;
		line-height: 1.8;
		user-select: all;
	}
}
</style>
