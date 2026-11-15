<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span class="verified-check" :title="label">
		<svg
			class="verified-check__mark"
			viewBox="0 0 24 24"
			width="16"
			height="16"
			role="img"
			:aria-label="label">
			<!-- one path, drawn by animating its own dash offset: no sprite to
			     load and nothing to line up with the text around it -->
			<path
				class="verified-check__tick"
				d="M4 12.5 L9.5 18 L20 6"
				fill="none"
				stroke="currentColor"
				stroke-width="2.5"
				stroke-linecap="round"
				stroke-linejoin="round" />
		</svg>
	</span>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { fullDateTime } from '../utils/relativeTime.js'

/**
 * The mark on a profile row whose link the owner proved is theirs.
 *
 * The tick draws itself once when it appears. That is the whole flourish: the
 * colour alone would say the same thing, so the animation is decoration and
 * `prefers-reduced-motion` turns it off without the mark losing its meaning.
 * The meaning itself is in the label, because a green tick is not readable.
 */
export default {
	name: 'VerifiedCheck',

	props: {
		/** when the link was last proved, as the API sends it; only shown */
		verifiedAt: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * What a screen reader says in place of the tick, and what the tooltip
		 * shows. The date is worth having — a verification is a check that was
		 * made once, and how long ago says how much it is worth — but a row
		 * whose date did not come through, or came through in a shape the
		 * browser cannot read, is still verified, so it falls back to the claim
		 * on its own rather than trailing off after "verified on".
		 *
		 * @return {string} the label
		 */
		label() {
			const date = this.verifiedAt === '' ? '' : fullDateTime(this.verifiedAt)
			if (date === '') {
				return t('social', 'Ownership of this link was verified')
			}

			return t('social', 'Ownership of this link was verified on {date}', { date })
		},
	},

	methods: {
		t,
	},
}
</script>

<style lang="scss" scoped>
.verified-check {
	display: inline-flex;
	align-items: center;
	vertical-align: text-bottom;
	margin-inline-start: var(--default-grid-baseline, 4px);
	color: var(--color-success, #2d7b32);
}

.verified-check__mark {
	flex: 0 0 auto;
}

.verified-check__tick {
	/* the length of the path, so the dash is the whole stroke and the offset
	   hides exactly all of it */
	stroke-dasharray: 26;
	stroke-dashoffset: 26;
	animation: verified-draw var(--social-motion-slow) .1s var(--social-ease) forwards;
}

@keyframes verified-draw {
	to {
		stroke-dashoffset: 0;
	}
}

@media (prefers-reduced-motion: reduce) {
	.verified-check__tick {
		animation: none;
		stroke-dashoffset: 0;
	}
}
</style>
