<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="composer-preview" aria-live="polite">
		<div class="composer-preview__head">
			<p class="composer-preview__caption">
				{{ t('social', 'How this will read') }}
			</p>
			<!-- the way out of a panel belongs on the panel -->
			<NcButton
				variant="tertiary"
				class="composer-preview__close"
				:title="t('social', 'Hide the preview')"
				:aria-label="t('social', 'Hide the preview')"
				@click="$emit('close')">
				<template #icon>
					<Close :size="18" />
				</template>
			</NcButton>
		</div>
		<div v-if="warning" class="composer-preview__warning">
			{{ warning }}
		</div>
		<p v-if="runs.length === 0" class="composer-preview__empty">
			{{ t('social', 'Nothing written yet.') }}
		</p>
		<p v-else class="composer-preview__body">
			<!-- drawn as runs, never parsed as markup. Each run's text sits against its
			     tags: the body is `pre-wrap`, so a newline here would be a space in
			     the preview that is not in the post. -->
			<span
				v-for="(run, index) in runs"
				:key="index"
				:class="run.type === 'text' ? null : ['composer-preview__entity', `composer-preview__entity--${run.type}`]"
				:style="run.type === 'hashtag' ? tagStyle(run.name) : null">{{ run.text }}</span>
		</p>
		<p class="composer-preview__note">
			{{ summary }}
		</p>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import Close from 'vue-material-design-icons/Close.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { linkifyRuns } from '../../utils/linkify.js'
import { tagStyle } from '../../utils/tagColour.js'

/**
 * What the post will look like once the server has linked its URLs, mentions
 * and hashtags (LinkifyService). The entities are found by utils/linkify.js,
 * which mirrors the server's rules; whether a mention resolves to a real
 * account is not known here, and the wording under the preview says so.
 */
export default {
	name: 'ComposerPreview',

	components: {
		Close,
		NcButton,
	},

	props: {
		/** the text as it has been typed */
		text: {
			type: String,
			default: '',
		},

		/** the content warning, when the post carries one */
		warning: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	computed: {
		/**
		 * @return {Array<{type: string, text: string, name?: string}>} the text
		 *         broken into plain stretches and the things that become links
		 */
		runs() {
			return linkifyRuns(this.text)
		},

		/**
		 * @return {{mentions: number, hashtags: number, links: number}} how many
		 *         of each the post carries
		 */
		counts() {
			const counts = { mentions: 0, hashtags: 0, links: 0 }

			for (const run of this.runs) {
				if (run.type === 'mention') {
					counts.mentions++
				} else if (run.type === 'hashtag') {
					counts.hashtags++
				} else if (run.type === 'url') {
					counts.links++
				}
			}

			return counts
		},

		/**
		 * What was found, in words. A count of nothing is worth saying too: "no
		 * hashtags" explains why a post did not reach a tag timeline.
		 *
		 * @return {string} the summary under the preview
		 */
		summary() {
			const { mentions, hashtags, links } = this.counts

			if (mentions === 0 && hashtags === 0 && links === 0) {
				return t('social', 'No mentions, hashtags or links yet.')
			}

			const parts = []
			if (mentions > 0) {
				parts.push(n('social', '%n mention', '%n mentions', mentions))
			}
			if (hashtags > 0) {
				parts.push(n('social', '%n hashtag', '%n hashtags', hashtags))
			}
			if (links > 0) {
				parts.push(n('social', '%n link', '%n links', links))
			}

			return t('social', 'Will be published with {entities}.', { entities: parts.join(', ') })
		},
	},

	methods: {
		t,
		n,
		tagStyle,
	},
}
</script>

<style lang="scss" scoped>
.composer-preview {
	margin: 6px 0;
	padding: 10px 12px;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius, 8px);
	background: var(--color-background-hover);
}

.composer-preview__head {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 6px;
}

.composer-preview__caption {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	text-transform: uppercase;
	letter-spacing: .04em;
}

.composer-preview__close {
	margin-inline-start: auto;
}

/* drawn the way a warning is drawn on a card, so the preview of a post with
   one looks like the card it is going to become */
.composer-preview__warning {
	margin-bottom: 6px;
	padding: 4px 8px;
	border-radius: var(--border-radius, 8px);
	background: var(--color-background-dark);
	color: var(--color-main-text);
	font-weight: 600;
	font-size: 13px;
}

.composer-preview__body {
	margin: 0;
	color: var(--color-main-text);
	font-size: 14px;
	line-height: 1.5;
	/* a post keeps the line breaks that were typed, and a long URL must not
	   push the composer wider than its column */
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.composer-preview__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-style: italic;
}

.composer-preview__entity {
	color: var(--color-primary-element);
	font-weight: 600;
}

/* a hashtag is shown in the colour it will have wherever it is met */
.composer-preview__entity--hashtag {
	color: var(--tag-colour, var(--color-primary-element));
}

.composer-preview__entity--url {
	text-decoration: underline;
}

.composer-preview__note {
	margin: 6px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

@media (prefers-color-scheme: dark) {
	.composer-preview__entity--hashtag {
		color: var(--tag-colour-dark, var(--color-primary-element));
	}
}

[data-themes*='dark'] .composer-preview__entity--hashtag {
	color: var(--tag-colour-dark, var(--color-primary-element));
}
</style>
