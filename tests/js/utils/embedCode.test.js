/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { embedCode } from '../../../src/utils/embedCode.js'

describe('embedCode', () => {
	it('names the frame with the title it is given', () => {
		expect(embedCode('https://cloud.example/apps/social/@alice/1/embed', 'Beitrag auf Aloha Social'))
			.toBe('<iframe src="https://cloud.example/apps/social/@alice/1/embed" width="550" height="480" loading="lazy" frameborder="0" style="max-width:100%;border:0;border-radius:12px" title="Beitrag auf Aloha Social"></iframe>')
	})

	it('keeps a translated title from breaking out of its attribute', () => {
		const code = embedCode('https://cloud.example/e?a=1&b=2', 'Le "post" <Aloha> & co')

		expect(code).toContain('src="https://cloud.example/e?a=1&amp;b=2"')
		expect(code).toContain('title="Le &quot;post&quot; &lt;Aloha&gt; &amp; co"')
	})
})
