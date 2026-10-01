/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import StoryViewer from '../../../src/components/StoryViewer.vue'
import { useAccountStore } from '../../../src/store/account.js'

const { post, del, get } = vi.hoisted(() => ({ post: vi.fn(), del: vi.fn(), get: vi.fn() }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { post, delete: del, get } }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess }))
vi.mock('../../../src/services/logger.js', () => ({ default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() } }))

const alice = { id: '7', url: 'https://cloud.example.org/@alice', acct: 'alice', username: 'alice', display_name: 'Alice', avatar: '' }
const bob = { id: '9', url: 'https://remote.example/users/bob', acct: 'bob@remote.example', username: 'bob', display_name: 'Bob', avatar: '' }

function story(id, account, overrides = {}) {
	return { id, account, seen: false, duration: 5, caption: '', view_count: 0, created_at: new Date().toISOString(), media: { type: 'image', url: `https://cloud.example.org/${id}.jpg` }, ...overrides }
}

const NcModalStub = {
	name: 'NcModal',
	props: ['name', 'hasPrevious', 'hasNext', 'size'],
	emits: ['close', 'previous', 'next'],
	template: '<div class="modal-stub"><slot /></div>',
}

function mountViewer(groups, start = 0) {
	const pinia = createPinia()
	setActivePinia(pinia)
	const store = useAccountStore()
	store.addAccount({ actorId: alice.url, data: alice })
	store.setCurrentAccount('alice@cloud.example.org')

	return mount(StoryViewer, {
		props: { groups, start },
		global: { plugins: [pinia], stubs: { NcModal: NcModalStub, ActorAvatar: true, RouterLink: RouterLinkStub } },
	})
}

describe('StoryViewer', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		// the mute choice is kept for the tab, and a test is a new tab
		window.sessionStorage.clear()
		// jsdom implements neither; a video story now calls play() itself
		HTMLMediaElement.prototype.play = vi.fn(() => Promise.resolve())
		HTMLMediaElement.prototype.pause = vi.fn()
		post.mockReset().mockResolvedValue({ data: {} })
		del.mockReset()
		get.mockReset().mockResolvedValue({ data: { reactions: [] } })
		showError.mockReset()
		showSuccess.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('shows the first story of the group it was opened on and marks it seen at once', async () => {
		const groups = [{ account: bob, own: false, seen: false, stories: [story('2', bob), story('3', bob)] }]
		const wrapper = mountViewer(groups)
		await flushPromises()

		expect(wrapper.find('.story-viewer__image').attributes('src')).toBe('https://cloud.example.org/2.jpg')
		expect(post).toHaveBeenCalledWith(expect.stringContaining('/apps/social/api/v1/stories/2/seen'))
		expect(wrapper.emitted('seen')[0]).toEqual(['2'])
		expect(wrapper.findAll('.story-viewer__segment')).toHaveLength(2)
	})

	it('moves on when the story\'s seconds are up, and closes after the last one', async () => {
		const groups = [{ account: bob, own: false, seen: false, stories: [story('2', bob, { duration: 3 }), story('3', bob, { duration: 3 })] }]
		const wrapper = mountViewer(groups)
		await flushPromises()

		vi.advanceTimersByTime(3200)
		await flushPromises()
		expect(wrapper.find('.story-viewer__image').attributes('src')).toBe('https://cloud.example.org/3.jpg')

		vi.advanceTimersByTime(3200)
		await flushPromises()
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('goes forward and back on the two halves of the stage', async () => {
		const groups = [{ account: bob, own: false, seen: false, stories: [story('2', bob), story('3', bob)] }]
		const wrapper = mountViewer(groups)
		await flushPromises()

		await wrapper.find('.story-viewer__tap--forward').trigger('click')
		expect(wrapper.find('.story-viewer__image').attributes('src')).toBe('https://cloud.example.org/3.jpg')
		await wrapper.find('.story-viewer__tap--back').trigger('click')
		expect(wrapper.find('.story-viewer__image').attributes('src')).toBe('https://cloud.example.org/2.jpg')
	})

	it('shows the poster their view count and a delete, and nobody else either', async () => {
		const own = [{ account: alice, own: true, seen: true, stories: [story('1', alice, { view_count: 12, seen: true })] }]
		const mine = mountViewer(own)
		await flushPromises()
		expect(mine.find('.story-viewer__views').text()).toContain('12')
		expect(mine.find('.story-viewer__delete').exists()).toBe(true)
		// own stories are not marked seen: the server would refuse, and the ring is the reader's anyway
		expect(post).not.toHaveBeenCalled()

		const theirs = mountViewer([{ account: bob, own: false, seen: false, stories: [story('2', bob)] }])
		await flushPromises()
		expect(theirs.find('.story-viewer__views').exists()).toBe(false)
		expect(theirs.find('.story-viewer__delete').exists()).toBe(false)
	})

	it('deletes an own story through the API and tells the bar', async () => {
		del.mockResolvedValue({ data: {} })
		const own = [{ account: alice, own: true, seen: true, stories: [story('1', alice, { seen: true })] }]
		const wrapper = mountViewer(own)
		await flushPromises()

		await wrapper.find('.story-viewer__delete').trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith(expect.stringContaining('/apps/social/api/v1/stories/1'))
		expect(wrapper.emitted('deleted')[0][0].id).toBe('1')
		expect(showSuccess).toHaveBeenCalled()
	})

	it('offers a reaction and a reply on somebody else\'s story, and neither on your own', async () => {
		const theirs = mountViewer([{ account: bob, own: false, seen: false, stories: [story('2', bob)] }])
		await flushPromises()
		expect(theirs.findAll('.story-viewer__reaction').length).toBeGreaterThan(0)
		expect(theirs.find('.story-viewer__reply-field').exists()).toBe(true)

		// you do not react to your own story; what you get is what was said
		const mine = mountViewer([{ account: alice, own: true, seen: true, stories: [story('1', alice, { seen: true })] }])
		await flushPromises()
		expect(mine.find('.story-viewer__reaction').exists()).toBe(false)
		expect(mine.find('.story-viewer__reply-field').exists()).toBe(false)
	})

	it('sends a tapped emoji to the story it is on', async () => {
		const wrapper = mountViewer([{ account: bob, own: false, seen: false, stories: [story('2', bob)] }])
		await flushPromises()
		post.mockClear()

		await wrapper.find('.story-viewer__reaction').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			expect.stringContaining('/apps/social/api/v1.2/stories/react'),
			expect.objectContaining({ sid: '2' }),
		)
	})

	it('sends a reply and clears the box', async () => {
		const wrapper = mountViewer([{ account: bob, own: false, seen: false, stories: [story('2', bob)] }])
		await flushPromises()
		post.mockClear()

		const field = wrapper.find('.story-viewer__reply-field')
		await field.setValue('  lovely light  ')
		await wrapper.find('.story-viewer__reply').trigger('submit')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			expect.stringContaining('/apps/social/api/v1.2/stories/comment'),
			{ sid: '2', caption: 'lovely light' },
		)
		expect(field.element.value).toBe('')
	})

	it('shows the poster what was said about their own story, and asks nobody else\'s', async () => {
		get.mockResolvedValue({ data: { reactions: [{ id: '1', type: 'reply', content: 'lovely light', account: bob }] } })

		const mine = mountViewer([{ account: alice, own: true, seen: true, stories: [story('1', alice, { seen: true })] }])
		await flushPromises()
		expect(mine.find('.story-viewer__answers').text()).toContain('lovely light')
		expect(get).toHaveBeenCalledWith(
			expect.stringContaining('/apps/social/api/v1.2/stories/reactions'),
			{ params: { sid: '1' } },
		)

		get.mockClear()
		mountViewer([{ account: bob, own: false, seen: false, stories: [story('2', bob)] }])
		await flushPromises()
		expect(get).not.toHaveBeenCalled()
	})

	/**
	 * A slow request for one story's replies must not put them under the next
	 * one: the poster would be reading answers to a story they have left.
	 */
	it('does not show one story\'s answers under the next', async () => {
		let answerFirst
		get.mockImplementationOnce(() => new Promise((resolve) => {
			answerFirst = () => resolve({
				data: { reactions: [{ id: 'r1', type: 'reply', content: 'about the first', account: bob }] },
			})
		}))

		const wrapper = mountViewer([{
			account: alice,
			own: true,
			seen: true,
			stories: [story('1', alice, { seen: true }), story('2', alice, { seen: true })],
		}])
		await flushPromises()

		// on to the second story, whose own answers arrive first
		get.mockResolvedValueOnce({
			data: { reactions: [{ id: 'r2', type: 'reply', content: 'about the second', account: bob }] },
		})
		wrapper.vm.next()
		await flushPromises()

		expect(wrapper.vm.answers.map((one) => one.content)).toEqual(['about the second'])

		answerFirst()
		await flushPromises()

		expect(wrapper.vm.answers.map((one) => one.content)).toEqual(['about the second'])
	})

	/**
	 * jsdom implements neither `play()` nor `pause()`, so a video element here
	 * plays and pauses only as far as these spies say it does. What is being
	 * asserted is that the component reaches for them at all — which it did not.
	 *
	 * @param {object} wrapper the mounted viewer
	 * @return {object} the two spies and the element
	 */
	function videoControls(wrapper) {
		const element = wrapper.find('.story-viewer__video').element
		const play = vi.fn(() => Promise.resolve())
		const pause = vi.fn(() => {
			Object.defineProperty(element, 'paused', { value: true, configurable: true })
		})
		Object.defineProperty(element, 'play', { value: play, configurable: true })
		Object.defineProperty(element, 'pause', { value: pause, configurable: true })
		Object.defineProperty(element, 'paused', { value: false, configurable: true })
		Object.defineProperty(element, 'ended', { value: false, configurable: true })

		return { play, pause, element }
	}

	const clip = (id, account) => story(id, account, { media: { type: 'video', url: 'https://cloud.example.org/' + id + '.mp4' } })

	// "Holding the stage pauses" is what the component says it does. It held
	// the clock a picture runs on and left a video running underneath.
	it('stops a video story when the stage is held', async () => {
		const wrapper = mountViewer([{ account: bob, own: false, seen: false, stories: [clip('2', bob)] }])
		await flushPromises()
		const { pause } = videoControls(wrapper)

		await wrapper.find('.story-viewer__stage').trigger('pointerdown')

		expect(pause).toHaveBeenCalled()
	})

	it('lets it run again when the stage is let go', async () => {
		const wrapper = mountViewer([{ account: bob, own: false, seen: false, stories: [clip('2', bob)] }])
		await flushPromises()
		const { play, pause } = videoControls(wrapper)

		await wrapper.find('.story-viewer__stage').trigger('pointerdown')
		await wrapper.find('.story-viewer__stage').trigger('pointerup')

		expect(pause).toHaveBeenCalled()
		expect(play).toHaveBeenCalled()
	})

	// The one that loses somebody's words: a short clip reaching `ended` while
	// they are typing takes the story they are answering off the screen.
	it('stops a video story while a reply is being written', async () => {
		const wrapper = mountViewer([{ account: bob, own: false, seen: false, stories: [clip('2', bob)] }])
		await flushPromises()
		const { pause } = videoControls(wrapper)

		await wrapper.find('.story-viewer__reply-field').trigger('focus')

		expect(pause).toHaveBeenCalled()
	})

	// Only what this component stopped: one the reader stopped with the
	// player's own controls stays stopped, and one that has run out is not
	// started again by a blur.
	it('does not restart a video the reader stopped, or one that has run out', async () => {
		const wrapper = mountViewer([{ account: bob, own: false, seen: false, stories: [clip('2', bob)] }])
		await flushPromises()
		const { play, element } = videoControls(wrapper)

		Object.defineProperty(element, 'ended', { value: true, configurable: true })
		Object.defineProperty(element, 'paused', { value: true, configurable: true })
		wrapper.vm.resume()

		expect(play).not.toHaveBeenCalled()
	})

	/** a story is opened by a tap on its ring, which is leave to play with sound */
	it('plays a video story with its sound on, and has a speaker for it', async () => {
		const wrapper = mountViewer([{ account: bob, own: false, seen: false, stories: [clip('2', bob)] }])
		await flushPromises()

		const element = wrapper.find('.story-viewer__video').element
		expect(element.hasAttribute('muted')).toBe(false)
		expect(element.muted).toBe(false)
		expect(element.hasAttribute('autoplay')).toBe(false)
		expect(wrapper.find('.story-viewer__sound').attributes('aria-label')).toBe('Mute')

		await wrapper.find('.story-viewer__sound').trigger('click')
		expect(element.muted).toBe(true)
		expect(wrapper.find('.story-viewer__sound').attributes('aria-label')).toBe('Unmute')
	})

	it('has no speaker on a picture story', async () => {
		const wrapper = mountViewer([{ account: bob, own: false, seen: false, stories: [story('2', bob)] }])
		await flushPromises()

		expect(wrapper.find('.story-viewer__sound').exists()).toBe(false)
	})

	it('falls back to muted with a hint when the browser refuses the sound', async () => {
		const wrapper = mountViewer([{ account: bob, own: false, seen: false, stories: [clip('2', bob)] }])
		await flushPromises()
		const { element } = videoControls(wrapper)
		const play = vi.fn()
			.mockRejectedValueOnce(Object.assign(new Error('no'), { name: 'NotAllowedError' }))
			.mockResolvedValue(undefined)
		Object.defineProperty(element, 'play', { value: play, configurable: true })

		await wrapper.vm.playWithSound(element)
		await flushPromises()

		expect(element.muted).toBe(true)
		expect(wrapper.find('.story-viewer__sound-hint').text()).toBe('Tap for sound')

		await wrapper.find('.story-viewer__sound').trigger('click')
		expect(element.muted).toBe(false)
		expect(wrapper.find('.story-viewer__sound-hint').exists()).toBe(false)
	})

	/** holding the speaker is pressing it, not holding the story still */
	it('does not pause the story when the speaker is pressed', async () => {
		const wrapper = mountViewer([{ account: bob, own: false, seen: false, stories: [clip('2', bob)] }])
		await flushPromises()
		const { pause } = videoControls(wrapper)

		await wrapper.find('.story-viewer__sound').trigger('pointerdown')

		expect(pause).not.toHaveBeenCalled()
		expect(wrapper.vm.paused).toBe(false)
	})

	it('mutes with the m key', async () => {
		const wrapper = mountViewer([{ account: bob, own: false, seen: false, stories: [clip('2', bob)] }])
		await flushPromises()

		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'm' }))
		await flushPromises()

		expect(wrapper.find('.story-viewer__video').element.muted).toBe(true)
		wrapper.unmount()
	})

	/**
	 * The next video story plays in the element the first one was allowed
	 * sound in, and is started by the viewer rather than by `autoplay`.
	 */
	it('keeps one video element from one video story to the next, and plays each', async () => {
		const wrapper = mountViewer([{ account: bob, own: false, seen: false, stories: [clip('2', bob), clip('3', bob)] }])
		await flushPromises()
		const { play, element } = videoControls(wrapper)

		await wrapper.find('.story-viewer__video').trigger('ended')
		await flushPromises()

		const now = wrapper.find('.story-viewer__video')
		expect(now.element).toBe(element)
		expect(now.attributes('src')).toBe('https://cloud.example.org/3.mp4')
		expect(play).toHaveBeenCalled()
	})

	it('stops the hidden video when a picture story comes up', async () => {
		const wrapper = mountViewer([{ account: bob, own: false, seen: false, stories: [clip('2', bob), story('3', bob)] }])
		await flushPromises()
		const { pause } = videoControls(wrapper)

		await wrapper.find('.story-viewer__tap--forward').trigger('click')
		await flushPromises()

		expect(pause).toHaveBeenCalled()
		expect(wrapper.find('.story-viewer__image').exists()).toBe(true)
		expect(wrapper.find('.story-viewer__sound').exists()).toBe(false)
	})

	// The clock a picture runs on is unchanged.
	it('still holds a picture story where it is', async () => {
		const wrapper = mountViewer([{ account: bob, own: false, seen: false, stories: [story('2', bob), story('3', bob)] }])
		await flushPromises()

		await wrapper.find('.story-viewer__stage').trigger('pointerdown')
		vi.advanceTimersByTime(9000)
		await flushPromises()

		expect(wrapper.vm.storyIndex).toBe(0)
	})
})
