<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="finder">
		<NcTextField
			v-model="query"
			class="finder__field"
			type="search"
			:label="t('social', 'Find people across the fediverse')"
			:placeholder="t('social', 'A name, a handle, or a subject')"
			trailingButtonIcon="close"
			:showTrailingButton="query !== ''"
			@trailingButtonClick="clear"
			@keydown.enter="search(true)">
			<template #icon>
				<AccountSearch :size="20" />
			</template>
		</NcTextField>

		<!-- which directories are asked, named because an administrator chose
		     them; one menu rather than a button per directory -->
		<div v-if="sources.length > 1" class="finder__where">
			<span class="finder__where-label">{{ t('social', 'Looking in') }}</span>
			<NcActions :menuName="chosenLabel" variant="tertiary">
				<NcActionButton :modelValue="chosen === ''" @click="choose('')">
					<template #icon>
						<Earth :size="20" />
					</template>
					{{ t('social', 'Everywhere') }}
				</NcActionButton>
				<NcActionButton
					v-for="source in sources"
					:key="source.host"
					:modelValue="chosen === source.host"
					@click="choose(source.host)">
					{{ source.label }}
				</NcActionButton>
			</NcActions>
			<span class="finder__where-why">{{ whereWhy }}</span>
		</div>

		<NcLoadingIcon v-if="loading" class="finder__loading" :size="32" />

		<template v-else-if="asked">
			<ul v-if="accounts.length" class="finder__list">
				<PersonCard
					v-for="account in accounts"
					:key="account.acct"
					:account="account"
					:link="account.known === true"
					:reason="reasonFor(account)"
					:followed="isFollowed(account)"
					:pending="isPending(account)"
					@follow="follow" />
			</ul>

			<NcEmptyContent
				v-else
				:name="t('social', 'Nobody by that name')"
				:description="emptyDescription">
				<template #icon>
					<AccountSearch />
				</template>
			</NcEmptyContent>

			<!-- a server that did not answer is not one with nobody on it -->
			<p v-if="quiet.length" class="finder__quiet">
				{{ quietNotice }}
			</p>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountSearch from 'vue-material-design-icons/AccountSearch.vue'
import Earth from 'vue-material-design-icons/Earth.vue'
import PersonCard from './PersonCard.vue'
import { useFollowByHandle } from '../composables/useFollowByHandle.js'
import logger from '../services/logger.js'
import { useSettingsStore } from '../store/settings.js'

/** How long the box waits after the last keystroke before it asks anybody. */
const DEBOUNCE = 400

/**
 * The shortest query worth sending to four servers. One letter matches
 * everybody and tells the reader nothing.
 */
const MIN_LENGTH = 2

/**
 * Finding somebody to follow when you do not know which server they are on,
 * by asking several directories. The server drops anybody on a domain this
 * instance does not federate with and stores nothing; each source's outcome
 * comes back too, since a quiet server and an empty one differ.
 */
export default {
	name: 'FediverseSearch',
	components: {
		AccountSearch,
		Earth,
		NcActionButton,
		NcActions,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextField,
		PersonCard,
	},

	setup() {
		return useFollowByHandle()
	},

	data() {
		return {
			query: '',
			/** one directory's host, or '' for all of them */
			chosen: '',
			sources: [],
			accounts: [],
			/** what each source said about itself, as the server reported it */
			reports: [],
			loading: false,
			/**
			 * Which search is still wanted: a counter, as the same text asked of
			 * another directory, or asked again, is a different search.
			 */
			searching: 0,
			/** whether anything has been asked yet, so the empty state waits its turn */
			asked: false,
			timer: null,
		}
	},

	computed: {
		/** @return {string} the directory being searched, for the menu's own button */
		chosenLabel() {
			if (this.chosen === '') {
				return t('social', 'Everywhere')
			}

			return this.sources.find((source) => source.host === this.chosen)?.label ?? this.chosen
		},

		/**
		 * @return {string} how many directories that is, or why this one is on the list
		 */
		whereWhy() {
			if (this.chosen !== '') {
				const source = this.sources.find((one) => one.host === this.chosen)

				return source ? this.whySource(source) : ''
			}

			return n('social', '%n directory', '%n directories', this.sources.length)
		},

		/** @return {string[]} the directories that were asked and did not answer */
		quiet() {
			return this.reports
				.filter((report) => report.status !== 'ok')
				.map((report) => report.label || report.host)
		},

		/** @return {string} who did not answer, for the line under the results */
		quietNotice() {
			return n('social', '{names} did not answer. What is above is the rest.', '{names} did not answer. What is above is the rest.', this.quiet.length, { names: this.quiet.join(', ') })
		},

		/**
		 * Why there is nothing, in the terms of what was actually asked.
		 *
		 * @return {string}
		 */
		emptyDescription() {
			if (this.chosen !== '') {
				return t('social', 'Nobody there matched. Try the other directories.')
			}

			// the directories hold who is listed in them; somebody who is not
			// is found by the sidebar's search, which looks a handle up
			return useSettingsStore().getServerData?.bluesky?.enabled === true
				? t('social', 'Nobody in these directories matched. To find somebody who is not listed, put their full handle, like @someone@example.org or @someone.example.com, into the search at the top of the sidebar.')
				: t('social', 'Nobody in these directories matched. To find somebody who is not listed, put their full handle, like @someone@example.org, into the search at the top of the sidebar.')
		},
	},

	watch: {
		query() {
			this.schedule()
		},
	},

	beforeMount() {
		this.loadSources()
	},

	beforeUnmount() {
		clearTimeout(this.timer)
	},

	methods: {
		/**
		 * Which directory answered for this person.
		 *
		 * @param {object} account one result
		 * @return {string}
		 */
		reasonFor(account) {
			if (account.known) {
				return t('social', 'Known to this server')
			}

			const source = this.sources.find((one) => one.host === account.source)

			return source ? t('social', 'From {directory}', { directory: source.label }) : ''
		},

		/**
		 * Why a server is in this list: an administrator chose it, this instance
		 * federates with it, or another list of servers suggested it.
		 *
		 * @param {object} source the source as the server described it
		 * @return {string} what to show when the name is pointed at
		 */
		whySource(source) {
			if (source.origin === 'federation') {
				return t('social', 'Asked because this server already federates with it')
			}

			if (source.origin === 'discovered') {
				return t('social', 'Suggested by a directory of servers')
			}

			return t('social', 'Chosen by the administrator of this server')
		},

		t,
		n,

		/** Which directories this instance asks, in the order it asks them. */
		async loadSources() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/directories'))
				this.sources = Array.isArray(data) ? data : []
			} catch (error) {
				// the box still works, only without choosing one directory
				logger.debug('Could not read the directory list', { error })
			}
		},

		choose(host) {
			this.chosen = host
			if (this.query.trim().length >= MIN_LENGTH) {
				this.search(true)
			}
		},

		clear() {
			this.query = ''
			this.accounts = []
			this.reports = []
			this.asked = false
		},

		/** Waits for the typing to stop, so a name is one search and not eight. */
		schedule() {
			clearTimeout(this.timer)
			if (this.query.trim().length < MIN_LENGTH) {
				this.asked = false
				this.accounts = []

				return
			}

			this.timer = setTimeout(() => this.search(), DEBOUNCE)
		},

		/**
		 * @param {boolean} now whether to skip the wait — the reader pressed
		 *                      Enter or picked a directory, which is not typing
		 */
		async search(now = false) {
			const query = this.query.trim()
			if (query.length < MIN_LENGTH) {
				return
			}

			if (now) {
				clearTimeout(this.timer)
			}

			// a search is the words and the directory together, so choosing
			// another source with the same text starts a new one
			const source = this.chosen
			this.loading = true
			this.searching += 1
			const generation = this.searching
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/directories/search'), {
					params: { q: query, source },
				})

				// the reader has typed on, or moved to another directory, and
				// these are answers to a question that is no longer on screen
				if (generation !== this.searching) {
					return
				}

				this.accounts = Array.isArray(data?.accounts) ? data.accounts : []
				this.reports = Array.isArray(data?.sources) ? data.sources : []
				this.asked = true
			} catch (error) {
				logger.error('Could not search the fediverse directories', { error })
				// a failure belonging to an abandoned question must not empty
				// the results of the one the reader is looking at
				if (generation === this.searching) {
					this.accounts = []
					this.reports = []
					this.asked = true
				}
			} finally {
				if (generation === this.searching) {
					this.loading = false
				}
			}
		},

		/**
		 * Where a row goes: the local profile for an account this instance
		 * knows, otherwise the profile on its own server.
		 *
		 * @param {object} account one result
		 * @return {object} what to bind on the link
		 */
		linkFor(account) {
			if (account.known) {
				return { to: { name: 'profile', params: { account: account.acct } } }
			}

			return { href: account.url, target: '_blank', rel: 'noopener noreferrer' }
		},

		/** @param {object} account one result @return {string} the letter its avatar falls back to */
		initial(account) {
			return (account.display_name || account.username || '?').trim().charAt(0).toUpperCase()
		},

		/**
		 * A picture that will not load leaves a broken frame in every row;
		 * dropping it falls back to the initial.
		 *
		 * @param {object} account the row whose picture failed
		 */
		dropAvatar(account) {
			account.avatar = ''
		},
	},
}
</script>

<style scoped lang="scss">
.finder {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-block-end: calc(var(--default-grid-baseline) * 4);
}

/*
 * "Looking in Everywhere · 10 directories" is one sentence with a control in
 * it, so it is set at one size and baseline; only the pressable part takes the
 * page's text colour.
 */
.finder__where {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) / 2);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 0.85em);
	min-height: 34px;
}

.finder__where :deep(.button-vue) {
	font-size: inherit;
	min-height: 34px;
	padding-inline: calc(var(--default-grid-baseline) * 1.5);
}

.finder__where :deep(.button-vue__text) {
	font-weight: bold;
	color: var(--color-main-text);
	margin-inline-start: calc(var(--default-grid-baseline) / 2);
}

/* the dots are what Nextcloud draws for a menu; at this size they are the
   only thing saying the name beside them can be pressed */
.finder__where :deep(.button-vue__icon) {
	height: 20px;
	min-width: 20px;
}

/* the separator belongs to the count, and needs air on both sides of it or it
   reads as part of the number */
.finder__where-why {
	margin-inline-start: var(--default-grid-baseline);
}

.finder__where-why::before {
	content: '·';
	margin-inline-end: var(--default-grid-baseline);
}

.finder__loading {
	margin: 24px auto;
}

.finder__list {
	list-style: none;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
	gap: var(--default-grid-baseline);
}

.finder__quiet {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 0.85em);
}
</style>
