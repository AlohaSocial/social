<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcButton
		:value="currentVisibilityPostLabel"
		:disabled="disabled"
		variant="primary"
		@click.prevent="handleClick">
		<template #icon>
			<ClockOutline
				v-if="scheduled"
				title=""
				:size="22"
				decorative />
			<Send
				v-else
				title=""
				:size="22"
				decorative />
		</template>
		{{ postTo }}
	</NcButton>
</template>

<script>

import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import Send from 'vue-material-design-icons/Send.vue'
import NcButton from '@nextcloud/vue/components/NcButton'

export default {
	name: 'SubmitStatusButton',
	components: {
		ClockOutline,
		NcButton,
		Send,
	},

	props: {
		visibility: {
			type: String,
			required: true,
		},

		disabled: {
			type: Boolean,
			default: true,
		},

		/**
		 * Whether the post is to go out later rather than now. The button
		 * then says so, whatever the audience: pressing "Post to followers"
		 * and having nothing appear is a button that lied.
		 */
		scheduled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['click'],
	computed: {
		/** @return {string} */
		postTo() {
			if (this.scheduled) {
				return t('social', 'Schedule')
			}

			switch (this.visibility) {
				case 'public':
				case 'unlisted':
					return t('social', 'Post')
				case 'followers':
					return t('social', 'Post to followers')
				case 'direct':
					return t('social', 'Send message to mentioned users')
			}
			return ''
		},

		/** @return {string} */
		currentVisibilityPostLabel() {
			return this.visibilityPostLabel(this.visibility)
		},

		/** @return {(visibility?: string) => string} */
		visibilityPostLabel() {
			return (visibility) => {
				if (visibility === undefined) {
					visibility = this.visibility
				}
				switch (visibility) {
					case 'public':
						return t('social', 'Post publicly')
					case 'followers':
						return t('social', 'Post to followers')
					case 'direct':
						return t('social', 'Post to recipients')
					case 'unlisted':
						return t('social', 'Post unlisted')
				}
			}
		},
	},

	methods: {
		handleClick() {
			this.$emit('click')
		},
	},
}

</script>
