<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="place social__wrapper">
		<NcLoadingIcon v-if="loading" class="place__loading" :size="44" />

		<div v-else-if="error" class="place__error" role="alert">
			<p>{{ error }}</p>
			<NcButton variant="primary" @click="load">
				<template #icon>
					<IconRefresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<template v-else-if="place">
			<header class="place__head">
				<IconMapMarker :size="28" class="place__icon" />
				<div class="place__titles">
					<h1 class="place__title">
						{{ place.name }}
					</h1>
					<p v-if="place.country" class="place__country">
						{{ place.country }}
					</p>
				</div>
			</header>
			<p class="place__hint">
				{{ t('social', 'Public posts that were taken here, as their posters said. A place is never worked out from a photo — it is only ever what somebody chose to say.') }}
			</p>

			<ProfileMediaGrid :posts="posts" :loading="loadingMore" />

			<NcEmptyContent
				v-if="!posts.length && !loadingMore"
				class="place__empty"
				:name="t('social', 'Nothing public from here yet')">
				<template #icon>
					<IconMapMarker :size="20" />
				</template>
			</NcEmptyContent>

			<div v-if="hasMore" class="place__more">
				<NcButton :disabled="loadingMore" @click="loadMore()">
					{{ t('social', 'Show more') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconMapMarker from 'vue-material-design-icons/MapMarkerOutline.vue'
import IconRefresh from 'vue-material-design-icons/Refresh.vue'
import ProfileMediaGrid from '../components/ProfileMediaGrid.vue'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'
import { latestLoad } from '../utils/latestLoad.js'

/** how many posts one page asks for */
const PAGE = 20

/**
 * One place: the public posts taken there, as a grid.
 *
 * Pixelfed's place page. It is deliberately a page of what people said
 * about where they were and nothing more — no map, no geocoding, no
 * "nearby" — because the one thing this app promises about location is
 * that it never infers any.
 */
export default {
	name: 'PlacePage',

	components: {
		IconMapMarker,
		IconRefresh,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		ProfileMediaGrid,
	},

	props: {
		/** the place's id, from the route */
		id: {
			type: [String, Number],
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			loadingMore: false,
			error: '',
			/** @type {object|null} */
			place: null,
			/** @type {Array<object>} */
			posts: [],
			hasMore: false,
			loads: latestLoad(),
		}
	},

	watch: {
		id: 'load',
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/** @return {Promise<void>} */
		async load() {
			const id = this.id
			const isNewest = this.loads.begin()
			this.loading = true
			this.loadingMore = false
			this.error = ''
			this.posts = []
			try {
				const { data } = await axios.get(generateUrl(`apps/social/api/v1/places/${id}`))
				if (!isNewest()) {
					return
				}
				this.place = data
				await this.loadMore(id, isNewest)
			} catch (error) {
				logger.error('could not load the place', { error })
				if (isNewest()) {
					this.error = (error?.response?.status === 404)
						? t('social', 'There is no such place.')
						: t('social', 'The place could not be loaded.')
				}
			} finally {
				if (isNewest()) {
					this.loading = false
				}
			}
		},

		/**
		 * @param {string|number} id the place the page belongs to
		 * @param {() => boolean} isNewest whether that is still the place on screen
		 * @return {Promise<void>}
		 */
		async loadMore(id = this.id, isNewest = this.loads.current()) {
			this.loadingMore = true
			try {
				const last = this.posts[this.posts.length - 1]
				const params = { limit: PAGE }
				if (last) {
					params.max_id = last.id
				}
				const { data } = await axios.get(generateUrl(`apps/social/api/v1/places/${id}/statuses`), { params })
				if (!isNewest()) {
					return
				}
				const page = Array.isArray(data) ? data : []
				this.posts = [...this.posts, ...page]
				this.hasMore = page.length >= PAGE
			} catch (error) {
				logger.error('could not load the posts of the place', { error })
				if (isNewest()) {
					showError(t('social', 'The posts from this place could not be loaded'))
				}
			} finally {
				if (isNewest()) {
					this.loadingMore = false
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
.place {
	padding: 0 10px;
}

.place__loading {
	margin: 60px auto;
}

.place__error {
	display: flex;
	flex-direction: column;
	gap: 10px;
	align-items: flex-start;
	padding: 16px;
}

.place__head {
	display: flex;
	gap: 10px;
	align-items: center;
	margin: 12px 0 4px;
}

.place__icon {
	color: var(--color-primary-element);
}

.place__title {
	margin: 0;
	font-size: 22px;
	font-weight: bold;
	overflow-wrap: anywhere;
}

.place__country {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.place__hint {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.place__empty {
	margin-top: 24px;
}

.place__more {
	display: flex;
	justify-content: center;
	margin: 16px 0;
}
</style>
