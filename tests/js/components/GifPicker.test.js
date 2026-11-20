/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import GifPicker from '../../../src/components/Composer/GifPicker.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const stubs = {
	// $attrs already carries the parent's @click; emitting one as well would
	// fire every press twice
	NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input :value="modelValue" :aria-label="label" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}

const library = [
	{ slug: 'happy-cat', title: 'A very happy cat', url: '/gif/happy-cat', media_type: 'image/gif' },
	{ slug: 'shipit', title: 'Ship it', url: '/gif/shipit', media_type: 'image/gif' },
]

/**
 * What the paged endpoint answers with.
 *
 * @param {Array} gifs the screenful
 * @param {number} total how many there are altogether
 * @return {object} an axios answer
 */
function page(gifs, total = gifs.length) {
	return { data: { gifs, total, attribution: 'Noto Animated Emoji, by Google, licensed CC BY 4.0' } }
}

/**
 * @param {Array} gifs what the server answers
 * @param {number} total how many it says there are
 * @return {Promise<object>} the mounted picker, once its request has settled
 */
async function mountPicker(gifs = library, total = gifs.length) {
	axios.get.mockResolvedValue(page(gifs, total))

	const wrapper = mount(GifPicker, { global: { stubs } })
	await flushPromises()

	return wrapper
}

describe('GifPicker', () => {
	beforeEach(() => {
		axios.get.mockReset()
		vi.useFakeTimers({ shouldAdvanceTime: true })
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('shows the library as a grid', async () => {
		const wrapper = await mountPicker()

		expect(wrapper.findAll('.gif-picker__item')).toHaveLength(2)
		expect(wrapper.find('.gif-picker__image').attributes('src')).toBe('/gif/happy-cat')
	})

	it('gives every picture alt text', async () => {
		const wrapper = await mountPicker()

		expect(wrapper.findAll('.gif-picker__image')[0].attributes('alt')).toBe('A very happy cat')
	})

	// the whole library is in the grid and most of it is below the fold; these
	// are animations
	it('leaves the pictures below the fold until they are reached', async () => {
		const wrapper = await mountPicker()

		expect(wrapper.find('.gif-picker__image').attributes('loading')).toBe('lazy')
	})

	it('hands the chosen picture up rather than attaching it itself', async () => {
		const wrapper = await mountPicker()

		await wrapper.findAll('.gif-picker__item')[1].trigger('click')

		expect(wrapper.emitted('chosen')).toEqual([[library[1]]])
	})

	it('waits for a pause in the typing before it searches again', async () => {
		const wrapper = await mountPicker()
		axios.get.mockClear()

		await wrapper.find('input').setValue('cat')
		expect(axios.get).not.toHaveBeenCalled()

		vi.advanceTimersByTime(300)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(expect.any(String), { params: { q: 'cat', limit: 24, offset: 0 } })
	})

	it('searches once for a run of keystrokes', async () => {
		const wrapper = await mountPicker()
		axios.get.mockClear()

		await wrapper.find('input').setValue('c')
		await wrapper.find('input').setValue('ca')
		await wrapper.find('input').setValue('cat')
		vi.advanceTimersByTime(300)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	describe('when there is nothing to show', () => {
		// an empty library and a search that found nothing are different
		// things, and only one of them is something the reader can fix
		it('says the library is empty', async () => {
			const wrapper = await mountPicker([])

			expect(wrapper.find('.gif-picker__note').text())
				.toBe('There are no GIFs on this instance.')
		})

		it('says a search found nothing', async () => {
			const wrapper = await mountPicker([])
			axios.get.mockResolvedValue(page([]))

			await wrapper.find('input').setValue('aardvark')
			vi.advanceTimersByTime(300)
			await flushPromises()

			expect(wrapper.find('.gif-picker__note').text()).toBe('Nothing here matches that.')
		})
	})

	/**
	 * The library is 881 pictures now that the animated emoji are in it, and
	 * every one drawn is one this instance goes and fetches the first time.
	 * A screenful at a time keeps that to what somebody is looking at.
	 */
	describe('a library too large to draw at once', () => {
		it('asks for a screenful, not for everything', async () => {
			await mountPicker()

			expect(axios.get).toHaveBeenCalledWith(
				expect.stringContaining('/api/v1/gifs'),
				{ params: { q: '', limit: 24, offset: 0 } },
			)
		})

		it('offers the rest only while there is a rest', async () => {
			const all = await mountPicker(library, 2)
			expect(all.find('.gif-picker__more').exists()).toBe(false)

			const some = await mountPicker(library, 900)
			expect(some.find('.gif-picker__more').exists()).toBe(true)
		})

		it('adds the next screenful to what is already drawn', async () => {
			const wrapper = await mountPicker(library, 4)
			axios.get.mockResolvedValue(page([
				{ slug: 'noto-1f600', title: 'smile', url: '/gif/noto-1f600', media_type: 'image/webp' },
				{ slug: 'noto-1f603', title: 'smile with big eyes', url: '/gif/noto-1f603', media_type: 'image/webp' },
			], 4))

			await wrapper.find('.gif-picker__more button').trigger('click')
			await flushPromises()

			expect(axios.get).toHaveBeenLastCalledWith(
				expect.stringContaining('/api/v1/gifs'),
				{ params: { q: '', limit: 24, offset: 2 } },
			)
			expect(wrapper.findAll('.gif-picker__item')).toHaveLength(4)
			expect(wrapper.find('.gif-picker__more').exists()).toBe(false)
		})

		it('starts again rather than appending when the search changes', async () => {
			const wrapper = await mountPicker(library, 900)
			axios.get.mockResolvedValue(page([library[0]], 1))

			await wrapper.find('input').setValue('cat')
			vi.advanceTimersByTime(300)
			await flushPromises()

			expect(wrapper.findAll('.gif-picker__item')).toHaveLength(1)
		})

		/** CC BY asks for it, and the server says what it is. */
		it('credits the emoji it did not draw itself', async () => {
			const wrapper = await mountPicker()

			expect(wrapper.find('.gif-picker__credit').text())
				.toBe('Noto Animated Emoji, by Google, licensed CC BY 4.0')
		})

		it('credits nothing when there is nothing to show', async () => {
			const wrapper = await mountPicker([])

			expect(wrapper.find('.gif-picker__credit').exists()).toBe(false)
		})
	})

	it('shows an empty library rather than an error when the request fails', async () => {
		axios.get.mockRejectedValue(new Error('nope'))

		const wrapper = mount(GifPicker, { global: { stubs } })
		await flushPromises()

		expect(wrapper.findAll('.gif-picker__item')).toHaveLength(0)
	})

	it('can be closed', async () => {
		const wrapper = await mountPicker()

		await wrapper.find('[aria-label="Close the GIF library"]').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
