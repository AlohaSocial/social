/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { h, resolveComponent } from 'vue'
import AccountHoverCard from './AccountHoverCard.vue'
import Emoji from './Emoji.vue'
import { isAllowedUrl } from '../utils/sanitizeHtml.js'

export default {
	name: 'MessageContent',
	props: {
		item: {
			type: Object,
			required: true,
		},
	},
	render() {
		const routerLink = resolveComponent('router-link')
		return formatMessage(h, routerLink, this.item)
	},
}

// One parser for the app rather than one per post per render: DOMParser holds
// no state between parseFromString() calls.
const parser = new DOMParser()

/**
 * @typedef MessageContext - what a post says about the things its content links to
 * @property {import('../types/Mastodon.js').StatusTag[]} tags - its hashtags
 * @property {import('../types/Mastodon.js').StatusMention[]} mentions - the accounts it mentions
 * @property {import('../types/Mastodon.js').CustomEmoji[]} emojis - the custom emoji it uses
 */

/**
 * @param {typeof import('vue').h} hFn the render function
 * @param {string|import('vue').ConcreteComponent} routerLink the RouterLink component, for the links that stay in the app
 * @param {import('../types/Mastodon.js').Status} item the post whose content is drawn
 */
function formatMessage(hFn, routerLink, item) {
	// `item` is the store's own status object and this runs from a render
	// function, so nothing here writes to it: defaults go on a copy, or a
	// missing `tags` key would be filled in on the store's post during render.
	const context = {
		tags: item.tags ?? [],
		mentions: item.mentions ?? [],
		emojis: item.emojis ?? [],
	}
	const dom = parser.parseFromString(`<div id="rootwrapper">${item.content ?? ''}</div>`, 'text/html')
	const element = dom.getElementById('rootwrapper')
	const cleaned = cleanCopy(hFn, routerLink, element, context)
	return cleaned
}

/**
 * @param {typeof import('vue').h} hFn the render function
 * @param {string|import('vue').ConcreteComponent} routerLink the RouterLink component, for the links that stay in the app
 * @param {Node} node a node of the parsed content: an element or a run of text
 * @param {MessageContext} context what the post says about its own tags, mentions and emoji
 */
function domToVue(hFn, routerLink, node, context) {
	const element = node.nodeType === Node.ELEMENT_NODE ? /** @type {Element} */ (node) : null
	if (element?.tagName === 'A') {
		return cleanLink(hFn, routerLink, element, context)
	}
	if (element && STRUCTURAL_TAGS.includes(element.tagName)) {
		return cleanCopy(hFn, routerLink, element, context)
	}
	// Anything else is reduced to its text, which also drops every attribute
	return transformText(hFn, routerLink, node.textContent ?? '', context)
}

/**
 * Elements copied through as-is (without attributes), matching the server's
 * allowlist in HtmlSanitizer.php so a post from Mastodon keeps its quotes,
 * lists and code blocks.
 */
const STRUCTURAL_TAGS = [
	'P',
	'BR',
	'SPAN',
	'DEL',
	'S',
	'PRE',
	'BLOCKQUOTE',
	'CODE',
	'B',
	'STRONG',
	'U',
	'I',
	'EM',
	'UL',
	'OL',
	'LI',
	'H1',
	'H2',
	'H3',
	'H4',
	'H5',
	'H6',
]

/**
 * A link target the browser may follow, or undefined to render a dead link.
 *
 * The server strips disallowed schemes already; this keeps a `javascript:`
 * href out of the DOM even if content reached the client another way.
 *
 * @param {Element} node - The anchor element
 * @return {string|undefined}
 */
function safeHref(node) {
	const href = node.getAttribute('href') ?? ''
	return isAllowedUrl(href) ? href : undefined
}

// No `g` flag: transformTextRegex() calls exec() repeatedly on shrinking
// strings, and a sticky lastIndex would skip every other match.
const mentionRegex = /(\W|^)((@\w+)@[\w.\-_]+)/i
const hashTagRegex = /(\W|^)(#\w+)/i

const customEmojiRegex = /:([a-zA-Z0-9_]+):/

/**
 * Render a plain-text string (a display name) with its custom emoji replaced
 * by inline images. Everything goes through createElement — never innerHTML.
 *
 * @param {typeof import('vue').h} hFn the render function
 * @param {string} text the plain text
 * @param {import('../types/Mastodon.js').CustomEmoji[]} emojis the CustomEmoji entries of the entity
 */
export function emojifyPlain(hFn, text, emojis) {
	return transformTextRegex(text ?? '', [
		{
			regex: customEmojiRegex,
			onMatch: (match) => {
				// a shortcode is matched whatever case it was written in, the
				// way the server matches one
				const shortcode = match[1].toLowerCase()
				const emoji = (emojis ?? []).find((entry) => (entry.shortcode ?? '').toLowerCase() === shortcode)
				if (!emoji) {
					return match[0]
				}
				return hFn('img', {
					class: 'custom-emoji',
					src: emoji.url,
					alt: match[0],
					title: match[0],
					draggable: 'false',
				})
			},
		},
	])
}

/**
 * Hang an account preview off a mention.
 *
 * The link itself is untouched — it keeps its href, its target and its place
 * in the run of text — and the card is a sibling of the text flow that only
 * exists once it is opened.
 *
 * @param {typeof import('vue').h} hFn - the render function
 * @param {string} handle - the account handle, without the leading @
 * @param {object} link - the rendered mention link
 * @return {object} the link, wrapped
 */
function withHoverCard(hFn, handle, link) {
	return hFn(AccountHoverCard, { handle }, { default: () => [link] })
}

/**
 * @param {typeof import('vue').h} hFn the render function
 * @param {string|import('vue').ConcreteComponent} routerLink the RouterLink component, for the links that stay in the app
 * @param {string} text the plain text
 * @param {MessageContext} [context] what the post says about its own tags, mentions and emoji
 * @return {import('vue').VNodeArrayChildren}
 */
function transformText(hFn, routerLink, text, context = { tags: [], mentions: [], emojis: [] }) {
	return transformTextRegex(text, [
		{
			regex: customEmojiRegex,
			onMatch: (match) => {
				// see emojifyPlain(): the shortcode is matched case-insensitively
				const shortcode = match[1].toLowerCase()
				const emoji = (context.emojis ?? []).find((entry) => (entry.shortcode ?? '').toLowerCase() === shortcode)
				if (!emoji) {
					return match[0]
				}
				return hFn('img', {
					class: 'custom-emoji',
					src: emoji.url,
					alt: match[0],
					title: match[0],
					draggable: 'false',
				})
			},
		},
		{
			regex: mentionRegex,
			onMatch: (match) => [
				match[1],
				withHoverCard(hFn, match[2].slice(1), hFn(routerLink, {
					to: {
						name: 'profile',
						params: { account: match[2].slice(1) },
					},
				}, [match[3]])),
			],
		},
		{
			regex: hashTagRegex,
			onMatch: (match) => [
				match[1],
				hFn(routerLink, {
					to: {
						name: 'tags',
						params: { tag: match[2].slice(1) },
					},
				}, [match[2]]),
			],
		},
		{
			regex: emojiRe,
			onMatch: (match) => hFn(
				Emoji,
				{
					emoji: match[0],
				},
			),
		},
	])
}

/**
 * @param {typeof import('vue').h} hFn the render function
 * @param {string|import('vue').ConcreteComponent} routerLink the RouterLink component, for the links that stay in the app
 * @param {Element} node an element of the parsed content
 * @param {MessageContext} context what the post says about its own tags, mentions and emoji
 */
function cleanCopy(hFn, routerLink, node, context) {
	const children = Array.from(node.childNodes).map((node) => domToVue(hFn, routerLink, node, context))
	return hFn(node.tagName, children)
}

/**
 * @param {typeof import('vue').h} hFn the render function
 * @param {string|import('vue').ConcreteComponent} routerLink the RouterLink component, for the links that stay in the app
 * @param {Element} node an element of the parsed content
 * @param {MessageContext} context what the post says about its own tags, mentions and emoji
 */
function cleanLink(hFn, routerLink, node, context) {
	const type = getLinkType(node.className)
	const attributes = {}
	const tag = matchMention(context.mentions, node.getAttribute('href') ?? '', node.textContent ?? '')

	switch (type) {
		case 'mention':
			if (tag) {
				attributes.rel = 'nofollow noopener noreferrer'
				attributes.target = '_blank'
				attributes.href = safeHref(node)

				return withHoverCard(hFn, tag.acct, hFn('a', attributes, [transformText(hFn, routerLink, node.textContent, context)]))
			} else {
				return transformText(hFn, routerLink, node.textContent, context)
			}
		case 'hashtag':
			return hFn(
				routerLink,
				{
					to: {
						name: 'tags',
						params: { tag: node.textContent?.slice(1) },
					},
				},
				[node.textContent],
			)
		default:
			attributes.rel = 'nofollow noopener noreferrer'
			attributes.target = '_blank'
			attributes.href = safeHref(node)

			return hFn('a', attributes, [transformText(hFn, routerLink, node.textContent)])
	}
}

/**
 * @param {string} className a link's class attribute
 */
function getLinkType(className) {
	const parts = className.split(' ')
	if (parts.includes('hashtag')) {
		return 'hashtag'
	}
	if (parts.includes('mention')) {
		return 'mention'
	}
	return ''
}

/**
 * @param {import('../types/Mastodon.js').StatusMention[]} tags the post's mentions
 * @param {string} mentionHref where the mention link points
 * @param {string} mentionText what the mention link says
 */
function matchMention(tags = [], mentionHref, mentionText) {
	const mentionHost = hostOf(mentionHref)
	for (const tag of tags) {
		if (mentionText === tag.acct) {
			return tag
		}

		if (mentionHost !== null && hostOf(tag.url) === mentionHost) {
			// acct is user@host for remote accounts; compare the user part
			const [name] = tag.acct.split('@')
			if (name === mentionText || '@' + name === mentionText) {
				return tag
			}
		}
	}
	return null
}

/**
 * The host of a URL, or null when it cannot be parsed. Sanitized remote
 * content may carry relative or scheme-less hrefs, and a `new URL()` throw
 * here would break rendering of the whole timeline entry.
 *
 * @param {string} url - The href to parse
 * @return {string|null}
 */
function hostOf(url) {
	try {
		return new URL(url).host
	} catch {
		return null
	}
}

// Multi-codepoint sequences (families, professions, skin tones). Truncated
// upstream, so single-codepoint emoji are matched by the Unicode property
// fallback below instead.

const emojiSequenceRe = /(?:\ud83d\udc68\ud83c\udffb\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffc-\udfff]|\ud83d\udc68\ud83c\udffc\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffb\udffd-\udfff]|\ud83d\udc68\ud83c\udffd\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffb\udffc\udffe\udfff]|\ud83d\udc68\ud83c\udffe\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffb-\udffd\udfff]|\ud83d\udc68\ud83c\udfff\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffb-\udffe]|\ud83d\udc69\ud83c\udffb\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffc-\udfff]|\ud83d\udc69\ud83c\udffb\u200d\ud83e\udd1d\u200d\ud83d\udc69\ud83c[\udffc-\udfff]|\ud83d\udc69\ud83c\udffc\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffb\udffd-\udfff]|\ud83d\udc69\ud83c\udffc\u200d\ud83e\udd1d\u200d\ud83d\udc69\ud83c[\udffb\udffd-\udfff]|\ud83d\udc69\ud83c\udffd\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffb\udffc\udffe\udfff]|\ud83d\udc69\ud83c\udffd\u200d\ud83e\udd1d\u200d\ud83d\udc69\ud83c[\udffb\udffc\udffe\udfff]|\ud83d\udc69\ud83c\udffe\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffb-\udffd\udfff]|\ud83d\udc69\ud83c\udffe\u200d\ud83e\udd1d\u200d\ud83d\udc69\ud83c[\udffb-\udffd\udfff]|\ud83d\udc69\ud83c\udfff\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffb-\udffe]|\ud83d\udc69\ud83c\udfff\u200d\ud83e\udd1d\u200d\ud83d\udc69\ud83c[\udffb-\udffe]|\ud83e\uddd1\ud83c\udffb\u200d\ud83e\udd1d\u200d\ud83e\uddd1\ud83c[\udffb-\udfff]|\ud83e\uddd1\ud83c\udffc\u200d\ud83e\udd1d\u200d\ud83e\uddd1\ud83c[\udffb-\udfff]|\ud83e\uddd1\ud83c\udffd\u200d\ud83e\udd1d\u200d\ud83e\uddd1\ud83c[\udffb-\udfff]|\ud83e\uddd1\ud83c\udffe\u200d\ud83e\udd1d\u200d\ud83e\uddd1\ud83c[\udffb-\udfff]|\ud83e\uddd1\ud83c\udfff\u200d\ud83e\udd1d\u200d\ud83e\uddd1\ud83c[\udffb-\udfff]|\ud83e\uddd1\u200d\ud83e\udd1d\u200d\ud83e\uddd1|\ud83d\udc6b\ud83c[\udffb-\udfff]|\ud83d\udc6c\ud83c[\udffb-\udfff]|\ud83d\udc6d\ud83c[\udffb-\udfff]|\ud83d[\udc6b-\udc6d])|(?:\ud83d[\udc68\udc69]|\ud83e\uddd1)(?:\ud83c[\udffb-\udfff])?\u200d(?:\u2695\ufe0f|\u2696\ufe0f|\u2708\ufe0f|\ud83c[\udf3e\udf73\udf7c\udf84\udf93\udfa4\udfa8\udfbb\udfe4]|\ud83d[\udc66-\udc69\udc6e\udc71\udc73\udc77\udc81\udc82\udc86\udc87\udcde\udd25\uddde\udde0\udde2\udde3\udde4\udde5\udde6\uddf3\udfeb\udfed]|\ud83e[\udd0f\udd1a\udd1c\udd20-\udd2d\udd35-\udd39\udd3b-\udd3e\udd40-\udd45\udd47-\udd4b\udd4c\udd4e\udd50-\udd58\udd5a-\udd62\udd64-\udd67\udd69-\udd6c\udd6f-\udd70\udd73-\udd76\udd78-\udd79\udd7c\udd7d\udd80-\udd86\udd88\udd8b-\udd8d\udd8f-\udd93\udd95\udd96\udd98\udda1\udda2\udda5\udda6\udda9\uddab\uddac\uddb0-\uddb2\uddb5\uddb8\uddb9\uddbc\uddbd\uddbf\uddce\uddc0-\uddc5\uddc7\uddcd\uddd0\uddd2-\uddd5\udde3\udde4\udde6\udde8\uddea\uddec-\uddef\uddf3\uddfa\uddfc\uddfe])/

// A plain \u{1F600}-style emoji is a single codepoint the sequence list above never
// matches; \p{Emoji_Presentation} catches those (and FE0F-selected symbols).
const emojiRe = new RegExp(emojiSequenceRe.source + '|\\p{Emoji_Presentation}|\\p{Extended_Pictographic}\uFE0F', 'u')

/**
 * @param {string} text the plain text
 * @param {Array<{regex: RegExp, onMatch: (match: RegExpExecArray) => import('vue').VNodeChild}>} handlers what to do with each kind of match
 * @return {import('vue').VNodeArrayChildren} the text, split around what matched
 */
function transformTextRegex(text, handlers) {
	const parts = []

	while (text.length > 0) {
		const result = handlers.reduce((bestMatch, handler) => {
			let match
			if ((match = handler.regex.exec(text))) {
				if (bestMatch.index === -1 || match.index < bestMatch.index) {
					return {
						index: match.index,
						match,
						onMatch: handler.onMatch,
					}
				}
			}
			return bestMatch
		}, /** @type {{index: number, match?: RegExpExecArray, onMatch?: (match: RegExpExecArray) => import('vue').VNodeChild}} */ ({ index: -1 }))

		if (result.index !== -1) {
			if (result.index > 0) {
				parts.push(text.slice(0, result.index))
			}

			parts.push(result.onMatch(result.match))
			text = text.slice(result.index + result.match[0].length)
		} else {
			parts.push(text)
			return parts
		}
	}

	return parts
}
