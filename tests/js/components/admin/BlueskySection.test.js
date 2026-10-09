/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BlueskySection from '../../../../src/components/admin/BlueskySection.vue'

const { get, post, del } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), del: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post, delete: del } }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../../src/services/toast.js', () => ({ showError, showSuccess }))

const ROUTE = '/index.php/apps/social/admin/bluesky'
const stubs = { NcSettingsSection: { template: '<section><slot /></section>' } }

/**
 * @param {object} overrides what differs from a healthy, switched-on server
 * @return {object} what `AtprotoStatusService::current()` answers
 */
function admin(overrides = {}) {
	return {
		settings: {
			enabled: true,
			relays: ['https://bsky.network'],
			plc_directory: 'https://plc.directory',
			appview: 'https://public.api.bsky.app',
			jetstream: '',
			sync_ceiling: 200,
			trusted_clients: [],
			...(overrides.settings ?? {}),
		},
		status: {
			handle_host: 'cloud.example.org',
			pds_endpoint: 'https://cloud.example.org',
			service_did: 'did:web:cloud.example.org',
			identities: 12,
			repositories: 11,
			events_in_window: 340,
			head_seq: 9001,
			rotation_key_age: 3,
			daemon: { running: true, pid: 4242, started: Math.floor(Date.now() / 1000) - 7200, seen: 0, head: 9001, subscribers: 2 },
			...(overrides.status ?? {}),
		},
		checks: overrides.checks ?? [
			{ id: 'https', state: 'ok', detail: '' },
			{ id: 'trusted_domains', state: 'ok', detail: '' },
			{ id: 'daemon', state: 'ok', detail: '' },
		],
	}
}

/**
 * @param {object} settings what the server provided
 * @return {object} the mounted card
 */
function mountCard(settings = admin()) {
	return mount(BlueskySection, { props: { settings }, global: { stubs } })
}
const toggle = (wrapper) => wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
const buttonByText = (wrapper, text) => wrapper.findAll('button').find((button) => button.text() === text)
const checks = (wrapper) => wrapper.findAll('.bluesky__check')
/**
 * @param {object} wrapper the mounted card
 * @param {string} label the field's label
 * @return {object|undefined} the NcTextField carrying it
 */
function fieldByLabel(wrapper, label) {
	return wrapper.findAllComponents({ name: 'NcTextField' }).find((field) => field.props('label') === label)
}

describe('the Bluesky card', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: admin() })
		post.mockReset().mockResolvedValue({ data: admin() })
		del.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
	})

	it('shows the switch where the server stands', () => {
		expect(toggle(mountCard()).props('modelValue')).toBe(true)
		expect(toggle(mountCard(admin({ settings: { enabled: false } }))).props('modelValue')).toBe(false)
	})

	it('lists what it needs, each with how it stands', () => {
		const wrapper = mountCard(admin({
			checks: [
				{ id: 'https', state: 'ok', detail: '' },
				{ id: 'handle_host', state: 'warning', detail: 'No identity yet to resolve' },
				{ id: 'firehose', state: 'error', detail: 'the web server must proxy the WebSocket to the daemon' },
			],
		}))

		expect(checks(wrapper).map((row) => row.classes().find((c) => c.startsWith('bluesky__check--'))))
			.toEqual(['bluesky__check--ok', 'bluesky__check--warning', 'bluesky__check--error'])
		expect(wrapper.text()).toContain('HTTPS')
		expect(wrapper.text()).toContain('Handle hosts resolve')
		expect(wrapper.text()).toContain('No identity yet to resolve')
		expect(wrapper.text()).toContain('the web server must proxy the WebSocket to the daemon')
	})

	it('says so when nothing has been checked yet', () => {
		const wrapper = mountCard(admin({ checks: [] }))

		expect(checks(wrapper)).toHaveLength(0)
		expect(wrapper.text()).toContain('Nothing has been checked yet')
	})

	it('shows the facts an administrator has to copy elsewhere', () => {
		const text = mountCard().text()

		expect(text).toContain('cloud.example.org')
		expect(text).toContain('https://cloud.example.org')
		expect(text).toContain('did:web:cloud.example.org')
	})

	it('shows the numbers, the key age in days and the daemon in one line', () => {
		const text = mountCard().text()

		expect(text).toContain('12')
		expect(text).toContain('340')
		expect(text).toContain('9001')
		expect(text).toContain('3 days')
		expect(text).toContain('Running as pid 4242 for 2 hours, serving 2 subscribers')
	})

	it('shows the reading side, with each lag as a duration or as caught up', () => {
		const text = mountCard(admin({ status: { reading: { watches: 7, lag: 0, accounts: 3, lag_notifications: 5400 } } })).text()

		expect(text).toContain('Reading Bluesky')
		expect(text).toContain('Authors followed')
		expect(text).toContain('7')
		expect(text).toContain('Accounts asking for notifications')
		expect(text).toContain('3')
		expect(text).toContain('up to date')
		expect(text).toContain('2 hours behind')
		expect(mountCard().text()).not.toContain('Reading Bluesky')
	})

	it('says plainly when the daemon is not running', () => {
		const wrapper = mountCard(admin({ status: { daemon: null } }))

		expect(wrapper.find('.bluesky__daemon--down').text()).toBe('Not running')
	})

	/**
	 * The switch is the one field whose answer can be "no", so it is written
	 * the moment it is pressed rather than with the Save button.
	 */
	describe('the switch', () => {
		it('writes itself at once, and follows what the server answered', async () => {
			post.mockResolvedValue({ data: admin({ settings: { enabled: true }, checks: [{ id: 'https', state: 'ok', detail: '' }] }) })
			const wrapper = mountCard(admin({ settings: { enabled: false }, checks: [] }))

			toggle(wrapper).vm.$emit('update:modelValue', true)
			await flushPromises()

			expect(post).toHaveBeenCalledWith(ROUTE, { enabled: true })
			expect(toggle(wrapper).props('modelValue')).toBe(true)
			expect(checks(wrapper)).toHaveLength(1)
			expect(showSuccess).toHaveBeenCalled()
		})

		it('falls back when the server refuses, and shows the checks it refused on', async () => {
			post.mockRejectedValue({
				response: {
					status: 422,
					data: {
						...admin({
							settings: { enabled: false },
							checks: [{ id: 'daemon', state: 'error', detail: 'occ social:atproto:serve is not running' }],
						}),
						error: 'Bluesky cannot be switched on while a requirement fails',
					},
				},
			})
			const wrapper = mountCard(admin({ settings: { enabled: false }, checks: [] }))

			toggle(wrapper).vm.$emit('update:modelValue', true)
			await flushPromises()

			expect(toggle(wrapper).props('modelValue')).toBe(false)
			expect(wrapper.findComponent({ name: 'NcNoteCard' }).text())
				.toBe('Bluesky cannot be switched on while a requirement fails')
			expect(wrapper.text()).toContain('occ social:atproto:serve is not running')
			expect(showError).toHaveBeenCalledWith('Bluesky cannot be switched on while a requirement fails')
		})
	})

	describe('the fields', () => {
		it('has nothing to save until something changes', () => {
			expect(buttonByText(mountCard(), 'Save').attributes('disabled')).toBeDefined()
		})

		it('sends only what changed, in the route\'s names', async () => {
			const wrapper = mountCard()
			await fieldByLabel(wrapper, 'Jetstream').find('input').setValue('wss://jetstream2.us-east.bsky.network')
			await fieldByLabel(wrapper, 'Sync ceiling').find('input').setValue('500')
			await buttonByText(wrapper, 'Save').trigger('click')
			await flushPromises()

			expect(post).toHaveBeenCalledWith(ROUTE, {
				jetstream: 'wss://jetstream2.us-east.bsky.network',
				sync_ceiling: 500,
			})
		})

		it('sends the relays as a list, one address per line, blank lines gone', async () => {
			const wrapper = mountCard()
			await wrapper.findComponent({ name: 'NcTextArea' }).find('textarea')
				.setValue('https://bsky.network\n\n  https://relay.example  \n')
			await buttonByText(wrapper, 'Save').trigger('click')
			await flushPromises()

			expect(post).toHaveBeenCalledWith(ROUTE, { relays: ['https://bsky.network', 'https://relay.example'] })
		})

		it('sends the trusted apps as a list of client IDs', async () => {
			const wrapper = mountCard()
			await wrapper.findAllComponents({ name: 'NcTextArea' })[1].find('textarea')
				.setValue('https://bsky.app/oauth-client-metadata.json\n\n')
			await buttonByText(wrapper, 'Save').trigger('click')
			await flushPromises()

			expect(post).toHaveBeenCalledWith(ROUTE, { trusted_clients: ['https://bsky.app/oauth-client-metadata.json'] })
		})

		it('shows what the server would not take', async () => {
			post.mockRejectedValue({ response: { status: 422, data: { ...admin(), error: 'That is not a relay address' } } })
			const wrapper = mountCard()
			await fieldByLabel(wrapper, 'PLC directory').find('input').setValue('nonsense')
			await buttonByText(wrapper, 'Save').trigger('click')
			await flushPromises()

			expect(wrapper.findComponent({ name: 'NcNoteCard' }).text()).toBe('That is not a relay address')
		})
	})

	it('re-runs the checks on request', async () => {
		get.mockResolvedValue({ data: admin({ checks: [{ id: 'https', state: 'error', detail: 'plain http' }] }) })
		const wrapper = mountCard()
		await buttonByText(wrapper, 'Re-check').trigger('click')
		await flushPromises()

		expect(get).toHaveBeenCalledWith(ROUTE)
		expect(checks(wrapper)).toHaveLength(1)
		expect(wrapper.text()).toContain('plain http')
	})

	describe('telling the relays', () => {
		it('asks each saved relay to crawl, and says what each answered', async () => {
			post.mockResolvedValue({ data: { results: { 'https://bsky.network': 'ok', 'https://relay.example': 'connection refused' } } })
			const wrapper = mountCard()
			await buttonByText(wrapper, 'Tell the relays now').trigger('click')
			await flushPromises()

			expect(post).toHaveBeenCalledWith(ROUTE + '/crawl')
			const rows = wrapper.findAll('.bluesky__crawl-results li')
			expect(rows.map((row) => row.findAll('span').map((cell) => cell.text())))
				.toEqual([['https://bsky.network', 'told'], ['https://relay.example', 'connection refused']])
			expect(rows[1].classes()).toContain('bluesky__crawl-result--refused')
		})

		/** The relays told are the saved ones; an unsaved list would mislead. */
		it('waits for the list to be saved first', async () => {
			const wrapper = mountCard()
			await wrapper.findComponent({ name: 'NcTextArea' }).find('textarea').setValue('https://other.example')

			expect(buttonByText(wrapper, 'Tell the relays now').attributes('disabled')).toBeDefined()
			expect(wrapper.text()).toContain('Save the list first')
		})
	})

	describe('the block list', () => {
		const spammer = { kind: 'did', value: 'did:plc:spammer', reason: 'reply spam', creation: 1790000000 }
		const badHost = { kind: 'host', value: 'pds.bad.example', reason: '', creation: 1790000100 }
		const blockRows = (wrapper) => wrapper.findAll('.bluesky__block')
		const blockForm = (wrapper) => wrapper.find('.bluesky__block-form')

		it('lists what is blocked, each with its kind and reason', () => {
			const wrapper = mountCard(admin({ status: { blocks: [spammer, badHost] } }))

			expect(blockRows(wrapper).map((row) => [
				row.find('.bluesky__block-kind').text(),
				row.find('.bluesky__block-value').text(),
				row.find('.bluesky__block-reason').exists() ? row.find('.bluesky__block-reason').text() : '',
			])).toEqual([
				['Account', 'did:plc:spammer', 'reply spam'],
				['Data server', 'pds.bad.example', ''],
			])
		})

		it('says so when nothing is blocked', () => {
			const wrapper = mountCard()

			expect(blockRows(wrapper)).toHaveLength(0)
			expect(wrapper.text()).toContain('Nothing is blocked.')
		})

		it('blocks a target with its reason, and says how many follows went with it', async () => {
			post.mockResolvedValue({ data: { blocks: [spammer], purged: 3 } })
			const wrapper = mountCard()

			await fieldByLabel(wrapper, 'DID or host to block').find('input').setValue(' did:plc:spammer ')
			await fieldByLabel(wrapper, 'Reason (optional)').find('input').setValue('reply spam')
			await blockForm(wrapper).trigger('submit')
			await flushPromises()

			expect(post).toHaveBeenCalledWith(ROUTE + '/blocks', { target: 'did:plc:spammer', reason: 'reply spam' })
			expect(blockRows(wrapper)).toHaveLength(1)
			expect(wrapper.text()).toContain('3 followed accounts removed')
			expect(fieldByLabel(wrapper, 'DID or host to block').find('input').element.value).toBe('')
		})

		it('says nothing about follows when none went', async () => {
			post.mockResolvedValue({ data: { blocks: [badHost], purged: 0 } })
			const wrapper = mountCard()

			await fieldByLabel(wrapper, 'DID or host to block').find('input').setValue('pds.bad.example')
			await blockForm(wrapper).trigger('submit')
			await flushPromises()

			expect(wrapper.text()).not.toContain('followed account')
		})

		it('shows why the server would not take a target, beside the form', async () => {
			post.mockRejectedValue({ response: { status: 422, data: { error: 'not a DID or a host' } } })
			const wrapper = mountCard()

			await fieldByLabel(wrapper, 'DID or host to block').find('input').setValue('nonsense here')
			await blockForm(wrapper).trigger('submit')
			await flushPromises()

			expect(wrapper.find('.bluesky__block-error').text()).toBe('not a DID or a host')
			expect(fieldByLabel(wrapper, 'DID or host to block').find('input').element.value).toBe('nonsense here')
		})

		it('takes a target off the list', async () => {
			del.mockResolvedValue({ data: { blocks: [badHost] } })
			const wrapper = mountCard(admin({ status: { blocks: [spammer, badHost] } }))

			await buttonByText(blockRows(wrapper)[0], 'Remove').trigger('click')
			await flushPromises()

			expect(del).toHaveBeenCalledWith(ROUTE + '/blocks', { data: { target: 'did:plc:spammer' } })
			expect(blockRows(wrapper).map((row) => row.find('.bluesky__block-value').text())).toEqual(['pds.bad.example'])
		})
	})
})
