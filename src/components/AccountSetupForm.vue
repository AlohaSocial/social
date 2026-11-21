<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<form class="account-setup-form" @submit.prevent="create">
		<h2 class="account-setup__heading">
			{{ t('social', 'Choose your username') }}
		</h2>
		<label class="account-setup__label" for="account-setup-handle">
			{{ t('social', 'Username') }}
		</label>
		<input
			id="account-setup-handle"
			v-model.trim="handle"
			type="text"
			class="account-setup__input"
			autocomplete="off"
			spellcheck="false"
			maxlength="64"
			pattern="[A-Za-z0-9_]+([A-Za-z0-9_.\-]*[A-Za-z0-9_])?"
			aria-describedby="account-setup-address"
			:aria-invalid="createError !== null"
			:disabled="busy"
			required>
		<!-- the address is shown as it is typed: the choice is permanent -->
		<p id="account-setup-address" class="account-setup__address">
			@{{ handle || '…' }}@{{ addressHost }}
		</p>
		<!-- the account's Bluesky address, where the administrator has enabled it -->
		<p v-if="blueskyEnabled && handle !== ''" class="account-setup__address account-setup__address--bluesky">
			<span class="account-setup__bluesky-label">{{ t('social', 'On Bluesky too:') }}</span>
			@{{ blueskyAddress }}
		</p>
		<p class="account-setup__hint">
			{{ t('social', 'Letters, numbers and underscores, with dots and dashes in between. Your username cannot be changed later.') }}
		</p>
		<p v-if="blueskyEnabled" class="account-setup__hint">
			{{ t('social', 'A Bluesky address has only small letters, numbers and dashes, so yours is written as shown. If another account here already has it, a number is added to it.') }}
		</p>
		<ServerError v-if="createError" class="account-setup__error" :error="createError" />
		<NcButton type="submit" variant="primary" :disabled="busy || handle === ''">
			<template #icon>
				<NcLoadingIcon v-if="busy" :size="20" />
				<IconAccountPlus v-else :size="20" />
			</template>
			{{ t('social', 'Create account') }}
		</NcButton>
	</form>
</template>

<script>
/**
 * The username question of the account setup, shared by the app's own setup
 * page and the sign-in page an app opens: the handle, the addresses it makes,
 * and `POST /api/v1/account/create`.
 */
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconAccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import ServerError from './ServerError.vue'
import logger from '../services/logger.js'
import { blueskyHandle } from '../utils/blueskyHandle.js'
import { errorOf } from '../utils/serverError.js'

export default {
	name: 'AccountSetupForm',
	components: {
		IconAccountPlus,
		NcButton,
		NcLoadingIcon,
		ServerError,
	},

	props: {
		/** the username typed so far (`v-model`) */
		modelValue: {
			type: String,
			default: '',
		},

		/** the host the address ends in: the social address */
		addressHost: {
			type: String,
			required: true,
		},

		/** whether the account gets a Bluesky address as well */
		blueskyEnabled: {
			type: Boolean,
			default: false,
		},

		/** the host Bluesky handles are issued under */
		blueskyHost: {
			type: String,
			default: '',
		},
	},

	emits: ['created', 'busy', 'update:modelValue'],

	data() {
		return {
			/** whether the account is being made */
			busy: false,
			/** @type {{text: string, details: string}|null} */
			createError: null,
		}
	},

	computed: {
		handle: {
			/** @return {string} the username typed so far */
			get() {
				return this.modelValue
			},

			/** @param {string} value what is in the field now */
			set(value) {
				this.$emit('update:modelValue', value)
			},
		},

		/**
		 * The Bluesky handle the typed username is given, as the server maps
		 * it; a number the server adds for a taken one is not known here.
		 *
		 * @return {string} `label.host`
		 */
		blueskyAddress() {
			return blueskyHandle(this.handle, this.blueskyHost)
		},
	},

	methods: {
		t,

		/**
		 * Creates the chosen local identity and emits it, or displays the refusal.
		 *
		 * @return {Promise<void>}
		 */
		async create() {
			this.busy = true
			this.$emit('busy', true)
			this.createError = null
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/account/create'), { username: this.handle })
				this.$emit('created', data?.result?.account ?? null)
			} catch (error) {
				logger.warn('Could not create the account', { error })
				this.createError = errorOf(error, t('social', 'Could not create the account'))
			} finally {
				this.busy = false
				this.$emit('busy', false)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.account-setup-form {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	text-align: center;
}

.account-setup {
	&__heading {
		margin: 0;
		font-size: 17px;
		font-weight: 600;
	}

	&__label {
		font-size: 13px;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
	}

	&__input {
		width: 100%;
		min-width: 0;
		margin: 0;
		text-align: center;
	}

	/* the address the username would make, in the shape FirstRun shows the
	   one an account has */
	&__address {
		margin: 0 auto;
		padding: 10px 14px;
		border-radius: var(--border-radius-element, 8px);
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
		font-size: 16px;
		font-weight: 700;
		overflow-wrap: anywhere;
		text-align: center;
		max-width: 100%;
	}

	/* the second address is the quieter one: the fediverse address is the
	   one the username is for */
	&__address--bluesky {
		background: none;
		border: 1px solid var(--color-border);
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		font-weight: 600;
		text-align: center;
	}

	&__bluesky-label {
		font-weight: 400;
	}

	&__hint {
		margin: 0;
		font-size: 13px;
		line-height: 1.5;
		color: var(--color-text-maxcontrast);
		text-align: center;
	}

	&__error {
		margin: 0;
		font-size: 13px;
		color: var(--color-error);
		text-align: center;
	}
}
</style>
