/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createPinia } from 'pinia'
import axios from '@nextcloud/axios'
import ProfileStatusCard from '../../../src/components/ProfileStatusCard.vue'
import { useTimelineStore } from '../../../src/store/timeline.js'

const status = {
	id: '42',
	url: 'https://social.example/@alice/42',
	replies_count: 2,
	favourites_count: 3,
	account: { acct: 'alice', display_name: 'Alice' },
	content: '<p>Post</p>',
}

const stubs = {
	TimelineEntry: { props: ['item', 'type', 'postHref', 'embeddedActions'], template: '<article class="timeline-entry-stub"><slot name="profileActions" /></article>' },
	PostReactedBy: { template: '<div class="reacted-by-stub" />' },
	MessageContent: { props: ['item'], template: '<div class="message-content-stub" :data-content="item.content" />' },
	NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
}

describe('ProfileStatusCard', () => {
	afterEach(() => vi.restoreAllMocks())

	it('provides a real post link, counts, and lazy like details', async () => {
		const wrapper = mount(ProfileStatusCard, { props: { status }, global: { stubs } })
		expect(wrapper.find('.timeline-entry-stub').exists()).toBe(true)
		expect(wrapper.find('.timeline-entry-stub').text()).toContain('Likes (3)')
		expect(wrapper.find('.profile-status-card__open').attributes('href')).toBe(status.url)
		expect(wrapper.text()).toContain('Likes (3)')
		expect(wrapper.text()).toContain('Comments (2)')
		await wrapper.findAll('.profile-status-card__toolbar button')[0].trigger('click')
		expect(wrapper.find('.reacted-by-stub').exists()).toBe(true)
	})

	it('reflects a native reaction update from the shared timeline store', async () => {
		const pinia = createPinia()
		const wrapper = mount(ProfileStatusCard, { props: { status }, global: { stubs, plugins: [pinia] } })
		const timeline = useTimelineStore(pinia)
		timeline.addToStatuses({ ...status, favourites_count: 4, favourited: true })
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain('Likes (4)')
	})

	it('fetches the post renderer only once there is a post to draw', () => {
		expect(ProfileStatusCard.components.TimelineEntry.__asyncLoader).toBeTypeOf('function')
	})

	it('loads only direct replies when the comments action is opened', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { descendants: [
			{ id: '43', in_reply_to_id: '42', content: '<p>Direct reply</p>' },
			{ id: '44', in_reply_to_id: '43', content: '<p>Nested reply</p>' },
		] } })
		const wrapper = mount(ProfileStatusCard, { props: { status }, global: { stubs } })
		await wrapper.findAll('.profile-status-card__toolbar button')[1].trigger('click')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/statuses/42/context')
		expect(wrapper.findAll('.profile-status-card__comment')).toHaveLength(1)
		expect(wrapper.find('.message-content-stub').attributes('data-content')).toContain('Direct reply')
	})

	it('deletes a local own post from the profile card', async () => {
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		const wrapper = mount(ProfileStatusCard, { props: { status, canDelete: true }, global: { stubs } })
		await wrapper.findAll('.profile-status-card__toolbar button')[3].trigger('click')
		await flushPromises()
		expect(del).toHaveBeenCalledWith('/index.php/apps/social/api/v1/statuses/42')
		expect(wrapper.emitted('deleted')).toHaveLength(1)
	})

	it('deletes a native Bluesky post through the ATProto endpoint', async () => {
		const nativeStatus = { ...status, id: 'https://cloud.example/apps/social/ap/bluesky/did:plc:alice/app.bsky.feed.post/3native' }
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		const wrapper = mount(ProfileStatusCard, { props: { status: nativeStatus, canDelete: true, nativeDelete: true }, global: { stubs } })
		await wrapper.findAll('.profile-status-card__toolbar button')[3].trigger('click')
		await flushPromises()
		expect(del).toHaveBeenCalledWith('/index.php/apps/social/api/v1/atproto/post', { params: { id: nativeStatus.id } })
		expect(wrapper.emitted('deleted')).toHaveLength(1)
	})

	it('edits a native Bluesky post through the ATProto endpoint', async () => {
		const nativeStatus = { ...status, id: 'https://cloud.example/apps/social/ap/bluesky/did:plc:alice/app.bsky.feed.post/3native' }
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { updated: true } })
		const wrapper = mount(ProfileStatusCard, { props: { status: nativeStatus, canEdit: true, nativeEdit: true }, global: { stubs } })
		await wrapper.findAll('.profile-status-card__toolbar button')[3].trigger('click')
		await wrapper.find('textarea').setValue('Edited post')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(put).toHaveBeenCalledWith('/index.php/apps/social/api/v1/atproto/post', { id: nativeStatus.id, text: 'Edited post' })
		expect(wrapper.emitted('updated')?.[0]?.[0]?.text).toBe('Edited post')
	})
})
