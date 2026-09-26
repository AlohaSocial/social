/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { setLanguage } from '@nextcloud/l10n'
import { showError, showSuccess } from '../../../src/services/toast.js'
import Composer from '../../../src/components/Composer/Composer.vue'
import ComposerPreview from '../../../src/components/Composer/ComposerPreview.vue'
import LanguageSelect from '../../../src/components/Composer/LanguageSelect.vue'
import PreviewGridItem from '../../../src/components/Composer/PreviewGridItem.vue'
import SubmitStatusButton from '../../../src/components/Composer/SubmitStatusButton.vue'
import VisibilitySelect from '../../../src/components/Visibility/VisibilitySelect.vue'
import eventBus from '../../../src/services/eventBus.js'
import { createPinia, setActivePinia } from 'pinia'
import { isProxy, nextTick } from 'vue'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

// What this app keeps in localStorage is kept under the name of whoever is
// signed in -- `localStorage` belongs to the origin and outlives a logout, so
// an unscoped key hands one account's unsent draft to the next account to sign
// in on the same computer. The mocked user below is why the keys here end
// '::alice'.
// @nextcloud/auth reads the user from <head>, which the harness does not set
vi.mock('@nextcloud/auth', async (importOriginal) => ({
	...(await importOriginal()),
	getCurrentUser: () => ({ uid: 'alice', displayName: 'Alice', isAdmin: false }),
}))

// the Files dialog mounts itself into the document and talks WebDAV; what the
// composer does with it is the builder it configures and the paths it gets back
vi.mock('@nextcloud/dialogs', () => ({
	getFilePickerBuilder: vi.fn(),
}))
vi.mock('../../../src/services/toast.js', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

// sound and touch are the device's business, and jsdom has neither
const { feel } = vi.hoisted(() => ({ feel: vi.fn() }))
vi.mock('../../../src/services/senses.js', () => ({ feel }))

// jsdom has no canvas, so the card is stood in for
const { renderTextCard } = vi.hoisted(() => ({ renderTextCard: vi.fn() }))
vi.mock('../../../src/utils/textCard.js', async (importOriginal) => ({
	...(await importOriginal()),
	renderTextCard,
}))

// the composer asks the server which team accounts this account may post as,
// and the mention/hashtag pickers reach for the same client
const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))

// The date picker is a date library and its locales, fetched when the clock
// is pressed. What the composer does with it is hand it a time and take one
// back, so a stub that does exactly that stands in for the calendar.
vi.mock('@nextcloud/vue/components/NcDateTimePicker', () => ({
	// `__esModule` is what tells defineAsyncComponent that this is a module
	// with a default export rather than the component itself
	__esModule: true,
	default: {
		name: 'NcDateTimePicker',
		props: ['modelValue', 'min', 'type', 'minuteStep', 'clearable', 'ariaLabel'],
		emits: ['update:modelValue'],
		template: '<div class="date-picker-stub" />',
	},
}))

const media = {
	id: 'media-1',
	type: 'image',
	url: 'https://cloud.example.org/media/original.png',
	preview_url: 'https://cloud.example.org/media/small.png',
	description: '',
	blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
	meta: { small: { width: 4, height: 3 } },
}

const bob = {
	id: '2',
	acct: 'bob@remote.example',
	username: 'bob',
	display_name: 'Bob',
	url: 'https://remote.example/@bob',
	avatar: 'https://remote.example/avatar.png',
}

const carol = {
	id: '3',
	acct: 'carol',
	username: 'carol',
	display_name: 'Carol',
	url: 'https://cloud.example.org/@carol',
	avatar: 'https://cloud.example.org/avatar/carol/64',
}

function replyTo(account = bob, overrides = {}) {
	return {
		id: '42',
		visibility: 'unlisted',
		content: '<p>Original post</p>',
		mentions: [],
		tags: [],
		account,
		...overrides,
	}
}

function quoteOf(account = bob, overrides = {}) {
	return {
		id: '77',
		visibility: 'public',
		content: '<p>The post being quoted</p>',
		mentions: [],
		tags: [],
		account,
		...overrides,
	}
}

// What the mention autocomplete inserts into the contenteditable
const MENTION_BOB = '<span class="mention" contenteditable="false">'
	+ '<a href="https://remote.example/@bob" target="_blank"><img src="https://remote.example/avatar.png">@bob@remote.example</a>'
	+ '</span>&nbsp;'

const wrappers = []

/**
 * @param {object} props what the parent passes the composer
 * @param {object} [options] the rest of the world it is mounted into
 * @param {string} [options.accountPrivacy] `source.privacy` of the signed-in
 *   account, as `verify_credentials` answers it; omitted for an account whose
 *   settings have not arrived
 * @return {object} the wrapper and the timeline store
 */
function mountComposer(props = {}, { accountPrivacy } = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData({ public: false, cloudAddress: 'https://cloud.example.org' })
	if (accountPrivacy !== undefined) {
		useAccountStore().setCredentials({
			id: '1',
			url: 'https://cloud.example.org/@alice',
			source: { privacy: accountPrivacy },
		})
	}
	const store = useTimelineStore()
	// `post` resolves with the created status and with undefined when the
	// server refused, which is how the composer tells the two apart
	vi.spyOn(store, 'createMedia').mockResolvedValue(media)
	vi.spyOn(store, 'createMediaFromFile').mockResolvedValue(media)
	vi.spyOn(store, 'post').mockResolvedValue({ id: 'new-1' })
	vi.spyOn(store, 'describeMedia').mockResolvedValue(undefined)
	vi.spyOn(store, 'focusMedia').mockResolvedValue(undefined)
	vi.spyOn(store, 'refreshTimeline').mockResolvedValue(undefined)
	vi.spyOn(store, 'setComposerDisplayStatus')
	const wrapper = mount(Composer, {
		props,
		global: {
			plugins: [pinia],
			stubs: {
				NcEmojiPicker: { name: 'NcEmojiPicker', emits: ['select'], template: '<div class="emoji-picker-stub"><slot /></div>' },
				NcAvatar: true,
				ActorAvatar: true,
				RouterLink: RouterLinkStub,
			},
		},
	})
	wrappers.push(wrapper)
	return { wrapper, store }
}

const input = (wrapper) => wrapper.find('.message')
// untrimmed, unlike DOMWrapper.text(), because inserted emoji end with a space
const typed = (wrapper) => input(wrapper).element.textContent

async function setContent(wrapper, html) {
	input(wrapper).element.innerHTML = html
	await input(wrapper).trigger('input')
}

const submitButton = (wrapper) => wrapper.findComponent(SubmitStatusButton).find('button')
const canPost = (wrapper) => submitButton(wrapper).attributes('disabled') === undefined
const currentVisibility = (wrapper) => wrapper.findComponent(VisibilitySelect).props('visibility')
const selectVisibility = (wrapper, visibility) => wrapper.findComponent(VisibilitySelect).vm.$emit('update:visibility', visibility)
/**
 * Presses the emoji button, which is what fetches the picker, and picks one.
 *
 * @param {object} wrapper the mounted composer
 * @param {string|object} emoji what the picker emits
 */
async function pickEmoji(wrapper, emoji) {
	await openEmojiPicker(wrapper)
	return wrapper.findComponent({ name: 'NcEmojiPicker' }).vm.$emit('select', emoji)
}

/**
 * @param {object} wrapper the mounted composer
 * @return {Promise<void>} resolved once the picker is on screen
 */
async function openEmojiPicker(wrapper) {
	await wrapper.find('button[aria-label="Add emoji"]').trigger('click')
	// The picker is fetched before it is mounted, which is more than one tick:
	// the press awaits a real `import()` of `@nextcloud/vue`, and resolving a
	// module graph that size in jsdom is not instant. `waitUntil` defaults to a
	// second, which this beat comfortably on its own and lost when the whole
	// suite ran back to back — so it waits on the condition rather than on a
	// second, and only fails if the picker never arrives at all.
	await vi.waitUntil(() => wrapper.findComponent({ name: 'NcEmojiPicker' }).exists(), { timeout: 15000 })
	await flushPromises()
}

async function attachFile(wrapper, file) {
	const fileInput = wrapper.find('input[type="file"]')
	Object.defineProperty(fileInput.element, 'files', { value: [file], configurable: true })
	await fileInput.trigger('change')
}

// What the composer holds the picker to: one dialog, several pictures, no
// folders. `pick()` resolves with the paths, and rejects when it is closed.
function filePicker(result) {
	const builder = {
		setMultiSelect: vi.fn(() => builder),
		setMimeTypeFilter: vi.fn(() => builder),
		allowDirectories: vi.fn(() => builder),
		addButton: vi.fn(() => builder),
		build: vi.fn(() => ({ pick: vi.fn(() => result) })),
	}
	getFilePickerBuilder.mockReturnValue(builder)

	return builder
}

async function addFromFiles(wrapper) {
	await wrapper.find('button[aria-label="Add from Files"]').trigger('click')
	await flushPromises()
}

const pickedPaths = (store) => store.createMediaFromFile.mock.calls.map(([payload]) => payload.path)

// jsdom has neither DataTransfer nor DragEvent, and constructing one is not
// what is being tested: what the composer reads off a drag is `types`, `files`
// and `relatedTarget`, so the fixtures carry exactly those, the same way
// attachFile fakes the file input's `files`.
function transfer(files = [], types = files.length > 0 ? ['Files'] : ['text/plain']) {
	return {
		files,
		types,
		dropEffect: 'none',
	}
}

function dragEvent(type, { dataTransfer = transfer(), relatedTarget = null } = {}) {
	const event = new Event(type, { bubbles: true, cancelable: true })
	Object.defineProperty(event, 'dataTransfer', { value: dataTransfer })
	Object.defineProperty(event, 'relatedTarget', { value: relatedTarget })
	return event
}

function pasteEvent(files = []) {
	const event = new Event('paste', { bubbles: true, cancelable: true })
	Object.defineProperty(event, 'clipboardData', { value: { files } })
	return event
}

// dispatched on a real element, because vue-test-utils builds a DragEvent
// jsdom does not have
async function dispatch(wrapper, target, event) {
	target.dispatchEvent(event)
	await wrapper.vm.$nextTick()
	return event
}

const postedStatus = (store, call = 0) => store.post.mock.calls[call]?.[0]

async function addWarning(wrapper, text) {
	await wrapper.find('button[aria-label="Add content warning"]').trigger('click')
	await wrapper.find('input.content-warning').setValue(text)
}

describe('Composer', () => {
	let getContext
	let createObjectURL
	let revokeObjectURL

	// jsdom has no innerText; the composer empties its input through it after posting
	beforeAll(() => {
		Object.defineProperty(HTMLElement.prototype, 'innerText', {
			configurable: true,
			get() {
				return this.textContent
			},
			set(value) {
				this.textContent = value
			},
		})
	})

	afterAll(() => {
		delete HTMLElement.prototype.innerText
	})

	// tributejs keeps its own state on the instance and the element; wrapped
	// in a reactive proxy, attach() and detach() would see the proxy rather
	// than the object they set up
	it('keeps the mention library\'s instance and its element unwrapped', () => {
		const { wrapper } = mountComposer()

		expect(wrapper.vm.tribute).not.toBeNull()
		expect(isProxy(wrapper.vm.tribute)).toBe(false)
		expect(wrapper.vm.tributeTarget).toBe(input(wrapper).element)
	})

	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: { teams: [] } })
		localStorage.clear()
		setLanguage('en')
		// module mocks, which restoreAllMocks() does not touch
		showError.mockClear()
		showSuccess.mockClear()
		getFilePickerBuilder.mockReset()
		vi.spyOn(console, 'debug').mockImplementation(() => {})
		getContext = vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
			createImageData: (width, height) => ({ data: new Uint8ClampedArray(width * height * 4) }),
			putImageData: () => {},
		})
		createObjectURL = URL.createObjectURL
		URL.createObjectURL = vi.fn(() => 'blob:preview-1')
		revokeObjectURL = URL.revokeObjectURL
		URL.revokeObjectURL = vi.fn()
	})

	afterEach(() => {
		while (wrappers.length > 0) {
			wrappers.pop().unmount()
		}
		eventBus.all.clear()
		getContext.mockRestore()
		URL.createObjectURL = createObjectURL
		URL.revokeObjectURL = revokeObjectURL
		vi.restoreAllMocks()
	})

	describe('the length allowance', () => {
		it("addresses the reader's own face by url rather than by account name", () => {
			// NcAvatar given a `user` keeps a note in browser storage saying whether
			// that account has a picture, and trusts the note over the image ever
			// after: one failed load, from a restart or a deploy, and the face is
			// gone in that browser until the note is cleared. A url is validated on
			// every render, and falls back to the initials when it truly fails.
			const { wrapper } = mountComposer()
			const avatar = wrapper.findComponent({ name: 'NcAvatar' })

			expect(avatar.exists()).toBe(true)
			expect(avatar.attributes('user')).toBeUndefined()
			// with the version Nextcloud bumps when the picture changes: the
			// avatar route is served `immutable`, so without it the browser
			// keeps the old face for a day
			expect(avatar.attributes('url')).toMatch(/\/avatar\/[^/]+\/64\?v=\d+$/)
		})

		it('shows a ring that fills as the post grows, and only once there is text', async () => {
			const { wrapper } = mountComposer()
			expect(wrapper.find('.char-ring').exists()).toBe(false)

			await setContent(wrapper, 'a'.repeat(250))

			const ring = wrapper.find('.char-ring')
			expect(ring.exists()).toBe(true)
			expect(ring.attributes('style')).toContain('--char-progress: 0.5')
			expect(ring.classes()).not.toContain('char-ring--warning')
			expect(ring.find('.char-ring__count').exists()).toBe(false)
		})

		it('counts down out loud near the limit and turns over past it', async () => {
			const { wrapper } = mountComposer()

			await setContent(wrapper, 'a'.repeat(480))
			expect(wrapper.find('.char-ring').classes()).toContain('char-ring--warning')
			expect(wrapper.find('.char-ring__count').text()).toBe('20')

			await setContent(wrapper, 'a'.repeat(510))
			expect(wrapper.find('.char-ring').classes()).toContain('char-ring--over')
			expect(wrapper.find('.char-ring__count').text()).toBe('-10')
		})

		it('counts the characters that are sent, not the markup that produces them', async () => {
			// the counter measured innerHTML: a mention pill from a reply is
			// ~200 characters of markup, so replying burned half the allowance
			// before a word was typed, and canPost could refuse a two-word post
			const { wrapper } = mountComposer()
			await setContent(wrapper, `${MENTION_BOB}hi`)

			expect(MENTION_BOB.length).toBeGreaterThan(150)
			// '@bob@remote.example' + nbsp + 'hi'
			expect(wrapper.find('.char-ring').attributes('style')).toContain('--char-progress: 0.044')
			expect(canPost(wrapper)).toBe(true)
		})

		it('counts a line break as one character, not as a <div>', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, '<div>one</div><div>two</div>')

			// 'one\ntwo\n', trimmed to 'one\ntwo'
			expect(wrapper.find('.char-ring').attributes('style')).toContain('--char-progress: 0.014')
		})

		it('keeps a break before the first block after bare text', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'one<div>two</div>')

			expect(wrapper.vm.statusText).toBe('one\ntwo')
		})

		it('counts an escaped entity as the character it stands for', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'a &amp; b')

			// 'a & b' is five characters, not nine
			expect(wrapper.find('.char-ring').attributes('style')).toContain('--char-progress: 0.01')
		})

		it('accepts 500 characters that only the markup pushes over the limit', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, `<div>${'a'.repeat(499)}</div>`)

			expect(canPost(wrapper)).toBe(true)
			expect(input(wrapper).classes()).not.toContain('too-long')
		})
	})

	describe('author', () => {
		it('names the author, and does not repeat their handle at them', () => {
			// whose account it is was never in question here — the composer only
			// ever writes as the reader — so the handle was a second line saying
			// nothing the first did not
			const { wrapper } = mountComposer()

			expect(wrapper.find('.post-author-name').text()).toBe('Alice')
			expect(wrapper.find('.post-author-id').exists()).toBe(false)
			expect(wrapper.find('.new-post-author').text()).not.toContain('@alice')
		})
	})

	describe('default visibility', () => {
		it('uses the visibility passed by the parent first', () => {
			localStorage.setItem('social.lastPostType::alice', 'direct')
			const { wrapper } = mountComposer({ defaultVisibility: 'unlisted' })
			expect(currentVisibility(wrapper)).toBe('unlisted')
		})

		it('falls back to the visibility of the last post', () => {
			localStorage.setItem('social.lastPostType::alice', 'direct')
			const { wrapper } = mountComposer()
			expect(currentVisibility(wrapper)).toBe('direct')
		})

		it("takes the account's own default over the last post", () => {
			localStorage.setItem('social.lastPostType::alice', 'direct')
			const { wrapper } = mountComposer({}, { accountPrivacy: 'unlisted' })
			expect(currentVisibility(wrapper)).toBe('unlisted')
		})

		it('lets the caller overrule the account default', () => {
			const { wrapper } = mountComposer({ defaultVisibility: 'direct' }, { accountPrivacy: 'unlisted' })
			expect(currentVisibility(wrapper)).toBe('direct')
		})

		// `private` on the wire is what this app calls `followers`
		it("reads the wire's name for a followers-only default", () => {
			const { wrapper } = mountComposer({}, { accountPrivacy: 'private' })
			expect(currentVisibility(wrapper)).toBe('followers')
		})

		it('ignores an account default this app has never heard of', () => {
			localStorage.setItem('social.lastPostType::alice', 'direct')
			const { wrapper } = mountComposer({}, { accountPrivacy: 'local-only' })
			expect(currentVisibility(wrapper)).toBe('direct')
		})

		/**
		 * The timeline draws a composer as the page opens, which is before
		 * `verify_credentials` has answered.
		 */
		it('takes the account default when it arrives after the composer', async () => {
			const { wrapper } = mountComposer()
			expect(currentVisibility(wrapper)).toBe('followers')

			useAccountStore().setCredentials({
				id: '1',
				url: 'https://cloud.example.org/@alice',
				source: { privacy: 'unlisted' },
			})
			await nextTick()

			expect(currentVisibility(wrapper)).toBe('unlisted')
		})

		it('leaves an audience the reader has already chosen alone', async () => {
			const { wrapper } = mountComposer()
			await selectVisibility(wrapper, 'direct')

			useAccountStore().setCredentials({
				id: '1',
				url: 'https://cloud.example.org/@alice',
				source: { privacy: 'public' },
			})
			await nextTick()

			expect(currentVisibility(wrapper)).toBe('direct')
		})

		it('leaves a reply with the audience of the post it answers', async () => {
			const { wrapper } = mountComposer({ inReplyTo: replyTo() })

			useAccountStore().setCredentials({
				id: '1',
				url: 'https://cloud.example.org/@alice',
				source: { privacy: 'public' },
			})
			await nextTick()

			expect(currentVisibility(wrapper)).toBe('unlisted')
		})

		it('defaults to followers only', () => {
			const { wrapper } = mountComposer()
			expect(currentVisibility(wrapper)).toBe('followers')
			expect(submitButton(wrapper).text()).toBe('Post to followers')
		})

		it('follows the visibility selector', async () => {
			const { wrapper } = mountComposer()
			await selectVisibility(wrapper, 'public')
			expect(currentVisibility(wrapper)).toBe('public')
			expect(submitButton(wrapper).text()).toBe('Post')
		})
	})

	describe('whether a post can be sent', () => {
		it('not while the message is empty', async () => {
			const { wrapper } = mountComposer()
			expect(canPost(wrapper)).toBe(false)
			await setContent(wrapper, '<br>')
			expect(canPost(wrapper)).toBe(false)
		})

		it('once there is some text', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'Hello fediverse')
			expect(canPost(wrapper)).toBe(true)
		})

		it('not beyond 500 characters, which is flagged on the input', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'a'.repeat(500))
			expect(canPost(wrapper)).toBe(true)
			expect(input(wrapper).classes()).not.toContain('too-long')

			await setContent(wrapper, 'a'.repeat(501))
			expect(canPost(wrapper)).toBe(false)
			expect(input(wrapper).classes()).toContain('too-long')
		})

		it('not as a direct message without anyone mentioned', async () => {
			const { wrapper } = mountComposer({ defaultVisibility: 'direct' })
			await setContent(wrapper, 'psst, secret')
			expect(canPost(wrapper)).toBe(false)
		})

		it('as a direct message once a handle is typed', async () => {
			const { wrapper } = mountComposer({ defaultVisibility: 'direct' })
			await setContent(wrapper, 'psst @bob@remote.example secret')
			expect(canPost(wrapper)).toBe(true)
		})

		it('as a direct message once the autocomplete inserted a mention', async () => {
			const { wrapper } = mountComposer({ defaultVisibility: 'direct' })
			await setContent(wrapper, `${MENTION_BOB}secret`)
			expect(canPost(wrapper)).toBe(true)
		})

		it('not while an attachment is still uploading', async () => {
			const { wrapper, store } = mountComposer()
			await setContent(wrapper, 'Look at this')
			let finishUpload
			store.createMedia.mockImplementation(() => new Promise((resolve) => {
				finishUpload = resolve
			}))

			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			expect(canPost(wrapper)).toBe(false)

			finishUpload(media)
			await flushPromises()
			expect(canPost(wrapper)).toBe(true)
		})
	})

	describe('an upload the server refused', () => {
		let previews = 0

		const failUpload = (store) => store.createMedia.mockResolvedValue(undefined)

		it('leaves the post sendable, without the attachment that never arrived', async () => {
			// createMedia answered with undefined, which was stored as
			// `data: undefined`; canPost only rejected `null`, so the button
			// stayed enabled and the submit path then threw on
			// `preview.data.id` before reaching its try block
			const { wrapper, store } = mountComposer()
			failUpload(store)
			await setContent(wrapper, 'Look at this')
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()

			expect(canPost(wrapper)).toBe(true)

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store)).toMatchObject({ status: 'Look at this', media_ids: [] })
		})

		it('marks the failed upload rather than leaving it undefined', async () => {
			const { wrapper, store } = mountComposer()
			failUpload(store)
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()

			expect(wrapper.findComponent(PreviewGridItem).props('preview')).toMatchObject({ data: null, failed: true })
		})

		it('sends only the ids of the uploads that did arrive', async () => {
			const { wrapper, store } = mountComposer()
			let uploads = 0
			URL.createObjectURL = vi.fn(() => `blob:preview-${++previews}`)
			store.createMedia.mockImplementation(() => {
				uploads += 1
				return Promise.resolve(uploads === 1 ? media : undefined)
			})

			await setContent(wrapper, 'Two pictures')
			const fileInput = wrapper.find('input[type="file"]')
			Object.defineProperty(fileInput.element, 'files', {
				value: [new File(['x'], 'a.png', { type: 'image/png' }), new File(['y'], 'b.png', { type: 'image/png' })],
				configurable: true,
			})
			await fileInput.trigger('change')
			await flushPromises()

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store).media_ids).toEqual([media.id])
		})

		it('never asks for a description of an attachment that failed', async () => {
			const { wrapper, store } = mountComposer()
			failUpload(store)
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()

			expect(wrapper.find('.composer-alt-warning').exists()).toBe(false)
		})
	})

	describe('closing it', () => {
		const closeButton = (wrapper) => wrapper.find('.new-post-author__close')
		const collapsed = (wrapper) => wrapper.find('.new-post').classes().includes('new-post--collapsed')
		const stored = () => JSON.parse(localStorage.getItem('social.composer.draft::alice') ?? 'null')

		it('offers no way out while there is nothing to come out of', () => {
			const { wrapper } = mountComposer()

			expect(closeButton(wrapper).exists()).toBe(false)
		})

		it('offers one as soon as the box is open', async () => {
			const { wrapper } = mountComposer()
			await input(wrapper).trigger('focusin')

			expect(closeButton(wrapper).exists()).toBe(true)
		})

		it('closes an empty box all the way', async () => {
			const { wrapper, store } = mountComposer()
			await input(wrapper).trigger('focusin')

			await closeButton(wrapper).trigger('click')

			expect(collapsed(wrapper)).toBe(true)
			expect(store.setComposerDisplayStatus).toHaveBeenCalledWith(false)
		})

		/**
		 * A click elsewhere already collapses an idle composer and may do no
		 * more than that, so before the close button the only way out of a box
		 * with a word in it was to delete the word. Closing it now keeps the
		 * word: what is asked for is the feed back, not the writing gone.
		 */
		it('keeps what was written when the box is closed', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'half a thought')

			await closeButton(wrapper).trigger('click')

			expect(collapsed(wrapper)).toBe(true)
			expect(typed(wrapper)).toBe('half a thought')
			expect(stored()).toMatchObject({ text: 'half a thought' })
		})

		it('gives it back when the box is opened again', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'half a thought')
			await closeButton(wrapper).trigger('click')

			await input(wrapper).trigger('focusin')

			expect(collapsed(wrapper)).toBe(false)
			expect(typed(wrapper)).toBe('half a thought')
		})

		/** Being asked to reply is a request to write one, closed box or not. */
		it('opens again when a reply is asked for', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'half a thought')
			await closeButton(wrapper).trigger('click')

			eventBus.emit('composer-reply', replyTo(bob))
			await flushPromises()

			expect(collapsed(wrapper)).toBe(false)
		})

		/** A box somebody is writing in stays where they can get back to it. */
		it('leaves a written box on the page rather than taking it away', async () => {
			const { wrapper, store } = mountComposer()
			await setContent(wrapper, 'half a thought')

			await closeButton(wrapper).trigger('click')

			expect(store.setComposerDisplayStatus).not.toHaveBeenCalled()
		})

		/**
		 * The New post dialog has its own way out, and a composer that is the
		 * whole reason the dialog is on screen has nothing to close itself to.
		 */
		it('is not offered where there is nothing to close to', async () => {
			const { wrapper } = mountComposer({ startExpanded: true })
			await setContent(wrapper, 'half a thought')

			expect(closeButton(wrapper).exists()).toBe(false)
		})
	})

	describe('the panels the toolbar opens', () => {
		const toolbarButton = (wrapper, label) => wrapper.findAll('button')
			.find((button) => button.attributes('aria-label') === label)

		/**
		 * Pressing the toolbar button again closed them, which is a row
		 * further down and a thing to work out; the way out of a panel belongs
		 * on the panel.
		 */
		it('closes the poll from the poll itself', async () => {
			const { wrapper } = mountComposer()
			await input(wrapper).trigger('focusin')
			await toolbarButton(wrapper, 'Add poll').trigger('click')
			expect(wrapper.find('.poll-editor').exists()).toBe(true)

			await wrapper.find('.poll-editor__remove').trigger('click')

			expect(wrapper.find('.poll-editor').exists()).toBe(false)
		})

		it('closes the content warning from the row it is typed in', async () => {
			const { wrapper } = mountComposer()
			await input(wrapper).trigger('focusin')
			await toolbarButton(wrapper, 'Add content warning').trigger('click')
			expect(wrapper.find('.content-warning').exists()).toBe(true)

			await wrapper.find('.content-warning-row__remove').trigger('click')

			expect(wrapper.find('.content-warning').exists()).toBe(false)
		})

		it('closes the preview from the preview', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'something to look at')
			await toolbarButton(wrapper, 'Preview this post').trigger('click')
			expect(wrapper.findComponent(ComposerPreview).exists()).toBe(true)

			await wrapper.find('.composer-preview__close').trigger('click')

			expect(wrapper.findComponent(ComposerPreview).exists()).toBe(false)
		})

		it('closes the place picker from the picker', async () => {
			const { wrapper } = mountComposer()
			await input(wrapper).trigger('focusin')
			await toolbarButton(wrapper, 'Say where this was taken').trigger('click')
			expect(wrapper.find('.place-picker').exists()).toBe(true)

			await wrapper.find('.place-picker__close').trigger('click')

			expect(wrapper.find('.place-picker').exists()).toBe(false)
		})

		/** What was typed in it goes with it, as pressing the button again does. */
		it('takes the warning text with the warning', async () => {
			const { wrapper } = mountComposer()
			await input(wrapper).trigger('focusin')
			await toolbarButton(wrapper, 'Add content warning').trigger('click')
			await wrapper.find('.content-warning').setValue('spoilers')

			await wrapper.find('.content-warning-row__remove').trigger('click')
			await toolbarButton(wrapper, 'Add content warning').trigger('click')

			expect(wrapper.find('.content-warning').element.value).toBe('')
		})
	})

	describe('the draft', () => {
		const stored = () => JSON.parse(localStorage.getItem('social.composer.draft::alice') ?? 'null')

		it('is kept as the post is written', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'half a thought')

			expect(stored()).toMatchObject({ text: 'half a thought' })
		})

		it('survives a failed post, and so does what was typed', async () => {
			const { wrapper, store } = mountComposer()
			store.post.mockResolvedValue(undefined)
			await setContent(wrapper, 'this must not be lost')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			// the composer used to clear itself in a `finally`: a toast, an
			// empty box, and the text gone
			expect(typed(wrapper)).toBe('this must not be lost')
			expect(stored()).toMatchObject({ text: 'this must not be lost' })
			expect(store.refreshTimeline).not.toHaveBeenCalled()
		})

		it('is forgotten once the post is away', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'off it goes')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(typed(wrapper)).toBe('')
			expect(stored()).toBeNull()
			// whoever opened this — the sidebar's modal — has no other way of
			// knowing the post went out
			expect(wrapper.emitted('posted')).toHaveLength(1)
		})

		it('ignores a visibility it has never heard of', async () => {
			// a draft written by another version: VisibilitySelect finds no
			// entry for it and the template reads `.text` off that, so the
			// whole composer failed to render
			localStorage.setItem('social.composer.draft::alice', JSON.stringify({
				text: 'from elsewhere',
				spoilerText: '',
				visibility: 'local-only',
				savedAt: Date.now(),
			}))

			const { wrapper } = mountComposer()
			await flushPromises()

			expect(typed(wrapper)).toBe('from elsewhere')
			expect(currentVisibility(wrapper)).toBe('followers')
		})

		it('ignores a remembered visibility it has never heard of', () => {
			localStorage.setItem('social.lastPostType::alice', 'local-only')

			expect(currentVisibility(mountComposer().wrapper)).toBe('followers')
		})

		it('comes back when the composer is mounted again', async () => {
			const first = mountComposer()
			await setContent(first.wrapper, 'unfinished')
			await addWarning(first.wrapper, 'a warning')
			first.wrapper.unmount()

			// App.vue used to key its router-view on the full path, so any
			// navigation unmounted the composer and dropped the draft
			const { wrapper } = mountComposer()
			await flushPromises()

			expect(typed(wrapper)).toBe('unfinished')
			expect(wrapper.find('input.content-warning').element.value).toBe('a warning')
			expect(canPost(wrapper)).toBe(true)
		})

		it('does not overwrite a reply mention with a stale draft', async () => {
			const first = mountComposer()
			await setContent(first.wrapper, 'old words')
			first.wrapper.unmount()

			const { wrapper } = mountComposer({ initialMention: carol })
			await flushPromises()

			// the draft is restored first, and the mention prefill declines to
			// overwrite a composer that is not empty
			expect(typed(wrapper)).toBe('old words')
		})

		it('survives storage that cannot be written or read', async () => {
			const getItem = vi.spyOn(localStorage, 'getItem').mockImplementation(() => {
				throw new Error('denied')
			})
			const setItem = vi.spyOn(localStorage, 'setItem').mockImplementation(() => {
				throw new Error('denied')
			})

			const { wrapper } = mountComposer()
			await setContent(wrapper, 'still typeable')

			expect(typed(wrapper)).toBe('still typeable')
			expect(canPost(wrapper)).toBe(true)
			getItem.mockRestore()
			setItem.mockRestore()
		})
	})

	describe('attachments', () => {
		it('uploads a selected file and previews it', async () => {
			const { wrapper, store } = mountComposer()
			const file = new File(['x'], 'cat.png', { type: 'image/png' })

			await attachFile(wrapper, file)

			expect(URL.createObjectURL).toHaveBeenCalledWith(file)
			expect(store.createMedia).toHaveBeenCalledWith(expect.objectContaining({ file }))
			const preview = wrapper.findComponent(PreviewGridItem)
			expect(preview.props('randomKey')).toBe('blob:preview-1')
			expect(preview.find('.loading-icon').exists()).toBe(true)

			await flushPromises()
			expect(preview.props('preview')).toEqual({ file, data: media, failed: false })
			expect(preview.find('img').attributes('src')).toBe(media.preview_url)
		})

		it('drops a preview again when it is deleted', async () => {
			const { wrapper } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()

			await wrapper.findComponent(PreviewGridItem).find('button').trigger('click')

			expect(wrapper.findComponent(PreviewGridItem).exists()).toBe(false)
		})

		it('lets go of the preview URLs once the post is away', async () => {
			const { wrapper } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()
			await setContent(wrapper, 'look at this')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			// the keys were dropped without revoking, so every attachment ever
			// posted stayed in memory for the life of the document
			expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview-1')
		})

		it('uploads no more than the ten a post can carry', async () => {
			const { wrapper, store } = mountComposer()
			const fileInput = wrapper.find('input[type="file"]')
			Object.defineProperty(fileInput.element, 'files', {
				value: Array.from({ length: 11 }, (unused, index) => new File(['x'], `${index}.png`, { type: 'image/png' })),
				configurable: true,
			})

			await fileInput.trigger('change')
			await flushPromises()

			expect(store.createMedia.mock.calls).toHaveLength(10)
			expect(showError).toHaveBeenCalledWith('A post can carry 10 attachments')
		})

		it('opens the file picker from the attachment button', async () => {
			const { wrapper } = mountComposer()
			const click = vi.spyOn(wrapper.find('input[type="file"]').element, 'click')
			await wrapper.find('button[aria-label="Add attachment"]').trigger('click')
			expect(click).toHaveBeenCalledTimes(1)
		})
	})

	describe('attaching from Files', () => {
		const beach = '/Photos/beach.jpg'

		it('attaches the files "Share to Social" handed it, open and without a click', async () => {
			const { wrapper, store } = mountComposer({ initialPaths: [beach, '', '/', '/Videos/talk.mp4'] })
			await flushPromises()

			// the same road the picker takes, so the server sees the path and
			// nothing is uploaded twice
			expect(pickedPaths(store)).toEqual([beach, '/Videos/talk.mp4'])
			expect(wrapper.find('.new-post').classes()).not.toContain('new-post--collapsed')
			expect(wrapper.findComponent(PreviewGridItem).exists()).toBe(true)
		})

		it('attaches nothing when it was handed nothing', async () => {
			const { store } = mountComposer()
			await flushPromises()

			expect(store.createMediaFromFile).not.toHaveBeenCalled()
		})

		it('offers the pictures and videos the reader already has, several at a time', async () => {
			const { wrapper, store } = mountComposer()
			const builder = filePicker(Promise.resolve([beach]))

			await addFromFiles(wrapper)

			expect(builder.setMultiSelect).toHaveBeenCalledWith(true)
			expect(builder.setMimeTypeFilter).toHaveBeenCalledWith(expect.arrayContaining(['image/*', 'video/*', 'application/pdf', 'text/plain']))
			expect(builder.allowDirectories).toHaveBeenCalledWith(false)
			// a picker built with no button renders none: a file could be
			// selected and there was nothing to press to confirm it
			expect(builder.addButton).toHaveBeenCalledWith(expect.objectContaining({
				label: 'Attach',
				variant: 'primary',
			}))
			expect(store.createMediaFromFile).toHaveBeenCalledWith({ path: beach })
		})

		it('sends the path rather than the bytes', async () => {
			// the whole point: a picture that is already on the server does not
			// have to be downloaded and uploaded back
			const { wrapper, store } = mountComposer()
			filePicker(Promise.resolve([beach]))

			await addFromFiles(wrapper)

			expect(store.createMedia).not.toHaveBeenCalled()
			expect(URL.createObjectURL).not.toHaveBeenCalled()
		})

		it('puts what came back where an upload would have put it', async () => {
			const { wrapper } = mountComposer()
			filePicker(Promise.resolve([beach]))

			await addFromFiles(wrapper)

			const preview = wrapper.findComponent(PreviewGridItem)
			expect(preview.props('preview')).toMatchObject({ path: beach, data: media, failed: false })
			expect(preview.find('img').attributes('src')).toBe(media.preview_url)
		})

		it('sends a picked picture with the post, like any other attachment', async () => {
			const { wrapper, store } = mountComposer()
			filePicker(Promise.resolve([beach]))
			await addFromFiles(wrapper)
			await setContent(wrapper, 'the sea')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store)).toMatchObject({ status: 'the sea', media_ids: [media.id] })
		})

		it('keeps the pictures that worked when the server refuses one of them', async () => {
			const { wrapper, store } = mountComposer()
			store.createMediaFromFile.mockImplementation((payload) => Promise.resolve(payload.path === '/Photos/gone.jpg' ? undefined : { ...media, id: payload.path }))
			filePicker(Promise.resolve([beach, '/Photos/gone.jpg', '/Photos/dunes.jpg']))

			await addFromFiles(wrapper)

			const previews = wrapper.findAllComponents(PreviewGridItem)
			expect(previews).toHaveLength(3)
			expect(previews[1].find('.preview-item__failed').exists()).toBe(true)

			await setContent(wrapper, 'two of three')
			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store).media_ids).toEqual([beach, '/Photos/dunes.jpg'])
		})

		it('attaches no more than the ten a post can carry, and says so', async () => {
			const { wrapper, store } = mountComposer()
			const paths = Array.from({ length: 12 }, (unused, index) => `/Photos/${index}.jpg`)
			filePicker(Promise.resolve(paths))

			await addFromFiles(wrapper)

			// the server refuses the eleventh outright, so the refusal has to be
			// explained here, where the ten that fit are not lost with it
			expect(pickedPaths(store)).toEqual(paths.slice(0, 10))
			expect(showError).toHaveBeenCalledWith('A post can carry 10 attachments')
		})

		it('counts an upload already in the post against the same ceiling', async () => {
			const { wrapper, store } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()
			filePicker(Promise.resolve(Array.from({ length: 10 }, (unused, index) => `/Photos/${index}.jpg`)))

			await addFromFiles(wrapper)

			expect(pickedPaths(store)).toHaveLength(9)
			expect(wrapper.findAllComponents(PreviewGridItem)).toHaveLength(10)
		})

		it('offers neither way of attaching once the post is full', async () => {
			const { wrapper } = mountComposer()
			filePicker(Promise.resolve(Array.from({ length: 10 }, (unused, index) => `/Photos/${index}.jpg`)))

			await addFromFiles(wrapper)

			expect(wrapper.find('button[aria-label="Add from Files"]').attributes('disabled')).toBeDefined()
			expect(wrapper.find('button[aria-label="Add attachment"]').attributes('disabled')).toBeDefined()
		})

		it('says the pictures are on their way while the requests are in flight', async () => {
			const { wrapper, store } = mountComposer()
			let finish
			store.createMediaFromFile.mockImplementation(() => new Promise((resolve) => {
				finish = resolve
			}))
			filePicker(Promise.resolve([beach]))

			await addFromFiles(wrapper)

			const bar = wrapper.find('[role="progressbar"]')
			expect(bar.exists()).toBe(true)
			expect(bar.text()).toContain('Attaching from Files…')
			expect(bar.attributes('aria-valuenow')).toBe('0')
			expect(canPost(wrapper)).toBe(false)

			finish(media)
			await flushPromises()

			expect(wrapper.find('[role="progressbar"]').exists()).toBe(false)
		})

		it('takes no for an answer when the dialog is closed', async () => {
			const { wrapper, store } = mountComposer()
			filePicker(Promise.reject(new Error('FilePicker: No nodes selected')))

			await addFromFiles(wrapper)

			// changing one's mind is not a failure to report
			expect(store.createMediaFromFile).not.toHaveBeenCalled()
			expect(showError).not.toHaveBeenCalled()
			expect(wrapper.findComponent(PreviewGridItem).exists()).toBe(false)
		})

		it('has no object URL to let go of when the post is away', async () => {
			const { wrapper } = mountComposer()
			filePicker(Promise.resolve([beach]))
			await addFromFiles(wrapper)
			await setContent(wrapper, 'the sea')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			// the key of a picked attachment is its path, not a blob URL
			expect(URL.revokeObjectURL).not.toHaveBeenCalled()
		})

		it('lets the same picture be attached twice, since the path cannot tell them apart', async () => {
			const { wrapper } = mountComposer()
			filePicker(Promise.resolve([beach, beach]))

			await addFromFiles(wrapper)

			expect(wrapper.findAllComponents(PreviewGridItem)).toHaveLength(2)
		})
	})

	describe('a post built around a picture', () => {
		const mediaFirst = (wrapper) => wrapper.find('.new-post-form').classes().includes('new-post-form--media-first')

		it('reads as a caption box only once there is something to caption', async () => {
			const { wrapper } = mountComposer()
			expect(mediaFirst(wrapper)).toBe(false)
			expect(input(wrapper).attributes('placeholder')).toBe('What would you like to share?')

			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()

			expect(mediaFirst(wrapper)).toBe(true)
			expect(input(wrapper).attributes('placeholder')).toBe('Write a caption…')
			expect(input(wrapper).attributes('aria-label')).toBe('Write a caption…')
			expect(input(wrapper).classes()).toContain('message--caption')
		})

		it('puts the picture ahead of the box it is captioned in', async () => {
			const { wrapper } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()

			const form = wrapper.find('.new-post-form').element
			const order = [...form.querySelectorAll('.preview-grid, .message')].map((el) => el.className)
			expect(order[0]).toContain('preview-grid')
			expect(order[1]).toContain('message')
		})

		it('is a text box again once the last picture is taken out', async () => {
			const { wrapper } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()

			await wrapper.findComponent(PreviewGridItem).find('button').trigger('click')

			expect(mediaFirst(wrapper)).toBe(false)
			expect(input(wrapper).attributes('placeholder')).toBe('What would you like to share?')
		})
	})

	describe('dropping a file on it', () => {
		const card = (wrapper) => wrapper.find('.new-post')
		const lit = (wrapper) => card(wrapper).classes().includes('new-post--drop-target')
		const picture = () => new File(['x'], 'cat.png', { type: 'image/png' })
		const dragOver = (wrapper, dataTransfer = transfer([picture()])) => dispatch(
			wrapper,
			card(wrapper).element,
			dragEvent('dragenter', { dataTransfer }),
		)

		it('lights the whole card up while a file is over it', async () => {
			const { wrapper } = mountComposer()
			expect(lit(wrapper)).toBe(false)

			await dragOver(wrapper)

			expect(lit(wrapper)).toBe(true)
			expect(wrapper.find('.new-post__drop-hint').text()).toBe('Drop to attach')
		})

		it('ignores text dragged around inside it', async () => {
			// a selection moved from one line to the next is not an attachment
			const { wrapper } = mountComposer()

			await dragOver(wrapper, transfer([], ['text/plain']))

			expect(lit(wrapper)).toBe(false)
			expect(wrapper.find('.new-post__drop-hint').exists()).toBe(false)
		})

		it('stays lit when the pointer crosses onto one of its own children', async () => {
			// the classic dragleave trap: leaving the card for the message box
			// inside it is not leaving the card
			const { wrapper } = mountComposer()
			await dragOver(wrapper)

			await dispatch(wrapper, card(wrapper).element, dragEvent('dragleave', {
				dataTransfer: transfer([picture()]),
				relatedTarget: input(wrapper).element,
			}))

			expect(lit(wrapper)).toBe(true)
		})

		it('goes dark when the drag really leaves', async () => {
			const { wrapper } = mountComposer()
			await dragOver(wrapper)

			await dispatch(wrapper, card(wrapper).element, dragEvent('dragleave', {
				dataTransfer: transfer([picture()]),
				relatedTarget: document.body,
			}))

			expect(lit(wrapper)).toBe(false)
		})

		it('goes dark when the drag leaves the window altogether', async () => {
			const { wrapper } = mountComposer()
			await dragOver(wrapper)

			// relatedTarget is null when the pointer left the document
			await dispatch(wrapper, card(wrapper).element, dragEvent('dragleave'))

			expect(lit(wrapper)).toBe(false)
		})

		it('attaches a dropped file exactly as the file dialog does', async () => {
			const { wrapper, store } = mountComposer()
			const file = picture()
			await dragOver(wrapper)

			await dispatch(wrapper, card(wrapper).element, dragEvent('drop', { dataTransfer: transfer([file]) }))
			await flushPromises()

			expect(store.createMedia).toHaveBeenCalledWith(expect.objectContaining({ file }))
			expect(wrapper.findComponent(PreviewGridItem).props('preview')).toEqual({ file, data: media, failed: false })
			expect(lit(wrapper)).toBe(false)
		})

		it('does not let the browser open the file and lose the draft', async () => {
			const { wrapper } = mountComposer()
			const element = card(wrapper).element

			const over = await dispatch(wrapper, element, dragEvent('dragover', { dataTransfer: transfer([picture()]) }))
			const drop = await dispatch(wrapper, element, dragEvent('drop', { dataTransfer: transfer([picture()]) }))
			await flushPromises()

			// an unhandled drag or drop navigates the tab to the file
			expect(over.defaultPrevented).toBe(true)
			expect(drop.defaultPrevented).toBe(true)
		})

		it('refuses a file the file dialog would never have offered', async () => {
			const { wrapper, store } = mountComposer()
			const notes = new File(['x'], 'setup.exe', { type: 'application/x-msdownload' })

			await dispatch(wrapper, card(wrapper).element, dragEvent('drop', { dataTransfer: transfer([notes]) }))
			await flushPromises()

			expect(store.createMedia).not.toHaveBeenCalled()
			expect(wrapper.findComponent(PreviewGridItem).exists()).toBe(false)
			expect(card(wrapper).classes()).toContain('new-post--refused')
		})

		it('takes the pictures out of a mixed drop and leaves the rest', async () => {
			const { wrapper, store } = mountComposer()
			const file = picture()
			const notes = new File(['x'], 'setup.exe', { type: 'application/x-msdownload' })

			await dispatch(wrapper, card(wrapper).element, dragEvent('drop', { dataTransfer: transfer([file, notes]) }))
			await flushPromises()

			const uploads = store.createMedia.mock.calls
			expect(uploads).toHaveLength(1)
			expect(uploads[0][0]).toMatchObject({ file })
		})

		it('opens a closed composer', async () => {
			const { wrapper } = mountComposer()
			expect(card(wrapper).classes()).toContain('new-post--collapsed')

			await dispatch(wrapper, card(wrapper).element, dragEvent('drop', { dataTransfer: transfer([picture()]) }))
			await flushPromises()

			expect(card(wrapper).classes()).not.toContain('new-post--collapsed')
		})
	})

	describe('pasting', () => {
		it('attaches a screenshot from the clipboard', async () => {
			const { wrapper, store } = mountComposer()
			const shot = new File(['x'], 'screenshot.png', { type: 'image/png' })

			const event = await dispatch(wrapper, input(wrapper).element, pasteEvent([shot]))
			await flushPromises()

			// prevented, or the browser drops the image into the box as markup
			// the post cannot carry
			expect(event.defaultPrevented).toBe(true)
			expect(store.createMedia).toHaveBeenCalledWith(expect.objectContaining({ file: shot }))
			expect(wrapper.findComponent(PreviewGridItem).props('preview')).toEqual({ file: shot, data: media, failed: false })
		})

		it('leaves pasted text to the input it was pasted into', async () => {
			const { wrapper, store } = mountComposer()

			const event = await dispatch(wrapper, input(wrapper).element, pasteEvent())
			// what the browser then does, unimpeded
			await setContent(wrapper, 'pasted words')

			expect(event.defaultPrevented).toBe(false)
			expect(store.createMedia).not.toHaveBeenCalled()
			expect(typed(wrapper)).toBe('pasted words')
			expect(canPost(wrapper)).toBe(true)
		})

		it('leaves a pasted file of a kind it cannot take to the input', async () => {
			const { wrapper, store } = mountComposer()
			const notes = new File(['x'], 'setup.exe', { type: 'application/x-msdownload' })

			const event = await dispatch(wrapper, input(wrapper).element, pasteEvent([notes]))
			await flushPromises()

			expect(event.defaultPrevented).toBe(false)
			expect(store.createMedia).not.toHaveBeenCalled()
		})
	})

	describe('emoji', () => {
		it('does not fetch the picker until the button is pressed', async () => {
			// the emoji set is 130 KB over the wire; a composer nobody has typed
			// an emoji into should not have paid for it
			const { wrapper } = mountComposer()

			expect(wrapper.findComponent({ name: 'NcEmojiPicker' }).exists()).toBe(false)
			expect(wrapper.find('button[aria-label="Add emoji"]').exists()).toBe(true)

			await openEmojiPicker(wrapper)

			expect(wrapper.findComponent({ name: 'NcEmojiPicker' }).exists()).toBe(true)
		})

		it('teleports the picker to the app root by default', async () => {
			const { wrapper } = mountComposer()
			await openEmojiPicker(wrapper)

			expect(wrapper.findComponent({ name: 'NcEmojiPicker' }).attributes('container')).toBe('#content')
		})

		it('keeps the picker inside the unclipped modal overlay when requested', async () => {
			// the dialog content scrolls and clips its children; the overlay
			// wrapper is still above the dimmed page but allows the picker to float
			const { wrapper } = mountComposer({ emojiPickerContainer: '.modal-wrapper' })
			await openEmojiPicker(wrapper)

			expect(wrapper.findComponent({ name: 'NcEmojiPicker' }).attributes('container')).toBe('.modal-wrapper')
		})

		it('inserts a picked emoji into an empty message', async () => {
			const { wrapper } = mountComposer()
			await pickEmoji(wrapper, '😀')
			expect(typed(wrapper)).toBe('😀 ')
			expect(canPost(wrapper)).toBe(true)
		})

		it('appends a picked emoji to the text', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'hi')
			await pickEmoji(wrapper, '😀')
			expect(typed(wrapper)).toBe('hi😀 ')
		})

		it('keeps a picked emoji before a trailing line break', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'hi<br>')
			await pickEmoji(wrapper, '🎉')
			expect(input(wrapper).element.innerHTML).toBe('hi🎉 <br>')
		})

		it('unwraps the picker\'s category object to its first emoji', async () => {
			const { wrapper } = mountComposer()
			await pickEmoji(wrapper, { people: { grinning: '😀', wink: '😉' } })
			expect(typed(wrapper)).toBe('😀 ')
		})
	})

	describe('polls', () => {
		it('attaches the poll options, duration and mode to the post', async () => {
			const { wrapper, store } = mountComposer({ defaultVisibility: 'public' })
			await setContent(wrapper, 'Cats or dogs?')

			await wrapper.find('button[aria-label="Add poll"]').trigger('click')
			const inputs = wrapper.findAll('.poll-editor__option input')
			await inputs[0].setValue('Cats')
			await inputs[1].setValue('Dogs')
			await wrapper.find('.poll-editor__settings input[type="checkbox"]').setValue(true)
			await wrapper.find('.poll-editor__settings select').setValue(3600)

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store).poll).toEqual({
				options: ['Cats', 'Dogs'],
				expires_in: 3600,
				multiple: true,
			})
		})

		it('sends no poll when the editor is closed or has fewer than two options', async () => {
			const { wrapper, store } = mountComposer()
			await setContent(wrapper, 'no poll here')

			await wrapper.find('button[aria-label="Add poll"]').trigger('click')
			await wrapper.findAll('.poll-editor__option input')[0].setValue('Only one')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store).poll).toBeUndefined()
		})
	})

	describe('content warnings', () => {
		it('sends the warning and marks the post sensitive', async () => {
			const { wrapper, store } = mountComposer()
			await setContent(wrapper, 'the spoiler itself')
			await addWarning(wrapper, 'season finale')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store).spoiler_text).toBe('season finale')
			expect(postedStatus(store).sensitive).toBe(true)
		})

		it('sends no warning when the field was never opened', async () => {
			const { wrapper, store } = mountComposer()
			await setContent(wrapper, 'nothing to warn about')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store).spoiler_text).toBe('')
			expect(postedStatus(store).sensitive).toBe(false)
		})

		it('drops a warning that was typed and then withdrawn', async () => {
			const { wrapper, store } = mountComposer()
			await setContent(wrapper, 'no longer a spoiler')
			await addWarning(wrapper, 'season finale')
			// the same button closes it again
			await wrapper.find('button[aria-label="Remove content warning"]').trigger('click')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store).spoiler_text).toBe('')
			expect(postedStatus(store).sensitive).toBe(false)
		})

		it('clears the warning once the post is away', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'the spoiler itself')
			await addWarning(wrapper, 'season finale')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(wrapper.find('input.content-warning').exists()).toBe(false)
		})
	})

	describe('describing an attachment', () => {
		const describe = async (wrapper, text) => {
			const field = wrapper.find('.preview-item__description')
			await field.setValue(text)

			return field
		}

		it('offers a description field for every attachment', async () => {
			const { wrapper } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()

			// the field the composer never had: this client could render other
			// servers' alt text but had no way to write any of its own
			expect(wrapper.find('.preview-item__description').exists()).toBe(true)
			expect(wrapper.find('.preview-item__label').attributes('for'))
				.toBe(wrapper.find('.preview-item__description').attributes('id'))
		})

		it('says when an attachment has none, without refusing to post', async () => {
			const { wrapper } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()
			await setContent(wrapper, 'look at this')

			// a nudge, not a gate: nobody should be unable to post because of it
			expect(wrapper.find('.composer-alt-warning').text()).toContain('no description')
			expect(submitButton(wrapper).attributes('disabled')).toBeUndefined()
		})

		it('stops warning once one is written', async () => {
			const { wrapper } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()
			await describe(wrapper, 'a cat asleep on a keyboard')

			expect(wrapper.find('.composer-alt-warning').exists()).toBe(false)
		})

		it('sends the description with the post', async () => {
			const { wrapper, store } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()
			await describe(wrapper, '  a cat asleep on a keyboard  ')
			await setContent(wrapper, 'look at this')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(store.describeMedia).toHaveBeenCalledWith({
				id: media.id,
				description: 'a cat asleep on a keyboard',
			})
		})

		it('saves a description as soon as the field is left', async () => {
			const { wrapper, store } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()
			const field = wrapper.find('.preview-item__description')
			field.element.value = '  a cat asleep on a keyboard  '

			await field.trigger('input')
			// a request per letter is what the local copy is kept to avoid
			expect(store.describeMedia).not.toHaveBeenCalled()

			await field.trigger('change')
			await flushPromises()

			// a description written into a post that never went out is still
			// worth keeping: it belongs to the attachment, not to the post
			expect(store.describeMedia).toHaveBeenCalledWith({
				id: media.id,
				description: 'a cat asleep on a keyboard',
			})
		})

		it('does not send the same description again when the post goes', async () => {
			const { wrapper, store } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()
			const field = wrapper.find('.preview-item__description')
			field.element.value = 'a cat asleep on a keyboard'
			await field.trigger('change')
			await flushPromises()
			await setContent(wrapper, 'look at this')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(store.describeMedia.mock.calls).toHaveLength(1)
		})

		it('describes a picture attached from Files the same way', async () => {
			const { wrapper, store } = mountComposer()
			filePicker(Promise.resolve(['/Photos/beach.jpg']))
			await addFromFiles(wrapper)
			const field = wrapper.find('.preview-item__description')
			field.element.value = 'the sea at dusk'

			await field.trigger('change')
			await flushPromises()

			expect(store.describeMedia).toHaveBeenCalledWith({
				id: media.id,
				description: 'the sea at dusk',
			})
		})

		it('marks a picture nobody described, and stops once one is written', async () => {
			const { wrapper } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()

			// the marker Mastodon puts on the thumbnail: alt text nobody can
			// see is alt text nobody writes
			expect(wrapper.find('.preview-item__missing').text()).toBe('No description')

			await describe(wrapper, 'a cat asleep on a keyboard')

			expect(wrapper.find('.preview-item__missing').exists()).toBe(false)
		})

		it('sends nothing for an attachment left undescribed', async () => {
			const { wrapper, store } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()
			await setContent(wrapper, 'look at this')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(store.describeMedia).not.toHaveBeenCalled()
		})

		/**
		 * Where the subject of a picture is, so a square crop keeps it in
		 * frame. It belongs to the upload rather than to the post, and travels
		 * by the same request the description does.
		 */
		it('saves the focal point against the upload', async () => {
			const { wrapper, store } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()

			const item = wrapper.findComponent(PreviewGridItem)
			item.vm.$emit('commitFocus', { key: item.props('randomKey'), focus: { x: 0.5, y: -0.25 } })
			await flushPromises()

			expect(store.focusMedia).toHaveBeenCalledWith({ id: media.id, focus: '0.5,-0.25' })
		})

		it('shows the point on the thumbnail while it is being moved', async () => {
			const { wrapper, store } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()

			const item = wrapper.findComponent(PreviewGridItem)
			item.vm.$emit('focus', { key: item.props('randomKey'), focus: { x: 1, y: 1 } })
			await flushPromises()

			expect(wrapper.findComponent(PreviewGridItem).props('preview').focus).toEqual({ x: 1, y: 1 })
			// a drag is not a save; the release is
			expect(store.focusMedia).not.toHaveBeenCalled()
		})

		it('saves nothing for an attachment the server never took', async () => {
			const { wrapper, store } = mountComposer()
			store.createMedia.mockResolvedValue(undefined)
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()

			const item = wrapper.findComponent(PreviewGridItem)
			item.vm.$emit('commitFocus', { key: item.props('randomKey'), focus: { x: 0.5, y: 0 } })
			await flushPromises()

			expect(store.focusMedia).not.toHaveBeenCalled()
		})
	})

	describe('posting', () => {
		it('sends the plain text of the message with the attachments and visibility', async () => {
			const { wrapper, store } = mountComposer({ defaultVisibility: 'public' })
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()
			await setContent(wrapper, `<div>${MENTION_BOB}hello <img class="emoji" alt="😀" src="/apps/social/img/twemoji/1f600.svg"> Tom &amp; Jerry</div>`
			+ '<div>second line</div>')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store)).toEqual({
				content_type: '',
				status: '@bob@remote.example hello 😀 Tom & Jerry\nsecond line',
				visibility: 'public',
				media_ids: ['media-1'],
				in_reply_to_id: undefined,
				quote_id: undefined,
				sensitive: false,
				spoiler_text: '',
				language: 'en',
			})
		})

		it('locks the input while sending and clears everything afterwards', async () => {
			const { wrapper, store } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()
			await setContent(wrapper, 'Hello')
			let finishPost
			store.post.mockImplementation(() => new Promise((resolve) => {
				finishPost = resolve
			}))

			await submitButton(wrapper).trigger('click')
			expect(input(wrapper).attributes('contenteditable')).toBe('false')
			expect(input(wrapper).classes()).toContain('icon-loading')
			expect(canPost(wrapper)).toBe(false)

			finishPost({ id: 'new-1' })
			await flushPromises()

			expect(input(wrapper).attributes('contenteditable')).toBe('true')
			expect(typed(wrapper)).toBe('')
			expect(wrapper.findComponent(PreviewGridItem).exists()).toBe(false)
			expect(canPost(wrapper)).toBe(false)
			expect(store.refreshTimeline).toHaveBeenCalled()
		})

		it('posts on Ctrl+Enter but not on Enter alone', async () => {
			const { wrapper, store } = mountComposer()
			await setContent(wrapper, 'Hello')

			await input(wrapper).trigger('keyup', { key: 'Enter' })
			expect(postedStatus(store)).toBeUndefined()

			await input(wrapper).trigger('keyup', { key: 'Enter', ctrlKey: true })
			await flushPromises()
			expect(postedStatus(store)).toMatchObject({ status: 'Hello' })
		})

		it('tells the rest of the app what went out', async () => {
			const { wrapper } = mountComposer()
			const published = vi.fn()
			eventBus.on('post-published', published)
			await setContent(wrapper, 'Hello')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(published).toHaveBeenCalledWith({ id: 'new-1' })
		})

		it('says nothing on the bus when the server refused the post', async () => {
			const { wrapper, store } = mountComposer()
			const published = vi.fn()
			eventBus.on('post-published', published)
			store.post.mockResolvedValue(undefined)
			await setContent(wrapper, 'Hello')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(published).not.toHaveBeenCalled()
		})

		it('sends no media ids after the only attachment was removed', async () => {
			const { wrapper, store } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()
			await wrapper.findComponent(PreviewGridItem).find('button').trigger('click')
			await setContent(wrapper, 'No picture after all')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store)).toMatchObject({ media_ids: [] })
		})
	})

	describe('replying', () => {
		it('shows who is being answered and adopts the visibility of their post', async () => {
			const { wrapper } = mountComposer({ defaultVisibility: 'public' })

			eventBus.emit('composer-reply', replyTo(bob))
			await flushPromises()

			const banner = wrapper.find('.reply-to')
			expect(banner.find('.reply-info').text()).toContain('In reply to')
			expect(banner.find('strong').text()).toBe('bob@remote.example')
			expect(banner.text()).toContain('Original post')
			expect(currentVisibility(wrapper)).toBe('unlisted')
		})

		it('prefills a mention of the remote author', async () => {
			const { wrapper } = mountComposer()

			eventBus.emit('composer-reply', replyTo(bob))
			await flushPromises()

			const mention = input(wrapper).find('.mention a')
			expect(mention.attributes('href')).toBe('https://remote.example/@bob')
			expect(mention.find('img').attributes('src')).toBe(bob.avatar)
			expect(mention.text()).toBe('@bob@remote.example')
			expect(canPost(wrapper)).toBe(true)
		})

		it('completes the handle of a local author with the instance host', async () => {
			const { wrapper } = mountComposer()

			eventBus.emit('composer-reply', replyTo(carol))
			await flushPromises()

			expect(input(wrapper).find('.mention a').text()).toBe('@carol@cloud.example.org')
		})

		/**
		 * A reply used to name the author and nobody else, so an answer in a
		 * conversation of three reached one of three: the others were neither
		 * addressed nor delivered to.
		 */
		it('prefills a mention of everyone in the conversation', async () => {
			const { wrapper } = mountComposer()

			eventBus.emit('composer-reply', replyTo(bob, {
				mentions: [
					{ id: '3', acct: 'carol', username: 'carol', url: 'https://cloud.example.org/@carol' },
					{ id: '4', acct: 'dave@other.example', username: 'dave', url: 'https://other.example/@dave' },
				],
			}))
			await flushPromises()

			expect(input(wrapper).findAll('.mention a').map((mention) => mention.text()))
				.toEqual(['@bob@remote.example', '@carol@cloud.example.org', '@dave@other.example'])
		})

		/** A reply that addresses its own author is talking to itself. */
		it('does not address the reader', async () => {
			const { wrapper } = mountComposer()

			eventBus.emit('composer-reply', replyTo(bob, {
				mentions: [
					{ id: '1', acct: 'alice', username: 'alice', url: 'https://cloud.example.org/@alice' },
					{ id: '3', acct: 'carol', username: 'carol', url: 'https://cloud.example.org/@carol' },
				],
			}))
			await flushPromises()

			expect(input(wrapper).findAll('.mention a').map((mention) => mention.text()))
				.toEqual(['@bob@remote.example', '@carol@cloud.example.org'])
		})

		it('names each participant once, whether or not the handle carries its host', async () => {
			const { wrapper } = mountComposer()

			eventBus.emit('composer-reply', replyTo(carol, {
				mentions: [
					{ id: '3', acct: 'carol@cloud.example.org', username: 'carol', url: 'https://cloud.example.org/@carol' },
					{ id: '2', acct: 'bob@remote.example', username: 'bob', url: 'https://remote.example/@bob' },
					{ id: '2', acct: 'bob@remote.example', username: 'bob', url: 'https://remote.example/@bob' },
				],
			}))
			await flushPromises()

			expect(input(wrapper).findAll('.mention a').map((mention) => mention.text()))
				.toEqual(['@carol@cloud.example.org', '@bob@remote.example'])
		})

		/** A Mention entity off a post carries no picture. */
		it('leaves out the avatar of a participant rather than showing a broken one', async () => {
			const { wrapper } = mountComposer()

			eventBus.emit('composer-reply', replyTo(bob, {
				mentions: [{ id: '3', acct: 'carol', username: 'carol', url: 'https://cloud.example.org/@carol' }],
			}))
			await flushPromises()

			const mentions = input(wrapper).findAll('.mention a')
			expect(mentions[0].find('img').exists()).toBe(true)
			expect(mentions[1].find('img').exists()).toBe(false)
		})

		it('sends the reply addressed to everyone it named', async () => {
			const { wrapper, store } = mountComposer()

			eventBus.emit('composer-reply', replyTo(bob, {
				mentions: [{ id: '3', acct: 'carol', username: 'carol', url: 'https://cloud.example.org/@carol' }],
			}))
			await flushPromises()

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			// the server turns the handles in the text into recipients and
			// inboxes; what the composer owes it is the handles, separated the
			// way the mention pills are
			expect(postedStatus(store).status)
				.toBe('@bob@remote.example\u00a0@carol@cloud.example.org')
		})

		it('survives a post that carries no mentions at all', async () => {
			const { wrapper } = mountComposer()

			eventBus.emit('composer-reply', replyTo(bob, { mentions: undefined }))
			await flushPromises()

			expect(input(wrapper).findAll('.mention a')).toHaveLength(1)
		})

		it('does not overwrite a message that is already being written', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'my draft')

			eventBus.emit('composer-reply', replyTo(bob))
			await flushPromises()

			expect(wrapper.find('.reply-to').exists()).toBe(true)
			expect(input(wrapper).find('.mention').exists()).toBe(false)
			expect(input(wrapper).text()).toBe('my draft')
		})

		it('sends the reply as an answer to the original post', async () => {
			const { wrapper, store } = mountComposer()
			eventBus.emit('composer-reply', replyTo(bob))
			await flushPromises()

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store)).toMatchObject({
				in_reply_to_id: '42',
				visibility: 'unlisted',
				status: '@bob@remote.example',
			})
			expect(wrapper.find('.reply-to').exists()).toBe(false)
		})

		it('can be dismissed, which also hides the composer', async () => {
			const { wrapper, store } = mountComposer()
			eventBus.emit('composer-reply', replyTo(bob))
			await flushPromises()

			await wrapper.find('.reply-to button[aria-label="Close reply"]').trigger('click')

			expect(wrapper.find('.reply-to').exists()).toBe(false)
			expect(store.setComposerDisplayStatus).toHaveBeenCalledWith(false)
		})
	})

	describe('anchored under the post it replies to', () => {
		it('starts pointed at that post, closed, and without quoting it back', async () => {
			const { wrapper } = mountComposer({ inReplyTo: replyTo(bob) })
			await flushPromises()

			// the post is directly above the box: repeating it inside the box,
			// with a button for closing a reply that has nowhere else to go,
			// is the page saying the same thing twice
			expect(wrapper.find('.reply-to').exists()).toBe(false)
			// and a box under every post, open, would be eight controls and a
			// text area under every post
			expect(wrapper.classes()).toContain('new-post--collapsed')
			expect(wrapper.find('.message').attributes('placeholder')).toBe('Write a reply…')
		})

		it('opens on a click into it', async () => {
			const { wrapper } = mountComposer({ inReplyTo: replyTo(bob) })

			await wrapper.trigger('focusin')

			expect(wrapper.classes()).not.toContain('new-post--collapsed')
		})

		it('opens when reply is pressed on the post it sits under', async () => {
			const { wrapper } = mountComposer({ inReplyTo: replyTo(bob) })

			// the target does not change, so the box opening is the whole of
			// what the button can do — without this the press did nothing
			eventBus.emit('composer-reply', replyTo(bob))
			await flushPromises()

			expect(wrapper.classes()).not.toContain('new-post--collapsed')
			expect(wrapper.find('.reply-to').exists()).toBe(false)
		})

		it('closes again once the reply has been sent', async () => {
			const { wrapper } = mountComposer({ inReplyTo: replyTo(bob) })
			await setContent(wrapper, 'answering')
			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(wrapper.classes()).toContain('new-post--collapsed')
		})

		it('sends what is typed as a reply to that post, in its audience', async () => {
			const { wrapper, store } = mountComposer({ inReplyTo: replyTo(bob) })
			await setContent(wrapper, 'answering')
			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store)).toMatchObject({ in_reply_to_id: '42', visibility: 'unlisted', status: 'answering' })
		})

		it('is still pointed at the post after sending one', async () => {
			const { wrapper, store } = mountComposer({ inReplyTo: replyTo(bob) })
			await setContent(wrapper, 'answering')
			await submitButton(wrapper).trigger('click')
			await flushPromises()
			await setContent(wrapper, 'and again')
			await submitButton(wrapper).trigger('click')
			await flushPromises()

			// a box that emptied itself of its target would send the second
			// reply to nobody, as a post of its own
			expect(postedStatus(store, 1)).toMatchObject({ in_reply_to_id: '42', status: 'and again' })
		})

		it('can be retargeted at another post in the thread, and says so', async () => {
			const { wrapper } = mountComposer({ inReplyTo: replyTo(bob) })
			eventBus.emit('composer-reply', replyTo(carol, { id: '99', visibility: 'public' }))
			await flushPromises()

			expect(wrapper.find('.reply-to').find('strong').text()).toBe('carol')
			expect(currentVisibility(wrapper)).toBe('public')
		})

		it('comes back to the post it is anchored under when that is dismissed', async () => {
			const { wrapper, store } = mountComposer({ inReplyTo: replyTo(bob) })
			eventBus.emit('composer-reply', replyTo(carol, { id: '99' }))
			await flushPromises()

			await wrapper.find('.reply-to button[aria-label="Close reply"]').trigger('click')
			await flushPromises()

			expect(wrapper.find('.reply-to').exists()).toBe(false)
			// still open, because the mention prefilled for carol is still in
			// the box: what the reader has not sent is not thrown away
			expect(typed(wrapper)).toContain('@carol')
			// closing a reply hides a composer that was opened to write it;
			// this one is part of the page and has nothing to close back to
			expect(store.setComposerDisplayStatus).not.toHaveBeenCalled()
		})

		it("follows the reader to another post's page", async () => {
			const { wrapper, store } = mountComposer({ inReplyTo: replyTo(bob) })

			await wrapper.setProps({ inReplyTo: replyTo(carol, { id: '99' }) })
			await setContent(wrapper, 'answering')
			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store)).toMatchObject({ in_reply_to_id: '99' })
		})
	})

	describe('quoting', () => {
		it('shows which post is being quoted', async () => {
			const { wrapper } = mountComposer()

			eventBus.emit('composer-quote', quoteOf(bob))
			await flushPromises()

			const banner = wrapper.find('.quote-of')
			expect(banner.find('.quote-info').text()).toContain('Quoting')
			expect(banner.find('strong').text()).toBe('bob@remote.example')
			expect(banner.text()).toContain('The post being quoted')
		})

		it('leaves the audience of the new post alone, unlike a reply', async () => {
			// the quote is addressed by whoever writes it; adopting the quoted
			// post's visibility would be answering a question nobody asked
			const { wrapper } = mountComposer({ defaultVisibility: 'followers' })

			eventBus.emit('composer-quote', quoteOf(bob, { visibility: 'public' }))
			await flushPromises()

			expect(wrapper.find('.quote-of').exists()).toBe(true)
			expect(currentVisibility(wrapper)).toBe('followers')
		})

		it('sends the quote with the post', async () => {
			const { wrapper, store } = mountComposer()
			eventBus.emit('composer-quote', quoteOf(bob))
			await flushPromises()
			await setContent(wrapper, 'worth reading')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store)).toMatchObject({ quote_id: '77', status: 'worth reading' })
			expect(wrapper.find('.quote-of').exists()).toBe(false)
		})

		it('can be taken back before posting, without losing what was written', async () => {
			const { wrapper, store } = mountComposer()
			eventBus.emit('composer-quote', quoteOf(bob))
			await flushPromises()
			await setContent(wrapper, 'on second thoughts')

			await wrapper.find('.quote-of button[aria-label="Remove quote"]').trigger('click')

			expect(wrapper.find('.quote-of').exists()).toBe(false)
			expect(typed(wrapper)).toBe('on second thoughts')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store).quote_id).toBeUndefined()
			expect(postedStatus(store).status).toBe('on second thoughts')
		})

		it('quotes and replies in the same post', async () => {
			const { wrapper, store } = mountComposer()
			eventBus.emit('composer-reply', replyTo(carol))
			eventBus.emit('composer-quote', quoteOf(bob))
			await flushPromises()

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store)).toMatchObject({ in_reply_to_id: '42', quote_id: '77' })
		})

		it('is open from the start when it carries a quote', async () => {
			const { wrapper } = mountComposer()

			eventBus.emit('composer-quote', quoteOf(bob))
			await wrapper.vm.$nextTick()

			expect(wrapper.find('.new-post').classes()).not.toContain('new-post--collapsed')
		})

		it('listens for quotes only while mounted', () => {
			const { wrapper } = mountComposer()
			expect(eventBus.all.get('composer-quote')).toHaveLength(1)

			wrapper.unmount()
			wrappers.pop()

			expect(eventBus.all.get('composer-quote') ?? []).toHaveLength(0)
			expect(() => eventBus.emit('composer-quote', quoteOf(bob))).not.toThrow()
		})
	})

	describe('initial mention', () => {
		it('starts the message with a mention of the given account', async () => {
			const { wrapper } = mountComposer({ initialMention: bob })
			await flushPromises()
			expect(input(wrapper).find('.mention a').text()).toBe('@bob@remote.example')
			expect(canPost(wrapper)).toBe(true)
		})
	})

	describe('mention autocomplete', () => {
		it('is wired up to the message input', () => {
			const { wrapper } = mountComposer()
			expect(input(wrapper).attributes('data-tribute')).toBe('true')
		})

		it('lets go of the message input when the composer disappears', async () => {
			const { wrapper } = mountComposer()
			const message = input(wrapper).element
			wrappers.pop()

			expect(() => wrapper.unmount()).not.toThrow()

			// tributejs cleans the marker off the element one macrotask later
			await new Promise((resolve) => setTimeout(resolve))
			expect(message.hasAttribute('data-tribute')).toBe(false)
		})
	})

	describe('the compose shortcut', () => {
		it('puts the caret in the composer when the shortcut fires', async () => {
			const { wrapper } = mountComposer()
			const input = wrapper.find('.message').element
			input.focus = vi.fn()
			input.scrollIntoView = vi.fn()

			eventBus.emit('shortcut:compose')
			await wrapper.vm.$nextTick()

			expect(input.focus).toHaveBeenCalled()
			// the composer sits above a timeline that may be scrolled away
			expect(input.scrollIntoView).toHaveBeenCalled()
		})

		it('stops answering once it is gone', async () => {
			const { wrapper } = mountComposer()
			const input = wrapper.find('.message').element
			input.focus = vi.fn()
			wrapper.unmount()

			eventBus.emit('shortcut:compose')

			expect(input.focus).not.toHaveBeenCalled()
		})
	})

	describe('opening and closing', () => {
		const collapsed = (wrapper) => wrapper.find('.new-post').classes().includes('new-post--collapsed')
		// jsdom has no PointerEvent; what the composer listens for is the type
		const clickOutside = async (wrapper, target = document.body) => {
			target.dispatchEvent(new Event('pointerdown', { bubbles: true }))
			await wrapper.vm.$nextTick()
		}

		it('starts as a line to write on, with the controls put away', () => {
			const { wrapper } = mountComposer()

			expect(collapsed(wrapper)).toBe(true)
		})

		it('opens when the reader puts the caret in it', async () => {
			const { wrapper } = mountComposer()

			await input(wrapper).trigger('focusin')

			expect(collapsed(wrapper)).toBe(false)
		})

		it('closes again when the reader turns to something else', async () => {
			const { wrapper } = mountComposer()
			await input(wrapper).trigger('focusin')

			await clickOutside(wrapper)

			expect(collapsed(wrapper)).toBe(true)
		})

		it('stays open while it holds something that closing would hide', async () => {
			const { wrapper } = mountComposer()
			await input(wrapper).trigger('focusin')
			await setContent(wrapper, 'half a thought')

			await clickOutside(wrapper)

			expect(collapsed(wrapper)).toBe(false)
		})

		it('stays open while the reader is in a menu of its own', async () => {
			// the emoji picker is rendered outside this element, so following it
			// with the caret looks exactly like leaving
			const { wrapper } = mountComposer()
			await input(wrapper).trigger('focusin')
			const popper = document.createElement('div')
			popper.className = 'v-popper__popper'
			document.body.appendChild(popper)

			await clickOutside(wrapper, popper)

			expect(collapsed(wrapper)).toBe(false)
			popper.remove()
		})

		it('is already open where writing a post is the whole point', () => {
			const { wrapper } = mountComposer({ startExpanded: true })

			expect(collapsed(wrapper)).toBe(false)
		})

		it('is open from the start when it carries a reply', async () => {
			const { wrapper } = mountComposer()
			eventBus.emit('composer-reply', replyTo())
			await wrapper.vm.$nextTick()

			expect(collapsed(wrapper)).toBe(false)
		})
	})

	describe('the language of a post', () => {
		/**
		 * A post that says nothing about its language federated as
		 * `language: null`: nobody filtering by language saw it, and nobody
		 * filtering it out was spared it.
		 */
		it('sends the language with the post', async () => {
			const { wrapper, store } = mountComposer()
			await setContent(wrapper, 'Hello')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store)).toMatchObject({ language: 'en' })
		})

		it('starts in the language Nextcloud is set to', () => {
			setLanguage('de_DE')
			const { wrapper } = mountComposer()

			// the region says how the interface is spelled, not what the post
			// is written in
			expect(wrapper.findComponent(LanguageSelect).props('language')).toBe('de')
		})

		it('starts in the language the last post went out in', () => {
			setLanguage('de')
			localStorage.setItem('social.lastLanguage::alice', 'fr')
			const { wrapper } = mountComposer()

			expect(wrapper.findComponent(LanguageSelect).props('language')).toBe('fr')
		})

		it('sends the language the writer chose', async () => {
			const { wrapper, store } = mountComposer()
			await setContent(wrapper, 'Bonjour')
			wrapper.findComponent(LanguageSelect).vm.$emit('update:language', 'fr')
			await flushPromises()

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store)).toMatchObject({ language: 'fr' })
		})
	})

	describe('scheduling', () => {
		const clock = (wrapper) => wrapper.find('.schedule-toggle')
		const picker = (wrapper) => wrapper.findComponent({ name: 'NcDateTimePicker' })
		const inAnHour = () => new Date(Date.now() + 60 * 60 * 1000)

		/**
		 * Presses the clock and waits for the picker, which is fetched when
		 * the button is pressed rather than with every composer.
		 *
		 * @param {object} wrapper the mounted composer
		 * @param {Date} [when] the time to set, an hour from now by default
		 */
		async function schedule(wrapper, when = inAnHour()) {
			await clock(wrapper).trigger('click')
			await flushPromises()
			picker(wrapper).vm.$emit('update:modelValue', when)
			await flushPromises()

			return when
		}

		it('does not fetch the date picker until the clock is pressed', () => {
			const { wrapper } = mountComposer()

			expect(wrapper.find('.schedule-editor').exists()).toBe(false)
			expect(picker(wrapper).exists()).toBe(false)
		})

		it('proposes a time rather than a blank once the clock is pressed', async () => {
			const { wrapper } = mountComposer()

			await clock(wrapper).trigger('click')
			await flushPromises()

			expect(wrapper.find('.schedule-editor').exists()).toBe(true)
			expect(picker(wrapper).props('modelValue').getTime())
				.toBeGreaterThan(Date.now() + 5 * 60 * 1000)
		})

		it('says that the post is being scheduled rather than sent', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'Later')
			expect(submitButton(wrapper).text()).toContain('Post')

			await schedule(wrapper)

			expect(submitButton(wrapper).text()).toBe('Schedule')
		})

		it('sends where the post was taken, by id for a known place and by name for a new one', async () => {
			const { wrapper, store } = mountComposer()
			await setContent(wrapper, 'The pier')
			await wrapper.find('button.place-toggle').trigger('click')
			const picker = wrapper.findComponent({ name: 'PlacePicker' })
			expect(picker.exists()).toBe(true)

			picker.vm.$emit('update:place', { id: '4', name: 'Berlin', country: 'Germany' })
			await submitButton(wrapper).trigger('click')
			await flushPromises()
			expect(postedStatus(store)).toMatchObject({ place_id: '4' })
			expect(postedStatus(store)).not.toHaveProperty('place_name')

			const again = mountComposer()
			await setContent(again.wrapper, 'The cove')
			await again.wrapper.find('button.place-toggle').trigger('click')
			again.wrapper.findComponent({ name: 'PlacePicker' }).vm.$emit('update:place', { name: 'Tiny Cove', country: 'Ireland' })
			await submitButton(again.wrapper).trigger('click')
			await flushPromises()
			expect(postedStatus(again.store)).toMatchObject({ place_name: 'Tiny Cove', place_country: 'Ireland' })
		})

		it('releasing the pin drops the place, so an unpressed pin means what it says', async () => {
			const { wrapper, store } = mountComposer()
			await setContent(wrapper, 'Nowhere in particular')
			await wrapper.find('button.place-toggle').trigger('click')
			wrapper.findComponent({ name: 'PlacePicker' }).vm.$emit('update:place', { id: '4', name: 'Berlin', country: '' })
			await wrapper.find('button.place-toggle').trigger('click')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store)).not.toHaveProperty('place_id')
		})

		it('sends the time the post is to go out, in UTC', async () => {
			const { wrapper, store } = mountComposer()
			await setContent(wrapper, 'The release notes')
			const when = await schedule(wrapper)

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store)).toMatchObject({
				status: 'The release notes',
				scheduled_at: when.toISOString(),
			})
		})

		/**
		 * Nothing is on any timeline yet, so there is nothing to refresh and
		 * no post to celebrate; what there is to say is when it will be.
		 */
		it('does not pretend a scheduled post has been published', async () => {
			const { wrapper, store } = mountComposer()
			const published = vi.fn()
			const scheduled = vi.fn()
			eventBus.on('post-published', published)
			eventBus.on('post-scheduled', scheduled)
			await setContent(wrapper, 'The release notes')
			await schedule(wrapper)

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(store.refreshTimeline).not.toHaveBeenCalled()
			expect(published).not.toHaveBeenCalled()
			expect(scheduled).toHaveBeenCalledTimes(1)
			expect(showSuccess).toHaveBeenCalledWith(expect.stringContaining('Scheduled for'))
		})

		it('goes back to posting now, and stops sending a time', async () => {
			const { wrapper, store } = mountComposer()
			await setContent(wrapper, 'Now then')
			await schedule(wrapper)

			await clock(wrapper).trigger('click')
			await flushPromises()

			expect(wrapper.find('.schedule-editor').exists()).toBe(false)
			expect(submitButton(wrapper).text()).toContain('Post')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus(store).scheduled_at).toBeUndefined()
		})

		/** The server refuses anything sooner, so the refusal comes first. */
		it('refuses a time the server would refuse, and says why', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'In a minute')

			await schedule(wrapper, new Date(Date.now() + 60 * 1000))

			expect(wrapper.find('.schedule-editor__hint').text())
				.toBe('Pick a time at least five minutes from now.')
			expect(canPost(wrapper)).toBe(false)
		})

		it('clears the time once the post has been scheduled', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'The release notes')
			await schedule(wrapper)

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(wrapper.find('.schedule-editor').exists()).toBe(false)
			expect(typed(wrapper)).toBe('')
		})
	})

	describe('lifecycle', () => {
		it('listens for replies only while mounted', () => {
			const { wrapper } = mountComposer()
			expect(eventBus.all.get('composer-reply')).toHaveLength(1)

			wrapper.unmount()
			wrappers.pop()

			expect(eventBus.all.get('composer-reply') ?? []).toHaveLength(0)
			expect(() => eventBus.emit('composer-reply', replyTo(bob))).not.toThrow()
		})
	})

	describe('writing a deleted post again', () => {
		const deleted = (extra = {}) => ({
			id: '101',
			content: '<p>Hello <strong>world</strong></p>',
			spoiler_text: '',
			visibility: 'followers',
			language: 'de',
			media_attachments: [],
			account: bob,
			...extra,
		})

		it('brings the words back as they were typed', async () => {
			const { wrapper } = mountComposer()

			eventBus.emit('composer-redraft', deleted())
			await flushPromises()

			expect(input(wrapper).element.innerText).toBe('Hello world')
		})

		it('brings the content warning and the audience with them', async () => {
			const { wrapper } = mountComposer()

			eventBus.emit('composer-redraft', deleted({ spoiler_text: 'spoilers' }))
			await flushPromises()

			expect(wrapper.find('input.content-warning').element.value).toBe('spoilers')
			expect(currentVisibility(wrapper)).toBe('followers')
		})

		/**
		 * The pictures are the uploads the server still holds: deleting a post
		 * removes the post, not the media rows behind it, so they go back on
		 * by id rather than being uploaded again.
		 */
		it('puts the pictures back by id, without uploading anything', async () => {
			const { wrapper, store } = mountComposer()

			eventBus.emit('composer-redraft', deleted({
				media_attachments: [{ id: 'm-7', description: 'a dog', url: 'https://cloud.example.org/m/7' }],
			}))
			await flushPromises()
			await setContent(wrapper, 'again')
			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(store.createMedia).not.toHaveBeenCalled()
			expect(postedStatus(store).media_ids).toEqual(['m-7'])
		})

		/**
		 * A re-draft arrives from a menu two clicks away. Overwriting
		 * half-written words with an old post is not a correction anybody
		 * asked for.
		 */
		it('leaves what is already being written alone', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'something else')

			eventBus.emit('composer-redraft', deleted())
			await flushPromises()

			expect(input(wrapper).element.innerText).toBe('something else')
		})
	})

	describe('posting as a team', () => {
		const press = { acct: 'press', username: 'press', display_name: 'The press office' }

		it('offers nothing at all on a server with no team accounts', async () => {
			const { wrapper } = mountComposer()
			await flushPromises()

			expect(wrapper.find('.composer-post-as').exists()).toBe(false)
		})

		it('offers the teams this account is in, and itself', async () => {
			get.mockResolvedValue({ data: { teams: [press] } })

			const { wrapper } = mountComposer()
			await flushPromises()

			const picker = wrapper.find('.composer-post-as')
			expect(picker.exists()).toBe(true)
			expect(picker.findAll('option').map((one) => one.text()))
				.toEqual(['As myself', 'The press office'])
		})

		it('sends nothing extra when the post is written as yourself', async () => {
			get.mockResolvedValue({ data: { teams: [press] } })

			const { wrapper, store } = mountComposer()
			await flushPromises()
			await setContent(wrapper, 'hello')
			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(store.post).toHaveBeenCalledWith(expect.not.objectContaining({ post_as: expect.anything() }))
		})

		it('names the team when the post is written as one', async () => {
			get.mockResolvedValue({ data: { teams: [press] } })

			const { wrapper, store } = mountComposer()
			await flushPromises()
			await wrapper.find('.composer-post-as').setValue('press')
			await setContent(wrapper, 'hello')
			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(store.post).toHaveBeenCalledWith(expect.objectContaining({ post_as: 'press' }))
		})
	})

	describe('the composer as a toy', () => {
		/** the roll plays at the speed a reader who asked for less motion gets */
		const quickRoll = () => vi.spyOn(window, 'matchMedia').mockImplementation((query) => ({
			matches: query.includes('reduce'),
			addEventListener: () => {},
			removeEventListener: () => {},
		}))

		beforeEach(() => {
			feel.mockReset()
			renderTextCard.mockReset().mockResolvedValue(new File(['png'], 'card.png', { type: 'image/png' }))
		})

		afterEach(() => {
			vi.restoreAllMocks()
		})

		it('says a game in the box is played when the post goes out', async () => {
			const { wrapper } = mountComposer()

			await setContent(wrapper, 'Lunch?')
			expect(wrapper.find('.composer-games').exists()).toBe(false)

			await setContent(wrapper, 'Who pays? /dice')
			expect(wrapper.find('.composer-games').text()).toContain('Played when you post')
		})

		/** the result is decided here and sent as text, so every server shows the same one */
		it('plays the games before sending, and sends the result', async () => {
			quickRoll()
			vi.spyOn(Math, 'random').mockReturnValue(0.1)
			const { wrapper, store } = mountComposer()

			await setContent(wrapper, 'Toss for it: /flip')
			await submitButton(wrapper).trigger('click')
			await vi.waitUntil(() => store.post.mock.calls.length > 0, { timeout: 3000 })

			expect(store.post).toHaveBeenCalledWith(expect.objectContaining({ status: 'Toss for it: 🪙 heads' }))
			expect(feel).toHaveBeenCalledWith('roll')
		})

		it('offers a card only for a short post with nothing else in it', async () => {
			const { wrapper } = mountComposer()

			await setContent(wrapper, 'Friday!')
			expect(wrapper.find('.card-toggle').exists()).toBe(true)

			await setContent(wrapper, 'x'.repeat(121))
			expect(wrapper.find('.card-toggle').exists()).toBe(false)
		})

		/**
		 * The card is the words drawn as a picture, described with those same
		 * words; the post keeps them too, so they are searchable and read aloud.
		 */
		it('posts a card as a picture of the words, described with them', async () => {
			const { wrapper, store } = mountComposer()

			await setContent(wrapper, 'Friday!')
			await wrapper.find('.card-toggle').trigger('click')
			expect(wrapper.find('.composer-card__text').text()).toBe('Friday!')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(renderTextCard).toHaveBeenCalledWith('Friday!', expect.objectContaining({ id: 'account' }), { width: 1080, height: 1080 })
			expect(store.createMedia).toHaveBeenCalledWith(expect.objectContaining({ name: 'card.png' }))
			expect(store.describeMedia).toHaveBeenCalledWith({ id: 'media-1', description: 'Friday!' })
			expect(store.post).toHaveBeenCalledWith(expect.objectContaining({ status: 'Friday!', media_ids: ['media-1'] }))
		})

		it('does not post at all when the card could not be drawn', async () => {
			renderTextCard.mockResolvedValue(null)
			const { wrapper, store } = mountComposer()

			await setContent(wrapper, 'Friday!')
			await wrapper.find('.card-toggle').trigger('click')
			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('This browser could not draw the card')
			expect(store.post).not.toHaveBeenCalled()
		})

		/** the box answers, and the post makes an entrance at the top of the timeline */
		it('marks the new post as just arrived and answers the press', async () => {
			const { wrapper, store } = mountComposer()
			const markArrived = vi.spyOn(store, 'markArrived')

			await setContent(wrapper, 'Hello')
			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(markArrived).toHaveBeenCalledWith('new-1')
			expect(feel).toHaveBeenCalledWith('post')
			expect(wrapper.find('.new-post').classes()).toContain('new-post--posted')
		})
	})
})
