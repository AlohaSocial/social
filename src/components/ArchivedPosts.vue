<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="archived-posts">
		<p v-if="loading && posts.length === 0" class="archived-posts__hint">
			{{ t('social', 'Loading …') }}
		</p>
		<ul v-else-if="posts.length > 0" class="archived-posts__list">
			<li v-for="post in posts" :key="post.id" class="archived-posts__item">
				<div class="archived-posts__when">
					<IconArchiveOutline :size="16" decorative title="" />
					<time :datetime="post.created_at">{{ fullDateTime(post.created_at) }}</time>
				</div>
				<!-- the words, not the rendered post: this is a list to find something in -->
				<p class="archived-posts__text">
					{{ textOf(post) }}
				</p>
				<p v-if="attachmentsOf(post) > 0" class="archived-posts__meta">
					{{ n('social', '%n attachment', '%n attachments', attachmentsOf(post)) }}
				</p>
				<NcButton
					:disabled="restoring.includes(post.id)"
					@click="restore(post)">
					{{ t('social', 'Put back') }}
				</NcButton>
			</li>
		</ul>
		<p v-else class="archived-posts__hint">
			{{ t('social', 'You have not archived anything.') }}
		</p>
		<NcButton v-if="hasMore" :disabled="loading" @click="load()">
			{{ t('social', 'Show more') }}
		</NcButton>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { fullDateTime } from '../utils/relativeTime.js'

/** How many to ask for at a time; the route's own ceiling. */
const PAGE_SIZE = 50

/** The reader's archived posts, each with a button that puts it back. */
export default {
	name: 'ArchivedPosts',

	components: {
		IconArchiveOutline,
		NcButton,
	},

	data() {
		return {
			loading: false,
			/** @type {object[]} the archived posts, newest first */
			posts: [],
			/** @type {string[]} the ids being put back */
			restoring: [],
			hasMore: false,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,
		fullDateTime,

		/** @param {object} post a Status */
		textOf(post) {
			// the rendered content with its markup taken out
			return (post.content ?? '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()
		},

		/** @param {object} post a Status */
		attachmentsOf(post) {
			return Array.isArray(post.media_attachments) ? post.media_attachments.length : 0
		},

		/** @return {Promise<void>} */
		async load() {
			if (this.loading) {
				return
			}

			this.loading = true
			try {
				const maxId = this.posts.length > 0 ? this.posts[this.posts.length - 1].id : 0
				const { data } = await axios.get(
					generateUrl('apps/social/api/pixelfed/v1/archive/list'),
					{ params: { limit: PAGE_SIZE, max_id: maxId } },
				)
				const page = Array.isArray(data) ? data : []
				this.posts = this.posts.concat(page)
				this.hasMore = page.length === PAGE_SIZE
			} catch (error) {
				logger.error('Failed to load the archived posts', { error })
				showError(t('social', 'Could not load your archived posts'))
			} finally {
				this.loading = false
			}
		},

		/** @param {object} post the one to put back */
		async restore(post) {
			this.restoring = [...this.restoring, post.id]
			try {
				await axios.post(generateUrl('apps/social/api/pixelfed/v1/archive/remove/' + post.id))
				this.posts = this.posts.filter((one) => one.id !== post.id)
				showSuccess(t('social', 'It is back on your profile'))
			} catch (error) {
				logger.error('Failed to restore a post', { error })
				showError(t('social', 'Could not put the post back'))
			} finally {
				this.restoring = this.restoring.filter((id) => id !== post.id)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.archived-posts__hint {
	color: var(--color-text-maxcontrast);
}

.archived-posts__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-block-end: 8px;
}

.archived-posts__item {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.archived-posts__when {
	display: flex;
	align-items: center;
	gap: 6px;
	color: var(--color-text-maxcontrast);
}

.archived-posts__text {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.archived-posts__meta {
	color: var(--color-text-maxcontrast);
}
</style>
