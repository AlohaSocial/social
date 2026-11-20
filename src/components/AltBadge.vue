<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- two roots on purpose: the badge and its text are both positioned
	     against the photo's frame, which a wrapper would get in the way of -->
	<button
		type="button"
		class="alt-badge"
		:aria-expanded="shown ? 'true' : 'false'"
		:aria-controls="descriptionId"
		:title="t('social', 'Image description')"
		@click.stop="shown = !shown">
		{{ t('social', 'ALT') }}
	</button>
	<p
		v-show="shown"
		:id="descriptionId"
		class="alt-description">
		{{ description }}
	</p>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

/** ids have to be unique per document, and one post may show several photos */
let sequence = 0

/**
 * The ALT badge: reveals the description of a photo to sighted readers.
 *
 * It positions itself against the frame it is put in, which therefore has to
 * be `position: relative` -- `.photo` in GalleryMedia, `.attachment-frame` in
 * PostAttachment.
 */
export default {
	name: 'AltBadge',

	props: {
		/** The description to reveal. Only rendered where there is one. */
		description: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			shown: false,
			descriptionId: `social-alt-${sequence++}`,
		}
	},

	watch: {
		// a different photo in the same frame should not arrive with its description open
		description() {
			this.shown = false
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.alt-badge {
	position: absolute;
	inset-block-end: 8px;
	inset-inline-start: 8px;
	z-index: 4;
	padding: 2px 8px;
	min-height: 0;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: .04em;
	line-height: 1.5;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius, 6px);
	color: var(--color-main-text);
	background: var(--color-main-background);
	opacity: .9;

	&:hover,
	&:focus-visible {
		opacity: 1;
		border-color: var(--color-primary-element);
	}
}

.alt-description {
	position: absolute;
	inset-inline: 0;
	inset-block-end: 0;
	z-index: 3;
	max-height: 60%;
	overflow-y: auto;
	margin: 0;
	padding: 8px 8px 34px;
	font-size: 13px;
	line-height: 1.45;
	text-align: start;
	color: var(--color-main-text);
	background: var(--color-main-background);
	border-block-start: 1px solid var(--color-border);
}
</style>
