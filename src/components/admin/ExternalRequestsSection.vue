<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<h4 class="requests__heading">
			{{ t('social', 'Waiting for approval') }}
		</h4>
		<p v-if="requests.length === 0" class="requests__empty">
			{{ t('social', 'Nobody is waiting.') }}
		</p>
		<div v-else class="social-admin__scroll">
			<table class="social-admin__table">
				<thead>
					<tr>
						<th>{{ t('social', 'Username') }}</th>
						<th>{{ t('social', 'Email') }}</th>
						<th>{{ t('social', 'Registered') }}</th>
						<th>{{ t('social', 'Decision') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="request in requests" :key="request.id">
						<td>@{{ request.handle }}</td>
						<td>{{ request.email }}</td>
						<td>{{ when(request.created) }}</td>
						<td>
							<div class="social-admin__actions">
								<NcButton
									size="small"
									variant="primary"
									:disabled="busy"
									@click="approve(request)">
									{{ t('social', 'Approve') }}
								</NcButton>
								<NcButton
									size="small"
									variant="error"
									:disabled="busy"
									@click="askReject(request)">
									{{ t('social', 'Reject') }}
								</NcButton>
							</div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<h4 class="requests__heading">
			{{ t('social', 'Invitation links') }}
		</h4>
		<form class="requests__invite" @submit.prevent="invite">
			<NcTextField
				v-model="note"
				class="requests__note"
				:label="t('social', 'Note (who it is for)')"
				:maxlength="255" />
			<NcTextField
				v-model="maxUses"
				class="requests__small"
				type="number"
				min="0"
				:label="t('social', 'Uses (0 for any)')" />
			<NcTextField
				v-model="days"
				class="requests__small"
				type="number"
				min="0"
				:label="t('social', 'Days (0 for ever)')" />
			<NcButton type="submit" variant="primary" :disabled="busy">
				{{ t('social', 'Create link') }}
			</NcButton>
		</form>
		<p v-if="invites.length === 0" class="requests__empty">
			{{ t('social', 'There are no invitation links.') }}
		</p>
		<div v-else class="social-admin__scroll">
			<table class="social-admin__table">
				<thead>
					<tr>
						<th>{{ t('social', 'Note') }}</th>
						<th>{{ t('social', 'Made by') }}</th>
						<th>{{ t('social', 'Used') }}</th>
						<th>{{ t('social', 'Expires') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="item in invites" :key="item.id">
						<td>{{ item.note || '—' }}</td>
						<td>{{ item.creator }}</td>
						<td>{{ item.maxUses > 0 ? t('social', '{uses} of {max}', { uses: item.uses, max: item.maxUses }) : item.uses }}</td>
						<td>{{ item.expires > 0 ? when(item.expires) : t('social', 'Never') }}</td>
						<td>
							<div class="social-admin__actions">
								<NcButton size="small" @click="copy(item)">
									{{ t('social', 'Copy link') }}
								</NcButton>
								<NcButton
									size="small"
									variant="error"
									:disabled="busy"
									@click="revoke(item)">
									{{ t('social', 'Withdraw') }}
								</NcButton>
							</div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<NcDialog
			:open="rejecting !== null"
			:name="t('social', 'Reject this registration?')"
			:buttons="rejectButtons"
			@update:open="rejecting = null">
			<p>{{ t('social', 'The registration is deleted and the person is emailed. You can tell them why.') }}</p>
			<NcTextArea v-model="reason" :label="t('social', 'Reason (optional)')" rows="3" />
		</NcDialog>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { externalUrl } from '../../services/externalApi.js'
import { showError, showSuccess } from '../../services/toast.js'

/** The approval queue of external registrations, and the invitation links. */
export default {
	name: 'ExternalRequestsSection',

	components: {
		NcButton,
		NcDialog,
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
			busy: false,
			note: '',
			maxUses: '1',
			days: '7',
			/** @type {?{id: number, handle: string}} the registration being turned down */
			rejecting: null,
			reason: '',
		}
	},

	computed: {
		/** @return {Array<{id: number, handle: string, email: string, created: number}>} */
		requests() {
			return this.external.requests ?? []
		},

		/** @return {Array<object>} */
		invites() {
			return this.external.invites ?? []
		},

		/** @return {object[]} */
		rejectButtons() {
			return [
				{ label: t('social', 'Cancel'), callback: () => { this.rejecting = null } },
				{ label: t('social', 'Reject'), variant: 'error', callback: () => this.reject() },
			]
		},
	},

	methods: {
		t,

		/**
		 * @param {number} seconds a unix time
		 * @return {string} the day it names
		 */
		when(seconds) {
			return new Date(seconds * 1000).toLocaleDateString()
		},

		/**
		 * Runs a change and hands what the server says afterwards up.
		 *
		 * @param {() => Promise<{data: object}>} request the change
		 * @param {string} failure what to say when it fails
		 * @return {Promise<object|null>} the answer
		 */
		async change(request, failure) {
			this.busy = true
			try {
				const { data } = await request()
				return data
			} catch (error) {
				showError(error?.response?.data?.message || failure)
				return null
			} finally {
				this.busy = false
			}
		},

		/** @param {{id: number, handle: string}} request the registration */
		async approve(request) {
			const data = await this.change(() => axios.post(externalUrl('/requests/' + request.id)), t('social', 'Could not approve the registration'))
			if (data) {
				this.$emit('changed', data)
				showSuccess(t('social', '@{handle} can log in now', { handle: request.handle }))
			}
		},

		/** @param {{id: number, handle: string}} request the registration */
		askReject(request) {
			this.reason = ''
			this.rejecting = request
		},

		async reject() {
			const request = this.rejecting
			this.rejecting = null
			if (request === null) {
				return
			}
			const data = await this.change(
				() => axios.delete(externalUrl('/requests/' + request.id), { data: { reason: this.reason } }),
				t('social', 'Could not reject the registration'),
			)
			if (data) {
				this.$emit('changed', data)
			}
		},

		async invite() {
			const data = await this.change(
				() => axios.post(externalUrl('/invites'), {
					note: this.note.trim(),
					maxUses: parseInt(this.maxUses, 10) || 0,
					days: parseInt(this.days, 10) || 0,
				}),
				t('social', 'Could not create the invitation'),
			)
			if (data) {
				this.note = ''
				this.$emit('changed', { ...this.external, invites: [data, ...this.invites] })
				await this.copy(data)
			}
		},

		/** @param {{id: number}} item the invitation */
		async revoke(item) {
			const data = await this.change(() => axios.delete(externalUrl('/invites/' + item.id)), t('social', 'Could not withdraw the invitation'))
			if (data) {
				this.$emit('changed', data)
			}
		},

		/**
		 * @param {{url: string}} item the invitation whose link to copy
		 * @return {Promise<void>}
		 */
		async copy(item) {
			try {
				await navigator.clipboard.writeText(item.url)
				showSuccess(t('social', 'Link copied'))
			} catch {
				window.prompt(t('social', 'The invitation link'), item.url)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.requests {
	&__heading {
		margin-block: calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 2);
		font-size: 1.05em;
		font-weight: bold;
	}

	&__empty {
		color: var(--color-text-maxcontrast);
	}

	&__invite {
		display: flex;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 2);
		align-items: flex-end;
		margin-block-end: calc(var(--default-grid-baseline) * 3);
	}

	&__note {
		flex: 1 1 220px;
	}

	&__small {
		flex: 0 0 150px;
	}
}
</style>
