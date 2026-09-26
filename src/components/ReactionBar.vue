<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="reactions.length || canReact" class="reaction-bar">
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
			<span class="reaction__emoji" aria-hidden="true">{{ reaction.name }}</span>
			<span class="reaction__count" aria-hidden="true">{{ reaction.count }}</span>
		</button>

		<!-- The picker is not here. `@nextcloud/vue` must not be imported
		     anywhere under TimelinePost — the app's entry and the dashboard's
		     both pull that in, and a framework import beneath it moves the
		     shared l10n chunk out of `social-framework` and copies half a
		     megabyte into each. So this asks for the picker over the bus and
		     keeps the request; see ReactionPicker. -->
		<button
			v-if="canReact"
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
 * The emoji reactions under a post.
 *
 * A like says "yes" and nothing else. A reaction says which kind of yes, which
 * on an internal feed is most of what people want to say about a colleague's
 * post — and it federates: Misskey, Pleroma, Akkoma, Iceshrimp and Sharkey all
 * send and understand `EmojiReact`.
 *
 * The bar is drawn from what the server sent with the post, and each press
 * answers with the post again, so the counts a reader sees are the ones the
 * next reader will be served rather than a local guess. The press is not
 * optimistic for that reason: a reaction is a federated write that can be
 * refused — an emoji the server will not take, a suspended account — and a
 * count that jumped and then jumped back is worse than one that waits a moment.
 *
 * Unicode emoji only. The picker offers nothing else, and the server refuses
 * anything else, for the reason ReactionService gives: a custom `:shortcode:`
 * means drawing a picture from somebody else's server inside the bar.
 */
export default {
	name: 'ReactionBar',

	components: {
		EmoticonPlusOutline,
	},

	props: {
		/**
		 * Which post, as the API's `id` — a **string**.
		 *
		 * Not `nid`. The two carry the same number, but the entity sends `nid`
		 * as a JSON number and a post id is a snowflake well past
		 * `Number.MAX_SAFE_INTEGER`: parsing one loses its last digits, so
		 * `…043682` arrives as `…043600` and the server answers 404 for a post
		 * that is on the screen. `id` is the same value as a string and
		 * survives the round trip, which is why every other action on a post
		 * addresses it that way too.
		 */
		statusId: {
			type: String,
			default: '',
		},

		/**
		 * The bar as the server sent it: `{name, count, me}` each.
		 *
		 * @type {import('vue').PropType<Array<{name: string, count: number, me: boolean}>>}
		 */
		modelValue: {
			type: Array,
			default: () => [],
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
		/** @return {Array<{name: string, count: number, me: boolean}>} */
		reactions() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},

	methods: {
		t,
		n,

		/**
		 * What a screen reader is told. The emoji itself is hidden from the
		 * accessibility tree: read aloud it is a name that may mean nothing
		 * here, and the useful part is how many people chose it and whether
		 * pressing this takes the reader's own back.
		 *
		 * @param {{name: string, count: number, me: boolean}} reaction one entry
		 * @return {string} the label
		 */
		labelFor(reaction) {
			const count = n('social', '%n reaction', '%n reactions', reaction.count)

			return reaction.me
				? t('social', 'Remove your {emoji} reaction ({count})', { emoji: reaction.name, count })
				: t('social', 'React with {emoji} ({count})', { emoji: reaction.name, count })
		},

		/**
		 * Asks for the shared picker, and hands it what to do with the answer.
		 *
		 * The callback keeps the request here rather than in the picker, so the
		 * two need know nothing about each other beyond the one event.
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

/* the ones the reader chose themselves, so pressing again to undo is an
   obvious thing to do rather than a discovery */
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
	/* an emoji is a picture: it must not pick up the italics or the weight of
	   whatever it is sitting in */
	font-style: normal;
	font-weight: normal;
}

.reaction__count {
	font-variant-numeric: tabular-nums;
}
</style>
