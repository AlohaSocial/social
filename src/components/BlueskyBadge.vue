<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span class="bluesky-badge" :class="{ 'bluesky-badge--verified': verified }" :title="label">
		<svg
			class="bluesky-badge__butterfly"
			viewBox="0 0 24 24"
			role="img"
			:aria-label="label">
			<path fill="currentColor" d="M5.9 3.6c2.5 1.9 5.2 5.7 6.1 7.7.9-2 3.6-5.8 6.1-7.7 1.8-1.4 4.7-2.4 4.7.9 0 .7-.4 5.6-.6 6.4-.8 2.8-3.6 3.5-6.1 3.1 4.4.7 5.5 3.2 3.1 5.7-4.6 4.7-6.6-1.2-7.1-2.7l-.1-.4-.1.4c-.5 1.5-2.5 7.4-7.1 2.7-2.4-2.5-1.3-5 3.1-5.7-2.5.4-5.3-.3-6.1-3.1-.2-.8-.6-5.7-.6-6.4 0-3.3 2.9-2.3 4.7-.9Z" />
		</svg>
		<!-- the check Bluesky draws beside an account a trusted verifier
		     vouched for, as the AppView judged it; the words are in the label -->
		<svg
			v-if="verified"
			class="bluesky-badge__check"
			viewBox="0 0 24 24"
			aria-hidden="true">
			<circle
				cx="12"
				cy="12"
				r="12"
				fill="currentColor" />
			<path fill="var(--color-main-background)" d="M10 16.2 6.4 12.6l1.4-1.4 2.2 2.2 6.2-6.2 1.4 1.4z" />
		</svg>
	</span>
</template>

<script>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'

/**
 * The butterfly beside the name of an account that lives on Bluesky.
 *
 * Sized to the text it sits in and never wider than that, so a byline with
 * it is exactly as tall as one without. The meaning is in the label: the
 * glyph alone says nothing to a screen reader.
 */
export default {
	name: 'BlueskyBadge',

	props: {
		/**
		 * The account, as a client entity: its `bluesky` block says whether
		 * Bluesky shows it as verified, and by whom.
		 */
		account: {
			type: Object,
			default: null,
		},
	},

	computed: {
		/** @return {boolean} */
		verified() {
			return this.account?.bluesky?.verified === true
		},

		/** @return {string} */
		label() {
			if (!this.verified) {
				return t('social', 'On Bluesky')
			}
			if (this.account.bluesky.verified_by_bluesky) {
				return t('social', 'On Bluesky, verified by Bluesky')
			}
			const count = (this.account.bluesky.verified_by ?? []).length

			return n('social', 'On Bluesky, verified by %n trusted verifier', 'On Bluesky, verified by %n trusted verifiers', Math.max(1, count))
		},
	},
}
</script>

<style lang="scss" scoped>
.bluesky-badge {
	display: inline-flex;
	align-items: center;
	vertical-align: -0.125em;
	margin-inline-start: 0.25em;
	color: var(--color-text-maxcontrast);
	line-height: 1;
}

.bluesky-badge__butterfly {
	flex: 0 0 auto;
	width: 1em;
	height: 1em;
}

.bluesky-badge--verified {
	// Bluesky's own blue, readable on both themes
	color: #1083fe;
}

.bluesky-badge__check {
	flex: 0 0 auto;
	width: 0.9em;
	height: 0.9em;
	margin-inline-start: 0.15em;
}
</style>
