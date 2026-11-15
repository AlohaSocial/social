/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import AccountHoverCard from '../../../src/components/AccountHoverCard.vue'
import MessageContent, { emojifyPlain, isJumboEmoji } from '../../../src/components/MessageContent.js'
import { createPinia } from 'pinia'

const Empty = { template: '<div />' }

/**
 * The named routes MessageContent links to, with the paths of src/router.js,
 * so the rendered <a href> is what the app would navigate to.
 */
function makeRouter() {
	return createRouter({
		history: createMemoryHistory('/apps/social/'),
		routes: [
			{ path: '/', component: Empty },
			{
				path: '/timeline/:type?',
				name: 'timeline',
				component: Empty,
				children: [{ path: 'tags/:tag', name: 'tags', component: Empty }],
			},
			{ path: '/@:account', name: 'profile', component: Empty },
		],
	})
}

function mountContent(content, extra = {}) {
	return mount(MessageContent, {
		props: { item: { content, mentions: [], ...extra } },
		global: { plugins: [makeRouter(), createPinia()] },
	})
}

describe('MessageContent', () => {
	describe('formatting', () => {
		it('keeps the structural markup Mastodon sends', () => {
			const wrapper = mountContent('<p>Hello <strong>world</strong><br>and <em>more</em></p>'
				+ '<blockquote><p>quoted</p></blockquote><ul><li>one</li></ul><pre><code>run()</code></pre>')

			expect(wrapper.find('p strong').text()).toBe('world')
			expect(wrapper.find('p br').exists()).toBe(true)
			expect(wrapper.find('p em').text()).toBe('more')
			expect(wrapper.find('blockquote p').text()).toBe('quoted')
			expect(wrapper.find('ul li').text()).toBe('one')
			expect(wrapper.find('pre code').text()).toBe('run()')
		})

		it('drops every attribute from structural elements', () => {
			const wrapper = mountContent('<p class="x" style="position:fixed" onclick="alert(1)">text</p>')
			expect(wrapper.find('p').attributes()).toEqual({})
			expect(wrapper.find('p').text()).toBe('text')
		})

		it('reduces unknown elements to their text', () => {
			const wrapper = mountContent('<p><font color="red">red</font> <mark data-x="1">marked</mark></p>')
			expect(wrapper.find('font').exists()).toBe(false)
			expect(wrapper.find('mark').exists()).toBe(false)
			expect(wrapper.html()).not.toContain('data-x')
			expect(wrapper.find('p').text()).toBe('red marked')
		})
	})

	describe('direction', () => {
		// an Arabic or Hebrew post in an English page was set left to right,
		// with its sentence-final punctuation on the wrong side
		it('lets the post set its own direction from its first strong character', () => {
			expect(mountContent('<p>مرحبا بالعالم</p>').attributes('dir')).toBe('auto')
			expect(mountContent('<p>Hello</p>').attributes('dir')).toBe('auto')
		})
	})

	describe('links', () => {
		it('opens ordinary links in a new tab without referrer or opener', () => {
			const wrapper = mountContent('<p>see <a href="https://example.org/x" onclick="alert(1)">https://example.org/x</a></p>')

			const link = wrapper.find('a')
			expect(link.attributes()).toEqual({
				href: 'https://example.org/x',
				rel: 'nofollow noopener noreferrer',
				target: '_blank',
			})
			expect(link.text()).toBe('https://example.org/x')
		})

		it('renders a dead link for script-capable URL schemes', () => {
			const wrapper = mountContent('<p><a href="javascript:alert(1)">click</a> <a href="data:text/html,x">data</a></p>')

			const links = wrapper.findAll('a')
			expect(links).toHaveLength(2)
			for (const link of links) {
				expect(link.attributes('href')).toBeUndefined()
				expect(link.attributes('target')).toBe('_blank')
			}
			expect(wrapper.html()).not.toContain('javascript:')
		})

		it('does not leave plain URLs in text clickable', () => {
			const wrapper = mountContent('<p>go to https://example.org now</p>')
			expect(wrapper.find('a').exists()).toBe(false)
			expect(wrapper.text()).toBe('go to https://example.org now')
		})
	})

	describe('hashtags', () => {
		it('turns a Mastodon hashtag anchor into a link to the tag timeline', () => {
			const wrapper = mountContent('<p>about <a href="https://mastodon.example/tags/nextcloud" class="mention hashtag" rel="tag">#<span>nextcloud</span></a></p>')

			const link = wrapper.find('a')
			expect(link.attributes('href')).toBe('/apps/social/timeline/tags/nextcloud')
			expect(link.attributes('rel')).toBeUndefined()
			expect(link.text()).toBe('#nextcloud')
		})

		it('links a hashtag written in plain text', () => {
			const wrapper = mountContent('<p>Loving the #Fediverse today</p>')

			const link = wrapper.find('a')
			expect(link.attributes('href')).toBe('/apps/social/timeline/tags/Fediverse')
			expect(link.text()).toBe('#Fediverse')
			expect(wrapper.text()).toBe('Loving the #Fediverse today')
		})
	})

	describe('mentions', () => {
		it('links a plain-text handle to the profile, showing only the short name', () => {
			const wrapper = mountContent('<p>cc @carol@other.example thanks</p>')

			const link = wrapper.find('a')
			expect(link.attributes('href')).toBe('/apps/social/@carol@other.example')
			expect(link.text()).toBe('@carol')
			expect(wrapper.text()).toBe('cc @carol thanks')
		})

		it('keeps the remote profile link of a mention listed in the status mentions', () => {
			const wrapper = mountContent(
				'<p><a href="https://remote.example/@bob" class="u-url mention">bob@remote.example</a> hi</p>',
				{ mentions: [{ id: '9', username: 'bob', acct: 'bob@remote.example', url: 'https://remote.example/@bob' }] },
			)

			const link = wrapper.find('a')
			expect(link.attributes('href')).toBe('https://remote.example/@bob')
			expect(link.attributes('rel')).toBe('nofollow noopener noreferrer')
			expect(link.attributes('target')).toBe('_blank')
			expect(link.text()).toBe('bob@remote.example')
		})

		it('refuses an unsafe URL on a listed mention', () => {
			const wrapper = mountContent(
				'<p><a href="javascript:alert(1)" class="mention">bob@remote.example</a> hi</p>',
				{ mentions: [{ id: '9', username: 'bob', acct: 'bob@remote.example', url: 'https://remote.example/@bob' }] },
			)
			expect(wrapper.find('a').attributes('href')).toBeUndefined()
		})

		it('reduces a mention anchor that is not among the status mentions to text', () => {
			const wrapper = mountContent('<p><a href="https://evil.example/@bob" class="mention">@bob</a> hi</p>')
			expect(wrapper.find('a').exists()).toBe(false)
			expect(wrapper.text()).toBe('@bob hi')
		})

		it('matches a short-name mention through the host of its listed url', () => {
			// Mastodon renders the anchor text as @bob while acct is bob@remote.example;
			// the lookup must compare the user part of acct, not its host.
			const wrapper = mountContent(
				'<p><a href="https://remote.example/@bob" class="u-url mention">@bob</a> hi</p>',
				{ mentions: [{ id: '9', username: 'bob', acct: 'bob@remote.example', url: 'https://remote.example/@bob' }] },
			)

			const link = wrapper.find('a')
			expect(link.attributes('href')).toBe('https://remote.example/@bob')
			expect(link.text()).toBe('@bob')
		})

		it('links every handle when a text node carries several', () => {
			// a stateful (global) regex used to skip every second mention
			const wrapper = mountContent('<p>cc @carol@other.example and @dave@other.example and @erin@other.example</p>')

			expect(wrapper.findAll('a').map((a) => a.text())).toEqual(['@carol', '@dave', '@erin'])
		})

		it('survives a mention anchor with a relative href', () => {
			// The server sanitizer keeps scheme-less hrefs; new URL() on one
			// throws, which used to break rendering of the whole post.
			const wrapper = mountContent(
				'<p><a href="/@bob" class="mention">@bob</a> hi</p>',
				{ mentions: [{ id: '9', username: 'bob', acct: 'bob@remote.example', url: 'https://remote.example/@bob' }] },
			)

			expect(wrapper.text()).toBe('@bob hi')
		})
	})

	describe('account preview', () => {
		it('hangs a hover card off a handle written in plain text', () => {
			const wrapper = mountContent('<p>cc @carol@other.example thanks</p>')

			const cards = wrapper.findAllComponents(AccountHoverCard)
			expect(cards).toHaveLength(1)
			expect(cards[0].props('handle')).toBe('carol@other.example')
			// the link inside it is untouched
			expect(cards[0].find('a').attributes('href')).toBe('/apps/social/@carol@other.example')
		})

		it('hangs a hover card off a mention anchor, using the handle the status carries', () => {
			// the anchor says @bob, the status says who that is
			const wrapper = mountContent(
				'<p><a href="https://remote.example/@bob" class="u-url mention">@bob</a> hi</p>',
				{ mentions: [{ id: '9', username: 'bob', acct: 'bob@remote.example', url: 'https://remote.example/@bob' }] },
			)

			const card = wrapper.findComponent(AccountHoverCard)
			expect(card.props('handle')).toBe('bob@remote.example')
			expect(card.find('a').attributes('href')).toBe('https://remote.example/@bob')
		})

		it('leaves hashtags, ordinary links and mentions the status does not list alone', () => {
			const wrapper = mountContent('<p><a href="https://mastodon.example/tags/x" class="mention hashtag">#x</a>'
				+ ' <a href="https://example.org/x">link</a>'
				+ ' <a href="https://evil.example/@bob" class="mention">@bob</a> #plain</p>')

			expect(wrapper.findAllComponents(AccountHoverCard)).toHaveLength(0)
		})

		it('shows nothing and asks for nothing until somebody hovers', () => {
			const wrapper = mountContent('<p>cc @carol@other.example thanks</p>')

			expect(wrapper.findComponent(AccountHoverCard).vm.shown).toBe(false)
			expect(document.querySelector('.account-hover-card')).toBeNull()
			expect(wrapper.text()).toBe('cc @carol thanks')
		})
	})

	describe('emoji', () => {
		it('replaces an emoji sequence by its twemoji image', () => {
			const wrapper = mountContent('<p>Hi 🧑‍🤝‍🧑 there</p>')

			const emoji = wrapper.find('img.emoji')
			expect(emoji.attributes('alt')).toBe('🧑‍🤝‍🧑')
			expect(emoji.attributes('src')).toBe('/apps/social/img/twemoji/1f9d1-200d-1f91d-200d-1f9d1.svg')
			expect(emoji.attributes('draggable')).toBe('false')
			expect(wrapper.text()).toBe('Hi  there')
		})

		it('replaces a plain single-codepoint emoji too', () => {
			const wrapper = mountContent('<p>Hi 😀 there</p>')

			const emoji = wrapper.find('img.emoji')
			expect(emoji.exists()).toBe(true)
			expect(emoji.attributes('alt')).toBe('😀')
			expect(emoji.element.parentElement.tagName).toBe('P')
			expect(emoji.element.nextSibling.textContent).toBe(' there')
			expect(wrapper.find('p br').exists()).toBe(false)
		})
	})

	describe('custom emoji', () => {
		const blobcat = { shortcode: 'blobcat', url: 'https://remote.example/emoji/blobcat.png', static_url: 'https://remote.example/emoji/blobcat.png', visible_in_picker: false }

		it('replaces known shortcodes with inline images', () => {
			const wrapper = mountContent('<p>hi :blobcat: !</p>', { emojis: [blobcat] })

			const img = wrapper.find('img.custom-emoji')
			expect(img.exists()).toBe(true)
			expect(img.attributes('src')).toBe(blobcat.url)
			expect(img.attributes('alt')).toBe(':blobcat:')
			expect(img.attributes('loading')).toBe('lazy')
			expect(img.attributes('decoding')).toBe('async')
			expect(wrapper.text()).toContain('hi')
		})

		it('leaves unknown shortcodes as text', () => {
			const wrapper = mountContent('<p>hi :missing: !</p>', { emojis: [blobcat] })

			expect(wrapper.find('img.custom-emoji').exists()).toBe(false)
			expect(wrapper.text()).toContain(':missing:')
		})

		// the server matches a shortcode case-insensitively, so `:BlobCat:`
		// arrives with the `blobcat` entry in the list of the status
		it('matches a shortcode whatever case it was written in', () => {
			const wrapper = mountContent('<p>hi :BlobCat: !</p>', { emojis: [blobcat] })

			const img = wrapper.find('img.custom-emoji')
			expect(img.exists()).toBe(true)
			expect(img.attributes('src')).toBe(blobcat.url)
			expect(img.attributes('alt')).toBe(':BlobCat:')
		})

		it('matches an entry whose own shortcode carries capitals', () => {
			const shouty = { ...blobcat, shortcode: 'BlobCat' }
			const wrapper = mountContent('<p>hi :blobcat: !</p>', { emojis: [shouty] })

			expect(wrapper.find('img.custom-emoji').attributes('src')).toBe(shouty.url)
		})

		it('never uses the emoji list as HTML', () => {
			const evil = { shortcode: 'x', url: '"><script>alert(1)</script>' }
			const wrapper = mountContent('<p>:x:</p>', { emojis: [evil] })

			expect(wrapper.find('script').exists()).toBe(false)
			expect(wrapper.find('img.custom-emoji').attributes('src')).toBe(evil.url)
		})
	})

	describe('jumbo emoji', () => {
		const blobcat = { shortcode: 'blobcat', url: 'https://remote.example/emoji/blobcat.png' }

		it.each([
			['😀'],
			['👍🏽 🎉'],
			['🇩🇪🇫🇷'],
			['❤️‍🔥'],
			['👨‍👩‍👧‍👦🏳️‍🌈1️⃣'],
			['🏴󠁧󠁢󠁳󠁣󠁴󠁿'],
		])('draws %s large', (text) => {
			expect(isJumboEmoji(text)).toBe(true)
		})

		it.each([
			[''],
			['hi 😀'],
			['😀😀😀😀'],
			['#️'],
			['42'],
		])('draws %s at text size', (text) => {
			expect(isJumboEmoji(text)).toBe(false)
		})

		it('counts the post\'s own custom emoji, and only those', () => {
			expect(isJumboEmoji(':blobcat: 😀', [blobcat])).toBe(true)
			expect(isJumboEmoji(':BlobCat:', [blobcat])).toBe(true)
			expect(isJumboEmoji(':missing:', [blobcat])).toBe(false)
			expect(isJumboEmoji(':blobcat::blobcat::blobcat::blobcat:', [blobcat])).toBe(false)
		})

		it('marks the content of an emoji-only post', () => {
			expect(mountContent('<p>🎉 🎉</p>').classes()).toContain('message-content--jumbo')
			expect(mountContent('<p>:blobcat:</p>', { emojis: [blobcat] }).classes()).toContain('message-content--jumbo')
		})

		it('leaves a post with words, a mention or a link at text size', () => {
			expect(mountContent('<p>yay 🎉</p>').classes()).not.toContain('message-content--jumbo')
			expect(mountContent('<p>🎉 <a href="https://example.org">x</a></p>').classes()).not.toContain('message-content--jumbo')
		})
	})

	describe('emojifyPlain', () => {
		const blobcat = { shortcode: 'blobcat', url: 'https://remote.example/emoji/blobcat.png' }
		const hFn = (tag, attrs) => ({ tag, attrs })

		it('replaces a shortcode in a display name', () => {
			const parts = emojifyPlain(hFn, 'Alice :blobcat:', [blobcat])

			expect(parts.some((part) => part?.attrs?.src === blobcat.url)).toBe(true)
		})

		/** A display name is matched the same way the text of a post is. */
		it('matches a shortcode whatever case it was written in', () => {
			const parts = emojifyPlain(hFn, 'Alice :BlobCat:', [blobcat])

			expect(parts.some((part) => part?.attrs?.src === blobcat.url)).toBe(true)
		})

		it('leaves an unknown shortcode as text', () => {
			const parts = emojifyPlain(hFn, 'Alice :missing:', [blobcat])

			expect(parts.join('')).toContain(':missing:')
		})
	})

	describe('hostile markup', () => {
		it('never renders script or image elements from the content', () => {
			const wrapper = mountContent('<p>a<script>alert(1)</script><img src="x" onerror="alert(1)">b</p>')

			expect(wrapper.find('script').exists()).toBe(false)
			expect(wrapper.find('img').exists()).toBe(false)
			expect(wrapper.html()).not.toContain('onerror')
		})

		it('renders an empty wrapper for an empty status', () => {
			const wrapper = mountContent('')
			expect(wrapper.element.tagName).toBe('DIV')
			expect(wrapper.text()).toBe('')
		})
	})
})
