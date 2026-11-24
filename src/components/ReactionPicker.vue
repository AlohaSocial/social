<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- a dialog: Escape closes it and focus starts inside it -->
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
			<!-- NcEmojiPicker has no prop to open it, so its trigger is pressed
			     for the reader when the dialog appears; it is a real button in
			     case that press does not land. The popper is rendered outside
			     this flex panel, which would squeeze it or stack it under the
			     backdrop. -->
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
 * The emoji picker the reaction bars share, one per page, mounted by `App` on
 * the first request (`firstAsk`); later requests arrive over the bus with a
 * callback. Cards cannot own it: `@nextcloud/vue` must not be imported under
 * `TimelinePost`, which the dashboard bundle also pulls in.
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
				// or Escape would go to a card behind the backdrop
				const backdrop = /** @type {HTMLElement|undefined} */ (this.$refs.backdrop)
				backdrop?.focus()
				const anchor = /** @type {HTMLElement|undefined} */ (this.$refs.anchor)
				anchor?.click()
			}
		},

		/**
		 * @param {object|string} chosen the emoji as a string, or an object
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

/* the trigger the picker positions against, pressed for the reader on open */
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
