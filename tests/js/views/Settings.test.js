/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Settings from '../../../src/views/Settings.vue'
import MigrationSettings from '../../../src/components/MigrationSettings.vue'
import ScheduledPosts from '../../../src/components/ScheduledPosts.vue'
import ShortcutList from '../../../src/components/ShortcutList.vue'
import ShortcutHelp from '../../../src/components/ShortcutHelp.vue'
import { SHORTCUTS } from '../../../src/services/shortcuts.js'
import { useSettingsStore } from '../../../src/store/settings.js'

// the scheduled list asks the server for its entries as soon as it is drawn
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn().mockResolvedValue({ data: [] }), delete: vi.fn() },
}))

const NcModalStub = { name: 'NcModal', template: '<div class="modal-stub"><slot /></div>' }

// The two account sections are `defineAsyncComponent`s, so mounting the page
// starts a dynamic import that outlives the test: vitest tears the
// environment down while `@nextcloud/vue` is still pulling in a stylesheet,
// and the run ends with an EnvironmentTeardownError although every assertion
// passed. They have tests of their own; here they are stubs.
const asyncStubs = {
	AccountSettings: { name: 'AccountSettings', template: '<section class="account-settings-stub" />' },
	// reads the featured tags and the suggestions on mount, same story
	FeaturedTagsSettings: { name: 'FeaturedTagsSettings', template: '<section class="featured-tags-settings-stub" />' },
	ListsSettings: { name: 'ListsSettings', template: '<section class="lists-settings-stub" />' },
	// reads the reader's keyword filters on mount, so the same applies
	FiltersSettings: { name: 'FiltersSettings', template: '<section class="filters-settings-stub" />' },
	// reads the recap setting on mount, for the same reason: its request would
	// answer after this file has finished
	RecapSettings: { name: 'RecapSettings', template: '<section class="recap-settings-stub" />' },
	// reads the portfolio and the collections on mount, same story again
	PortfolioSettings: { name: 'PortfolioSettings', template: '<section class="portfolio-settings-stub" />' },
	// reads the interests on mount
	InterestsSettings: { name: 'InterestsSettings', template: '<section class="interests-settings-stub" />' },
}

describe('Settings', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('shows every shortcut the app listens for', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()
		const rows = wrapper.findAll('.shortcut-list__row')

		expect(rows).toHaveLength(SHORTCUTS.length)
		expect(rows[0].find('kbd').text()).toBe(SHORTCUTS[0].keys[0])
		expect(rows[0].find('dd').text()).toBe(SHORTCUTS[0].label)
	})

	/** A shortcut with two keys is two keys, not "j/k" in one box. */
	it('gives every key of a shortcut its own cap', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()
		const twoKeyed = SHORTCUTS.findIndex((shortcut) => shortcut.keys.length > 1)

		expect(twoKeyed).toBeGreaterThan(-1)
		expect(wrapper.findAll('.shortcut-list__row')[twoKeyed].findAll('kbd'))
			.toHaveLength(SHORTCUTS[twoKeyed].keys.length)
	})

	it('says that the keys stop while you are typing', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		expect(wrapper.find('.shortcut-list__hint').text())
			.toBe('Shortcuts are off while you are writing.')
	})

	/**
	 * A shortcut is a promise about a keystroke, and the `?` dialog makes the
	 * same one. They draw the same component so the two cannot drift apart.
	 */
	it('draws the same list the ? dialog does', async () => {
		const page = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()
		const dialog = mount(ShortcutHelp, {
			props: { open: true },
			global: { stubs: { NcModal: NcModalStub } },
		})

		expect(page.findComponent(ShortcutList).exists()).toBe(true)
		expect(dialog.findComponent(ShortcutList).exists()).toBe(true)
		const rows = page.findAll('.shortcut-list__row').length
		expect(dialog.findAll('.shortcut-list__row')).toHaveLength(rows)
	})

	/**
	 * The frame is a list of sections, and Migration was the second.
	 *
	 * The shortcuts are reference rather than a setting — nothing there is
	 * changed, it is a list to look something up in — so they sit at the end,
	 * under the things that are done to the account, with only the deletion
	 * below them, which is last because it cannot be undone.
	 */
	it('is a page of sections rather than a page about shortcuts', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		expect(wrapper.find('.settings__heading').text()).toBe('Settings')
		expect(wrapper.findAll('.settings__section-heading').map((h) => h.text()))
			.toEqual(['Your account', 'Featured hashtags', 'Lists', 'Scheduled posts', 'Portfolio', 'Archived posts', 'Waiting to be looked at', 'Looking back', 'Sound and touch', 'Authorized apps', 'Introduction', 'Keyboard shortcuts', 'Delete your Social account'])
	})

	/**
	 * The introduction is shown once, after the setup screen; an account made
	 * by an administrator never saw it, and nothing brought it back.
	 */
	it('offers the first-run introduction again', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		const section = wrapper.find('#introduction')
		expect(section.exists()).toBe(true)
		const button = section.findComponent({ name: 'NcButton' })
		expect(button.text()).toBe('Show the introduction again')
		expect(button.props('to')).toEqual({ name: 'timeline', query: { welcome: '1' } })
	})

	/**
	 * Each section is linked to from somewhere, and a section wearing another
	 * one's id is a link that scrolls to the wrong place.
	 */
	it('gives each section the id that names it', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		const idOf = (heading) => wrapper.findAll('.settings__section')
			.find((section) => section.find('.settings__section-heading').text() === heading)
			.attributes('id')

		expect(idOf('Scheduled posts')).toBe('scheduled')
		expect(idOf('Keyboard shortcuts')).toBe('shortcuts')
	})

	/**
	 * The hashtag editor is reached from the reader's own profile, which links
	 * at `#featured-tags` — a section with no id is a link that lands at the
	 * top of the page and looks like it did nothing.
	 */
	it('gives the hashtag editor the id its profile link points at', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		expect(wrapper.find('#featured-tags').exists()).toBe(true)
	})

	/**
	 * They were here, under "Filtered words", until it was pointed out that a
	 * reader looking for them looks where the accounts they have silenced are.
	 * Blocking owns them now; Settings must not grow a second copy.
	 */
	it('leaves the keyword filters to the Blocking page', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		expect(wrapper.find('.filters-settings-stub').exists()).toBe(false)
	})

	/**
	 * A page of its own for a list that is usually empty, and whose entries
	 * offer two actions, would be a navigation entry earning its place from
	 * nothing. The composer's clock is where a post is scheduled; this is
	 * where one is moved or taken back.
	 */
	it('holds the posts waiting to go out', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		expect(wrapper.findComponent(ScheduledPosts).exists()).toBe(true)
	})

	/** A waiting post can be moved here, so the section does not send people to write it again. */
	it('says a waiting post can be moved as well as cancelled', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		const lede = wrapper.find('#scheduled .settings__section-lede').text()
		expect(lede).toContain('Move one to another time')
		expect(lede).not.toContain('write it again')
	})

	/**
	 * Migration is a page of its own, reached from the account menu.
	 *
	 * It was a section here, on the grounds that the account menu is for
	 * places to read something and this is a thing you do to the account.
	 * The other way round in the end: exporting an archive, importing one and
	 * bringing a following list over from another network are each a job
	 * somebody sits down to do, with a file manager open and another server in
	 * the next tab — not a switch flipped while reading down a page of
	 * switches, and not something to scroll past eleven of them to find.
	 */
	it('leaves the migration tools to their own page', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		expect(wrapper.findComponent(MigrationSettings).exists()).toBe(false)
		expect(wrapper.findAll('.settings__toc-link').map((link) => link.text()))
			.not.toContain('Migration')
	})

	/**
	 * `#migration` is what has been linked to and bookmarked for as long as it
	 * was a section here. Sent on rather than ignored: landing on a settings
	 * page with nothing highlighted is the one answer that says nothing.
	 */
	it('sends an old link to the migration section on to the page', async () => {
		const replace = vi.fn()
		mount(Settings, {
			global: {
				stubs: asyncStubs,
				mocks: { $route: { hash: '#migration' }, $router: { replace } },
			},
		})
		await flushPromises()

		expect(replace).toHaveBeenCalledWith({ name: 'migration' })
	})

	/**
	 * Twelve sections is a long scroll, and the one a reader came for was
	 * somewhere in it. The rail is that scroll as a list, so it has to name
	 * every section and point at it -- a rail that has drifted from the page
	 * is worse than no rail, because it sends people to the wrong place.
	 */
	it('lists every section in the rail, in the order they are read', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		const railed = wrapper.findAll('.settings__toc-link')
		const headings = wrapper.findAll('.settings__section-heading')

		expect(railed.map((link) => link.text())).toEqual(headings.map((h) => h.text()))
	})

	it('points each rail link at the section it names', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		const hrefs = wrapper.findAll('.settings__toc-link').map((link) => link.attributes('href'))
		const ids = wrapper.findAll('.settings__section').map((section) => `#${section.attributes('id')}`)

		expect(hrefs).toEqual(ids)
	})

	/**
	 * The deletion is the one thing on the page that cannot be undone, and it
	 * is marked in both places a reader meets it.
	 */
	/**
	 * The mark follows the reader, and where it lands is the whole point of
	 * the rail: the section whose heading has last passed the top of the
	 * window, not whichever section happens to be the highest thing visible.
	 * A tall section straddling the top is still the highest thing on screen
	 * while the reader is already reading the one after it.
	 */
	it('marks the last section whose heading has passed the top', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		// account and featured-tags start above the window, lists is just
		// below its top, and scheduled is further down the page
		const tops = { account: -800, 'featured-tags': -240, lists: 40, scheduled: 320 }
		const real = document.getElementById.bind(document)
		vi.spyOn(document, 'getElementById').mockImplementation((id) => (
			id in tops ? { getBoundingClientRect: () => ({ top: tops[id] }) } : real(id)
		))

		wrapper.vm.markCurrent()
		expect(wrapper.vm.current).toBe('lists')

		vi.restoreAllMocks()
	})

	it('marks the section that cannot be undone', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		expect(wrapper.find('#delete').classes()).toContain('settings__section--danger')
		expect(wrapper.findAll('.settings__toc-link--danger')).toHaveLength(1)
	})
	/**
	 * The interests section shapes a feed the administrator can switch off,
	 * and a section about a feed that does not exist is a promise nobody
	 * keeps. It comes right after the featured hashtags: both are about the
	 * reader's hashtags.
	 */
	it('offers the interests only when the feature is on', async () => {
		const off = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()
		expect(off.find('#interests').exists()).toBe(false)

		useSettingsStore().setServerData({ interests: { enabled: true, learning: true, paused: false } })
		const on = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		const ids = on.findAll('.settings__section').map((section) => section.attributes('id'))
		expect(ids.indexOf('interests')).toBe(ids.indexOf('featured-tags') + 1)
		expect(on.find('#interests .interests-settings-stub').exists()).toBe(true)
		expect(on.findAll('.settings__toc-link').map((link) => link.text())).toContain('My interests')
	})
})
