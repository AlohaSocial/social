<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span v-if="missing" class="emoji emoji--native">{{ emoji }}</span>
	<img
		v-else
		class="emoji"
		draggable="false"
		:alt="emoji"
		:src="emojiUrl"
		loading="lazy"
		decoding="async"
		@error="missing = true">
</template>

<script>
import { generateFilePath } from '@nextcloud/router'
import { toCodePoint } from '../utils/emojiCodePoint.js'

// avoid using a string literal like '\u200D' here because minifiers expand it inline
const U200D = String.fromCharCode(0x200D)
const UFE0Fg = /\uFE0F/g

export default {
	name: 'Emoji',
	props: {
		emoji: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			/**
			 * Whether the picture for this emoji failed to load. The pictures
			 * stop at whichever Unicode version the bundled Twemoji knows;
			 * anything newer has no file, and is better drawn as the reader's
			 * own glyph than as a broken image.
			 */
			missing: false,
		}
	},

	computed: {
		/**
		 * @return {string}
		 */
		icon() {
			return toCodePoint(this.emoji.indexOf(U200D) < 0
				? this.emoji.replace(UFE0Fg, '')
				: this.emoji)
		},

		/**
		 * @return {string}
		 */
		emojiUrl() {
			return generateFilePath('social', 'img', 'twemoji/' + this.icon + '.svg')
		},
	},

	watch: {
		emoji() {
			this.missing = false
		},
	},
}
</script>
