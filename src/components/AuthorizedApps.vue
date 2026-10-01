<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="apps">
		<p v-if="loading" class="apps__hint">
			{{ t('social', 'Loading …') }}
		</p>

		<p v-else-if="apps.length === 0" class="apps__hint">
			{{ t('social', 'No app has been signed in to this account. Apps appear here when you sign in to one with your Aloha Social account.') }}
		</p>

		<ul v-else class="apps__list">
			<li v-for="app in apps" :key="app.id" class="apps__item">
				<div class="apps__who">
					<IconCellphoneLink :size="20" decorative title="" />
					<span class="apps__name">{{ app.name || t('social', 'An app that did not give a name') }}</span>
					<a
						v-if="app.website"
						class="apps__website"
						:href="app.website"
						target="_blank"
						rel="noopener noreferrer">{{ app.website }}</a>
				</div>

				<p class="apps__meta">
					<span v-if="app.signed_in">
						{{ t('social', 'Signed in {when}', { when: when(app.created_at) }) }}
					</span>
					<!-- the browser came back and the app never asked for its
					     token: it is not a sign-in, and saying "signed in"
					     about it would be untrue -->
					<span v-else>
						{{ t('social', 'Asked for access {when} and never used it', { when: when(app.created_at) }) }}
					</span>
					<span v-if="app.last_used_at">
						{{ t('social', 'Last used {when}', { when: when(app.last_used_at) }) }}
					</span>
				</p>

				<p v-if="(app.scopes || []).length" class="apps__scopes">
					{{ t('social', 'It may: {what}', { what: whatItMay(app.scopes) }) }}
				</p>

				<div v-if="confirming === app.id" class="apps__actions">
					<p class="apps__warning">
						{{ t('social', 'Signing this app out cannot be undone — the app will ask you to sign in again the next time you open it.') }}
					</p>
					<NcButton
						variant="error"
						:disabled="busy.includes(app.id)"
						@click="revoke(app)">
						{{ t('social', 'Sign it out') }}
					</NcButton>
					<NcButton :disabled="busy.includes(app.id)" @click="confirming = 0">
						{{ t('social', 'Keep it') }}
					</NcButton>
				</div>
				<div v-else class="apps__actions">
					<NcButton :disabled="busy.includes(app.id)" @click="confirming = app.id">
						{{ t('social', 'Sign this app out') }}
					</NcButton>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconCellphoneLink from 'vue-material-design-icons/CellphoneLink.vue'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { fullDateTime } from '../utils/relativeTime.js'

/**
 * The apps this account has signed in to, and the way to sign one out.
 *
 * Every authorization has been recorded since `social_client_auth` was split
 * out of the app registration; nothing showed it, and nothing could take one
 * back short of the app revoking its own token — which is no use at all when
 * the app is the phone you have just lost. This is the first page somebody
 * looks for then, and Mastodon keeps it in the same place.
 *
 * The token itself is never shown, because it is stored hashed and there is
 * nothing here that wants it.
 */
export default {
	name: 'AuthorizedApps',

	components: {
		IconCellphoneLink,
		NcButton,
	},

	data() {
		return {
			/** @type {object[]} one row per authorization, newest first */
			apps: [],
			loading: true,
			/** @type {number} the row asking to be confirmed, or 0 */
			confirming: 0,
			/** @type {number[]} the rows a request is out for */
			busy: [],
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * @param {number} seconds a unix timestamp, as the server writes them
		 * @return {string} the date and time, or '' where there is none
		 */
		when(seconds) {
			// seconds, not milliseconds: the row carries what the database
			// column held, and reading it as milliseconds dated every app to
			// January 1970
			return seconds > 0 ? fullDateTime(new Date(seconds * 1000)) : ''
		},

		/**
		 * @param {string[]} scopes what the app was granted
		 * @return {string} them, in a row
		 */
		whatItMay(scopes) {
			return scopes.join(', ')
		},

		/** @return {Promise<void>} */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/authorized_apps'))
				this.apps = Array.isArray(data) ? data : []
			} catch (error) {
				logger.error('could not load the authorized apps', { error })
				showError(t('social', 'Could not load the apps you have signed in to'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} app the row to take back
		 * @return {Promise<void>}
		 */
		async revoke(app) {
			this.busy.push(app.id)
			try {
				await axios.delete(generateUrl('apps/social/api/v1/authorized_apps/{id}', { id: app.id }))
				this.apps = this.apps.filter((one) => one.id !== app.id)
				this.confirming = 0
				showSuccess(t('social', 'That app has been signed out'))
			} catch (error) {
				logger.error('could not revoke an authorization', { error })
				showError(t('social', 'Could not sign that app out'))
			} finally {
				this.busy = this.busy.filter((id) => id !== app.id)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.apps__hint {
	color: var(--color-text-maxcontrast);
}

.apps__list {
	display: flex;
	flex-direction: column;
	gap: 12px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.apps__item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.apps__who {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.apps__name {
	font-weight: bold;
}

.apps__website {
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.apps__meta {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	color: var(--color-text-maxcontrast);
	margin-block: 8px 0;
}

.apps__scopes {
	color: var(--color-text-maxcontrast);
	margin-block: 4px 0;
	overflow-wrap: anywhere;
}

.apps__warning {
	margin-block: 0 8px;
	flex-basis: 100%;
}

.apps__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-block-start: 8px;
}
</style>
