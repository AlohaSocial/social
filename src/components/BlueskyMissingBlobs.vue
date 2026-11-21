<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcNoteCard type="warning" class="migration__bluesky-missing">
		<p>
			{{ n('social', '%n photo or video of your posts could not be copied here.', '%n photos or videos of your posts could not be copied here.', count) }}
			{{ t('social', 'Your account is not pointed at this server until you decide.') }}
		</p>
		<ServerError v-if="error" :error="error" />
		<div class="migration__bluesky-missing-actions">
			<NcButton :disabled="busy" @click="decide(false)">
				{{ t('social', 'Try copying them again') }}
			</NcButton>
			<NcButton :disabled="busy" @click="confirming = true">
				{{ t('social', 'Go on without them') }}
			</NcButton>
		</div>
		<ConfirmDialog
			v-if="confirming"
			:open="true"
			:name="t('social', 'Go on without them?')"
			:message="n('social', '%n photo or video of your posts could not be copied here. Your account is pointed at this server without it: the posts that show it lose it, and it is not copied later.', '%n photos or videos of your posts could not be copied here. Your account is pointed at this server without them: the posts that show them lose them, and they are not copied later.', count)"
			:confirmLabel="t('social', 'Go on without them')"
			@update:open="confirming = false"
			@confirm="decide(true)">
			<BlueskyMissingRecords :records="move.progress?.missing_records ?? []" />
		</ConfirmDialog>
	</NcNoteCard>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ServerError from './ServerError.vue'
import BlueskyMissingRecords from './BlueskyMissingRecords.vue'
import ConfirmDialog from './admin/ConfirmDialog.vue'
import { showError } from '../services/toast.js'
import { errorOf } from '../utils/serverError.js'

/**
 * A move here that stopped because pictures or videos did not arrive
 * (`needs_decision`): the person has them copied again or, once they
 * confirmed it, goes on without them. Emits the move as the server answers
 * it.
 */
export default {
	name: 'BlueskyMissingBlobs',

	components: {
		ServerError,
		BlueskyMissingRecords,
		ConfirmDialog,
		NcButton,
		NcNoteCard,
	},

	props: {
		/** the move, as the server keeps it */
		move: {
			type: Object,
			required: true,
		},
	},

	emits: ['move'],

	data() {
		return {
			busy: false,
			/** whether going on without them is being asked about */
			confirming: false,
			/** @type {{text: string, details: string}|null} */
			error: null,
		}
	},

	computed: {
		/** @return {number} how many did not arrive */
		count() {
			return this.move.progress?.missing?.count ?? 0
		},
	},

	methods: {
		n,
		t,

		/**
		 * @param {boolean} withoutMissing whether the move goes on without them
		 * @return {Promise<void>}
		 */
		async decide(withoutMissing) {
			this.busy = true
			this.error = null
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/social/bluesky/move-in/continue'), { without_missing: withoutMissing })
				this.$emit('move', data?.move)
			} catch (error) {
				const reason = errorOf(error, t('social', 'Could not go on with the move'))
				if (error?.response?.status === 422) {
					this.error = reason
				} else {
					showError(reason.text)
				}
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.migration__bluesky-missing-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
