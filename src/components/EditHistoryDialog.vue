<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('social', 'Edit history')"
		:open="true"
		size="normal"
		@update:open="$emit('close')">
		<NcLoadingIcon v-if="loading" :size="32" class="history__loading" />

		<div v-else-if="loadError" class="history__none" role="alert">
			<p>{{ t('social', 'Could not load this post’s edit history.') }}</p>
			<NcButton variant="secondary" @click="load">
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<p v-else-if="versions.length === 0 && editedAt" class="history__none">
			{{ t('social', 'This post is marked as edited, but its revision history is unavailable.') }}
		</p>

		<p v-else-if="versions.length === 0" class="history__none">
			{{ t('social', 'This post has not been edited.') }}
		</p>

		<ol v-else class="history__list">
			<li v-for="(version, index) in versions" :key="version.created_at + index" class="history__version">
				<p class="history__when">
					<strong>{{ index === 0 ? t('social', 'As first posted') : t('social', 'Edited') }}</strong>
					<time :datetime="version.created_at">{{ when(version.created_at) }}</time>
				</p>

				<p v-if="version.spoiler_text" class="history__warning">
					{{ version.spoiler_text }}
				</p>

				<!-- Sanitized: a revision is remote HTML, see sanitizeHtml.js -->
				<!-- eslint-disable-next-line vue/no-v-html -->
				<div class="history__content" v-html="contentOf(version)" />

				<ul v-if="(version.media_attachments || []).length" class="history__media">
					<li v-for="media in version.media_attachments" :key="media.id">
						{{ media.description || t('social', 'A picture with no description') }}
					</li>
				</ul>
			</li>
		</ol>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcButton from '@nextcloud/vue/components/NcButton'
import logger from '../services/logger.js'
import { fullDateTime } from '../utils/relativeTime.js'
import { sanitizeHtml } from '../utils/sanitizeHtml.js'

/**
 * Every version of an edited post, oldest first.
 *
 * The API has served this since editing landed and the client showed only
 * "Edited {date}" — which tells a reader that the words they are looking at are
 * not the words somebody replied to, and nothing about what changed. Mastodon
 * shows the versions; a reader who has been quoted or answered wants to see
 * them.
 *
 * The content is rendered as HTML because that is what it is: each version is
 * the post as it stood, and rendering it as text would show markup to the
 * reader. It goes through the client sanitiser first, the way every other
 * remote-HTML sink in this app does — a revision of a remote post is that
 * server's markup.
 */
export default {
	name: 'EditHistoryDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
	},

	props: {
		/** the post, by the id its own routes use */
		nid: {
			type: [Number, String],
			required: true,
		},

		/** present when the status says an edit happened, even if revisions are absent */
		editedAt: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			versions: [],
			loading: true,
			loadError: false,
			loadRequest: 0,
		}
	},

	watch: {
		nid() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * @param {object} version one revision of the post
		 * @return {string} its content, reduced to what may be rendered
		 */
		contentOf(version) {
			return sanitizeHtml(version.content ?? '')
		},

		/**
		 * @param {string} iso when a version was written
		 * @return {string}
		 */
		when(iso) {
			return fullDateTime(iso)
		},

		/** @return {Promise<void>} */
		async load() {
			const request = ++this.loadRequest
			this.loading = true
			this.loadError = false
			this.versions = []
			try {
				const url = generateUrl('apps/social/api/v1/statuses/{nid}/history', { nid: this.nid })
				const { data } = await axios.get(url)
				const versions = data?.result ?? data
				if (!Array.isArray(versions)) {
					throw new TypeError('The edit history response is not a list')
				}
				if (request === this.loadRequest) {
					this.versions = versions
				}
			} catch (error) {
				if (request === this.loadRequest) {
					logger.error('could not load the edit history', { error })
					this.loadError = true
				}
			} finally {
				if (request === this.loadRequest) {
					this.loading = false
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
.history__loading {
	margin-block: 24px;
}

.history__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.history__version {
	border-inline-start: 2px solid var(--color-border);
	padding-inline-start: 12px;
}

.history__when {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin: 0 0 4px;
	color: var(--color-text-maxcontrast);
}

.history__warning {
	font-weight: bold;
	margin: 0 0 4px;
}

.history__content {
	overflow-wrap: anywhere;

	:deep(p) {
		margin: 0 0 8px;
	}
}

.history__media {
	color: var(--color-text-maxcontrast);
	margin-block-start: 4px;
}
</style>
