<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<form class="cloud-setup" novalidate @submit.prevent="save">
		<h2>{{ t('social', 'Confirm the address of this server') }}</h2>
		<p>
			{{ t('social', 'Every account and post here is identified by this address, and other servers keep it. It cannot be changed later without deleting everything Aloha Social holds.') }}
		</p>
		<p class="cloud-setup__source">
			{{ proposedFrom === 'overwrite.cli.url'
				? t('social', 'Suggested from overwrite.cli.url in config.php.')
				: t('social', 'Suggested from the address of this page, because overwrite.cli.url is not set in config.php.') }}
		</p>

		<NcTextField
			v-model="address"
			class="cloud-setup__input"
			type="url"
			inputClass="setup-input"
			:label="t('social', 'Address of this server')"
			:error="invalid"
			:disabled="saving" />

		<NcNoteCard v-if="problems.length > 0" :type="invalid ? 'error' : 'warning'">
			<ul class="cloud-setup__problems">
				<li v-for="problem in problems" :key="problem">
					{{ explain(problem) }}
				</li>
			</ul>
		</NcNoteCard>

		<NcCheckboxRadioSwitch
			v-if="problems.length > 0 && !invalid"
			v-model="allowUnsafe"
			class="cloud-setup__override"
			:disabled="saving">
			{{ t('social', 'Use this address anyway. Only for a test instance: other servers may not reach it, and it cannot be changed later.') }}
		</NcCheckboxRadioSwitch>

		<NcNoteCard v-if="error !== ''" type="error">
			{{ error }}
		</NcNoteCard>

		<NcButton variant="primary" type="submit" :disabled="!canSave">
			<template v-if="saving" #icon>
				<NcLoadingIcon :size="20" />
			</template>
			{{ t('social', 'Use this address') }}
		</NcButton>

		<slot />
	</form>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { cloudAddressProblems } from '../utils/cloudAddress.js'

/**
 * The first setup: the address every id is built from, proposed by the server
 * and stored only once an administrator confirms it.
 *
 * The form checks the address as it is typed, by the rules the server holds
 * it to; the server's answer has the last word and replaces that check.
 */
export default {
	name: 'CloudAddressSetup',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	props: {
		/** the address the server derived */
		proposed: {
			type: String,
			default: '',
		},

		/** where it came from: `overwrite.cli.url` or `request` */
		proposedFrom: {
			type: String,
			default: 'request',
		},
	},

	emits: ['saved'],

	data() {
		return {
			address: this.proposed,
			allowUnsafe: false,
			saving: false,
			error: '',
			/** what the server refused the last address it was sent for */
			refused: null,
		}
	},

	computed: {
		/** @return {string[]} what is wrong with the address in the field */
		problems() {
			if (this.refused !== null && this.refused.address === this.address) {
				return this.refused.problems
			}

			return cloudAddressProblems(this.address)
		},

		/** @return {boolean} whether the address cannot be used even with the override */
		invalid() {
			return this.problems.includes('invalid')
		},

		/** @return {boolean} */
		canSave() {
			return !this.saving && !this.invalid && (this.problems.length === 0 || this.allowUnsafe)
		},
	},

	methods: {
		t,

		/**
		 * @param {string} problem a code of CloudAddressRules
		 * @return {string}
		 */
		explain(problem) {
			switch (problem) {
				case 'http':
					return t('social', 'It uses http. Other servers expect https, and many refuse plain http.')
				case 'loopback':
					return t('social', 'It names this machine itself, such as localhost. No other server can reach it.')
				case 'ip':
					return t('social', 'It is a bare IP address. Accounts would be named after the number, and it changes when the server moves.')
				case 'index_php':
					return t('social', 'It contains /index.php, which would stay in every id. Turn on pretty URLs in Nextcloud first, then reload this page.')
				default:
					return t('social', 'This is not a web address. It has to start with https:// and name a host, without anything after a question mark.')
			}
		},

		async save() {
			if (!this.canSave) {
				return
			}

			this.saving = true
			this.error = ''
			const address = this.address
			try {
				await axios.post(generateUrl('/apps/social/api/v1/config/cloudAddress'), {
					cloudAddress: address,
					allowUnsafe: this.allowUnsafe,
				})
				this.$emit('saved', address)
			} catch (error) {
				const status = error?.response?.status
				const problems = error?.response?.data?.problems
				if (status === 422 && Array.isArray(problems)) {
					this.refused = { address, problems }
				} else if (status === 409) {
					this.error = t('social', 'The address has already been set. Reload the page.')
				} else {
					this.error = t('social', 'The address could not be saved. Try again.')
				}
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.cloud-setup {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 3);
	max-width: 640px;

	&__source {
		color: var(--color-text-maxcontrast);
	}

	&__input {
		width: 100%;
	}

	&__problems {
		list-style: disc;
		padding-inline-start: calc(var(--default-grid-baseline) * 4);
	}
}
</style>
