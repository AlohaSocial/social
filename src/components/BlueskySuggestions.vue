<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<ul v-if="accounts.length" class="bluesky-suggestions">
		<PersonCard
			v-for="account in accounts"
			:key="account.id"
			:account="account"
			link
			:followed="isFollowed(account)"
			:pending="isPending(account)"
			@follow="follow" />
	</ul>
	<p v-else-if="!loading" class="bluesky-suggestions__empty">
		{{ t('social', 'Bluesky has nobody to suggest to you right now.') }}
	</p>
</template>

<script>
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import PersonCard from './PersonCard.vue'
import { useFollowByHandle } from '../composables/useFollowByHandle.js'
import logger from '../services/logger.js'

/**
 * The accounts Bluesky suggests to the reader, read as them where they are
 * on Bluesky, and followed here by their handle like anybody else on
 * Discover.
 */
export default {
	name: 'BlueskySuggestions',

	components: { PersonCard },

	setup() {
		return useFollowByHandle()
	},

	data() {
		return {
			accounts: [],
			loading: true,
		}
	},

	async mounted() {
		try {
			const { data } = await axios.get(generateUrl('apps/social/api/v1/social/bluesky/suggestions'))
			this.accounts = Array.isArray(data?.accounts) ? data.accounts : []
		} catch (error) {
			logger.info('Bluesky suggestions not loaded', { error })
		} finally {
			this.loading = false
		}
	},

	methods: { t },
}
</script>

<style scoped lang="scss">
.bluesky-suggestions {
	list-style: none;
	margin: 0;
	padding: 0;

	&__empty {
		color: var(--color-text-maxcontrast);
	}
}
</style>
