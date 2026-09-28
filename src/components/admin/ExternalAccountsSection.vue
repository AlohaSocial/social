<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('social', 'External accounts')"
		:description="t('social', 'The accounts people registered themselves. Promoting one makes it an ordinary account of this server, with the same username, password and Social account; the groups and apps you give it are then up to you.')">
		<form class="external-accounts__search" @submit.prevent="load(false)">
			<NcTextField
				v-model="query"
				class="external-accounts__query"
				type="search"
				:label="t('social', 'Search by username or name')" />
			<NcButton type="submit" :disabled="loading">
				{{ t('social', 'Search') }}
			</NcButton>
		</form>

		<p v-if="users.length === 0 && !loading" class="external-accounts__empty">
			{{ t('social', 'No external account matches that.') }}
		</p>
		<div v-else class="social-admin__scroll">
			<table class="social-admin__table">
				<thead>
					<tr>
						<th>{{ t('social', 'Account') }}</th>
						<th>{{ t('social', 'Email') }}</th>
						<th>{{ t('social', 'Status') }}</th>
						<th>{{ t('social', 'Registered') }}</th>
						<th>{{ t('social', 'Last login') }}</th>
						<th>{{ t('social', 'Registration source') }}</th>
						<th>{{ t('social', 'Media') }}</th>
						<th>{{ t('social', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="user in users" :key="user.uid" :class="{ 'external-accounts__disabled': !user.enabled }">
						<td>
							<span class="external-accounts__name">{{ user.displayName }}</span>
							<span class="external-accounts__handle">@{{ user.uid }}</span>
						</td>
						<td>{{ user.email }}</td>
						<td>{{ user.enabled ? t('social', 'Enabled') : t('social', 'Disabled') }}</td>
						<td>{{ day(user.created) }}</td>
						<td>{{ user.lastLogin > 0 ? day(user.lastLogin) : t('social', 'Never') }}</td>
						<td>{{ originLabel(user.origin) }}</td>
						<td>{{ humanSize(user.mediaBytes) }}</td>
						<td>
							<div class="social-admin__actions">
								<NcButton size="small" :disabled="busy" @click="setEnabled(user, !user.enabled)">
									{{ user.enabled ? t('social', 'Disable') : t('social', 'Enable') }}
								</NcButton>
								<NcButton size="small" :disabled="busy" @click="ask(user, 'promote')">
									{{ t('social', 'Promote') }}
								</NcButton>
								<NcButton
									size="small"
									variant="error"
									:disabled="busy"
									@click="ask(user, 'delete')">
									{{ t('social', 'Delete') }}
								</NcButton>
							</div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<p v-if="hasMore" class="social-admin__actions">
			<NcButton :disabled="loading" @click="load(true)">
				{{ t('social', 'Show more') }}
			</NcButton>
		</p>

		<ConfirmDialog
			:open="asking !== null"
			:name="asking?.action === 'delete' ? t('social', 'Delete this account?') : t('social', 'Promote this account?')"
			:message="askMessage"
			:confirmLabel="asking?.action === 'delete' ? t('social', 'Delete it') : t('social', 'Promote it')"
			@update:open="asking = null"
			@confirm="confirmed" />
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ConfirmDialog from './ConfirmDialog.vue'
import { confirmPassword, externalUrl } from '../../services/externalApi.js'
import { showError, showSuccess } from '../../services/toast.js'
import { humanSize } from '../../utils/humanSize.js'

const PAGE = 50

/** The external accounts: search them, disable, promote or delete one. */
export default {
	name: 'ExternalAccountsSection',

	components: {
		ConfirmDialog,
		NcButton,
		NcSettingsSection,
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
			users: this.external.users ?? [],
			query: '',
			loading: false,
			busy: false,
			hasMore: (this.external.users ?? []).length >= PAGE,
			/** @type {?{user: object, action: 'promote'|'delete'}} */
			asking: null,
		}
	},

	computed: {
		/** @return {string} */
		askMessage() {
			if (this.asking === null) {
				return ''
			}

			return this.asking.action === 'delete'
				? t('social', '@{handle} is deleted with everything they posted, and every server that knew the account is told. It cannot be undone.', { handle: this.asking.user.uid })
				: t('social', '@{handle} becomes an ordinary account of this server. They keep their username, password and Social account, and from then on the apps and groups you give them apply. It cannot be turned back into an external account.', { handle: this.asking.user.uid })
		},
	},

	watch: {
		'external.users': function(users) {
			if (this.query === '') {
				this.users = users ?? []
				this.hasMore = this.users.length >= PAGE
			}
		},
	},

	methods: {
		t,
		humanSize,

		/** @param {string} origin recorded registration path */
		originLabel(origin) {
			if (typeof origin !== 'string' || origin === '') {
				return t('social', 'Unknown')
			}
			if (origin.startsWith('invite:')) {
				return t('social', 'Invitation')
			}
			if (origin === 'approval') {
				return t('social', 'Administrator approval')
			}
			if (origin === 'open') {
				return t('social', 'Open registration')
			}
			return t('social', 'Unknown')
		},

		/**
		 * @param {number} seconds a unix time
		 * @return {string} the day it names
		 */
		day(seconds) {
			return new Date(seconds * 1000).toLocaleDateString()
		},

		/**
		 * @param {boolean} more whether to add the next page to what is shown
		 * @return {Promise<void>}
		 */
		async load(more) {
			this.loading = true
			try {
				const { data } = await axios.get(externalUrl('/users'), {
					params: { search: this.query.trim(), offset: more ? this.users.length : 0 },
				})
				this.users = more ? [...this.users, ...data] : data
				this.hasMore = data.length >= PAGE
			} catch {
				showError(t('social', 'Could not load the external accounts'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} user the account
		 * @param {boolean} enabled what it should be
		 * @return {Promise<void>}
		 */
		async setEnabled(user, enabled) {
			this.busy = true
			try {
				const { data } = await axios.post(externalUrl('/users/' + encodeURIComponent(user.uid) + '/enabled'), { enabled })
				this.users = this.users.map((other) => other.uid === user.uid ? { ...other, enabled: data.enabled } : other)
			} catch {
				showError(t('social', 'Could not change the account'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} user the account
		 * @param {'promote'|'delete'} action what to ask about
		 */
		ask(user, action) {
			this.asking = { user, action }
		},

		/** @return {Promise<void>} */
		async confirmed() {
			const asked = this.asking
			this.asking = null
			if (asked === null) {
				return
			}

			try {
				await confirmPassword()
			} catch {
				return
			}

			this.busy = true
			const url = externalUrl('/users/' + encodeURIComponent(asked.user.uid) + (asked.action === 'promote' ? '/promote' : ''))
			try {
				const { data } = asked.action === 'promote' ? await axios.post(url) : await axios.delete(url)
				this.users = this.users.filter((other) => other.uid !== asked.user.uid)
				this.$emit('changed', data)
				showSuccess(asked.action === 'promote'
					? t('social', '@{handle} is an ordinary account now', { handle: asked.user.uid })
					: t('social', '@{handle} was deleted', { handle: asked.user.uid }))
			} catch (error) {
				showError(error?.response?.data?.message || t('social', 'Could not change the account'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.external-accounts {
	&__search {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 2);
		align-items: flex-end;
		margin-block-end: calc(var(--default-grid-baseline) * 3);
		max-width: 520px;
	}

	&__query {
		flex: 1;
	}

	&__empty {
		color: var(--color-text-maxcontrast);
	}

	&__name {
		display: block;
		font-weight: bold;
	}

	&__handle {
		color: var(--color-text-maxcontrast);
	}

	&__disabled td {
		opacity: .6;
	}
}
</style>
