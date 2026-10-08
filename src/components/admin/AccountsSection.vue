<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<form class="accounts__search" @submit.prevent="search(false)">
			<NcTextField
				v-model="query"
				class="accounts__query"
				type="search"
				:label="t('social', 'Search')"
				placeholder="bob@instance.example" />
			<NcSelect
				v-model="origin"
				class="accounts__filter"
				:inputLabel="t('social', 'Origin')"
				:options="origins"
				:clearable="false"
				:searchable="false"
				label="label" />
			<NcSelect
				v-model="status"
				class="accounts__filter"
				:inputLabel="t('social', 'State')"
				:options="states"
				:clearable="false"
				:searchable="false"
				label="label" />
			<NcButton type="submit" variant="primary" :disabled="loading">
				<template v-if="loading" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Search') }}
			</NcButton>
		</form>

		<NcEmptyContent
			v-if="accounts.length === 0 && !loading"
			:name="t('social', 'No account matches that.')">
			<template #icon>
				<IconAccountSearch :size="20" />
			</template>
		</NcEmptyContent>

		<div v-else-if="accounts.length > 0" class="social-admin__scroll">
			<table class="social-admin__table accounts__table">
				<thead>
					<tr>
						<th>{{ t('social', 'Account') }}</th>
						<th>{{ t('social', 'Instance') }}</th>
						<th>{{ t('social', 'State') }}</th>
						<th>{{ t('social', 'History') }}</th>
						<th>{{ t('social', 'Decision') }}</th>
					</tr>
				</thead>
				<!-- a handle and an instance name are whatever a remote server
				     sent, so every cell here is interpolated and none of this
				     page uses `v-html` -->
				<tbody v-for="account in accounts" :key="account.actor_id" class="accounts__group">
					<tr>
						<td>{{ account.handle || account.username }}</td>
						<td>{{ account.local ? t('social', 'This instance') : (account.domain || '') }}</td>
						<td class="accounts__state">
							{{ stateOf(account.level) }}
						</td>
						<td>
							<span v-if="!account.strikes">{{ t('social', 'None') }}</span>
							<NcButton
								v-else
								size="small"
								@click="toggleHistory(account)">
								{{ n('social', '%n strike', '%n strikes', account.strikes) }}
							</NcButton>
						</td>
						<td>
							<div class="social-admin__actions">
								<NcButton
									size="small"
									:disabled="account.level === 'silence'"
									@click="askModerate(account, 'silence')">
									{{ t('social', 'Silence') }}
								</NcButton>
								<NcButton
									size="small"
									variant="error"
									:disabled="account.level === 'suspend'"
									@click="askModerate(account, 'suspend')">
									{{ t('social', 'Suspend') }}
								</NcButton>
								<NcButton
									size="small"
									:disabled="account.level === ''"
									@click="askModerate(account, '')">
									{{ t('social', 'Lift') }}
								</NcButton>
							</div>
						</td>
					</tr>
					<tr v-if="history[account.actor_id]" class="accounts__history">
						<td colspan="5">
							<ul>
								<li v-for="(strike, index) in history[account.actor_id]" :key="index">
									{{ strikeLine(strike) }}
								</li>
							</ul>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<p v-if="hasMore" class="social-admin__actions">
			<NcButton :disabled="loading" @click="search(true)">
				{{ t('social', 'Show more') }}
			</NcButton>
		</p>

		<ConfirmDialog
			v-if="pending !== null"
			:open="true"
			:name="t('social', 'Suspend this account?')"
			:message="pending.message"
			:confirmLabel="t('social', 'Suspend')"
			@update:open="pending = null"
			@confirm="moderate(pending.account, pending.level)" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import IconAccountSearch from 'vue-material-design-icons/AccountSearch.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ConfirmDialog from './ConfirmDialog.vue'
import { stateOf, suspensionWarning } from './moderation.js'
import { moderationUrl } from '../../services/adminApi.js'
import { showError, showSuccess } from '../../services/toast.js'

/** What one page of the browser holds, as ModerationController pages it. */
export const PAGE = 40

/**
 * Every account this instance knows, whether or not anybody complained.
 *
 * Before this section only a *reported* account could be acted on from the web:
 * an instance that had a problem with somebody nobody had filed a report about
 * needed a moderation client and a token.
 */
export default {
	name: 'AccountsSection',

	components: {
		ConfirmDialog,
		IconAccountSearch,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},

	data() {
		return {
			query: '',
			// filled in from the option lists below, so that what the select
			// shows and what is in its list are the same object: vue-select
			// marks the selected entry by identity
			origin: null,
			status: null,
			accounts: [],
			history: {},
			cursor: 0,
			/**
			 * The filters the rows on screen came from — which is not what the
			 * form says, once somebody has typed in it without searching yet.
			 */
			active: null,
			generation: 0,
			hasMore: false,
			loading: true,
			pending: null,
		}
	},

	computed: {
		origins() {
			return [
				{ id: '', label: t('social', 'Anywhere') },
				{ id: 'local', label: t('social', 'This instance') },
				{ id: 'remote', label: t('social', 'Other instances') },
			]
		},

		states() {
			return [
				{ id: '', label: t('social', 'Any') },
				{ id: 'active', label: t('social', 'Nothing standing against it') },
				{ id: 'silenced', label: t('social', 'Silenced') },
				{ id: 'suspended', label: t('social', 'Suspended') },
			]
		},
	},

	created() {
		this.origin = this.origins[0]
		this.status = this.states[0]
	},

	mounted() {
		this.search(false)
	},

	methods: {
		t,
		n,
		stateOf,

		/**
		 * What the three controls currently say.
		 *
		 * @return {object} the filters a fresh search would send
		 */
		formFilters() {
			return {
				query: this.query.trim(),
				origin: this.origin?.id ?? '',
				status: this.status?.id ?? '',
			}
		},

		/**
		 * Runs the search the form describes, or continues the one on screen.
		 *
		 * `more` asks for the page after the rows already here, so it has to
		 * ask the same question they answered: the cursor belongs to that
		 * search, and the form may have been typed in since without anybody
		 * pressing Search. Reading the controls again put one search's cursor
		 * on another one's filters and appended the answer to rows it has
		 * nothing to do with.
		 *
		 * The counter is for the other direction: two replacement searches can
		 * settle in the order the server felt like, and only the newest one
		 * may write.
		 *
		 * @param {boolean} [more] whether to continue the current page rather
		 *   than start again
		 * @return {Promise<void>}
		 */
		async search(more = false) {
			if (this.loading && more) {
				return
			}

			const filters = more && this.active !== null ? this.active : this.formFilters()
			const continuing = more && this.active !== null && this.cursor > 0
			const params = continuing ? { ...filters, maxId: String(this.cursor) } : { ...filters }
			const asked = ++this.generation

			this.loading = true
			try {
				const { data } = await axios.get(moderationUrl('/accounts'), { params })
				if (asked !== this.generation) {
					return
				}

				const cursors = data.cursors || []
				this.cursor = cursors.length > 0 ? cursors[cursors.length - 1] : 0
				const accounts = data.accounts || []
				this.accounts = continuing ? this.accounts.concat(accounts) : accounts
				if (!continuing) {
					this.active = filters
					this.history = {}
				}
				// a full page may have more behind it; a short one is the end
				this.hasMore = accounts.length >= PAGE
			} catch {
				if (asked !== this.generation) {
					return
				}

				showError(t('social', 'Could not read the accounts'))
			} finally {
				if (asked === this.generation) {
					this.loading = false
				}
			}
		},

		/**
		 * Opens one account's history under its row, and closes it again.
		 *
		 * A number is what a moderator scans a page for; the history is what
		 * they need once one of the numbers is not zero.
		 *
		 * @param {object} account the row
		 * @return {Promise<void>}
		 */
		async toggleHistory(account) {
			if (this.history[account.actor_id]) {
				delete this.history[account.actor_id]

				return
			}

			try {
				const { data } = await axios.get(moderationUrl('/accounts/history'), {
					params: { actorId: account.actor_id },
				})
				this.history = { ...this.history, [account.actor_id]: data.strikes || [] }
			} catch {
				showError(t('social', 'Could not read the history'))
			}
		},

		/**
		 * What one strike says, in one line.
		 *
		 * @param {object} strike as the route sends it
		 * @return {string} the line
		 */
		strikeLine(strike) {
			const when = new Date(strike.creation * 1000).toISOString().slice(0, 10)
			const what = strike.action === 'none' ? t('social', 'Warning') : stateOf(strike.action)
			const who = strike.moderator || t('social', 'the server')

			return when + ' — ' + what + ' — ' + who + (strike.text ? ': ' + strike.text : '')
		},

		/**
		 * Silences, suspends or lifts. Suspending deletes, so it asks first.
		 *
		 * @param {object} account the row
		 * @param {string} level 'silence', 'suspend' or ''
		 */
		askModerate(account, level) {
			if (level !== 'suspend') {
				this.moderate(account, level)

				return
			}

			this.pending = { account, level, message: suspensionWarning() }
		},

		/**
		 * @param {object} account the row
		 * @param {string} level what was decided
		 * @return {Promise<void>}
		 */
		async moderate(account, level) {
			this.pending = null
			try {
				await axios.post(moderationUrl('/accounts'), {
					actorId: account.actor_id,
					level,
					comment: '',
				})
				account.level = level
				showSuccess(level === ''
					? t('social', 'The decision was lifted')
					: t('social', 'The decision was applied'))
			} catch {
				showError(t('social', 'Could not apply the decision'))
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.accounts {
	&__search {
		display: flex;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 2);
		align-items: flex-end;
	}

	&__query {
		max-width: 320px;
	}

	&__filter {
		min-width: 200px;
	}

	&__state {
		color: var(--color-text-maxcontrast);
	}

	&__history {
		ul {
			margin: 0;
			padding-inline-start: calc(var(--default-grid-baseline) * 4);
			list-style: disc;
		}
	}
}
</style>
