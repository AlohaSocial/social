<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="filters">
		<!-- The first thing to say, because it is the thing nobody remembers
		     setting: these filters are taking posts away right now, and a
		     conversation with a hole in it is what that looks like from the
		     outside. -->
		<NcNoteCard v-if="hiding.length > 0" type="warning">
			<p>
				{{ n('social', '%n filter is taking posts out of what you read.', '%n filters are taking posts out of what you read.', hiding.length) }}
				{{ t('social', 'If a conversation seems to have a gap in it, this is why.') }}
			</p>
			<p class="filters__note-list">
				{{ hidingSummary }}
			</p>
		</NcNoteCard>

		<p v-if="loading" class="filters__hint">
			{{ t('social', 'Loading your filters …') }}
		</p>
		<p v-else-if="filters.length === 0" class="filters__hint">
			{{ t('social', 'You have no filters. A filter is a word or a phrase you would rather not read: a post carrying it is folded away behind the filter’s name, or taken out of your timelines altogether.') }}
		</p>

		<ul v-else class="filters__list">
			<li
				v-for="filter in filters"
				:key="filter.id"
				class="filters__item"
				:class="{ 'filters__item--inactive': !active(filter) }">
				<div class="filters__row">
					<div class="filters__summary">
						<span class="filters__title">{{ filter.title }}</span>
						<span v-if="!active(filter)" class="filters__badge">
							{{ t('social', 'Expired') }}
						</span>
						<p class="filters__what">
							{{ actionLine(filter) }}
						</p>
						<ul class="filters__words">
							<li v-for="keyword in filter.keywords" :key="keyword.id" class="filters__word">
								{{ keyword.whole_word
									? t('social', '{word} (whole word)', { word: keyword.keyword })
									: keyword.keyword }}
							</li>
						</ul>
						<p class="filters__where">
							{{ whereLine(filter) }} · {{ expiryLine(filter) }}
						</p>
					</div>

					<div class="filters__row-actions">
						<NcButton
							variant="tertiary"
							:pressed="editing === filter.id"
							:aria-label="t('social', 'Edit the filter {title}', { title: filter.title })"
							@click="edit(filter)">
							<template #icon>
								<IconPencil :size="20" />
							</template>
							{{ t('social', 'Edit') }}
						</NcButton>
						<NcActions :aria-label="t('social', 'More actions for {title}', { title: filter.title })">
							<NcActionButton
								closeAfterClick
								@click="deleting = filter">
								<template #icon>
									<IconDelete :size="20" />
								</template>
								{{ t('social', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</div>
				</div>

				<FilterForm
					v-if="editing === filter.id"
					:key="`edit-${filter.id}`"
					:filter="filter"
					:busy="busy"
					@submit="(payload) => update(filter, payload)"
					@cancel="editing = null" />
			</li>
		</ul>

		<FilterForm
			v-if="editing === 'new'"
			key="new"
			:busy="busy"
			@submit="create"
			@cancel="editing = null" />
		<NcButton
			v-else
			class="filters__add"
			variant="secondary"
			@click="editing = 'new'">
			<template #icon>
				<IconPlus :size="20" />
			</template>
			{{ t('social', 'Add a filter') }}
		</NcButton>

		<NcDialog
			:open="deleting !== null"
			:name="t('social', 'Delete the filter {title}?', { title: deleting?.title ?? '' })"
			:buttons="deleteButtons"
			@update:open="deleting = $event ? deleting : null">
			<p class="filters__hint">
				{{ t('social', 'The filter goes and stops applying everywhere. Posts it was keeping from you are shown again — nothing was deleted, only hidden.') }}
			</p>
		</NcDialog>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import IconDelete from 'vue-material-design-icons/Delete.vue'
import IconPencil from 'vue-material-design-icons/Pencil.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import FilterForm from './FilterForm.vue'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { contextLabel, errorSaid, expiryLabel, isActive } from '../utils/filters.js'

/**
 * The reader's keyword filters: what they are, and everything that can be done
 * to them.
 *
 * These filters were reachable only from a Mastodon client, and they apply to
 * every read this app makes — so one set from a phone months ago has been
 * shaping this timeline with nothing on any page saying so. That is what this
 * section is for, and why a filter that *hides* posts is announced at the top
 * rather than merely listed.
 *
 * The v2 API only. v1 is Mastodon's deprecated one and is still served for old
 * clients, but there a filter *is* a single phrase: editing a filter of three
 * words through it would mean three rows with three ids that are not the ids
 * the v2 list shows. The model this app stores is the v2 one.
 *
 * Every write is followed by a read of the whole list rather than by patching
 * the row in place: the server decides the order and the expiry it stored, and
 * a keyword added here comes back with the id an edit will need.
 */
export default {
	name: 'FiltersSettings',

	components: {
		FilterForm,
		IconDelete,
		IconPencil,
		IconPlus,
		NcActionButton,
		NcActions,
		NcButton,
		NcDialog,
		NcNoteCard,
	},

	data() {
		return {
			/** @type {object[]} the filters, as the API answers with them */
			filters: [],
			loading: true,
			/** whether a write is in flight */
			busy: false,
			/** 'new', the id of the filter being edited, or null */
			editing: null,
			/** the filter a delete is being confirmed for, or null */
			deleting: null,
		}
	},

	computed: {
		/** @return {object[]} the filters that are removing posts as things stand */
		hiding() {
			return this.filters.filter((filter) => filter.filter_action === 'hide' && this.active(filter))
		},

		/** @return {string} which filters those are, and where they apply */
		hidingSummary() {
			return this.hiding
				.map((filter) => t('social', '{title} — in {places}', {
					title: filter.title,
					places: this.placeNames(filter),
				}))
				.join('; ')
		},

		/** @return {import('../types/Nextcloud.js').DialogButton[]} */
		deleteButtons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => {
						this.deleting = null
					},
				},
				{
					label: t('social', 'Delete'),
					variant: 'error',
					disabled: this.busy,
					callback: () => this.remove(),
				},
			]
		},
	},

	mounted() {
		this.fetchFilters()
	},

	methods: {
		t,
		n,

		/**
		 * @param {object} filter a v2 Filter entity
		 * @return {boolean} whether it still applies
		 */
		active(filter) {
			return isActive(filter)
		},

		/**
		 * @param {object} filter a v2 Filter entity
		 * @return {string} the places it applies, in this app's words
		 */
		placeNames(filter) {
			return (filter.context ?? []).map(contextLabel).join(', ')
		},

		/**
		 * @param {object} filter a v2 Filter entity
		 * @return {string} what it does to a post it matches
		 */
		actionLine(filter) {
			return filter.filter_action === 'hide'
				? t('social', 'Posts carrying any of these are taken out of your timelines:')
				: t('social', 'Posts carrying any of these are folded away behind this filter’s name, and can still be opened:')
		},

		/**
		 * @param {object} filter a v2 Filter entity
		 * @return {string} where it applies
		 */
		whereLine(filter) {
			const places = this.placeNames(filter)

			return places === ''
				? t('social', 'Nowhere — this filter names no timeline, so it does nothing')
				: t('social', 'In {places}', { places })
		},

		/**
		 * @param {object} filter a v2 Filter entity
		 * @return {string} when it stops applying
		 */
		expiryLine(filter) {
			return expiryLabel(filter)
		},

		/**
		 * @param {object} filter the one to open
		 */
		edit(filter) {
			this.editing = this.editing === filter.id ? null : filter.id
		},

		/** @return {Promise<void>} */
		async fetchFilters() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v2/filters'))
				this.filters = Array.isArray(data) ? data : []
			} catch (error) {
				logger.error('Failed to load the keyword filters', { error })
				showError(t('social', 'Could not load your filters'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} payload the body the form built
		 * @return {Promise<void>}
		 */
		async create(payload) {
			if (this.busy) {
				return
			}
			this.busy = true
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v2/filters'), payload)
				this.editing = null
				await this.fetchFilters()
				showSuccess(t('social', 'The filter {title} is now applied', { title: data?.title ?? payload.title }))
			} catch (error) {
				logger.error('Failed to create the keyword filter', { error })
				showError(errorSaid(error, t('social', 'Could not create the filter')))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} filter the one being changed
		 * @param {object} payload the body the form built
		 * @return {Promise<void>}
		 */
		async update(filter, payload) {
			if (this.busy) {
				return
			}
			this.busy = true
			try {
				await axios.put(generateUrl(`apps/social/api/v2/filters/${filter.id}`), payload)
				this.editing = null
				await this.fetchFilters()
			} catch (error) {
				logger.error('Failed to change the keyword filter', { error })
				showError(errorSaid(error, t('social', 'Could not save the filter')))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Deletes `deleting`, once the dialog has agreed.
		 *
		 * @return {Promise<void>}
		 */
		async remove() {
			const filter = this.deleting
			if (!filter || this.busy) {
				return
			}
			this.busy = true
			try {
				await axios.delete(generateUrl(`apps/social/api/v2/filters/${filter.id}`))
				this.deleting = null
				if (this.editing === filter.id) {
					this.editing = null
				}
				await this.fetchFilters()
				showSuccess(t('social', 'The filter {title} is gone', { title: filter.title }))
			} catch (error) {
				logger.error('Failed to delete the keyword filter', { error })
				showError(errorSaid(error, t('social', 'Could not delete the filter')))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.filters {
	&__hint {
		color: var(--color-text-maxcontrast);
		margin: 4px 0;
		max-width: 60ch;
	}

	&__note-list {
		margin: 4px 0 0;
	}

	&__list {
		list-style: none;
		margin: 0 0 8px;
		padding: 0;
	}

	&__item {
		border-top: 1px solid var(--color-border);
		padding: 8px 0;

		/* an expired filter is not doing anything, and the list should not
		   read as though it were */
		&--inactive {
			opacity: 0.6;
		}
	}

	&__row {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		flex-wrap: wrap;
	}

	&__summary {
		flex: 1 1 260px;
		min-width: 0;
	}

	&__title {
		font-weight: bold;
	}

	&__badge {
		margin-inline-start: 8px;
		padding: 1px 8px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	&__what,
	&__where {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		margin: 2px 0;
	}

	&__words {
		list-style: none;
		display: flex;
		flex-wrap: wrap;
		gap: 4px;
		margin: 4px 0;
		padding: 0;
	}

	&__word {
		padding: 1px 8px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-dark);
		/* a keyword is somebody's own text and may be long or unbreakable */
		overflow-wrap: anywhere;
	}

	&__row-actions {
		display: flex;
		align-items: center;
		gap: 4px;
		margin-inline-start: auto;
	}
}
</style>
