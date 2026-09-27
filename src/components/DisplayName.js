/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { h } from 'vue'
import { emojifyPlain } from './MessageContent.js'

/**
 * A display name (or any plain text) with its custom emoji rendered as
 * inline images. Render-function based: the text is never treated as HTML.
 */
export default {
	name: 'DisplayName',
	props: {
		text: {
			type: String,
			default: '',
		},
		emojis: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').CustomEmoji[]>} */ (Array),
			default: () => [],
		},
	},
	render() {
		return h('span', { class: 'display-name' }, emojifyPlain(h, this.text, this.emojis))
	},
}
