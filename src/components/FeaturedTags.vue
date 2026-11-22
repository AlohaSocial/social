<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<nav v-if="tags.length || editable" class="featured-tags" :aria-label="t('social', 'Hashtags this account features')">
		<ul class="featured-tags__list">
			<li v-for="tag in tags" :key="tag.name">
				<router-link
					class="featured-tags__tag"
					:style="tagStyle(tag.name)"
					:to="{ name: 'tags', params: { tag: tag.name } }">
					<span class="featured-tags__name">#{{ tag.name }}</span>
					<span v-if="tag.statuses_count > 0" class="featured-tags__count">{{ tag.statuses_count }}</span>
				</router-link>
			</li>
			<!-- only on the reader's own profile, where the hashtags are edited -->
			<li v-if="editable">
				<button type="button" class="featured-tags__edit" @click="editing = true">
					{{ tags.length ? t('social', 'Edit featured hashtags') : t('social', 'Feature a hashtag') }}
				</button>
			</li>
		</ul>
		<SettingsDialog
			v-if="editing"
			:title="t('social', 'Featured hashtags')"
			:description="t('social', 'Hashtags shown under your bio. Anyone can click one to read what you posted with it.')"
			@close="doneEditing">
			<FeaturedTagsSettings />
		</SettingsDialog>
	</nav>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { defineAsyncComponent } from 'vue'
import logger from '../services/logger.js'
import SettingsDialog from './SettingsDialog.vue'
import { tagStyle } from '../utils/tagColour.js'

/**
 * The hashtags an account pins to its own profile, shown next to the bio. Each
 * tag wears its colour from utils/tagColour.js. On the reader's own profile it
 * also opens the editor, even when there are no tags yet.
 */
export default {
	name: 'FeaturedTags',

	components: {
		FeaturedTagsSettings: defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'./FeaturedTagsSettings.vue')),
		SettingsDialog,
	},

	props: {
		/** whose profile this is, as the API addresses accounts */
		accountId: {
			type: String,
			default: '',
		},

		/** whether this is the reader's own profile, and so theirs to change */
		editable: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			tags: [],
			editing: false,
		}
	},

	watch: {
		accountId: {
			handler: 'load',
			immediate: true,
		},
	},

	methods: {
		t,
		tagStyle,

		doneEditing() {
			this.editing = false
			this.load()
		},

		/** Reads the account's featured tags, and shows nothing if it cannot. */
		async load() {
			this.tags = []

			if (this.accountId === '') {
				return
			}

			try {
				const { data } = await axios.get(generateUrl(`/apps/social/api/v1/accounts/${encodeURIComponent(this.accountId)}/featured_tags`))

				this.tags = Array.isArray(data) ? data.filter((tag) => typeof tag?.name === 'string' && tag.name !== '') : []
			} catch (error) {
				// a profile is readable without these; a failed extra is not
				// worth an error message on somebody's page
				logger.debug('Could not read the featured tags', { error })
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.featured-tags__list {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 6px 0;
	padding: 0;
	list-style: none;
}

.featured-tags__tag {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	padding: 1px 9px;
	border: 1px solid var(--tag-colour, var(--color-border));
	border-radius: var(--border-radius-pill, 16px);
	color: var(--tag-colour, var(--color-main-text));
	font-size: 13px;
	text-decoration: none;

	&:hover,
	&:focus-visible {
		background: var(--color-background-hover);
	}
}

/* not a chip, so it does not read as one of the featured tags */
/* an outlined chip beside the tags, so it reads as something to press and
   not as a caption */
.featured-tags__edit {
	margin: 0;
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--border-radius-pill, 100px);
	background: none;
	cursor: pointer;
	display: inline-flex;
	align-items: center;
	min-height: 0;
	padding: 2px 12px;
	color: var(--color-main-text);
	font-size: 13px;

	&:hover,
	&:focus-visible {
		border-style: solid;
		background: var(--color-background-hover);
	}
}

.featured-tags__count {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-variant-numeric: tabular-nums;
}

@media (prefers-color-scheme: dark) {
	.featured-tags__tag {
		border-color: var(--tag-colour-dark, var(--color-border));
		color: var(--tag-colour-dark, var(--color-main-text));
	}
}

[data-themes*='dark'] .featured-tags__tag {
	border-color: var(--tag-colour-dark, var(--color-border));
	color: var(--tag-colour-dark, var(--color-main-text));
}
</style>
