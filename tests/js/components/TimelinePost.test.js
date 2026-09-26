/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import TimelinePost from '../../../src/components/TimelinePost.vue'
import eventBus from '../../../src/services/eventBus.js'
import { createPinia, setActivePinia } from 'pinia'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'
import { useInstanceStore } from '../../../src/store/instance.js'
import { useTimelineStore } from '../../../src/store/timeline.js'
import axios from '@nextcloud/axios'

// the toast service fetches `@nextcloud/dialogs` when it is first used, and
// this file now has tests that reach it (mute and block from the post menu).
// That import resolves after the test has ended, so vitest tears the
// environment down underneath it and the run fails with every assertion green.
vi.mock('../../../src/services/toast.js', async (importOriginal) => ({
	...await importOriginal(),
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

const { feel } = vi.hoisted(() => ({ feel: vi.fn() }))
vi.mock('../../../src/services/senses.js', () => ({ feel }))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

const alice = {
	id: '1',
	acct: 'alice',
	username: 'alice',
	display_name: 'Alice',
	url: 'https://cloud.example.org/@alice',
	avatar: 'https://cloud.example.org/avatar/alice/64',
	note: '',
}

const bob = {
	id: '2',
	acct: 'bob@remote.example',
	username: 'bob',
	display_name: 'Bob',
	url: 'https://remote.example/@bob',
	avatar: 'https://remote.example/avatar.png',
	note: '',
}

function makeItem(overrides = {}) {
	return {
		id: '101',
		uri: 'https://cloud.example.org/@alice/101',
		created_at: '2026-09-01T10:00:00Z',
		content: '<p>Hello <strong>world</strong></p>',
		visibility: 'public',
		replies_count: 0,
		reblogs_count: 0,
		favourites_count: 0,
		reblogged: false,
		favourited: false,
		media_attachments: [],
		mentions: [],
		tags: [],
		account: alice,
		...overrides,
	}
}

// NcActions only renders its entries inside a popover once opened; these
// stand-ins render them inline so the menu content can be asserted.
const NcActionsStub = { name: 'NcActions', template: '<div class="post-menu"><slot /></div>' }
const NcActionButtonStub = {
	name: 'NcActionButton',
	emits: ['click'],
	template: '<button class="post-menu__item" @click="$emit(\'click\')"><slot /></button>',
}
const NcActionLinkStub = {
	name: 'NcActionLink',
	props: ['href', 'target', 'rel'],
	template: '<a class="post-menu__link" :href="href"><slot /></a>',
}

// the real NcDialog reports its own dismissal through update:open, which is
// what v-model:open binds to — the stub has to do the same or a one-way binding
// looks like it works
const NcDialogStub = {
	name: 'NcDialog',
	props: ['open', 'buttons', 'name'],
	emits: ['update:open'],
	template: '<div v-if="open" class="report-dialog nc-dialog">'
		+ '<span class="nc-dialog__name">{{ name }}</span>'
		+ '<button class="report-dialog__close" @click="$emit(\'update:open\', false)" />'
		+ '<button v-for="(button, index) in buttons" :key="index"'
		+ ' :class="\'nc-dialog__button nc-dialog__button--\' + index"'
		+ ' @click="button.callback()">{{ button.label }}</button>'
		+ '<slot /></div>',
}

const POST_ACTIONS = [
	'postLike',
	'postUnlike',
	'postBoost',
	'postUnBoost',
	'postEdit',
	'postDelete',
	'postPin',
	'postBookmark',
]

function mountPost({
	item = makeItem(),
	route = { name: 'timeline', params: { type: 'home' } },
	currentAccount = alice,
	serverData = { public: false, cloudAddress: 'https://cloud.example.org' },
	// the like/boost store actions answer with the updated status, and with
	// nothing at all when they had to roll the change back
	dispatch = vi.fn().mockResolvedValue(makeItem()),
} = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData(serverData)
	const accountStore = useAccountStore()
	if (currentAccount) {
		accountStore.addAccount({ actorId: currentAccount.url, data: currentAccount })
		accountStore.setCurrentAccount(`${currentAccount.acct}@cloud.example.org`)
	}
	const store = useTimelineStore()
	// one mock behind every action the post can take, so "nothing happened"
	// stays a single assertion
	POST_ACTIONS.forEach((action) => vi.spyOn(store, action).mockImplementation(dispatch))
	const $router = { push: vi.fn() }
	const wrapper = mount(TimelinePost, {
		props: { item, type: 'home' },
		global: {
			plugins: [pinia],
			mocks: { $route: route, $router },
			stubs: {
				NcActions: NcActionsStub,
				NcActionButton: NcActionButtonStub,
				NcActionLink: NcActionLinkStub,
				NcDialog: NcDialogStub,
				CollectionPickerDialog: { name: 'CollectionPickerDialog', props: ['open', 'status'], template: '<div class="collection-picker-stub" />' },
				PostAttachment: true,
				RouterLink: RouterLinkStub,
			},
		},
	})
	return { wrapper, item, store, dispatch, $router }
}

// the media-first layout is about order: the picture leads and the text reads
// as its caption underneath
function isBefore(first, second) {
	return Boolean(first.compareDocumentPosition(second) & Node.DOCUMENT_POSITION_FOLLOWING)
}

function photo(index = 1) {
	return {
		id: `m${index}`,
		type: 'image',
		url: `https://cloud.example.org/m${index}.jpg`,
		preview_url: `https://cloud.example.org/m${index}-small.jpg`,
		description: `Picture ${index}`,
		blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
		meta: { original: { width: 1600, height: 1200 } },
	}
}

const attachmentsOf = (wrapper) => wrapper.findComponent({ name: 'PostAttachment' })

const actionButton = (wrapper, label) => wrapper.find(`.post-actions button[aria-label="${label}"]`)
const menuItem = (wrapper, label) => wrapper.findAll('.post-menu__item').find((button) => button.text() === label)

describe('TimelinePost', () => {
	afterEach(() => {
		eventBus.all.clear()
	})

	describe('header and body', () => {
		it('shows the author and links to the profile', () => {
			const { wrapper } = mountPost()

			expect(wrapper.find('.post-author').text()).toBe('Alice')
			expect(wrapper.find('.post-author-wrapper a').attributes('href')).toBe('/index.php/u/alice')
			expect(wrapper.attributes('data-social-status')).toBe('101')
		})

		/**
		 * The handle repeats under the pointer as the wrapper's own `title`,
		 * and again in the hover card. Once in the byline as well was three
		 * times.
		 */
		it('leaves the handle out of a byline that has a name', () => {
			const { wrapper } = mountPost()

			expect(wrapper.find('.post-author-wrapper').attributes('title')).toBe('alice')
			expect(wrapper.find('.post-author-id').exists()).toBe(false)
		})

		it.each([
			['no display name at all', ''],
			['a display name of one space', ' '],
		])('falls back to the handle for an account with %s', (_, displayName) => {
			const { wrapper } = mountPost({
				item: makeItem({ account: { ...alice, display_name: displayName } }),
			})

			expect(wrapper.find('.post-author-id').text()).toBe('@alice')
		})

		it('opens the post when the card is pressed', async () => {
			// a post in a timeline is a link to itself; the picture and the
			// video open from the post's own page rather than over the
			// timeline
			const { wrapper, $router } = mountPost()

			await wrapper.find('.post-message').trigger('click')

			expect($router.push).toHaveBeenCalledWith({
				name: 'single-post',
				params: { account: 'alice', id: '101', type: 'single-post' },
			})
		})

		it('leaves the press alone when it was meant for a control inside the post', async () => {
			// liking a post is not opening it. The timestamp is excluded from
			// this on purpose: opening the post is exactly what it is for
			const { wrapper, $router, dispatch } = mountPost()

			await actionButton(wrapper, 'Like').trigger('click')
			await flushPromises()

			expect(dispatch).toHaveBeenCalled()
			expect($router.push).not.toHaveBeenCalled()
		})

		it('leaves a press alone that ended a selection', async () => {
			// dragging across a post to copy a sentence ends in a click, and
			// navigating away from what was just highlighted is the worst
			// possible answer to it
			const { wrapper, $router } = mountPost()
			const selection = vi.spyOn(window, 'getSelection').mockReturnValue({ toString: () => 'a sentence' })

			await wrapper.find('.post-message').trigger('click')

			expect($router.push).not.toHaveBeenCalled()
			selection.mockRestore()
		})

		it('leaves a modified press alone, since the card is not a link to open in a tab', async () => {
			const { wrapper, $router } = mountPost()

			await wrapper.find('.post-message').trigger('click', { metaKey: true })
			await wrapper.find('.post-message').trigger('click', { ctrlKey: true })

			expect($router.push).not.toHaveBeenCalled()
		})

		it('does not open the post the reader is already reading', async () => {
			const { wrapper, $router } = mountPost({
				route: { name: 'single-post', params: { account: 'alice', id: '101', type: 'single-post' } },
			})

			await wrapper.find('.post-message').trigger('click')

			expect($router.push).not.toHaveBeenCalled()
			expect(wrapper.classes()).not.toContain('post-content--openable')
		})

		it('opens a reply on the page of the post it answers, because a reply is a post too', async () => {
			const { wrapper, $router } = mountPost({
				route: { name: 'single-post', params: { account: 'bob', id: '999', type: 'single-post' } },
			})

			await wrapper.find('.post-message').trigger('click')

			expect($router.push).toHaveBeenCalledWith({
				name: 'single-post',
				params: { account: 'alice', id: '101', type: 'single-post' },
			})
		})

		it('renders the status content through MessageContent', () => {
			const { wrapper } = mountPost()
			expect(wrapper.find('.post-message strong').text()).toBe('world')
		})

		it('shows the post visibility as an icon with the label as tooltip', () => {
			const { wrapper } = mountPost({ item: makeItem({ visibility: 'followers' }) })
			const icon = wrapper.find('.post-visibility')
			expect(icon.classes()).toContain('account-multiple-icon')
			expect(icon.find('title').text()).toBe('Followers')
		})

		/**
		 * The byline is 12px text. A 22px globe beside it was the loudest thing
		 * in the row and is the least important thing in it.
		 */
		it('draws the visibility icon no larger than the byline it sits in', () => {
			const { wrapper } = mountPost()
			expect(wrapper.find('.post-visibility svg').attributes('width')).toBe('14')
		})

		/** two icons on one line at two different sizes read as a mistake */
		it('draws both byline icons at the same size', () => {
			const { wrapper } = mountPost({ item: makeItem({ pinned: true }) })
			const globe = wrapper.find('.post-visibility svg').attributes('width')
			const pin = wrapper.find('.post-pinned svg').attributes('width')

			expect(pin).toBe(globe)
		})

		it('exposes the creation time on the timestamp', () => {
			const { wrapper } = mountPost()
			expect(wrapper.find('.post-timestamp').attributes('data-timestamp')).toBe(String(Date.parse('2026-09-01T10:00:00Z')))
		})

		it('never renders the author bio as the body of a post without content', () => {
			// exportAsLocal() emits content verbatim, so a media-only or
			// poll-only post arrives with content: '' — and the v-else used
			// to publish the author's bio in its place
			const { wrapper } = mountPost({
				item: makeItem({ content: '', account: { ...alice, note: '<p>bio <b>bold</b></p>' } }),
			})

			expect(wrapper.find('.post-message').exists()).toBe(false)
			expect(wrapper.text()).not.toContain('bio')
		})

		it('renders attachments only when the status has some', () => {
			expect(mountPost().wrapper.findComponent({ name: 'PostAttachment' }).exists()).toBe(false)

			const media = [{ id: 'm1', url: 'https://cloud.example.org/m1.jpg' }]
			const { wrapper } = mountPost({ item: makeItem({ media_attachments: media }) })
			expect(wrapper.findComponent({ name: 'PostAttachment' }).props('attachments')).toEqual(media)
		})
	})

	describe('keyboard actions', () => {
		afterEach(() => {
			eventBus.all.clear()
		})

		it('acts on the post the keyboard is on, and ignores the others', async () => {
			const { wrapper, item, store, dispatch } = mountPost()
			eventBus.emit('timeline:focused', item)
			await wrapper.vm.$nextTick()

			eventBus.emit('shortcut:like')
			await flushPromises()
			expect(store.postLike).toHaveBeenCalledWith(expect.objectContaining({ status: item }))

			// the keyboard moves on: this post stops answering
			eventBus.emit('timeline:focused', { ...item, id: 'somewhere-else' })
			await wrapper.vm.$nextTick()
			dispatch.mockClear()

			eventBus.emit('shortcut:like')
			eventBus.emit('shortcut:boost')
			await flushPromises()
			expect(dispatch).not.toHaveBeenCalled()
		})

		it('refuses to boost what cannot be boosted', async () => {
			const { wrapper, item, dispatch } = mountPost({ item: makeItem({ visibility: 'direct' }) })
			eventBus.emit('timeline:focused', item)
			await wrapper.vm.$nextTick()

			eventBus.emit('shortcut:boost')
			await flushPromises()

			expect(dispatch).not.toHaveBeenCalled()
		})

		it('opens the focused post', async () => {
			const { wrapper, item, $router } = mountPost()
			eventBus.emit('timeline:focused', item)
			await wrapper.vm.$nextTick()

			eventBus.emit('shortcut:open')
			await flushPromises()

			expect($router.push).toHaveBeenCalledWith(expect.objectContaining({ name: 'single-post' }))
		})
	})

	describe('keyword filters', () => {
		/**
		 * `filtered` as the server sends it: Mastodon FilterResult entities,
		 * one per filter that matched. `keyword_matches` is the text of the
		 * post, not of the filter.
		 *
		 * @param {string[]} titles the names of the filters that matched
		 * @param {object} overrides anything else about the post
		 */
		function filteredPost(titles = ['Politics'], overrides = {}) {
			return makeItem({
				content: '<p>the filtered part</p>',
				filtered: titles.map((title, index) => ({
					filter: {
						id: String(index + 1),
						title,
						context: ['home'],
						expires_at: null,
						filter_action: 'warn',
					},
					keyword_matches: ['Election Day'],
					status_matches: [],
				})),
				...overrides,
			})
		}

		const cover = (wrapper) => wrapper.find('.post-filtered')
		const showAnyway = (wrapper) => wrapper.findAll('button').find((button) => button.text() === 'Show anyway')

		it('folds a post a filter matched, and keeps the body out of the page', () => {
			const { wrapper } = mountPost({ item: filteredPost() })

			expect(cover(wrapper).text()).toContain('Filtered: Politics')
			// not merely hidden with css: what somebody filtered should not be
			// sitting in the markup to be read on the way past
			expect(wrapper.text()).not.toContain('the filtered part')
			expect(wrapper.findComponent({ name: 'MessageContent' }).exists()).toBe(false)
		})

		it('shows it on request', async () => {
			const { wrapper } = mountPost({ item: filteredPost() })

			await showAnyway(wrapper).trigger('click')

			expect(wrapper.findComponent({ name: 'MessageContent' }).exists()).toBe(true)
			expect(cover(wrapper).exists()).toBe(false)
		})

		it('leaves a post nothing matched exactly as it was', () => {
			const { wrapper } = mountPost({ item: makeItem({ filtered: [] }) })

			expect(cover(wrapper).exists()).toBe(false)
			expect(wrapper.findComponent({ name: 'MessageContent' }).exists()).toBe(true)
		})

		it('names every filter that matched, each once', () => {
			const { wrapper } = mountPost({
				item: filteredPost(['Politics', 'Football', 'Politics']),
			})

			expect(cover(wrapper).text()).toContain('Filtered: Politics, Football')
		})

		/**
		 * `keyword_matches` is what the *post* said — "Election Day", as the
		 * post cased it. Printing it on the cover would put exactly the words
		 * somebody filtered back in front of them.
		 */
		it('says which filter, never what the post said to match it', () => {
			const { wrapper } = mountPost({ item: filteredPost() })

			expect(wrapper.text()).not.toContain('Election Day')
		})

		it('still says it is filtered when the filter has no name to give', () => {
			const { wrapper } = mountPost({
				item: makeItem({ filtered: [{ filter: { id: '1' }, keyword_matches: [], status_matches: [] }] }),
			})

			expect(cover(wrapper).text()).toContain('Filtered')
		})

		it('covers the pictures, the poll and the link preview too', () => {
			const { wrapper } = mountPost({
				item: filteredPost(['Politics'], {
					media_attachments: [{ id: 'm1', url: 'https://cloud.example.org/m1.jpg' }],
					poll: { id: 'p1', options: [{ title: 'yes', votes_count: 0 }], votes_count: 0, own_votes: [] },
					card: { title: 'A headline', url: 'https://example.org' },
				}),
			})

			expect(wrapper.findComponent({ name: 'PostAttachment' }).exists()).toBe(false)
			expect(wrapper.findComponent({ name: 'Poll' }).exists()).toBe(false)
			expect(wrapper.text()).not.toContain('A headline')
			// one control for one reveal, as the content warning has
			expect(wrapper.find('.post-sensitive').exists()).toBe(false)
		})

		/**
		 * The author's cover is not the reader's to lift: a post carrying both
		 * is still warned about once the filter has been shown through.
		 */
		it('hands a warned post back to its content warning rather than opening it', async () => {
			const { wrapper } = mountPost({
				item: filteredPost(['Politics'], { spoiler_text: 'an election' }),
			})

			await showAnyway(wrapper).trigger('click')

			expect(wrapper.find('.post-warning__text').text()).toBe('an election')
			expect(wrapper.text()).not.toContain('the filtered part')
		})
	})

	describe('content warnings', () => {
		const warned = () => makeItem({ spoiler_text: 'politics', content: '<p>the hidden part</p>' })

		it('keeps a warned post closed, and the body out of the page entirely', () => {
			const { wrapper } = mountPost({ item: warned() })

			expect(wrapper.find('.post-warning__text').text()).toBe('politics')
			// not merely hidden with css: an author asking for it not to be
			// shown should not have it sitting in the markup
			expect(wrapper.text()).not.toContain('the hidden part')
			expect(wrapper.findComponent({ name: 'MessageContent' }).exists()).toBe(false)
		})

		it('opens and closes it on request', async () => {
			const { wrapper } = mountPost({ item: warned() })
			const toggle = () => wrapper.findAll('button').find((button) => /Show (more|less)/.test(button.text()))

			expect(toggle().text()).toBe('Show more')
			await toggle().trigger('click')

			expect(wrapper.findComponent({ name: 'MessageContent' }).exists()).toBe(true)
			expect(toggle().text()).toBe('Show less')

			await toggle().trigger('click')
			expect(wrapper.findComponent({ name: 'MessageContent' }).exists()).toBe(false)
		})

		it('shows an unwarned post as it always did', () => {
			const { wrapper } = mountPost()

			expect(wrapper.find('.post-warning').exists()).toBe(false)
			expect(wrapper.findComponent({ name: 'MessageContent' }).exists()).toBe(true)
		})

		it('covers the pictures, the poll and the link preview too', async () => {
			// <Poll>, <PostAttachment> and <PostCard> used to be siblings
			// rendered unconditionally, so a warned post showed its media in
			// full immediately — the one thing the feature exists to prevent
			const { wrapper } = mountPost({
				item: makeItem({
					spoiler_text: 'politics',
					content: '<p>the hidden part</p>',
					media_attachments: [{ id: 'm1', url: 'https://cloud.example.org/m1.jpg' }],
					poll: { id: 'p1', options: [{ title: 'yes', votes_count: 0 }], votes_count: 0, own_votes: [] },
					card: { title: 'A headline', url: 'https://example.org' },
				}),
			})

			expect(wrapper.findComponent({ name: 'PostAttachment' }).exists()).toBe(false)
			expect(wrapper.findComponent({ name: 'Poll' }).exists()).toBe(false)
			// one control for one reveal: the warning's own "Show more" used
			// to be joined by a second "Show sensitive content" box below it
			expect(wrapper.find('.post-sensitive').exists()).toBe(false)
			expect(wrapper.findAll('button').filter((button) => /Show (more|sensitive content)/.test(button.text()))).toHaveLength(1)

			await wrapper.findAll('button').find((button) => button.text() === 'Show more').trigger('click')

			expect(wrapper.findComponent({ name: 'PostAttachment' }).exists()).toBe(true)
			expect(wrapper.findComponent({ name: 'Poll' }).exists()).toBe(true)
		})

		it('covers only the media of a post flagged sensitive without a warning', async () => {
			const { wrapper } = mountPost({
				item: makeItem({
					sensitive: true,
					content: '<p>look at this</p>',
					media_attachments: [{ id: 'm1', url: 'https://cloud.example.org/m1.jpg' }],
				}),
			})

			// the text is not warned about, so it stays readable
			expect(wrapper.find('.post-message').exists()).toBe(true)
			expect(wrapper.findComponent({ name: 'PostAttachment' }).exists()).toBe(false)

			const reveal = wrapper.findAll('button').find((button) => button.text() === 'Show sensitive content')
			expect(reveal.exists()).toBe(true)
			// the control is gone once the media is out, so it can never say
			// "expanded": it used to claim aria-expanded="false" regardless
			expect(reveal.attributes('aria-expanded')).toBeUndefined()
			await reveal.trigger('click')
			expect(wrapper.findComponent({ name: 'PostAttachment' }).exists()).toBe(true)
		})

		it('does not gate the media of a post that is neither warned nor sensitive', () => {
			const { wrapper } = mountPost({
				item: makeItem({ media_attachments: [{ id: 'm1', url: 'https://cloud.example.org/m1.jpg' }] }),
			})

			expect(wrapper.find('.post-sensitive').exists()).toBe(false)
			expect(wrapper.findComponent({ name: 'PostAttachment' }).exists()).toBe(true)
		})
	})

	describe('quoting', () => {
		const quotedStatus = (overrides = {}) => ({
			id: '77',
			url: 'https://remote.example/@bob/77',
			content: '<p>The post being quoted</p>',
			visibility: 'public',
			mentions: [],
			tags: [],
			emojis: [],
			account: bob,
			quote: null,
			...overrides,
		})
		const quoting = (quote) => makeItem({ quote })

		it('shows the quoted post inside the post that quotes it', () => {
			const { wrapper } = mountPost({
				item: quoting({ state: 'accepted', quoted_status: quotedStatus() }),
			})

			const quoted = wrapper.find('.quoted-post')
			expect(quoted.exists()).toBe(true)
			expect(quoted.text()).toContain('The post being quoted')
			expect(quoted.find('.quoted-post__handle').text()).toBe('@bob@remote.example')
			// nested, not merged into the post's own body
			expect(wrapper.find('.post-message').element.contains(quoted.element)).toBe(false)
		})

		it.each([
			['pending', 'This quote is waiting for the quoted author to approve it.'],
			['rejected', 'The author of the quoted post did not allow this quote.'],
			['revoked', 'The author of the quoted post withdrew their permission for this quote.'],
		])('says what became of a %s quote instead of showing nothing', (state, said) => {
			const { wrapper } = mountPost({ item: quoting({ state, quoted_status: null }) })

			expect(wrapper.find('.quoted-post__notice').text()).toBe(said)
		})

		it('shows nothing of the kind for a post that quotes nothing', () => {
			const quoter = mountPost({ item: quoting({ state: 'accepted', quoted_status: quotedStatus() }) })
			const { wrapper } = mountPost({ item: quoting(null) })

			expect(quoter.wrapper.find('.quoted-post').exists()).toBe(true)
			expect(wrapper.find('.quoted-post').exists()).toBe(false)
			expect(wrapper.find('.quoted-post__notice').exists()).toBe(false)
		})

		it('keeps the quote behind a content warning, like the rest of the post', async () => {
			const { wrapper } = mountPost({
				item: makeItem({
					spoiler_text: 'politics',
					quote: { state: 'accepted', quoted_status: quotedStatus() },
				}),
			})

			expect(wrapper.find('.quoted-post').exists()).toBe(false)
			expect(wrapper.text()).not.toContain('The post being quoted')

			await wrapper.findAll('button').find((button) => button.text() === 'Show more').trigger('click')

			expect(wrapper.find('.quoted-post').exists()).toBe(true)
		})

		it('keeps the quote behind the reveal of a post flagged sensitive', async () => {
			const { wrapper } = mountPost({
				item: makeItem({ sensitive: true, quote: { state: 'accepted', quoted_status: quotedStatus() } }),
			})

			expect(wrapper.find('.quoted-post').exists()).toBe(false)

			await wrapper.findAll('button').find((button) => button.text() === 'Show sensitive content').trigger('click')

			expect(wrapper.find('.quoted-post').exists()).toBe(true)
		})

		it.each(['public', 'unlisted'])('is offered on a %s post', (visibility) => {
			const { wrapper } = mountPost({ item: makeItem({ visibility }) })

			expect(menuItem(wrapper, 'Quote')).not.toBeUndefined()
		})

		it.each(['followers', 'direct'])('is not offered on a %s post, which the server would refuse', (visibility) => {
			const { wrapper } = mountPost({ item: makeItem({ visibility }) })

			expect(menuItem(wrapper, 'Quote')).toBeUndefined()
		})

		it('opens the composer and hands it the post to quote', async () => {
			const onQuote = vi.fn()
			eventBus.on('composer-quote', onQuote)
			const { wrapper, item, store } = mountPost()

			await menuItem(wrapper, 'Quote').trigger('click')

			expect(store.composerDisplayStatus).toBe(true)
			expect(onQuote).toHaveBeenCalledTimes(1)
			expect(onQuote.mock.calls[0][0]).toEqual(item)
		})
	})

	describe('reachable without a mouse', () => {
		it('is an article named after its author', () => {
			const { wrapper } = mountPost()
			const article = wrapper.find('article.post-content')

			// a post with no landmark cannot be moved between by structure
			expect(article.exists()).toBe(true)
			expect(article.attributes('aria-label')).toContain('alice')
		})

		it('opens the post from a real button, not an anchor without an href', () => {
			const { wrapper } = mountPost()
			const timestamp = wrapper.find('.post-timestamp')

			expect(timestamp.element.tagName).toBe('BUTTON')
			expect(timestamp.attributes('aria-label')).toBeTruthy()
		})

		it('keeps the like button in place as the state changes', async () => {
			const { wrapper } = mountPost()
			const like = () => wrapper.findAll('button').find((button) => /Like/.test(button.attributes('aria-label') ?? ''))

			const before = like().element
			expect(like().attributes('aria-pressed')).toBe('false')
			expect(like().attributes('aria-label')).toBe('Like')

			await wrapper.setProps({ item: makeItem({ favourited: true }) })

			// the same element, relabelled. Two buttons swapped by v-if would
			// unmount the one just pressed and drop the reader's focus with it
			expect(like().element).toBe(before)
			expect(like().attributes('aria-pressed')).toBe('true')
			expect(like().attributes('aria-label')).toBe('Undo Like')
		})

		it('says whether a post is boosted, not only how the icon is filled', async () => {
			const { wrapper } = mountPost()
			const boost = () => wrapper.findAll('button').find((button) => /[Bb]oost/.test(button.attributes('aria-label') ?? ''))

			expect(boost().attributes('aria-pressed')).toBe('false')
			await wrapper.setProps({ item: makeItem({ reblogged: true }) })
			expect(boost().attributes('aria-pressed')).toBe('true')
		})
	})

	describe('where the post came from', () => {
		/**
		 * It used to be a chip beside the byline, on every remote post. In a
		 * timeline whose whole point is that it spans servers that is a
		 * hostname next to almost every name, and the ring around the avatar
		 * was already saying the same thing in colour. The words moved to the
		 * card that opens when the avatar is hovered, where somebody who wants
		 * the hostname asks for it -- see `AccountHoverCard`.
		 */
		it('does not put the instance beside the byline', () => {
			const { wrapper } = mountPost({ item: makeItem({ account: bob }) })

			expect(wrapper.find('.post-instance').exists()).toBe(false)
			// the byline is the name and nothing else; the hostname survives
			// only in the overflow menu's "Open on original instance"
			expect(wrapper.find('.post-author').text()).toBe('Bob')
		})
	})

	describe('link preview', () => {
		const card = {
			url: 'https://example.org/news/today',
			title: 'The headline',
			description: 'What it is about',
			provider_name: 'Example News',
			image: null,
		}

		it('shows the preview of the linked page', () => {
			const { wrapper } = mountPost({ item: makeItem({ card }) })
			const preview = wrapper.findComponent({ name: 'PostCard' })

			expect(preview.exists()).toBe(true)
			expect(preview.props('card')).toEqual(card)
		})

		it('shows no preview for a post that links nowhere', () => {
			expect(mountPost().wrapper.findComponent({ name: 'PostCard' }).exists()).toBe(false)
			expect(mountPost({ item: makeItem({ card: null }) }).wrapper.findComponent({ name: 'PostCard' }).exists()).toBe(false)
		})

		it('leaves the preview out when the post carries media of its own', () => {
			const item = makeItem({
				card,
				media_attachments: [{ id: '1', type: 'image', url: 'https://cloud.example.org/m.png' }],
			})

			expect(mountPost({ item }).wrapper.findComponent({ name: 'PostCard' }).exists()).toBe(false)
		})
	})

	describe('opening the single post view', () => {
		it('navigates to the single-post route for a local post', async () => {
			const { wrapper, $router } = mountPost()
			await wrapper.find('.post-timestamp').trigger('click')
			expect($router.push).toHaveBeenCalledWith({
				name: 'single-post',
				params: { account: 'alice', id: '101', type: 'single-post' },
			})
		})

		it('opens the thread of a remote post too, keyed by its full handle', async () => {
			// the click used to return early with a logger.warn for anything
			// non-local, which is most of the Global timeline: the timestamp
			// is the only affordance for opening a thread, and it did nothing
			const { wrapper, $router } = mountPost({ item: makeItem({ account: bob }) })
			await wrapper.find('.post-timestamp').trigger('click')
			expect($router.push).toHaveBeenCalledWith({
				name: 'single-post',
				params: { account: 'bob@remote.example', id: '101', type: 'single-post' },
			})
		})

		it('does not navigate when there is no account or id to navigate to', async () => {
			const { wrapper, $router } = mountPost({ item: makeItem({ account: { ...alice, acct: '' } }) })
			await wrapper.find('.post-timestamp').trigger('click')
			expect($router.push).not.toHaveBeenCalled()
		})

		it('offers the original instance for a remote post, and not for a local one', () => {
			const remote = mountPost({ item: makeItem({ account: bob, url: 'https://remote.example/@bob/101' }) })
			const link = remote.wrapper.find('.post-menu__link')
			expect(link.exists()).toBe(true)
			expect(link.attributes('href')).toBe('https://remote.example/@bob/101')

			expect(mountPost().wrapper.find('.post-menu__link').exists()).toBe(false)
		})
	})

	describe('action bar', () => {
		it('is shown on a regular timeline for a logged-in user', () => {
			const { wrapper } = mountPost()
			expect(wrapper.find('.post-actions').exists()).toBe(true)
			expect(actionButton(wrapper, 'Reply').exists()).toBe(true)
		})

		it('puts the overflow menu ahead of the counts, so they end at the card edge', () => {
			const { wrapper } = mountPost()
			const row = wrapper.find('.post-actions')

			const children = Array.from(row.element.children)
			const menu = row.find('.post-menu').element
			const counts = row.find('.post-actions__groups').element

			expect(children.indexOf(menu)).toBeLessThan(children.indexOf(counts))
		})

		it('is hidden on the notifications timeline', () => {
			const { wrapper } = mountPost({ route: { name: 'timeline', params: { type: 'notifications' } } })
			expect(wrapper.find('.post-actions').exists()).toBe(false)
		})

		it('is hidden on the public page', () => {
			const { wrapper } = mountPost({ serverData: { public: true, cloudAddress: 'https://cloud.example.org' } })
			expect(wrapper.find('.post-actions').exists()).toBe(false)
		})

		/**
		 * The card grows for the row when the pointer arrives, in CSS. The one
		 * case CSS cannot see is the overflow menu: it opens in a portal, so
		 * the pointer is off the card while its own menu is open, and the card
		 * would close under it.
		 */
		it('holds the action row open while the overflow menu is', async () => {
			const { wrapper } = mountPost()
			expect(wrapper.find('.post-actions-reveal').classes()).not.toContain('post-actions-reveal--held')

			wrapper.findComponent({ name: 'NcActions' }).vm.$emit('update:open', true)
			await wrapper.vm.$nextTick()
			expect(wrapper.find('.post-actions-reveal').classes()).toContain('post-actions-reveal--held')

			wrapper.findComponent({ name: 'NcActions' }).vm.$emit('update:open', false)
			await wrapper.vm.$nextTick()
			expect(wrapper.find('.post-actions-reveal').classes()).not.toContain('post-actions-reveal--held')
		})

		it('shows a counter only when it is above zero', () => {
			expect(mountPost().wrapper.findAll('.post-action-count')).toHaveLength(0)

			const { wrapper } = mountPost({ item: makeItem({ replies_count: 3, reblogs_count: 0, favourites_count: 12 }) })
			expect(wrapper.findAll('.post-action-count').map((count) => count.text())).toEqual(['3', '12'])
		})
	})

	describe('rolling counters', () => {
		const counter = (wrapper) => wrapper.find('.post-action-group--like .post-action-count')

		it('rolls the number up to what the like made it, and settles as one number', async () => {
			vi.useFakeTimers()
			const { wrapper } = mountPost({ item: makeItem({ favourites_count: 3 }) })

			await wrapper.setProps({ item: makeItem({ favourites_count: 4, favourited: true }) })

			// the new digit arrives while the old one is still on its way out
			expect(counter(wrapper).classes()).toContain('rolling-count--up')
			expect(counter(wrapper).find('.rolling-count__value--current').text()).toBe('4')
			const leaving = counter(wrapper).find('.rolling-count__value--leaving')
			expect(leaving.text()).toBe('3')
			expect(leaving.attributes('aria-hidden')).toBe('true')

			vi.advanceTimersByTime(300)
			await wrapper.vm.$nextTick()

			expect(counter(wrapper).text()).toBe('4')
			expect(counter(wrapper).classes()).not.toContain('rolling-count--up')
			vi.useRealTimers()
		})

		it('rolls the other way when the count drops', async () => {
			const { wrapper } = mountPost({ item: makeItem({ favourites_count: 12, favourited: true }) })

			await wrapper.setProps({ item: makeItem({ favourites_count: 11 }) })

			expect(counter(wrapper).classes()).toContain('rolling-count--down')
			expect(counter(wrapper).find('.rolling-count__value--current').text()).toBe('11')
			expect(counter(wrapper).find('.rolling-count__value--leaving').text()).toBe('12')
		})

		it('rolls the very first one in, with no digit to send away', async () => {
			const { wrapper } = mountPost({ item: makeItem({ favourites_count: 0 }) })
			expect(counter(wrapper).exists()).toBe(false)

			await wrapper.setProps({ item: makeItem({ favourites_count: 1, favourited: true }) })

			expect(counter(wrapper).classes()).toContain('rolling-count--up')
			expect(counter(wrapper).find('.rolling-count__value--current').text()).toBe('1')
			expect(counter(wrapper).find('.rolling-count__value--leaving').exists()).toBe(false)
		})

		it('takes the counter away again when the last like is undone', async () => {
			const { wrapper } = mountPost({ item: makeItem({ favourites_count: 1, favourited: true }) })

			await wrapper.setProps({ item: makeItem({ favourites_count: 0 }) })

			expect(counter(wrapper).exists()).toBe(false)
		})

		it('rolls a number of any length', async () => {
			const { wrapper } = mountPost({ item: makeItem({ favourites_count: 999 }) })

			await wrapper.setProps({ item: makeItem({ favourites_count: 1000, favourited: true }) })

			expect(counter(wrapper).find('.rolling-count__value--current').text()).toBe('1000')
			expect(counter(wrapper).find('.rolling-count__value--leaving').text()).toBe('999')
		})

		it('swaps the number outright for a reader who asked for reduced motion', async () => {
			const matchMedia = vi.spyOn(window, 'matchMedia').mockReturnValue({ matches: true })
			const { wrapper } = mountPost({ item: makeItem({ favourites_count: 3 }) })

			await wrapper.setProps({ item: makeItem({ favourites_count: 4, favourited: true }) })

			expect(matchMedia).toHaveBeenCalledWith('(prefers-reduced-motion: reduce)')
			expect(counter(wrapper).text()).toBe('4')
			expect(counter(wrapper).classes()).not.toContain('rolling-count--up')
			expect(counter(wrapper).find('.rolling-count__value--leaving').exists()).toBe(false)
			matchMedia.mockRestore()
		})
	})

	describe('reply', () => {
		it('opens the composer and hands it the status to reply to', async () => {
			const onReply = vi.fn()
			eventBus.on('composer-reply', onReply)
			const { wrapper, item, store } = mountPost()

			await actionButton(wrapper, 'Reply').trigger('click')

			expect(store.composerDisplayStatus).toBe(true)
			expect(onReply).toHaveBeenCalledTimes(1)
			expect(onReply.mock.calls[0][0]).toEqual(item)
		})
	})

	describe('boost', () => {
		it.each(['public', 'unlisted'])('is offered for a %s post', (visibility) => {
			const { wrapper } = mountPost({ item: makeItem({ visibility }) })
			expect(actionButton(wrapper, 'Boost').exists()).toBe(true)
		})

		it.each(['followers', 'direct'])('is not offered for a %s post', (visibility) => {
			const { wrapper } = mountPost({ item: makeItem({ visibility }) })
			expect(actionButton(wrapper, 'Boost').exists()).toBe(false)
			expect(actionButton(wrapper, 'Undo boost').exists()).toBe(false)
		})

		it('boosts a post that is not boosted yet', async () => {
			const { wrapper, item, store, dispatch } = mountPost()
			await actionButton(wrapper, 'Boost').trigger('click')
			expect(dispatch).toHaveBeenCalledTimes(1)
			expect(store.postBoost).toHaveBeenCalledWith(expect.objectContaining({ status: item }))
		})

		it('undoes the boost of an already boosted post', async () => {
			const { wrapper, item, store, dispatch } = mountPost({ item: makeItem({ reblogged: true }) })
			expect(actionButton(wrapper, 'Boost').exists()).toBe(false)

			await actionButton(wrapper, 'Undo boost').trigger('click')

			expect(dispatch).toHaveBeenCalledTimes(1)
			expect(store.postUnBoost).toHaveBeenCalledWith(expect.objectContaining({ status: item }))
		})
	})

	describe('like', () => {
		it('likes a post that is not liked yet', async () => {
			const { wrapper, item, store, dispatch } = mountPost()
			expect(actionButton(wrapper, 'Like').find('.heart-outline-icon').exists()).toBe(true)
			expect(actionButton(wrapper, 'Undo Like').exists()).toBe(false)

			await actionButton(wrapper, 'Like').trigger('click')

			expect(dispatch).toHaveBeenCalledTimes(1)
			expect(store.postLike).toHaveBeenCalledWith(expect.objectContaining({ status: item }))
		})

		it('removes the like from a liked post', async () => {
			const { wrapper, item, store, dispatch } = mountPost({ item: makeItem({ favourited: true }) })
			expect(actionButton(wrapper, 'Like').exists()).toBe(false)
			expect(actionButton(wrapper, 'Undo Like').find('.heart-icon').exists()).toBe(true)

			await actionButton(wrapper, 'Undo Like').trigger('click')

			expect(dispatch).toHaveBeenCalledTimes(1)
			expect(store.postUnlike).toHaveBeenCalledWith(expect.objectContaining({ status: item }))
		})

		it('confirms the like with the heart, and only when liking', async () => {
			const { wrapper } = mountPost()
			await actionButton(wrapper, 'Like').trigger('click')
			await flushPromises()

			expect(wrapper.find('.post-action__burst').exists()).toBe(true)

			// undoing is not something to celebrate
			const undoing = mountPost({ item: makeItem({ favourited: true }) })
			await actionButton(undoing.wrapper, 'Undo Like').trigger('click')
			await flushPromises()

			expect(undoing.wrapper.find('.post-action__burst').exists()).toBe(false)
		})

		it('says so when the server refuses, instead of flipping back in silence', async () => {
			// the store reports its own error and resolves undefined
			const { wrapper } = mountPost({ dispatch: vi.fn().mockResolvedValue(undefined) })

			await actionButton(wrapper, 'Like').trigger('click')
			await flushPromises()

			expect(wrapper.find('.post-action-group--like').classes()).toContain('post-action-group--refused')
			expect(wrapper.find('.post-action__burst').exists()).toBe(false)
		})
	})

	describe('blocking and muting the author', () => {
		it('offers both for somebody else\'s post', () => {
			const { wrapper } = mountPost({ item: makeItem({ account: bob }) })

			expect(menuItem(wrapper, 'Mute bob@remote.example')).toBeDefined()
			expect(menuItem(wrapper, 'Block bob@remote.example')).toBeDefined()
		})

		it('offers neither on the reader\'s own post', () => {
			const { wrapper } = mountPost()

			expect(menuItem(wrapper, 'Mute alice')).toBeUndefined()
			expect(menuItem(wrapper, 'Block alice')).toBeUndefined()
		})

		it('offers neither on the public pages, where nobody is signed in', () => {
			const { wrapper } = mountPost({
				item: makeItem({ account: bob }),
				currentAccount: null,
				serverData: { public: true, cloudAddress: 'https://cloud.example.org' },
			})

			expect(menuItem(wrapper, 'Mute bob@remote.example')).toBeUndefined()
			expect(menuItem(wrapper, 'Block bob@remote.example')).toBeUndefined()
		})

		/** The same dialog the profile opens, with the same two questions. */
		it('asks the mute dialog rather than muting outright', async () => {
			const { wrapper } = mountPost({ item: makeItem({ account: bob }) })
			const accountStore = useAccountStore()
			vi.spyOn(accountStore, 'muteAccount').mockResolvedValue({ id: bob.id })

			await menuItem(wrapper, 'Mute bob@remote.example').trigger('click')
			await flushPromises()

			expect(accountStore.muteAccount).not.toHaveBeenCalled()
			// the dialog is fetched before it is mounted, which is more than
			// one tick: the press awaits a real `import()`
			await vi.waitUntil(
				() => wrapper.findComponent({ name: 'MuteDialog' }).exists(),
				{ timeout: 15000 },
			)
		})

		it('asks before blocking, and then blocks', async () => {
			const { wrapper } = mountPost({ item: makeItem({ account: bob }) })
			const accountStore = useAccountStore()
			vi.spyOn(accountStore, 'blockAccount').mockResolvedValue({ id: bob.id, blocking: true })

			await menuItem(wrapper, 'Block bob@remote.example').trigger('click')
			expect(accountStore.blockAccount).not.toHaveBeenCalled()

			await wrapper.find('.nc-dialog__button--1').trigger('click')
			await flushPromises()

			expect(accountStore.blockAccount).toHaveBeenCalledWith({ id: bob.id })
		})

		it('leaves the confirmation up when the block was refused', async () => {
			const { wrapper } = mountPost({ item: makeItem({ account: bob }) })
			const accountStore = useAccountStore()
			// the store says what went wrong and answers with nothing
			vi.spyOn(accountStore, 'blockAccount').mockResolvedValue(undefined)

			await menuItem(wrapper, 'Block bob@remote.example').trigger('click')
			await wrapper.find('.nc-dialog__button--1').trigger('click')
			await flushPromises()

			expect(wrapper.find('.nc-dialog').exists()).toBe(true)
		})
	})

	describe('edit and delete', () => {
		it('are offered for the viewer\'s own post', () => {
			const { wrapper } = mountPost()
			expect(menuItem(wrapper, 'Edit')).toBeDefined()
			expect(menuItem(wrapper, 'Delete')).toBeDefined()
		})

		it('lets the report dialog close itself, which needs a two-way binding', async () => {
			const { wrapper } = mountPost({ item: makeItem({ account: bob }) })
			await menuItem(wrapper, 'Report').trigger('click')
			expect(wrapper.find('.report-dialog').exists()).toBe(true)

			// :open.sync did nothing on Vue 3: the flag never came back
			await wrapper.find('.report-dialog__close').trigger('click')

			expect(wrapper.find('.report-dialog').exists()).toBe(false)
		})

		it('are withheld for somebody else\'s post, which offers Report instead', () => {
			const { wrapper } = mountPost({ item: makeItem({ account: bob }) })
			expect(menuItem(wrapper, 'Edit')).toBeUndefined()
			expect(menuItem(wrapper, 'Delete')).toBeUndefined()
			expect(menuItem(wrapper, 'Report')).toBeDefined()
		})

		it('are withheld while the current account is unknown', () => {
			const { wrapper } = mountPost({ currentAccount: null })
			expect(menuItem(wrapper, 'Edit')).toBeUndefined()
			expect(menuItem(wrapper, 'Delete')).toBeUndefined()
		})

		it('says where a post was taken, and only when the poster said', () => {
			const { wrapper } = mountPost({ item: makeItem({ place: { id: '4', name: 'Berlin', country: 'Germany' } }) })
			const chip = wrapper.find('.post-place')

			expect(chip.exists()).toBe(true)
			expect(chip.text()).toContain('Berlin, Germany')
			expect(wrapper.findComponent('.post-place').props('to')).toEqual({ name: 'place', params: { id: '4' } })
			expect(mountPost({ item: makeItem({ place: null }) }).wrapper.find('.post-place').exists()).toBe(false)
		})

		it('offers Add to a collection only for an own post that has a picture in it', () => {
			expect(menuItem(mountPost({ item: makeItem({ media_attachments: [photo()] }) }).wrapper, 'Add to a collection')).toBeDefined()
			// a collection holds only its owner's own media posts
			expect(menuItem(mountPost({ item: makeItem() }).wrapper, 'Add to a collection')).toBeUndefined()
			expect(menuItem(mountPost({ item: makeItem({ account: bob, media_attachments: [photo()] }) }).wrapper, 'Add to a collection')).toBeUndefined()
			expect(menuItem(mountPost({ item: makeItem({ local: false, media_attachments: [photo()] }) }).wrapper, 'Add to a collection')).toBeUndefined()
		})

		it('opens the collection picker for the post', async () => {
			const { wrapper } = mountPost({ item: makeItem({ media_attachments: [photo()] }) })

			await menuItem(wrapper, 'Add to a collection').trigger('click')

			const picker = wrapper.findComponent({ name: 'CollectionPickerDialog' })
			expect(picker.exists()).toBe(true)
			expect(picker.props('status').id).toBe('101')
		})

		it('offers Pin to profile for an own post and pins it', async () => {
			const { wrapper, item, store } = mountPost()

			expect(menuItem(wrapper, 'Pin to profile')).toBeDefined()
			await menuItem(wrapper, 'Pin to profile').trigger('click')

			expect(store.postPin).toHaveBeenCalledWith({ status: item, pinned: true })
		})

		it('flips to Unpin from profile for a post that is already pinned', async () => {
			const { wrapper, item, store } = mountPost({ item: makeItem({ pinned: true }) })

			expect(menuItem(wrapper, 'Pin to profile')).toBeUndefined()
			await menuItem(wrapper, 'Unpin from profile').trigger('click')

			expect(store.postPin).toHaveBeenCalledWith({ status: item, pinned: false })
		})

		it('never offers pinning for somebody else\'s post or a remote one', () => {
			expect(menuItem(mountPost({ item: makeItem({ account: bob }) }).wrapper, 'Pin to profile')).toBeUndefined()
			expect(menuItem(mountPost({ item: makeItem({ local: false }) }).wrapper, 'Pin to profile')).toBeUndefined()
		})

		it.each(['public', 'unlisted'])('offers pinning a %s post', (visibility) => {
			expect(menuItem(mountPost({ item: makeItem({ visibility }) }).wrapper, 'Pin to profile')).toBeDefined()
		})

		it.each(['private', 'direct'])('never offers pinning a %s post', (visibility) => {
			// the featured collection is public: a pinned followers-only post
			// was served in full to anyone who asked, so the server refuses
			// anything but public and unlisted and the entry has to go
			expect(menuItem(mountPost({ item: makeItem({ visibility }) }).wrapper, 'Pin to profile')).toBeUndefined()
		})

		it('marks a pinned post in the header', () => {
			expect(mountPost().wrapper.find('.post-pinned').exists()).toBe(false)
			expect(mountPost({ item: makeItem({ pinned: true }) }).wrapper.find('.post-pinned').text()).toBe('Pinned')
		})

		it('asks before deleting, and only then deletes', async () => {
			const { wrapper, item, store } = mountPost()

			await menuItem(wrapper, 'Delete').trigger('click')
			// one click on a menu item sitting right under "Edit" used to be
			// enough for something irreversible and federated
			expect(store.postDelete).not.toHaveBeenCalledWith(item)

			const dialog = wrapper.findAll('.nc-dialog').find((el) => el.text().includes('Delete this post?'))
			expect(dialog).toBeDefined()

			await dialog.find('.nc-dialog__button--1').trigger('click')
			expect(store.postDelete).toHaveBeenCalledWith(item)
		})

		it('leaves the post alone when the delete confirmation is cancelled', async () => {
			const { wrapper, item, store } = mountPost()

			await menuItem(wrapper, 'Delete').trigger('click')
			const dialog = wrapper.findAll('.nc-dialog').find((el) => el.text().includes('Delete this post?'))
			await dialog.find('.nc-dialog__button--0').trigger('click')

			expect(store.postDelete).not.toHaveBeenCalledWith(item)
			expect(wrapper.findAll('.nc-dialog').some((el) => el.text().includes('Delete this post?'))).toBe(false)
		})

		it('offers the author, and only the author, the delivery status of a local post', () => {
			expect(menuItem(mountPost().wrapper, 'Delivery status')).toBeDefined()
			// which servers a post reached is a fact about the author's account
			expect(menuItem(mountPost({ item: makeItem({ account: bob }) }).wrapper, 'Delivery status')).toBeUndefined()
			// a post that arrived from elsewhere was never in this queue
			expect(menuItem(mountPost({ item: makeItem({ local: false }) }).wrapper, 'Delivery status')).toBeUndefined()
		})

		it('asks the server where the post got to and lists it per server, worst first', async () => {
			axios.get.mockResolvedValueOnce({
				data: {
					delivered: 1,
					sending: 0,
					waiting: 0,
					failing: 1,
					abandoned: 1,
					total: 3,
					retention: 604800,
					instances: [
						{ host: 'gone.example', state: 'abandoned', tries: 16, last: 1 },
						{ host: 'slow.example', state: 'failing', tries: 3, last: 2 },
						{ host: 'fine.example', state: 'delivered', tries: 0, last: 3 },
					],
				},
			})
			const { wrapper, item } = mountPost()

			await menuItem(wrapper, 'Delivery status').trigger('click')
			await flushPromises()

			expect(axios.get).toHaveBeenCalledWith(`/index.php/apps/social/api/v1/statuses/${item.id}/delivery`)
			const dialog = wrapper.findAll('.nc-dialog').find((el) => el.text().includes('Delivery status'))
			expect(dialog.find('.delivery-hint').text()).toBe('Of 3 deliveries: delivered to 1 server, failing against 1 server, given up on 1 server.')
			const rows = dialog.findAll('.delivery-list__row')
			expect(rows.map((row) => row.find('.delivery-list__host').text())).toEqual(['gone.example', 'slow.example', 'fine.example'])
			expect(rows.map((row) => row.find('.delivery-list__state').text())).toEqual(['Given up after 16 attempts', 'Failing (3 attempts)', 'Delivered'])
			expect(rows[0].classes()).toContain('delivery-list__row--abandoned')
		})

		it('says so when nothing is on record for the post', async () => {
			axios.get.mockResolvedValueOnce({
				data: { delivered: 0, sending: 0, waiting: 0, failing: 0, abandoned: 0, total: 0, retention: 604800, instances: [] },
			})
			const { wrapper } = mountPost()

			await menuItem(wrapper, 'Delivery status').trigger('click')
			await flushPromises()

			const dialog = wrapper.findAll('.nc-dialog').find((el) => el.text().includes('Delivery status'))
			expect(dialog.text()).toContain('Delivery records are kept for 7 days')
			expect(dialog.text()).toContain('does not broadcast them to every known server')
			expect(dialog.find('.delivery-list').exists()).toBe(false)
		})

		it('reports a failed lookup instead of an empty dialog', async () => {
			axios.get.mockRejectedValueOnce(new Error('500'))
			const { wrapper } = mountPost()

			await menuItem(wrapper, 'Delivery status').trigger('click')
			await flushPromises()

			const dialog = wrapper.findAll('.nc-dialog').find((el) => el.text().includes('Delivery status'))
			expect(dialog.find('.delivery-hint--error').text()).toBe('Could not read the delivery status of this post.')
		})

		it('opens an inline editor prefilled with the plain text of the post', async () => {
			const { wrapper } = mountPost({ item: makeItem({ content: '<p>Line one</p><p>Line <b>two</b><br>three</p>' }) })

			await menuItem(wrapper, 'Edit').trigger('click')

			expect(wrapper.find('.post-message').exists()).toBe(false)
			expect(wrapper.find('textarea.post-edit-textarea').element.value).toBe('Line one\nLine two\nthree')
		})

		it('saves the trimmed text and leaves edit mode', async () => {
			const { wrapper, item, store } = mountPost()
			await menuItem(wrapper, 'Edit').trigger('click')

			await wrapper.find('textarea').setValue('  Edited text  ')
			await wrapper.find('.post-edit-actions button[aria-label="Save"]').trigger('click')
			await flushPromises()

			expect(store.postEdit).toHaveBeenCalledWith({
				status: item,
				content: 'Edited text',
				spoiler_text: '',
				sensitive: false,
			})
			expect(wrapper.find('textarea').exists()).toBe(false)
			expect(wrapper.find('.post-message').exists()).toBe(true)
		})

		it('keeps the content warning of the post it is editing', async () => {
			// saveEdit always sent spoiler_text: '' and sensitive: false, so
			// fixing a typo un-hid sensitive content for every follower
			const item = makeItem({ spoiler_text: 'politics', sensitive: true, content: '<p>Old</p>' })
			const { wrapper, store } = mountPost({ item })

			await menuItem(wrapper, 'Edit').trigger('click')
			expect(wrapper.find('input.post-edit-warning').element.value).toBe('politics')

			await wrapper.find('textarea').setValue('New text')
			await wrapper.find('.post-edit-actions button[aria-label="Save"]').trigger('click')
			await flushPromises()

			expect(store.postEdit).toHaveBeenCalledWith({
				status: item,
				content: 'New text',
				spoiler_text: 'politics',
				sensitive: true,
			})
		})

		it('lets the warning be changed and removed from the inline editor', async () => {
			const item = makeItem({ spoiler_text: 'politics', sensitive: true, content: '<p>Old</p>' })
			const { wrapper, store } = mountPost({ item })

			await menuItem(wrapper, 'Edit').trigger('click')
			await wrapper.find('input.post-edit-warning').setValue('  ')
			await wrapper.find('textarea').setValue('Now harmless')
			await wrapper.find('.post-edit-actions button[aria-label="Save"]').trigger('click')
			await flushPromises()

			expect(store.postEdit).toHaveBeenCalledWith(expect.objectContaining({
				spoiler_text: '',
				// the post was flagged sensitive; dropping the warning does
				// not silently unflag the media
				sensitive: true,
			}))
		})

		it('never seeds the editor from the author bio, and refuses an over-long edit', async () => {
			const { wrapper, store } = mountPost({
				item: makeItem({ content: '', account: { ...alice, note: '<p>the bio</p>' } }),
			})

			await menuItem(wrapper, 'Edit').trigger('click')
			expect(wrapper.find('textarea').element.value).toBe('')

			await wrapper.find('textarea').setValue('x'.repeat(501))
			expect(wrapper.find('.post-edit-actions button[aria-label="Save"]').attributes('disabled')).toBeDefined()
			expect(wrapper.find('.post-edit-count').text()).toBe('1 character too many')

			await wrapper.find('textarea').trigger('keydown', { key: 'Enter', ctrlKey: true })
			await flushPromises()
			expect(store.postEdit).not.toHaveBeenCalled()
		})

		it('keeps the editor open when the server refuses the edit', async () => {
			const { wrapper } = mountPost({ dispatch: vi.fn().mockResolvedValue(undefined) })
			await menuItem(wrapper, 'Edit').trigger('click')

			await wrapper.find('textarea').setValue('Edited text')
			await wrapper.find('.post-edit-actions button[aria-label="Save"]').trigger('click')
			await flushPromises()

			expect(wrapper.find('textarea').element.value).toBe('Edited text')
		})

		it('saves on Ctrl+Enter', async () => {
			const { wrapper, store } = mountPost()
			await menuItem(wrapper, 'Edit').trigger('click')

			await wrapper.find('textarea').setValue('Quick fix')
			await wrapper.find('textarea').trigger('keydown', { key: 'Enter', ctrlKey: true })
			await flushPromises()

			expect(store.postEdit).toHaveBeenCalledWith(expect.objectContaining({ content: 'Quick fix' }))
		})

		it('refuses to save an empty edit and stays in edit mode', async () => {
			const { wrapper, dispatch } = mountPost()
			await menuItem(wrapper, 'Edit').trigger('click')

			await wrapper.find('textarea').setValue('   ')
			await wrapper.find('.post-edit-actions button[aria-label="Save"]').trigger('click')
			await flushPromises()

			expect(dispatch).not.toHaveBeenCalled()
			expect(wrapper.find('textarea').exists()).toBe(true)
		})

		it('cancels without saving and restores the content', async () => {
			const { wrapper, dispatch } = mountPost()
			await menuItem(wrapper, 'Edit').trigger('click')
			await wrapper.find('textarea').setValue('Never saved')

			await wrapper.find('.post-edit-actions button[aria-label="Cancel"]').trigger('click')

			expect(dispatch).not.toHaveBeenCalled()
			expect(wrapper.find('textarea').exists()).toBe(false)
			expect(wrapper.find('.post-message strong').text()).toBe('world')

			// the draft is not kept for the next edit
			await menuItem(wrapper, 'Edit').trigger('click')
			expect(wrapper.find('textarea').element.value).toBe('Hello world')
		})
	})
	describe('the media-first layout', () => {
		it('leads with the pictures and reads the text as their caption', () => {
			const media = [photo(1), photo(2)]
			const { wrapper } = mountPost({ item: makeItem({ media_attachments: media }) })

			const attachments = attachmentsOf(wrapper)
			expect(attachments.props('mediaFirst')).toBe(true)
			expect(attachments.props('attachments')).toEqual(media)

			const caption = wrapper.find('.post-message')
			expect(caption.classes()).toContain('post-message--caption')
			expect(isBefore(attachments.element, caption.element)).toBe(true)
		})

		it('leaves a post without media exactly as it was, text first and uncaptioned', () => {
			const { wrapper } = mountPost()

			expect(attachmentsOf(wrapper).exists()).toBe(false)
			expect(wrapper.find('.post-message').exists()).toBe(true)
			expect(wrapper.find('.post-message').classes()).not.toContain('post-message--caption')
		})

		it('shows a picture post with no text at all as the picture alone', () => {
			const { wrapper } = mountPost({
				item: makeItem({ content: '', media_attachments: [photo(1)] }),
			})

			expect(attachmentsOf(wrapper).props('mediaFirst')).toBe(true)
			expect(wrapper.find('.post-message').exists()).toBe(false)
		})

		it('puts the text back on top while the post is being edited', async () => {
			const { wrapper } = mountPost({ item: makeItem({ media_attachments: [photo(1)] }) })

			await menuItem(wrapper, 'Edit').trigger('click')

			// the editor is what is being worked on, and it is the text
			expect(wrapper.find('.post-edit-textarea').exists()).toBe(true)
			expect(attachmentsOf(wrapper).props('mediaFirst')).toBe(false)
			expect(isBefore(wrapper.find('.post-edit-inline').element, attachmentsOf(wrapper).element)).toBe(true)
		})

		it('never renders the pictures twice, in either layout', () => {
			const withMedia = mountPost({ item: makeItem({ media_attachments: [photo(1)] }) }).wrapper
			const warned = mountPost({
				item: makeItem({ spoiler_text: 'politics', media_attachments: [photo(1)] }),
			}).wrapper

			expect(withMedia.findAllComponents({ name: 'PostAttachment' })).toHaveLength(1)
			expect(warned.findAllComponents({ name: 'PostAttachment' })).toHaveLength(0)
		})
	})

	describe('the media-first layout and the reveals', () => {
		const sensitive = () => makeItem({
			sensitive: true,
			content: '<p>look at this</p>',
			media_attachments: [photo(1)],
		})

		it('keeps a picture post flagged sensitive covered, and offers one reveal', async () => {
			const { wrapper } = mountPost({ item: sensitive() })

			expect(attachmentsOf(wrapper).exists()).toBe(false)
			const reveals = wrapper.findAll('button').filter((button) => button.text() === 'Show sensitive content')
			expect(reveals).toHaveLength(1)
			// the reveal stands where the pictures will be: the caption below it
			// does not move when they arrive
			const cover = wrapper.find('.post-sensitive--leading')
			expect(cover.exists()).toBe(true)
			expect(isBefore(cover.element, wrapper.find('.post-message').element)).toBe(true)

			await reveals[0].trigger('click')

			expect(attachmentsOf(wrapper).props('mediaFirst')).toBe(true)
			expect(isBefore(attachmentsOf(wrapper).element, wrapper.find('.post-message').element)).toBe(true)
		})

		it('never lets a warned picture post lead with the picture', async () => {
			const item = makeItem({
				spoiler_text: 'politics',
				content: '<p>the hidden part</p>',
				media_attachments: [photo(1)],
			})
			const { wrapper } = mountPost({ item })

			expect(attachmentsOf(wrapper).exists()).toBe(false)
			expect(wrapper.find('.post-sensitive').exists()).toBe(false)
			expect(wrapper.find('.post-warning').exists()).toBe(true)

			await wrapper.findAll('button').find((button) => button.text() === 'Show more').trigger('click')

			// the cover still leads the post, so the picture is not given the top
			const attachments = attachmentsOf(wrapper)
			expect(attachments.props('mediaFirst')).toBe(false)
			expect(isBefore(wrapper.find('.post-warning').element, attachments.element)).toBe(true)
		})

		it('covers a warned picture post again when the warning is put back', async () => {
			const item = makeItem({
				spoiler_text: 'politics',
				content: '<p>the hidden part</p>',
				media_attachments: [photo(1)],
			})
			const { wrapper } = mountPost({ item })
			await wrapper.findAll('button').find((button) => button.text() === 'Show more').trigger('click')

			await wrapper.findAll('button').find((button) => button.text() === 'Show less').trigger('click')

			expect(attachmentsOf(wrapper).exists()).toBe(false)
		})
	})
	describe('the reaction bar', () => {
		/**
		 * The card must address the post by `id`, the string, and never by
		 * `nid`, the JSON number.
		 *
		 * A post id is a snowflake well past Number.MAX_SAFE_INTEGER: parsed as
		 * a number it loses its last digits, so `…043682` becomes `…043600` and
		 * the server answers 404 for a post that is on the screen. The card
		 * passed `nid` and every reaction failed with "Could not add that
		 * reaction".
		 */
		it('addresses the post by its string id, not the rounded nid', () => {
			const { wrapper } = mountPost({
				// Number(), not a literal: the literal is the lossy value this
				// test is about, and eslint refuses to let one be written
				item: makeItem({ id: '1789344497747043682', nid: Number('1789344497747043682') }),
			})

			const bar = wrapper.findComponent({ name: 'ReactionBar' })

			expect(bar.props('statusId')).toBe('1789344497747043682')
			expect(bar.props('statusId')).not.toContain('043600')
		})

		it('hands the bar what the server sent with the post', () => {
			const reactions = [{ name: '🎉', count: 2, me: true }]
			const { wrapper } = mountPost({ item: makeItem({ reactions }) })

			expect(wrapper.findComponent({ name: 'ReactionBar' }).props('modelValue')).toEqual(reactions)
		})

		// the public page has nobody to react as
		it('lets nobody react on the public page', () => {
			const { wrapper } = mountPost({
				serverData: { public: true, cloudAddress: 'https://cloud.example.org' },
			})

			expect(wrapper.findComponent({ name: 'ReactionBar' }).props('canReact')).toBe(false)
		})
	})

	// deleting and writing it again

	/**
	 * The words come back in the composer only after the post is actually
	 * gone: a delete the server refuses leaves the post where it is, and a
	 * composer already holding its words would invite posting it twice.
	 */
	it('hands the post to the composer after it has been deleted', async () => {
		const item = makeItem({
			content: '<p>Hello <strong>world</strong></p>',
			spoiler_text: 'spoilers',
			visibility: 'followers',
			language: 'de',
		})
		const { wrapper, store } = mountPost({ item })
		const redrafted = vi.fn()
		eventBus.on('composer-redraft', redrafted)

		const redraft = wrapper.findAll('.post-menu__item')
			.find((button) => button.text() === 'Delete & re-draft')
		await redraft.trigger('click')
		await wrapper.find('.nc-dialog__button--1').trigger('click')
		await flushPromises()

		expect(store.postDelete).toHaveBeenCalled()
		expect(redrafted).toHaveBeenCalledWith(item)
		eventBus.off('composer-redraft', redrafted)
	})

	it('says what a re-draft will cost before doing it', async () => {
		const { wrapper } = mountPost()

		const redraft = wrapper.findAll('.post-menu__item')
			.find((button) => button.text() === 'Delete & re-draft')
		await redraft.trigger('click')

		expect(wrapper.find('.nc-dialog__name').text()).toBe('Delete and write it again?')
		expect(wrapper.find('.delete-hint').text()).toContain('a new post')
	})

	/** An ordinary delete is still an ordinary delete. */
	it('does not open the composer for a plain delete', async () => {
		const { wrapper, store } = mountPost()
		const redrafted = vi.fn()
		eventBus.on('composer-redraft', redrafted)

		const remove = wrapper.findAll('.post-menu__item')
			.find((button) => button.text() === 'Delete')
		await remove.trigger('click')
		await wrapper.find('.nc-dialog__button--1').trigger('click')
		await flushPromises()

		expect(store.postDelete).toHaveBeenCalled()
		expect(redrafted).not.toHaveBeenCalled()
		eventBus.off('composer-redraft', redrafted)
	})

	// translating

	/** Offered only where the server has a provider, and only across languages. */
	it('offers no translation when the server cannot translate', async () => {
		const { wrapper } = mountPost({ item: makeItem({ language: 'de' }) })

		expect(wrapper.findAll('.post-menu__item').map((button) => button.text()))
			.not.toContain('Translate')
	})

	it('offers a translation of a post written in another language', async () => {
		const { wrapper } = mountPost({ item: makeItem({ language: 'de' }) })
		useInstanceStore().translation = true
		await wrapper.vm.$nextTick()

		expect(wrapper.findAll('.post-menu__item').map((button) => button.text()))
			.toContain('Translate')
	})

	it('offers none for a post already in the reader\'s language', async () => {
		const { wrapper } = mountPost({ item: makeItem({ language: 'en' }) })
		useInstanceStore().translation = true
		await wrapper.vm.$nextTick()

		expect(wrapper.findAll('.post-menu__item').map((button) => button.text()))
			.not.toContain('Translate')
	})

	/**
	 * The translation is shown in place of the post and says where it came
	 * from: a reader is entitled to know they are reading a machine.
	 */
	it('shows the translation in place of the post, and says so', async () => {
		const { wrapper } = mountPost({
			item: makeItem({ content: '<p>Guten Morgen</p>', language: 'de' }),
		})
		useInstanceStore().translation = true
		await wrapper.vm.$nextTick()
		axios.post.mockResolvedValue({
			data: {
				content: '<p>Good morning</p>',
				spoiler_text: '',
				detected_source_language: 'de',
				provider: 'DeepL',
			},
		})

		await wrapper.findAll('.post-menu__item')
			.find((button) => button.text() === 'Translate')
			.trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/statuses/101/translate')
		expect(wrapper.find('.post-message').text()).toContain('Good morning')
		expect(wrapper.find('.post-translated').text()).toContain('DeepL')
	})

	it('puts the original back', async () => {
		const { wrapper } = mountPost({
			item: makeItem({ content: '<p>Guten Morgen</p>', language: 'de' }),
		})
		useInstanceStore().translation = true
		await wrapper.vm.$nextTick()
		axios.post.mockResolvedValue({
			data: { content: '<p>Good morning</p>', provider: 'DeepL', detected_source_language: 'de' },
		})

		const translate = () => wrapper.findAll('.post-menu__item')
			.find((button) => ['Translate', 'Show original'].includes(button.text()))
		await translate().trigger('click')
		await flushPromises()
		await translate().trigger('click')
		await flushPromises()

		expect(wrapper.find('.post-message').text()).toContain('Guten Morgen')
		expect(wrapper.find('.post-translated').exists()).toBe(false)
		// asked once: the answer is kept, so toggling does not spend a
		// provider call every time
		expect(axios.post).toHaveBeenCalledTimes(1)
	})

	describe('who is in the picture', () => {
		const withPeople = (people, extra = {}) => makeItem({
			nid: 101,
			media_attachments: [photo()],
			tagged_people: people,
			...extra,
		})

		it('names them under the post, linked to their profiles', () => {
			const { wrapper } = mountPost({ item: withPeople([bob]) })

			const named = wrapper.find('.post-tagged')
			expect(named.exists()).toBe(true)
			expect(named.text()).toContain('Bob')
		})

		it('says nothing at all when nobody is named', () => {
			const { wrapper } = mountPost({ item: withPeople([]) })

			expect(wrapper.find('.post-tagged').exists()).toBe(false)
		})

		it('offers the author a way to name people, and nobody else', () => {
			const mine = mountPost({ item: withPeople([]) })
			expect(menuItem(mine.wrapper, 'Tag people')).toBeTruthy()

			const theirs = mountPost({ item: withPeople([], { account: bob }) })
			expect(menuItem(theirs.wrapper, 'Tag people')).toBeUndefined()
		})

		it('does not offer it on a post with no picture', () => {
			const { wrapper } = mountPost({ item: makeItem({ nid: 101, media_attachments: [] }) })

			expect(menuItem(wrapper, 'Tag people')).toBeUndefined()
		})

		it('lets somebody named take their own name off', async () => {
			axios.post.mockResolvedValue({ data: { untagged: true } })
			const me = { ...alice, acct: 'alice' }
			const { wrapper, store } = mountPost({
				item: withPeople([me, bob], { account: bob }),
				currentAccount: alice,
			})
			const updateStatusTagged = vi.spyOn(store, 'updateStatusTagged')

			const leave = wrapper.find('.post-tagged__leave')
			expect(leave.exists()).toBe(true)
			await leave.trigger('click')
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/apps/social/api/v1.1/compose/tag/untagme'),
				{ status_id: 101 },
			)
			expect(updateStatusTagged).toHaveBeenCalledWith({
				statusId: '101',
				taggedPeople: [bob],
			})
		})

		it('offers no way out to somebody who is not named', () => {
			const { wrapper } = mountPost({ item: withPeople([bob], { account: bob }) })

			expect(wrapper.find('.post-tagged__leave').exists()).toBe(false)
		})
	})

	/**
	 * GoToSocial defined `interactionPolicy` and Mastodon 4.5 reads it: an
	 * author can say their post may not be replied to, boosted or liked.
	 * Offering the button anyway means the reader is told their reply went
	 * out — and it did, and the author's server threw it away.
	 */
	describe('what the author allows', () => {
		it('offers everything for a post whose server said nothing', () => {
			const { wrapper } = mountPost({ item: makeItem({ visibility: 'public' }) })

			expect(actionButton(wrapper, 'Reply').exists()).toBe(true)
			expect(actionButton(wrapper, 'Boost').exists()).toBe(true)
			expect(actionButton(wrapper, 'Like').exists()).toBe(true)
		})

		it('leaves out a button the author refused', () => {
			const { wrapper } = mountPost({
				item: makeItem({
					visibility: 'public',
					interaction_policy: { reply: false, boost: false, like: false },
				}),
			})

			expect(actionButton(wrapper, 'Reply').exists()).toBe(false)
			expect(actionButton(wrapper, 'Boost').exists()).toBe(false)
			expect(actionButton(wrapper, 'Like').exists()).toBe(false)
		})

		it('keeps the ones the author allowed', () => {
			const { wrapper } = mountPost({
				item: makeItem({
					visibility: 'public',
					interaction_policy: { reply: true, boost: false },
				}),
			})

			expect(actionButton(wrapper, 'Reply').exists()).toBe(true)
			expect(actionButton(wrapper, 'Boost').exists()).toBe(false)
			expect(actionButton(wrapper, 'Like').exists()).toBe(true)
		})

		/** Undoing is how this instance stops having sent one. */
		it('still lets a like be taken back after the policy changed', () => {
			const { wrapper } = mountPost({
				item: makeItem({
					visibility: 'public',
					favourited: true,
					interaction_policy: { like: false },
				}),
			})

			expect(actionButton(wrapper, 'Undo Like').exists()).toBe(true)
		})
	})

	describe('sound, touch and arrival', () => {
		/** a like is heard and felt where the reader allows it; the undoing is quiet */
		it('answers a like with the like feeling, and an unlike with nothing', async () => {
			feel.mockClear()
			const { wrapper, item } = mountPost()
			eventBus.emit('timeline:focused', item)
			await wrapper.vm.$nextTick()

			eventBus.emit('shortcut:like')
			await flushPromises()
			expect(feel).toHaveBeenCalledWith('like')
			eventBus.all.clear()

			feel.mockClear()
			const liked = mountPost({ item: makeItem({ favourited: true }) })
			eventBus.emit('timeline:focused', liked.item)
			await liked.wrapper.vm.$nextTick()
			eventBus.emit('shortcut:like')
			await flushPromises()
			expect(feel).not.toHaveBeenCalled()
			eventBus.all.clear()
		})

		it('makes an entrance when it is the post the reader just published', async () => {
			const { wrapper, item, store } = mountPost()
			expect(wrapper.classes()).not.toContain('post-content--arrived')

			store.arrivedId = String(item.id)
			await wrapper.vm.$nextTick()

			expect(wrapper.classes()).toContain('post-content--arrived')
		})
	})
})
