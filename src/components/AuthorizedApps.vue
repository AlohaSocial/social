<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="apps">
		<p v-if="loading" class="apps__hint">
			{{ t('social', 'Loading …') }}
		</p>

		<template v-else-if="apps.length === 0">
			<p class="apps__hint">
				{{ t('social', 'No app has been signed in to this account. Apps appear here when you sign in to one with your Aloha Social account.') }}
			</p>
			<UseInAppCard />
		</template>

		<ul v-else class="apps__list">
			<li v-for="app in apps" :key="app.id" class="apps__item">
				<div class="apps__who">
					<IconCellphoneLink :size="20" decorative title="" />
					<span class="apps__name">{{ app.name || t('social', 'An app that did not give a name') }}</span>
					<a
						v-if="websiteHref(app.website)"
						class="apps__website"
						:href="websiteHref(app.website)"
						target="_blank"
						rel="noopener noreferrer">{{ app.website }}</a>
					<span v-else-if="app.website" class="apps__website">{{ app.website }}</span>
				</div>

				<p class="apps__meta">
					<span v-if="app.signed_in">
						{{ t('social', 'Signed in {when}', { when: when(app.created_at) }) }}
					</span>
					<!-- the app never fetched its token, so this was not a sign-in -->
					<span v-else>
						{{ t('social', 'Asked for access {when} and never used it', { when: when(app.created_at) }) }}
					</span>
					<span v-if="app.last_used_at">
						{{ t('social', 'Last used {when}', { when: when(app.last_used_at) }) }}
					</span>
					<!-- however much it is used: the instance's token_max_days -->
					<span v-if="app.signed_in && app.expires_at" class="apps__expires">
						{{ t('social', 'Signs out by itself {when}', { when: when(app.expires_at) }) }}
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
import UseInAppCard from './UseInAppCard.vue'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { fullDateTime } from '../utils/relativeTime.js'

/**
 * The apps this account has signed in to, and a way to sign one out, e.g. for
 * a lost phone. The token is never shown: it is stored hashed.
 */
export default {
	name: 'AuthorizedApps',

	components: {
		IconCellphoneLink,
		NcButton,
		UseInAppCard,
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
		/**
		 * The website an app gave when it registered, as a link only when it is
		 * a web address: registration is open to anybody, and what was written
		 * there is not checked.
		 *
		 * @param {string} website what the app said
		 * @return {string} the address to link to, '' for none
		 */
		websiteHref(website) {
			if (!website) {
				return ''
			}
			try {
				const url = new URL(website)
				return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : ''
			} catch {
				return ''
			}
		},

		t,

		/**
		 * @param {number} seconds a unix timestamp, as the server writes them
		 * @return {string} the date and time, or '' where there is none
		 */
		when(seconds) {
			// the row carries seconds, as the database column holds them
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
	margin-block-end: 12px;
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
