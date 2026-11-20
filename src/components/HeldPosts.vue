<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="held-posts">
		<p v-if="loading && posts.length === 0" class="held-posts__hint">
			{{ t('social', 'Loading …') }}
		</p>
		<ul v-else-if="posts.length > 0" class="held-posts__list">
			<li v-for="post in posts" :key="post.id" class="held-posts__item">
				<div class="held-posts__when">
					<ClockOutline :size="16" decorative title="" />
					<time :datetime="post.created_at">{{ fullDateTime(post.created_at) }}</time>
					<span class="held-posts__reason">{{ reasonText(post.reason) }}</span>
				</div>
				<p v-if="post.spoiler_text" class="held-posts__warning">
					{{ post.spoiler_text }}
				</p>
				<p class="held-posts__text">
					{{ post.text }}
				</p>
				<p v-if="post.media_count > 0" class="held-posts__meta">
					{{ n('social', '%n attachment', '%n attachments', post.media_count) }}
				</p>
				<NcButton
					variant="tertiary"
					class="held-posts__withdraw"
					:aria-label="t('social', 'Take this post back')"
					:title="t('social', 'Take this post back')"
					:disabled="withdrawing.includes(post.id)"
					@click="withdraw(post)">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
		<p v-else class="held-posts__hint">
			{{ t('social', 'Nothing of yours is waiting.') }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'
import { fullDateTime } from '../utils/relativeTime.js'

/**
 * The reader's own posts held for moderation, so they can find them again.
 * Withdrawing one deletes it and leaves no moderation record.
 */
export default {
	name: 'HeldPosts',

	components: {
		ClockOutline,
		Close,
		NcButton,
	},

	data() {
		return {
			loading: false,
			/** @type {object[]} the held posts, newest first */
			posts: [],
			/** @type {string[]} the ids whose withdrawal is in flight */
			withdrawing: [],
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,
		fullDateTime,

		/**
		 * @param {string} reason the rule that held it
		 * @return {string} what the reader is told
		 */
		reasonText(reason) {
			switch (reason) {
				case 'first_post':
					return t('social', 'Your first post here')
				case 'links':
					return t('social', 'More links than a post usually carries')
				case 'mentions':
					return t('social', 'A lot of mentions')
				case 'repeat':
					return t('social', 'The same as a post already waiting')
				case 'video':
					return t('social', 'Videos are looked at before they are published here')
				default:
					return reason
			}
		},

		async load() {
			if (this.loading) {
				return
			}

			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/review'))
				this.posts = Array.isArray(data.held) ? data.held : []
			} catch (error) {
				logger.error('Failed to load the held posts', { error })
				showError(t('social', 'Could not load the posts waiting to be looked at'))
			} finally {
				this.loading = false
			}
		},

		/** @param {object} post the held post to take back */
		async withdraw(post) {
			this.withdrawing = [...this.withdrawing, post.id]
			try {
				await axios.delete(generateUrl('apps/social/api/v1/review/' + post.id))
				this.posts = this.posts.filter((one) => one.id !== post.id)
			} catch (error) {
				logger.error('Failed to withdraw a held post', { error })
				showError(t('social', 'Could not take the post back'))
			} finally {
				this.withdrawing = this.withdrawing.filter((id) => id !== post.id)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.held-posts__hint {
	color: var(--color-text-maxcontrast);
}

.held-posts__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.held-posts__item {
	position: relative;
	padding: 8px 44px 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.held-posts__when {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
	color: var(--color-text-maxcontrast);
}

.held-posts__reason {
	font-style: italic;
}

.held-posts__warning {
	font-weight: bold;
}

.held-posts__text {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.held-posts__meta {
	color: var(--color-text-maxcontrast);
}

.held-posts__withdraw {
	position: absolute;
	inset-block-start: 4px;
	inset-inline-end: 4px;
}
</style>
