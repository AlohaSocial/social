<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:open="open"
		:name="t('social', 'Add to a collection')"
		:buttons="buttons"
		class="collection-picker"
		@update:open="$emit('update:open', $event)">
		<NcLoadingIcon v-if="loading" class="collection-picker__loading" :size="32" />

		<template v-else>
			<ul v-if="collections.length" class="collection-picker__list">
				<li v-for="collection in collections" :key="collection.id" class="collection-picker__row">
					<span class="collection-picker__name">
						{{ collection.title }}
						<span class="collection-picker__count">{{ n('social', '%n post', '%n posts', collection.size) }}</span>
					</span>
					<NcButton
						:variant="added.includes(collection.id) ? 'success' : 'secondary'"
						:disabled="busy.includes(collection.id) || added.includes(collection.id)"
						@click="add(collection)">
						<template #icon>
							<NcLoadingIcon v-if="busy.includes(collection.id)" :size="20" />
							<IconCheck v-else-if="added.includes(collection.id)" :size="20" />
							<IconPlus v-else :size="20" />
						</template>
						{{ added.includes(collection.id) ? t('social', 'Added') : t('social', 'Add') }}
					</NcButton>
				</li>
			</ul>
			<p v-else class="collection-picker__hint">
				{{ t('social', 'You have no collections yet. Name one below and the post goes straight into it.') }}
			</p>

			<form class="collection-picker__create" @submit.prevent="createAndAdd">
				<NcTextField
					v-model="newTitle"
					:label="t('social', 'New collection')"
					:placeholder="t('social', 'What to call it')"
					maxlength="255" />
				<NcButton type="submit" variant="primary" :disabled="newTitle.trim() === '' || creating">
					<template #icon>
						<NcLoadingIcon v-if="creating" :size="20" />
						<IconPlus v-else :size="20" />
					</template>
					{{ t('social', 'Create and add') }}
				</NcButton>
			</form>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconCheck from 'vue-material-design-icons/Check.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'

/**
 * Puts one of the reader's own posts into one of their collections.
 *
 * Opened from the post's menu, so the post is already chosen and the only
 * question is which album. The server does not say which albums a post is
 * already in — adding one twice is a no-op there — so the dialog remembers
 * only what it added itself, and a second click on the same row is a
 * button that no longer does anything.
 */
export default {
	name: 'CollectionPickerDialog',

	components: {
		IconCheck,
		IconPlus,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcTextField,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		/** the post to add: only its `id` is used */
		status: {
			type: Object,
			required: true,
		},
	},

	emits: ['update:open', 'added'],

	data() {
		return {
			loading: true,
			/** @type {Array<object>} the reader's own collections */
			collections: [],
			/** ids of the collections the post was put into from here */
			added: [],
			/** ids of the collections an add is on its way to */
			busy: [],
			newTitle: '',
			creating: false,
		}
	},

	computed: {
		/** @return {import('../types/Nextcloud.js').DialogButton[]} */
		buttons() {
			return [{ label: t('social', 'Done'), variant: 'primary', callback: () => this.$emit('update:open', false) }]
		},
	},

	watch: {
		open(open) {
			if (open) {
				this.load()
			}
		},
	},

	mounted() {
		if (this.open) {
			this.load()
		}
	},

	methods: {
		t,
		n,

		/** @return {Promise<void>} */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/collections'))
				this.collections = Array.isArray(data) ? data : []
			} catch (error) {
				logger.error('could not load the collections', { error })
				showError(t('social', 'Your collections could not be loaded'))
				this.collections = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} collection the album to put the post into
		 * @return {Promise<boolean>} whether it went in
		 */
		async add(collection) {
			if (this.busy.includes(collection.id) || this.added.includes(collection.id)) {
				return false
			}

			this.busy = [...this.busy, collection.id]
			try {
				const { data } = await axios.post(
					generateUrl(`apps/social/api/v1/collections/${collection.id}/items`),
					{ status_id: this.status.id },
				)
				this.added = [...this.added, collection.id]
				this.collections = this.collections.map((candidate) => (candidate.id === collection.id ? { ...candidate, size: data?.size ?? candidate.size } : candidate))
				this.$emit('added', collection)
				showSuccess(t('social', 'Added to {title}', { title: collection.title }))

				return true
			} catch (error) {
				logger.error('could not add the post to the collection', { error })
				showError(error?.response?.data?.error || t('social', 'Could not add the post to the collection'))

				return false
			} finally {
				this.busy = this.busy.filter((id) => id !== collection.id)
			}
		},

		/** @return {Promise<void>} */
		async createAndAdd() {
			const title = this.newTitle.trim()
			if (title === '' || this.creating) {
				return
			}

			this.creating = true
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/collections'), { title, visibility: 'public' })
				this.collections = [data, ...this.collections]
				this.newTitle = ''
				await this.add(data)
			} catch (error) {
				logger.error('could not create the collection', { error })
				showError(error?.response?.data?.error || t('social', 'Could not create the collection'))
			} finally {
				this.creating = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.collection-picker__loading {
	margin: 24px auto;
}

.collection-picker__list {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 0 0 16px;
	padding: 0;
	list-style: none;
}

.collection-picker__row {
	display: flex;
	gap: 12px;
	align-items: center;
	justify-content: space-between;
}

.collection-picker__name {
	display: flex;
	flex-direction: column;
	min-width: 0;
	overflow-wrap: anywhere;
}

.collection-picker__count {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.collection-picker__hint {
	margin-bottom: 12px;
	color: var(--color-text-maxcontrast);
}

.collection-picker__create {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 12px;
	align-items: flex-end;
}
</style>
