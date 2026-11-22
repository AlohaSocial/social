/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import ShortcutHelp from '../../../src/components/ShortcutHelp.vue'
import { PLAYER_SHORTCUTS, SHORTCUTS } from '../../../src/services/shortcuts.js'

const NcModal = {
	name: 'NcModal',
	props: ['name'],
	emits: ['close'],
	template: '<div class="modal"><slot /></div>',
}

/**
 * @param {boolean} open whether the dialog is showing
 * @return {object} the mounted dialog
 */
function mountHelp(open) {
	return mount(ShortcutHelp, { props: { open }, global: { stubs: { NcModal } } })
}

describe('the ? dialog', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('stays out of the page until it is asked for', () => {
		expect(mountHelp(false).findComponent(NcModal).exists()).toBe(false)
	})

	it('shows the shortcuts when it is opened', () => {
		const wrapper = mountHelp(true)

		expect(wrapper.findComponent(NcModal).props('name')).toBe('Keyboard shortcuts')
		expect(wrapper.findAll('.shortcut-list__row')).toHaveLength(SHORTCUTS.length + PLAYER_SHORTCUTS.length)
	})

	/** The switch lived in Settings; it is where the shortcuts are listed now. */
	it('offers the switch that turns them off, above the list', () => {
		expect(mountHelp(true).text()).toContain('Use keyboard shortcuts')
	})

	it('tells the page when it is dismissed', () => {
		const wrapper = mountHelp(true)

		wrapper.findComponent(NcModal).vm.$emit('close')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
