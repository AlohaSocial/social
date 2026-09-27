<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!--
		The things a post's own page can say that a card in a timeline should
		not: when exactly, to whom, in what language, whether it has been
		changed since, and where it came from. Quiet on purpose — this is the
		fine print under something somebody came to read, not part of it.
	-->
	<p class="post-details">
		<time class="post-details__item" :datetime="status.created_at">{{ published }}</time>
		<span class="post-details__item">{{ visibilityText }}</span>
		<span v-if="languageName" class="post-details__item">{{ languageName }}</span>
		<!-- "Edited" told a reader the words are not the words somebody replied
		     to, and nothing about what changed. The versions have been served
		     since editing landed; this is the button that opens them. -->
		<button
			v-if="edited"
			type="button"
			class="post-details__item post-details__edited"
			@click="showHistory = true">
			{{ edited }}
		</button>

		<EditHistoryDialog
			v-if="showHistory"
			:nid="status.nid || status.id"
			:editedAt="status.edited_at || ''"
			@close="showHistory = false" />
		<a
			v-if="originalUrl"
			class="post-details__item post-details__original"
			:href="originalUrl"
			target="_blank"
			rel="noopener noreferrer">
			{{ t('social', 'View original') }}
			<OpenInNew :size="14" />
		</a>
	</p>
</template>

<script>
import { getLanguage, translate as t } from '@nextcloud/l10n'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import visibilities from './Visibility/VisibilitiesInfos.js'
import { fullDateTime } from '../utils/relativeTime.js'
import EditHistoryDialog from './EditHistoryDialog.vue'

export default {
	name: 'PostDetails',
	components: {
		EditHistoryDialog,
		OpenInNew,
	},

	props: {
		status: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */ (Object),
			required: true,
		},
	},

	data() {
		return {
			showHistory: false,
		}
	},

	computed: {
		/** @return {string} when it was posted, in full rather than "14 hours ago" */
		published() {
			return fullDateTime(this.status.created_at)
		},

		/** @return {string} who it went to, in the words the composer offers */
		visibilityText() {
			const known = visibilities.find((visibility) => visibility.id === this.status.visibility)

			return known ? known.text : t('social', 'Unknown audience')
		},

		/**
		 * The language the author declared, named rather than coded: `de` is
		 * not something a reader should have to know. A code no browser knows
		 * is shown as it stands, which is better than dropping it.
		 *
		 * @return {string}
		 */
		languageName() {
			const code = this.status.language
			if (!code) {
				return ''
			}

			try {
				return new Intl.DisplayNames([getLanguage()], { type: 'language' }).of(code) ?? code
			} catch {
				return code
			}
		},

		/** @return {string} when it was last changed, if it ever was */
		edited() {
			return this.status.edited_at
				? t('social', 'Edited {date}', { date: fullDateTime(this.status.edited_at) })
				: ''
		},

		/**
		 * Where the post lives, for a post that lives somewhere else. A local
		 * post is already at its own address — this page — so a link to it
		 * would go round in a circle.
		 *
		 * @return {string}
		 */
		originalUrl() {
			if (this.status.local === true) {
				return ''
			}

			const url = this.status.url || this.status.uri || ''

			return /^https?:\/\//.test(url) ? url : ''
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.post-details {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 10px;
	margin: 8px 0 0;
	padding: 0 4px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

/* a middle dot between items, drawn by the gap rather than typed into the
   markup, so no separator is left hanging when an item is not there */
.post-details__item + .post-details__item::before {
	content: '·';
	margin-inline-end: 10px;
}

.post-details__original {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	color: var(--color-text-maxcontrast);
	text-decoration: underline;

	&:hover,
	&:focus-visible {
		color: var(--color-main-text);
	}
}

.post-details__edited {
	border: none;
	background: none;
	padding: 0;
	font: inherit;
	color: inherit;
	cursor: pointer;
	text-decoration: underline dotted;

	&:hover,
	&:focus-visible {
		text-decoration: underline;
	}
}

</style>
