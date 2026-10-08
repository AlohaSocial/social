<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<div class="discover__add">
			<NcTextField
				v-model="name"
				class="discover__name"
				:label="t('social', 'Subject')"
				:placeholder="t('social', 'Architecture')"
				:disabled="busy" />
			<NcTextField
				v-model="hashtags"
				class="discover__tags"
				:label="t('social', 'The hashtags it means')"
				placeholder="#brutalism #concrete #stairwells"
				:disabled="busy" />
			<NcButton :disabled="busy || name.trim() === ''" @click="add">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Add') }}
			</NcButton>
		</div>

		<NcEmptyContent
			v-if="categories.length === 0"
			:name="t('social', 'Nothing is named yet.')"
			:description="t('social', 'Until something is, Explore shows only what is trending.')">
			<template #icon>
				<IconCompass :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="discover__list">
			<li v-for="category in categories" :key="category.id" class="discover__item">
				<div>
					<strong>{{ category.name }}</strong>
					<span class="discover__item-tags">{{ category.hashtags.map((tag) => '#' + tag).join(' ') }}</span>
				</div>
				<NcButton :disabled="busy" @click="remove(category)">
					{{ t('social', 'Remove') }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import IconCompass from 'vue-material-design-icons/Compass.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { moderationUrl } from '../../services/adminApi.js'
import { showError } from '../../services/toast.js'

/** The curated half of Explore. */
export default {
	name: 'DiscoverSection',

	components: {
		IconCompass,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextField,
	},

	data() {
		return {
			categories: [],
			name: '',
			hashtags: '',
			busy: false,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/** @return {Promise<void>} */
		async load() {
			try {
				const { data } = await axios.get(moderationUrl('/discover/categories'))
				this.categories = data.categories ?? []
			} catch {
				showError(t('social', 'Could not load the subjects'))
			}
		},

		/** @return {Promise<void>} */
		async add() {
			this.busy = true
			try {
				const { data } = await axios.post(moderationUrl('/discover/categories'), {
					name: this.name.trim(),
					hashtags: this.hashtags.trim(),
				})
				this.categories = data.categories ?? []
				this.name = ''
				this.hashtags = ''
			} catch (error) {
				showError(error.response?.data?.error ?? t('social', 'Could not add that subject'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} category the one to remove
		 * @return {Promise<void>}
		 */
		async remove(category) {
			this.busy = true
			try {
				const { data } = await axios.delete(moderationUrl('/discover/categories'), {
					data: { id: category.id },
				})
				this.categories = data.categories ?? []
			} catch {
				showError(t('social', 'Could not remove that subject'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.discover__add {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
	margin-block-end: 8px;
}

.discover__name {
	max-width: 220px;
}

.discover__tags {
	max-width: 420px;
}

.discover__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.discover__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

.discover__item-tags {
	margin-inline-start: 8px;
	color: var(--color-text-maxcontrast);
}
</style>
