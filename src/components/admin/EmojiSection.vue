<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('social', 'Custom emoji')"
		:description="t('social', 'The pictures people here can write into a post as :shortcode:. They travel with the post, so somebody on another server sees them too. Until now they could only be added with occ, which meant most instances had none.')">
		<form class="emoji__add" @submit.prevent="add">
			<NcTextField
				v-model="shortcode"
				class="emoji__shortcode"
				:label="t('social', 'Shortcode')"
				placeholder="blobcat"
				:disabled="busy" />
			<NcTextField
				v-model="category"
				class="emoji__category"
				:label="t('social', 'Group it belongs to')"
				:disabled="busy" />
			<input
				ref="picture"
				type="file"
				class="emoji__file"
				accept="image/png,image/gif,image/webp,image/jpeg"
				@change="pick">
			<NcButton :disabled="busy" @click="choosePicture">
				<template #icon>
					<IconUpload :size="20" />
				</template>
				{{ pictureName || t('social', 'Choose a picture') }}
			</NcButton>
			<NcButton type="submit" variant="primary" :disabled="busy || !canAdd">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Add') }}
			</NcButton>
		</form>
		<p class="social-admin__hint">
			{{ t('social', 'A shortcode is 2 to 64 characters of a–z, 0–9 and underscore, and the picture is at most 64 KiB. The same rules the command applies, because it is the same code.') }}
		</p>

		<NcEmptyContent
			v-if="emojis.length === 0"
			:name="t('social', 'This server has no emoji of its own.')">
			<template #icon>
				<IconEmoticon :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="emoji__list">
			<li v-for="emoji in emojis" :key="emoji.shortcode" class="emoji__item">
				<img class="emoji__image" :src="emoji.url" :alt="`:${emoji.shortcode}:`">
				<code class="emoji__code">:{{ emoji.shortcode }}:</code>
				<span v-if="emoji.category" class="emoji__group">{{ emoji.category }}</span>
				<NcButton :disabled="busy" @click="remove(emoji)">
					{{ t('social', 'Remove') }}
				</NcButton>
			</li>
		</ul>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import IconEmoticon from 'vue-material-design-icons/Emoticon.vue'
import IconUpload from 'vue-material-design-icons/Upload.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { moderationUrl } from '../../services/adminApi.js'
import { showError, showSuccess } from '../../services/toast.js'

/**
 * The instance's own emoji.
 *
 * The upload goes to a route that hands the file to the same service
 * `occ social:emoji` calls, so what is refused here is exactly what the
 * command refuses — one set of rules rather than two that drift.
 */
export default {
	name: 'EmojiSection',

	components: {
		IconEmoticon,
		IconUpload,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSettingsSection,
		NcTextField,
	},

	data() {
		return {
			emojis: [],
			shortcode: '',
			category: '',
			picture: null,
			pictureName: '',
			busy: false,
		}
	},

	computed: {
		/** @return {boolean} */
		canAdd() {
			return this.shortcode.trim() !== '' && this.picture !== null
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/** Opens the file chooser for the emoji's picture. */
		choosePicture() {
			/** @type {HTMLInputElement} */ (this.$refs.picture).click()
		},

		t,

		/** @return {Promise<void>} */
		async load() {
			try {
				const { data } = await axios.get(moderationUrl('/emojis'))
				this.emojis = data.emojis ?? []
			} catch {
				showError(t('social', 'Could not load the emoji'))
			}
		},

		/**
		 * @param {Event} event the file input's change
		 */
		pick(event) {
			this.picture = /** @type {HTMLInputElement} */ (event.target).files?.[0] ?? null
			this.pictureName = this.picture?.name ?? ''
		},

		/** @return {Promise<void>} */
		async add() {
			if (!this.canAdd) {
				return
			}

			this.busy = true
			try {
				const body = new FormData()
				body.append('shortcode', this.shortcode.trim())
				body.append('category', this.category.trim())
				body.append('picture', this.picture)

				const { data } = await axios.post(moderationUrl('/emojis'), body)
				this.emojis = data.emojis ?? []
				this.shortcode = ''
				this.category = ''
				this.picture = null
				this.pictureName = ''
				showSuccess(t('social', 'Added'))
			} catch (error) {
				showError(error.response?.data?.error ?? t('social', 'Could not add that emoji'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} emoji the one to remove
		 * @return {Promise<void>}
		 */
		async remove(emoji) {
			this.busy = true
			try {
				const { data } = await axios.delete(moderationUrl('/emojis'), {
					data: { shortcode: emoji.shortcode },
				})
				this.emojis = data.emojis ?? []
			} catch {
				showError(t('social', 'Could not remove that emoji'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.emoji__add {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
}

.emoji__file {
	display: none;
}

.emoji__shortcode,
.emoji__category {
	max-width: 200px;
}

.emoji__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.emoji__item {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.emoji__image {
	width: 24px;
	height: 24px;
	object-fit: contain;
}

.emoji__group {
	color: var(--color-text-maxcontrast);
}
</style>
