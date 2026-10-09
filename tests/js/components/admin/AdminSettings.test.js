/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { readFileSync, readdirSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import AdminSettings from '../../../../src/components/admin/AdminSettings.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

/** The page as `AdminSettings::getForm()` provides it. */
const STATE = {
	reports: [],
	openReports: 0,
	resolvedReports: 0,
	reportsPerPage: 50,
	activity: { day: { posts: 3, authors: 2 }, week: { posts: 11, authors: 4 } },
	background: { jobs: [], late: 0, worst: 0 },
	server: {
		contact_email: '',
		extended_description: '',
		max_size: 10,
		max_video_size: 2048,
		inbox_throttle: 300,
		secure_mode: false,
		publish_blocks: false,
		allow_self_signed: false,
	},
	sections: {
		stories: true,
		section_photos: true,
		section_videos: true,
		section_news: true,
		group_lists: [],
	},
	groups: [{ id: 'design', name: 'Design' }],
	accessType: 'all_but',
	accessList: [],
	retentionDays: 0,
	federation: {
		waiting: 0,
		running: 0,
		failing: 0,
		atRisk: 0,
		abandoned: 0,
		maxTries: 15,
		truncated: false,
		abandonedTruncated: false,
		retentionDays: 7,
		instances: [],
		givenUp: [],
	},
}

/** the pages mounted by a test, unmounted after it so their listeners go too */
const mounted = []

afterEach(() => {
	mounted.splice(0).forEach((wrapper) => wrapper.unmount())
})

/**
 * @param {object} state what the server provided
 * @param {string} hash the address's fragment when the page opens
 * @return {Promise<object>} the mounted page
 */
async function mountPage(state, hash = '') {
	// loadState memoises what it read on the window; a second page in the same
	// test file would otherwise get the first one's state
	delete globalThis._nc_initial_state
	globalThis.setInitialState('social', 'adminSettings', state)
	window.history.replaceState(null, '', `/settings/admin/social${hash}`)
	const wrapper = mount(AdminSettings, { attachTo: document.body })
	mounted.push(wrapper)
	await flushPromises()

	return wrapper
}

/**
 * @param {object} wrapper the page
 * @return {string[]} the names of the groups in the navigation
 */
function groupNames(wrapper) {
	return wrapper.findAll('.social-admin__group-title').map((title) => title.text())
}

/**
 * @param {object} wrapper the page
 * @return {string[]} the headings of the sections drawn
 */
function sectionHeadings(wrapper) {
	return wrapper.findAll('.social-admin__section-heading').map((heading) => heading.text())
}

/**
 * Opens a group as a click on it in the navigation does.
 *
 * @param {object} wrapper the page
 * @param {string} name the group's name
 */
async function openGroup(wrapper, name) {
	const link = wrapper.findAll('.social-admin__group-link')
		.find((one) => one.find('.social-admin__group-title').text() === name)
	await link.trigger('click')
	await flushPromises()
}

describe('the administration page', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.get.mockResolvedValue({ data: { accounts: [], cursors: [], announcements: [] } })
	})

	it('opens on the overview, with what needs attention above the activity', async () => {
		const wrapper = await mountPage(STATE)

		expect(wrapper.find('.social-admin__group-heading').text()).toBe('Overview')
		expect(sectionHeadings(wrapper)).toEqual(['Needs attention', 'Activity here'])
		expect(wrapper.text()).toContain('Nothing is waiting for you.')
	})

	/**
	 * Twenty sections in one run were more than anybody reads to the end of;
	 * they are in groups by what an administrator came to do, one at a time.
	 */
	it('puts every section in exactly one group, shown one group at a time', async () => {
		const wrapper = await mountPage(STATE)

		expect(groupNames(wrapper)).toEqual(['Overview', 'Moderation', 'Explore and features', 'Federation', 'Server'])

		const seen = {}
		for (const name of groupNames(wrapper)) {
			await openGroup(wrapper, name)
			expect(wrapper.find('.social-admin__group-heading').text()).toBe(name)
			seen[name] = sectionHeadings(wrapper)
		}

		expect(seen).toEqual({
			Overview: ['Needs attention', 'Activity here'],
			Moderation: ['Reports', 'Posts waiting for review', 'Accounts', 'Verified accounts', 'Refused pictures', 'Server rules'],
			'Explore and features': ['About this server', 'What may trend', 'Custom emoji', 'Announcements', 'Features'],
			Federation: ['Allowed and blocked servers', 'Block lists', 'Relays', 'Deliveries'],
			Server: ['Server settings', 'Retention', 'Storage', 'Background jobs'],
		})
	})

	it('lists the sections of the open group beside it, each a link to its section', async () => {
		const wrapper = await mountPage(STATE)
		await openGroup(wrapper, 'Moderation')

		const links = wrapper.findAll('.social-admin__toc-link')
		expect(links.map((link) => link.text())).toEqual(sectionHeadings(wrapper))
		expect(links.map((link) => link.attributes('href').slice(1)))
			.toEqual(wrapper.findAll('.social-admin__section').map((section) => section.attributes('id')))
	})

	/**
	 * A delegate moderates; they do not administer. `AdminSettings` sends no
	 * server settings to one, so there is nothing for those sections to draw.
	 */
	it('leaves out what a delegated administrator is not sent', async () => {
		const wrapper = await mountPage({ ...STATE, server: null, sections: null, groups: null })
		const all = []
		for (const name of groupNames(wrapper)) {
			await openGroup(wrapper, name)
			all.push(...sectionHeadings(wrapper))
		}

		expect(all).not.toContain('Server settings')
		// nor the relays, which change what every federated timeline here
		// holds and where every public post written here is sent
		expect(all).not.toContain('Relays')
		expect(all).not.toContain('Block lists')
		// nor what the app offers everybody, which is a decision about the
		// instance rather than about a report
		expect(all).not.toContain('Features')
		expect(all).toHaveLength(17)
	})

	/**
	 * The Bluesky side of the server is administration: it changes what the
	 * server is to the outside, so a delegate is sent nothing for it, and an
	 * instance whose server told nothing draws no card.
	 */
	it('draws the Bluesky card with the federation when the server sends it', async () => {
		const bluesky = {
			settings: { enabled: false, relays: [], plc_directory: 'https://plc.directory', appview: 'https://public.api.bsky.app', jetstream: '', sync_ceiling: 200 },
			status: { handle_host: 'cloud.example.org', pds_endpoint: 'https://cloud.example.org', service_did: 'did:web:cloud.example.org', identities: 0, repositories: 0, events_in_window: 0, head_seq: 0, rotation_key_age: 0, daemon: null },
			checks: [],
		}
		const wrapper = await mountPage({ ...STATE, bluesky })
		await openGroup(wrapper, 'Federation')

		expect(sectionHeadings(wrapper)).toEqual(['Allowed and blocked servers', 'Block lists', 'Relays', 'Bluesky', 'Deliveries'])
		expect(wrapper.findComponent({ name: 'BlueskySection' }).props('settings')).toEqual(bluesky)
		expect(wrapper.find('#bluesky').exists()).toBe(true)

		const delegate = await mountPage({ ...STATE, bluesky, server: null })
		await openGroup(delegate, 'Federation')
		expect(sectionHeadings(delegate)).not.toContain('Bluesky')

		const without = await mountPage(STATE)
		await openGroup(without, 'Federation')
		expect(sectionHeadings(without)).not.toContain('Bluesky')
	})

	/** Who may have an account here is an administrator's decision; a delegate is sent none of it. */
	it('draws the Sign-ups group after moderation when the server sends it', async () => {
		const external = {
			settings: { enabled: false, max: 100, quota: 1024, mode: 'approval', verifyEmail: true, minAge: 16, reserved: [], userInvites: false, signupNotice: '', count: 0, awaitingApproval: 2, restricted: false, restrictionIncludesExternals: true },
			twoFactor: { enforced: false, everybody: false },
			requests: [],
			invites: [],
			users: [],
		}
		const wrapper = await mountPage({ ...STATE, external })

		expect(groupNames(wrapper).slice(0, 3)).toEqual(['Overview', 'Moderation', 'Sign-ups'])
		await openGroup(wrapper, 'Sign-ups')
		expect(sectionHeadings(wrapper)).toEqual(['Who may sign up', 'Waiting and invited', 'External accounts'])

		const without = await mountPage(STATE)
		expect(groupNames(without)).not.toContain('Sign-ups')
	})

	it('draws a page the server told nothing about without breaking', async () => {
		const wrapper = await mountPage({}, '#reports')

		expect(wrapper.text()).toContain('No open reports.')
	})

	/**
	 * Old links, the dashboard widgets and the report notification name a
	 * section; the page opens the group it is in.
	 */
	it('opens the group of the section the address names', async () => {
		const wrapper = await mountPage(STATE, '#relays')

		expect(wrapper.find('.social-admin__group-heading').text()).toBe('Federation')
		expect(wrapper.find('#relays').exists()).toBe(true)

		window.location.hash = '#retention'
		window.dispatchEvent(new HashChangeEvent('hashchange'))
		await flushPromises()
		expect(wrapper.find('.social-admin__group-heading').text()).toBe('Server')
	})

	it('puts the group opened in the address, so a reload comes back to it', async () => {
		const wrapper = await mountPage(STATE)
		await openGroup(wrapper, 'Federation')

		expect(window.location.hash).toBe('#access')
	})
})

describe('what needs attention', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.get.mockResolvedValue({ data: { accounts: [], cursors: [], announcements: [] } })
	})

	const BUSY = {
		...STATE,
		openReports: 3,
		reviewTotal: 2,
		background: { jobs: [], late: 1, worst: 3600 },
		federation: { ...STATE.federation, failing: 4, atRisk: 0 },
	}

	it('lists each kind of waiting work with its count, the most urgent first', async () => {
		const wrapper = await mountPage(BUSY)
		const items = wrapper.findAll('.attention__item')

		expect(items.map((item) => [item.find('.attention__count').text(), item.attributes('href')])).toEqual([
			['1', '#background'],
			['3', '#reports'],
			['2', '#review'],
			['4', '#federation'],
		])
	})

	it('takes the administrator to the section that deals with it', async () => {
		const wrapper = await mountPage(BUSY)
		await wrapper.find('.attention__item[href="#reports"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('.social-admin__group-heading').text()).toBe('Moderation')
		expect(window.location.hash).toBe('#reports')
	})

	/** A delivery that will be retried is information, not a job; it puts no number on its group. */
	it('counts what waits on a group beside its name', async () => {
		const wrapper = await mountPage(BUSY)
		const badges = Object.fromEntries(wrapper.findAll('.social-admin__group-link').map((link) => [
			link.find('.social-admin__group-title').text(),
			link.find('.social-admin__badge').exists() ? link.find('.social-admin__badge').text() : '',
		]))

		expect(badges).toEqual({ Overview: '', Moderation: '5', 'Explore and features': '', Federation: '', Server: '1' })
	})
})

describe('the search', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.get.mockResolvedValue({ data: { accounts: [], cursors: [], announcements: [] } })
	})

	/**
	 * @param {object} wrapper the page
	 * @param {string} query what to type
	 */
	async function search(wrapper, query) {
		await wrapper.find('input[type="search"]').setValue(query)
		await flushPromises()
	}

	it('finds a section in any group by its name, its description or a word it is known by', async () => {
		const wrapper = await mountPage(STATE)

		await search(wrapper, 'cron')
		expect(sectionHeadings(wrapper)).toEqual(['Background jobs'])
		expect(wrapper.find('.social-admin__section-group').text()).toBe('Server')

		await search(wrapper, 'blocklist')
		expect(sectionHeadings(wrapper)).toContain('Allowed and blocked servers')

		await search(wrapper, 'spam')
		expect(sectionHeadings(wrapper)).toContain('Posts waiting for review')
	})

	it('says so when nothing matches', async () => {
		const wrapper = await mountPage(STATE)
		await search(wrapper, 'nothing like this')

		expect(sectionHeadings(wrapper)).toEqual([])
		expect(wrapper.find('.social-admin__results').text()).toContain('No setting matches')
	})
})

/**
 * Half of what this page draws — a handle, an instance name, the comment on a
 * report — is a string another server sent, and this is the page whose buttons
 * delete accounts. Vue escapes interpolation; `v-html` is the one way to lose
 * that, so there is none of it here and this is what says so.
 */
describe('the escaping of what a peer sent', () => {
	it('uses no v-html anywhere on the administration page', () => {
		const directory = resolve(process.cwd(), 'src/components/admin')
		const files = readdirSync(directory).filter((name) => name.endsWith('.vue'))

		expect(files.length).toBeGreaterThan(0)
		for (const name of files) {
			// the directive, not the word: these files talk about it in prose
			expect(readFileSync(join(directory, name), 'utf8'), `${name} renders raw markup`)
				.not.toMatch(/\sv-html\s*=/)
		}
	})

	it('renders a handle that is markup as the text it is', async () => {
		const wrapper = await mountPage({
			...STATE,
			reports: [{
				id: 1,
				account_id: 'https://spam.example/users/x',
				account: '<img src=x onerror=alert(1)>',
				reporter: 'https://cloud.example/users/alice',
				local: true,
				category: 'spam',
				comment: '<script>alert(2)</script>',
				status_ids: [],
				creation: 0,
				resolved: false,
				level: '',
			}],
			openReports: 1,
		}, '#reports')

		expect(wrapper.find('table').html()).not.toContain('<img src=x')
		expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>')
	})

	it('refuses to link an actor id that is not an address', async () => {
		const wrapper = await mountPage({
			...STATE,
			reports: [{
				id: 1,
				// an actor id is whatever the server that sent the report said
				// it was, and this one is a click away from running here
				account_id: 'javascript:alert(1)',
				account: 'spammer@spam.example',
				reporter: 'https://cloud.example/users/alice',
				local: false,
				category: 'spam',
				comment: '',
				status_ids: ['javascript:alert(2)'],
				creation: 0,
				resolved: false,
				level: '',
			}],
			openReports: 1,
		}, '#reports')

		const table = wrapper.find('table')
		const linked = table.findAll('a').map((link) => link.attributes('href'))
		expect(linked).not.toContain('javascript:alert(1)')
		expect(linked).not.toContain('javascript:alert(2)')
		// both are still shown, as the text they are
		expect(table.text()).toContain('spammer@spam.example')
		expect(table.text()).toContain('javascript:alert(2)')
	})
	/**
	 * For you is decided for the whole instance, like the features, so
	 * it sits with them — and only when the server sent its settings, which
	 * it does not for a delegate.
	 */
	it('draws the For you section next to the features when it has its settings', async () => {
		const interests = { enabled: true, learningDefault: true, halfLife: 30, threshold: 3, cap: 30, window: 7 }
		const wrapper = await mountPage({ ...STATE, interests }, '#sections')
		const ids = wrapper.findAll('.social-admin__section').map((section) => section.attributes('id'))

		expect(ids.indexOf('interests')).toBe(ids.indexOf('sections') + 1)
		expect(wrapper.findAll('.social-admin__toc-link').map((link) => link.text())).toContain('For you')

		const without = await mountPage({ ...STATE, interests: null }, '#sections')
		expect(without.find('#interests').exists()).toBe(false)
	})
})
