<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="reactions.length || (canReact && offerAdd)" class="reaction-bar">
		<button
			v-for="reaction in reactions"
			:key="reaction.name"
			type="button"
			class="reaction"
			:class="{ 'reaction--mine': reaction.me, 'reaction--busy': busy === reaction.name }"
			:disabled="!canReact || busy !== ''"
			:aria-pressed="reaction.me ? 'true' : 'false'"
			:aria-label="labelFor(reaction)"
			@click.stop="toggle(reaction.name)">
			<img
				v-if="reaction.url"
				class="reaction__emoji-image"
				:src="reaction.url"
				alt=""
				loading="lazy"
				decoding="async"
				aria-hidden="true">
			<span v-else class="reaction__emoji" aria-hidden="true">{{ reaction.name }}</span>
			<span class="reaction__count" aria-hidden="true">{{ reaction.count }}</span>
		</button>

		<!-- The picker is asked for over the bus (see ReactionPicker): importing
		     `@nextcloud/vue` anywhere under TimelinePost would copy half a
		     megabyte of shared chunks into the app and dashboard bundles. -->
		<button
			v-if="canReact && offerAdd"
			type="button"
			class="reaction reaction--add"
			:disabled="busy !== ''"
			:aria-haspopup="true"
			:aria-label="t('social', 'Add a reaction')"
			:title="t('social', 'Add a reaction')"
			@click.stop="askForPicker">
			<EmoticonPlusOutline :size="16" />
		</button>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import EmoticonPlusOutline from 'vue-material-design-icons/EmoticonPlusOutline.vue'
import eventBus, { REACTION_PICK } from '../services/eventBus.js'
import logger from '../services/logger.js'
import { feel } from '../services/senses.js'
import { showError } from '../services/toast.js'

/**
 * The emoji reactions under a post, federated as `EmojiReact`.
 *
 * Not optimistic: a reaction can be refused, so the bar shows the counts from
 * the server's answer. Remote `:shortcode:` images are never fetched or drawn.
 */
export default {
	name: 'ReactionBar',

	components: {
		EmoticonPlusOutline,
	},

	props: {
		/**
		 * The post's API `id`, a string: `nid` is a JSON number and loses digits
		 * past `Number.MAX_SAFE_INTEGER`.
		 */
		statusId: {
			type: String,
			default: '',
		},

		/**
		 * The bar as the server sent it: `{name, count, me}`, with a local
		 * emoji image URL when this instance owns that custom emoji.
		 */
		modelValue: {
			type: /** @type {import('vue').PropType<Array<{name: string, count: number, me: boolean, url?: string}>>} */ (Array),
			default: () => [],
		},

		/**
		 * Whether the bar offers its own button for a new reaction. A post
		 * does not: its heart offers the reactions on a hold, and the menu does.
		 */
		offerAdd: {
			type: Boolean,
			default: true,
		},

		/** whether this reader may react at all; false on the public page */
		canReact: {
			type: Boolean,
			default: true,
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			/** which emoji is in flight, '' when none */
			busy: '',
		}
	},

	computed: {
		/** @return {Array<{name: string, count: number, me: boolean, url?: string}>} */
		reactions() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},

	methods: {
		t,
		n,

		/**
		 * What a screen reader is told: how many chose it and whether pressing
		 * takes the reader's own back. The emoji itself is hidden.
		 *
		 * @param {{name: string, count: number, me: boolean, url?: string}} reaction one entry
		 * @return {string} the label
		 */
		labelFor(reaction) {
			const count = n('social', '%n reaction', '%n reactions', reaction.count)

			return reaction.me
				? t('social', 'Remove your {emoji} reaction ({count})', { emoji: reaction.name, count })
				: t('social', 'React with {emoji} ({count})', { emoji: reaction.name, count })
		},

		/**
		 * Asks for the shared picker, handing it the callback for the answer.
		 */
		askForPicker() {
			if (!this.canReact || this.busy !== '') {
				return
			}

			eventBus.emit(REACTION_PICK, { react: (emoji) => this.send(emoji, true) })
		},

		/**
		 * @param {string} emoji the one that was pressed
		 */
		toggle(emoji) {
			const existing = this.reactions.find((reaction) => reaction.name === emoji)
			this.send(emoji, !existing?.me)
		},

		/**
		 * @param {string} emoji what to react with
		 * @param {boolean} add whether to add it or take it back
		 */
		async send(emoji, add) {
			if (!this.canReact || this.busy !== '' || this.statusId === '') {
				return
			}

			this.busy = emoji
			try {
				const { data } = await axios.post(
					generateUrl(`/apps/social/api/v1/statuses/${this.statusId}/${add ? 'react' : 'unreact'}`),
					{ emoji },
				)

				this.$emit('update:modelValue', Array.isArray(data?.reactions) ? data.reactions : [])
				if (add) {
					feel('react')
				}
			} catch (error) {
				logger.error('Could not change the reaction', { error })
				showError(add
					? t('social', 'Could not add that reaction')
					: t('social', 'Could not remove that reaction'))
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.reaction-bar {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-top: 6px;
}

.reaction {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	min-height: 24px;
	padding: 1px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 13px;
	line-height: 20px;
	cursor: pointer;

	&:hover:not(:disabled),
	&:focus-visible {
		border-color: var(--color-primary-element);
	}

	&:disabled {
		cursor: default;
	}
}

/* the reader's own, so pressing again to undo is obvious */
.reaction--mine {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.reaction--busy {
	opacity: .6;
}

.reaction--add {
	padding-inline: 6px;
	color: var(--color-text-maxcontrast);
}

.reaction__emoji {
	font-size: 14px;
	/* an emoji must not inherit the italics or weight around it */
	font-style: normal;
	font-weight: normal;
}

.reaction__emoji-image {
	width: 16px;
	height: 16px;
	object-fit: contain;
}

.reaction__count {
	font-variant-numeric: tabular-nums;
}
</style>
