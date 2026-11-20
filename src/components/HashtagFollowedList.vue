<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="!serverData.public" class="followed-hashtags">
		<NcButton
			variant="tertiary"
			class="followed-hashtags__toggle"
			:aria-expanded="open ? 'true' : 'false'"
			aria-controls="followed-hashtags-list"
			@click="toggle">
			<template #icon>
				<Pound :size="20" />
			</template>
			{{ t('social', 'Followed hashtags') }}
		</NcButton>

		<!-- always in the tree, so the control it is named by has something to
		     point at whether it is open or not -->
		<div id="followed-hashtags-list" class="followed-hashtags__panel">
			<template v-if="open">
				<p v-if="loading" class="followed-hashtags__hint">
					{{ t('social', 'Loading …') }}
				</p>
				<template v-else-if="tags.length > 0">
					<ul class="followed-hashtags__list">
						<li v-for="tag in tags" :key="tag.name">
							<router-link :to="{ name: 'tags', params: { tag: tag.name } }">
								{{ '#' + tag.name }}
							</router-link>
						</li>
					</ul>
					<NcButton
						v-if="cursor"
						variant="tertiary"
						class="followed-hashtags__more"
						:disabled="loadingMore"
						@click="loadMore">
						{{ loadingMore ? t('social', 'Loading …') : t('social', 'Show more') }}
					</NcButton>
				</template>
				<p v-else class="followed-hashtags__hint">
					{{ t('social', 'You are not following any hashtag yet.') }}
				</p>
			</template>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '../services/toast.js'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import Pound from 'vue-material-design-icons/Pound.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import logger from '../services/logger.js'
import { useServerData } from '../composables/useServerData.js'
import { nextCursor } from '../utils/linkHeader.js'

/**
 * The server's page size for this list. Show more follows the cursor in the
 * `Link` header, which is the follow's own row.
 */
const PAGE_SIZE = 50

export default {
	name: 'HashtagFollowedList',
	components: {
		NcButton,
		Pound,
	},

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	data() {
		return {
			open: false,
			loading: false,
			loadingMore: false,
			/** @type {object[]} Tag entities */
			tags: [],
			/** where the next page starts, '' once the server said there is none */
			cursor: '',
		}
	},

	methods: {
		t,
		toggle() {
			this.open = !this.open
			if (this.open) {
				this.load()
			}
		},

		/** Re-reads the list, but only while somebody is looking at it. */
		refresh() {
			if (this.open) {
				this.load()
			}
		},

		async load() {
			if (this.loading) {
				return
			}

			this.loading = true
			try {
				const { data, headers } = await axios.get(
					generateUrl('apps/social/api/v1/followed_tags'),
					{ params: { limit: PAGE_SIZE } },
				)
				this.tags = Array.isArray(data) ? data : []
				this.cursor = nextCursor(headers)
			} catch (error) {
				logger.error('Failed to load the followed hashtags', { error })
				showError(t('social', 'Could not load the hashtags you follow'))
			} finally {
				this.loading = false
			}
		},

		async loadMore() {
			const cursor = this.cursor
			if (this.loading || this.loadingMore || !cursor) {
				return
			}

			this.loadingMore = true
			try {
				const { data, headers } = await axios.get(
					generateUrl('apps/social/api/v1/followed_tags'),
					{ params: { limit: PAGE_SIZE, max_id: cursor } },
				)
				// the list was read again from the top meanwhile
				if (this.cursor !== cursor) {
					return
				}
				const seen = new Set(this.tags.map((tag) => tag.name))
				const more = (Array.isArray(data) ? data : []).filter((tag) => !seen.has(tag.name))
				this.tags = [...this.tags, ...more]
				this.cursor = nextCursor(headers)
			} catch (error) {
				logger.error('Failed to load more of the followed hashtags', { error })
				showError(t('social', 'Could not load the hashtags you follow'))
			} finally {
				this.loadingMore = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.followed-hashtags {
	margin: 0 calc(var(--default-grid-baseline) * 2);
}

.followed-hashtags__list {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	margin: var(--default-grid-baseline) 0;

	a {
		display: inline-block;
		padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-hover);
		color: var(--color-main-text);
	}
}

.followed-hashtags__more {
	margin-bottom: var(--default-grid-baseline);
}

.followed-hashtags__hint {
	color: var(--color-text-lighter);
	margin: var(--default-grid-baseline) 0;
}
</style>
