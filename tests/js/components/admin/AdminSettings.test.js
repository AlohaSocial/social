/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { readFileSync, readdirSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
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

/**
 * @param {object} state what the server provided
 * @return {Promise<object>} the mounted page
 */
async function mountPage(state) {
	// loadState memoises what it read on the window; a second page in the same
	// test file would otherwise get the first one's state
	delete globalThis._nc_initial_state
	globalThis.setInitialState('social', 'adminSettings', state)
	const wrapper = mount(AdminSettings)
	await flushPromises()

	return wrapper
}

describe('the administration page', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.get.mockResolvedValue({ data: { accounts: [], cursors: [], announcements: [] } })
	})

	/**
	 * Seventeen cards in one run is a list nobody reads to the end of, so they
	 * are grouped by what they are for. The order inside a group is the order
	 * they were in; the groups are what changed.
	 */
	it('is the nineteen cards, grouped by what they are for', async () => {
		const wrapper = await mountPage(STATE)
		const headings = wrapper.findAll('h2').map((heading) => heading.text())

		expect(headings).toEqual([
			'Activity here',
			'Reports',
			'Posts waiting to be looked at',
			'Accounts',
			'Refused pictures',
			'The rules of this server',
			'What this server is about',
			'What may trend',
			'Custom emoji',
			'Announcements',
			'Sections',
			'Retention',
			'Storage',
			'Federation health',
			'Background work',
			'Fediverse access',
			'Block lists',
			'Relays',
			'Server',
		])
	})

	it('names every group, and gives every card an anchor of its own', async () => {
		const wrapper = await mountPage(STATE)

		expect(wrapper.findAll('.social-admin__group-name').map((h) => h.text()))
			.toEqual(['Overview', 'Moderation', 'What people see', 'What is kept', 'Federation', 'Server'])
		// the rail is the same list, so it cannot drift from the page
		expect(wrapper.findAll('.social-admin__rail-link')).toHaveLength(19)
		expect(wrapper.findAll('.social-admin__card').map((card) => card.attributes('id')))
			.toEqual(wrapper.findAll('.social-admin__rail-link').map((link) => link.attributes('href').slice(1)))
	})

	/**
	 * A delegate moderates; they do not administer. `AdminSettings` sends no
	 * server settings to one, so there is nothing for the card to draw.
	 */
	it('leaves the Server card out for a delegated administrator', async () => {
		const wrapper = await mountPage({ ...STATE, server: null, sections: null, groups: null })
		const headings = wrapper.findAll('h2').map((heading) => heading.text())

		expect(headings).not.toContain('Server')
		// nor the relays, which change what every federated timeline here
		// holds and where every public post written here is sent
		expect(headings).not.toContain('Relays')
		// nor what the app offers everybody, which is a decision about the
		// instance rather than about a report
		expect(headings).not.toContain('Sections')
		// and the fourteen cards a delegate does hold are all still there
		expect(wrapper.findAll('h2')).toHaveLength(15)
		// with the group that has nothing left in it drawing no heading
		expect(wrapper.findAll('.social-admin__group-name').map((h) => h.text()))
			.not.toContain('Server')
	})

	/** Who may have an account here is an administrator's decision; a delegate is sent none of it. */
	it('draws the External users group above moderation when the server sends it', async () => {
		const external = {
			settings: { enabled: false, max: 100, quota: 1024, mode: 'approval', verifyEmail: true, minAge: 16, reserved: [], userInvites: false, signupNotice: '', count: 0, awaitingApproval: 0, restricted: false, restrictionIncludesExternals: true },
			twoFactor: { enforced: false, everybody: false },
			requests: [],
			invites: [],
			users: [],
		}
		const wrapper = await mountPage({ ...STATE, external })

		expect(wrapper.findAll('.social-admin__group-name').map((h) => h.text()).slice(0, 3))
			.toEqual(['Overview', 'External users', 'Moderation'])
		expect(wrapper.findAll('h2').map((heading) => heading.text()).slice(1, 4))
			.toEqual(['External users', 'Registrations and invitations', 'External accounts'])

		const without = await mountPage(STATE)
		expect(without.findAll('.social-admin__group-name').map((h) => h.text())).not.toContain('External users')
	})

	it('draws a page the server told nothing about without breaking', async () => {
		const wrapper = await mountPage({})

		expect(wrapper.text()).toContain('No open reports.')
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
		})

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
		})

		const table = wrapper.find('table')
		const linked = table.findAll('a').map((link) => link.attributes('href'))
		expect(linked).not.toContain('javascript:alert(1)')
		expect(linked).not.toContain('javascript:alert(2)')
		// both are still shown, as the text they are
		expect(table.text()).toContain('spammer@spam.example')
		expect(table.text()).toContain('javascript:alert(2)')
	})
	/**
	 * For you is decided for the whole instance, like the sections, so
	 * it sits with them — and only when the server sent its settings, which
	 * it does not for a delegate.
	 */
	it('draws the For you card next to the sections when it has its settings', async () => {
		const interests = { enabled: true, learningDefault: true, halfLife: 30, threshold: 3, cap: 30, window: 7 }
		const wrapper = await mountPage({ ...STATE, interests })
		const ids = wrapper.findAll('.social-admin__card').map((card) => card.attributes('id'))

		expect(ids.indexOf('interests')).toBe(ids.indexOf('sections') + 1)
		expect(wrapper.findAll('.social-admin__rail-link').map((link) => link.text())).toContain('For you')

		const without = await mountPage({ ...STATE, interests: null })
		expect(without.find('#interests').exists()).toBe(false)
	})
})
