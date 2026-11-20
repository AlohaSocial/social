<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('social', 'Who is in this photo?')"
		:open="true"
		size="normal"
		@update:open="$emit('close')">
		<p class="tag-people__lede">
			{{ t('social', 'Handles, separated by commas — for example alice@cloud.example. Everybody named is told, and it shows up under "Tagged" on their profile.') }}
		</p>

		<NcTextField
			v-model="draft"
			class="tag-people__field"
			:label="t('social', 'People in this photo')"
			placeholder="alice@cloud.example, bob@cloud.example"
			:disabled="saving" />

		<ServerError v-if="error" class="tag-people__error" :error="error" />

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('social', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ServerError from './ServerError.vue'
import logger from '../services/logger.js'
import { errorOf } from '../utils/serverError.js'

/**
 * Naming the people in a photograph. The field holds the whole list, as the
 * server's route takes it, so removing somebody is deleting their handle.
 * Handles are typed rather than picked, as people on other servers may be
 * unknown to this instance.
 */
export default {
	name: 'TagPeopleDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcTextField,
		ServerError,
	},

	props: {
		/** the post, by the id its own routes use */
		nid: {
			type: [Number, String],
			required: true,
		},

		/** who it names now */
		people: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Account[]>} */ (Array),
			default: () => [],
		},
	},

	emits: ['close', 'tagged'],

	data() {
		return {
			draft: this.people.map((person) => person.acct).join(', '),
			saving: false,
			/** @type {{text: string, details: string}|null} */
			error: null,
		}
	},

	methods: {
		t,

		/** @return {Promise<void>} */
		async save() {
			this.saving = true
			this.error = null
			try {
				const accounts = this.draft
					.split(',')
					.map((one) => one.trim().replace(/^@/, ''))
					.filter((one) => one !== '')

				const url = generateUrl('apps/social/api/v1.1/compose/tag')
				const { data } = await axios.post(url, { status_id: this.nid, accounts })
				this.$emit('tagged', data.tagged_people ?? [])
				this.$emit('close')
			} catch (error) {
				logger.error('could not name the people in a photo', { error })
				this.error = errorOf(error, t('social', 'Could not save that'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.tag-people__lede {
	color: var(--color-text-maxcontrast);
	margin-block-end: 8px;
}

.tag-people__error {
	margin-block-start: 8px;
}
</style>
