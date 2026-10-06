/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import AccountHoverCard, { CLOSE_DELAY, OPEN_DELAY, resetAccountCache } from '../../../src/components/AccountHoverCard.vue'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

// The store modules keep their state in a shared module-level object.
const bob = {
	id: '42',
	url: 'https://remote.example/users/bob',
	acct: 'bob@remote.example',
	username: 'bob',
	display_name: 'Bob',
	avatar: 'https://remote.example/media/bob.png',
	note: '<p>Gardener, <a href="https://remote.example/tags/moss">#moss</a> enthusiast</p>',
	followers_count: 128,
	following_count: 7,
	statuses_count: 1204,
	created_at: '2019-03-17T09:00:00.000Z',
	locked: false,
	bot: false,
	fields: [
		{ name: 'Website', value: '<a href="https://mossy.example" rel="me">mossy.example</a>' },
		{ name: 'Pronouns', value: 'they/them' },
	],
	emojis: [],
}

let pinia
let accountStore
let settingsStore
let wrappers = []

function makeStore() {
	pinia = createPinia()
	setActivePinia(pinia)
	accountStore = useAccountStore()
	settingsStore = useSettingsStore()
	settingsStore.setServerData({ public: false })

	return pinia
}

function mountCard(props = {}) {
	const wrapper = mount(AccountHoverCard, {
		props: { handle: bob.acct, ...props },
		slots: { default: '<a class="mention" href="https://remote.example/users/bob">@bob</a>' },
		global: { plugins: [pinia] },
		attachTo: document.body,
	})
	wrappers.push(wrapper)
	return wrapper
}

/**
 * The trigger the reader points at.
 *
 * @param {object} wrapper - the mounted card
 * @return {object} the trigger wrapper
 */
const trigger = (wrapper) => wrapper.find('.account-hover__trigger')

/** The card itself, which floating-vue renders at the end of <body>. */
const card = () => document.querySelector('.account-hover-card')

/**
 * Let the delays run and floating-vue mount (or unmount) its popper.
 *
 * @param {number} ms - how much time passes
 */
async function advance(ms) {
	await vi.advanceTimersByTimeAsync(ms)
	await flushPromises()
}

/**
 * floating-vue mounts and unmounts its popper across animation frames, so the
 * card reaches the document a frame after the component asked for it. Two
 * frames of slack, which is still less than any of the delays under test.
 */
async function frame() {
	await vi.advanceTimersByTimeAsync(32)
	await flushPromises()
}

/**
 * Hover the trigger and wait exactly as long as it takes to open.
 *
 * @param {object} wrapper - the mounted card
 */
async function hoverUntilOpen(wrapper) {
	await pointerEnter(wrapper)
	await advance(OPEN_DELAY)
	await frame()
}

/**
 * Point at the trigger with a mouse.
 *
 * @param {object} wrapper - the mounted card
 * @param {string} pointerType - what is doing the pointing
 */
function pointerEnter(wrapper, pointerType = 'mouse') {
	return trigger(wrapper).trigger('pointerenter', { pointerType })
}

/**
 * Take the pointer off the trigger again.
 *
 * @param {object} wrapper - the mounted card
 * @param {string} pointerType - what was doing the pointing
 */
function pointerLeave(wrapper, pointerType = 'mouse') {
	return trigger(wrapper).trigger('pointerleave', { pointerType })
}

beforeEach(() => {
	// floating-vue mounts its popper across animation frames; vitest does not
	// fake those by default, so the card would never appear under fake timers
	vi.useFakeTimers({
		toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date', 'requestAnimationFrame', 'cancelAnimationFrame'],
	})
	resetAccountCache()
	makeStore()
	axios.get.mockReset()
	axios.get.mockResolvedValue({ data: bob })
})

afterEach(async () => {
	for (const wrapper of wrappers) {
		wrapper.unmount()
	}
	wrappers = []
	await vi.runOnlyPendingTimersAsync()
	vi.useRealTimers()
	document.body.innerHTML = ''
})

describe('AccountHoverCard', () => {
	describe('opening', () => {
		it('leaves the mention alone until the pointer has rested on it', async () => {
			const wrapper = mountCard()
			expect(trigger(wrapper).text()).toBe('@bob')

			await pointerEnter(wrapper)
			await advance(OPEN_DELAY - 50)
			await frame()
			expect(card()).toBeNull()

			await advance(50)
			await frame()
			expect(card()).not.toBeNull()
		})

		it('does not open for a pointer that only passes over the mention', async () => {
			const wrapper = mountCard()

			await pointerEnter(wrapper)
			await advance(OPEN_DELAY - 100)
			await pointerLeave(wrapper)
			await advance(OPEN_DELAY * 2)

			expect(card()).toBeNull()
		})

		it('never opens under a finger, so a tap follows the link', async () => {
			const wrapper = mountCard()

			await pointerEnter(wrapper, 'touch')
			await trigger(wrapper).trigger('pointerdown', { pointerType: 'touch' })
			await trigger(wrapper).trigger('touchstart')
			// the tap gives the link focus, which must not stand in for a hover
			await trigger(wrapper).trigger('focusin')
			await advance(OPEN_DELAY * 2)
			await frame()

			expect(card()).toBeNull()
			expect(axios.get).not.toHaveBeenCalled()
		})
	})

	describe('what it shows', () => {
		it('shows a loading state first and the account when it arrives', async () => {
			let resolveRequest
			axios.get.mockReturnValue(new Promise((resolve) => {
				resolveRequest = resolve
			}))
			const wrapper = mountCard()

			await hoverUntilOpen(wrapper)
			expect(card().querySelector('.account-hover-card__loading')).not.toBeNull()

			resolveRequest({ data: bob })
			await advance(0)

			expect(card().querySelector('.account-hover-card__loading')).toBeNull()
			expect(card().querySelector('.account-hover-card__name').textContent).toContain('Bob')
			expect(card().querySelector('.account-hover-card__handle').textContent).toBe('@bob@remote.example')
			expect(card().querySelector('.account-hover-card__bio').textContent).toContain('Gardener')
			expect(card().querySelector('.account-hover-card__counts').textContent).toContain('128')
			expect(card().querySelector('.account-hover-card__counts').textContent).toContain('followers')
			expect(card().querySelector('.account-hover-card__counts').textContent).toContain('7')
		})

		it('leaves the followers out for a reader who hides the numbers, and keeps the rest', async () => {
			settingsStore.setServerDataEntry({ key: 'hideCounts', value: true })
			axios.get.mockResolvedValue({ data: bob })
			const wrapper = mountCard()

			await hoverUntilOpen(wrapper)
			await advance(0)

			const counts = card().querySelector('.account-hover-card__counts').textContent
			expect(counts).not.toContain('128')
			expect(counts).not.toContain('followers')
			expect(counts).toContain('following')
		})

		it('shows what the profile says about itself, not only the two counts', async () => {
			// the card is the answer to "who is this?" — a bio and two numbers
			// left the reader opening the profile to find out anything else
			axios.get.mockResolvedValue({ data: bob })
			const wrapper = mountCard()

			await hoverUntilOpen(wrapper)

			const counts = card().querySelector('.account-hover-card__counts').textContent
			expect(counts).toContain('1204')
			expect(counts).toContain('posts')
			expect(card().querySelector('.account-hover-card__joined').textContent).toContain('2019')

			const rows = [...card().querySelectorAll('.account-hover-card__field')]
			expect(rows.map((row) => row.querySelector('dt').textContent.trim())).toEqual(['Website', 'Pronouns'])
			expect(rows[0].querySelector('a').getAttribute('href')).toBe('https://mossy.example')
			expect(rows[0].querySelector('a').textContent).toBe('mossy.example')
			// a row that is not a link is text, not an anchor to nowhere
			expect(rows[1].querySelector('a')).toBeNull()
			expect(rows[1].querySelector('dd').textContent.trim()).toBe('they/them')
		})

		/**
		 * The hostname used to be a chip beside the byline of every remote
		 * post, which in a timeline that spans servers is a hostname next to
		 * almost every name. It is here now, in the same colour the ring
		 * around the avatar is drawn in, for the reader who asked.
		 */
		it('says which server the account is on, in that server\'s colour', async () => {
			axios.get.mockResolvedValue({ data: bob })
			const wrapper = mountCard()

			await hoverUntilOpen(wrapper)

			const chip = card().querySelector('.account-hover-card__instance')
			expect(chip.textContent.trim()).toBe('remote.example')
			expect(chip.getAttribute('style')).toContain('--instance-colour: hsl(')
		})

		it('says nothing about the server for an account on this one', async () => {
			// everything here is on this server; naming it would be noise on
			// every card a reader opens
			axios.get.mockResolvedValue({ data: { ...bob, acct: 'alice', url: 'https://cloud.example.org/@alice' } })
			const wrapper = mountCard({ handle: 'alice' })

			await hoverUntilOpen(wrapper)

			expect(card().querySelector('.account-hover-card__instance')).toBeNull()
		})

		it('never follows a field into a scheme a profile should not name', async () => {
			axios.get.mockResolvedValue({ data: { ...bob, fields: [{ name: 'Site', value: '<a href="javascript:alert(1)">click</a>' }] } })
			const wrapper = mountCard()

			await hoverUntilOpen(wrapper)

			const row = card().querySelector('.account-hover-card__field')
			expect(row.querySelector('a')).toBeNull()
			expect(row.querySelector('dd').textContent.trim()).toBe('click')
		})

		it('shows at most four fields, so a profile cannot turn the card into a page', async () => {
			const fields = Array.from({ length: 9 }, (unused, index) => ({ name: 'F' + index, value: String(index) }))
			axios.get.mockResolvedValue({ data: { ...bob, fields } })
			const wrapper = mountCard()

			await hoverUntilOpen(wrapper)

			expect(card().querySelectorAll('.account-hover-card__field')).toHaveLength(4)
		})

		it('says what kind of account it is before somebody follows it', async () => {
			axios.get.mockResolvedValue({ data: { ...bob, locked: true, bot: true } })
			const wrapper = mountCard()

			await hoverUntilOpen(wrapper)

			const badges = [...card().querySelectorAll('.account-hover-card__badge')].map((b) => b.textContent.trim())
			expect(badges).toEqual(['Approves followers', 'Automated'])
		})

		it('leaves out what the account did not say', async () => {
			// a fallback built from a mention carries none of this, and an
			// invented "Joined January 1970" is worse than a gap
			axios.get.mockReturnValue(new Promise(() => {}))
			const wrapper = mountCard({ fallback: { acct: bob.acct, username: 'bob', display_name: 'Bob' } })

			await hoverUntilOpen(wrapper)

			expect(card().querySelector('.account-hover-card__joined')).toBeNull()
			expect(card().querySelector('.account-hover-card__fields')).toBeNull()
			expect(card().querySelector('.account-hover-card__badges')).toBeNull()
		})

		it('shows what the caller already knows while the request is in flight', async () => {
			axios.get.mockReturnValue(new Promise(() => {}))
			const wrapper = mountCard({ fallback: { acct: bob.acct, username: 'bob', display_name: 'Bob' } })

			await hoverUntilOpen(wrapper)

			expect(card().querySelector('.account-hover-card__loading')).toBeNull()
			expect(card().querySelector('.account-hover-card__name').textContent).toContain('Bob')
			// nothing is known about the numbers yet, and 0 would be a lie
			expect(card().querySelector('.account-hover-card__counts')).toBeNull()
		})

		it('never injects the bio as raw markup', async () => {
			axios.get.mockResolvedValue({ data: { ...bob, note: '<p>hi<script>alert(1)</script><img src=x onerror="alert(1)"></p>' } })
			const wrapper = mountCard()

			await hoverUntilOpen(wrapper)

			const bio = card().querySelector('.account-hover-card__bio')
			expect(bio.querySelector('script')).toBeNull()
			expect(bio.querySelector('img')).toBeNull()
			expect(bio.innerHTML).not.toContain('onerror')
		})

		it('says when the account follows the reader, without asking anybody', async () => {
			accountStore.addRelationship({ actorId: bob.id, data: { id: bob.id, followed_by: true } })
			const wrapper = mountCard()

			await hoverUntilOpen(wrapper)

			expect(card().querySelector('.account-hover-card__badge').textContent).toContain('Follows you')
			expect(axios.get).toHaveBeenCalledTimes(1)
			expect(axios.get.mock.calls[0][0]).toContain('/global/account/info')
		})
	})

	describe('requests', () => {
		it('asks for nothing while the card is closed', async () => {
			const wrapper = mountCard()

			await pointerEnter(wrapper)
			await advance(OPEN_DELAY - 50)

			expect(axios.get).not.toHaveBeenCalled()
		})

		it('asks once, and takes the second hover out of the store', async () => {
			const first = mountCard()
			await hoverUntilOpen(first)
			expect(axios.get).toHaveBeenCalledTimes(1)
			expect(accountStore.getAccount(bob.acct)).toMatchObject({ acct: bob.acct })

			await pointerLeave(first)
			await advance(CLOSE_DELAY)

			// only the store can answer the second hover now
			resetAccountCache()
			const second = mountCard()
			await hoverUntilOpen(second)

			expect(axios.get).toHaveBeenCalledTimes(1)
			expect(card().querySelector('.account-hover-card__name').textContent).toContain('Bob')
		})

		it('sends one request when two cards for the same account open together', async () => {
			const first = mountCard()
			const second = mountCard()

			await pointerEnter(first)
			await pointerEnter(second)
			await advance(OPEN_DELAY)
			await frame()

			expect(axios.get).toHaveBeenCalledTimes(1)
		})

		it('tries again after a failed lookup', async () => {
			axios.get.mockRejectedValueOnce(new Error('gone'))
			const wrapper = mountCard()

			await hoverUntilOpen(wrapper)
			expect(card().querySelector('.account-hover-card__loading')).not.toBeNull()

			await pointerLeave(wrapper)
			await advance(CLOSE_DELAY)
			await hoverUntilOpen(wrapper)

			expect(axios.get).toHaveBeenCalledTimes(2)
			expect(card().querySelector('.account-hover-card__name').textContent).toContain('Bob')
		})
	})

	describe('closing', () => {
		it('waits before closing, so the pointer can reach the card', async () => {
			const wrapper = mountCard()
			await hoverUntilOpen(wrapper)

			await pointerLeave(wrapper)
			await advance(CLOSE_DELAY - 50)
			expect(card()).not.toBeNull()

			await advance(50)
			expect(card()).toBeNull()
		})

		it('stays open while the pointer is on the card itself', async () => {
			const wrapper = mountCard()
			await hoverUntilOpen(wrapper)

			await pointerLeave(wrapper)
			card().dispatchEvent(new Event('pointerenter'))
			await advance(CLOSE_DELAY * 3)

			expect(card()).not.toBeNull()
		})

		it('closes once the pointer has left both the mention and the card', async () => {
			const wrapper = mountCard()
			await hoverUntilOpen(wrapper)

			await pointerLeave(wrapper)
			card().dispatchEvent(new Event('pointerenter'))
			await advance(CLOSE_DELAY * 2)
			card().dispatchEvent(new Event('pointerleave'))
			await advance(CLOSE_DELAY)

			expect(card()).toBeNull()
		})

		it('can be opened again after it closed', async () => {
			const wrapper = mountCard()
			await hoverUntilOpen(wrapper)
			await pointerLeave(wrapper)
			await advance(CLOSE_DELAY)
			expect(card()).toBeNull()

			await hoverUntilOpen(wrapper)
			expect(card()).not.toBeNull()
		})
	})

	describe('keyboard', () => {
		it('opens on focus, without waiting', async () => {
			const wrapper = mountCard()

			await trigger(wrapper).trigger('focusin')
			await frame()

			expect(card()).not.toBeNull()
			expect(axios.get).toHaveBeenCalledTimes(1)
		})

		it('closes on Escape', async () => {
			const wrapper = mountCard()
			await trigger(wrapper).trigger('focusin')
			await frame()
			expect(card()).not.toBeNull()

			document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
			await frame()

			expect(card()).toBeNull()
		})

		it('does not open when a click on the mention takes the focus', async () => {
			const wrapper = mountCard()

			await trigger(wrapper).trigger('pointerdown', { pointerType: 'mouse' })
			await trigger(wrapper).trigger('focusin')
			await advance(OPEN_DELAY)
			await frame()

			expect(card()).toBeNull()
			expect(axios.get).not.toHaveBeenCalled()
		})

		it('opens on focus again once the press is over', async () => {
			// Safari never focuses a clicked link, so the suppression cannot
			// wait for a focusout that may never come
			const wrapper = mountCard()
			await trigger(wrapper).trigger('pointerdown', { pointerType: 'mouse' })
			await advance(0)

			await trigger(wrapper).trigger('focusin')
			await frame()

			expect(card()).not.toBeNull()
		})

		it('closes when focus moves on', async () => {
			const wrapper = mountCard()
			await trigger(wrapper).trigger('focusin')
			await frame()

			await trigger(wrapper).trigger('focusout')
			await advance(CLOSE_DELAY)

			expect(card()).toBeNull()
		})

		it('listens for Escape only while it is open', async () => {
			const wrapper = mountCard()
			const listeners = vi.spyOn(document, 'addEventListener')

			await trigger(wrapper).trigger('focusin')
			await frame()
			expect(listeners).toHaveBeenCalledWith('keydown', expect.any(Function))

			const removed = vi.spyOn(document, 'removeEventListener')
			document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
			await frame()
			expect(removed).toHaveBeenCalledWith('keydown', expect.any(Function))
		})
	})

	describe('where the account cannot be looked up', () => {
		it('opens nothing at all rather than a card that can never fill', async () => {
			// a public page has no session to ask with, and this mention came
			// with nothing of its own
			settingsStore.setServerData({ public: true })
			const wrapper = mountCard()

			await hoverUntilOpen(wrapper)

			expect(card()).toBeNull()
		})

		it('asks nothing of a public page, where there is no session to ask with', async () => {
			settingsStore.setServerData({ public: true })
			const wrapper = mountCard({ fallback: { acct: bob.acct, username: 'bob', display_name: 'Bob' } })

			await hoverUntilOpen(wrapper)

			expect(card().querySelector('.account-hover-card__name').textContent).toContain('Bob')
			expect(axios.get).not.toHaveBeenCalled()
		})
	})

	describe('without a store', () => {
		it('renders the mention from what the page already carried', async () => {
			settingsStore.setServerData({ public: true })
			const wrapper = mountCard({ fallback: { acct: bob.acct, username: 'bob', display_name: 'Bob' } })

			await hoverUntilOpen(wrapper)

			expect(card().querySelector('.account-hover-card__name').textContent).toContain('Bob')
			expect(axios.get).not.toHaveBeenCalled()
		})
	})
})
