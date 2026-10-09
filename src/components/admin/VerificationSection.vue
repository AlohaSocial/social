<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<p class="social-admin__hint">
			{{ t('social', 'A verified account has a check beside its name, wherever it appears here. Verify the people you know are who they say they are, such as the people of your organisation. Moderators can also verify an account from its profile.') }}
		</p>

		<template v-if="administrator">
			<h4 class="verification__title">
				{{ t('social', 'Verifying account') }}
			</h4>
			<p class="social-admin__hint">
				{{ t('social', 'The account the checks are given in the name of: its name is what people read after "Verified by". Without one, the checks are given in the name of this server.') }}
			</p>
			<p class="social-admin__hint">
				{{ t('social', 'With Bluesky switched on, each verification of an account that has a Bluesky identity is also published from this account. Bluesky apps show those checks only once Bluesky trusts the account as a verifier, which Bluesky decides; until then they are shown here only. See https://bsky.social/about/blog/04-21-2025-verification') }}
			</p>
			<form class="verification__form" @submit.prevent="saveVerifier">
				<NcTextField
					v-model="verifierDraft"
					class="verification__field"
					:label="t('social', 'Username of an account on this server')"
					:disabled="savingVerifier" />
				<NcButton type="submit" :disabled="savingVerifier || !verifierChanged">
					<template v-if="savingVerifier" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('social', 'Save') }}
				</NcButton>
			</form>
		</template>
		<p v-if="verifier" class="social-admin__hint">
			{{ publishing
				? t('social', 'Checks are given in the name of {name} (@{account}) and published.', { name: verifier.name, account: verifier.account })
				: t('social', 'Checks are given in the name of {name} (@{account}) and shown here only.', { name: verifier.name, account: verifier.account }) }}
		</p>

		<h4 class="verification__title">
			{{ t('social', 'Verify an account') }}
		</h4>
		<form class="verification__form" @submit.prevent="verify">
			<NcTextField
				v-model="account"
				class="verification__field"
				:label="t('social', 'Handle')"
				placeholder="alice, bob@example.social, carol.bsky.social"
				:disabled="busy" />
			<NcButton type="submit" :disabled="busy || account.trim() === ''">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Verify') }}
			</NcButton>
		</form>

		<h4 class="verification__title">
			{{ t('social', 'Verified accounts') }}
		</h4>
		<p v-if="loaded && verifications.length === 0" class="social-admin__hint">
			{{ t('social', 'No account is verified yet.') }}
		</p>
		<ul v-else class="verification__list">
			<li v-for="item in verifications" :key="item.actor_id" class="verification__item">
				<span class="verification__name">{{ item.name }}</span>
				<code class="verification__account">@{{ item.account }}</code>
				<span class="verification__meta">{{ metaOf(item) }}</span>
				<span v-if="item.did === ''" class="verification__meta">{{ t('social', 'Shown here only') }}</span>
				<NcButton
					variant="tertiary"
					size="small"
					:disabled="busy"
					@click="unverify(item)">
					{{ t('social', 'Remove') }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage, moderationUrl, verifierUrl } from '../../services/adminApi.js'
import { showError, showSuccess } from '../../services/toast.js'
import { fullDate } from '../../utils/relativeTime.js'

/**
 * @typedef {object} Verification an account this instance verified
 * @property {string} actor_id - the account
 * @property {string} account - its handle, the bare username for a local one
 * @property {string} name - its display name
 * @property {string} did - the DID its published record names, '' for an account that has none
 * @property {boolean} local - whether it is an account here
 * @property {string} verified_by - the moderator who verified it, by user id
 * @property {string} created_at - when
 */

/**
 * The accounts this instance verified, and — for an administrator — the
 * account the checks are given in the name of (`VerificationController`).
 */
export default {
	name: 'VerificationSection',

	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
	},

	props: {
		/** whether the reader administers the server, who alone chooses the verifying account */
		administrator: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			/** @type {?{actor_id: string, account: string, name: string, did: string}} */
			verifier: null,
			publishing: false,
			/** @type {Verification[]} */
			verifications: [],
			loaded: false,
			verifierDraft: '',
			savingVerifier: false,
			account: '',
			busy: false,
		}
	},

	computed: {
		/** @return {boolean} */
		verifierChanged() {
			return this.verifierDraft.trim() !== (this.verifier?.account ?? '')
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/** @return {Promise<void>} */
		async load() {
			try {
				const { data } = await axios.get(moderationUrl('/verifications'))
				this.apply(data)
				this.verifications = data.verifications ?? []
				this.loaded = true
			} catch {
				showError(t('social', 'Could not load the verified accounts'))
			}
		},

		/**
		 * @param {{verifier: ?object, publishing: boolean}} state what the server answered
		 */
		apply(state) {
			this.verifier = state.verifier ?? null
			this.publishing = state.publishing === true
			this.verifierDraft = this.verifier?.account ?? ''
		},

		/** @return {Promise<void>} */
		async saveVerifier() {
			this.savingVerifier = true
			try {
				const { data } = await axios.post(verifierUrl(), { account: this.verifierDraft.trim() })
				this.apply(data)
				showSuccess(t('social', 'The verifying account was saved'))
			} catch (error) {
				showError(errorMessage(error, t('social', 'Could not save the verifying account')))
			} finally {
				this.savingVerifier = false
			}
		},

		/** @return {Promise<void>} */
		async verify() {
			this.busy = true
			try {
				await axios.post(moderationUrl('/verifications'), { account: this.account.trim() })
				this.account = ''
				showSuccess(t('social', 'The account is verified'))
				await this.load()
			} catch (error) {
				showError(errorMessage(error, t('social', 'Could not verify the account')))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {Verification} item the verification to take back
		 * @return {Promise<void>}
		 */
		async unverify(item) {
			this.busy = true
			try {
				await axios.delete(moderationUrl('/verifications'), { data: { account: item.actor_id } })
				this.verifications = this.verifications.filter((other) => other.actor_id !== item.actor_id)
				showSuccess(t('social', 'The verification was removed'))
			} catch (error) {
				showError(errorMessage(error, t('social', 'Could not remove the verification')))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {Verification} item a verified account
		 * @return {string} who verified it and when
		 */
		metaOf(item) {
			const date = fullDate(item.created_at)
			if (item.verified_by === '') {
				return t('social', 'Verified on {date}', { date })
			}

			return t('social', 'Verified by {user} on {date}', { user: item.verified_by, date })
		},
	},
}
</script>

<style lang="scss" scoped>
.verification__title {
	margin-block: calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 2);
	font-weight: bold;
}

.verification__form {
	display: flex;
	align-items: flex-end;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}

.verification__field {
	flex: 1 1 240px;
	max-width: 420px;
}

.verification__list {
	display: flex;
	flex-direction: column;
}

.verification__item {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 4px 12px;
	padding-block: 4px;

	& + & {
		border-top: 1px solid var(--color-border);
	}
}

.verification__name {
	font-weight: bold;
}

.verification__account {
	overflow-wrap: anywhere;
}

.verification__meta {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
