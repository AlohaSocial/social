<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<div class="sections">
			<NcCheckboxRadioSwitch v-model="form.stories" type="switch">
				{{ t('social', '24-hour shorts') }}
			</NcCheckboxRadioSwitch>
			<p class="sections__hint">
				{{ t('social', 'A video, a picture or a text card for followers, gone after 24 hours. Off means the Shorts bar goes from the home timeline, New short offers only keeping it on the profile, and nothing new is taken.') }}
			</p>

			<NcCheckboxRadioSwitch v-model="form.photos" type="switch">
				{{ t('social', 'Photos') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="form.videos" type="switch">
				{{ t('social', 'Videos') }}
			</NcCheckboxRadioSwitch>
			<p class="sections__hint">
				{{ t('social', 'Two timelines in the sidebar, each the same posts read through one filter. A post is not changed by its section being off.') }}
			</p>

			<div class="sections__groups">
				<label for="social-group-lists" class="sections__label">
					{{ t('social', 'Nextcloud groups that become lists') }}
				</label>
				<NcSelect
					v-model="form.groupLists"
					inputId="social-group-lists"
					:labelOutside="true"
					:options="groupOptions"
					:multiple="true"
					:keepOpen="true"
					label="name"
					:placeholder="t('social', 'No groups')">
					<template #no-options>
						{{ t('social', 'This server has no groups') }}
					</template>
				</NcSelect>
				<p class="sections__hint">
					{{ t('social', 'Everybody in one of these groups gets a list for it, holding the members who have a Aloha Social account. Nobody is followed by it and nothing leaves this server: a list is a view, not a relationship. Empty — the default — means no group becomes a list, because a group list tells everybody in the group who else is in it.') }}
				</p>
			</div>

			<NcButton variant="primary" :disabled="saving" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Save') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { errorMessage, sectionsUrl } from '../../services/adminApi.js'
import { showError, showSuccess } from '../../services/toast.js'

/**
 * Which parts of the app this instance offers, and which Nextcloud groups
 * become Social lists.
 *
 * One save for the card rather than one per switch: the endpoint writes all of
 * it or none of it, and four switches that each saved on their own would let
 * an administrator leave the page half applied without being told.
 */
export default {
	name: 'SectionsSection',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
	},

	props: {
		/** what stands now, as `SectionsService::current()` answers it */
		settings: {
			type: Object,
			default: () => ({}),
		},

		/** every group on this server, `{ id, name }` */
		groups: {
			type: /** @type {import('vue').PropType<Array<{id: string, name: string}>>} */ (Array),
			default: () => [],
		},
	},

	data() {
		return {
			form: {
				stories: this.settings?.stories !== false,
				photos: this.settings?.section_photos !== false,
				videos: this.settings?.section_videos !== false,
				// the picker works in whole options, so the stored ids are
				// matched back to the groups they name; an id whose group has
				// since been deleted stands for itself rather than vanishing
				groupLists: (this.settings?.group_lists ?? []).map((id) => (
					this.groups.find((group) => group.id === id) ?? { id, name: id }
				)),
			},

			saving: false,
		}
	},

	computed: {
		/** @return {object[]} the groups, minus the ones already chosen */
		groupOptions() {
			const chosen = new Set(this.form.groupLists.map((group) => group.id))

			return this.groups.filter((group) => !chosen.has(group.id))
		},
	},

	methods: {
		t,

		/**
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			try {
				await axios.post(sectionsUrl(), {
					stories: this.form.stories,
					photos: this.form.photos,
					videos: this.form.videos,
					groupLists: this.form.groupLists.map((group) => group.id),
				})
				showSuccess(t('social', 'Saved'))
			} catch (error) {
				showError(errorMessage(error, t('social', 'Could not save what this instance offers')))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.sections {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: flex-start;

	&__hint {
		color: var(--color-text-maxcontrast);
		max-width: 62ch;
		margin-block: 0 calc(var(--default-grid-baseline) * 2);
	}

	&__groups {
		inline-size: 100%;
		max-inline-size: 480px;
		margin-block-start: calc(var(--default-grid-baseline) * 2);
	}

	&__label {
		display: block;
		margin-block-end: calc(var(--default-grid-baseline) * 1);
	}
}
</style>
