/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '../../../src/services/toast.js'

import Migration from '../../../src/components/MigrationSettings.vue'
import { confirmPassword } from '../../../src/services/externalApi.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/externalApi.js', () => ({ confirmPassword: vi.fn().mockResolvedValue(undefined) }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

function mountPage() {
	return mount(Migration, { attachTo: document.body })
}

const buttonNamed = (wrapper, text) => wrapper.findAll('button').find((b) => b.text() === text)

/** An import as the server lists it. */
function job(kind, status, counts = {}, report = {}) {
	return { id: 7, kind, status, total: 0, done: 0, skipped: 0, failed: 0, report, options: {}, ...counts }
}

/**
 * The server queues an upload and answers the import; the list is what says
 * what came of it. `done` is what the poll answers.
 */
function serverQueues(kind, done) {
	axios.post.mockResolvedValue({ data: { import: job(kind, 'queued') } })
	axios.get.mockImplementation((url) => {
		if (url.endsWith('/migration/imports')) {
			return Promise.resolve({ data: { imports: done ? [done] : [] } })
		}
		return Promise.resolve({ data: { aliases: [] } })
	})
}

/** A file input cannot be filled by hand, so the change is dispatched with one. */
async function choose(wrapper, ref, name = 'social-alice.zip') {
	const input = wrapper.vm.$refs[ref]
	const file = new File(['zip bytes'], name)
	Object.defineProperty(input, 'files', { value: [file], configurable: true })
	await input.dispatchEvent(new Event('change'))
	await flushPromises()

	return file
}

describe('Migration', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.useFakeTimers()
		document.body.innerHTML = ''
		// the page asks for the aliases and the imports when it opens
		axios.get.mockResolvedValue({ data: { aliases: [], imports: [] } })
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	it('says what the page is for', () => {
		const wrapper = mountPage()

		expect(wrapper.text()).toContain('Export your data')
		expect(wrapper.text()).toContain('Import an archive')
		expect(wrapper.text()).toContain('Coming from another network')
	})

	/**
	 * The inputs are off screen but not hidden: left in the tab order they
	 * are six unnamed "file upload" stops between the buttons that open them.
	 */
	it('leaves only the buttons for a keyboard to reach, not the file inputs behind them', () => {
		const wrapper = mountPage()
		const inputs = wrapper.findAll('input[type="file"]')

		expect(inputs).toHaveLength(8)
		for (const input of inputs) {
			expect(input.attributes('tabindex')).toBe('-1')
			expect(input.attributes('aria-hidden')).toBe('true')
		}
	})

	// export

	it('asks the server for the archive and saves it under the name it was given', async () => {
		const blob = new Blob(['zip bytes'])
		axios.get.mockResolvedValue({
			data: blob,
			headers: { 'content-disposition': 'attachment; filename="social-alice-2026-09-13.zip"' },
		})
		// the click is stubbed rather than `createElement`: the component is
		// rendered with the same function, so a mocked one would hand Vue the
		// anchor as well and the mount would fall apart
		let savedAs = ''
		const click = vi.fn(function saved() {
			savedAs = this.getAttribute('download')
		})
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(click)
		URL.createObjectURL = vi.fn(() => 'blob:archive')
		URL.revokeObjectURL = vi.fn()

		const wrapper = mountPage()
		await wrapper.find('.migration__card:not(.migration__card--lead) button').trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${API}/migration/export`, { responseType: 'blob' })
		expect(click).toHaveBeenCalled()
		expect(savedAs).toBe('social-alice-2026-09-13.zip')
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:archive')
		expect(showSuccess).toHaveBeenCalled()
	})

	it('says so when the export fails rather than downloading nothing', async () => {
		axios.get.mockRejectedValue(new Error('no room on the disk'))

		const wrapper = mountPage()
		await wrapper.find('.migration__card:not(.migration__card--lead) button').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not export your data')
	})

	// import

	it('uploads the chosen archive and shows what the import did', async () => {
		axios.post.mockResolvedValue({ data: { imported: true, log: ['Importing the Social profile…'] } })

		const wrapper = mountPage()
		await choose(wrapper, 'archive')

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/import`, expect.any(FormData))
		expect(wrapper.find('.migration__log').text()).toContain('Importing the Social profile…')
		expect(showSuccess).toHaveBeenCalled()
	})

	/** The server names what was wrong — the wrong zip, most likely — so say that. */
	it('shows the reason the server gave for refusing an archive', async () => {
		axios.post.mockRejectedValue({ response: { data: { error: 'this archive holds no Social data' } } })

		const wrapper = mountPage()
		await choose(wrapper, 'archive')

		expect(showError).toHaveBeenCalledWith('this archive holds no Social data')
	})

	/** Choosing the same file twice fires no change unless the input is cleared. */
	it('clears the file input so the same archive can be chosen again', async () => {
		axios.post.mockResolvedValue({ data: { imported: true, log: [] } })

		const wrapper = mountPage()
		await choose(wrapper, 'archive')

		expect(wrapper.vm.$refs.archive.value).toBe('')
	})

	// follows from another network

	// moving away

	/** The page's reads, with the move status as given. */
	function serverSays(status) {
		axios.get.mockImplementation((url) => {
			if (url.endsWith('/migration/move')) {
				return Promise.resolve({ data: status })
			}
			if (url.endsWith('/migration/announcement')) {
				return Promise.resolve({ data: { handle: '@alice@cloud.example' } })
			}
			return Promise.resolve({ data: { aliases: [], imports: [] } })
		})
	}

	it('moves the account away after the password and the typed handle, and shows where it went', async () => {
		serverSays({ moved_to: '', moved_at: null, can_move_at: 0 })
		axios.post.mockResolvedValue({ data: { moved_to: 'https://new.example/users/alice', moved_at: 1700000000, can_move_at: 1702592000, target: { acct: 'alice@new.example' } } })

		const wrapper = mountPage()
		await flushPromises()
		const fields = wrapper.findAll('.migration__move-out-field input')
		await fields[0].setValue('@alice@new.example')
		await fields[1].setValue('alice@cloud.example')
		await buttonNamed(wrapper, 'Move my followers to the new account').trigger('click')
		await flushPromises()

		expect(confirmPassword).toHaveBeenCalled()
		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/move`, { target: '@alice@new.example', confirm: 'alice@cloud.example' })
		expect(wrapper.text()).toContain('This account moved to https://new.example/users/alice')
		expect(buttonNamed(wrapper, 'Undo the move')).toBeTruthy()
	})

	it('does nothing when the password dialog is dismissed', async () => {
		serverSays({ moved_to: '', moved_at: null, can_move_at: 0 })
		confirmPassword.mockRejectedValueOnce(new Error('dismissed'))

		const wrapper = mountPage()
		await flushPromises()
		const fields = wrapper.findAll('.migration__move-out-field input')
		await fields[0].setValue('@alice@new.example')
		await fields[1].setValue('alice@cloud.example')
		await buttonNamed(wrapper, 'Move my followers to the new account').trigger('click')
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalledWith(`${API}/migration/move`, expect.anything())
	})

	it('shows the reason the server refuses a move', async () => {
		serverSays({ moved_to: '', moved_at: null, can_move_at: 0 })
		axios.post.mockRejectedValue({ response: { data: { error: 'type your own handle, alice@cloud.example, to confirm the move' } } })

		const wrapper = mountPage()
		await flushPromises()
		const fields = wrapper.findAll('.migration__move-out-field input')
		await fields[0].setValue('@alice@new.example')
		await fields[1].setValue('somebody@else.example')
		await buttonNamed(wrapper, 'Move my followers to the new account').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('type your own handle, alice@cloud.example, to confirm the move')
	})

	it('offers to undo a move, and says the followers do not come back', async () => {
		serverSays({ moved_to: 'https://new.example/users/alice', moved_at: 1700000000, can_move_at: 1702592000 })
		axios.delete.mockResolvedValue({ data: { moved_to: '', moved_at: 1700000000, can_move_at: 1702592000 } })

		const wrapper = mountPage()
		await flushPromises()
		expect(wrapper.text()).toContain('do not come back by themselves')
		await buttonNamed(wrapper, 'Undo the move').trigger('click')
		await flushPromises()

		expect(confirmPassword).toHaveBeenCalled()
		expect(axios.delete).toHaveBeenCalledWith(`${API}/migration/move`)
		expect(buttonNamed(wrapper, 'Undo the move')).toBeUndefined()
	})

	it('says when the account may move again, instead of offering the form', async () => {
		serverSays({ moved_to: '', moved_at: 1700000000, can_move_at: Math.floor(Date.now() / 1000) + 86400 })

		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.text()).toContain('can move again on')
		expect(wrapper.find('.migration__move-out-field').exists()).toBe(false)
	})

	// moving in, from the old handle

	const oldAccount = (following, posts) => ({
		id: 'https://old.example/users/alice',
		acct: 'alice@old.example',
		name: 'Alice',
		url: 'https://old.example/@alice',
		avatar: '',
		following,
		posts,
	})
	it('looks the old account up and says what its server lets us read', async () => {
		axios.post.mockResolvedValue({ data: { account: oldAccount({ total: 120, readable: true }, { total: 900, readable: false }) } })

		const wrapper = mountPage()
		await wrapper.find('.migration__move-in-field input').setValue('@alice@old.example')
		await buttonNamed(wrapper, 'Look it up').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/move-in/inspect`, { handle: '@alice@old.example' })
		expect(wrapper.text()).toContain('Alice')
		expect(wrapper.text()).toContain('It follows 120 accounts.')
		expect(wrapper.text()).toContain('Its posts cannot be read from here')
	})

	it('starts the move with what can be read, and names the last step once it has run', async () => {
		let moved = false
		axios.post.mockImplementation((url) => {
			if (url.endsWith('/move-in/inspect')) {
				return Promise.resolve({ data: { account: oldAccount({ total: 3, readable: true }, { total: 2, readable: true }) } })
			}
			moved = true
			return Promise.resolve({ data: { import: job('move_in', 'queued') } })
		})
		axios.get.mockImplementation((url) => {
			if (url.endsWith('/migration/imports')) {
				return Promise.resolve({ data: { imports: moved ? [job('move_in', 'done', { done: 5 }, { followed: 3, imported: 2 })] : [] } })
			}
			if (url.endsWith('/migration/announcement')) {
				return Promise.resolve({ data: { handle: '@alice@cloud.example' } })
			}
			return Promise.resolve({ data: { aliases: [] } })
		})

		const wrapper = mountPage()
		await wrapper.find('.migration__move-in-field input').setValue('@alice@old.example')
		await buttonNamed(wrapper, 'Look it up').trigger('click')
		await flushPromises()
		await buttonNamed(wrapper, 'Move here').trigger('click')
		await flushPromises()
		await vi.runOnlyPendingTimersAsync()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/move-in`, { handle: '@alice@old.example', follows: '1', posts: '1', fetch_media: '1' })
		expect(wrapper.text()).toContain('3 accounts followed and 2 posts brought over')
		expect(wrapper.find('.migration__move-in-finish').text()).toContain('@alice@cloud.example')
	})

	it('shows the reason when the old account cannot be found', async () => {
		axios.post.mockRejectedValue({ response: { data: { error: 'no account answers to alice@gone.example' } } })

		const wrapper = mountPage()
		await wrapper.find('.migration__move-in-field input').setValue('alice@gone.example')
		await buttonNamed(wrapper, 'Look it up').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('no account answers to alice@gone.example')
	})

	it('queues a follows CSV and shows what the import came to', async () => {
		serverQueues('follows', job('follows', 'done', { done: 12, skipped: 1, failed: 1 }))

		const wrapper = mountPage()
		await choose(wrapper, 'follows', 'following_accounts.csv')
		await vi.runOnlyPendingTimersAsync()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/follows`, expect.any(FormData))
		expect(showSuccess).toHaveBeenCalled()
		expect(wrapper.find('.migration__result').text()).toBe('12 followed, 1 skipped, 1 could not be reached')
	})

	it('shows where a running import has got to, and asks again while it runs', async () => {
		serverQueues('follows', job('follows', 'running', { total: 500, done: 120 }))

		const wrapper = mountPage()
		await choose(wrapper, 'follows', 'following_accounts.csv')
		await vi.runOnlyPendingTimersAsync()
		await flushPromises()

		expect(wrapper.find('.migration__result').text()).toBe('120 of 500 …')
		const polls = axios.get.mock.calls.filter(([url]) => url.endsWith('/migration/imports')).length
		await vi.runOnlyPendingTimersAsync()
		await flushPromises()
		expect(axios.get.mock.calls.filter(([url]) => url.endsWith('/migration/imports')).length).toBeGreaterThan(polls)
	})

	it('refuses to queue the same kind twice and says so', async () => {
		axios.post.mockRejectedValue({ response: { status: 409, data: { error: 'an import of this kind is already running; wait for it to finish' } } })

		const wrapper = mountPage()
		await choose(wrapper, 'follows', 'following_accounts.csv')

		expect(showError).toHaveBeenCalledWith('an import of this kind is already running; wait for it to finish')
	})

	it('dismisses a finished import through its own route', async () => {
		serverQueues('follows', job('follows', 'done', { done: 2 }))
		axios.delete.mockResolvedValue({ data: { imports: [] } })

		const wrapper = mountPage()
		await flushPromises()
		expect(wrapper.find('.migration__import').exists()).toBe(true)
		await wrapper.find('.migration__import button').trigger('click')
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith(`${API}/migration/imports/7`)
		expect(wrapper.find('.migration__import').exists()).toBe(false)
	})

	it('takes Pixelfed\'s JSON export too, since that is the only follows file Pixelfed writes', async () => {
		serverQueues('follows', job('follows', 'done', { done: 3 }))

		const wrapper = mountPage()
		expect(wrapper.find('input[ref="follows"], input[accept*="json"]').exists()).toBe(true)
		await choose(wrapper, 'follows', 'pixelfed-following.json')
		await vi.runOnlyPendingTimersAsync()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/follows`, expect.any(FormData))
		expect(wrapper.find('.migration__result').text()).toContain('3')
		expect(wrapper.text()).toContain('pixelfed-following.json')
	})

	// the posts themselves, which is what moving has never carried

	it('queues an export, says it may fetch the pictures, and shows what the import came to', async () => {
		serverQueues('posts', job('posts', 'done', { done: 240, skipped: 12, failed: 1 }, { media: 96 }))

		const wrapper = mountPage()
		await choose(wrapper, 'posts', 'outbox.json')
		await vi.runOnlyPendingTimersAsync()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/posts`, expect.any(FormData))
		const form = axios.post.mock.calls[0][1]
		expect(form.get('fetch_media')).toBe('1')
		const result = wrapper.findAll('.migration__result').at(-1).text()
		expect(result).toContain('240')
		expect(result).toContain('96')
		expect(result).toContain('12')
	})

	it('sends the fetch off when the reader turns it off', async () => {
		serverQueues('posts', null)

		const wrapper = mountPage()
		await wrapper.find('.migration__media-switch input').setValue(false)
		await choose(wrapper, 'posts', 'pixelfed-statuses.json')

		expect(axios.post.mock.calls[0][1].get('fetch_media')).toBe('0')
	})

	it('shows the reason when a run failed', async () => {
		serverQueues('posts', job('posts', 'failed', {}, { error: 'this archive has no outbox.json' }))

		const wrapper = mountPage()
		await choose(wrapper, 'posts', 'outbox.json')
		await vi.runOnlyPendingTimersAsync()
		await flushPromises()

		expect(wrapper.findAll('.migration__result').at(-1).text()).toContain('this archive has no outbox.json')
	})

	it('says so when the export cannot be read', async () => {
		axios.post.mockRejectedValue({ response: { data: { error: 'this file is not the JSON an export is written in' } } })

		const wrapper = mountPage()
		await choose(wrapper, 'posts', 'notes.txt')

		expect(showError).toHaveBeenCalledWith('this file is not the JSON an export is written in')
	})

	it('says so when the follows cannot be read', async () => {
		axios.post.mockRejectedValue({ response: { data: { error: 'that is not a CSV' } } })

		const wrapper = mountPage()
		await choose(wrapper, 'follows', 'notes.txt')

		expect(showError).toHaveBeenCalledWith('that is not a CSV')
	})

	/**
	 * What a person actually needs to know: where the file is in each of the
	 * other apps, and that the ones which do not federate cannot be imported.
	 */
	it('says where to find the file in the other networks', () => {
		const text = mountPage().text()

		for (const network of ['Mastodon', 'Pixelfed', 'GoToSocial', 'Bluesky']) {
			expect(text).toContain(network)
		}
	})

	/** A move federates and cannot be undone, so it is not a button here. */
	/**
	 * Naming the old account federates nothing and is the person's own to do;
	 * moving the followers cannot be taken back, and stays an administrator's.
	 */
	it('offers the alias and the move itself, with no occ command to ask for', () => {
		const text = mountPage().text()

		expect(text).toContain('Accounts you also answer to')
		expect(text).toContain('Move your account away')
		expect(text).not.toContain('social:account:move')
		expect(text).not.toContain('social:account:alias')
	})

	it('reads the aliases when the page opens', async () => {
		axios.get.mockResolvedValue({ data: { aliases: ['https://pixelfed.social/users/you'] } })
		const wrapper = mountPage()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/migration/aliases')
		expect(wrapper.text()).toContain('https://pixelfed.social/users/you')
	})

	it('adds one and shows the list the server answers with', async () => {
		const wrapper = mountPage()
		await flushPromises()
		axios.post.mockResolvedValue({ data: { aliases: ['https://old.example/users/me'] } })

		wrapper.vm.aliasInput = 'https://old.example/users/me'
		await wrapper.vm.addAlias()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/social/api/v1/migration/aliases',
			{ alias: 'https://old.example/users/me' },
		)
		expect(wrapper.text()).toContain('https://old.example/users/me')
		// the box is cleared, so a second press cannot add the same one twice
		expect(wrapper.vm.aliasInput).toBe('')
	})

	// the other three lists, in and out

	it('queues a blocks CSV through its own route and reports what it came to', async () => {
		serverQueues('blocks', job('blocks', 'done', { done: 3, skipped: 1, failed: 1 }))

		const wrapper = mountPage()
		await choose(wrapper, 'blocks', 'blocked_accounts.csv')
		await vi.runOnlyPendingTimersAsync()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/blocks`, expect.any(FormData))
		expect(wrapper.text()).toContain('3 applied, 1 skipped, 1 could not be reached')
		expect(showSuccess).toHaveBeenCalled()
	})

	it('names what could not be done, with the reason, under the summary', async () => {
		serverQueues('blocks', job('blocks', 'done', { done: 1, failed: 7 }, { failed: { 'gone@dead.example': 'no such server', 'two@x.example': 'b', 'three@x.example': 'c', 'four@x.example': 'd', 'five@x.example': 'e', 'six@x.example': 'f', 'seven@x.example': 'g' } }))

		const wrapper = mountPage()
		await choose(wrapper, 'blocks', 'blocked_accounts.csv')
		await vi.runOnlyPendingTimersAsync()
		await flushPromises()

		const named = wrapper.find('.migration__failures')
		expect(named.text()).toContain('gone@dead.example — no such server')
		expect(named.findAll('li')).toHaveLength(6)
		expect(named.text()).toContain('and 2 more')
	})

	it('queues a bookmarks file and says what came of the addresses in it', async () => {
		serverQueues('bookmarks', job('bookmarks', 'done', { done: 4, skipped: 1, failed: 2 }))

		const wrapper = mountPage()
		await choose(wrapper, 'bookmarks', 'bookmarks.csv')
		await vi.runOnlyPendingTimersAsync()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/bookmarks`, expect.any(FormData))
		expect(wrapper.text()).toContain('4 posts bookmarked, 1 lines were not the address of a post, 2 could not be fetched')
	})

	it('queues a blocked-domains file under the name Mastodon gives the list', async () => {
		serverQueues('domain_blocks', job('domain_blocks', 'done', { done: 2 }))

		const wrapper = mountPage()
		await choose(wrapper, 'domain_blocks', 'blocked_domains.csv')
		await vi.runOnlyPendingTimersAsync()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/domain_blocks`, expect.any(FormData))
		expect(wrapper.text()).toContain('2 servers blocked')
	})

	it('queues a mutes CSV through the mutes route', async () => {
		serverQueues('mutes', job('mutes', 'done', { done: 2 }))

		const wrapper = mountPage()
		await choose(wrapper, 'mutes', 'muted_accounts.csv')
		await vi.runOnlyPendingTimersAsync()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/mutes`, expect.any(FormData))
		expect(wrapper.text()).toContain('2 applied, 0 skipped, 0 could not be reached')
	})

	/**
	 * A list here can only hold accounts the owner follows, so the count of
	 * the ones left out has to say why — otherwise a half-filled list looks
	 * like a failure nobody can act on.
	 */
	it('says why the accounts left out of a list were left out', async () => {
		serverQueues('lists', job('lists', 'done', { done: 5, skipped: 4 }, { lists: 2 }))

		const wrapper = mountPage()
		await choose(wrapper, 'lists', 'lists.csv')
		await vi.runOnlyPendingTimersAsync()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/lists`, expect.any(FormData))
		expect(wrapper.text()).toContain('2 lists made, 5 accounts added, 4 skipped because you do not follow them')
	})

	it('shows the server\'s reason when one of those files is refused', async () => {
		axios.post.mockRejectedValue({ response: { data: { error: 'that is not a CSV' } } })

		const wrapper = mountPage()
		await choose(wrapper, 'blocks', 'blocked_accounts.csv')

		expect(showError).toHaveBeenCalledWith('that is not a CSV')
	})

	it('downloads one list at a time under the name the server gave it', async () => {
		axios.get.mockResolvedValue({
			data: new Blob(['carol@remote.example\n']),
			headers: { 'content-disposition': 'attachment; filename="blocked_accounts.csv"' },
		})
		let savedAs = ''
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function saved() {
			savedAs = this.getAttribute('download')
		})
		URL.createObjectURL = vi.fn(() => 'blob:csv')
		URL.revokeObjectURL = vi.fn()

		const wrapper = mountPage()
		await flushPromises()
		const button = wrapper.findAll('.migration__csv-buttons button')
			.find((one) => one.text() === 'Blocks')
		await button.trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${API}/migration/export/blocks`, { responseType: 'blob' })
		expect(savedAs).toBe('blocked_accounts.csv')
	})

	/** An address that is not an account's own is refused, and the server says why. */
	it('shows the reason the server gave for refusing an address', async () => {
		const wrapper = mountPage()
		await flushPromises()
		axios.post.mockRejectedValue({ response: { data: { error: 'that is not an actor id' } } })

		wrapper.vm.aliasInput = 'pixelfed.social'
		await wrapper.vm.addAlias()
		await flushPromises()

		const { showError } = await import('../../../src/services/toast.js')
		expect(showError).toHaveBeenCalledWith('that is not an actor id')
	})
})
