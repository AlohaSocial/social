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
import Check from 'vue-material-design-icons/Check.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import KeyOutline from 'vue-material-design-icons/KeyOutline.vue'
import { translate as t } from '@nextcloud/l10n'
import { confirmPassword } from '../services/externalApi.js'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'

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
	},

	data() {
		return {
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
			/** which of the two was just copied: 'handle', 'did' or '' */
			blueskyCopied: '',
			blueskyCopyTimer: null,
			recovering: false,
			/** the twelve words, for as long as the dialog shows them */
			phrase: '',
		}
	},

	computed: {
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
		 * @param {'handle'|'did'|'phrase'} which what was asked for
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
