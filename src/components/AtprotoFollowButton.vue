<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcButton
		:disabled="loading"
		:variant="following ? 'secondary' : 'primary'"
		@click="toggle">
		{{ following ? t('social', 'Following on Bluesky') : t('social', 'Follow on Bluesky') }}
	</NcButton>
</template>

<script>
import axios from '@nextcloud/axios'
import NcButton from '@nextcloud/vue/components/NcButton'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { showError } from '../services/toast.js'

export default {
	name: 'AtprotoFollowButton',
	components: { NcButton },
	props: {
		handle: { type: String, required: true },
		initialFollowing: { type: Boolean, default: false },
	},

	data() {
		return { loading: false, following: this.initialFollowing }
	},

	watch: {
		initialFollowing(value) {
			this.following = value
		},
	},

	methods: {
		t,
		async toggle() {
			this.loading = true
			try {
				const url = generateUrl('apps/social/api/v1/atproto/follow')
				const response = this.following
					? await axios.delete(url, { params: { handle: this.handle } })
					: await axios.put(url, { handle: this.handle })
				this.following = response.data?.following === true
			} catch (error) {
				showError(error?.response?.data?.message ?? t('social', 'Could not change the Bluesky follow'))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
