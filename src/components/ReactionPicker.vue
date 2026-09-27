<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- a dialog, not a bare overlay: Escape closes it, the backdrop is
	     labelled and reachable, and focus starts inside it -->
	<div
		v-if="open"
		ref="backdrop"
		class="reaction-picker"
		role="dialog"
		aria-modal="true"
		:aria-label="t('social', 'Choose a reaction')"
		tabindex="-1"
		@click.self="close"
		@keydown.esc.stop="close">
		<div class="reaction-picker__panel">
			<!-- NcEmojiPicker is a popover: it opens from its own trigger and
			     there is no prop to open it from here, so the trigger is
			     pressed for the reader as soon as the dialog appears. It is a
			     real, labelled button rather than a hidden anchor — if that
			     press ever fails to land, what is left is something to press
			     rather than an empty box. -->
			<!-- Keep the popper out of this flex panel: rendering it as a panel
			     child lets flex sizing squeeze the fixed-size picker into the
			     row, and the panel's stacking context can put it under the backdrop. -->
			<NcEmojiPicker :closeOnSelect="true" container="#content" @select="pick">
				<button ref="anchor" type="button" class="reaction-picker__anchor">
					{{ t('social', 'Choose an emoji') }}
				</button>
			</NcEmojiPicker>
			<NcButton
				variant="tertiary"
				:aria-label="t('social', 'Close')"
				:title="t('social', 'Close')"
				@click="close">
				<template #icon>
					<Close :size="20" />
				</template>
			</NcButton>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Close from 'vue-material-design-icons/Close.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmojiPicker from '@nextcloud/vue/components/NcEmojiPicker'
import eventBus, { REACTION_PICK } from '../services/eventBus.js'

/**
 * The emoji picker the reaction bars share.
 *
 * One for the page, mounted by `App` only once somebody asks for it, with
 * that first request as `firstAsk`; later ones arrive over the bus. The cards do not own it: `@nextcloud/vue` may not be imported anywhere
 * under `TimelinePost`, which the app's entry and the dashboard's both pull
 * in — a framework import beneath it moves the shared l10n chunk out of
 * `social-framework` and copies half a megabyte into each entry.
 *
 * So the card asks, over the bus, and hands a callback with it; this opens,
 * takes the answer, and gives it back. The request stays with the card.
 */
export default {
	name: 'ReactionPicker',

	components: {
		Close,
		NcButton,
		NcEmojiPicker,
	},

	props: {
		/** the request that had the picker mounted, answered as it arrives */
		firstAsk: {
			type: /** @type {import('vue').PropType<{react: (emoji: string) => void}>} */ (Object),
			default: null,
		},
	},

	data() {
		return {
			open: false,
			/** what to call with the chosen emoji, null when nothing is open */
			react: null,
		}
	},

	mounted() {
		eventBus.on(REACTION_PICK, this.onAsked)
		if (this.firstAsk !== null) {
			this.onAsked(this.firstAsk)
		}
	},

	beforeUnmount() {
		eventBus.off(REACTION_PICK, this.onAsked)
	},

	methods: {
		t,

		/**
		 * @param {{react: (emoji: string) => void}} payload who asked, and what to tell
		 */
		async onAsked(payload) {
			this.react = typeof payload?.react === 'function' ? payload.react : null
			this.open = this.react !== null

			if (this.open) {
				await this.$nextTick()
				// or Escape would go to whatever the reader last pressed,
				// which is a card behind the backdrop
				const backdrop = /** @type {HTMLElement|undefined} */ (this.$refs.backdrop)
				backdrop?.focus()
				const anchor = /** @type {HTMLElement|undefined} */ (this.$refs.anchor)
				anchor?.click()
			}
		},

		/**
		 * @param {object|string} chosen what the picker handed over — `select`
		 *        passes the emoji as a string; older versions passed an object
		 *        carrying it as `native`
		 */
		pick(chosen) {
			const emoji = typeof chosen === 'string' ? chosen : (chosen?.native ?? '')
			const react = this.react

			this.close()

			if (emoji !== '' && react !== null) {
				react(emoji)
			}
		},

		close() {
			this.open = false
			this.react = null
		},
	},
}
</script>

<style lang="scss" scoped>
/* a backdrop, so pressing away from the picker closes it */
.reaction-picker {
	position: fixed;
	inset: 0;
	z-index: 10000;
	display: flex;
	align-items: center;
	justify-content: center;
	/* the same wash a modal uses, so this sits on the page like one */
	background: var(--color-backdrop, rgba(0, 0, 0, .3));
}

.reaction-picker__panel {
	position: relative;
	display: flex;
	align-items: flex-start;
	gap: 4px;
	padding: 6px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
	box-shadow: var(--social-elevation-raised);
}

/* the trigger the picker positions against. Pressed for the reader on open,
   so it is normally seen for an instant; it stays a real button so that a
   press which does not land leaves something to press. */
.reaction-picker__anchor {
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
	cursor: pointer;
}
</style>
