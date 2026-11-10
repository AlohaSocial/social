/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import StorageSection from '../../../../src/components/admin/StorageSection.vue'

const MEASURED = {
	local: { attachments: { files: 100, bytes: 5 * 1024 * 1024 * 1024 }, avatars: { files: 10, bytes: 1024 * 1024 } },
	remote: { attachments: { files: 4000, bytes: 31 * 1024 * 1024 * 1024 }, avatars: { files: 900, bytes: 50 * 1024 * 1024 } },
	missing: 3,
	evicted: 25829,
	measured: 1789480000,
}

describe('the storage section', () => {
	/**
	 * The split is the point: "Social is using 40 GB" is not actionable, and
	 * "31 GB of it is other servers' pictures, and Retention is the lever" is.
	 */
	it('separates what was posted here from what was cached', () => {
		const wrapper = mount(StorageSection, { props: { storage: MEASURED } })

		expect(wrapper.text()).toContain('5.0 GiB')
		expect(wrapper.text()).toContain('posted from here')
		expect(wrapper.text()).toContain('31 GiB')
		expect(wrapper.text()).toContain('cached from other servers')
		expect(wrapper.text()).toContain('110 files')
	})

	it('says when it was measured, and what was missing', () => {
		const wrapper = mount(StorageSection, { props: { storage: MEASURED } })

		expect(wrapper.text()).toMatch(/Measured/)
		expect(wrapper.text()).toContain('3 files named in the database were not on disk')
	})

	/**
	 * A purged copy of another server's picture is not a lost file, and must
	 * not read like one.
	 */
	it('counts purged copies apart from missing ones', () => {
		const wrapper = mount(StorageSection, { props: { storage: MEASURED } })

		expect(wrapper.text()).toContain('25829 cached copies are not on disk and are fetched again when somebody looks at them')
	})

	it('shows the media cache limits when the page has them', () => {
		const wrapper = mount(StorageSection, {
			props: { storage: null, mediaCache: { days: 30, sizeMb: 5120, lastPurge: null } },
		})

		expect(wrapper.text()).toContain('Media from other servers')
	})

	/**
	 * "Nothing measured yet" and "this instance stores nothing" are different
	 * things, and a page that cannot tell them apart says the second.
	 */
	it('says nothing has been measured rather than showing zeroes', () => {
		const wrapper = mount(StorageSection, { props: { storage: null } })

		expect(wrapper.text()).toContain('Nothing has been measured yet')
		expect(wrapper.text()).not.toContain('posted from here')
	})
})
