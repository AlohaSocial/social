<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="featured-tags-settings">
		<p v-if="loading" class="featured-tags-settings__hint">
			{{ t('social', 'Loading …') }}
		</p>

		<template v-else>
			<ul v-if="featured.length > 0" class="featured-tags-settings__list">
				<li v-for="tag in featured" :key="tag.id" class="featured-tags-settings__item">
					<router-link
						class="featured-tags-settings__tag"
						:style="tagStyle(tag.name)"
						:to="{ name: 'tags', params: { tag: tag.name } }">
						{{ '#' + tag.name }}
					</router-link>
					<span class="featured-tags-settings__count">{{ postsLabel(tag) }}</span>
					<NcButton
						variant="tertiary"
						:disabled="busy"
						:aria-label="t('social', 'Stop featuring #{tag}', { tag: tag.name })"
						@click="unfeature(tag)">
						<template #icon>
							<IconClose :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>
			<p v-else class="featured-tags-settings__hint">
				{{ t('social', 'You feature no hashtags yet. The ones you pick sit at the top of your profile, under your bio.') }}
			</p>

			<!-- the tags this account posts with most and has not featured -->
			<div v-if="suggestions.length > 0" class="featured-tags-settings__suggestions">
				<h4 class="featured-tags-settings__subheading">
					{{ t('social', 'Hashtags you post with') }}
				</h4>
				<ul class="featured-tags-settings__chips">
					<li v-for="tag in suggestions" :key="tag.name">
						<button
							type="button"
							class="featured-tags-settings__chip"
							:style="tagStyle(tag.name)"
							:disabled="busy"
							@click="feature(tag.name)">
							<IconPlus :size="16" />
							<span>{{ '#' + tag.name }}</span>
						</button>
					</li>
				</ul>
			</div>

			<form class="featured-tags-settings__add" @submit.prevent="addTyped">
				<NcTextField
					v-model="newTag"
					class="featured-tags-settings__field"
					:label="t('social', 'Another hashtag')"
					:placeholder="t('social', 'travel')"
					:error="typedIsNotATag"
					:helperText="typedIsNotATag ? t('social', 'A hashtag is letters, numbers and underscores — nothing else.') : ''"
					:maxlength="maxLength"
					:showTrailingButton="false" />
				<NcButton type="submit" variant="primary" :disabled="!canSubmit">
					<template #icon>
						<NcLoadingIcon v-if="busy" :size="20" />
						<IconPlus v-else :size="20" />
					</template>
					{{ t('social', 'Feature') }}
				</NcButton>
			</form>

			<!-- only the server knows how many tags are too many; its reason stays on
			     the page, since the way out is to remove one of the rows above -->
			<NcNoteCard v-if="refusal" type="warning">
				<ServerError :error="refusal" :alert="false" />
			</NcNoteCard>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { errorOf } from '../utils/serverError.js'
import ServerError from './ServerError.vue'
import { tagStyle } from '../utils/tagColour.js'

/** The width of `social_featured_tag.hashtag`; `FeaturedTagsRequest::MAX_HASHTAG_LENGTH`. */
const MAX_HASHTAG_LENGTH = 127

/**
 * A hashtag as the server stores one, for deciding whether the box holds one.
 *
 * The same rule as `FeaturedTagsRequest::normaliseHashtag()`. Only used to
 * enable the button and say why it is off: what was typed is sent as typed.
 *
 * @param {string} value what was typed
 * @return {string} the tag, or '' for something that is not one
 */
function asHashtag(value) {
	const tag = String(value ?? '').trim().replace(/^#/, '').toLowerCase()

	return /^[\p{L}\p{N}_]+$/u.test(tag) && tag.length <= MAX_HASHTAG_LENGTH ? tag : ''
}

/**
 * The hashtags the reader pins to their own profile: pick some, drop some.
 * The profile links here, since the suggestions need room the header lacks.
 */
export default {
	name: 'FeaturedTagsSettings',

	components: {
		IconClose,
		IconPlus,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		ServerError,
	},

	data() {
		return {
			/** @type {object[]} the featured tags, as the API sends them */
			featured: [],
			/** @type {object[]} Tag entities the reader posts with and has not featured */
			suggestions: [],
			loading: true,
			/** whether a request that changes the list is in flight */
			busy: false,
			newTag: '',
			/** @type {{text: string, details: string}|null} why the server last refused a tag, kept in view until one lands */
			refusal: null,
		}
	},

	computed: {
		/**
		 * @return {number} the longest thing worth typing: the column's width
		 *         plus the `#` people put in front of a hashtag, which the
		 *         server strips before measuring
		 */
		maxLength() {
			return MAX_HASHTAG_LENGTH + 1
		},

		/** @return {boolean} whether the box holds something that is not a hashtag */
		typedIsNotATag() {
			return this.newTag.trim() !== '' && asHashtag(this.newTag) === ''
		},

		/** @return {boolean} */
		canSubmit() {
			return !this.busy && asHashtag(this.newTag) !== ''
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,
		tagStyle,

		/** @param {object} tag a featured tag */
		postsLabel(tag) {
			const count = Number(tag.statuses_count ?? 0)

			return count > 0
				// the counts are over public and unlisted posts, which is what
				// a visitor clicking the tag would be shown
				? this.n('social', '%n public post', '%n public posts', count)
				: t('social', 'Nothing posted with it yet')
		},

		async load() {
			this.loading = true
			await Promise.all([this.fetchFeatured(), this.fetchSuggestions()])
			this.loading = false
		},

		async fetchFeatured() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/featured_tags'))
				this.featured = Array.isArray(data) ? data : []
			} catch (error) {
				logger.error('Failed to load the featured hashtags', { error })
				showError(t('social', 'Could not load the hashtags you feature'))
			}
		},

		/** A failure here costs nothing but the shortcut, so it says nothing. */
		async fetchSuggestions() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/featured_tags/suggestions'))
				this.suggestions = Array.isArray(data)
					? data.filter((tag) => typeof tag?.name === 'string' && tag.name !== '')
					: []
			} catch (error) {
				logger.debug('Could not load the hashtag suggestions', { error })
				this.suggestions = []
			}
		},

		/** The form: features whatever is in the box. */
		addTyped() {
			if (!this.canSubmit) {
				return
			}

			this.feature(this.newTag)
		},

		/**
		 * @param {string} name a suggestion, or whatever was typed; the server
		 *                      normalises it either way
		 */
		async feature(name) {
			if (this.busy) {
				return
			}
			this.busy = true
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/featured_tags'), { name })
				// featuring one that is already featured answers the existing entity, so
				// this replaces rather than appends
				this.featured = [...this.featured.filter((tag) => tag.id !== data.id), data]
				this.suggestions = this.suggestions.filter((tag) => tag.name !== data.name)
				// only when that is what was sent: clicking a suggestion should not take
				// half-typed text away
				if (name === this.newTag) {
					this.newTag = ''
				}
				this.refusal = null
				showSuccess(t('social', '#{tag} is now featured on your profile', { tag: data.name }))
			} catch (error) {
				logger.error('Failed to feature the hashtag', { error })
				const reason = errorOf(error, t('social', 'Could not feature that hashtag'))
				this.refusal = reason
				showError(reason)
			} finally {
				this.busy = false
			}
		},

		/** @param {object} tag the featured tag to drop */
		async unfeature(tag) {
			if (this.busy) {
				return
			}
			this.busy = true
			try {
				await axios.delete(generateUrl(`apps/social/api/v1/featured_tags/${tag.id}`))
				this.featured = this.featured.filter((entry) => entry.id !== tag.id)
				this.refusal = null
				showSuccess(t('social', '#{tag} is no longer featured', { tag: tag.name }))
				// it can be suggested again now, and the server decides whether
				// it is worth suggesting
				await this.fetchSuggestions()
			} catch (error) {
				logger.error('Failed to stop featuring the hashtag', { error })
				showError(errorOf(error, t('social', 'Could not stop featuring #{tag}', { tag: tag.name })))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.featured-tags-settings {
	&__hint {
		color: var(--color-text-maxcontrast);
		margin: 4px 0;
	}

	&__subheading {
		margin: 0 0 6px;
		font-size: 14px;
	}

	&__list {
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__item {
		display: flex;
		align-items: center;
		gap: 8px;
		min-height: 44px;
		border-top: 1px solid var(--color-border);
		padding: 4px 0;
	}

	&__count {
		flex: 1 1 auto;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	&__suggestions {
		margin-top: 16px;
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__tag,
	&__chip {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 2px 10px;
		border: 1px solid var(--tag-colour, var(--color-border));
		border-radius: var(--border-radius-pill, 16px);
		background: transparent;
		color: var(--tag-colour, var(--color-main-text));
		font-size: 13px;
		text-decoration: none;
	}

	&__chip {
		cursor: pointer;

		&:disabled {
			cursor: default;
			opacity: 0.5;
		}

		&:hover:not(:disabled),
		&:focus-visible {
			background: var(--color-background-hover);
		}
	}

	&__tag:hover,
	&__tag:focus-visible {
		background: var(--color-background-hover);
	}

	&__add {
		display: flex;
		align-items: flex-end;
		gap: 8px;
		flex-wrap: wrap;
		margin-top: 16px;
	}

	&__field {
		flex: 1 1 240px;
		max-width: 420px;
	}
}

@media (prefers-color-scheme: dark) {
	.featured-tags-settings__tag,
	.featured-tags-settings__chip {
		border-color: var(--tag-colour-dark, var(--color-border));
		color: var(--tag-colour-dark, var(--color-main-text));
	}
}

[data-themes*='dark'] .featured-tags-settings__tag,
[data-themes*='dark'] .featured-tags-settings__chip {
	border-color: var(--tag-colour-dark, var(--color-border));
	color: var(--tag-colour-dark, var(--color-main-text));
}
</style>
