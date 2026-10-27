<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="collections">
		<TimelineSwitcher
			:options="kinds"
			value="collections"
			:label="t('social', 'Which of their posts to show')" />

		<!-- an album is made here, where the albums are, rather than in a
		     settings page nobody would look for it in -->
		<form v-if="isOwn" class="collections__create" @submit.prevent="create">
			<NcTextField
				v-model="newTitle"
				class="collections__create-title"
				:label="t('social', 'New collection')"
				:placeholder="t('social', 'What to call it')"
				maxlength="255" />
			<NcCheckboxRadioSwitch
				v-model="newFollowersOnly"
				type="switch"
				class="collections__create-visibility">
				{{ t('social', 'Followers only') }}
			</NcCheckboxRadioSwitch>
			<NcButton type="submit" variant="primary" :disabled="newTitle.trim() === '' || creating">
				<template #icon>
					<NcLoadingIcon v-if="creating" :size="20" />
					<IconPlus v-else :size="20" />
				</template>
				{{ t('social', 'Create') }}
			</NcButton>
		</form>

		<NcLoadingIcon v-if="loading" class="collections__loading" :size="32" />

		<div v-else-if="error" class="collections__error" role="alert">
			<p>{{ error }}</p>
			<NcButton variant="primary" @click="load">
				<template #icon>
					<IconRefresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<ul v-else-if="collections.length" class="collections__list">
			<li v-for="collection in collections" :key="collection.id" class="collections__card">
				<router-link
					class="collections__link"
					:to="{ name: 'collection', params: { id: collection.id } }"
					:aria-label="cardLabel(collection)">
					<span class="collections__cover">
						<img
							v-if="coverOf(collection)"
							class="collections__cover-image"
							:src="coverOf(collection)"
							alt=""
							loading="lazy"
							decoding="async">
						<span v-else class="collections__cover-empty" aria-hidden="true">
							<IconFolderMultipleImage :size="32" />
						</span>
						<span v-if="collection.visibility !== 'public'" class="collections__lock" aria-hidden="true">
							<IconLock :size="16" />
						</span>
					</span>
					<span class="collections__title">{{ collection.title }}</span>
					<span class="collections__count">{{ n('social', '%n post', '%n posts', collection.size) }}</span>
				</router-link>
			</li>
		</ul>

		<NcEmptyContent
			v-else
			:name="t('social', 'No collections yet')"
			:description="isOwn
				? t('social', 'Gather some of your posts into an album: name one above, then add posts to it from their menu.')
				: t('social', 'Albums this account makes out of its posts will show up here.')">
			<template #icon>
				<IconFolderMultipleImage :size="20" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconFolderMultipleImage from 'vue-material-design-icons/FolderMultipleImage.vue'
import IconLock from 'vue-material-design-icons/Lock.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconRefresh from 'vue-material-design-icons/Refresh.vue'
import TimelineSwitcher from '../components/TimelineSwitcher.vue'
import { profileKinds } from '../composables/useProfileKinds.js'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { errorOf } from '../utils/serverError.js'
import { useAccountStore } from '../store/account.js'
import { latestLoad } from '../utils/latestLoad.js'

/**
 * An account's collections — Pixelfed's albums — as the fourth tab of a
 * profile.
 *
 * The API has had these for a while; this is the first page that shows them.
 * The list is asked of the server for the account on screen, so a visitor
 * sees the public ones and a follower the followers-only ones too, exactly as
 * the API decides it. The owner makes a new one here, where the others are.
 */
export default {
	name: 'ProfileCollections',

	components: {
		IconFolderMultipleImage,
		IconLock,
		IconPlus,
		IconRefresh,
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextField,
		TimelineSwitcher,
	},

	props: { profileLayout: { type: Object, default: undefined } },

	data() {
		return {
			loading: true,
			error: '',
			/** @type {Array<object>} */
			collections: [],
			newTitle: '',
			newFollowersOnly: false,
			creating: false,
			loads: latestLoad(),
		}
	},

	computed: {
		...mapStores(useAccountStore),

		/** @return {string} the handle in the route */
		account() {
			return String(this.$route.params.account ?? '')
		},

		kinds() {
			return profileKinds(this.account, this.profileLayout, 'collections')
		},

		/**
		 * @return {boolean} whether these are the reader's own albums. The
		 * handle in the route is what the server was asked about, so it is
		 * what the reader's own handle is compared with — no lookup, and
		 * nothing to wait for
		 */
		isOwn() {
			const current = this.accountStore.currentAccount?.acct

			return Boolean(current && this.account && current === this.account)
		},
	},

	watch: {
		account: 'load',
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		/** @return {Promise<void>} */
		async load() {
			// the handle from the route, which the server resolves as happily
			// as a numeric id or an actor URL: the page has no reason to wait
			// for the account store to be filled, and on the first paint it is
			// not
			const account = this.account
			const isNewest = this.loads.begin()
			if (!account) {
				this.loading = false

				return
			}

			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl(`apps/social/api/v1/accounts/${encodeURIComponent(account)}/collections`))
				if (isNewest()) {
					this.collections = Array.isArray(data) ? data : []
				}
			} catch (error) {
				logger.error('could not load the collections', { error })
				if (isNewest()) {
					this.error = t('social', 'The collections could not be loaded.')
				}
			} finally {
				if (isNewest()) {
					this.loading = false
				}
			}
		},

		/** @return {Promise<void>} */
		async create() {
			const title = this.newTitle.trim()
			if (title === '' || this.creating) {
				return
			}

			this.creating = true
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/collections'), {
					title,
					visibility: this.newFollowersOnly ? 'followers' : 'public',
				})
				// newest first, as the server lists them
				this.collections = [data, ...this.collections]
				this.newTitle = ''
				this.newFollowersOnly = false
				showSuccess(t('social', 'Collection created'))
			} catch (error) {
				logger.error('could not create the collection', { error })
				showError(errorOf(error, t('social', 'Could not create the collection')))
			} finally {
				this.creating = false
			}
		},

		/**
		 * @param {object} collection one album
		 * @return {string} the first picture of its first post, or ''
		 */
		coverOf(collection) {
			for (const post of collection.posts ?? []) {
				const first = (post.media_attachments ?? [])[0]
				if (first) {
					return first.preview_url || first.url || ''
				}
			}

			return ''
		},

		/**
		 * @param {object} collection one album
		 * @return {string} what the card is, for a reader who cannot see it
		 */
		cardLabel(collection) {
			return t('social', '{title}, {count}', {
				title: collection.title,
				count: n('social', '%n post', '%n posts', collection.size),
			})
		},
	},
}
</script>

<style scoped lang="scss">
.collections {
	min-width: 0;
}

.collections__create {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 12px;
	align-items: flex-end;
	margin: 12px 0 16px;
}

.collections__create-title {
	flex: 1 1 220px;
}

.collections__loading {
	margin: 40px auto;
}

.collections__error {
	display: flex;
	flex-direction: column;
	gap: 10px;
	align-items: flex-start;
	padding: 16px;
}

.collections__list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
	gap: 12px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.collections__link {
	display: flex;
	flex-direction: column;
	gap: 4px;
	color: var(--color-main-text);

	&:hover .collections__title,
	&:focus-visible .collections__title {
		text-decoration: underline;
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
		border-radius: var(--border-radius-large, 12px);
	}
}

.collections__cover {
	position: relative;
	display: block;
	aspect-ratio: 1 / 1;
	overflow: hidden;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-dark);
}

.collections__cover-image {
	display: block;
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.collections__cover-empty {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 100%;
	color: var(--color-text-maxcontrast);
}

.collections__lock {
	position: absolute;
	top: 6px;
	inset-inline-end: 6px;
	color: #fff;
	filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.6));
}

.collections__title {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.collections__count {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
