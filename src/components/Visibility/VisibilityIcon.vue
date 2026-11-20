<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<Earth v-if="visibility === 'public'" :size="size" />
	<AccountMultiple v-else-if="visibility === 'followers'" :size="size" />
	<LockOpen v-else-if="visibility === 'unlisted'" :size="size" />
	<At v-else-if="visibility === 'direct'" :size="size" />
	<!-- an unknown visibility gets a question mark: no icon would read as public -->
	<HelpCircleOutline v-else :size="size" />
</template>

<script>
import Earth from 'vue-material-design-icons/Earth.vue'
import LockOpen from 'vue-material-design-icons/LockOpen.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import At from 'vue-material-design-icons/At.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'

export default {
	name: 'VisibilityIcon',
	components: {
		Earth,
		LockOpen,
		AccountMultiple,
		At,
		HelpCircleOutline,
	},

	props: {
		visibility: {
			type: String,
			required: true,
		},

		size: {
			type: Number,
			default: 22,
		},
	},

	data() {
		return {}
	},

	computed: {

	},

	methods: {

	},
}
</script>
