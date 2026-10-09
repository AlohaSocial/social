/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import PostCard from '../../../src/components/PostCard.vue'

function card(overrides = {}) {
	return {
		url: 'https://example.org/news/today',
		title: 'The headline',
		description: 'What it is about',
		type: 'link',
		provider_name: 'Example News',
		image: 'https://example.org/img/hero.png',
		...overrides,
	}
}

const mountCard = (value) => mount(PostCard, { props: { card: value } })

describe('PostCard', () => {
	it('links to the page and shows what it says about itself', () => {
		const wrapper = mountCard(card())
		const link = wrapper.find('a')

		expect(link.attributes('href')).toBe('https://example.org/news/today')
		expect(link.attributes('target')).toBe('_blank')
		expect(link.attributes('rel')).toBe('nofollow noopener noreferrer')
		expect(wrapper.find('.post-card__title').text()).toBe('The headline')
		expect(wrapper.find('.post-card__description').text()).toBe('What it is about')
		expect(wrapper.find('.post-card__provider').text()).toBe('Example News')
		expect(wrapper.find('img').attributes('src')).toBe('https://example.org/img/hero.png')
		expect(wrapper.find('img').attributes('loading')).toBe('lazy')
	})

	it('falls back to the host when the page names no provider', () => {
		expect(mountCard(card({ provider_name: '' })).find('.post-card__provider').text()).toBe('example.org')
	})

	it('renders a card without an image or a description', () => {
		const wrapper = mountCard(card({ image: null, description: '' }))

		expect(wrapper.find('img').exists()).toBe(false)
		expect(wrapper.find('.post-card__description').exists()).toBe(false)
		expect(wrapper.find('.post-card__title').text()).toBe('The headline')
	})

	it('drops an image that cannot be loaded instead of showing a broken one', async () => {
		const wrapper = mountCard(card())

		await wrapper.find('img').trigger('error')

		expect(wrapper.find('img').exists()).toBe(false)
		expect(wrapper.find('.post-card__title').text()).toBe('The headline')
	})

	it('renders nothing without a card or without a title', () => {
		expect(mountCard(null).find('a').exists()).toBe(false)
		expect(mountCard(card({ title: '' })).find('a').exists()).toBe(false)
	})

	it('escapes what the linked page said rather than rendering it', () => {
		const wrapper = mountCard(card({ title: '<img src=x onerror=alert(1)>', description: '<b>bold</b>' }))

		expect(wrapper.find('.post-card__title').text()).toBe('<img src=x onerror=alert(1)>')
		expect(wrapper.find('.post-card__title').element.querySelector('img')).toBeNull()
		expect(wrapper.find('.post-card__description').element.querySelector('b')).toBeNull()
	})

	it('opens a Bluesky starter pack inside the app, where there is a router', async () => {
		const push = vi.fn()
		const $router = { push, resolve: (route) => ({ href: `/apps/social/starter-pack/${route.params.actor}/${route.params.rkey}` }) }
		const pack = card({ url: 'https://bsky.app/starter-pack/bob.test/3ks', provider_name: 'Bluesky starter pack by @bob.test' })
		const wrapper = mount(PostCard, { props: { card: pack }, global: { mocks: { $router } } })
		const link = wrapper.find('a')

		expect(link.attributes('href')).toBe('/apps/social/starter-pack/bob.test/3ks')
		expect(link.attributes('target')).toBeUndefined()
		await link.trigger('click', { button: 0 })
		expect(push).toHaveBeenCalledWith({ name: 'starter-pack', params: { actor: 'bob.test', rkey: '3ks' } })

		expect(mountCard(pack).find('a').attributes('href')).toBe('https://bsky.app/starter-pack/bob.test/3ks', 'the dashboard has no router: bsky.app')
	})
})
