/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import ProfileMediaGrid from '../../../src/components/ProfileMediaGrid.vue'

afterEach(() => {
	vi.unstubAllGlobals()
})

/**
 * The methods are pure, so they are exercised directly rather than through a
 * mounted component: what is worth pinning here is the focal-point arithmetic
 * and which posts become tiles, neither of which needs a DOM.
 *
 * @param {object} overrides anything to change about the component's state
 * @return {object} a `this` for calling the component's own methods against
 */
function grid(overrides = {}) {
	return {
		broken: [],
		account: 'alice',
		...ProfileMediaGrid.methods,
		...overrides,
	}
}

describe('ProfileMediaGrid', () => {
	describe('focalPosition', () => {
		// the centre is spelled the way every other position is, since the
		// conversion is now the one the composer's crosshair writes with
		it('centres a picture that never said where to look', () => {
			expect(grid().focalPosition({})).toBe('50.00% 50.00%')
			expect(grid().focalPosition({ meta: {} })).toBe('50.00% 50.00%')
			expect(grid().focalPosition(undefined)).toBe('50.00% 50.00%')
		})

		it('centres rather than trusting a malformed focus', () => {
			for (const focus of [{ x: 'left', y: 0 }, { x: 0 }, { y: 0 }, null]) {
				expect(grid().focalPosition({ meta: { focus } })).toBe('50.00% 50.00%')
			}
		})

		it('maps the centre to the centre', () => {
			expect(grid().focalPosition({ meta: { focus: { x: 0, y: 0 } } })).toBe('50.00% 50.00%')
		})

		/**
		 * The inversion is the part worth pinning: the focal point's y points
		 * up from the centre, CSS measures down from the top. Getting it the
		 * wrong way round crops the top of every portrait instead of the
		 * bottom, which looks plausible until you compare it with the post.
		 */
		it('inverts the vertical axis, because CSS measures down and focus points up', () => {
			expect(grid().focalPosition({ meta: { focus: { x: 0, y: 1 } } })).toBe('50.00% 0.00%')
			expect(grid().focalPosition({ meta: { focus: { x: 0, y: -1 } } })).toBe('50.00% 100.00%')
		})

		it('maps the horizontal axis straight across', () => {
			expect(grid().focalPosition({ meta: { focus: { x: -1, y: 0 } } })).toBe('0.00% 50.00%')
			expect(grid().focalPosition({ meta: { focus: { x: 1, y: 0 } } })).toBe('100.00% 50.00%')
		})

		it('handles a point that is not on an edge', () => {
			expect(grid().focalPosition({ meta: { focus: { x: 0.5, y: -0.5 } } })).toBe('75.00% 75.00%')
		})
	})

	describe('toTile', () => {
		const post = (attachments, extra = {}) => ({
			id: '42',
			media_attachments: attachments,
			...extra,
		})

		it('takes the first picture of an album and says how many there are', () => {
			const tile = grid().toTile(post([
				{ preview_url: 'a.jpg', type: 'image' },
				{ preview_url: 'b.jpg', type: 'image' },
			]))

			expect(tile.preview).toBe('a.jpg')
			expect(tile.count).toBe(2)
		})

		it('falls back to the full picture when there is no preview', () => {
			expect(grid().toTile(post([{ url: 'full.jpg', type: 'image' }])).preview).toBe('full.jpg')
		})

		it('uses a video element when the preview URL is the video itself', () => {
			const tile = grid().toTile(post([{
				type: 'video',
				url: 'clip.mp4',
				preview_url: 'clip.mp4',
			}]))

			expect(tile.preview).toBeNull()
			expect(tile.videoSource).toBe('clip.mp4')
		})

		it('uses the still poster when a video has a separate preview image', () => {
			const tile = grid().toTile(post([{
				type: 'video',
				url: 'clip.mp4',
				preview_url: 'poster.jpg',
			}]))

			expect(tile.preview).toBe('poster.jpg')
			expect(tile.videoSource).toBeNull()
		})

		it('draws nothing rather than a broken image once one has failed', () => {
			const tile = grid({ broken: ['42'] }).toTile(post([{ preview_url: 'gone.jpg' }]))

			expect(tile.preview).toBeNull()
		})

		it('marks a video so the tile can say it is one', () => {
			expect(grid().toTile(post([{ type: 'video' }])).isVideo).toBe(true)
			expect(grid().toTile(post([{ type: 'gifv' }])).isVideo).toBe(true)
			expect(grid().toTile(post([{ type: 'image' }])).isVideo).toBe(false)
		})

		it('carries the sensitive flag, so the tile is veiled like the post', () => {
			expect(grid().toTile(post([{ type: 'image' }], { sensitive: true })).sensitive).toBe(true)
		})

		it('links the tile at the post, not at the picture', () => {
			const tile = grid().toTile(post([{ type: 'image' }]))

			expect(tile.route).toEqual({
				name: 'single-post',
				params: { account: 'alice', id: '42' },
			})
		})

		it('links a tile at the account that wrote the post, where the grid has none', () => {
			// Discover is everybody's grid: `account` is a required route param,
			// and an empty one threw while resolving and took every tile with it
			const tile = grid({ account: '' }).toTile(post([{ type: 'image' }], { account: { acct: 'bob@remote.example' } }))

			expect(tile.route.params.account).toBe('bob@remote.example')
		})

		it('still uses the grid\'s own account when a post carries none', () => {
			expect(grid().toTile(post([{ type: 'image' }])).route.params.account).toBe('alice')
		})

		/**
		 * The grid is the other way this app draws a timeline, and a filter
		 * that covers a post in the list has to cover it here. Not veiled like
		 * a sensitive picture: `preview` is dropped, so the file is never
		 * fetched at all.
		 */
		it('draws no picture for a post the reader\'s own filters matched', () => {
			const tile = grid().toTile(post([{ preview_url: 'a.jpg', type: 'image' }], {
				filtered: [{ filter: { id: '1', title: 'Politics' }, keyword_matches: ['election'], status_matches: [] }],
			}))

			expect(tile.filtered).toBe(true)
			expect(tile.label).toBe('Filtered: Politics')
		})

		it('leaves a tile nothing matched alone', () => {
			expect(grid().toTile(post([{ type: 'image' }], { filtered: [] })).filtered).toBe(false)
		})

		/** The alt text is matched by a filter as surely as the words are. */
		it('does not put the alt text of a filtered picture in the label', () => {
			const tile = grid().toTile(post([{ description: 'a rally in the square' }], {
				filtered: [{ filter: { id: '1', title: 'Politics' }, keyword_matches: [], status_matches: [] }],
			}))

			expect(tile.label).not.toContain('rally')
		})

		it('prefers the alt text as the label', () => {
			expect(grid().toTile(post([{ description: 'a cat asleep' }])).label).toBe('a cat asleep')
		})
	})

	describe('tiles', () => {
		it('skips the posts that have no pictures at all', () => {
			const context = {
				posts: [
					{ id: '1', media_attachments: [{ type: 'image' }] },
					{ id: '2', media_attachments: [] },
					{ id: '3' },
					null,
				],
				broken: [],
				account: 'alice',
				...ProfileMediaGrid.methods,
			}

			expect(ProfileMediaGrid.computed.tiles.call(context)).toHaveLength(1)
		})
	})

	describe('video preview loading', () => {
		it('does not fetch fallback video tiles until they approach the viewport', () => {
			let callback
			const observe = vi.fn()
			const unobserve = vi.fn()
			vi.stubGlobal('IntersectionObserver', class {
				constructor(onEntries, options) {
					callback = onEntries
					expect(options.rootMargin).toBe('120px')
					this.observe = observe
					this.unobserve = unobserve
				}
			})

			const video = { src: '', dataset: { source: 'clip.mp4' }, preload: 'none' }
			const context = grid({ $refs: { videoPreviews: [video] }, videoObserver: null })
			context.observeVideoPreviews()

			expect(video.src).toBe('')
			expect(observe).toHaveBeenCalledWith(video)

			callback([{ isIntersecting: false, target: video }])
			expect(video.src).toBe('')

			callback([{ isIntersecting: true, target: video }])
			expect(video.src).toBe('clip.mp4')
			expect(video.preload).toBe('metadata')
			expect(unobserve).toHaveBeenCalledWith(video)
		})

		it('seeks past the opening frame when a video loads', () => {
			const video = { duration: 2, currentTime: 0 }
			grid().showVideoFrame({ target: video })
			expect(video.currentTime).toBe(1)
		})
	})
})
