/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import VideoHeader from '../../../src/components/VideoHeader.vue'
import { useTimelineStore } from '../../../src/store/timeline.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))

const ACCOUNT = {
	id: '1',
	acct: 'films@peer.tube',
	username: 'films',
	display_name: 'Films',
}

/**
 * @param {object} video what the post said about the video
 * @param {object} overrides the rest of the status
 * @return {object} a status carrying a Video
 */
function status(video = {}, overrides = {}) {
	return {
		id: '1',
		account: ACCOUNT,
		content: '<p>A film about stairwells</p>',
		media_attachments: [],
		video: { title: 'Stairwells', ...video },
		...overrides,
	}
}

/**
 * @param {object} theStatus the post to draw
 * @return {object} the mounted page
 */
function mountHeader(theStatus = status(), serverData = {}) {
	setActivePinia(createPinia())
	useSettingsStore().setServerData(serverData)

	return mount(VideoHeader, {
		props: { status: theStatus },
		global: {
			stubs: {
				RouterLink: RouterLinkStub,
				PostAttachment: true,
				ActorAvatar: true,
				FollowButton: true,
			},
		},
	})
}

const facts = (wrapper) => wrapper.findAll('.watch__facts span').map((fact) => fact.text())
const chapters = (wrapper) => wrapper.findAll('.watch__chapter')

/**
 * A video's own page: the same route a post opens on, showing what a `Video`
 * object carries and a timeline card has no room for.
 */
describe('the video page', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	describe('what it is called', () => {
		it('uses the video own title where there is one', () => {
			expect(mountHeader().find('.watch__title').text()).toBe('Stairwells')
		})

		/**
		 * A video posted from here has no separate title — the composer does
		 * not ask for one — so the first line stands in, which is where
		 * somebody writing about a video puts its name.
		 */
		it('falls back to the first line of the post', () => {
			const wrapper = mountHeader(status({ title: '' }, {
				content: '<p>Stairwells of East Berlin</p><p>Shot over ten years.</p>',
			}))

			expect(wrapper.find('.watch__title').text()).toBe('Stairwells of East Berlin')
		})

		it('skips the empty lines the markup leaves behind', () => {
			const wrapper = mountHeader(status({ title: '' }, {
				content: '<p></p>\n<p>  </p><p>Stairwells</p>',
			}))

			expect(wrapper.find('.watch__title').text()).toBe('Stairwells')
		})

		it('is empty rather than broken for a post with no words in it', () => {
			expect(mountHeader(status({ title: '' }, { content: '' })).find('.watch__title').text())
				.toBe('')
		})

		it('marks a broadcast that is happening now', () => {
			expect(mountHeader(status({ live: true })).find('.watch__live').text()).toBe('Live')
			expect(mountHeader().find('.watch__live').exists()).toBe(false)
		})
	})

	describe('the facts under the title', () => {
		it('shows the counters, the category, the language and the licence', () => {
			const wrapper = mountHeader(status({
				views: 2,
				likes: 1,
				category: 'Art',
				language: 'de',
				licence: 'CC BY-SA',
			}))

			expect(facts(wrapper)).toEqual(['2 views', '1 like', 'Art', 'de', 'CC BY-SA'])
		})

		/** Nothing known about it: a blank line, not a row of zeroes. */
		it('says nothing about what the video did not carry', () => {
			expect(facts(mountHeader())).toEqual([])
		})

		/** A video with no views yet has a count, and it is worth showing. */
		it('shows a count of nothing, which is different from no count', () => {
			expect(facts(mountHeader(status({ views: 0 })))).toEqual(['0 views'])
		})

		/**
		 * This server counts the `Dislike` activities it received; PeerTube
		 * states a number covering everybody who watched it there.
		 */
		it('prefers this server own dislike count over the one PeerTube stated', () => {
			expect(facts(mountHeader(status({ dislikes: 9 }, { dislikes_count: 2 }))))
				.toEqual(['2 dislikes'])
			expect(facts(mountHeader(status({ dislikes: 9 })))).toEqual(['9 dislikes'])
		})

		it('says nothing about dislikes to a reader who hides the numbers', () => {
			expect(facts(mountHeader(status({ dislikes: 9 }, { dislikes_count: 2 }), { hideCounts: true }))).toEqual([])
		})
	})

	/** On PeerTube a video is listed under its channel, not under a person. */
	describe('the channel', () => {
		it('names it and links to it', () => {
			const wrapper = mountHeader()

			expect(wrapper.find('.watch__channel-name').text()).toBe('Films')
			expect(wrapper.find('.watch__channel-acct').text()).toBe('films@peer.tube')
			expect(wrapper.findComponent(RouterLinkStub).props('to'))
				.toEqual({ name: 'profile', params: { account: 'films@peer.tube' } })
		})

		it('falls back to the username when the channel has no name', () => {
			const wrapper = mountHeader(status({}, {
				account: { ...ACCOUNT, display_name: '' },
			}))

			expect(wrapper.find('.watch__channel-name').text()).toBe('films')
		})
	})

	/**
	 * PeerTube's other counter, and only here: a dislike button under a written
	 * post is a product this app is not.
	 */
	describe('disliking', () => {
		it('sends a dislike, and takes it back', async () => {
			const wrapper = mountHeader()
			const store = useTimelineStore()
			store.postDislike = vi.fn().mockResolvedValue(undefined)
			store.postUndislike = vi.fn().mockResolvedValue(undefined)

			wrapper.find('.watch__dislike').trigger('click')
			await flushPromises()
			expect(store.postDislike).toHaveBeenCalledWith({ status: wrapper.props('status') })

			await wrapper.setProps({ status: status({}, { disliked: true }) })
			wrapper.find('.watch__dislike').trigger('click')
			await flushPromises()
			expect(store.postUndislike).toHaveBeenCalled()
		})

		it('says which way it is pressed', () => {
			expect(mountHeader().find('.watch__dislike').attributes('aria-pressed')).toBe('false')

			const pressed = mountHeader(status({}, { disliked: true }))
			expect(pressed.find('.watch__dislike').attributes('aria-pressed')).toBe('true')
			expect(pressed.findComponent({ name: 'NcButton' }).props('ariaLabel')).toBe('Undo dislike')
		})

		it('cannot be pressed twice while it is in flight', async () => {
			const wrapper = mountHeader()
			const store = useTimelineStore()
			store.postDislike = vi.fn().mockReturnValue(new Promise(() => {}))

			wrapper.find('.watch__dislike').trigger('click')
			await wrapper.vm.$nextTick()
			wrapper.find('.watch__dislike').trigger('click')
			await flushPromises()

			expect(store.postDislike).toHaveBeenCalledTimes(1)
			expect(wrapper.findComponent({ name: 'NcButton' }).props('disabled')).toBe(true)
		})
	})

	describe('the chapters', () => {
		const CHAPTERS = [
			{ start: 0, title: 'Before' },
			{ start: 125, title: 'The stairwell' },
			{ start: 3725, title: 'After' },
		]

		it('lists them, each as a moment to press', () => {
			const wrapper = mountHeader(status({ chapters: CHAPTERS }))

			expect(chapters(wrapper).map((chapter) => [
				chapter.find('.watch__chapter-time').text(),
				chapter.find('.watch__chapter-title').text(),
			])).toEqual([
				['0:00', 'Before'],
				['2:05', 'The stairwell'],
				['1:02:05', 'After'],
			])
		})

		it('says nothing at all when the video has none', () => {
			expect(mountHeader().find('.watch__chapters').exists()).toBe(false)
			expect(mountHeader(status({ chapters: 'soon' })).find('.watch__chapters').exists())
				.toBe(false)
		})

		/**
		 * The player is several components below this one, so it is found
		 * rather than passed down: threading a ref through each of them to
		 * seek would tie all of them to this page.
		 */
		it('moves the player to the chapter, and plays it', async () => {
			const wrapper = mountHeader(status({ chapters: CHAPTERS }))
			const player = document.createElement('video')
			player.play = vi.fn().mockResolvedValue(undefined)
			wrapper.element.appendChild(player)

			await chapters(wrapper)[1].trigger('click')

			expect(player.currentTime).toBe(125)
			expect(player.play).toHaveBeenCalled()
		})

		/** A browser that refuses to play unprompted must not throw here. */
		it('does not fail when the browser refuses to play', async () => {
			const wrapper = mountHeader(status({ chapters: CHAPTERS }))
			const player = document.createElement('video')
			player.play = vi.fn().mockRejectedValue(new Error('not allowed'))
			wrapper.element.appendChild(player)

			await chapters(wrapper)[0].trigger('click')
			await flushPromises()

			expect(player.currentTime).toBe(0)
		})

		it('does nothing when the player is not there yet', async () => {
			const wrapper = mountHeader(status({ chapters: CHAPTERS }))

			await expect(chapters(wrapper)[0].trigger('click')).resolves.not.toThrow()
		})
	})

	it('shows the line the author wrote asking for support', () => {
		expect(mountHeader(status({ support: 'Paid for by the people who watch it' }))
			.find('.watch__support').text()).toBe('Paid for by the people who watch it')
		expect(mountHeader().find('.watch__support').exists()).toBe(false)
	})

	/** A request, not a lock: the file is still served, and the page says so. */
	it('passes on a request that the video not be downloaded', () => {
		expect(mountHeader(status({ download: false })).find('.watch__note').text())
			.toContain('not be downloaded')
		expect(mountHeader(status({ download: true })).find('.watch__note').exists()).toBe(false)
		expect(mountHeader().find('.watch__note').exists()).toBe(false)
	})
})
