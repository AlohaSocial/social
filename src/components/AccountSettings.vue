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

			<!-- the same account as the other network sees it. Nothing here is a
			     setting: the identity exists because this server offers one, and
			     the one thing the person can take away is the phrase that proves
			     it is theirs without this server -->
			<section v-if="blueskyOffered" class="account-settings__bluesky">
				<h3 class="account-settings__bluesky-title">
					{{ t('social', 'Bluesky') }}
				</h3>
				<p class="account-settings__hint account-settings__hint--block">
					{{ t('social', 'This account is also reachable on Bluesky, because this server offers one.') }}
				</p>
				<p v-if="blueskyError" class="account-settings__hint account-settings__hint--block">
					{{ blueskyError }}
				</p>
				<p v-else-if="!bluesky" class="account-settings__hint account-settings__hint--block">
					{{ t('social', 'Loading …') }}
				</p>
				<template v-else>
					<dl class="account-settings__identity">
						<div class="account-settings__identity-row">
							<dt>{{ t('social', 'Handle') }}</dt>
							<dd>
								<a
									class="account-settings__code"
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
						<div class="account-settings__identity-row">
							<dt>{{ t('social', 'DID') }}</dt>
							<dd>
								<code class="account-settings__code">{{ bluesky.did }}</code>
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
						class="account-settings__switch account-settings__bluesky-switch"
						:disabled="switchingBluesky"
						@update:modelValue="setBlueskyActive">
						{{ t('social', 'Show my posts on Bluesky') }}
					</NcCheckboxRadioSwitch>
					<p v-if="bluesky.active === false" class="account-settings__hint account-settings__hint--block">
						{{ t('social', 'This account is paused on Bluesky: nothing new is published there until it is switched back on.') }}
					</p>
					<div class="account-settings__recovery">
						<NcButton :disabled="recovering" @click="createRecoveryPhrase">
							<template #icon>
								<NcLoadingIcon v-if="recovering" :size="20" />
								<KeyOutline v-else :size="20" />
							</template>
							{{ bluesky.recovery_key ? t('social', 'Create a new recovery phrase') : t('social', 'Create recovery phrase') }}
						</NcButton>
						<p class="account-settings__hint account-settings__hint--block">
							{{ bluesky.recovery_key
								? t('social', 'The phrase you have stops working the moment a new one is made.')
								: t('social', 'Twelve words that prove this Bluesky identity is yours even without this server. They are shown once, for you to write down.') }}
						</p>
					</div>
					<!-- what a Bluesky app signs in with: this server is its
					     hosting provider, and the Nextcloud password is never
					     handed to it -->
					<div v-if="bluesky.active !== false && !appPasswordsHidden" class="account-settings__app-passwords">
						<h4 class="account-settings__bluesky-title">
							{{ t('social', 'App passwords for Bluesky apps') }}
						</h4>
						<p class="account-settings__hint account-settings__hint--block">
							{{ t('social', 'To use a Bluesky app with this account, sign in there with your Bluesky handle and an app password made here, and choose this server as your hosting provider: {server}.', { server: origin }) }}
						</p>
						<p v-if="appPasswordsError" class="account-settings__hint account-settings__hint--block">
							{{ appPasswordsError }}
						</p>
						<p v-else-if="appPasswords === null" class="account-settings__hint account-settings__hint--block">
							{{ t('social', 'Loading …') }}
						</p>
						<template v-else>
							<div v-if="newAppPassword" class="account-settings__new-password" role="status">
								<p class="account-settings__new-password-title">
									{{ t('social', 'New app password for {name}', { name: newAppPassword.name }) }}
								</p>
								<p class="account-settings__new-password-value">
									<code class="account-settings__code">{{ newAppPassword.password }}</code>
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
								<p class="account-settings__hint account-settings__hint--block">
									{{ t('social', 'Copy it now: it is not shown again.') }}
								</p>
								<NcButton class="account-settings__new-password-done" @click="newAppPassword = null">
									{{ t('social', 'Done') }}
								</NcButton>
							</div>
							<ul v-if="appPasswords.length > 0" class="account-settings__app-password-list">
								<li v-for="appPassword in appPasswords" :key="appPassword.id" class="account-settings__app-password">
									<span class="account-settings__app-password-name">{{ appPassword.name }}</span>
									<span class="account-settings__hint">
										{{ t('social', 'Made {date}', { date: madeOn(appPassword.creation) }) }}
										·
										{{ appPassword.last_used > 0
											? t('social', 'Last used {when}', { when: lastUsed(appPassword.last_used) })
											: t('social', 'Never used') }}
									</span>
									<div v-if="confirmingRevoke === appPassword.id" class="account-settings__app-password-actions">
										<p class="account-settings__hint account-settings__hint--block">
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
									<div v-else class="account-settings__app-password-actions">
										<NcButton @click="confirmingRevoke = appPassword.id">
											{{ t('social', 'Revoke') }}
										</NcButton>
									</div>
								</li>
							</ul>
							<!-- not a form of its own: it sits inside the account
							     form, so Enter here makes a password instead of
							     saving the settings below -->
							<div class="account-settings__app-password-create">
								<NcTextField
									v-model="appPasswordName"
									class="account-settings__app-password-field"
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
				</template>
				<NcDialog
					:open="phrase !== ''"
					:name="t('social', 'Your recovery phrase')"
					:buttons="phraseButtons"
					@update:open="closePhrase">
					<NcNoteCard type="warning">
						{{ t('social', 'These twelve words are shown once and never again. Write them down and keep them where only you can find them.') }}
					</NcNoteCard>
					<p class="account-settings__phrase">
						<code>{{ phrase }}</code>
					</p>
				</NcDialog>
			</section>

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
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Check from 'vue-material-design-icons/Check.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import KeyOutline from 'vue-material-design-icons/KeyOutline.vue'
import { translate as t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useServerData } from '../composables/useServerData.js'
import { confirmPassword } from '../services/externalApi.js'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { fromNow, fullDate } from '../utils/relativeTime.js'
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
		Check,
		ContentCopy,
		ContentSave,
		KeyOutline,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
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

			/**
			 * The Bluesky identity, once asked for; null until it comes
			 *
			 * @type {{handle: string, did: string, url: string, state: string, recovery_key: boolean, active: boolean}|null}
			 */
			bluesky: null,
			blueskyError: '',
			/** the pause switch: it moves at once, and comes back if the server refuses */
			blueskyActive: true,
			switchingBluesky: false,
			/** what was just copied: 'handle', 'did', 'phrase', 'password' or '' */
			blueskyCopied: '',
			blueskyCopyTimer: null,
			recovering: false,
			/** the twelve words, for as long as the dialog shows them */
			phrase: '',

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

		/** @return {string} the address a Bluesky app is given as the hosting provider */
		origin() {
			return window.location.origin
		},

		/** @return {boolean} whether the identity is here and live, which app passwords need */
		blueskyLive() {
			return this.bluesky !== null && this.bluesky.active !== false
		},

		/** @return {boolean} whether this instance gives every account a Bluesky identity */
		blueskyOffered() {
			return this.serverData?.bluesky?.enabled === true
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
		// asked for the first time the identity is live, whether on load or
		// when it is switched back on
		blueskyLive(live) {
			if (live && this.appPasswords === null && !this.appPasswordsHidden) {
				this.loadAppPasswords()
			}
		},

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
		if (this.blueskyOffered) {
			this.loadBluesky()
		}
	},

	beforeUnmount() {
		window.clearTimeout(this.blueskyCopyTimer)
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
		 * @param {'handle'|'did'|'phrase'|'password'} which what was asked for
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

	&__bluesky {
		max-width: 420px;
		margin-bottom: 8px;
		padding-top: 8px;
		border-top: 1px solid var(--color-border);
	}

	&__bluesky-title {
		margin: 0;
		font-size: 15px;
		font-weight: bold;
	}

	&__identity {
		display: flex;
		flex-direction: column;
		gap: 2px;
		margin: 8px 0;
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

	&__app-passwords {
		margin-top: 12px;
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

	&__app-password {
		padding: 8px 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-element, 8px);
	}

	&__app-password-name {
		font-weight: bold;
		overflow-wrap: anywhere;
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

	&__phrase {
		margin: 0 12px 12px;
		padding: 12px;
		border-radius: var(--border-radius-element, 8px);
		background: var(--color-background-dark);
		font-size: 16px;
		line-height: 1.8;
		user-select: all;
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
