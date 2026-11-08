/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ReportsTable from '../../../../src/components/admin/ReportsTable.vue'

/**
 * @param {object} overrides what this row says
 * @return {object} a report, as /moderation/reports sends one
 */
function report(overrides = {}) {
	return {
		id: 1,
		account: 'jens@chaos.social',
		account_id: 'https://chaos.social/users/jens',
		reporter: 'alice@cloud.example',
		local: true,
		category: 'spam',
		comment: 'Posting the same link everywhere',
		status_ids: ['https://chaos.social/users/jens/statuses/1'],
		creation: 1_758_000_000,
		resolved: false,
		level: '',
		...overrides,
	}
}

/**
 * @param {object[]} reports the rows
 * @param {string[]} takenDown posts taken down since the page loaded
 * @return {object} the mounted table
 */
function mountTable(reports = [report()], takenDown = []) {
	return mount(ReportsTable, { props: { reports, takenDown } })
}

const rows = (wrapper) => wrapper.findAll('.reports-table__row')
function buttonByText(scope, text) {
	return scope.findAllComponents({ name: 'NcButton' }).find((button) => button.text() === text)
}

describe('the reports table', () => {
	it('draws a row per report', () => {
		expect(rows(mountTable([report({ id: 1 }), report({ id: 2 })]))).toHaveLength(2)
	})

	it('draws nothing but headings when there is nothing to moderate', () => {
		const wrapper = mountTable([])

		expect(rows(wrapper)).toHaveLength(0)
		expect(wrapper.findAll('th')).toHaveLength(9)
	})

	describe('what a row says', () => {
		it('lists the rules a reporter said were broken under the category', () => {
			const wrapper = mountTable([report({ category: 'violation', rules: ['Be kind', 'No spam'] })])

			expect(wrapper.findAll('.reports-table__rules li').map((item) => item.text())).toEqual(['Be kind', 'No spam'])
			expect(mountTable().find('.reports-table__rules').exists()).toBe(false)
		})

		it('names who was reported, by whom, and why', () => {
			const cells = mountTable().findAll('td')

			expect(cells[0].text()).toBe('jens@chaos.social')
			expect(cells[1].text()).toBe('alice@cloud.example')
			expect(cells[2].text()).toBe('spam')
			expect(cells[3].text()).toBe('Posting the same link everywhere')
		})

		/** A report from another server is weighed differently from a local one. */
		it('marks a report that came from another server', () => {
			expect(mountTable([report({ local: false })]).text()).toContain('remote')
			expect(mountTable([report({ local: true })]).text()).not.toContain('remote')
		})

		it('writes the date out in UTC, to the minute', () => {
			expect(mountTable().findAll('td')[5].text()).toBe('2025-09-16 05:20')
		})

		/** A row whose date never made it should be blank, not "1970-01-01". */
		it('leaves the date blank when there is none', () => {
			expect(mountTable([report({ creation: 0 })]).findAll('td')[5].text()).toBe('')
		})

		it('says whether the report is still open', () => {
			expect(mountTable().find('.reports-table__state').text()).toBe('Open')
			expect(mountTable([report({ resolved: true })]).find('.reports-table__state').text())
				.toBe('Resolved')
		})

		it('says what already stands against the account', () => {
			expect(mountTable([report({ level: '' })]).find('.reports-table__decision').text()).toBe('')
			expect(mountTable([report({ level: 'silence' })]).find('.reports-table__decision').text())
				.toBe('Silenced')
			expect(mountTable([report({ level: 'suspend' })]).find('.reports-table__decision').text())
				.toBe('Suspended')
		})

		/** An account this server has never resolved a handle for. */
		it('falls back to the id when there is no handle', () => {
			expect(mountTable([report({ account: '' })]).findAll('td')[0].text())
				.toBe('https://chaos.social/users/jens')
		})
	})

	/**
	 * Every id in this table is a string a remote server chose, and this is the
	 * page whose buttons delete accounts: a `javascript:` id in an `href` would
	 * be one moderator click from running here.
	 */
	describe('the addresses it will open', () => {
		it('links an account id that is plainly https', () => {
			const link = mountTable().find('a.reports-table__actor')

			expect(link.attributes('href')).toBe('https://chaos.social/users/jens')
			expect(link.attributes('rel')).toBe('noreferrer noopener')
			expect(link.attributes('target')).toBe('_blank')
		})

		it('shows anything else as the text it is', () => {
			const wrapper = mountTable([report({ account_id: 'javascript:alert(1)' })])

			expect(wrapper.find('a.reports-table__actor').exists()).toBe(false)
			expect(wrapper.findAll('td')[0].text()).toBe('jens@chaos.social')
		})

		it('does the same for the id of a reported post', () => {
			const wrapper = mountTable([report({ status_ids: ['javascript:alert(1)'] })])

			expect(wrapper.find('.reports-table__status a').exists()).toBe(false)
			expect(wrapper.find('.reports-table__status').text()).toContain('javascript:alert(1)')
		})

		/** A handle, an instance name and a comment are all remote strings too. */
		it('never renders a remote string as markup', () => {
			const wrapper = mountTable([report({
				account: '<img src=x onerror=alert(1)>',
				comment: '<script>alert(1)</script>',
			})])

			expect(wrapper.html()).not.toContain('<img src=x')
			expect(wrapper.html()).not.toContain('<script>')
			expect(wrapper.find('.reports-table__comment').text()).toBe('<script>alert(1)</script>')
		})
	})

	describe('the decisions a moderator can make', () => {
		it('asks to silence, to suspend, and to lift', () => {
			const wrapper = mountTable()

			buttonByText(wrapper, 'Silence').vm.$emit('click')
			buttonByText(wrapper, 'Suspend').vm.$emit('click')
			buttonByText(wrapper, 'Lift').vm.$emit('click')

			expect(wrapper.emitted('moderate').map(([payload]) => payload.level))
				.toEqual(['silence', 'suspend', ''])
			expect(wrapper.emitted('moderate')[0][0].report.id).toBe(1)
		})

		/** Suspending an already-suspended account says nothing new. */
		it('does not offer the decision that already stands', () => {
			expect(buttonByText(mountTable([report({ level: 'silence' })]), 'Silence').props('disabled'))
				.toBe(true)
			expect(buttonByText(mountTable([report({ level: 'suspend' })]), 'Suspend').props('disabled'))
				.toBe(true)
			expect(buttonByText(mountTable([report({ level: 'silence' })]), 'Suspend').props('disabled'))
				.toBe(false)
		})

		it('resolves an open report and reopens a resolved one', () => {
			const open = mountTable()
			buttonByText(open, 'Resolve').vm.$emit('click')
			expect(open.emitted('resolve')[0][0].id).toBe(1)

			const resolved = mountTable([report({ resolved: true })])
			expect(buttonByText(resolved, 'Reopen')).toBeTruthy()
		})

		it('takes a reported post down, naming which one', () => {
			const wrapper = mountTable()

			buttonByText(wrapper, 'Take down').vm.$emit('click')

			expect(wrapper.emitted('takedown')[0][0].statusId)
				.toBe('https://chaos.social/users/jens/statuses/1')
		})

		/** Once it is down there is nothing left to press, and it says so. */
		it('says a post is down instead of offering to take it down again', () => {
			const wrapper = mountTable(
				[report()],
				['https://chaos.social/users/jens/statuses/1'],
			)

			expect(wrapper.find('.reports-table__status').text()).toBe('Taken down')
			expect(buttonByText(wrapper, 'Take down')).toBeUndefined()
		})

		it('offers each reported post separately', () => {
			const wrapper = mountTable([report({ status_ids: ['https://a.example/1', 'https://a.example/2'] })])

			expect(wrapper.findAll('.reports-table__status')).toHaveLength(2)
		})
	})
})
