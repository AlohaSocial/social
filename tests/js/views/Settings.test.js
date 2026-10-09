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
	// reads who may send direct messages on mount
	DirectMessagesSettings: { name: 'DirectMessagesSettings', template: '<section class="direct-messages-settings-stub" />' },
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
	// reads the delivery settings on mount
	NotificationDeliverySettings: { name: 'NotificationDeliverySettings', template: '<section class="notification-delivery-settings-stub" />' },
	NotificationPolicySettings: { name: 'NotificationPolicySettings', template: '<section class="notification-policy-settings-stub" />' },
	// reads its switch on mount
	FilesCommentsSettings: { name: 'FilesCommentsSettings', template: '<section class="files-comments-settings-stub" />' },
	// reads nothing, but travels in the same chunk as the rest
	SensitiveMediaSettings: { name: 'SensitiveMediaSettings', template: '<section class="sensitive-media-settings-stub" />' },
	// reads the Bluesky identity on mount
	BlueskySettings: { name: 'BlueskySettings', template: '<section class="bluesky-settings-stub" />' },
}

/**
 * @param {object} options what to mount with
 * @param {string} options.hash the address's hash, as a link would carry it
 * @return {Promise<object>} the page, once its first update has settled
 */
async function mountSettings({ hash = '' } = {}) {
	const replace = vi.fn().mockResolvedValue()
	const wrapper = mount(Settings, {
		global: {
			stubs: asyncStubs,
			mocks: { $route: { hash }, $router: { replace } },
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * @param {object} wrapper the page
 * @param {string} title the group's name as the rail shows it
 */
async function openGroup(wrapper, title) {
	const link = wrapper.findAll('.settings__group-link')
		.find((one) => one.find('.settings__group-title').text() === title)
	await link.trigger('click')
	await flushPromises()
}

const headings = (wrapper) => wrapper.findAll('.settings__section-heading').map((h) => h.text())
const groupTitles = (wrapper) => wrapper.findAll('.settings__group-title').map((title) => title.text())

describe('Settings', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	/**
	 * Seventeen sections in one scroll were more than anybody could find
	 * their way through. They are in groups, and one group is shown at a time.
	 */
	it('sorts the sections into groups and opens the first', async () => {
		const wrapper = await mountSettings()

		expect(wrapper.find('.settings__heading').text()).toBe('Settings')
		expect(groupTitles(wrapper)).toEqual(['Profile and privacy', 'Reading', 'Notifications', 'Your posts', 'Apps and account', 'Help'])
		expect(wrapper.find('.settings__group-heading').text()).toBe('Profile and privacy')
		expect(headings(wrapper)).toEqual(['Who can find and follow you', 'Who may send you direct messages', 'Featured hashtags', 'Portfolio'])
	})

	it('has every section in exactly one group, in the order they are read', async () => {
		const wrapper = await mountSettings()
		const seen = []
		for (const title of groupTitles(wrapper)) {
			await openGroup(wrapper, title)
			expect(wrapper.find('.settings__group-heading').text()).toBe(title)
			seen.push(...headings(wrapper))
		}

		expect(seen).toEqual([
			'Who can find and follow you',
			'Who may send you direct messages',
			'Featured hashtags',
			'Portfolio',
			'Lists',
			'Sensitive media',
			'Likes and followers',
			'Looking back',
			'Sound and touch',
			'When to tell you',
			'Who may reach you',
			'Scheduled posts',
			'Waiting for review',
			'Archived posts',
			'Replies in Files',
			'Authorized apps',
			'Delete your Aloha Social account',
			'Introduction',
			'Keyboard shortcuts',
		])
	})

	/** The deletion cannot be undone, so it comes last in its group, marked. */
	it('marks the section that cannot be undone', async () => {
		const wrapper = await mountSettings()
		await openGroup(wrapper, 'Apps and account')

		expect(wrapper.find('#delete').classes()).toContain('settings__section--danger')
		expect(headings(wrapper).at(-1)).toBe('Delete your Aloha Social account')
		expect(wrapper.findAll('.settings__toc-link--danger')).toHaveLength(1)
	})

	describe('a link to a section', () => {
		/**
		 * Other pages link to sections by id: the follow requests page to
		 * #account, a profile to #featured-tags, the held requests to
		 * #notification-policy. A link opens the group the section is in.
		 */
		it.each([
			['#scheduled', 'Your posts'],
			['#notification-policy', 'Notifications'],
			['#featured-tags', 'Profile and privacy'],
			['#shortcuts', 'Help'],
		])('%s opens %s', async (hash, group) => {
			const wrapper = await mountSettings({ hash })

			expect(wrapper.find('.settings__group-heading').text()).toBe(group)
			expect(wrapper.find(hash).exists()).toBe(true)
		})

		/**
		 * `#notification-policy` was linked to from three pages while no
		 * section carried it, so every one of them landed at the top.
		 */
		it('reaches who may reach you, which now has a section of its own', async () => {
			const wrapper = await mountSettings({ hash: '#notification-policy' })

			expect(wrapper.find('section.settings__section#notification-policy .notification-policy-settings-stub').exists()).toBe(true)
			expect(wrapper.find('section.settings__section#notifications .notification-delivery-settings-stub').exists()).toBe(true)
		})

		/**
		 * `#migration` is what has been linked to and bookmarked for as long
		 * as it was a section here. Sent on rather than ignored: landing on a
		 * settings page with nothing highlighted is the one answer that says
		 * nothing.
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
	})

	describe('the rail', () => {
		it('lists the sections of the open group, pointing at each', async () => {
			const wrapper = await mountSettings()
			await openGroup(wrapper, 'Your posts')

			const links = wrapper.findAll('.settings__toc-link')
			expect(links.map((link) => link.text())).toEqual(headings(wrapper))
			expect(links.map((link) => link.attributes('href')))
				.toEqual(wrapper.findAll('.settings__section').map((section) => `#${section.attributes('id')}`))
		})

		it('points each group at its first section, so the link can be copied', async () => {
			const wrapper = await mountSettings()

			expect(wrapper.findAll('.settings__group-link').map((link) => link.attributes('href')))
				.toEqual(['#account', '#lists', '#notifications', '#scheduled', '#apps', '#introduction'])
		})

		it('puts the group opened into the address', async () => {
			const wrapper = await mountSettings()
			await openGroup(wrapper, 'Help')

			expect(wrapper.vm.$router.replace).toHaveBeenCalledWith({ hash: '#introduction' })
			expect(wrapper.find('.settings__group-link--current .settings__group-title').text()).toBe('Help')
		})

		/**
		 * The mark follows the reader: the section whose heading has last
		 * passed the top of the window, not whichever section happens to be
		 * the highest thing visible.
		 */
		it('marks the last section whose heading has passed the top', async () => {
			const wrapper = await mountSettings()

			const tops = { account: -800, 'direct-messages': -400, 'featured-tags': 40, portfolio: 320 }
			const real = document.getElementById.bind(document)
			vi.spyOn(document, 'getElementById').mockImplementation((id) => (
				id in tops ? { getBoundingClientRect: () => ({ top: tops[id] }) } : real(id)
			))

			wrapper.vm.markCurrent()
			expect(wrapper.vm.current).toBe('featured-tags')

			vi.restoreAllMocks()
		})

		/**
		 * At the top of the page nothing has passed, and that is the first
		 * section. It used to keep whatever was marked before, which left a
		 * section far down the page marked while the reader was at the top.
		 */
		it('marks the first section before any has passed the top', async () => {
			const wrapper = await mountSettings()
			wrapper.vm.current = 'portfolio'

			const tops = { account: 150, 'direct-messages': 600, 'featured-tags': 900, portfolio: 1400 }
			const real = document.getElementById.bind(document)
			vi.spyOn(document, 'getElementById').mockImplementation((id) => (
				id in tops ? { getBoundingClientRect: () => ({ top: tops[id] }) } : real(id)
			))

			wrapper.vm.markCurrent()
			expect(wrapper.vm.current).toBe('account')

			vi.restoreAllMocks()
		})
	})

	describe('finding a setting', () => {
		/** For the reader who knows what they want but not where it is. */
		it('shows the sections of every group that match what was typed', async () => {
			const wrapper = await mountSettings()
			await wrapper.find('.settings__search input').setValue('phone')
			await flushPromises()

			expect(headings(wrapper)).toEqual(['Sound and touch', 'Authorized apps'])
			expect(wrapper.findAll('.settings__section-group').map((label) => label.text())).toEqual(['Reading', 'Apps and account'])
			expect(wrapper.find('.settings__results').text()).toBe('2 settings match “phone”')
			expect(wrapper.find('.settings__group-link--current').exists()).toBe(false)
		})

		it('needs every word, in any case and with or without accents', async () => {
			const wrapper = await mountSettings()
			await wrapper.find('.settings__search input').setValue('  SCHEDULED   later ')
			await flushPromises()

			expect(headings(wrapper)).toEqual(['Scheduled posts'])
		})

		it('says so when nothing matches', async () => {
			const wrapper = await mountSettings()
			await wrapper.find('.settings__search input').setValue('zzz')
			await flushPromises()

			expect(wrapper.findAll('.settings__section')).toHaveLength(0)
			expect(wrapper.find('.settings__results').text()).toBe('No setting matches “zzz”.')
		})

		it('goes back to the groups once a group is chosen', async () => {
			const wrapper = await mountSettings()
			await wrapper.find('.settings__search input').setValue('phone')
			await openGroup(wrapper, 'Notifications')

			expect(wrapper.vm.query).toBe('')
			expect(headings(wrapper)).toEqual(['When to tell you', 'Who may reach you'])
		})
	})

	describe('the shortcuts', () => {
		it('shows every shortcut the app listens for', async () => {
			const wrapper = await mountSettings({ hash: '#shortcuts' })
			const rows = wrapper.findAll('.shortcut-list__row')

			expect(rows).toHaveLength(SHORTCUTS.length)
			expect(rows[0].find('kbd').text()).toBe(SHORTCUTS[0].keys[0])
			expect(rows[0].find('dd').text()).toBe(SHORTCUTS[0].label)
		})

		/** A shortcut with two keys is two keys, not "j/k" in one box. */
		it('gives every key of a shortcut its own cap', async () => {
			const wrapper = await mountSettings({ hash: '#shortcuts' })
			const twoKeyed = SHORTCUTS.findIndex((shortcut) => shortcut.keys.length > 1)

			expect(twoKeyed).toBeGreaterThan(-1)
			expect(wrapper.findAll('.shortcut-list__row')[twoKeyed].findAll('kbd'))
				.toHaveLength(SHORTCUTS[twoKeyed].keys.length)
		})

		it('says that the keys stop while you are typing', async () => {
			const wrapper = await mountSettings({ hash: '#shortcuts' })

			expect(wrapper.find('.shortcut-list__hint').text())
				.toBe('Shortcuts are off while you are writing.')
		})

		/**
		 * A shortcut is a promise about a keystroke, and the `?` dialog makes
		 * the same one. They draw the same component so the two cannot drift
		 * apart.
		 */
		it('draws the same list the ? dialog does', async () => {
			const page = await mountSettings({ hash: '#shortcuts' })
			const dialog = mount(ShortcutHelp, {
				props: { open: true },
				global: { stubs: { NcModal: NcModalStub } },
			})

			expect(page.findComponent(ShortcutList).exists()).toBe(true)
			expect(dialog.findComponent(ShortcutList).exists()).toBe(true)
			const rows = page.findAll('.shortcut-list__row').length
			expect(dialog.findAll('.shortcut-list__row')).toHaveLength(rows)
		})
	})

	/** A self-registered external user has no Files to read replies in. */
	it('offers replies in Files only to somebody who has Files', async () => {
		useSettingsStore().setServerData({ externalMedia: { quota: 10, used: 0 } })
		const wrapper = await mountSettings({ hash: '#scheduled' })

		expect(headings(wrapper)).not.toContain('Replies in Files')
	})

	/**
	 * The introduction is shown once, after the setup screen; an account made
	 * by an administrator never saw it, and nothing brought it back.
	 */
	it('offers the first-run introduction again', async () => {
		const wrapper = await mountSettings({ hash: '#introduction' })

		const button = wrapper.find('#introduction').findComponent({ name: 'NcButton' })
		expect(button.text()).toBe('Show the introduction again')
		expect(button.props('to')).toEqual({ name: 'timeline', query: { replay: '1' } })
	})

	/**
	 * They were here, under "Filtered words", until it was pointed out that a
	 * reader looking for them looks where the accounts they have silenced are.
	 * Blocking owns them now; Settings must not grow a second copy.
	 */
	it('leaves the keyword filters to the Blocking page', async () => {
		const wrapper = await mountSettings()
		await wrapper.find('.settings__search input').setValue('a')
		await flushPromises()

		expect(wrapper.find('.filters-settings-stub').exists()).toBe(false)
	})

	/**
	 * A page of its own for a list that is usually empty would be a
	 * navigation entry earning its place from nothing. The composer's clock
	 * is where a post is scheduled; this is where one is moved or taken back.
	 */
	it('holds the posts waiting to go out, and says they can be moved', async () => {
		const wrapper = await mountSettings({ hash: '#scheduled' })

		expect(wrapper.findComponent(ScheduledPosts).exists()).toBe(true)
		const lede = wrapper.find('#scheduled .settings__section-lede').text()
		expect(lede).toContain('Move one to another time')
		expect(lede).not.toContain('write it again')
	})

	/**
	 * Migration is a page of its own, reached from the account menu: each of
	 * its tools is a job somebody sits down to do, not a switch on a page of
	 * switches.
	 */
	it('leaves the migration tools to their own page', async () => {
		const wrapper = await mountSettings()
		await wrapper.find('.settings__search input').setValue('e')
		await flushPromises()

		expect(wrapper.findComponent(MigrationSettings).exists()).toBe(false)
		expect(groupTitles(wrapper)).not.toContain('Migration')
	})

	/**
	 * The interests shape a feed the administrator can switch off, and a
	 * section about a feed that does not exist is a promise nobody keeps.
	 */
	it('offers the interests only when the feature is on, first under Reading', async () => {
		const off = await mountSettings({ hash: '#lists' })
		expect(off.find('#interests').exists()).toBe(false)

		useSettingsStore().setServerData({ interests: { enabled: true, learning: true, paused: false } })
		const on = await mountSettings({ hash: '#lists' })

		expect(headings(on)[0]).toBe('For you')
		expect(on.find('#interests .interests-settings-stub').exists()).toBe(true)
	})

	/** How sensitive media is shown is about reading, not about the account. */
	it('keeps sensitive media with the reading settings', async () => {
		const wrapper = await mountSettings({ hash: '#sensitive' })

		expect(wrapper.find('.settings__group-heading').text()).toBe('Reading')
		expect(wrapper.find('#sensitive .sensitive-media-settings-stub').exists()).toBe(true)
	})

	/**
	 * The identity exists because this server offers one; on a server that
	 * offers none, a section about it would describe somebody else's server.
	 */
	it('shows the Bluesky identity only where the server offers one, with the apps', async () => {
		const off = await mountSettings({ hash: '#apps' })
		expect(off.find('#bluesky').exists()).toBe(false)

		useSettingsStore().setServerData({ bluesky: { enabled: true, host: 'cloud.example.org' } })
		const on = await mountSettings({ hash: '#bluesky' })

		expect(on.find('.settings__group-heading').text()).toBe('Apps and account')
		expect(on.find('#bluesky .settings__section-lede').text()).toBe('This account is also reachable on Bluesky, because this server offers one.')
		expect(on.find('#bluesky .bluesky-settings-stub').exists()).toBe(true)
	})
})
