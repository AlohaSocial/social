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
			<label class="external-accounts__filter">
				{{ t('social', 'Sort by') }}
				<select v-model="sort">
					<option value="handle">{{ t('social', 'Username') }}</option>
					<option value="newest">{{ t('social', 'Newest first') }}</option>
					<option value="oldest">{{ t('social', 'Oldest first') }}</option>
				</select>
			</label>
			<label class="external-accounts__filter">
				{{ t('social', 'Account status') }}
				<select v-model="status">
					<option value="any">{{ t('social', 'Any status') }}</option>
					<option value="enabled">{{ t('social', 'Enabled') }}</option>
					<option value="disabled">{{ t('social', 'Disabled') }}</option>
				</select>
			</label>
			<label class="external-accounts__filter">
				{{ t('social', 'Registration source') }}
				<select v-model="source">
					<option value="any">{{ t('social', 'Any source') }}</option>
					<option value="open">{{ t('social', 'Open registration') }}</option>
					<option value="approval">{{ t('social', 'Administrator approval') }}</option>
					<option value="invite">{{ t('social', 'Invitation') }}</option>
				</select>
			</label>
			<label class="external-accounts__filter">
				{{ t('social', 'Last login') }}
				<select v-model="lastLogin">
					<option value="any">{{ t('social', 'Any login activity') }}</option>
					<option value="never">{{ t('social', 'Never logged in') }}</option>
					<option value="seen">{{ t('social', 'Has logged in') }}</option>
				</select>
			</label>
			<label class="external-accounts__filter">
				{{ t('social', 'Registered from') }}
				<input v-model="registeredFrom" type="date">
			</label>
			<label class="external-accounts__filter">
				{{ t('social', 'Registered until') }}
				<input v-model="registeredUntil" type="date">
			</label>
			<label class="external-accounts__filter">
				{{ t('social', 'Minimum media usage in MB') }}
				<input
					v-model="minimumMediaMb"
					type="number"
					min="0"
					step="1">
			</label>
			<label class="external-accounts__filter">
				{{ t('social', 'Registration notice') }}
				<select v-model="noticeAcceptance">
					<option value="any">{{ t('social', 'Any acceptance status') }}</option>
					<option value="accepted">{{ t('social', 'Notice accepted') }}</option>
					<option value="missing">{{ t('social', 'No acceptance record') }}</option>
				</select>
			</label>
			<NcButton type="submit" :disabled="loading">
				{{ t('social', 'Search') }}
			</NcButton>
		</form>

		<p v-if="users.length === 0 && !loading && !hasMore" class="external-accounts__empty">
			{{ t('social', 'No external account matches that.') }}
		</p>
		<div v-if="users.length > 0" class="social-admin__scroll">
			<table class="social-admin__table">
				<thead>
					<tr>
						<th>{{ t('social', 'Account') }}</th>
						<th>{{ t('social', 'Email') }}</th>
						<th>{{ t('social', 'Email confirmation') }}</th>
						<th>{{ t('social', 'Status') }}</th>
						<th>{{ t('social', 'Registered') }}</th>
						<th>{{ t('social', 'Last login') }}</th>
						<th>{{ t('social', 'Registration source') }}</th>
						<th>{{ t('social', 'Media') }}</th>
						<th>{{ t('social', 'Registration notice') }}</th>
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
						<td>{{ emailVerificationLabel(user.emailVerified) }}</td>
						<td>{{ user.enabled ? t('social', 'Enabled') : t('social', 'Disabled') }}</td>
						<td>{{ day(user.created) }}</td>
						<td>{{ user.lastLogin > 0 ? day(user.lastLogin) : t('social', 'Never') }}</td>
						<td>{{ originLabel(user.origin) }}</td>
						<td>{{ humanSize(user.mediaBytes) }}</td>
						<td>
							<details v-if="user.noticeAcceptedAt > 0" class="external-accounts__notice">
								<summary :title="user.noticeVersion">
									{{ t('social', 'Accepted on {date}', { date: day(user.noticeAcceptedAt) }) }}
								</summary>
								<p>{{ user.noticeSnapshot }}</p>
								<small>{{ t('social', 'Version') }}: {{ user.noticeVersion }}</small>
							</details>
							<span v-else>{{ t('social', 'No acceptance record') }}</span>
						</td>
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
				{{ users.length === 0 ? t('social', 'Continue searching') : t('social', 'Show more') }}
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
			sort: 'handle',
			status: 'any',
			source: 'any',
			lastLogin: 'any',
			registeredFrom: '',
			registeredUntil: '',
			minimumMediaMb: '',
			noticeAcceptance: 'any',
			loading: false,
			busy: false,
			hasMore: this.external.usersHasMore ?? (this.external.users ?? []).length >= PAGE,
			nextOffset: this.external.usersNextOffset ?? (this.external.users ?? []).length,
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
			this.users = users ?? []
			this.hasMore = this.external.usersHasMore ?? this.users.length >= PAGE
			this.nextOffset = this.external.usersNextOffset ?? this.users.length
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

		/** @param {?boolean} verified whether email confirmation was completed */
		emailVerificationLabel(verified) {
			if (verified === true) {
				return t('social', 'Confirmed')
			}
			return verified === false ? t('social', 'Not confirmed') : t('social', 'Unknown')
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
					params: {
						search: this.query.trim(),
						offset: more ? this.nextOffset : 0,
						sort: this.sort,
						status: this.status,
						source: this.source,
						lastLogin: this.lastLogin,
						registeredAfter: this.timestamp(this.registeredFrom, false),
						registeredBefore: this.timestamp(this.registeredUntil, true),
						minimumMediaBytes: Math.max(0, Number(this.minimumMediaMb) || 0) * 1024 * 1024,
						noticeAcceptance: this.noticeAcceptance,
					},
				})
				this.users = more ? [...this.users, ...data.users] : data.users
				this.nextOffset = data.nextOffset
				this.hasMore = data.hasMore
			} catch {
				showError(t('social', 'Could not load the external accounts'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} day a date input
		 * @param {boolean} endOfDay include the whole final day
		 */
		timestamp(day, endOfDay) {
			if (!day) {
				return 0
			}
			const date = new Date(`${day}T${endOfDay ? '23:59:59.999' : '00:00:00'}`)
			return Math.floor(date.getTime() / 1000)
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
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
		gap: calc(var(--default-grid-baseline) * 2);
		align-items: end;
		margin-block-end: calc(var(--default-grid-baseline) * 3);
		max-width: 520px;
	}

	&__query {
		grid-column: span 2;
	}

	&__filter {
		display: grid;
		gap: 4px;
		font-size: var(--font-size-small);
		color: var(--color-text-maxcontrast);

		select,
		input {
			min-width: 0;
			min-height: 44px;
			padding: 6px 10px;
			border: 1px solid var(--color-border-dark);
			border-radius: var(--border-radius);
			background: var(--color-main-background);
			color: var(--color-main-text);
			font: inherit;
		}
	}

	&__notice {
		max-width: 280px;

		summary {
			cursor: pointer;
		}

		p {
			max-height: 180px;
			overflow: auto;
			white-space: pre-wrap;
		}
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
