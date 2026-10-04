/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import MediaAttachment from '../../../src/components/MediaAttachment.vue'

const attachment = {
	id: '7',
	type: 'image',
	url: 'https://cloud.example.org/media/original.jpg',
	preview_url: 'https://cloud.example.org/media/small.jpg',
	description: 'A cat on a sofa',
	blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
	meta: { small: { width: 4, height: 3 } },
}

describe('MediaAttachment', () => {
	let context
	let getContext

	beforeEach(() => {
		// jsdom has no 2D canvas: hand out a stub context and record what is drawn
		context = {
			createImageData: vi.fn((width, height) => ({ data: new Uint8ClampedArray(width * height * 4) })),
			putImageData: vi.fn(),
		}
		getContext = vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(context)
	})

	afterEach(() => {
		getContext.mockRestore()
	})

	// video: a federated one is streamed through this instance, and its still
	// is the only reason a page of them can be scrolled at all

	/**
	 * A PeerTube video's preview is a thumbnail mirrored here, so it is drawn
	 * before anything is played -- and with a frame already on screen there is
	 * nothing to fetch until somebody asks to watch.
	 */
	it('shows a federated video its own still and fetches nothing until asked', () => {
		const wrapper = mount(MediaAttachment, {
			props: {
				attachment: {
					id: '9',
					type: 'video',
					url: 'https://cloud.example.org/media/stream/9',
					preview_url: 'https://cloud.example.org/media/thumb.jpg',
					description: 'A talk about federation',
				},
			},
		})

		const video = wrapper.find('video')
		expect(video.attributes('src')).toBe('https://cloud.example.org/media/stream/9')
		expect(video.attributes('poster')).toBe('https://cloud.example.org/media/thumb.jpg')
		expect(video.attributes('preload')).toBe('none')
	})

	/**
	 * An upload here has `preview_url` pointing at the file itself, which is no
	 * use as a poster: a browser handed a video for one downloads it to find a
	 * frame, which is the whole thing a poster avoids.
	 */
	it('plays a video where it sits rather than taking over the screen', () => {
		// without `playsinline` iOS opens its own full-screen player the
		// moment a video starts, and the reader is thrown out of the timeline
		const wrapper = mount(MediaAttachment, {
			props: { attachment: { id: '9', type: 'video', url: 'https://cloud.example.org/media/9.mp4', description: '' } },
		})

		expect(wrapper.find('video').attributes('playsinline')).toBeDefined()
	})

	it('gives a local video no poster, since its preview is the video', () => {
		const wrapper = mount(MediaAttachment, {
			props: {
				attachment: {
					id: '9',
					type: 'video',
					url: 'https://cloud.example.org/media/abc.mp4',
					preview_url: 'https://cloud.example.org/media/abc.mp4',
					description: '',
				},
			},
		})

		const video = wrapper.find('video')
		expect(video.attributes('poster')).toBeUndefined()
		expect(video.attributes('preload')).toBe('metadata')
	})

	it('shows a file as a named download card rather than a broken picture', () => {
		const wrapper = mount(MediaAttachment, {
			props: { attachment: { id: '9', type: 'unknown', url: 'https://cloud.example.org/media/abc.pdf', preview_url: null, description: 'minutes.pdf' }, interactive: true },
		})

		const card = wrapper.find('a.attachment__file')
		expect(card.exists()).toBe(true)
		expect(card.attributes('href')).toBe('https://cloud.example.org/media/abc.pdf')
		expect(card.attributes('download')).toBeDefined()
		expect(card.find('.attachment__file-name').text()).toBe('minutes.pdf')
		expect(card.find('.attachment__file-kind').text()).toBe('PDF')
		// nothing to load, so nothing spins and no placeholder says it failed
		expect(wrapper.find('.attachment__failed').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(false)
	})

	it('names an undescribed file after the last part of its address', () => {
		const wrapper = mount(MediaAttachment, {
			props: { attachment: { id: '9', type: 'unknown', url: 'https://cloud.example.org/media/Q3%20report.docx?x=1', preview_url: null, description: null } },
		})

		expect(wrapper.find('.attachment__file-name').text()).toBe('Q3 report.docx')
		expect(wrapper.find('.attachment__file-kind').text()).toBe('DOCX')
	})

	it('renders the small preview image', () => {
		const wrapper = mount(MediaAttachment, { props: { attachment } })
		expect(wrapper.find('img.attachment__preview').attributes('src')).toBe(attachment.preview_url)
	})

	it('fetches the preview as it comes into view and decodes it off the main thread', () => {
		// a timeline is a long column of these; the blurhash holds the
		// frame until each one arrives
		const wrapper = mount(MediaAttachment, { props: { attachment } })
		const image = wrapper.find('img.attachment__preview')

		expect(image.attributes('loading')).toBe('lazy')
		expect(image.attributes('decoding')).toBe('async')
	})

	it('paints the blurhash placeholder at the small preview dimensions', () => {
		mount(MediaAttachment, { props: { attachment } })

		expect(getContext).toHaveBeenCalledWith('2d')
		expect(context.createImageData).toHaveBeenCalledWith(4, 3)
		const imageData = context.createImageData.mock.results[0].value
		expect(imageData.data).toHaveLength(4 * 3 * 4)
		expect(imageData.data.some((channel) => channel !== 0)).toBe(true)
		expect(context.putImageData).toHaveBeenCalledWith(imageData, 0, 0)
	})

	it('crossfades the image out of its own blurhash once it has loaded', async () => {
		const wrapper = mount(MediaAttachment, { props: { attachment } })
		expect(wrapper.find('canvas.attachment__blurhash').classes())
			.not.toContain('attachment__blurhash--hidden')
		expect(wrapper.find('img.attachment__preview').classes()).not.toContain('attachment__preview--shown')
		expect(wrapper.find('.loading-icon').exists()).toBe(true)

		await wrapper.find('img').trigger('load')

		// the blurhash stays mounted and fades under the image; removing it
		// in the same frame is what made the swap visible
		expect(wrapper.find('canvas.attachment__blurhash').classes()).toContain('attachment__blurhash--hidden')
		expect(wrapper.find('img.attachment__preview').classes()).toContain('attachment__preview--shown')
		expect(wrapper.find('.loading-icon').exists()).toBe(false)
	})

	it('shows only a spinner while the attachment is still uploading', () => {
		const wrapper = mount(MediaAttachment, { props: { attachment: null } })
		expect(wrapper.find('img').exists()).toBe(false)
		expect(wrapper.find('.loading-icon').exists()).toBe(true)
		expect(getContext).not.toHaveBeenCalled()
	})

	it('skips the blurhash when the preview size is unknown', () => {
		mount(MediaAttachment, { props: { attachment: { ...attachment, meta: { small: {} } } } })
		expect(getContext).not.toHaveBeenCalled()
		expect(context.putImageData).not.toHaveBeenCalled()
	})

	it('repaints when the attachment arrives after an upload', async () => {
		const wrapper = mount(MediaAttachment, { props: { attachment: null } })
		expect(context.createImageData).not.toHaveBeenCalled()

		await wrapper.setProps({ attachment: { ...attachment, meta: { small: { width: 2, height: 5 } } } })

		expect(context.createImageData).toHaveBeenCalledWith(2, 5)
		expect(wrapper.find('img').attributes('src')).toBe(attachment.preview_url)
	})

	it('stops spinning and says so when the preview cannot be loaded', async () => {
		const wrapper = mount(MediaAttachment, { props: { attachment: { ...attachment, description: '' } } })
		expect(wrapper.find('.loading-icon').exists()).toBe(true)

		// no @error meant previewLoaded stayed false forever, so federated
		// media that has gone away spun for the life of the page
		await wrapper.find('img').trigger('error')

		expect(wrapper.find('.loading-icon').exists()).toBe(false)
		expect(wrapper.find('img').exists()).toBe(false)
		expect(wrapper.find('.attachment__failed').attributes('aria-label'))
			.toBe('Attachment could not be loaded')
	})

	it('does not spin for an attachment the server has no preview for', async () => {
		// Vue drops a null `src`, so neither @load nor @error is guaranteed to
		// fire — on Firefox neither does — and the spinner stayed up for good
		const wrapper = mount(MediaAttachment, { props: { attachment: { ...attachment, preview_url: null, description: '' } } })

		expect(wrapper.find('.loading-icon').exists()).toBe(false)
		expect(wrapper.find('img').exists()).toBe(false)
		expect(wrapper.find('.attachment__failed').attributes('aria-label')).toBe('No preview available')
	})

	it('explains when the server refused to cache an image', () => {
		const wrapper = mount(MediaAttachment, {
			props: { attachment: { ...attachment, preview_url: null, cache_error: 1 } },
		})

		expect(wrapper.find('.attachment__failed').attributes('aria-label'))
			.toBe('The image exceeds this instance’s media size limit. A cat on a sofa')
		expect(wrapper.find('.attachment__failed').attributes('title'))
			.toBe('The image exceeds this instance’s media size limit. A cat on a sofa')
	})

	it('names an undescribed-preview attachment by its description', () => {
		const wrapper = mount(MediaAttachment, { props: { attachment: { ...attachment, preview_url: '', description: 'a cat' } } })

		expect(wrapper.find('.attachment__failed').attributes('aria-label')).toBe('No preview available: a cat')
	})

	it('names the attachment in the failure when the author described it', async () => {
		const wrapper = mount(MediaAttachment, { props: { attachment: { ...attachment, description: 'a cat' } } })
		await wrapper.find('img').trigger('error')

		expect(wrapper.find('.attachment__failed').attributes('aria-label'))
			.toBe('Attachment could not be loaded: a cat')
	})

	it('starts over when a new attachment replaces one that failed', async () => {
		const wrapper = mount(MediaAttachment, { props: { attachment } })
		await wrapper.find('img').trigger('error')
		expect(wrapper.find('img').exists()).toBe(false)

		await wrapper.setProps({ attachment: { ...attachment, id: 'other' } })
		expect(wrapper.find('img').exists()).toBe(true)
	})

	it('draws nothing rather than throwing for an attachment with no blurhash', () => {
		// CacheDocumentService sets the copy sizes before it knows GD could
		// read the image, so an unreadable upload arrives sized with an empty
		// hash — and decode('') throws
		for (const blurhash of ['', undefined, null, 'abc']) {
			expect(() => mount(MediaAttachment, { props: { attachment: { ...attachment, blurhash } } })).not.toThrow()
		}
		expect(context.putImageData).not.toHaveBeenCalled()
	})

	it('draws nothing rather than throwing for a hash that does not decode', () => {
		expect(() => mount(MediaAttachment, {
			props: { attachment: { ...attachment, blurhash: '!!!!!!!!!!!!' } },
		})).not.toThrow()
		expect(context.putImageData).not.toHaveBeenCalled()
	})

	// a local video's ladder: an addition to a file that already plays, which
	// is why every path through it keeps the file

	const laddered = {
		id: '11',
		type: 'video',
		media_type: 'video/mp4',
		url: 'https://cloud.example.org/media/movie.mp4',
		hls_url: 'https://cloud.example.org/media/hls/stored-uuid',
		preview_url: 'https://cloud.example.org/media/poster.jpg',
		description: '',
		blurhash: '',
	}

	/**
	 * The plain file is what plays while hls.js is being fetched, what plays
	 * if the ladder cannot be loaded, and what plays in a browser with no HLS
	 * at all — so it is on the element from the first frame.
	 */
	it('keeps the plain file as the src of a laddered video', () => {
		const wrapper = mount(MediaAttachment, { props: { attachment: laddered, interactive: true } })

		expect(wrapper.find('video').attributes('src')).toBe('https://cloud.example.org/media/movie.mp4')
	})

	/**
	 * A remote HLS video is a playlist and nothing else, so its `src` has to
	 * be empty and hls.js hands the element its own.
	 */
	it('gives a remote playlist no src of its own', () => {
		const wrapper = mount(MediaAttachment, {
			props: {
				attachment: {
					id: '12',
					type: 'video',
					media_type: 'application/x-mpegURL',
					url: 'https://cloud.example.org/apps/social/media/playlist/4',
					preview_url: 'https://cloud.example.org/media/poster.jpg',
					description: '',
					blurhash: '',
				},
				interactive: true,
			},
		})

		expect(wrapper.find('video').attributes('src')).toBeUndefined()
	})

	/** A video with no ladder has nothing extra to load. */
	it('offers no ladder for a video the server has not laddered', () => {
		const wrapper = mount(MediaAttachment, {
			props: { attachment: { ...laddered, hls_url: null }, interactive: true },
		})

		expect(wrapper.vm.ladderSource).toBe('')
	})

	/** A thumbnail in a timeline must not start fetching an adaptive stream. */
	it('loads no ladder for an attachment that is not interactive', () => {
		const wrapper = mount(MediaAttachment, { props: { attachment: laddered } })

		expect(wrapper.vm.hls).toBe(null)
	})

	it('emits click so a parent can open the viewer', async () => {
		const wrapper = mount(MediaAttachment, { props: { attachment } })
		await wrapper.find('.attachment').trigger('click')
		// the component's own emit carries no payload (the native event is recorded separately)
		expect(wrapper.emitted('click')).toContainEqual([])
	})

	// #2290: an attachment this instance holds no copy of. The server used to
	// send a link to a copy that was not here; it sends null now, and none of
	// these elements may be drawn around nothing.

	it('says why an image is missing rather than fetching a copy that is not here', () => {
		const wrapper = mount(MediaAttachment, {
			props: {
				attachment: {
					...attachment,
					url: null,
					preview_url: null,
					remote_url: 'https://files.remote.example/media/cat.jpg',
					cache_error: 1,
				},
			},
		})

		expect(wrapper.find('img').exists()).toBe(false)
		const placeholder = wrapper.find('.attachment__failed')
		expect(placeholder.exists()).toBe(true)
		expect(placeholder.attributes('aria-label')).toContain('media size limit')
	})

	it('draws no player for a video with no copy here', () => {
		const wrapper = mount(MediaAttachment, {
			props: {
				attachment: { ...attachment, type: 'video', url: null, preview_url: null, cache_error: 2 },
			},
		})

		// an empty <video> sat blank and said nothing
		expect(wrapper.find('video').exists()).toBe(false)
		expect(wrapper.find('.attachment__failed').exists()).toBe(true)
	})

	it('offers no download link for a file with no copy here', () => {
		const wrapper = mount(MediaAttachment, {
			props: {
				attachment: { ...attachment, type: 'unknown', url: null, preview_url: null, cache_error: 3 },
			},
		})

		expect(wrapper.find('a.attachment__file').exists()).toBe(false)
		expect(wrapper.find('.attachment__failed').exists()).toBe(true)
	})

	it('still draws an attachment that is here', () => {
		const wrapper = mount(MediaAttachment, { props: { attachment } })

		expect(wrapper.find('img').exists()).toBe(true)
		expect(wrapper.find('.attachment__failed').exists()).toBe(false)
	})
})
