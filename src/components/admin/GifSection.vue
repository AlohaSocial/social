<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<form class="gif__add" @submit.prevent="add">
			<NcTextField
				v-model="slug"
				class="gif__slug"
				:label="t('social', 'Name')"
				placeholder="high-five"
				:disabled="busy" />
			<NcTextField
				v-model="title"
				class="gif__title"
				:label="t('social', 'What people search for')"
				:disabled="busy" />
			<input
				ref="picture"
				type="file"
				class="gif__file"
				accept="image/gif,image/webp,video/mp4"
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
			{{ t('social', 'A name is 2 to 64 characters of a–z, 0–9, - and _, and the picture is a GIF, an animated WebP or an MP4 of at most 8 MiB. Adding one under a name already in the library replaces it. The same rules the command applies, because it is the same code.') }}
		</p>

		<NcEmptyContent
			v-if="gifs.length === 0"
			:name="t('social', 'This server has no GIFs of its own.')">
			<template #icon>
				<IconGif :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="gif__list">
			<li v-for="gif in gifs" :key="gif.slug" class="gif__item">
				<video
					v-if="gif.media_type === 'video/mp4'"
					class="gif__preview"
					:src="gif.url"
					:aria-label="gif.title || gif.slug"
					muted
					loop
					autoplay
					playsinline />
				<img
					v-else
					class="gif__preview"
					:src="gif.url"
					:alt="gif.title || gif.slug"
					loading="lazy">
				<code class="gif__name">{{ gif.slug }}</code>
				<span v-if="gif.title" class="gif__search">{{ gif.title }}</span>
				<NcButton
					:aria-label="t('social', 'Remove {name}', { name: gif.slug })"
					:disabled="busy"
					@click="remove(gif)">
					{{ t('social', 'Remove') }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import IconGif from 'vue-material-design-icons/FileGifBox.vue'
import IconUpload from 'vue-material-design-icons/Upload.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { moderationUrl } from '../../services/adminApi.js'
import { showError, showSuccess } from '../../services/toast.js'
import { errorOf } from '../../utils/serverError.js'

/**
 * The server's own GIF library, which the composer's GIF picker offers
 * everybody before the animated emoji.
 *
 * The upload goes to a route that hands the file to the same service
 * `occ social:gif` calls, so what is refused here is exactly what the
 * command refuses.
 */
export default {
	name: 'GifSection',

	components: {
		IconGif,
		IconUpload,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextField,
	},

	data() {
		return {
			gifs: [],
			slug: '',
			title: '',
			picture: null,
			pictureName: '',
			busy: false,
		}
	},

	computed: {
		/** @return {boolean} */
		canAdd() {
			return this.slug.trim() !== '' && this.picture !== null
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/** Opens the file chooser for the picture. */
		choosePicture() {
			/** @type {HTMLInputElement} */ (this.$refs.picture).click()
		},

		/** @return {Promise<void>} */
		async load() {
			try {
				const { data } = await axios.get(moderationUrl('/gifs'))
				this.gifs = data.gifs ?? []
			} catch {
				showError(t('social', 'Could not load the GIFs'))
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
				body.append('slug', this.slug.trim())
				body.append('title', this.title.trim())
				body.append('picture', this.picture)

				const { data } = await axios.post(moderationUrl('/gifs'), body)
				this.gifs = data.gifs ?? []
				this.slug = ''
				this.title = ''
				this.picture = null
				this.pictureName = ''
				const input = /** @type {HTMLInputElement|undefined} */ (this.$refs.picture)
				if (input) {
					input.value = ''
				}
				showSuccess(t('social', 'Added'))
			} catch (error) {
				showError(errorOf(error, t('social', 'Could not add that GIF')))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} gif the one to remove
		 * @return {Promise<void>}
		 */
		async remove(gif) {
			this.busy = true
			try {
				const { data } = await axios.delete(moderationUrl('/gifs'), {
					data: { slug: gif.slug },
				})
				this.gifs = data.gifs ?? []
			} catch {
				showError(t('social', 'Could not remove that GIF'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.gif__add {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
}

.gif__file {
	display: none;
}

.gif__slug,
.gif__title {
	max-width: 220px;
}

.gif__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.gif__item {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.gif__preview {
	width: 64px;
	height: 64px;
	border-radius: var(--border-radius);
	object-fit: cover;
}

.gif__search {
	color: var(--color-text-maxcontrast);
}
</style>
