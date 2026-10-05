/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import { getCanonicalLocale } from '@nextcloud/l10n'
import { showError, showSuccess } from '../../../src/services/toast.js'
import ProfileInfo from '../../../src/components/ProfileInfo.vue'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

vi.mock('../../../src/services/toast.js', async (importOriginal) => ({
	...await importOriginal(),
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

const NcAvatarStub = {
	name: 'NcAvatar',
	props: ['url', 'user', 'size', 'disableTooltip'],
	template: '<span class="nc-avatar-stub" />',
}
const FollowButtonStub = {
	name: 'FollowButton',
	props: ['uid'],
	template: '<button class="follow-button-stub" />',
}
const RouterLinkStub = {
	name: 'RouterLink',
	props: ['to'],
	template: '<a class="router-link-stub"><slot /></a>',
}
const NcModalStub = {
	name: 'NcModal',
	emits: ['close'],
	template: '<div class="modal-stub"><slot /></div>',
}
// NcActions only renders its entries inside a popover once opened; these
// stand-ins render them inline so the menu content can be asserted.
const NcActionsStub = { name: 'NcActions', template: '<div class="profile-menu"><slot /></div>' }
const NcActionButtonStub = {
	name: 'NcActionButton',
	emits: ['click'],
	template: '<button class="profile-menu__item" @click="$emit(\'click\')"><slot /></button>',
}

const bob = {
	id: 'https://remote.example/users/bob',
	url: 'https://remote.example/users/bob',
	acct: 'bob@remote.example',
	username: 'bob',
	display_name: 'Bob',
	avatar: 'https://remote.example/media/bob.png',
	header: 'https://remote.example/media/bob-header.png',
	statuses_count: 12,
	following_count: 3,
	followers_count: 5,
	fields: [],
}
const carol = {
	id: 'https://cloud.example.org/users/carol',
	url: 'https://cloud.example.org/users/carol',
	acct: 'carol',
	username: 'carol',
	display_name: null,
	avatar: 'https://cloud.example.org/avatar/carol/128',
	header: '',
	statuses_count: 0,
	following_count: 0,
	followers_count: 1,
	fields: [],
}
const alice = {
	id: 'https://cloud.example.org/users/alice',
	url: 'https://cloud.example.org/users/alice',
	acct: 'alice',
	username: 'alice',
	display_name: 'Alice',
	avatar: 'https://cloud.example.org/avatar/alice/128',
	header: '',
	statuses_count: 7,
	following_count: 2,
	followers_count: 2,
	fields: [],
}

let pinia
let accountStore

// the actions the profile takes, behind one mock, so "nothing was asked of the
// store" stays a single assertion
const ACCOUNT_ACTIONS = ['blockAccount', 'unblockAccount', 'muteAccount', 'unmuteAccount', 'fetchAccountInfo']

function spyOnAccountActions(result) {
	const dispatch = vi.fn().mockResolvedValue(result)
	ACCOUNT_ACTIONS.forEach((name) => vi.spyOn(accountStore, name).mockImplementation(dispatch))

	return dispatch
}

function makeStore(serverData = {}) {
	pinia = createPinia()
	setActivePinia(pinia)
	accountStore = useAccountStore()
	useSettingsStore().setServerData({ public: false, cloudAddress: 'https://cloud.example.org', ...serverData })
	for (const data of [bob, carol, alice]) {
		accountStore.addAccount({ actorId: data.url, data })
	}

	return pinia
}

function mountProfile(uid) {
	return mount(ProfileInfo, {
		props: { uid },
		global: {
			plugins: [pinia],
			stubs: {
				NcAvatar: NcAvatarStub,
				FollowButton: FollowButtonStub,
				RouterLink: RouterLinkStub,
				NcModal: NcModalStub,
				NcActions: NcActionsStub,
				NcActionButton: NcActionButtonStub,
				// it reads the account's highlights on mount, which is its own
				// request and has its own tests; left real it would answer
				// after these tests have finished
				ProfileHighlights: { name: 'ProfileHighlights', props: ['accountId'], template: '<div class="profile-highlights-stub" />' },
				FeaturedTags: { name: 'FeaturedTags', props: ['accountId'], template: '<nav class="featured-tags-stub" />' },
			},
		},
	})
}

const linkTexts = (wrapper) => wrapper.findAll('.user-profile__info li').map((li) => li.text().replace(/\s+/g, ' '))
const buttonByText = (wrapper, text) => wrapper.findAll('button').find((button) => button.text() === text)
const bannerOf = (wrapper) => wrapper.find('.user-profile__banner')

describe('ProfileInfo', () => {
	/**
	 * What `verify_credentials` answers.
	 *
	 * The editor reads the bio from there and not from the account on the
	 * page, because `/global/account/info` -- which is what draws this page,
	 * and is answered to anybody -- carries no `source` at all. A fixture that
	 * put `source` on the page's account is how the empty-bio bug survived its
	 * own tests, so none of them does that any more.
	 */
	let credentials

	beforeEach(() => {
		makeStore()
		credentials = { source: { note: '', fields: [] } }
		vi.spyOn(axios, 'get').mockImplementation((url) => (
			String(url).endsWith('/accounts/verify_credentials')
				? Promise.resolve({ data: credentials })
				: Promise.resolve({ data: {} })
		))
	})

	/** Clicks Edit profile and lets the read behind it settle. */
	const openProfileEditor = async (wrapper) => {
		await buttonByText(wrapper, 'Edit profile').trigger('click')
		await flushPromises()

		return wrapper.find('.modal-stub')
	}

	afterEach(() => {
		vi.restoreAllMocks()
		vi.mocked(showError).mockClear()
		vi.mocked(showSuccess).mockClear()
	})

	it('renders nothing for an account that is not in the store', () => {
		expect(mountProfile('nobody@remote.example').find('.user-profile').exists()).toBe(false)
	})

	/** The redirect every other server shows for a moved account. */
	it('says where a moved account went, and links to it', () => {
		accountStore.addAccount({
			actorId: 'https://remote.example/users/gone',
			data: { ...bob, acct: 'gone@remote.example', url: 'https://remote.example/users/gone', moved: { acct: 'bob@new.example', url: 'https://new.example/@bob' } },
		})

		const wrapper = mountProfile('gone@remote.example')

		expect(wrapper.find('.user-profile__moved').text()).toContain('This account has moved to @bob@new.example.')
		expect(wrapper.find('.user-profile__moved a, .user-profile__moved [to]').exists() || wrapper.findComponent(RouterLinkStub).exists()).toBe(true)
	})

	it('shows no redirect for an account that stayed', () => {
		expect(mountProfile('bob@remote.example').find('.user-profile__moved').exists()).toBe(false)
	})

	it('shows the name, the delivered avatar and the counters of a remote account', () => {
		const wrapper = mountProfile('bob@remote.example')
		expect(wrapper.find('h2').text()).toBe('Bob')
		const avatar = wrapper.findComponent(NcAvatarStub)
		expect(avatar.props('url')).toBe('https://remote.example/media/bob.png')
		expect(avatar.props('user')).toBeUndefined()
		expect(avatar.props('size')).toBe(128)
		expect(linkTexts(wrapper)).toEqual(['12 posts', '3 following', '5 followers'])
	})

	it('counts one post as a post, and one follower as a follower', () => {
		// "1 posts" is not English, and three labels glued to a bare number
		// cannot be pluralised in any language at all
		const wrapper = mountProfile('carol')

		expect(linkTexts(wrapper)).toEqual(['0 posts', '0 following', '1 follower'])
	})

	it('writes the counts in the reader\'s own digits and grouping', () => {
		const account = { ...bob, acct: 'dora@remote.example', url: 'https://remote.example/users/dora', id: 'https://remote.example/users/dora', statuses_count: 1234 }
		accountStore.addAccount({ actorId: account.url, data: account })
		const wrapper = mountProfile('dora@remote.example')

		expect(linkTexts(wrapper)[0]).toBe((1234).toLocaleString(getCanonicalLocale()) + ' posts')
	})

	it('links the counters to the profile sub pages of the shown account', () => {
		const links = mountProfile('bob@remote.example').findAllComponents(RouterLinkStub).map((link) => link.props('to'))
		expect(links).toEqual([
			{ name: 'profile', params: { account: 'bob@remote.example' } },
			{ name: 'profile.following', params: { account: 'bob@remote.example' } },
			{ name: 'profile.followers', params: { account: 'bob@remote.example' } },
		])
	})

	it('uses the server avatar of the uid for a local account and falls back to the username', () => {
		const wrapper = mountProfile('carol')
		const avatar = wrapper.findComponent(NcAvatarStub)
		expect(avatar.props('user')).toBe('carol')
		expect(avatar.props('url')).toBeUndefined()
		expect(wrapper.find('h2').text()).toBe('carol')
	})

	it('hands the uid to the follow button and does not offer the public follow flow', () => {
		const wrapper = mountProfile('bob@remote.example')
		expect(wrapper.findComponent(FollowButtonStub).props('uid')).toBe('bob@remote.example')
		expect(buttonByText(wrapper, 'Follow')).toBeUndefined()
	})

	/**
	 * The banner controls used to float over the picture on everybody's own
	 * profile. They live in the Edit profile dialog now, so the page carries
	 * nothing but the profile itself.
	 */
	it('keeps the banner controls out of the page', () => {
		const own = mountProfile('alice')

		expect(buttonByText(own, 'Upload an image')).toBeUndefined()
		expect(buttonByText(own, 'Apply')).toBeUndefined()
		expect(own.find('input[type="url"]').exists()).toBe(false)
	})

	it('only lets the viewer edit the banner of their own profile', async () => {
		const own = mountProfile('alice')
		expect(bannerOf(own).classes()).toContain('user-profile__banner--editable')
		await openProfileEditor(own)
		expect(buttonByText(own.find('.modal-stub'), 'Upload an image')).toBeDefined()
		expect(own.find('.modal-stub input[type="url"]').exists()).toBe(true)

		const other = mountProfile('bob@remote.example')
		expect(buttonByText(other, 'Edit profile')).toBeUndefined()
		expect(bannerOf(other).classes()).not.toContain('user-profile__banner--editable')
	})

	it('marks the banner as visible only when the account has a header image', () => {
		expect(bannerOf(mountProfile('bob@remote.example')).classes()).toContain('user-profile__banner--visible')
		expect(bannerOf(mountProfile('carol')).classes()).not.toContain('user-profile__banner--visible')
	})

	it('paints an existing header image onto the banner on first render', async () => {
		const wrapper = mountProfile('bob@remote.example')
		await nextTick()
		expect(bannerOf(wrapper).element.style.backgroundImage).toContain('https://remote.example/media/bob-header.png')
		expect(bannerOf(wrapper).element.style.backgroundSize).toBe('cover')
	})

	it('paints the header image onto the banner when it changes and clears it when removed', async () => {
		const wrapper = mountProfile('carol')
		accountStore.addAccount({ actorId: carol.url, data: { header: 'https://cloud.example.org/carol-header.png' } })
		await nextTick()
		expect(bannerOf(wrapper).element.style.backgroundImage).toContain('https://cloud.example.org/carol-header.png')
		expect(bannerOf(wrapper).element.style.backgroundSize).toBe('cover')
		expect(bannerOf(wrapper).classes()).toContain('user-profile__banner--visible')

		accountStore.addAccount({ actorId: carol.url, data: { header: '' } })
		await nextTick()
		expect(bannerOf(wrapper).element.style.backgroundImage).toBe('')
		expect(bannerOf(wrapper).element.style.backgroundColor).toBe('var(--color-background-dark)')
		expect(bannerOf(wrapper).classes()).not.toContain('user-profile__banner--visible')
	})

	describe('the private note', () => {
		const relationship = (extra = {}) => ({
			id: '42',
			following: false,
			blocking: false,
			muting: false,
			note: '',
			...extra,
		})
		const noteBox = (wrapper) => wrapper.find('.user-profile__private-note-input')

		it('is offered on somebody else’s profile', () => {
			accountStore.addRelationship({ actorId: bob.id, data: relationship() })

			expect(noteBox(mountProfile('bob@remote.example')).exists()).toBe(true)
		})

		it('is not offered on the reader’s own profile', () => {
			accountStore.addRelationship({ actorId: alice.id, data: relationship() })

			expect(noteBox(mountProfile('alice')).exists()).toBe(false)
		})

		it('shows the note the relationship carries', () => {
			accountStore.addRelationship({ actorId: bob.id, data: relationship({ note: 'met at the conference' }) })

			expect(noteBox(mountProfile('bob@remote.example')).element.value).toBe('met at the conference')
		})

		it('saves it as the comment the route takes', async () => {
			accountStore.addRelationship({ actorId: bob.id, data: relationship() })
			const wrapper = mountProfile('bob@remote.example')
			vi.spyOn(axios, 'post').mockResolvedValue({ data: relationship({ note: 'met at the conference' }) })

			await noteBox(wrapper).setValue('met at the conference')
			await wrapper.find('.user-profile__private-note').trigger('submit')
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(
				'/index.php/apps/social/api/v1/accounts/42/note',
				{ comment: 'met at the conference' },
			)
			expect(showSuccess).toHaveBeenCalledWith('Your note has been saved')
		})

		it('says so when an empty box cleared it', async () => {
			accountStore.addRelationship({ actorId: bob.id, data: relationship({ note: 'met at the conference' }) })
			const wrapper = mountProfile('bob@remote.example')
			vi.spyOn(axios, 'post').mockResolvedValue({ data: relationship() })

			await noteBox(wrapper).setValue('')
			await wrapper.find('.user-profile__private-note').trigger('submit')
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(
				'/index.php/apps/social/api/v1/accounts/42/note',
				{ comment: '' },
			)
			expect(showSuccess).toHaveBeenCalledWith('Your note has been cleared')
		})

		it('offers no way out until the note is touched', () => {
			accountStore.addRelationship({ actorId: bob.id, data: relationship({ note: 'met at the conference' }) })
			const wrapper = mountProfile('bob@remote.example')

			expect(buttonByText(wrapper, 'Discard')).toBeUndefined()
		})

		it('puts the box back to the stored note when the edit is discarded', async () => {
			accountStore.addRelationship({ actorId: bob.id, data: relationship({ note: 'met at the conference' }) })
			const wrapper = mountProfile('bob@remote.example')
			vi.spyOn(axios, 'post')

			await noteBox(wrapper).setValue('met at the pub')
			await buttonByText(wrapper, 'Discard').trigger('click')
			await nextTick()

			expect(noteBox(wrapper).element.value).toBe('met at the conference')
			expect(axios.post).not.toHaveBeenCalled()
		})

		it('empties a note the reader never had, when the edit is discarded', async () => {
			accountStore.addRelationship({ actorId: bob.id, data: relationship() })
			const wrapper = mountProfile('bob@remote.example')

			await noteBox(wrapper).setValue('a word typed by mistake')
			await buttonByText(wrapper, 'Discard').trigger('click')
			await nextTick()

			expect(noteBox(wrapper).element.value).toBe('')
			expect(buttonByText(wrapper, 'Save note').attributes('disabled')).toBeDefined()
		})

		it('discards the edit on escape, the way a dialog is dismissed', async () => {
			accountStore.addRelationship({ actorId: bob.id, data: relationship({ note: 'met at the conference' }) })
			const wrapper = mountProfile('bob@remote.example')

			await noteBox(wrapper).setValue('met at the pub')
			await noteBox(wrapper).trigger('keydown.esc')
			await nextTick()

			expect(noteBox(wrapper).element.value).toBe('met at the conference')
		})

		it('has nothing to save until the note changes', () => {
			accountStore.addRelationship({ actorId: bob.id, data: relationship({ note: 'met at the conference' }) })
			const wrapper = mountProfile('bob@remote.example')

			expect(buttonByText(wrapper, 'Save note').attributes('disabled')).toBeDefined()
		})

		it('says so when the note could not be saved', async () => {
			accountStore.addRelationship({ actorId: bob.id, data: relationship() })
			const wrapper = mountProfile('bob@remote.example')
			vi.spyOn(axios, 'post').mockRejectedValue(new Error('nope'))

			await noteBox(wrapper).setValue('met at the conference')
			await wrapper.find('.user-profile__private-note').trigger('submit')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not save your note')
		})
	})

	describe('block and mute menu', () => {
		const relationship = (extra = {}) => ({
			id: '42',
			following: false,
			followed_by: false,
			blocking: false,
			blocked_by: false,
			muting: false,
			muting_notifications: false,
			requested: false,
			...extra,
		})
		const menuItems = (wrapper) => wrapper.findAll('.profile-menu__item').map((button) => button.text())
		const menuItem = (wrapper, label) => wrapper.findAll('.profile-menu__item').find((button) => button.text() === label)

		it('shows no menu while the relationship has not been loaded', () => {
			expect(menuItems(mountProfile('bob@remote.example'))).toEqual([])
		})

		it('offers Block and Mute for an account that is neither blocked nor muted', () => {
			accountStore.addRelationship({ actorId: bob.id, data: relationship() })
			const wrapper = mountProfile('bob@remote.example')
			expect(menuItems(wrapper)).toEqual(['Block', 'Mute', 'Add to list'])
			expect(wrapper.findComponent(FollowButtonStub).exists()).toBe(true)
			expect(wrapper.find('.user-profile__blocked-hint').exists()).toBe(false)
		})

		it('flips to Unblock, shows the Blocked hint and hides the follow button for a blocked account', () => {
			accountStore.addRelationship({ actorId: bob.id, data: relationship({ blocking: true }) })
			const wrapper = mountProfile('bob@remote.example')
			expect(menuItems(wrapper)).toEqual(['Unblock', 'Mute', 'Add to list'])
			expect(wrapper.find('.user-profile__blocked-hint').text()).toBe('Blocked')
			expect(wrapper.findComponent(FollowButtonStub).exists()).toBe(false)
		})

		it('flips to Unmute for a muted account', () => {
			accountStore.addRelationship({ actorId: bob.id, data: relationship({ muting: true, muting_notifications: true }) })
			expect(menuItems(mountProfile('bob@remote.example'))).toEqual(['Block', 'Unmute', 'Add to list'])
		})

		// Mute is missing on purpose: it opens a dialog with questions of its
		// own, and the store is only called once that has been answered
		it.each([
			['Block', relationship(), 'blockAccount'],
			['Unblock', relationship({ blocking: true }), 'unblockAccount'],
			['Unmute', relationship({ muting: true }), 'unmuteAccount'],
		])('clicking %s calls %s with the relationship id', async (label, data, action) => {
			accountStore.addRelationship({ actorId: bob.id, data })
			spyOnAccountActions(data)
			const wrapper = mountProfile('bob@remote.example')

			await menuItem(wrapper, label).trigger('click')
			await flushPromises()

			expect(accountStore[action]).toHaveBeenCalledWith({ id: '42' })
		})

		it('says when a timed mute lifts, and only when there is a date', () => {
			accountStore.addRelationship({
				actorId: bob.id,
				data: relationship({ muting: true, mute_expires_at: '2026-01-01T00:00:00.000Z' }),
			})

			expect(mountProfile('bob@remote.example').find('.user-profile__blocked-hint').text())
				.toContain('Muted until')

			makeStore()
			accountStore.addRelationship({ actorId: bob.id, data: relationship({ muting: true }) })

			expect(mountProfile('bob@remote.example').find('.user-profile__blocked-hint').text())
				.toBe('Muted')
		})

		it('shows no menu on the own profile', () => {
			accountStore.addRelationship({ actorId: alice.id, data: relationship() })
			expect(menuItems(mountProfile('alice'))).toEqual([])
		})

		it('shows no menu on the public page', () => {
			makeStore({ public: true })
			accountStore.addRelationship({ actorId: bob.id, data: relationship() })
			expect(menuItems(mountProfile('bob@remote.example'))).toEqual([])
		})
	})

	describe('on the public page', () => {
		beforeEach(() => {
			makeStore({ public: true })
		})

		it('offers a follow button that opens the remote follow dialog for the local part of the uid', async () => {
			const open = vi.spyOn(window, 'open').mockImplementation(() => null)
			const wrapper = mountProfile('bob@remote.example')
			await buttonByText(wrapper, 'Follow').trigger('click')
			expect(open).toHaveBeenCalledTimes(1)
			expect(open.mock.calls[0][0]).toBe('/index.php/apps/social/api/v1/ostatus/followRemote/bob')
			expect(open.mock.calls[0][1]).toBe('followRemote')
		})

		// the route needs a login; asking anyway was a 401 on every profile
		it('does not ask who the visitor follows that follows this account', async () => {
			mountProfile('bob@remote.example')
			await flushPromises()

			const asked = vi.mocked(axios.get).mock.calls.map(([url]) => String(url))
			expect(asked.some((url) => url.endsWith('/accounts/familiar_followers'))).toBe(false)
		})
	})

	it('asks a reader who is logged in who they follow that follows this account', async () => {
		mountProfile('bob@remote.example')
		await flushPromises()

		const asked = vi.mocked(axios.get).mock.calls.map(([url]) => String(url))
		expect(asked.some((url) => url.endsWith('/accounts/familiar_followers'))).toBe(true)
	})

	describe('profile metadata fields', () => {
		const fieldRows = (wrapper) => wrapper.findAll('.user-profile__field').map((row) => ({
			name: row.find('dt').text(),
			text: row.find('dd').text(),
			href: row.find('a').exists() ? row.find('a').attributes('href') : undefined,
		}))

		it('shows no field table for an account without fields', () => {
			expect(mountProfile('bob@remote.example').find('.user-profile__fields').exists()).toBe(false)
		})

		it('renders the text of a remote field and links it when it points at a URL', () => {
			accountStore.addAccount({
				actorId: bob.url,
				data: {
					fields: [
						{ name: 'Website', value: '<a href="https://example.org/bob" rel="me">example.org/bob</a>', verified_at: null },
						{ name: 'Pronouns', value: 'they/them', verified_at: null },
					],
				},
			})

			expect(fieldRows(mountProfile('bob@remote.example'))).toEqual([
				{ name: 'Website', text: 'example.org/bob', href: 'https://example.org/bob' },
				{ name: 'Pronouns', text: 'they/them', href: undefined },
			])
		})

		it('never turns a javascript: value from a remote server into a link', () => {
			accountStore.addAccount({
				actorId: bob.url,
				data: { fields: [{ name: 'Evil', value: '<a href="javascript:alert(1)">click me</a>', verified_at: null }] },
			})

			expect(fieldRows(mountProfile('bob@remote.example'))).toEqual([
				{ name: 'Evil', text: 'click me', href: undefined },
			])
		})

		it('only offers the profile editor on the own profile', () => {
			expect(buttonByText(mountProfile('alice'), 'Edit profile')).toBeDefined()
			expect(buttonByText(mountProfile('bob@remote.example'), 'Edit profile')).toBeUndefined()
		})

		it('prefills the editor with the own raw field values and saves the trimmed set', async () => {
			accountStore.addAccount({
				actorId: alice.url,
				data: {
					fields: [{ name: 'Website', value: '<a href="https://example.org">example.org</a>', verified_at: null }],
				},
			})
			// the raw values, which only the account's own copy carries
			credentials = { source: { note: '', fields: [{ name: 'Website', value: 'https://example.org' }] } }
			const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { result: { account: alice } } })
			spyOnAccountActions(alice)
			const wrapper = mountProfile('alice')

			await openProfileEditor(wrapper)
			const modal = wrapper.find('.modal-stub')
			// scoped to the field rows: the dialog also holds the banner controls
			const inputs = modal.findAll('.user-profile__fields-row input')
			expect(inputs).toHaveLength(2)
			expect(inputs[0].element.value).toBe('Website')
			expect(inputs[1].element.value).toBe('https://example.org')

			await buttonByText(modal, 'Add field').trigger('click')
			const rows = wrapper.find('.modal-stub').findAll('.user-profile__fields-row')
			await rows[1].findAll('input')[0].setValue('  Pronouns  ')
			await rows[1].findAll('input')[1].setValue('  they/them  ')
			await buttonByText(wrapper.find('.modal-stub'), 'Save').trigger('click')
			await flushPromises()

			expect(put).toHaveBeenCalledTimes(1)
			expect(put.mock.calls[0][0]).toBe('/index.php/apps/social/api/v1/account/fields')
			expect(put.mock.calls[0][1]).toEqual({
				fields: [
					{ name: 'Website', value: 'https://example.org' },
					{ name: 'Pronouns', value: 'they/them' },
				],
			})
			expect(wrapper.find('.modal-stub').exists()).toBe(false)
			expect(showSuccess).toHaveBeenCalledWith('Profile saved')
			expect(accountStore.fetchAccountInfo).toHaveBeenCalledWith('alice@cloud.example.org')
		})

		it('drops half-filled rows and can clear every field', async () => {
			const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { result: { account: alice } } })
			spyOnAccountActions(alice)
			const wrapper = mountProfile('alice')

			await openProfileEditor(wrapper)
			const modal = wrapper.find('.modal-stub')
			await modal.findAll('input')[0].setValue('a label without a value')
			await buttonByText(modal, 'Save').trigger('click')
			await flushPromises()

			expect(put.mock.calls[0][1]).toEqual({ fields: [] })
		})

		it('offers at most four rows', async () => {
			credentials = {
				source: {
					note: '',
					fields: [
						{ name: 'One', value: '1' },
						{ name: 'Two', value: '2' },
						{ name: 'Three', value: '3' },
						{ name: 'Four', value: '4' },
					],
				},
			}
			const wrapper = mountProfile('alice')

			await openProfileEditor(wrapper)
			const modal = wrapper.find('.modal-stub')
			expect(modal.findAll('.user-profile__fields-row')).toHaveLength(4)
			expect(buttonByText(modal, 'Add field')).toBeUndefined()
		})

		it('keeps the editor open and reports a failed save', async () => {
			vi.spyOn(axios, 'put').mockRejectedValue(new Error('500'))
			const wrapper = mountProfile('alice')

			await openProfileEditor(wrapper)
			await buttonByText(wrapper.find('.modal-stub'), 'Save').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Failed to save profile')
			expect(wrapper.find('.modal-stub').exists()).toBe(true)
			expect(buttonByText(wrapper.find('.modal-stub'), 'Save').attributes('disabled')).toBeUndefined()
		})

		/**
		 * The verdicts are the reason the fields are worth filling in, and the
		 * editor is where somebody is standing when they wonder why their
		 * website has no tick.
		 */
		describe('verified links', () => {
			const statuses = (modal) => modal.findAll('.user-profile__fields-status').map((row) => row.text())

			/** `source.fields` is the editable copy; `fields` carries the verdicts. */
			const withFields = (fields) => {
				credentials = {
					source: { note: '', fields: fields.map(({ name, value }) => ({ name, value })) },
					fields,
				}
			}

			it('says when each verified row was proved', async () => {
				withFields([
					{ name: 'Website', value: 'https://alice.example', verified_at: '2026-09-01T10:00:00+00:00' },
					{ name: 'Blog', value: 'https://blog.alice.example', verified_at: null },
				])
				const wrapper = mountProfile('alice')

				const modal = await openProfileEditor(wrapper)
				expect(modal.findAllComponents({ name: 'VerifiedCheck' })).toHaveLength(1)
				expect(statuses(modal)[0]).toContain('Verified on')
				expect(statuses(modal)[1]).toContain('Not verified')
			})

			/**
			 * A verdict belongs to the value it was made about — that is how the
			 * server keys it — so the tick has to go the moment the value does,
			 * rather than sitting under a page nobody has checked.
			 */
			it('drops the claim as soon as the verified value is edited', async () => {
				withFields([
					{ name: 'Website', value: 'https://alice.example', verified_at: '2026-09-01T10:00:00+00:00' },
				])
				const wrapper = mountProfile('alice')

				const modal = await openProfileEditor(wrapper)
				await modal.findAll('.user-profile__fields-row input')[1].setValue('https://elsewhere.example')

				expect(modal.findComponent({ name: 'VerifiedCheck' }).exists()).toBe(false)
				expect(statuses(modal)[0]).toContain('Not verified')
			})

			it('says nothing about a row that names no page', async () => {
				withFields([{ name: 'Pronouns', value: 'they/them', verified_at: null }])
				const wrapper = mountProfile('alice')

				const modal = await openProfileEditor(wrapper)
				expect(statuses(modal)).toEqual([''])
				expect(modal.find('.user-profile__fields-verify').exists()).toBe(false)
			})

			/** The usual way to end up with a row that can never be verified. */
			it('tells a bare domain that it cannot be verified', async () => {
				withFields([{ name: 'Website', value: 'alice.example', verified_at: null }])
				const wrapper = mountProfile('alice')

				const modal = await openProfileEditor(wrapper)
				expect(statuses(modal)[0]).toContain('starting with http')
				expect(modal.find('.user-profile__fields-verify').exists()).toBe(false)
			})

			it('shows the line to paste, and what the server does with it, once a row is an address', async () => {
				withFields([{ name: 'Website', value: 'https://alice.example', verified_at: null }])
				const wrapper = mountProfile('alice')

				const modal = await openProfileEditor(wrapper)
				const card = modal.find('.user-profile__fields-verify')
				expect(card.exists()).toBe(true)
				expect(card.find('code').text())
					.toBe('<a rel="me" href="https://cloud.example.org/users/alice">Alice</a>')
				expect(card.text()).toContain('rel words')
				expect(card.text()).toContain('once a day per account')
			})

			it('copies the line to paste', async () => {
				const writeText = vi.fn().mockResolvedValue(undefined)
				Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
				withFields([{ name: 'Website', value: 'https://alice.example', verified_at: null }])
				const wrapper = mountProfile('alice')

				const modal = await openProfileEditor(wrapper)
				await modal.find('.user-profile__fields-snippet button').trigger('click')
				await flushPromises()

				expect(writeText).toHaveBeenCalledWith('<a rel="me" href="https://cloud.example.org/users/alice">Alice</a>')
			})

			/** The same fallback the rows themselves have when `source` is unreadable. */
			it('falls back to the verdicts on the page’s own account', async () => {
				accountStore.addAccount({
					actorId: alice.url,
					data: {
						fields: [{ name: 'Website', value: 'https://alice.example', verified_at: '2026-09-01T10:00:00+00:00' }],
					},
				})
				vi.spyOn(axios, 'get').mockRejectedValue(new Error('500'))
				const wrapper = mountProfile('alice')

				const modal = await openProfileEditor(wrapper)
				expect(modal.findComponent({ name: 'VerifiedCheck' }).exists()).toBe(true)
			})
		})
	})

	describe('bio', () => {
		const bioBox = (wrapper) => wrapper.find('#social-profile-bio')
		const bioCount = (wrapper) => wrapper.find('#social-profile-bio-count')
		const openEditor = (wrapper) => openProfileEditor(wrapper)

		it('renders the bio of the shown account and strips what is not safe to inject', () => {
			accountStore.addAccount({
				actorId: bob.url,
				data: { note: '<p>Hi <a href="https://example.org">there</a></p><script>alert(1)</script>' },
			})

			const note = mountProfile('bob@remote.example').find('.user-profile__note')
			expect(note.text()).toContain('Hi there')
			expect(note.html()).not.toContain('alert(1)')
			expect(note.find('a').attributes('href')).toBe('https://example.org')
		})

		it('shows a bio block only for an account that has one', () => {
			expect(mountProfile('bob@remote.example').find('.user-profile__note').exists()).toBe(false)

			accountStore.addAccount({ actorId: bob.url, data: { note: '<p>Hello</p>' } })

			expect(mountProfile('bob@remote.example').find('.user-profile__note').exists()).toBe(true)
		})

		it('fills the edit box with the stored plain text, never with the rendered HTML', async () => {
			// no `source` here: this is what `/global/account/info` answers
			accountStore.addAccount({
				actorId: alice.url,
				data: { note: '<p>Rendered <b>HTML</b></p>' },
			})
			credentials = { source: { note: 'Plain <text> bio' } }
			const wrapper = mountProfile('alice')

			await openEditor(wrapper)

			expect(bioBox(wrapper).element.value).toBe('Plain <text> bio')
		})

		/**
		 * The bug this replaced: `source` was taken off the account the page
		 * was drawn from, and that route carries none -- it is answered to
		 * anybody, and `source` holds things only the account may read. The box
		 * opened empty over a bio that was still there.
		 */
		it('asks the account itself rather than reading the page it is drawn on', async () => {
			accountStore.addAccount({ actorId: alice.url, data: { note: '<p>Stored</p>' } })
			credentials = { source: { note: 'Stored' } }
			const wrapper = mountProfile('alice')

			await openEditor(wrapper)

			expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/verify_credentials')
			expect(bioBox(wrapper).element.value).toBe('Stored')
		})

		/**
		 * A bio nobody could read is never one this editor sends back: both
		 * halves stay empty and equal, which is exactly what `bioChanged`
		 * asks, so a failed read cannot erase what is stored.
		 */
		it('cannot erase the bio when it could not be read', async () => {
			accountStore.addAccount({ actorId: alice.url, data: { note: '<p>Stored</p>' } })
			vi.spyOn(axios, 'get').mockRejectedValue(new Error('500'))
			const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
			const patch = vi.spyOn(axios, 'patch').mockResolvedValue({ data: {} })
			spyOnAccountActions(alice)
			const wrapper = mountProfile('alice')

			const modal = await openEditor(wrapper)
			await buttonByText(modal, 'Save').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not load your profile for editing')
			expect(put).toHaveBeenCalledTimes(1)
			expect(patch).not.toHaveBeenCalled()
		})

		it('takes the profile fields from the account\'s own copy', async () => {
			accountStore.addAccount({
				actorId: alice.url,
				data: { note: '', fields: [{ name: 'Stale', value: 'from the page' }] },
			})
			credentials = { source: { note: '', fields: [{ name: 'Pronouns', value: 'they/them' }] } }
			const wrapper = mountProfile('alice')

			const modal = await openEditor(wrapper)

			// scoped to the field rows: the dialog also holds the banner controls
			const inputs = modal.findAll('.user-profile__fields-row input')
			expect(inputs[0].element.value).toBe('Pronouns')
			expect(inputs[1].element.value).toBe('they/them')
		})

		it('sends the bio as the plain text it is stored as and refreshes the account', async () => {
			accountStore.addAccount({ actorId: alice.url, data: { note: '<p>Old</p>' } })
			credentials = { source: { note: 'Old' } }
			const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
			const patch = vi.spyOn(axios, 'patch').mockResolvedValue({ data: {} })
			spyOnAccountActions(alice)
			const wrapper = mountProfile('alice')

			const modal = await openEditor(wrapper)
			await bioBox(wrapper).setValue('  A new bio\r\nover two lines  ')
			await buttonByText(modal, 'Save').trigger('click')
			await flushPromises()

			expect(put).toHaveBeenCalledTimes(1)
			expect(patch).toHaveBeenCalledTimes(1)
			expect(patch.mock.calls[0][0]).toBe('/index.php/apps/social/api/v1/accounts/update_credentials')
			expect(patch.mock.calls[0][1]).toEqual({ note: 'A new bio\nover two lines' })
			expect(wrapper.find('.modal-stub').exists()).toBe(false)
			expect(showSuccess).toHaveBeenCalledWith('Profile saved')
			expect(accountStore.fetchAccountInfo).toHaveBeenCalledWith('alice@cloud.example.org')
		})

		it('leaves the stored bio alone when only the other fields were edited', async () => {
			accountStore.addAccount({ actorId: alice.url, data: { note: '<p>Old</p>' } })
			credentials = { source: { note: 'Old' } }
			const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
			const patch = vi.spyOn(axios, 'patch').mockResolvedValue({ data: {} })
			spyOnAccountActions(alice)
			const wrapper = mountProfile('alice')

			const modal = await openEditor(wrapper)
			await modal.findAll('input')[0].setValue('Pronouns')
			await modal.findAll('input')[1].setValue('they/them')
			await buttonByText(modal, 'Save').trigger('click')
			await flushPromises()

			expect(put).toHaveBeenCalledTimes(1)
			expect(patch).not.toHaveBeenCalled()
		})

		it('counts what is stored, by code point, and refuses to send a bio over the limit', async () => {
			const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
			const patch = vi.spyOn(axios, 'patch').mockResolvedValue({ data: {} })
			const wrapper = mountProfile('alice')
			const modal = await openEditor(wrapper)

			// one emoji is one character to the server, two UTF-16 units to JS
			await bioBox(wrapper).setValue('x'.repeat(499) + '\u{1F600}')
			expect(bioCount(wrapper).text()).toBe('0 characters left')
			expect(bioBox(wrapper).attributes('aria-invalid')).toBe('false')
			expect(buttonByText(modal, 'Save').attributes('disabled')).toBeUndefined()

			await bioBox(wrapper).setValue('x'.repeat(500) + '\u{1F600}')
			expect(bioCount(wrapper).text()).toBe('1 character too many')
			expect(bioBox(wrapper).attributes('aria-invalid')).toBe('true')
			expect(buttonByText(modal, 'Save').attributes('disabled')).toBeDefined()

			await wrapper.vm.saveProfile()

			expect(put).not.toHaveBeenCalled()
			expect(patch).not.toHaveBeenCalled()
			expect(wrapper.find('.modal-stub').exists()).toBe(true)
		})

		it('never has two saves in flight at once', async () => {
			let release
			const put = vi.spyOn(axios, 'put').mockImplementation(() => new Promise((resolve) => {
				release = resolve
			}))
			const patch = vi.spyOn(axios, 'patch').mockResolvedValue({ data: {} })
			spyOnAccountActions(alice)
			const wrapper = mountProfile('alice')

			const modal = await openEditor(wrapper)
			await bioBox(wrapper).setValue('A new bio')
			const save = buttonByText(modal, 'Save')
			save.trigger('click')
			save.trigger('click')
			await flushPromises()

			expect(put).toHaveBeenCalledTimes(1)

			release({ data: {} })
			await flushPromises()

			expect(patch).toHaveBeenCalledTimes(1)
		})

		it('keeps the editor and the typed bio when the save fails', async () => {
			vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
			vi.spyOn(axios, 'patch').mockRejectedValue(new Error('500'))
			const dispatch = spyOnAccountActions(alice)
			const wrapper = mountProfile('alice')

			const modal = await openEditor(wrapper)
			await bioBox(wrapper).setValue('A new bio')
			await buttonByText(modal, 'Save').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Failed to save profile')
			expect(showSuccess).not.toHaveBeenCalled()
			expect(wrapper.find('.modal-stub').exists()).toBe(true)
			expect(bioBox(wrapper).element.value).toBe('A new bio')
			expect(buttonByText(wrapper.find('.modal-stub'), 'Save').attributes('disabled')).toBeUndefined()
			expect(dispatch).not.toHaveBeenCalled()
		})
	})

	describe('banner upload on the own profile', () => {
		let post
		let dispatch

		beforeEach(() => {
			post = vi.spyOn(axios, 'post')
			dispatch = spyOnAccountActions(alice)
		})

		it('uploads the chosen file, applies the returned banner and refreshes the account', async () => {
			post.mockResolvedValue({ data: { result: { url: 'https://cloud.example.org/banners/alice.png' } } })
			const wrapper = mountProfile('alice')
			await openProfileEditor(wrapper)
			const file = new File(['png'], 'banner.png', { type: 'image/png' })
			const input = wrapper.find('input[type="file"]')
			Object.defineProperty(input.element, 'files', { value: [file], configurable: true })

			await input.trigger('change')
			await flushPromises()

			expect(post).toHaveBeenCalledTimes(1)
			const [url, body] = post.mock.calls[0]
			expect(url).toBe('/index.php/apps/social/api/v1/banner')
			expect(body).toBeInstanceOf(FormData)
			expect(body.get('file')).toBe(file)
			expect(bannerOf(wrapper).element.style.backgroundImage).toContain('https://cloud.example.org/banners/alice.png')
			expect(showSuccess).toHaveBeenCalledWith('Banner uploaded successfully')
			expect(accountStore.fetchAccountInfo).toHaveBeenCalledWith('alice@cloud.example.org')
			expect(buttonByText(wrapper.find('.modal-stub'), 'Upload an image').attributes('disabled')).toBeUndefined()
		})

		it('ignores a change event without a file', async () => {
			const wrapper = mountProfile('alice')
			await wrapper.find('input[type="file"]').trigger('change')
			await flushPromises()
			expect(post).not.toHaveBeenCalled()
		})

		it('reports a failed upload and unlocks the buttons again', async () => {
			post.mockRejectedValue(new Error('500'))
			const wrapper = mountProfile('alice')
			await openProfileEditor(wrapper)
			const input = wrapper.find('input[type="file"]')
			Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'b.png', { type: 'image/png' })], configurable: true })

			await input.trigger('change')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Failed to upload banner')
			expect(dispatch).not.toHaveBeenCalled()
			expect(buttonByText(wrapper.find('.modal-stub'), 'Upload an image').attributes('disabled')).toBeUndefined()
		})

		it('sets the banner from a URL inside the Edit profile dialog', async () => {
			post.mockResolvedValue({ data: { result: { url: 'https://cloud.example.org/banners/from-url.png' } } })
			const wrapper = mountProfile('alice')
			expect(wrapper.find('.modal-stub').exists()).toBe(false)

			await openProfileEditor(wrapper)
			const modal = wrapper.find('.modal-stub')
			expect(modal.find('h3').text()).toBe('Edit profile')
			const apply = buttonByText(modal, 'Apply')
			expect(apply.attributes('disabled')).toBeDefined()

			await modal.find('input[type="url"]').setValue(' https://example.com/image.jpg ')
			expect(buttonByText(wrapper.find('.modal-stub'), 'Apply').attributes('disabled')).toBeUndefined()
			await buttonByText(wrapper.find('.modal-stub'), 'Apply').trigger('click')
			await flushPromises()

			const [url, body, config] = post.mock.calls[0]
			expect(url).toBe('/index.php/apps/social/api/v1/banner/url')
			expect(body).toBeInstanceOf(URLSearchParams)
			expect(body.get('url')).toBe('https://example.com/image.jpg')
			expect(config.headers['Content-Type']).toBe('application/x-www-form-urlencoded')
			// the dialog stays: the bio and the fields may still be being edited
			expect(wrapper.find('.modal-stub').exists()).toBe(true)
			expect(wrapper.find('.modal-stub input[type="url"]').element.value).toBe('')
			expect(bannerOf(wrapper).element.style.backgroundImage).toContain('https://cloud.example.org/banners/from-url.png')
			expect(showSuccess).toHaveBeenCalledWith('Banner set successfully')
			expect(accountStore.fetchAccountInfo).toHaveBeenCalledWith('alice@cloud.example.org')
		})

		it('keeps the modal open and reports when the URL cannot be fetched', async () => {
			post.mockRejectedValue(new Error('400'))
			const wrapper = mountProfile('alice')
			await openProfileEditor(wrapper)
			await wrapper.find('.modal-stub input[type="url"]').setValue('https://example.com/broken.jpg')
			await buttonByText(wrapper.find('.modal-stub'), 'Apply').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Failed to set banner from URL')
			expect(wrapper.find('.modal-stub').exists()).toBe(true)
			expect(buttonByText(wrapper.find('.modal-stub'), 'Apply').attributes('disabled')).toBeUndefined()
		})
	})

	describe('what you see from an account you follow', () => {
		const following = (extra = {}) => ({
			id: '42',
			following: true,
			blocking: false,
			muting: false,
			notifying: false,
			showing_reblogs: true,
			note: '',
			...extra,
		})

		const menuLabels = (wrapper) => wrapper.findAll('button').map((button) => button.text())

		/** Both ride along with the follow, so neither is offered to a stranger. */
		it('offers neither switch to somebody you do not follow', () => {
			accountStore.addRelationship({ actorId: bob.id, data: following({ following: false }) })

			const labels = menuLabels(mountProfile('bob@remote.example'))

			expect(labels).not.toContain('Notify me when they post')
			expect(labels).not.toContain('Hide their boosts')
		})

		it('offers the bell and the boosts to somebody you follow', () => {
			accountStore.addRelationship({ actorId: bob.id, data: following() })

			const labels = menuLabels(mountProfile('bob@remote.example'))

			expect(labels).toContain('Notify me when they post')
			expect(labels).toContain('Hide their boosts')
		})

		it('says the other thing once each is on', () => {
			accountStore.addRelationship({
				actorId: bob.id,
				data: following({ notifying: true, showing_reblogs: false }),
			})

			const labels = menuLabels(mountProfile('bob@remote.example'))

			expect(labels).toContain('Stop notifying me about their posts')
			expect(labels).toContain('Show their boosts')
		})

		it('rings the bell through the follow, which is the route there is', async () => {
			accountStore.addRelationship({ actorId: bob.id, data: following() })
			const setFollowOptions = vi.fn().mockResolvedValue(following({ notifying: true }))
			vi.spyOn(accountStore, 'setFollowOptions').mockImplementation(setFollowOptions)
			const wrapper = mountProfile('bob@remote.example')

			await buttonByText(wrapper, 'Notify me when they post').trigger('click')
			await flushPromises()

			expect(setFollowOptions).toHaveBeenCalledWith({ id: '42', notify: true })
		})

		/** Only the switch that was pressed is sent; the server leaves the rest. */
		it('turns the boosts off without touching the bell', async () => {
			accountStore.addRelationship({ actorId: bob.id, data: following({ notifying: true }) })
			const setFollowOptions = vi.fn().mockResolvedValue(following({ showing_reblogs: false }))
			vi.spyOn(accountStore, 'setFollowOptions').mockImplementation(setFollowOptions)
			const wrapper = mountProfile('bob@remote.example')

			await buttonByText(wrapper, 'Hide their boosts').trigger('click')
			await flushPromises()

			expect(setFollowOptions).toHaveBeenCalledWith({ id: '42', reblogs: false })
		})
	})
})
