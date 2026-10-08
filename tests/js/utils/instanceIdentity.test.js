/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { instanceColour, instanceOf, originOf } from '../../../src/utils/instanceIdentity.js'

describe('instanceOf', () => {
	it('reads the host out of a remote handle', () => {
		expect(instanceOf('alice@example.org')).toBe('example.org')
		expect(instanceOf('alice@sub.example.org')).toBe('sub.example.org')
	})

	it('treats a bare handle as local', () => {
		expect(instanceOf('alice')).toBe('')
	})

	it('lowercases the host, so one instance gets one identity', () => {
		expect(instanceOf('alice@Example.ORG')).toBe('example.org')
	})

	it('takes the last @, as a handle written with a leading one still resolves', () => {
		expect(instanceOf('@alice@example.org')).toBe('example.org')
	})

	it('survives nonsense', () => {
		expect(instanceOf('')).toBe('')
		expect(instanceOf('@')).toBe('')
		expect(instanceOf(undefined)).toBe('')
		expect(instanceOf(null)).toBe('')
		expect(instanceOf(42)).toBe('')
	})
})

describe('instanceColour', () => {
	it('gives one host one colour, every time', () => {
		expect(instanceColour('example.org')).toBe(instanceColour('example.org'))
	})

	it('gives different hosts different colours', () => {
		const hosts = ['mastodon.social', 'chaos.social', 'example.org', 'nextcloud.com', 'fosstodon.org']
		const colours = new Set(hosts.map(instanceColour))

		expect(colours.size).toBe(hosts.length)
	})

	it('produces a usable hsl colour with a hue in range', () => {
		for (const host of ['a', 'example.org', 'a-very-long-hostname.example.co.uk']) {
			const colour = instanceColour(host)
			const hue = Number(colour.match(/^hsl\((\d+),/)[1])

			expect(hue).toBeGreaterThanOrEqual(0)
			expect(hue).toBeLessThan(360)
			// the saturation is fixed; the lightness is whatever the hue needs
			expect(colour).toContain('62%,')
		}
	})

	it('has no colour for a local account', () => {
		expect(instanceColour('')).toBe('')
	})
})

describe('instanceColour contrast', () => {
	/** WCAG 2 relative luminance of an hsl() string. */
	const luminanceOf = (colour) => {
		const [hue, saturation, lightness] = colour
			.match(/^hsl\((\d+), ([\d.]+)%, ([\d.]+)%\)$/)
			.slice(1)
			.map(Number)
		const s = saturation / 100
		const l = lightness / 100
		const channel = (n) => {
			const a = s * Math.min(l, 1 - l)
			const k = (n + hue / 30) % 12

			return l - a * Math.max(-1, Math.min(k - 3, 9 - k, 1))
		}
		const linear = (v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)

		return 0.2126 * linear(channel(0)) + 0.7152 * linear(channel(8)) + 0.0722 * linear(channel(4))
	}

	it('carries white text at every hue it can produce', () => {
		// a fixed lightness cannot: hsl(60, 62%, 52%) against white is ~1.5:1,
		// so whoever happened to land on a yellow instance got an unreadable badge
		const seen = new Set()
		for (let i = 0; i < 400; i++) {
			const colour = instanceColour('host' + i + '.example')
			if (seen.has(colour)) {
				continue
			}
			seen.add(colour)

			const contrast = 1.05 / (luminanceOf(colour) + 0.05)
			expect(contrast, colour).toBeGreaterThanOrEqual(4.5)
		}

		expect(seen.size).toBeGreaterThan(20)
	})

	it('keeps the hue, so two instances still look different', () => {
		const hueOf = (host) => Number(instanceColour(host).match(/^hsl\((\d+),/)[1])

		expect(hueOf('one.example')).not.toBe(hueOf('two.example'))
	})
})

describe('originOf', () => {
	it('describes a remote account', () => {
		expect(originOf('bob@chaos.social')).toEqual({
			instance: 'chaos.social',
			colour: instanceColour('chaos.social'),
			local: false,
		})
	})

	it('describes a local account as local, with no colour to show', () => {
		expect(originOf('alice')).toEqual({ instance: '', colour: '', local: true })
		expect(originOf({ acct: 'alice', bluesky: null })).toEqual({ instance: '', colour: '', local: true })
	})

	it('places a Bluesky account under its handle domain, never here', () => {
		expect(originOf({ acct: 'dana.bsky.social', bluesky: { native: true } })).toEqual({
			instance: 'bsky.social',
			colour: instanceColour('bsky.social'),
			local: false,
		})
	})
})
