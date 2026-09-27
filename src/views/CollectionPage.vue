<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="collection social__wrapper">
		<NcLoadingIcon v-if="loading" class="collection__loading" :size="44" />

		<div v-else-if="error" class="collection__error" role="alert">
			<p>{{ error }}</p>
			<NcButton variant="primary" @click="load">
				<template #icon>
					<IconRefresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<template v-else-if="collection">
			<header class="collection__head">
				<router-link
					v-if="ownerAcct"
					class="collection__back"
					:to="{ name: 'profile.collections', params: { account: ownerAcct } }">
					<IconArrowLeft :size="20" />
					{{ t('social', 'All collections') }}
				</router-link>

				<div class="collection__titles">
					<h1 class="collection__title">
						{{ collection.title }}
						<span v-if="collection.visibility !== 'public'" class="collection__lock" :title="t('social', 'Followers only')">
							<IconLock :size="18" />
							<span class="hidden-visually">{{ t('social', 'Followers only') }}</span>
						</span>
					</h1>
					<p v-if="collection.description" class="collection__description">
						{{ collection.description }}
					</p>
					<p class="collection__meta">
						<router-link
							v-if="ownerAcct"
							class="collection__owner"
							:to="{ name: 'profile', params: { account: ownerAcct } }">
							<ActorAvatar
								:actor="collection.account"
								:size="24"
								:link="false"
								:hoverCard="false" />
							{{ collection.account.display_name || collection.account.username }}
						</router-link>
						<span>{{ n('social', '%n post', '%n posts', collection.size) }}</span>
					</p>
				</div>

				<div v-if="isOwn" class="collection__actions">
					<NcButton @click="openEdit">
						<template #icon>
							<IconPencil :size="20" />
						</template>
						{{ t('social', 'Edit') }}
					</NcButton>
					<NcButton :pressed="managing" @click="managing = !managing">
						<template #icon>
							<IconImageRemove :size="20" />
						</template>
						{{ managing ? t('social', 'Done') : t('social', 'Remove posts') }}
					</NcButton>
					<NcButton variant="error" @click="showDelete = true">
						<template #icon>
							<IconDelete :size="20" />
						</template>
						{{ t('social', 'Delete') }}
					</NcButton>
				</div>
			</header>

			<!-- taking posts out: the same squares, each with a button on it,
			     rather than a second list nobody would recognise -->
			<ul v-if="managing && posts.length" class="collection__manage">
				<li v-for="post in posts" :key="post.id" class="collection__manage-cell">
					<img
						v-if="thumbOf(post)"
						class="collection__manage-image"
						:src="thumbOf(post)"
						:alt="post.media_attachments[0].description || ''">
					<NcButton
						class="collection__manage-remove"
						variant="error"
						:ariaLabel="t('social', 'Remove from the collection')"
						:disabled="removing.includes(post.id)"
						@click="removePost(post)">
						<template #icon>
							<IconClose :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>
			<ProfileMediaGrid
				v-else
				:posts="posts"
				:account="ownerAcct"
				:loading="loadingMore" />

			<NcEmptyContent
				v-if="!posts.length && !loadingMore"
				class="collection__empty"
				:name="t('social', 'Nothing in this collection yet')"
				:description="isOwn
					? t('social', 'Add posts to it from their menu: … → Add to a collection.')
					: ''">
				<template #icon>
					<IconFolderMultipleImage :size="20" />
				</template>
			</NcEmptyContent>

			<div v-if="hasMore" class="collection__more">
				<NcButton :disabled="loadingMore" @click="loadMore()">
					{{ t('social', 'Show more') }}
				</NcButton>
			</div>

			<NcDialog
				v-model:open="showEdit"
				:name="t('social', 'Edit collection')"
				:buttons="editButtons">
				<form class="collection__form" @submit.prevent="saveEdit">
					<NcTextField
						v-model="editTitle"
						:label="t('social', 'Title')"
						maxlength="255" />
					<NcTextField
						v-model="editDescription"
						:label="t('social', 'Description')"
						maxlength="500" />
					<NcCheckboxRadioSwitch v-model="editFollowersOnly" type="switch">
						{{ t('social', 'Followers only') }}
					</NcCheckboxRadioSwitch>
				</form>
			</NcDialog>

			<NcDialog
				v-model:open="showDelete"
				:name="t('social', 'Delete this collection?')"
				:buttons="deleteButtons">
				<p class="collection__hint">
					{{ t('social', 'The collection goes; the posts in it stay where they are.') }}
				</p>
			</NcDialog>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconDelete from 'vue-material-design-icons/Delete.vue'
import IconFolderMultipleImage from 'vue-material-design-icons/FolderMultipleImage.vue'
import IconImageRemove from 'vue-material-design-icons/ImageRemove.vue'
import IconLock from 'vue-material-design-icons/Lock.vue'
import IconPencil from 'vue-material-design-icons/Pencil.vue'
import IconRefresh from 'vue-material-design-icons/Refresh.vue'
import ActorAvatar from '../components/ActorAvatar.vue'
import ProfileMediaGrid from '../components/ProfileMediaGrid.vue'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { useAccountStore } from '../store/account.js'
import { latestLoad } from '../utils/latestLoad.js'

/** how many posts one page of a collection asks for: the API's ceiling */
const PAGE = 40

/**
 * One collection: its posts as a grid, and for the owner the three things
 * they can do to it.
 *
 * The grid is the profile's own, so a collection looks like the profile it
 * was made from. Removing posts turns the same squares into squares with a
 * button on each: the owner takes posts out where they see them, not in a
 * list of titles a picture does not have.
 */
export default {
	name: 'CollectionPage',

	components: {
		ActorAvatar,
		IconArrowLeft,
		IconClose,
		IconDelete,
		IconFolderMultipleImage,
		IconImageRemove,
		IconLock,
		IconPencil,
		IconRefresh,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextField,
		ProfileMediaGrid,
	},

	props: {
		/** the collection's id, from the route */
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
			collection: null,
			/** @type {Array<object>} */
			posts: [],
			hasMore: false,
			managing: false,
			/** ids of the posts whose removal is on its way */
			removing: [],
			showEdit: false,
			editTitle: '',
			editDescription: '',
			editFollowersOnly: false,
			saving: false,
			showDelete: false,
			deleting: false,
			loads: latestLoad(),
		}
	},

	computed: {
		...mapStores(useAccountStore),

		/** @return {string} the owner's handle, or '' while unknown */
		ownerAcct() {
			return this.collection?.account?.acct ?? ''
		},

		/** @return {boolean} whether the reader made this collection */
		isOwn() {
			const current = this.accountStore.currentAccount

			return Boolean(current && this.ownerAcct && current.acct === this.ownerAcct)
		},

		/** @return {import('../types/Nextcloud.js').DialogButton[]} */
		editButtons() {
			return [
				{ label: t('social', 'Cancel'), callback: () => { this.showEdit = false } },
				{
					label: t('social', 'Save'),
					variant: 'primary',
					disabled: this.saving || this.editTitle.trim() === '',
					callback: () => this.saveEdit(),
				},
			]
		},

		/** @return {import('../types/Nextcloud.js').DialogButton[]} */
		deleteButtons() {
			return [
				{ label: t('social', 'Cancel'), callback: () => { this.showDelete = false } },
				{
					label: t('social', 'Delete'),
					variant: 'error',
					disabled: this.deleting,
					callback: () => this.remove(),
				},
			]
		},
	},

	watch: {
		id: 'load',
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		/**
		 * @param {string} suffix what follows the collection in the path
		 * @param {string|number} id the collection, the one on screen unless given
		 * @return {string} the API path of this collection
		 */
		url(suffix = '', id = this.id) {
			return generateUrl(`apps/social/api/v1/collections/${id}${suffix}`)
		},

		/** @return {Promise<void>} */
		async load() {
			// the id is taken once, so the collection and its posts are always
			// asked of the same one even if the route moves on in between
			const id = this.id
			const isNewest = this.loads.begin()
			this.loading = true
			this.loadingMore = false
			this.error = ''
			this.posts = []
			this.managing = false
			try {
				const { data } = await axios.get(this.url('', id))
				if (!isNewest()) {
					return
				}
				this.collection = data
				await this.loadMore(id, isNewest)
			} catch (error) {
				logger.error('could not load the collection', { error })
				if (isNewest()) {
					this.error = (error?.response?.status === 404)
						? t('social', 'There is no such collection, or it is not one you can see.')
						: t('social', 'The collection could not be loaded.')
				}
			} finally {
				if (isNewest()) {
					this.loading = false
				}
			}
		},

		/**
		 * @param {string|number} id the collection the page belongs to
		 * @param {() => boolean} isNewest whether that is still the collection on screen
		 * @return {Promise<void>}
		 */
		async loadMore(id = this.id, isNewest = this.loads.current()) {
			this.loadingMore = true
			try {
				const { data } = await axios.get(this.url('/items', id), { params: { limit: PAGE, offset: this.posts.length } })
				if (!isNewest()) {
					return
				}
				const page = Array.isArray(data) ? data : []
				this.posts = [...this.posts, ...page]
				// a page shorter than asked for is the last one
				this.hasMore = page.length >= PAGE
			} catch (error) {
				logger.error('could not load the collection\'s posts', { error })
				if (isNewest()) {
					showError(t('social', 'The posts of the collection could not be loaded'))
				}
			} finally {
				if (isNewest()) {
					this.loadingMore = false
				}
			}
		},

		openEdit() {
			this.editTitle = this.collection?.title ?? ''
			this.editDescription = this.collection?.description ?? ''
			this.editFollowersOnly = this.collection?.visibility !== 'public'
			this.showEdit = true
		},

		/** @return {Promise<void>} */
		async saveEdit() {
			const title = this.editTitle.trim()
			if (title === '' || this.saving) {
				return
			}

			this.saving = true
			try {
				const { data } = await axios.put(this.url(), {
					title,
					description: this.editDescription.trim(),
					visibility: this.editFollowersOnly ? 'followers' : 'public',
				})
				// the answer carries no owner; keep the one the page has
				this.collection = { ...this.collection, ...data, account: data.account ?? this.collection.account }
				this.showEdit = false
				showSuccess(t('social', 'Collection saved'))
			} catch (error) {
				logger.error('could not save the collection', { error })
				showError(error?.response?.data?.error || t('social', 'Could not save the collection'))
			} finally {
				this.saving = false
			}
		},

		/** @return {Promise<void>} */
		async remove() {
			if (this.deleting) {
				return
			}

			this.deleting = true
			try {
				await axios.delete(this.url())
				showSuccess(t('social', 'Collection deleted'))
				this.showDelete = false
				if (this.ownerAcct) {
					this.$router.push({ name: 'profile.collections', params: { account: this.ownerAcct } })
				} else {
					this.$router.push({ name: 'timeline' })
				}
			} catch (error) {
				logger.error('could not delete the collection', { error })
				showError(t('social', 'Could not delete the collection'))
			} finally {
				this.deleting = false
			}
		},

		/**
		 * @param {object} post one of the collection's posts
		 * @return {Promise<void>}
		 */
		async removePost(post) {
			if (this.removing.includes(post.id)) {
				return
			}

			this.removing = [...this.removing, post.id]
			try {
				await axios.delete(this.url(`/items/${post.id}`))
				this.posts = this.posts.filter((candidate) => candidate.id !== post.id)
				if (this.collection) {
					this.collection = { ...this.collection, size: Math.max(0, (this.collection.size ?? 1) - 1) }
				}
			} catch (error) {
				logger.error('could not take the post out of the collection', { error })
				showError(t('social', 'Could not remove the post from the collection'))
			} finally {
				this.removing = this.removing.filter((id) => id !== post.id)
			}
		},

		/**
		 * @param {object} post one post
		 * @return {string} its first picture, small
		 */
		thumbOf(post) {
			const first = (post.media_attachments ?? [])[0]

			return first ? (first.preview_url || first.url || '') : ''
		},
	},
}
</script>

<style scoped lang="scss">
.collection {
	padding: 0 10px;
}

.collection__loading {
	margin: 60px auto;
}

.collection__error {
	display: flex;
	flex-direction: column;
	gap: 10px;
	align-items: flex-start;
	padding: 16px;
}

.collection__head {
	display: flex;
	flex-wrap: wrap;
	gap: 12px 16px;
	align-items: flex-start;
	margin: 12px 0 16px;
}

.collection__back {
	display: inline-flex;
	gap: 4px;
	align-items: center;
	flex-basis: 100%;
	color: var(--color-text-maxcontrast);

	&:hover,
	&:focus-visible {
		text-decoration: underline;
	}
}

.collection__titles {
	flex: 1 1 260px;
	min-width: 0;
}

.collection__title {
	display: flex;
	gap: 8px;
	align-items: center;
	margin: 0;
	font-size: 22px;
	font-weight: bold;
	overflow-wrap: anywhere;
}

.collection__lock {
	display: inline-flex;
	color: var(--color-text-maxcontrast);
}

.collection__description {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.collection__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 6px 14px;
	align-items: center;
	margin: 8px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.collection__owner {
	display: inline-flex;
	gap: 6px;
	align-items: center;
	color: var(--color-main-text);

	&:hover,
	&:focus-visible {
		text-decoration: underline;
	}
}

.collection__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.collection__manage {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
	gap: 2px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.collection__manage-cell {
	position: relative;
	aspect-ratio: 1 / 1;
	overflow: hidden;
	background: var(--color-background-dark);
}

.collection__manage-image {
	display: block;
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.collection__manage-remove {
	position: absolute;
	top: 6px;
	inset-inline-end: 6px;
}

.collection__empty {
	margin-top: 24px;
}

.collection__more {
	display: flex;
	justify-content: center;
	margin: 16px 0;
}

.collection__form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}

.collection__hint {
	color: var(--color-text-maxcontrast);
}
</style>
